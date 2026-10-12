<?php

declare(strict_types=1);

namespace Ged\Socle;

use PDO;

/** Tables du secteur Socle. Idempotent : rejouable sur une base existante. */
final class Schema
{
    public static function installer(PDO $pdo): void
    {
        self::assurerTablesBase($pdo);
        self::assurerColonneVersionPartage($pdo);
        // Parité Paperless (lot ged-parite-final-1) : `UserViewSet.destroy` DÉSACTIVE un compte. La colonne
        // n'est pas une table de plus (plafond V2-01 intact) et vaut 1 par défaut (aucun compte muet).
        self::assurerColonneActif($pdo, 'comptes');
    }

    /**
     * Tables de base du secteur (documents, versions, déplacements, comptes, collaboration, installation),
     * sans les évolutions de colonnes. Appelée par `installer` et par la migration versionnée (`Migrations`).
     */
    public static function assurerTablesBase(PDO $pdo): void
    {
        // Le document : une ligne = un fichier du fonds. La base n'est qu'un index de ce que
        // le dossier montre ; tout ce qui doit survivre à une reconstruction vit dans le `.meta.json`.
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS documents (
              id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              chemin_relatif VARCHAR(1024) NOT NULL COMMENT 'chemin sous la racine documentaire, séparateurs /',
              nom VARCHAR(255) NOT NULL,
              titre VARCHAR(512) NOT NULL,
              type VARCHAR(64) NULL,
              emetteur VARCHAR(255) NULL,
              date_document DATE NULL,
              montant DECIMAL(14,2) NULL,
              etiquettes JSON NULL COMMENT 'liste de chaînes',
              source VARCHAR(20) NULL COMMENT 'dossier | adresse | ecran | mobile',
              statut VARCHAR(20) NOT NULL DEFAULT 'a_controler',
              empreinte CHAR(64) NOT NULL COMMENT 'sha256 du contenu : sert au suivi des déplacements',
              taille INT UNSIGNED NOT NULL,
              mime VARCHAR(127) NOT NULL,
              meta JSON NOT NULL COMMENT 'contenu canonique du .meta.json (le dossier fait foi)',
              supprime_le DATETIME NULL COMMENT 'drapeau corbeille : le fichier vit alors sous .corbeille/',
              supprime_par VARCHAR(64) NULL,
              absent_depuis DATETIME NULL COMMENT 'fichier disparu du fonds : la ligne reste (zéro suppression)',
              cree_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              maj_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              UNIQUE KEY uq_chemin (chemin_relatif(700)),
              KEY k_empreinte (empreinte),
              KEY k_corbeille (supprime_le),
              KEY k_statut (statut, supprime_le, absent_depuis, date_document, id),
              KEY k_vivant (supprime_le, absent_depuis, chemin_relatif(700))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        // Une table créée par une version antérieure n'a pas l'index du statut : l'écran « À traiter »
        // l'exige pour borner sa lecture à la file, jamais au fonds entier.
        self::assurerIndexStatut($pdo);

        // PERF-3 : la Bibliothèque compte les vivants et dresse l'arborescence sans lire une seule ligne
        // du fonds. Sans cet index, le moindre COUNT parcourt le fonds et l'écran dépasse son seuil.
        self::assurerIndexVivant($pdo);

        // Les versions : une archive par contenu observé, dans `.versions/<fichier>/` à côté du document.
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS document_versions (
              document_id INT UNSIGNED NOT NULL,
              numero SMALLINT UNSIGNED NOT NULL,
              nom_archive VARCHAR(255) NOT NULL COMMENT 'ex. v001_1a2b3c4d.pdf',
              empreinte CHAR(64) NOT NULL,
              taille INT UNSIGNED NOT NULL,
              cree_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (document_id, numero),
              KEY k_empreinte (empreinte)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        // Trace des déplacements : par le dépôt, par mise à jour, ou constatés par l'indexeur
        // quand le dossier a été manipulé hors application (la base suit le dossier).
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS document_deplacements (
              id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              document_id INT UNSIGNED NOT NULL,
              chemin_avant VARCHAR(1024) NOT NULL,
              chemin_apres VARCHAR(1024) NOT NULL,
              cause VARCHAR(20) NOT NULL COMMENT 'depot | mise_a_jour | indexeur',
              auteur VARCHAR(64) NOT NULL DEFAULT 'indexeur',
              date_deplacement DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              KEY k_document (document_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        // Comptes : minimal, pour l'installateur (--admin). Le secteur Sécurité reprendra la gestion.
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS comptes (
              id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              identifiant VARCHAR(64) NOT NULL,
              empreinte VARCHAR(255) NOT NULL COMMENT 'password_hash',
              role VARCHAR(20) NOT NULL DEFAULT 'admin',
              actif TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'mise en veille (UserViewSet.destroy), zéro suppression',
              cree_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              UNIQUE KEY uq_identifiant (identifiant)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        // État de l'installation (version du schéma, date, compte posé) : jamais une donnée utilisateur.
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS installation (
              cle VARCHAR(64) PRIMARY KEY,
              valeur VARCHAR(255) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        // Décision juge V2-12d7 : une seule table typée pour toute la collaboration. Retrait et
        // révocation restent des marqueurs, jamais un DELETE ; les colonnes nulles dépendent du type.
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS document_collaboration (
              id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              document_id INT UNSIGNED NOT NULL,
              type ENUM('note','lien','partage','evenement') NOT NULL,
              auteur VARCHAR(128) NOT NULL,
              texte TEXT NULL,
              document_source_id INT UNSIGNED NULL,
              document_cible_id INT UNSIGNED NULL,
              libelle VARCHAR(128) NULL,
              type_partage ENUM('interne','externe') NULL,
              cible_type ENUM('compte','groupe','lien') NULL,
              cible_id INT UNSIGNED NULL,
              jeton_empreinte CHAR(64) NULL,
              action VARCHAR(64) NULL,
              objet_id INT UNSIGNED NULL,
              migration_origine VARCHAR(48) NULL,
              migration_id INT UNSIGNED NULL,
              cree_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              maj_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              retire_le DATETIME NULL,
              expire_le DATETIME NULL,
              revoque_le DATETIME NULL,
              KEY k_collaboration_document (document_id, type, retire_le, cree_le),
              KEY k_lien_source (document_source_id, retire_le),
              KEY k_lien_cible (document_cible_id, retire_le),
              KEY k_partage_document (document_id, type, revoque_le),
              KEY k_partage_cible (cible_type, cible_id, revoque_le),
              UNIQUE KEY uq_collaboration_migration (migration_origine, migration_id),
              UNIQUE KEY uq_partage_jeton (jeton_empreinte)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
        self::migrerCollaborationV212d($pdo);
    }

    /**
     * Pose la colonne `version` de `document_collaboration` si elle manque (table créée avant elle) :
     * un partage externe peut viser une version précise du document, pas seulement la courante.
     * Idempotent, rejouable.
     */
    public static function assurerColonneVersionPartage(PDO $pdo): void
    {
        if (self::colonneExiste($pdo, 'document_collaboration', 'version')) {
            return;
        }
        $pdo->exec('ALTER TABLE document_collaboration ADD COLUMN version INT UNSIGNED NULL '
            . "COMMENT 'version du document partagée, NULL = courante'");
    }

    private static function colonneExiste(PDO $pdo, string $table, string $colonne): bool
    {
        $requete = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns'
            . ' WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
        $requete->execute([$table, $colonne]);

        return (int) $requete->fetchColumn() > 0;
    }

    /**
     * Pose la colonne `actif` (défaut 1) sur une table de référentiel/compte si elle manque : le retrait
     * par l'API est une mise en veille, jamais une suppression (règle 4). Idempotent, rejouable.
     */
    private static function assurerColonneActif(PDO $pdo, string $table): void
    {
        if (self::colonneExiste($pdo, $table, 'actif')) {
            return;
        }
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN actif TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'mise en veille, zéro suppression'");
    }

    /**
     * Pose l'index du statut s'il manque (table créée avant lui) : sans cet index, une requête
     * `WHERE statut = ?` parcourt tout le fonds. `(statut, supprime_le, absent_depuis)` couvre aussi le
     * comptage vivant du bilan, sans lire une seule ligne de document. Idempotent, rejouable (y compris pour corriger un
     * ancien index à une seule colonne).
     */
    public static function assurerIndexStatut(PDO $pdo): void
    {
        $colonnes = $pdo->query("SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX)"
            . " FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()"
            . " AND TABLE_NAME = 'documents' AND INDEX_NAME = 'k_statut'")->fetchColumn();
        if (is_string($colonnes) && $colonnes !== '') {
            if (strcasecmp($colonnes, 'statut,supprime_le,absent_depuis,date_document,id') === 0) {
                return;
            }
            $pdo->exec('ALTER TABLE documents DROP KEY k_statut');
        }
        $pdo->exec('ALTER TABLE documents ADD KEY k_statut (statut, supprime_le, absent_depuis, date_document, id)');
    }

    /**
     * Pose l'index des documents VIVANTS s'il manque (table créée avant lui) : `(supprime_le, absent_depuis,
     * chemin_relatif(700))`. Il rend indexé le comptage des vivants (`COUNT(*)`, aucune ligne de fonds lue)
     * et couvre l'arborescence de la Bibliothèque (PERF-3), qui sans lui parcourt tout le fonds. Idempotent.
     */
    public static function assurerIndexVivant(PDO $pdo): void
    {
        $colonnes = $pdo->query("SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX)"
            . " FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()"
            . " AND TABLE_NAME = 'documents' AND INDEX_NAME = 'k_vivant'")->fetchColumn();
        if (is_string($colonnes) && $colonnes !== '') {
            return;
        }
        $pdo->exec('ALTER TABLE documents ADD KEY k_vivant (supprime_le, absent_depuis, chemin_relatif(700))');
    }

    /** Tables de l'index documentaire, dans l'ordre de destruction/reconstruction (jamais comptes ni installation). */
    public const TABLES_INDEX = ['documents', 'document_versions', 'document_deplacements'];

    /** Migration V2-12d7 : copie vérifiée avant le retrait des cinq tables V2-12d. */
    private static function migrerCollaborationV212d(PDO $pdo): void
    {
        $anciennes = ['document_notes', 'document_liens', 'document_partages', 'document_collaboration_evenements', 'reconstruction_index_jobs'];
        $presentes = array_values(array_filter($anciennes, fn (string $table): bool => self::tableExiste($pdo, $table)));
        if ($presentes === []) return;
        // MySQL valide implicitement les DDL : vérifier chaque INSERT avant le premier DROP est donc
        // plus sûr qu'une transaction illusoire. Une erreur laisse les anciennes tables intactes.
        $copies = [
            'document_notes' => "INSERT INTO document_collaboration (document_id,type,auteur,texte,cree_le,maj_le,retire_le,migration_origine,migration_id) SELECT document_id,'note',auteur,texte,cree_le,maj_le,retire_le,'document_notes',id FROM document_notes ON DUPLICATE KEY UPDATE migration_id=document_collaboration.migration_id",
            'document_liens' => "INSERT INTO document_collaboration (document_id,type,auteur,document_source_id,document_cible_id,libelle,cree_le,retire_le,migration_origine,migration_id) SELECT document_source_id,'lien',auteur,document_source_id,document_cible_id,libelle,cree_le,retire_le,'document_liens',id FROM document_liens ON DUPLICATE KEY UPDATE migration_id=document_collaboration.migration_id",
            'document_partages' => "INSERT INTO document_collaboration (document_id,type,auteur,type_partage,cible_type,cible_id,jeton_empreinte,cree_le,expire_le,revoque_le,migration_origine,migration_id) SELECT document_id,'partage',auteur,type_partage,cible_type,cible_id,jeton_empreinte,cree_le,expire_le,revoque_le,'document_partages',id FROM document_partages ON DUPLICATE KEY UPDATE migration_id=document_collaboration.migration_id",
            'document_collaboration_evenements' => "INSERT INTO document_collaboration (document_id,type,auteur,action,objet_id,cree_le,migration_origine,migration_id) SELECT document_id,'evenement',auteur,action,objet_id,cree_le,'document_collaboration_evenements',id FROM document_collaboration_evenements ON DUPLICATE KEY UPDATE migration_id=document_collaboration.migration_id",
        ];
        foreach ($copies as $table => $sql) {
            if (!in_array($table, $presentes, true)) continue;
            $attendues = (int) $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
            $pdo->exec($sql);
            $s = $pdo->prepare('SELECT COUNT(*) FROM document_collaboration WHERE migration_origine = ?'); $s->execute([$table]);
            if ((int) $s->fetchColumn() !== $attendues) throw new \RuntimeException("migration collaboration : copie $table incomplète");
        }
        if (in_array('reconstruction_index_jobs', $presentes, true)) {
            $ancien = $pdo->query('SELECT id, etat, total, traite, auteur, erreur FROM reconstruction_index_jobs ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
            if (is_array($ancien)) {
                $registre = new Registre(new \Ged\Config(dirname(__DIR__, 2)));
                $registre->modifier('reconstruction-index', static fn (array $etat): array => [
                    'id' => (int) $ancien['id'], 'etat' => (string) $ancien['etat'], 'total' => (int) $ancien['total'],
                    'traite' => (int) $ancien['traite'], 'auteur' => (string) $ancien['auteur'], 'erreur' => $ancien['erreur'],
                ]);
            }
        }
        foreach ($presentes as $table) $pdo->exec("DROP TABLE `$table`");
    }

    private static function tableExiste(PDO $pdo, string $table): bool
    {
        $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $s->execute([$table]); return (int) $s->fetchColumn() === 1;
    }
}
