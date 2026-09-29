<?php

declare(strict_types=1);

namespace Ged\Classement;

use Ged\Config;
use Ged\Contrats\Classeur;
use PDO;
use Throwable;

/**
 * Le classeur de la GED : il propose type, émetteur, titre, date, montant et étiquettes pour une pièce,
 * et ne valide seul que sur une preuve corroborée (règle `typeCorrobore` de GEDv1) :
 *
 *   1. une habitude de l'émetteur (N choix humains identiques), OU BIEN
 *   2. une IA à confiance ≥ seuil (CLASSEMENT_SEUIL, défaut 0,8) qui propose le même type qu'un indice
 *      du texte — l'IA ET le mot-clé, jamais l'un sans l'autre.
 *
 * Un mot-clé seul reste une suggestion ; l'IA absente, en panne ou en désaccord laisse la pièce dans
 * « À traiter » avec sa raison (« IA indisponible », « IA et texte divergent : IA=…, texte=… »).
 * L'IA ne force jamais un type sans indice dans le texte. « Autre » n'est jamais validé seul, et un
 * Document RH exige que le propriétaire de la GED (GED_ORGANISATION) figure dans la pièce.
 */
final class ClasseurGed implements Classeur
{
    private readonly ClientIa $ia;
    private readonly Habitudes $habitudes;
    private readonly float $seuil;

    public function __construct(
        private readonly Config $config,
        private readonly PDO $pdo,
        ?ClientIa $ia = null,
        ?Habitudes $habitudes = null,
    ) {
        $this->ia = $ia ?? new InfomaniakClient($config);
        $this->habitudes = $habitudes ?? new Habitudes($pdo);
        $this->seuil = max(0.0, min(1.0, (float) $config->get('CLASSEMENT_SEUIL', '0.8')));
    }

