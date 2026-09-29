<?php

declare(strict_types=1);

namespace Ged\Recherche;

use PDO;

/**
 * Table du secteur Recherche : le texte indexé de chaque document du fonds, avec son index FULLTEXT.
 *
 * La table `documents` du Socle n'est jamais modifiée ; on ajoute à côté de quoi chercher, et l'index
 * peut être reconstruit à tout moment depuis le dossier (le dossier fait foi). Idempotent : rejouable.
 *
 * Moteur MyISAM : c'est un index DÉRIVÉ, reconstructible, et le FULLTEXT InnoDB de ce serveur est hors
 * service (`innodb_ft_min_token_size = 0` : aucune ligne n'est tokenisée). Le repli LIKE couvre ce que
 * MyISAM ne sait pas indexer (mots de moins de quatre lettres, fragments internes).
 */
final class Schema
{
    public const TABLE = 'recherche_textes';
    public const INDEX_PLEIN_TEXTE = 'ft_recherche';

    public static function installer(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS recherche_textes (
              cle CHAR(64) NOT NULL COMMENT 'sha256 du chemin : clé stable et indexable',
              chemin VARCHAR(1024) NOT NULL COMMENT 'fichier du fonds, relatif à STORAGE_ROOT',
              document_id INT UNSIGNED NULL COMMENT 'id de la ligne documents du Socle quand elle existe',
              titre VARCHAR(512) NOT NULL DEFAULT '',
              type VARCHAR(64) NULL,
              emetteur VARCHAR(255) NULL,
              date_document DATE NULL,
              montant DECIMAL(14,2) NULL,
              contenu LONGTEXT NOT NULL COMMENT 'texte lu du document (.meta.json texte, OCR ou fichier texte)',
              signature CHAR(64) NOT NULL COMMENT 'sha256 taille|mtime du .meta.json : détecte une modification',
              maj_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (cle),
              KEY k_document (document_id),
              KEY k_tri (date_document)
            ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        self::assurerMoteur($pdo);
        self::assurerIndexPleinTexte($pdo);
    }

    /** Bascule une table créée en InnoDB par une version antérieure : sans cela, son FULLTEXT ne répond pas. */
    public static function assurerMoteur(PDO $pdo): void
    {
        $moteur = $pdo->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()"
            . " AND TABLE_NAME = 'recherche_textes'")->fetchColumn();
        if (is_string($moteur) && strcasecmp($moteur, 'MyISAM') !== 0) {
            $pdo->exec('ALTER TABLE recherche_textes ENGINE=MyISAM');
        }
    }

    /** Ajoute l'index FULLTEXT s'il manque (table créée par une version antérieure, index retiré). */
    public static function assurerIndexPleinTexte(PDO $pdo): void
    {
        if (!self::indexPleinTextePresent($pdo)) {
            $pdo->exec('ALTER TABLE recherche_textes ADD FULLTEXT KEY ' . self::INDEX_PLEIN_TEXTE . ' (titre, contenu)');
        }
    }

    /**
     * Le FULLTEXT peut-il répondre ? L'index doit exister et le serveur savoir tokeniser le moteur employé.
     * Sinon la recherche bascule sur le repli LIKE, sans jamais rendre une liste vide muette.
     */
    public static function pleinTexteUtilisable(PDO $pdo): bool
    {
        if (!self::indexPleinTextePresent($pdo)) {
            return false;
        }
        $moteur = (string) $pdo->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()"
            . " AND TABLE_NAME = 'recherche_textes'")->fetchColumn();
        $variable = strcasecmp($moteur, 'MyISAM') === 0 ? '@@ft_min_word_len' : '@@innodb_ft_min_token_size';
        $minimum = $pdo->query("SELECT $variable")->fetchColumn();

        return is_scalar($minimum) && (int) $minimum >= 1;
    }

    /** L'index FULLTEXT est-il présent sur la table ? */
    public static function indexPleinTextePresent(PDO $pdo): bool
    {
        try {
            $etat = $pdo->query("SHOW INDEX FROM recherche_textes WHERE Key_name = '" . self::INDEX_PLEIN_TEXTE . "'");

            return $etat !== false && $etat->fetchColumn() !== false;
        } catch (\PDOException) {
            return false;   // table absente : l'appelant nommera l'erreur
        }
    }
}
