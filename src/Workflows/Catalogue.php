<?php

declare(strict_types=1);

namespace Ged\Workflows;

/**
 * Catalogue des composants du designer : il n'expose QUE ce que le moteur GEDv2 exécute réellement,
 * mappé sur les deux niveaux du modèle :
 *  - déclencheurs + conditions + actions → une RÈGLE ;
 *  - états → un CIRCUIT ; approbation → les actions de validation/assignation.
 *
 * Un nœud du catalogue de GEDv1 (24 nœuds : OCR, IA, webhook, délai, scan…) que le moteur ne sait pas
 * exécuter n'apparaît pas ici : il est recensé par `aPorter()` et reste hors du designer.
 *
 * Chaque entrée porte : type (clé du nœud dans le graphe), genre, catégorie, libellé, description,
 * sorties, et la description de ses champs de réglage (`config.champs`) + ses valeurs par défaut.
 */
final class Catalogue
{
    /** Champs d'une condition, dans l'ordre du moteur (`Conditions::CHAMPS`). */
    private const CONDITIONS = [
        'type' => ['Type de document', 'Le type rangé sur la fiche (Facture, Contrat…)', ['egal', 'different', 'contient', 'non_vide'], 'text'],
        'emetteur' => ['Émetteur', "L'émetteur (fournisseur, client…)", ['egal', 'different', 'contient', 'non_vide'], 'text'],
        'titre' => ['Titre', 'Le titre du document', ['egal', 'different', 'contient', 'non_vide'], 'text'],
        'montant' => ['Montant', 'Le montant lu sur la pièce', ['superieur', 'superieur_ou_egal', 'inferieur', 'inferieur_ou_egal', 'egal', 'different'], 'number'],
        'date' => ['Date', 'La date du document', ['egal', 'different', 'superieur', 'superieur_ou_egal', 'inferieur', 'inferieur_ou_egal'], 'date'],
        'etiquette' => ['Étiquette', 'Une étiquette posée sur le document', ['dans', 'egal', 'non_vide'], 'text'],
        'etiquettes' => ['Étiquettes (liste)', 'La liste complète des étiquettes', ['dans', 'egal', 'non_vide'], 'text'],
        'texte' => ['Texte', 'Le texte extrait de la pièce', ['contient', 'non_vide'], 'text'],
        'delai_echeance_jours' => ["Délai d'échéance (jours)", "Nombre de jours avant l'échéance (J-90…)", ['superieur', 'superieur_ou_egal', 'inferieur', 'inferieur_ou_egal', 'egal'], 'number'],
    ];

    /** Actions « courantes » exécutées par le moteur. */
    private const ACTIONS = [
        'poser' => ['Poser un champ', 'Écrit un champ du document', [['champ', 'Champ', 'select', 'champs_posables'], ['valeur', 'Valeur', 'text', null]]],
        'etiqueter' => ['Étiqueter', 'Ajoute une étiquette au document', [['etiquette', 'Étiquette', 'text', null]]],
        'notifier' => ['Notifier', 'Journalise une notification ({titre}, {montant}, {jours}…)', [['destinataire', 'Destinataire', 'text', null], ['message', 'Message', 'textarea', null]]],
        'appeler_jonction' => ['Appeler une jonction', 'Journalise un appel à une jonction externe', [['cible', 'Jonction', 'text', null]]],
    ];

