<?php

declare(strict_types=1);

namespace Ged\Ui;

/**
 * Données FIXES des maquettes cliquables (lot MAQUETTES). Entièrement fictives : mêmes émetteurs que le
 * corpus synthétique `courrier-100p`. Chaque écran sera ensuite branché sur les vrais services de son
 * secteur ; la maquette reste le contrat visuel (tests Playwright `tests/e2e/maquettes.spec.ts`).
 */
final class Maquette
{
    public const TYPES = ['Facture', 'Note de crédit', 'Contrat', 'Correspondance', 'Reçu', 'Document RH', 'Autre'];

    /** @return array{auto:int, a_controler:int, en_cours:int, seuil:int, lots:int} */
    public static function bilan(): array
    {
        return ['auto' => 47, 'a_controler' => 5, 'en_cours' => 1, 'seuil' => 85, 'lots' => 1];
    }

    /** @return list<array<string, mixed>> */
    public static function aTraiter(): array
    {
        return [
            self::piece(101, 'Relevé de compte courant — décembre 2025', 'Banque Fictive du Jura', 'Correspondance', 62, 'IA',
                'aucun type livré ne décrit un relevé', '2026-01-10', null, 3, ['banque'],
                ['Banque Fictive du Jura', 'Grand-Rue 1 · 2800 Delémont', '', 'Delémont, le 10.01.2026', 'Concerne : Relevé de compte courant — décembre 2025', 'Solde au 31.12.2025 : CHF 18 402.35', 'Mouvements du mois en annexe.']),
            self::piece(102, 'Décompte de charges 2025', 'Gérance Immobilière Modèle SA', 'Facture', 71, 'IA',
                'le mot « facture » n’apparaît pas dans le texte', '2026-01-08', 1284.60, 2, [],
                ['Gérance Immobilière Modèle SA', 'Place du Test 3 · 2400 Le Locle', '', 'Le Locle, le 08.01.2026', 'Concerne : Décompte de charges 2025', 'Solde en votre défaveur : CHF 1 284.60', 'Payable à 30 jours.']),
            self::piece(103, 'Contrat d’assurance RC entreprise', 'Assurances Démonstration SA', 'Contrat', 81, 'IA',
                'confiance sous le seuil de 85 %', '2026-02-02', null, 6, ['assurance'],
                ['Assurances Démonstration SA', 'Avenue du Contrôle 8 · 2000 Neuchâtel', '', 'Neuchâtel, le 02.02.2026', 'Concerne : Police RC entreprise n° RC-2026-118', 'Article 1. Objet de l’assurance', 'Article 2. Prime annuelle : CHF 1 180.00']),
            self::piece(104, 'Courrier — rappel de service', 'Garage du Banc d’Essai Sàrl', 'Correspondance', 93, 'Habitude',
                '2 choix sur 3 : l’habitude n’est pas encore formée', '2026-02-11', null, 2, ['garage'],
                ['Garage du Banc d’Essai Sàrl', 'Rue des Pistons 4 · 2300 La Chaux-de-Fonds', '', 'La Chaux-de-Fonds, le 11.02.2026', 'Concerne : Rappel — service annuel', 'Votre véhicule arrive au terme de son intervalle de service.']),
            self::piece(105, 'Décision sur opposition — dossier 2026-114', 'Office cantonal fictif des prestations', 'Autre', 88, 'Règle',
                '« Autre » n’est jamais validé seul', '2026-01-18', null, 4, [],
                ['Office cantonal fictif des prestations', 'Case postale 12 · 2001 Neuchâtel', '', 'Neuchâtel, le 18.01.2026', 'Décision sur opposition', 'Considérant 1. …', 'Par ces motifs, l’office décide :']),
        ];
    }

    /**
     * @param list<string> $etiquettes
     * @param list<string> $texte
     * @return array<string, mixed>
     */
    private static function piece(int $id, string $titre, string $emetteur, string $type, int $confiance, string $source,
        string $raison, string $date, ?float $montant, int $pages, array $etiquettes, array $texte): array
    {
        return compact('id', 'titre', 'emetteur', 'type', 'confiance', 'source', 'raison', 'date', 'montant', 'pages', 'etiquettes', 'texte');
    }

