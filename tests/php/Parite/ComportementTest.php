<?php

declare(strict_types=1);

namespace Ged\Tests\Parite;

use Ged\Classement\Regles;
use PHPUnit\Framework\TestCase;

/**
 * Phase 2 du lot ged-oracle-paperless-1 : parite d'effet.
 *
 * Les vecteurs {entree, sortie} sont extraits mecaniquement des tests de Paperless
 * (tests/parite/vecteurs/*.json, jamais de code). Chaque vecteur passe par l'equivalent
 * GEDv2 ; le resultat (passes / total, par categorie et par algorithme) est ecrit dans
 * var/parite/comportement.json pour le rapport de parite. Un ecart de comportement est
 * MESURE, jamais corrige dans ce lot.
 */
final class ComportementTest extends TestCase
{
    /** @return array<string, mixed> */
    private function vecteurs(string $categorie): array
    {
        $chemin = dirname(__DIR__, 2) . '/parite/vecteurs/' . $categorie . '.json';
        self::assertFileExists($chemin, 'vecteurs absents : ' . $categorie);
        $donnees = json_decode((string) file_get_contents($chemin), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($donnees);
        self::assertNotEmpty($donnees['vecteurs'] ?? [], 'aucun vecteur pour ' . $categorie);

        return $donnees;
    }

    /**
     * @param array<string, mixed> $vecteur
     * @return array{0:bool, 1:string}
     */
    private function rejouerCorrespondance(array $vecteur): array
    {
        $entree = is_array($vecteur['entree'] ?? null) ? $vecteur['entree'] : [];
        $algorithme = (string) ($entree['algorithme'] ?? '');
        $match = (string) ($entree['match_texte'] ?? '');
        $doc = (string) ($entree['doc_texte'] ?? '');
        $attendu = (bool) ($vecteur['sortie'] ?? false);

        $obtenu = match ($algorithme) {
            'MATCH_NONE' => false,
            'MATCH_LITERAL' => Regles::nomSeLit($doc, $match),
            'MATCH_FUZZY' => Regles::emetteursCorrespondent($match, $doc),
            default => null,
        };
        if ($obtenu === null) {
            return [false, 'pas d\'equivalent GEDv2 pour ' . $algorithme];
        }

        return [$obtenu === $attendu, ''];
    }

    public function testRejoueLesVecteursEtEcritLaMesure(): void
    {
        $resultats = [];
        $ecarts = [];

        // Analyse de dates.
        $dates = $this->vecteurs('dates');
        $passes = 0;
        foreach ((array) $dates['vecteurs'] as $vecteur) {
            $entree = is_array($vecteur['entree'] ?? null) ? $vecteur['entree'] : [];
            $obtenu = Regles::dateEnTete((string) ($entree['texte'] ?? ''));
            $attendu = $vecteur['sortie'] ?? null;
            $attendu = is_string($attendu) ? $attendu : null;
            if ($obtenu === $attendu) {
                $passes++;
            } else {
                $ecarts[] = [
                    'id' => (string) ($vecteur['id'] ?? ''),
                    'attendu' => $attendu,
                    'obtenu' => $obtenu,
                    'reference' => (string) ($vecteur['reference'] ?? ''),
                ];
            }
        }
        $resultats['dates'] = ['total' => count((array) $dates['vecteurs']), 'passes' => $passes, 'ecarts' => $ecarts];

        // Correspondance automatique.
        $correspondance = $this->vecteurs('correspondance');
        $passes = 0;
        $ecarts = [];
        $parAlgorithme = [];
        foreach ((array) $correspondance['vecteurs'] as $vecteur) {
            $entree = is_array($vecteur['entree'] ?? null) ? $vecteur['entree'] : [];
            $algorithme = (string) ($entree['algorithme'] ?? '');
            [$ok, $raison] = $this->rejouerCorrespondance($vecteur);
            $parAlgorithme[$algorithme] ??= ['total' => 0, 'passes' => 0];
            $parAlgorithme[$algorithme]['total']++;
            if ($ok) {
                $passes++;
                $parAlgorithme[$algorithme]['passes']++;
            } else {
                $ecarts[] = [
                    'id' => (string) ($vecteur['id'] ?? ''),
                    'algorithme' => $algorithme,
                    'raison' => $raison,
                    'reference' => (string) ($vecteur['reference'] ?? ''),
                ];
            }
        }
        $resultats['correspondance'] = [
            'total' => count((array) $correspondance['vecteurs']),
            'passes' => $passes,
            'par_algorithme' => $parAlgorithme,
            'ecarts' => $ecarts,
        ];

        $dossier = dirname(__DIR__, 3) . '/var/parite';
        if (!is_dir($dossier)) {
            mkdir($dossier, 0777, true);
        }
        file_put_contents(
            $dossier . '/comportement.json',
            json_encode(
                [
                    'version' => 1,
                    'reference' => $dates['reference'] ?? [],
                    'categories' => $resultats,
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ) . "\n",
        );

        self::assertFileExists($dossier . '/comportement.json', 'mesure de comportement non ecrite');
        self::assertSame(
            count((array) $dates['vecteurs']),
            $resultats['dates']['total'],
            'la mesure doit couvrir tous les vecteurs de dates',
        );
    }
}
