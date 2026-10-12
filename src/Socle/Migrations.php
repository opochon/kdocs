<?php

declare(strict_types=1);

namespace Ged\Socle;

use PDO;
use RuntimeException;

/**
 * Migrations versionnées du secteur Socle : un numéro de version, des étapes idempotentes, jamais
 * destructives en marche avant. La version se LIT dans le schéma (colonne présente), aucun état parallèle.
 *
 *  - version 1 : les tables de base (`documents`, versions, déplacements, collaboration) ;
 *  - version 2 : la version partagée d'un lien externe (`ShareLink.file_version`) sur `document_collaboration`.
 *
 * `retour()` refuse de descendre dès qu'un partage viserait une version précise : une trace de partage ne
 * doit jamais disparaître en silence (zéro suppression de donnée).
 */
final class Migrations
{
    public const VERSION = 2;

    /** @var array<int, string> */
    public const ETAPES = [
        1 => 'tables de base (documents, versions, déplacements, collaboration)',
        2 => 'version du document partagée (ShareLink.file_version)',
    ];

    public static function monter(PDO $pdo, int $cible = self::VERSION): void
    {
        for ($prochaine = self::version($pdo) + 1; $prochaine <= $cible; $prochaine++) {
            self::appliquer($pdo, $prochaine);
        }
        // Un schéma v0 dont l'installateur pose déjà la colonne se lit directement en version 2.
    }

    /** Descend en retirant les objets des versions supérieures ; refuse si un partage versionné serait perdu. */
    public static function retour(PDO $pdo, int $cible): void
    {
        if (!isset(self::ETAPES[$cible])) {
            throw new RuntimeException("retour : version cible inconnue ($cible)");
        }
        for ($version = self::VERSION; $version > $cible; $version--) {
            self::retirer($pdo, $version);
        }
    }

    /** Version lue dans le schéma : 0 sans la table, 1 avec la table seule, 2 avec la colonne `version`. */
    public static function version(PDO $pdo): int
    {
        if (!self::tableExiste($pdo, 'document_collaboration')) {
            return 0;
        }

        return self::colonneExiste($pdo, 'document_collaboration', 'version') ? 2 : 1;
    }

    private static function appliquer(PDO $pdo, int $version): void
    {
        match ($version) {
            1 => Schema::assurerTablesBase($pdo),
            2 => Schema::assurerColonneVersionPartage($pdo),
            default => throw new RuntimeException("migration : étape inconnue ($version)"),
        };
    }

    private static function retirer(PDO $pdo, int $version): void
    {
        if ($version !== 2) {
            throw new RuntimeException("retour : étape $version non réversible (objet de base)");
        }
        if (!self::colonneExiste($pdo, 'document_collaboration', 'version')) {
            return;
        }
        $perdues = (int) $pdo->query('SELECT COUNT(*) FROM document_collaboration WHERE version IS NOT NULL')->fetchColumn();
        if ($perdues > 0) {
            throw new RuntimeException(sprintf(
                'retour v2 refusé : %d partage(s) visent une version précise, le retrait perdrait cette trace',
                $perdues,
            ));
        }
        $pdo->exec('ALTER TABLE document_collaboration DROP COLUMN version');
    }

    public static function tableExiste(PDO $pdo, string $table): bool
    {
        $requete = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables'
            . ' WHERE table_schema = DATABASE() AND table_name = ?');
        $requete->execute([$table]);

        return (int) $requete->fetchColumn() > 0;
    }

    public static function colonneExiste(PDO $pdo, string $table, string $colonne): bool
    {
        $requete = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns'
            . ' WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
        $requete->execute([$table, $colonne]);

        return (int) $requete->fetchColumn() > 0;
    }
}