    /** @return list<array<string, mixed>> */
    public static function bibliotheque(): array
    {
        $l = [
            [201, 'Facture AN-5001', 'Facture', 'Atelier Numérique Exemple Sàrl', '2026-01-12', 4244.74, '2026/Facture/Atelier_Numerique_Exemple_Sarl'],
            [202, 'Note de crédit AN-5001-NC', 'Note de crédit', 'Atelier Numérique Exemple Sàrl', '2026-01-20', -312.00, '2026/Note_de_credit/Atelier_Numerique_Exemple_Sarl'],
            [203, 'Contrat de bail commercial', 'Contrat', 'Gérance Immobilière Modèle SA', '2026-01-15', null, '2026/Contrat/Gerance_Immobiliere_Modele_SA'],
            [204, 'Contrat de maintenance CM-2026-04', 'Contrat', 'Atelier Numérique Exemple Sàrl', '2026-02-05', null, '2026/Contrat/Atelier_Numerique_Exemple_Sarl'],
            [205, 'Facture PS-2026-101', 'Facture', 'Papeterie des Sapins Fictive SA', '2026-01-09', 186.40, '2026/Facture/Papeterie_des_Sapins_Fictive_SA'],
            [206, 'Facture PS-2026-102', 'Facture', 'Papeterie des Sapins Fictive SA', '2026-01-23', 92.15, '2026/Facture/Papeterie_des_Sapins_Fictive_SA'],
            [207, 'Reçu R-2002', 'Reçu', 'Papeterie des Sapins Fictive SA', '2026-02-06', 118.90, '2026/Recu/Papeterie_des_Sapins_Fictive_SA'],
            [208, 'Facture énergie EE-2026-0212', 'Facture', 'Énergies Exemple SA', '2026-02-12', 612.30, '2026/Facture/Energies_Exemple_SA'],
            [209, 'Attestation de travail', 'Document RH', 'Commune de Modeleville', '2026-01-22', null, '2026/Document_RH/Commune_de_Modeleville'],
            [210, 'Police RC — avenant 1', 'Contrat', 'Assurances Démonstration SA', '2026-03-01', null, '2026/Contrat/Assurances_Demonstration_SA'],
            [211, 'Reçu de paiement en espèces', 'Reçu', 'Garage du Banc d’Essai Sàrl', '2026-01-16', 240.00, '2026/Recu/Garage_du_Banc_d_Essai_Sarl'],
            [212, 'Facture AN-5002', 'Facture', 'Atelier Numérique Exemple Sàrl', '2026-02-18', 1320.00, '2026/Facture/Atelier_Numerique_Exemple_Sarl'],
        ];

        return array_map(static fn (array $d) => [
            'id' => $d[0], 'titre' => $d[1], 'type' => $d[2], 'emetteur' => $d[3], 'date' => $d[4],
            'montant' => $d[5], 'dossier' => $d[6], 'fichier' => strtolower(str_replace([' ', '—'], ['_', '-'], $d[1])) . '.pdf',
        ], $l);
    }

    /** @return list<array{nom:string, compte:int, icone:string}> */
    public static function vues(): array
    {
        return [
            ['nom' => 'Tous les documents', 'compte' => 1284, 'icone' => 'tout'],
            ['nom' => 'Factures à payer', 'compte' => 14, 'icone' => 'facture'],
            ['nom' => 'Contrats à échéance', 'compte' => 3, 'icone' => 'contrat'],
            ['nom' => 'Ajoutés cette semaine', 'compte' => 52, 'icone' => 'recent'],
            ['nom' => 'Documents RH', 'compte' => 27, 'icone' => 'rh'],
        ];
    }

    /** @return list<array{nom:string, enfants:list<string>}> */
    public static function arbre(): array
    {
        return [
            ['nom' => '2026', 'enfants' => ['Contrat', 'Correspondance', 'Document RH', 'Facture', 'Note de crédit', 'Reçu']],
            ['nom' => '2025', 'enfants' => ['Contrat', 'Correspondance', 'Facture']],
        ];
    }

