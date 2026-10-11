<?php

declare(strict_types=1);

namespace Ged\Tests\Parite;

use PHPUnit\Framework\TestCase;

/**
 * Garde-fou d'exhaustivite de l'inventaire Paperless (lot ged-oracle-paperless-1).
 *
 * Le fichier doit couvrir exactement ce que le comptage independant annonce. Le test
 * recalcule, cote PHP et sur les sources de reference quand elles sont presentes, les
 * memes nombres que l'extracteur (regex distincte de l'extraction ast) et echoue en cas
 * d'ecart. Sans depot de reference accessible, seules les verifications de coherence du
 * fichier versionne sont faites.
 */
final class InventaireTest extends TestCase
{
    /** @return array<string, mixed> */
    private function inventaire(): array
    {
        $chemin = dirname(__DIR__, 3) . '/docs/parite/inventaire-paperless.json';
        self::assertFileExists($chemin);
        $donnees = json_decode((string) file_get_contents($chemin), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($donnees);

        return $donnees;
    }

    public function testChaqueElementEstCompletEtUnique(): void
    {
        $inventaire = $this->inventaire();
        self::assertSame($inventaire['total'], count((array) $inventaire['elements']));
        $vus = [];
        foreach ((array) $inventaire['elements'] as $element) {
            self::assertIsArray($element);
            foreach (['id', 'categorie', 'type', 'libelle', 'reference'] as $cle) {
                self::assertArrayHasKey($cle, $element, 'element incomplet : ' . (string) ($element['id'] ?? '?'));
            }
            self::assertMatchesRegularExpression(
                '#^src(-ui)?/[^:]+:\d+$#',
                (string) $element['reference'],
                'reference Paperless mal formee : ' . (string) $element['reference'],
            );
            self::assertArrayNotHasKey((string) $element['id'], $vus, 'id duplique : ' . (string) $element['id']);
            $vus[(string) $element['id']] = true;
        }
    }

    public function testLeComptageIndependantCorrespondALextraction(): void
    {
        $inventaire = $this->inventaire();
        $elements = (array) $inventaire['elements'];

        $parCategorie = [];
        $parType = [];
        foreach ($elements as $element) {
            $categorie = (string) $element['categorie'];
            $parCategorie[$categorie] = ($parCategorie[$categorie] ?? 0) + 1;
            $type = (string) $element['type'];
            $parType[$type] = ($parType[$type] ?? 0) + 1;
        }
        self::assertSame($parCategorie, $inventaire['comptage']['par_categorie']);
        self::assertSame($parCategorie, $inventaire['comptage_independant']['par_categorie']);

        $classesModele = 0;
        foreach ($elements as $element) {
            if ($element['type'] === 'classe' && str_ends_with(strstr((string) $element['reference'], ':', true) ?: '', 'models.py')) {
                $classesModele++;
            }
        }
        self::assertSame($inventaire['comptage_independant']['modeles_classes'], $classesModele);
        self::assertSame($inventaire['comptage_independant']['api_actions'], $parType['action'] ?? 0);
        self::assertSame($inventaire['comptage_independant']['masse_fonctions'], $parCategorie['masse'] ?? 0);
        self::assertSame($inventaire['comptage_independant']['commandes'], $parCategorie['commande'] ?? 0);
    }

    public function testComptageDirectSurLesSourcesDeReference(): void
    {
        $depot = getenv('PAPERLESS_DEPOT');
        if (!is_string($depot) || $depot === '') {
            $depot = 'C:\\ged\\ref\\paperless-ngx';
        }
        if (!is_file($depot . '/src/documents/models.py')) {
            self::markTestSkipped('depot Paperless de reference absent : ' . $depot);
        }

        $inventaire = $this->inventaire();
        $attendu = $inventaire['comptage_independant'];

        $lire = static fn (string $relatif): string => is_file($depot . '/' . $relatif)
            ? (string) file_get_contents($depot . '/' . $relatif)
            : '';

        $classes = preg_match_all('/^class \w+/m', $lire('src/documents/models.py') . $lire('src/paperless_mail/models.py'));
        self::assertSame($attendu['modeles_classes'], $classes, 'classes de modele');

        $actions = preg_match_all('/@action\b/', $lire('src/documents/views.py') . $lire('src/paperless_mail/views.py') . $lire('src/paperless/views.py'));
        self::assertSame($attendu['api_actions'], $actions, 'actions @action');

        $masse = preg_match_all('/^def \w+/m', $lire('src/documents/bulk_edit.py'));
        self::assertSame($attendu['masse_fonctions'], $masse, 'fonctions de masse');

        $ui = preg_match_all("/path:\s*'/", $lire('src-ui/src/app/app-routing.module.ts'));
        self::assertSame($attendu['ecran_routes_ui'], $ui, 'routes UI');
    }
}
