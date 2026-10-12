<?php

declare(strict_types=1);

namespace Ged\Workflows;

use PDO;

/**
 * Migrations versionnées du secteur Workflows : un numéro de version, des étapes idempotentes, jamais
 * destructives en marche avant. La version se LIT dans le schéma (colonnes et index présents) : rien à
 * écrire dans une table de suivi, aucun état parallèle au dossier.
 *
 *  - version 1 : tables de base (`Schema::installer`) ;
 *  - version 2 : colonne de tri `workflow_definitions.ordre` + index `(genre, ordre)`.
 *
 * `retour()` n'accepte de descendre que si aucune donnée de tri ne serait perdue : un workflow qui porte
 * un ordre non nul fait refuser le retour, nommément. Le lot ged-parite-modele-1 ajoute la version 2.
 */
final class Migrations
{
    public const VERSION = 2;

    /** @var array<int, string> */
    public const ETAPES = [
        1 => 'tables de base (workflow_definitions, workflow_instances, workflow_journal)',
        2 => 'colonne de tri workflow_definitions.ordre + index (genre, ordre)',
    ];

    /** Monte jusqu'à la version demandée (toutes les étapes strictement supérieures à la version lue). */
    public static function monter(PDO $pdo, int $cible = self::VERSION): void
    {
        $version = self::version($pdo);
        for ($prochaine = $version + 1; $prochaine <= $cible; $prochaine++) {
            self::appliquer($pdo, $prochaine);
        }
    }

    /**
     * Descend à la version cible en retirant les objets ajoutés par les versions supérieures. Un retour
     * qui perdrait une donnée de tri est refusé, nommément : jamais une destruction silencieuse.
     */
    public static function retour(PDO $pdo, int $cible): void
    {
        if (!isset(self::ETAPES[$cible])) {
            throw new ErreurWorkflow("retour : version cible inconnue ($cible)");
        }
        for ($version = self::VERSION; $version > $cible; $version--) {
            self::retirer($pdo, $version);
        }
    }

    /** Version lue dans le schéma : 0 si rien n'existe, 1 si les tables seules, 2 avec la colonne de tri. */
    public static function version(PDO $pdo): int
    {
        if (!self::tableExiste($pdo, 'workflow_definitions')) {
            return 0;
        }

        return self::colonneExiste($pdo, 'workflow_definitions', 'ordre') ? 2 : 1;
    }

    private static function appliquer(PDO $pdo, int $version): void
    {
        match ($version) {
            1 => Schema::assurerTables($pdo),
            2 => Schema::assurerOrdre($pdo),
            default => throw new ErreurWorkflow("migration : étape inconnue ($version)"),
        };
    }

    private static function retirer(PDO $pdo, int $version): void
    {
        if ($version !== 2) {
            throw new ErreurWorkflow("retour : étape $version non réversible (objet de base)");
        }
        if (!self::colonneExiste($pdo, 'workflow_definitions', 'ordre')) {
            return;
        }
        $perdus = (int) $pdo->query('SELECT COUNT(*) FROM workflow_definitions WHERE ordre <> 0')->fetchColumn();
        if ($perdus > 0) {
            throw new ErreurWorkflow(sprintf(
                'retour v2 refusé : %d workflow(s) portent un ordre non nul, le retrait perdrait ce tri',
                $perdus,
            ));
        }
        if (self::indexExiste($pdo, 'workflow_definitions', 'k_genre_ordre')) {
            $pdo->exec('ALTER TABLE workflow_definitions DROP INDEX k_genre_ordre');
        }
        $pdo->exec('ALTER TABLE workflow_definitions DROP COLUMN ordre');
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

    private static function indexExiste(PDO $pdo, string $table, string $index): bool
    {
        $requete = $pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics'
            . ' WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?');
        $requete->execute([$table, $index]);

        return (int) $requete->fetchColumn() > 0;
    }
}