    public function proposer(string $texte, array $analyse, string $nomFichier): array
    {
        $proprietaire = $this->config->get('GED_ORGANISATION', '');
        $indiceRegle = Regles::typeEtPreuve($texte);
        $emetteur = Regles::lireEmetteur($texte, $proprietaire);

        // L'IA n'est consultée que si elle est disponible ; toute panne se replie sans bloquer le rangement.
        $reponseIa = null;
        if ($this->ia->disponible() && trim($texte) !== '') {
            try {
                $reponseIa = $this->ia->classer($texte, $nomFichier, Regles::typesConnus());
            } catch (Throwable) {
                $reponseIa = null;
            }
        }
        if ($emetteur === null && $reponseIa?->emetteur !== null && Regles::nomSeLit($texte, $reponseIa->emetteur)) {
            $emetteur = $reponseIa->emetteur;
        }

        $habitude = $emetteur !== null ? $this->habitudes->habitude($emetteur, Habitudes::CHAMP_TYPE) : null;

        $type = null;
        $source = 'regle';
        $preuve = null;
        $confiance = 0.0;
        $valide = false;
        $raison = null;

        if ($habitude !== null && !Regles::estAutre((string) $habitude['valeur'])) {
            // L'habitude vaut preuve à elle seule : trois choix humains identiques ont déjà tranché.
            $type = (string) $habitude['valeur'];
            $source = 'habitude';
            $preuve = 'habitude : ' . $habitude['choix'] . ' choix identiques pour cet émetteur';
            $confiance = (float) $habitude['confiance'];
            $valide = true;
        } else {
            $typeTexte = $indiceRegle['type'] ?? null;
            $typeIa = $reponseIa?->type;
            // Règle `typeCorrobore` : l'IA doit corroborer un indice DU MÊME TYPE dans le texte.
            $iaCorrobore = $indiceRegle !== null
                && $typeIa !== null
                && $reponseIa->confiance >= $this->seuil
                && Regles::memeType($typeIa, $typeTexte);

            if ($iaCorrobore) {
                $type = $typeTexte;
                $source = 'ia';
                $preuve = 'ia + ' . $indiceRegle['preuve'];
                $confiance = max($indiceRegle['confiance'], $reponseIa->confiance);
                $valide = true;
            } elseif ($indiceRegle !== null) {
                // Un mot-clé seul reste une suggestion : jamais de rangement automatique.
                $type = $typeTexte;
                $source = 'regle';
                $preuve = $indiceRegle['preuve'];
                $confiance = $indiceRegle['confiance'];
                if ($reponseIa === null) {
                    $raison = 'IA indisponible';
                } elseif ($typeIa === null) {
                    $raison = 'IA sans type : le texte seul ne valide pas';
                } elseif (!Regles::memeType($typeIa, $typeTexte)) {
                    $raison = 'IA et texte divergent : IA=' . $typeIa . ', texte=' . $typeTexte;
                } else {
                    $raison = 'confiance IA insuffisante (' . round($reponseIa->confiance, 2) . ' < ' . $this->seuil . ')';
                }
            } elseif ($typeIa !== null) {
                $type = $typeIa;
                $source = 'ia';
                $confiance = $reponseIa->confiance;
                $raison = 'le type proposé par l’IA n’a aucun indice dans le texte';
            } else {
                $raison = 'aucun indice de type dans le texte';
            }
        }

        if ($valide && Regles::estAutre($type)) {
            $valide = false;
            $raison = '« Autre » n’est jamais validé seul';
        }
        if ($valide && Regles::memeType($type, 'Document RH') && !Regles::proprietaireDansTexte($texte, $proprietaire)) {
            $valide = false;
            $raison = 'document RH mais le propriétaire (GED_ORGANISATION) ne figure pas dans la pièce';
        }
        if ($valide && $confiance < $this->seuil) {
            $valide = false;
            $raison = 'confiance insuffisante (' . round($confiance, 2) . ' < ' . $this->seuil . ')';
        }
        if (!$valide && $raison === null) {
            $raison = 'à contrôler';
        }

        // La date vient de l'en-tête de la pièce, puis de la facture, puis de l'IA, et seulement en
        // dernier recours d'une date citée dans le corps. Le titre vient de la pièce, jamais du lot.
        $dateDocument = Regles::dateEnTete($texte) ?? $this->dateFacture($analyse) ?? $reponseIa?->date
            ?? $this->dateCorps($analyse);

        return [
            'titre' => $reponseIa?->titre ?? Regles::titrePiece($texte, $type, $emetteur, $dateDocument),
            'type' => $type,
            'emetteur' => $emetteur,
            'date' => $dateDocument,
            'montant' => $reponseIa?->montant ?? $this->montantAnalyse($analyse),
            'etiquettes' => $reponseIa?->etiquettes ?? ($emetteur !== null ? $this->etiquettesHabitude($emetteur) : []),
            'confiance' => round($confiance, 2),
            'source' => $source,
            'preuve' => $preuve,
            'valide' => $valide,
            'raison' => $raison,
        ];
    }

    /** Date de facture lue par le moteur d'ingestion (invoice_date, due_date). @param array<string, mixed> $analyse */
    private function dateFacture(array $analyse): ?string
    {
        $facture = $analyse['facture'] ?? [];
        if (!is_array($facture)) {
            return null;
        }
        foreach (['invoice_date', 'due_date'] as $cle) {
            if (is_string($facture[$cle] ?? null) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $facture[$cle], $m) === 1
                && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                return $facture[$cle];
            }
        }

        return null;
    }

    /** Date citée dans le corps de la pièce — dernier recours. @param array<string, mixed> $analyse */
    private function dateCorps(array $analyse): ?string
    {
        $dates = $analyse['origine']['cles']['dates'] ?? [];
        if (!is_array($dates)) {
            return null;
        }
        foreach ($dates as $d) {
            if (is_string($d) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) === 1
                && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                return $d;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $analyse */
    private function montantAnalyse(array $analyse): ?float
    {
        $montant = $analyse['facture']['amount'] ?? null;

        return is_numeric($montant) ? (float) $montant : null;
    }

    /** @return list<string> */
    private function etiquettesHabitude(string $emetteur): array
    {
        $h = $this->habitudes->habitude($emetteur, Habitudes::CHAMP_ETIQUETTES);
        if ($h === null || trim((string) $h['valeur']) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', (string) $h['valeur'])),
            static fn (string $v): bool => $v !== ''));
    }
}