    /** Actions d'approbation : la validation par rôle, portée par le moteur. */
    private const APPROBATION = [
        'demander_validation' => ['Demander une validation', 'Fait entrer le document dans le circuit, à l’état visé, assigné à un rôle',
            [['vers', 'État visé', 'select', 'etats'], ['assigne', 'Rôle', 'text', null]]],
        'assigner' => ['Assigner', 'Assigne une instance déjà ouverte à un rôle',
            [['vers', 'État visé', 'select', 'etats'], ['assigne', 'Rôle', 'text', null]]],
    ];

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public static function tout(): array
    {
        $declencheurs = [[
            'type' => 'declencheur', 'genre' => 'declencheur', 'categorie' => 'declencheurs',
            'libelle' => 'Déclencheur', 'description' => 'Ce qui fait réagir la règle : un dépôt, une mise à jour, une échéance.',
            'couleur' => 'accent',
            'sorties' => ['default'],
            'config' => [
                'champs' => [['nom' => 'evenement', 'libelle' => 'Déclencheur', 'type' => 'select',
                    'options' => self::declencheurs()]],
                'defaut' => ['evenement' => 'document_depose'],
            ],
        ]];

        $conditions = [];
        foreach (self::CONDITIONS as $champ => [$libelle, $description, $operateurs, $typeValeur]) {
            $conditions[] = [
                'type' => 'condition_' . $champ, 'genre' => 'condition', 'categorie' => 'conditions',
                'libelle' => $libelle, 'description' => $description, 'couleur' => 'regle',
                'sorties' => ['default'],
                'config' => [
                    'champ' => $champ,
                    'operateurs' => $operateurs,
                    'champs' => [
                        ['nom' => 'operateur', 'libelle' => 'Opérateur', 'type' => 'select', 'options' => self::operateurs($operateurs)],
                        ['nom' => 'valeur', 'libelle' => 'Valeur', 'type' => $typeValeur],
                    ],
                    'defaut' => ['champ' => $champ, 'operateur' => $operateurs[0], 'valeur' => ''],
                ],
            ];
        }

        return [
            'declencheurs' => $declencheurs,
            'conditions' => $conditions,
            'actions' => self::famille('actions', self::ACTIONS, 'ok'),
            'etats' => [[
                'type' => 'etat', 'genre' => 'etat', 'categorie' => 'etats',
                'libelle' => 'État', 'description' => 'Un état du circuit ; les liaisons entre états sont des transitions, chacune avec son droit.',
                'couleur' => 'habitude',
                'sorties' => ['default'],
                'config' => [
                    'champs' => [
                        ['nom' => 'nom', 'libelle' => 'Identifiant', 'type' => 'text', 'requis' => true],
                        ['nom' => 'libelle', 'libelle' => 'Libellé', 'type' => 'text'],
                        ['nom' => 'initial', 'libelle' => 'État initial', 'type' => 'checkbox'],
                        ['nom' => 'final', 'libelle' => 'État final', 'type' => 'checkbox'],
                    ],
                    'defaut' => ['nom' => '', 'libelle' => '', 'initial' => false, 'final' => false],
                ],
            ]],
            'approbation' => self::famille('approbation', self::APPROBATION, 'ia'),
        ];
    }

    /**
     * @param array<string, array{0:string,1:string,2:list<array{0:string,1:string,2:string,3:?string}>}> $famille
     * @return list<array<string, mixed>>
     */
    private static function famille(string $categorie, array $famille, string $couleur): array
    {
        $entrees = [];
        foreach ($famille as $action => [$libelle, $description, $champs]) {
            $configChamps = [];
            foreach ($champs as [$nom, $libelleChamp, $type, $source]) {
                $champ = ['nom' => $nom, 'libelle' => $libelleChamp, 'type' => $type];
                if ($source !== null) {
                    $champ['source'] = $source;
                }
                $configChamps[] = $champ;
            }
            $entrees[] = [
                'type' => 'action_' . $action, 'genre' => 'action', 'categorie' => $categorie,
                'libelle' => $libelle, 'description' => $description, 'couleur' => $couleur,
                'sorties' => ['default'],
                'config' => ['action' => $action, 'champs' => $configChamps, 'defaut' => ['type' => $action]],
            ];
        }

        return $entrees;
    }

    /** @return array<string, string> */
    public static function declencheurs(): array
    {
        return [
            'document_depose' => 'Document déposé',
            'document_modifie' => 'Document mis à jour',
            'echeance' => 'Échéance planifiée',
        ];
    }

