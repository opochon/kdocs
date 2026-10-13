<?php

declare(strict_types=1);

namespace Ged\Tests\DepotFileserver;

/**
 * Fonds jetable pour les tests du secteur DepotFileserver : un dossier neuf sous `var/tests/`
 * (hors corpus suivi, jamais committé), supprimé en fin de test. Aucune base de données, aucun
 * réseau : la famille « archivage / versions » ne lit et n'écrit que des fichiers.
 */
final class SupportDepot
{
    /** Crée un dossier de travail neuf et vide. */
    public static function dossier(string $nom): string
    {
        $base = dirname(__DIR__, 3) . '/var/tests/depot-fileserver/' . $nom . '-' . bin2hex(random_bytes(4));
        if (!is_dir($base) && !mkdir($base, 0775, true) && !is_dir($base)) {
            throw new \RuntimeException('dossier de test impossible : ' . $base);
        }

        return str_replace('\\', '/', $base);
    }

    public static function ecrire(string $fichier, string $contenu): void
    {
        $dossier = dirname($fichier);
        if (!is_dir($dossier) && !mkdir($dossier, 0775, true) && !is_dir($dossier)) {
            throw new \RuntimeException('dossier impossible : ' . $dossier);
        }
        file_put_contents($fichier, $contenu);
    }

    public static function supprimer(string $dossier): void
    {
        if (!is_dir($dossier)) {
            return;
        }
        $iterateur = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dossier, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterateur as $element) {
            /** @var \SplFileInfo $element */
            if ($element->isDir()) {
                @rmdir($element->getPathname());
            } else {
                @unlink($element->getPathname());
            }
        }
        @rmdir($dossier);
    }
}