    /** @return array<string, mixed> */
    public static function document(int $id): array
    {
        $doc = null;
        foreach (self::bibliotheque() as $d) {
            if ($d['id'] === $id) {
                $doc = $d;
            }
        }
        $doc ??= self::bibliotheque()[0];

        return $doc + [
            'texte' => [$doc['emetteur'], 'Avenue Léopold-Robert 99 · 2300 La Chaux-de-Fonds', '', 'La Chaux-de-Fonds, le ' . date('d.m.Y', strtotime($doc['date'])),
                'Concerne : ' . $doc['titre'], ...($doc['montant'] === null ? ['Article 1. Objet', 'Article 2. Durée et résiliation']
                    : ['Désignation — quantité — prix', 'Total TTC : CHF ' . number_format((float) $doc['montant'], 2, '.', "'")])],
            'versions' => [
                ['n' => 3, 'quand' => '2026-02-20 09:12', 'qui' => 'Olivier', 'quoi' => 'annotation ajoutée', 'taille' => '212 Ko'],
                ['n' => 2, 'quand' => '2026-02-14 16:40', 'qui' => 'Olivier', 'quoi' => 'page 2 tournée', 'taille' => '209 Ko'],
                ['n' => 1, 'quand' => '2026-01-12 08:03', 'qui' => 'ingestion', 'quoi' => 'pièce créée depuis le lot courrier-du-matin (p. 8-9)', 'taille' => '208 Ko'],
            ],
            'historique' => [
                ['quand' => '2026-01-12 08:03', 'quoi' => 'Classé automatiquement : Facture (confiance 95 %, indice « facture » p. 1)'],
                ['quand' => '2026-01-12 08:03', 'quoi' => 'Rangé dans ' . $doc['dossier']],
                ['quand' => '2026-02-14 16:40', 'quoi' => 'Nouvelle version (v2) : page 2 tournée'],
                ['quand' => '2026-02-20 09:12', 'quoi' => 'Workflow « Facture à valider » : visa donné'],
            ],
            'notes' => [['qui' => 'Olivier', 'quand' => '2026-02-20', 'texte' => 'Contrôlé avec le bon de commande BC-114.']],
            'liens' => [['id' => 202, 'titre' => 'Note de crédit AN-5001-NC', 'relation' => 'corrige']],
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function workflows(): array
    {
        return [
            ['id' => 1, 'nom' => 'Facture fournisseur à valider', 'declencheur' => 'Document classé « Facture »', 'actif' => true, 'executions' => 128,
                'etapes' => ['Classé Facture', 'Montant ≤ 1 000 CHF ?', 'Visa responsable', 'Envoi à K-Time']],
            ['id' => 2, 'nom' => 'Contrat à échéance', 'declencheur' => 'Planifié — chaque jour 07:00', 'actif' => true, 'executions' => 12,
                'etapes' => ['Contrat', 'Échéance < 90 j ?', 'Rappel au responsable']],
            ['id' => 3, 'nom' => 'Courrier RH confidentiel', 'declencheur' => 'Document classé « Document RH »', 'actif' => false, 'executions' => 0,
                'etapes' => ['Document RH', 'Droits : groupe RH seul', 'Notification RH']],
        ];
    }

    /** @return list<array{titre:string, extrait:string, id:int, type:string, date:string}> */
    public static function resultats(string $q): array
    {
        $q = $q !== '' ? $q : 'maintenance';

        return [
            ['id' => 204, 'titre' => 'Contrat de maintenance CM-2026-04', 'type' => 'Contrat', 'date' => '2026-02-05',
                'extrait' => 'Article 1. Prestation de «' . $q . '» informatique : l’Atelier assure la ' . $q . ' des postes…'],
            ['id' => 212, 'titre' => 'Facture AN-5002', 'type' => 'Facture', 'date' => '2026-02-18',
                'extrait' => 'Forfait ' . $q . ' février 2026 — 12 h × CHF 110.00'],
            ['id' => 201, 'titre' => 'Facture AN-5001', 'type' => 'Facture', 'date' => '2026-01-12',
                'extrait' => 'Intervention hors contrat de ' . $q . ' — déplacement compris'],
        ];
    }
}