    /** @param list<string> $operateurs @return array<string, string> */
    private static function operateurs(array $operateurs): array
    {
        $libelles = [
            'egal' => 'Égal à', 'different' => 'Différent de', 'contient' => 'Contient',
            'superieur' => 'Supérieur à', 'superieur_ou_egal' => 'Supérieur ou égal à',
            'inferieur' => 'Inférieur à', 'inferieur_ou_egal' => 'Inférieur ou égal à',
            'dans' => 'Dans la liste', 'non_vide' => 'Non vide',
        ];

        return array_combine($operateurs, array_map(static fn (string $o): string => $libelles[$o] ?? $o, $operateurs));
    }

    /** @return array<string, mixed>|null */
    public static function parType(string $type): ?array
    {
        foreach (self::tout() as $entrees) {
            foreach ($entrees as $entree) {
                if ($entree['type'] === $type) {
                    return $entree;
                }
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function types(): array
    {
        $types = [];
        foreach (self::tout() as $entrees) {
            foreach ($entrees as $entree) {
                $types[] = (string) $entree['type'];
            }
        }

        return $types;
    }

    /**
     * Nœuds de GEDv1 que le moteur GEDv2 ne sait pas (encore) exécuter : hors du designer, à porter plus tard.
     *
     * @return list<array{type:string, libelle:string, raison:string}>
     */
    public static function aPorter(): array
    {
        return [
            ['type' => 'process_ocr', 'libelle' => 'OCR', 'raison' => 'la lecture du texte appartient à l’Ingestion, pas au moteur de workflow'],
            ['type' => 'process_ai_extract', 'libelle' => 'Extraction IA', 'raison' => 'le classement IA est le métier du Classeur, hors workflow'],
            ['type' => 'process_classify', 'libelle' => 'Classification', 'raison' => 'le classement appartient au secteur Classement'],
            ['type' => 'trigger_scan', 'libelle' => 'Scan dossier', 'raison' => 'l’entrée dossier est une source d’ingestion, déjà couverte par « document déposé »'],
            ['type' => 'trigger_upload', 'libelle' => 'Upload', 'raison' => 'l’envoi par l’interface arrive comme dépôt'],
            ['type' => 'trigger_manual', 'libelle' => 'Démarrage manuel', 'raison' => 'aucun déclencheur manuel dans le moteur actuel'],
            ['type' => 'trigger_tag_added', 'libelle' => 'Tag ajouté', 'raison' => 'l’événement « document mis à jour » + condition d’étiquette le couvre'],
            ['type' => 'trigger_validation_changed', 'libelle' => 'Validation changée', 'raison' => 'la transition trace déjà le changement d’état'],
            ['type' => 'action_create_approval', 'libelle' => 'Créer approbation (jeton)', 'raison' => 'l’approbation par courriel à jeton n’est pas encore portée'],
            ['type' => 'action_request_approval', 'libelle' => 'Demande d’approbation par courriel', 'raison' => 'le canal courriel est celui des Jonctions, à brancher plus tard'],
            ['type' => 'action_assign_group', 'libelle' => 'Assigner au groupe', 'raison' => 'les rôles du moteur sont des noms, pas encore des groupes'],
            ['type' => 'action_send_email', 'libelle' => 'Envoyer un courriel', 'raison' => 'l’envoi de courriel passe par les Jonctions'],
            ['type' => 'action_webhook', 'libelle' => 'Webhook', 'raison' => 'aucune action webhook dans le moteur'],
            ['type' => 'action_set_validation', 'libelle' => 'Marquer validé/rejeté', 'raison' => 'la transition d’état porte déjà le verdict'],
            ['type' => 'wait_approval', 'libelle' => 'Attendre approbation', 'raison' => 'aucune attente bloquante : le moteur est court et sans démon'],
            ['type' => 'timer_delay', 'libelle' => 'Délai', 'raison' => 'pas de minuterie : la reprise sur échéance est portée par le déclencheur « échéance »'],
        ];
    }
}
