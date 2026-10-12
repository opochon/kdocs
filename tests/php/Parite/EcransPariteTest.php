<?php

declare(strict_types=1);

namespace Ged\Tests\Parite;

use PHPUnit\Framework\TestCase;

/**
 * Oracle de cohérence des SCENARIOS D'ÉCRAN (lot ged-parite-ecrans-1).
 *
 * Le fichier `tests/parite/vecteurs/ecrans.json` décrit, écran par écran, le scénario utilisateur
 * attendu de l'équivalent GEDv2 et le parcours E2E Playwright qui le prouve. Ce test ne juge pas
 * l'ergonomie (c'est le rôle des parcours) : il vérifie que le contrat est COMPLET et COHÉRENT —
 *   - chaque scénario cite un `ecran` de l'inventaire Paperless (aucun id inventé) ;
 *   - le gabarit GEDv2 cité existe vraiment ;
 *   - `docs/parite/correspondance.json` déclare ce même gabarit et la même preuve E2E.
 *
 * Il est donc rouge tant que les écrans et leurs preuves ne sont pas livrés : « oracle d'abord ».
 */
final class EcransPariteTest extends TestCase
{
    /** @return array<string, mixed> */
    private function charger(string $chemin): array
    {
        self::assertFileExists($chemin);
        $donnees = json_decode((string) file_get_contents($chemin), true);

        self::assertIsArray($donnees, $chemin . ' doit être un objet JSON');

        return $donnees;
    }

    /** @return list<string> ids `ecran` de l'inventaire, exclusions mises à part */
    private function idsEcranInventaire(): array
    {
        $inventaire = $this->charger(dirname(__DIR__, 3) . '/docs/parite/inventaire-paperless.json');
        $ids = [];
        foreach ((array) ($inventaire['elements'] ?? []) as $element) {
            if (is_array($element) && ($element['categorie'] ?? '') === 'ecran') {
                $ids[] = (string) $element['id'];
            }
        }

        return $ids;
    }

    public function testLesScenariosCitentDesEcransReelsDeLInventaire(): void
    {
        $vecteurs = $this->charger(dirname(__DIR__, 3) . '/tests/parite/vecteurs/ecrans.json');
        $inventaire = $this->idsEcranInventaire();
        $scenarios = (array) ($vecteurs['ecrans'] ?? []);

        self::assertGreaterThanOrEqual(17, count($scenarios), 'au moins les 17 écarts du lot sont décrits');
        foreach (array_keys($scenarios) as $id) {
            self::assertContains((string) $id, $inventaire, "scénario hors inventaire : $id");
        }
    }

    public function testChaqueScenarioAUnGabaritEtUnePreuveE2E(): void
    {
        $racine = dirname(__DIR__, 3);
        $vecteurs = $this->charger($racine . '/tests/parite/vecteurs/ecrans.json');
        $correspondance = $this->charger($racine . '/docs/parite/correspondance.json');
        $entrees = (array) ($correspondance['elements'] ?? []);

        foreach ((array) ($vecteurs['ecrans'] ?? []) as $id => $scenario) {
            self::assertIsArray($scenario, (string) $id);
            $etapes = (array) ($scenario['scenario'] ?? []);
            self::assertNotEmpty($etapes, "scénario vide : $id");
            self::assertNotEmpty((string) ($scenario['preuve_e2e'] ?? ''), "preuve E2E manquante : $id");

            $gedv2 = (array) ($scenario['gedv2'] ?? []);
            $fichier = (string) ($gedv2['fichier'] ?? '');
            self::assertSame('ecran', (string) ($gedv2['type'] ?? ''), "type GEDv2 non-écran : $id");
            self::assertFileExists($racine . '/' . $fichier, "gabarit GEDv2 introuvable : $id ($fichier)");
            self::assertNotSame('', (string) ($gedv2['route'] ?? ''), "route GEDv2 manquante : $id");

            $entree = (array) ($entrees[(string) $id] ?? []);
            self::assertContains($entree['statut'] ?? '', ['couvert', 'couvert-autrement'], "statut non couvert : $id");
            self::assertSame('ecran', (string) ($entree['gedv2']['type'] ?? ''), "correspondance non-écran : $id");
            self::assertSame($fichier, (string) ($entree['gedv2']['fichier'] ?? ''), "gabarit divergent : $id");
            self::assertSame(
                (string) $scenario['preuve_e2e'],
                (string) ($entree['preuve']['cible'] ?? ''),
                "preuve E2E divergente : $id",
            );
        }
    }
}
