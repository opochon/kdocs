<?php

declare(strict_types=1);

namespace Ged\Tests\Parite;

use Ged\Parite\InterpreteurPython;
use PHPUnit\Framework\TestCase;

/**
 * Garde-fou de l'inventaire M-Files (lot ged-mfiles-oracle-1) : completé, unique, chaque élément
 * porte une référence de documentation PUBLIQUE, et la correspondance donne un statut à CHACUN.
 *
 * S'il est présent, l'interpréteur Python régénère l'inventaire depuis la capture versionnée et le
 * compare au fichier versionné : la mesure est rejouable hors ligne, comme pour Paperless.
 */
final class MfilesInventaireTest extends TestCase
{
    /** @return array<string, mixed> */
    private function inventaire(): array
    {
        $chemin = dirname(__DIR__, 3) . '/docs/parite/inventaire-mfiles.json';
        self::assertFileExists($chemin);
        $donnees = json_decode((string) file_get_contents($chemin), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($donnees);

        return $donnees;
    }

    public function testChaqueElementEstCompletUniqueEtPubliquementReference(): void
    {
        $inventaire = $this->inventaire();
        self::assertSame($inventaire['total'], count((array) $inventaire['elements']));
        $vus = [];
        foreach ((array) $inventaire['elements'] as $element) {
            self::assertIsArray($element);
            foreach (['id', 'categorie', 'type', 'libelle', 'reference'] as $cle) {
                self::assertArrayHasKey($cle, $element, 'element incomplet : ' . (string) ($element['id'] ?? '?'));
            }
            self::assertStringStartsWith('mfiles:', (string) $element['id']);
            self::assertSame('mfiles', $element['categorie']);
            self::assertMatchesRegularExpression(
                '#^https://developer\.m-files\.com/APIs/REST-API/Reference/resources/.+\#(get|post|put|delete|patch)$#',
                (string) $element['reference'],
                'reference M-Files non publique ou mal formee : ' . (string) $element['reference'],
            );
            self::assertArrayNotHasKey((string) $element['id'], $vus, 'id duplique : ' . (string) $element['id']);
            $vus[(string) $element['id']] = true;
        }
    }

    public function testLeComptageCorrespondAuxElements(): void
    {
        $inventaire = $this->inventaire();
        $parVerbe = [];
        foreach ((array) $inventaire['elements'] as $element) {
            $verbe = explode(' ', (string) $element['libelle'], 2)[0];
            $parVerbe[$verbe] = ($parVerbe[$verbe] ?? 0) + 1;
        }
        ksort($parVerbe);

        self::assertSame(['mfiles' => count((array) $inventaire['elements'])], $inventaire['comptage']['par_categorie']);
        self::assertSame($parVerbe, $inventaire['comptage']['par_verbe']);
    }

    public function testLaCapturePubliqueExisteEtCorrespondALInventaire(): void
    {
        $capture = dirname(__DIR__, 3) . '/docs/parite/mfiles-rest-api.capture.json';
        self::assertFileExists($capture);
        $donnees = json_decode((string) file_get_contents($capture), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($donnees);
        self::assertSame((int) $donnees['total'], count((array) $donnees['ressources']));
        self::assertStringStartsWith('https://developer.m-files.com/', (string) $donnees['source']);
    }

    public function testChaqueElementAPlusieursStatutEtAucuneExclusionPoseeParLAgent(): void
    {
        $inventaire = $this->inventaire();
        $correspondance = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3) . '/docs/parite/correspondance.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $elements = (array) $correspondance['elements'];
        foreach ((array) $inventaire['elements'] as $element) {
            $id = (string) $element['id'];
            self::assertArrayHasKey($id, $elements, 'inventaire M-Files sans statut : ' . $id);
            $entree = $elements[$id];
            self::assertIsArray($entree);
            self::assertContains($entree['statut'] ?? null, ['couvert', 'couvert-autrement', 'absent', 'exclu']);
            self::assertNotSame('exclu', $entree['statut'], 'une exclusion est une decision du juge, pas de l agent : ' . $id);
        }
    }

    public function testLInventaireEstRejoueALIdentiqueDepuisLaCapture(): void
    {
        $interpreteur = InterpreteurPython::resoudre();
        if ($interpreteur === []) {
            self::markTestSkipped('aucun interpreteur Python 3 : rejeu de l inventaire M-Files impossible');
        }
        $racine = dirname(__DIR__, 3);
        $sortie = $racine . '/var/parite/inventaire-mfiles-test.json';
        if (!is_dir(dirname($sortie))) {
            mkdir(dirname($sortie), 0777, true);
        }
        $commande = array_merge($interpreteur, [
            $racine . '/tools/parite/extraire-mfiles.py',
            '--capture', $racine . '/docs/parite/mfiles-rest-api.capture.json',
            '--sortie', $sortie,
        ]);
        $descripteurs = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $tuyaux = [];
        $processus = proc_open($commande, $descripteurs, $tuyaux);
        self::assertIsResource($processus);
        $erreur = (string) stream_get_contents($tuyaux[2]);
        fclose($tuyaux[1]);
        fclose($tuyaux[2]);
        $code = proc_close($processus);
        self::assertSame(0, $code, 'extracteur M-Files en echec : ' . $erreur);

        $rejoue = json_decode((string) file_get_contents($sortie), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($this->inventaire()['elements'], $rejoue['elements']);
    }
}
