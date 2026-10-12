<?php

declare(strict_types=1);

namespace Ged\Tests\Parite;

use RuntimeException;

/**
 * Chargement commun des vecteurs de comportement de la chaîne (lot ged-parite-chaine-1) et écriture de
 * la mesure lue par le rapport de parité. Un vecteur est un couple {entree, sortie} extrait des tests
 * Paperless : il n'est jamais filtré, ni par catégorie, ni par famille.
 */
final class Vecteurs
{
    /**
     * @return array{version:int, categorie:string, reference:array<string,string>, total:int,
     *               vecteurs:list<array<string,mixed>>}
     */
    public static function charger(string $fichier): array
    {
        $chemin = dirname(__DIR__, 2) . '/parite/vecteurs/' . $fichier . '.json';
        if (!is_file($chemin)) {
            throw new RuntimeException('vecteurs absents : ' . $chemin);
        }
        $donnees = json_decode((string) file_get_contents($chemin), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($donnees) || !is_array($donnees['vecteurs'] ?? null) || $donnees['vecteurs'] === []) {
            throw new RuntimeException('vecteurs illisibles ou vides : ' . $chemin);
        }

        return $donnees;
    }

    /**
     * @param array<string,mixed> $resultat
     */
    public static function ecrire(string $categorie, array $resultat): void
    {
        self::ecrireMesure('chaine-' . $categorie, $resultat);
    }

    /**
     * Mesure d'une famille `api` (lot ged-parite-api-1) : écrite à part pour que la phase 3 (chaîne)
     * ne mélange pas ses familles avec celles de l'API.
     *
     * @param array<string,mixed> $resultat
     */
    public static function ecrireApi(string $categorie, array $resultat): void
    {
        self::ecrireMesure('api-' . $categorie, $resultat);
    }

    /** @param array<string,mixed> $resultat */
    private static function ecrireMesure(string $fichier, array $resultat): void
    {
        $dossier = dirname(__DIR__, 3) . '/var/parite';
        if (!is_dir($dossier) && !mkdir($dossier, 0777, true) && !is_dir($dossier)) {
            throw new RuntimeException('dossier de mesure impossible : ' . $dossier);
        }
        file_put_contents(
            $dossier . '/' . $fichier . '.json',
            json_encode($resultat, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
        );
    }
}
