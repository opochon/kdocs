<?php

declare(strict_types=1);

namespace Ged\Tests\Parite;

use Ged\Classement\Correspondance;
use Ged\Classement\Regles;
use PHPUnit\Framework\TestCase;

/**
 * Phase 2 du lot ged-oracle-paperless-1 : parité d'effet, rattrapée par le lot ged-parite-comportement-1.
 *
 * Les vecteurs {entree, sortie} sont extraits mécaniquement des tests de Paperless
 * (tests/parite/vecteurs/*.json, jamais de code) et sont l'ORACLE : ils ne sont ni modifiés ni filtrés.
 * Chaque vecteur est rejoué par l'équivalent GEDv2 — Ged\Classement\Correspondance pour la
 * correspondance, Regles::dateDansTexte pour les dates — et le résultat (passes / total, par catégorie
 * et par algorithme) est écrit dans var/parite/comportement.json pour le rapport de parité. Le test
 * échoue si UN vecteur s'écarte de sa sortie attendue.
 *
 * Sensibilité à la casse : Paperless porte le drapeau `is_insensitive` dans le modèle, absent des
 * vecteurs extraits. Rejouer en sensible est le seul choix cohérent avec les 137 vecteurs (aucun
 * couple (algorithme, motif, texte) n'a deux sorties contradictoires) : les vecteurs de la classe
 * insensible ne font jamais varier la casse, donc ils passent aussi en sensible.
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
     * Rejoue un vecteur de correspondance via le service GEDv2. Aucun cas particulier : l'algorithme
     * du vecteur est transmis tel quel au service, qui décide (un algorithme non textuel renvoie false).
     *
     * @param array<string, mixed> $vecteur
     * @return array{0:bool, 1:string}
     */
    private function rejouerCorrespondance(array $vecteur): array
    {
        $entree = is_array($vecteur['entree'] ?? null) ? $vecteur['entree'] : [];
        $attendu = (bool) ($vecteur['sortie'] ?? false);
        $obtenu = Correspondance::correspond(
            (string) ($entree['algorithme'] ?? ''),
            (string) ($entree['match_texte'] ?? ''),
            (string) ($entree['doc_texte'] ?? ''),
            false,
        );
        if ($obtenu === $attendu) {
            return [true, ''];
        }

        return [false, 'attendu ' . var_export($attendu, true) . ', obtenu ' . var_export($obtenu, true)];
    }

    public function testRejoueLesVecteursEtEcritLaMesure(): void
    {
        $resultats = [];
        $echecs = [];

        // Analyse de dates : la première date lisible dans le texte, à la manière de Paperless.
        $dates = $this->vecteurs('dates');
        $passes = 0;
        $ecarts = [];
        foreach ((array) $dates['vecteurs'] as $vecteur) {
            $entree = is_array($vecteur['entree'] ?? null) ? $vecteur['entree'] : [];
            $obtenu = Regles::dateDansTexte((string) ($entree['texte'] ?? ''));
            $attendu = $vecteur['sortie'] ?? null;
            $attendu = is_string($attendu) ? $attendu : null;
            if ($obtenu === $attendu) {
                $passes++;
            } else {
                $ecart = [
                    'id' => (string) ($vecteur['id'] ?? ''),
                    'attendu' => $attendu,
                    'obtenu' => $obtenu,
                    'reference' => (string) ($vecteur['reference'] ?? ''),
                ];
                $ecarts[] = $ecart;
                $echecs[] = 'dates ' . $ecart['id'] . ' (' . $ecart['reference'] . ') : attendu '
                    . var_export($attendu, true) . ', obtenu ' . var_export($obtenu, true);
            }
        }
        $resultats['dates'] = ['total' => count((array) $dates['vecteurs']), 'passes' => $passes, 'ecarts' => $ecarts];

        // Correspondance automatique : chaque algorithme du modèle Paperless.
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
                $ecart = [
                    'id' => (string) ($vecteur['id'] ?? ''),
                    'algorithme' => $algorithme,
                    'raison' => $raison,
                    'reference' => (string) ($vecteur['reference'] ?? ''),
                ];
                $ecarts[] = $ecart;
                $echecs[] = 'correspondance ' . $ecart['id'] . ' ' . $algorithme . ' ('
                    . $ecart['reference'] . ') : ' . $raison;
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
        self::assertSame([], $echecs, "vecteurs de comportement en écart :\n" . implode("\n", $echecs));
    }
}
