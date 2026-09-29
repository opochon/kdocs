<?php

declare(strict_types=1);

namespace Ged\Classement;

use Ged\Config;
use Ged\Contrats\Classeur;
use PDO;
use Throwable;

/**
 * Le classeur de la GED : il propose type, émetteur, titre, date, montant et étiquettes pour une pièce,
 * et ne valide seul que si deux conditions tiennent ensemble :
 *
 *   1. une preuve — indice du type dans le texte (règle), ou habitude de l'émetteur ;
 *   2. une confiance ≥ seuil (CLASSEMENT_SEUIL, défaut 0,8).
 *
 * L'IA propose mais ne force jamais : sans indice dans le texte, sa suggestion part dans « À traiter »
 * avec sa raison. « Autre » n'est jamais validé seul, et un Document RH exige que le propriétaire de la
 * GED (GED_ORGANISATION) figure dans la pièce.
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
            $type = (string) $habitude['valeur'];
            $source = 'habitude';
            $preuve = 'habitude : ' . $habitude['choix'] . ' choix identiques pour cet émetteur';
            $confiance = (float) $habitude['confiance'];
            $valide = true;
        } elseif ($indiceRegle !== null) {
            $type = $indiceRegle['type'];
            $preuve = $indiceRegle['preuve'];
            $confiance = $indiceRegle['confiance'];
            // L'IA qui converge avec l'indice porte la source : elle a lu le même signe.
            if ($reponseIa !== null && Regles::memeType($reponseIa->type, $type) && $reponseIa->confiance >= $this->seuil) {
                $source = 'ia';
                $preuve = 'ia + ' . $preuve;
                $confiance = max($confiance, $reponseIa->confiance);
            }
            $valide = true;
        } elseif ($reponseIa !== null && $reponseIa->type !== null) {
            $type = $reponseIa->type;
            $source = 'ia';
            $confiance = $reponseIa->confiance;
            $raison = 'le type proposé par l’IA n’a aucun indice dans le texte';
        } else {
            $raison = 'aucun indice de type dans le texte';
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

        return [
            'titre' => $reponseIa?->titre,
            'type' => $type,
            'emetteur' => $emetteur,
            'date' => $reponseIa?->date ?? $this->dateAnalyse($analyse),
            'montant' => $reponseIa?->montant ?? $this->montantAnalyse($analyse),
            'etiquettes' => $reponseIa?->etiquettes ?? ($emetteur !== null ? $this->etiquettesHabitude($emetteur) : []),
            'confiance' => round($confiance, 2),
            'source' => $source,
            'preuve' => $preuve,
            'valide' => $valide,
            'raison' => $raison,
        ];
    }

    /** @param array<string, mixed> $analyse */
    private function dateAnalyse(array $analyse): ?string
    {
        $candidats = [];
        $facture = $analyse['facture'] ?? [];
        if (is_array($facture)) {
            foreach (['invoice_date', 'due_date'] as $cle) {
                if (!empty($facture[$cle])) {
                    $candidats[] = $facture[$cle];
                }
            }
        }
        $dates = $analyse['origine']['cles']['dates'] ?? [];
        if (is_array($dates)) {
            foreach ($dates as $d) {
                $candidats[] = $d;
            }
        }
        foreach ($candidats as $d) {
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
