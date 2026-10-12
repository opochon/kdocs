<?php

declare(strict_types=1);

namespace Ged\Ingestion;

use PDO;

/**
 * Migrations versionnées du secteur Ingestion : un numéro de version, des étapes idempotentes, jamais
 * destructives en marche avant. La version se LIT dans le schéma (colonnes présentes), aucun état parallèle.
 *
 *  - version 1 : tables de base (`ingestion_travaux`, `ingestion_messages`) ;
 *  - version 2 : colonnes du journal des courriels traités (modele Paperless `ProcessedMail`) sur
 *    `ingestion_messages` : règle, dossier, uid, sujet, statut, erreur, date de traitement.
 *
 * `retour()` refuse de descendre dès qu'une ligne porterait une règle : la trace du courriel traité ne doit
 * jamais disparaître en silence (zéro suppression de donnée).
 */
final class Migrations
{
    public const VERSION = 2;

    /** @var array<int, string> */
    public const ETAPES = [
        1 => 'tables de base (ingestion_travaux, ingestion_messages)',
        2 => 'journal des courriels traités : regle, dossier, uid, sujet, statut, erreur, traite_le',
    ];

    public static function monter(PDO $pdo, int $cible = self::VERSION): void
    {
        for ($prochaine = self::version($pdo) + 1; $prochaine <= $cible; $prochaine++) {
            self::appliquer($pdo, $prochaine);
        }
    }

    /** Descend en retirant les objets des versions supérieures ; refuse si une trace de courrier serait perdue. */
    public static function retour(PDO $pdo, int $cible): void
    {
        if (!isset(self::ETAPES[$cible])) {
            throw new ErreurIngestion("retour : version cible inconnue ($cible)");
        }
        for ($version = self::VERSION; $version > $cible; $version--) {
            self::retirer($pdo, $version);
        }
    }

    /** Version lue dans le schéma : 0 sans la table, 1 avec les tables seules, 2 avec les colonnes courrier. */
    public static function version(PDO $pdo): int
    {
        if (!self::tableExiste($pdo, 'ingestion_messages')) {
            return 0;
        }

        return self::colonneExiste($pdo, 'ingestion_messages', 'regle') ? 2 : 1;
    }

    private static function appliquer(PDO $pdo, int $version): void
    {
        match ($version) {
            1 => Schema::assurerTables($pdo),
            2 => Schema::assurerColonnesCourrier($pdo),
            default => throw new ErreurIngestion("migration : étape inconnue ($version)"),
        };
    }

    private static function retirer(PDO $pdo, int $version): void
    {
        if ($version !== 2) {
            throw new ErreurIngestion("retour : étape $version non réversible (objet de base)");
        }
        if (!self::colonneExiste($pdo, 'ingestion_messages', 'regle')) {
            return;
        }
        $perdues = (int) $pdo->query('SELECT COUNT(*) FROM ingestion_messages WHERE regle IS NOT NULL')->fetchColumn();
        if ($perdues > 0) {
            throw new ErreurIngestion(sprintf(
                'retour v2 refusé : %d courriel(s) tracé(s) portent une règle, le retrait perdrait cette trace',
                $perdues,
            ));
        }
        foreach (array_reverse(Schema::COLONNES_COURRIER) as $colonne) {
            $pdo->exec("ALTER TABLE ingestion_messages DROP COLUMN $colonne");
        }
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
