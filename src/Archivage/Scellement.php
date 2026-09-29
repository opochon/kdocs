<?php

declare(strict_types=1);

namespace Ged\Archivage;

/**
 * Le scellement VIT DANS LE DOSSIER, à côté du document, comme ses versions : un dossier `.scellement/`
 * contient `<fichier>.json` (le justificatif) et `<fichier>.tsr` (la réponse TSA brute). La base n'en
 * sait rien : le dossier fait foi, et l'indexeur ignore ce dossier interne (préfixe point).
 *
 * Le justificatif porte l'identifiant d'index (`document_id`) pour retrouver le scellement même si un
 * renommage hors application a changé le nom du fichier : c'est ce qui rend la garde résistante.
 */
final class Scellement
{
    /** Dossier `.scellement/` du document (frère de ses `.versions/`). */
    public static function dossier(string $absoluDocument): string
    {
        return dirname($absoluDocument) . '/.scellement';
    }

    /** Justificatif JSON du document. */
    public static function cheminJson(string $absoluDocument): string
    {
        return self::dossier($absoluDocument) . '/' . basename($absoluDocument) . '.json';
    }

    /** Jeton TSA brut (TimeStampResp DER) du document. */
    public static function cheminJeton(string $absoluDocument): string
    {
        return self::dossier($absoluDocument) . '/' . basename($absoluDocument) . '.tsr';
    }

    /** Écrit le jeton puis le justificatif (le JSON en dernier : sa présence dit le scellement complet). */
    public static function ecrire(string $absoluDocument, array $donnees, string $jeton): void
    {
        $dossier = self::dossier($absoluDocument);
        if (!is_dir($dossier) && !mkdir($dossier, 0775, true) && !is_dir($dossier)) {
            throw ErreurScellement::scellement("impossible de créer $dossier");
        }
        if (class_exists(\Ged\Socle\Fichiers::class)) {
            \Ged\Socle\Fichiers::cacher($dossier);
        }
        if (file_put_contents(self::cheminJeton($absoluDocument), $jeton, LOCK_EX) === false) {
            throw ErreurScellement::scellement('impossible d’écrire le jeton TSA');
        }
        if (file_put_contents(self::cheminJson($absoluDocument), self::encoder($donnees), LOCK_EX) === false) {
            throw ErreurScellement::scellement('impossible d’écrire le justificatif de scellement');
        }
    }

    /**
     * Le scellement d'un document : d'abord le justificatif attendu (nom du fichier), puis, si un renommage
     * hors application a changé ce nom, le justificatif d'un autre nom portant le MÊME `document_id`.
     */
    public static function localiser(string $absoluDocument, ?int $idDocument = null): ?string
    {
        $candidat = self::cheminJson($absoluDocument);
        if (is_file($candidat)) {
            return $candidat;
        }
        if ($idDocument === null) {
            return null;
        }
        foreach (glob(self::dossier($absoluDocument) . '/*.json') ?: [] as $fichier) {
            $donnees = self::decoder((string) file_get_contents($fichier));
            if ($donnees !== null && (int) ($donnees['document_id'] ?? 0) === $idDocument) {
                return $fichier;
            }
        }

        return null;
    }

    /** Le justificatif décodé d'un document, ou null s'il n'est pas scellé. */
    public static function lire(string $absoluDocument, ?int $idDocument = null): ?array
    {
        $chemin = self::localiser($absoluDocument, $idDocument);

        return $chemin === null ? null : self::decoder((string) file_get_contents($chemin));
    }

    /** Un scellement est actif tant qu'aucune levée humaine n'a été tracée. */
    public static function estActif(?array $scellement): bool
    {
        return $scellement !== null && empty($scellement['leve_le']);
    }

    /**
     * Tous les justificatifs d'un fonds, sans jamais entrer dans les autres dossiers internes
     * (`.versions/`, `.corbeille/`, `.index/`, `.archivage/`).
     *
     * @return list<string> chemins absolus des `.json` de scellement, triés
     */
    public static function parcourir(string $racine): array
    {
        $trouves = self::descendre($racine, true);
        sort($trouves);

        return $trouves;
    }

    /** @return list<string> */
    private static function descendre(string $dossier): array
    {
        $trouves = [];
        if (!is_dir($dossier)) {
            return $trouves;
        }
        foreach (scandir($dossier) ?: [] as $nom) {
            if ($nom === '.' || $nom === '..') {
                continue;
            }
            $sous = $dossier . '/' . $nom;
            if (!is_dir($sous)) {
                continue;
            }
            if ($nom === '.scellement') {
                foreach (glob($sous . '/*.json') ?: [] as $fichier) {
                    $trouves[] = $fichier;
                }
            } elseif (!str_starts_with($nom, '.')) {
                $trouves = array_merge($trouves, self::descendre($sous));
            }
        }

        return $trouves;
    }

    /** @param array<string, mixed> $donnees */
    private static function encoder(array $donnees): string
    {
        return json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
    }

    /** @return array<string, mixed>|null */
    public static function decoder(string $contenu): ?array
    {
        $donnees = json_decode($contenu, true);

        return is_array($donnees) ? $donnees : null;
    }
}
