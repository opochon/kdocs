<?php

declare(strict_types=1);

namespace Ged\Classement;

use Ged\Config;
use Ged\Contrats\Classeur;
use Ged\Socle\Nomenclature;
use PDO;
use Throwable;

/**
 * Le classeur de la GED : il propose type, émetteur, titre, date, montant et étiquettes pour une pièce,
 * et ne valide seul que sur une preuve corroborée (règle `typeCorrobore` de GEDv1) :
 *
 *   1. une habitude de l'émetteur (N choix humains identiques), OU BIEN
 *   2. une IA à confiance ≥ seuil (CLASSEMENT_SEUIL, défaut 0,8) qui propose le même type qu'un indice
 *      du texte — l'IA ET la règle/habitude, jamais l'un sans l'autre ;
 *   3. dans tous les cas, émetteur et date d'émission crédibles, corroborés par l'IA et la pièce.
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
        $emetteurRegle = Regles::lireEmetteur($texte, $proprietaire);

        // L'IA n'est consultée que si elle est disponible ; toute panne se replie sans bloquer le rangement.
        $reponseIa = null;
        if ($this->ia->disponible() && trim($texte) !== '') {
            try {
                $reponseIa = $this->ia->classer($texte, $nomFichier, $this->typesDuFonds());
            } catch (Throwable) {
                $reponseIa = null;
            }
        }
        $emetteurIa = Regles::emetteurIaCredible($texte, $reponseIa?->emetteur, $proprietaire);
        $emetteurAppris = $emetteurRegle === null ? $this->habitudes->emetteurApprisDansTexte($texte) : null;
        // L'IA peut reconnaître le logo ou le pied de page ; une validation seule demandera ensuite que
        // ce nom soit corroboré par la lecture ou une habitude.
        // Le texte garde sa casse et sa forme canonique : l'IA est une confirmation, pas un nom de dossier
        // concurrent (AG/SA/GmbH/Sàrl doivent aboutir au même correspondant).
        $emetteur = $emetteurRegle ?? $emetteurIa ?? $emetteurAppris;
        $emetteurCorrobore = $emetteurAppris !== null || ($emetteurIa !== null && $emetteurRegle !== null
            && Regles::emetteursCorrespondent($emetteurIa, $emetteurRegle));

        $habitude = $emetteur !== null ? $this->habitudes->habitude($emetteur, Habitudes::CHAMP_TYPE) : null;

        $type = null;
        $source = 'regle';
        $preuve = null;
        $confiance = 0.0;
        $valide = false;
        $raison = null;
        $typeCorrobore = false;
        $validationParHabitude = false;

        if ($habitude !== null && !Regles::estAutre((string) $habitude['valeur'])) {
            // L'habitude vaut preuve à elle seule : trois choix humains identiques ont déjà tranché.
            $type = (string) $habitude['valeur'];
            $source = 'habitude';
            $preuve = 'habitude : ' . $habitude['choix'] . ' choix identiques pour cet émetteur';
            $confiance = (float) $habitude['confiance'];
            $valide = true;
            $typeCorrobore = true;
            $validationParHabitude = true;
        } else {
            $typeTexte = $indiceRegle['type'] ?? null;
            $typeIa = $reponseIa?->type;
            // Règle `typeCorrobore` : l'IA doit corroborer un indice DU MÊME TYPE dans le texte.
            // Une famille commune (Police d'assurance / Contrat) n'est pas une preuve du sous-type :
            // elle sert seulement à expliquer la suggestion à l'humain, jamais à ranger seule.
            $iaCorrobore = $indiceRegle !== null
                && $typeIa !== null
                && $reponseIa->confiance >= $this->seuil
                && Regles::memeType($typeIa, $typeTexte);

            if ($iaCorrobore) {
                $type = Regles::typeLePlusPrecis($typeIa, $typeTexte);
                $source = 'ia';
                $preuve = 'ia + ' . $indiceRegle['preuve'];
                $confiance = max($indiceRegle['confiance'], $reponseIa->confiance);
                $valide = true;
                $typeCorrobore = true;
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
                } elseif (!Regles::memeType($typeIa, $typeTexte) && Regles::typesCompatibles($typeIa, $typeTexte)) {
                    // Le type IA reste visible (il peut guider le contrôleur), mais le seul dénominateur
                    // commun est le parent. Sans indice propre du sous-type, aucune validation seule.
                    $type = Regles::typeLePlusPrecis($typeIa, $typeTexte);
                    $source = 'ia';
                    $confiance = $reponseIa->confiance;
                    $raison = 'sous-type IA à contrôler : ' . $typeIa . ' sans indice propre dans le texte (texte=' . $typeTexte . ')';
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

        // Une date ne vient jamais seule de l'IA ni des dates de corps que l'OCR extrait sans contexte.
        // Le parseur de facture est une seconde source structurée, après l'en-tête documentaire.
        $dateRegle = Regles::dateEnTete($texte) ?? $this->dateFacture($analyse);
        $dateIa = Regles::datePlausible($reponseIa?->date) ? $reponseIa?->date : null;
        $dateDocument = $dateRegle;
        // Une date absente reste absente : le dépôt la rangera sous Sans_date, jamais sous l'année du jour.
        // Valider seul exige les DEUX sources : une date absente n'est pas une concordance. La date
        // lue reste proposée pour aider l'humain, mais ne suffit pas au rangement automatique.
        $dateCorroboree = $dateRegle !== null && $dateIa !== null && $dateRegle === $dateIa;

        // Trois validations humaines du même émetteur constituent la preuve métier d'apprentissage :
        // elles rendent cet émetteur crédible et autorisent la quatrième pièce même hors-ligne ou sans
        // date OCR. Le tri automatique, lui, n'appelle jamais retenirChoix().
        if ($validationParHabitude) {
            $emetteurCorrobore = $emetteur !== null;
            $dateCorroboree = true;
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
        if ($valide && !$typeCorrobore) {
            $valide = false;
            $raison = 'type à contrôler : IA et règle/habitude ne concordent pas';
        }
        if ($valide && !$emetteurCorrobore) {
            $valide = false;
            $raison = $emetteur === null ? 'émetteur non crédible : aucune source lisible corroborée'
                : 'émetteur à contrôler : IA et texte ne concordent pas';
        }
        if ($valide && !$dateCorroboree) {
            $valide = false;
            $raison = $dateRegle === null || $dateIa === null
                ? 'date à contrôler : date crédible absente dans l’IA ou le document'
                : 'date à contrôler : IA et document ne concordent pas';
        }
        if ($valide && trim((string) $preuve) === '') {
            $valide = false;
            $raison = 'preuve automatique absente';
        }
        if (!$valide && $raison === null) {
            $raison = 'à contrôler';
        }

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
            if (is_string($facture[$cle] ?? null) && Regles::datePlausible($facture[$cle])) {
                return $facture[$cle];
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

    /** @return list<string> */
    private function typesDuFonds(): array
    {
        try {
            $types = (new Nomenclature($this->config))->types();
            if ($types !== []) {
                return $types;
            }
        } catch (Throwable) {
            // Fonds antérieur à V2-04 ou registre momentanément indisponible : repli compatible.
        }

        return Regles::typesConnus();
    }
}
