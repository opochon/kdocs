<?php

declare(strict_types=1);

namespace Ged\Workflows;

use PDO;

/**
 * Gabarits « prêts à l'emploi », installables d'un geste et sans doublon :
 *  - FACTURE_A_SEUIL : une facture au-dessus du seuil part chez « direction », en dessous chez « comptabilité »,
 *    et traverse le circuit reçu → à valider → validé/refusé ;
 *  - CONTRAT_A_ECHEANCE : un contrat à J-90 (ou moins) déclenche son rappel et entre dans le circuit de
 *    renouvellement.
 */
final class Gabarits
{
    public const FACTURE_A_SEUIL = 'facture_a_seuil';
    public const CONTRAT_A_ECHEANCE = 'contrat_a_echeance';
    public const SEUIL_FACTURE = 10000.0;
    public const DELAI_CONTRAT = 90;

    private const NOMS = [self::FACTURE_A_SEUIL, self::CONTRAT_A_ECHEANCE];

    public static function toutInstaller(PDO $pdo): void
    {
        foreach (self::NOMS as $gabarit) {
            self::installer($pdo, $gabarit);
        }
    }

    public static function installer(PDO $pdo, string $gabarit): void
    {
        match ($gabarit) {
            self::FACTURE_A_SEUIL => self::installerFacture($pdo),
            self::CONTRAT_A_ECHEANCE => self::installerContrat($pdo),
            default => throw new ErreurWorkflow("gabarit inconnu : « $gabarit »"),
        };
    }

    /**
     * @return list<array{nom:string, libelle:string, description:string, installe:bool}>
     */
    public static function catalogue(PDO $pdo): array
    {
        $circuits = new Circuits($pdo);

        return [
            ['nom' => self::FACTURE_A_SEUIL, 'libelle' => 'Facture à seuil',
                'description' => 'Au-dessus du seuil : direction ; en dessous : comptabilité. Circuit reçu → à valider → validé/refusé.',
                'installe' => $circuits->parGabarit(self::FACTURE_A_SEUIL) !== null],
            ['nom' => self::CONTRAT_A_ECHEANCE, 'libelle' => 'Contrat à échéance',
                'description' => 'Rappel à J-90 et entrée dans le circuit actif → à renouveler → résilié.',
                'installe' => $circuits->parGabarit(self::CONTRAT_A_ECHEANCE) !== null],
        ];
    }

    private static function installerFacture(PDO $pdo): void
    {
        $circuits = new Circuits($pdo);
        $regles = new Regles($pdo);

        if ($circuits->parGabarit(self::FACTURE_A_SEUIL) === null) {
            $circuits->creer([
                'nom' => 'Facture à seuil', 'gabarit' => self::FACTURE_A_SEUIL, 'actif' => true,
                'etats' => [
                    ['nom' => 'recu', 'libelle' => 'Reçu', 'initial' => true, 'final' => false],
                    ['nom' => 'a_valider', 'libelle' => 'À valider', 'initial' => false, 'final' => false],
                    ['nom' => 'valide', 'libelle' => 'Validé', 'initial' => false, 'final' => true],
                    ['nom' => 'refuse', 'libelle' => 'Refusé', 'initial' => false, 'final' => true],
                ],
                'transitions' => [
                    ['de' => 'recu', 'vers' => 'a_valider', 'libelle' => 'Soumettre', 'permission' => 'workflow.transition.soumettre'],
                    ['de' => 'a_valider', 'vers' => 'valide', 'libelle' => 'Valider', 'permission' => 'workflow.transition.valider'],
                    ['de' => 'a_valider', 'vers' => 'refuse', 'libelle' => 'Refuser', 'permission' => 'workflow.transition.refuser'],
                ],
            ]);
        }

        self::creerRegleSiAbsente($regles, self::FACTURE_A_SEUIL, 'document_depose', 'Facture élevée → direction', [
            ['champ' => 'type', 'operateur' => 'egal', 'valeur' => 'Facture'],
            ['champ' => 'montant', 'operateur' => 'superieur_ou_egal', 'valeur' => self::SEUIL_FACTURE],
        ], [['type' => 'demander_validation', 'circuit' => self::FACTURE_A_SEUIL, 'vers' => 'a_valider', 'assigne' => 'direction']]);

        self::creerRegleSiAbsente($regles, self::FACTURE_A_SEUIL, 'document_depose', 'Facture courante → comptabilité', [
            ['champ' => 'type', 'operateur' => 'egal', 'valeur' => 'Facture'],
            ['champ' => 'montant', 'operateur' => 'inferieur', 'valeur' => self::SEUIL_FACTURE],
        ], [['type' => 'demander_validation', 'circuit' => self::FACTURE_A_SEUIL, 'vers' => 'a_valider', 'assigne' => 'comptabilite']]);
    }

    private static function installerContrat(PDO $pdo): void
    {
        $circuits = new Circuits($pdo);
        $regles = new Regles($pdo);

        if ($circuits->parGabarit(self::CONTRAT_A_ECHEANCE) === null) {
            $circuits->creer([
                'nom' => 'Contrat à échéance', 'gabarit' => self::CONTRAT_A_ECHEANCE, 'actif' => true,
                'etats' => [
                    ['nom' => 'actif', 'libelle' => 'Actif', 'initial' => true, 'final' => false],
                    ['nom' => 'a_renouveler', 'libelle' => 'À renouveler', 'initial' => false, 'final' => false],
                    ['nom' => 'resilie', 'libelle' => 'Résilié', 'initial' => false, 'final' => true],
                ],
                'transitions' => [
                    ['de' => 'actif', 'vers' => 'a_renouveler', 'libelle' => 'À renouveler', 'permission' => 'workflow.transition.renouveler'],
                    ['de' => 'a_renouveler', 'vers' => 'resilie', 'libelle' => 'Résilier', 'permission' => 'workflow.transition.resilier'],
                ],
            ]);
        }

        self::creerRegleSiAbsente($regles, self::CONTRAT_A_ECHEANCE, 'echeance', 'Contrat à échéance (J-90)', [
            ['champ' => 'type', 'operateur' => 'egal', 'valeur' => 'Contrat'],
            ['champ' => 'delai_echeance_jours', 'operateur' => 'inferieur_ou_egal', 'valeur' => self::DELAI_CONTRAT],
        ], [
            ['type' => 'notifier', 'destinataire' => 'responsable',
                'message' => 'Contrat « {titre} » : échéance dans {jours} jours ({echeance}).'],
            ['type' => 'demander_validation', 'circuit' => self::CONTRAT_A_ECHEANCE, 'vers' => 'a_renouveler', 'assigne' => 'responsable'],
        ]);
    }

    /**
     * @param list<array<string, mixed>> $conditions
     * @param list<array<string, mixed>> $actions
     */
    private static function creerRegleSiAbsente(Regles $regles, string $gabarit, string $declencheur, string $nom, array $conditions, array $actions): void
    {
        foreach ($regles->toutes() as $existante) {
            if ($existante['gabarit'] === $gabarit && $existante['nom'] === $nom) {
                return;
            }
        }

        $regles->creer([
            'nom' => $nom, 'gabarit' => $gabarit, 'declencheur' => $declencheur,
            'conditions' => $conditions, 'actions' => $actions, 'actif' => true,
        ]);
    }
}
