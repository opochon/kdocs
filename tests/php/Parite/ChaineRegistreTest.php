<?php

declare(strict_types=1);

namespace Ged\Tests\Parite;

use Ged\Ingestion\RegistreParseurs;
use PHPUnit\Framework\TestCase;

/**
 * Rejeu des vecteurs du registre des parseurs (famille « registre » du lot ged-parite-chaine-1).
 *
 * Les vecteurs (tests/parite/vecteurs/chaine-registre.json) sont extraits de
 * src/documents/tests/test_parsers.py de Paperless v2.20.5 : type MIME → extension par défaut, type MIME
 * → famille de parseur, extension → support. Ils sont l'ORACLE : aucun cas particulier n'est câblé ici,
 * chaque vecteur est transmis tel quel au service.
 *
 * Deux vecteurs sont propres à Django et ne sont PAS rejouables tels quels : `test_get_parser_class_0_parsers`
 * et `test_get_parser_class_no_valid_parser` vident le registre par un mock
 * (`document_consumer_declaration.send`) pour vérifier que Paperless rend None. GEDv2 n'a pas de registre
 * découvert par signaux : `application/pdf` et `image/tiff` y ont toujours un parseur. Ces deux vecteurs
 * sont comptés à part (`non_rejouables`) et le test ÉCHOUE si leur nombre change — jamais un contournement
 * silencieux.
 */
final class ChaineRegistreTest extends TestCase
{
    /** Familles Paperless → familles GEDv2 (équivalence déclarée, pas un renommage). */
    private const FAMILLES = [
        'RasterisedDocumentParser' => RegistreParseurs::RASTERISE,
        'TextDocumentParser' => RegistreParseurs::TEXTE,
        'TikaDocumentParser' => RegistreParseurs::BUREAUTIQUE,
    ];

    public function testRejoueLesVecteursDuRegistre(): void
    {
        $donnees = Vecteurs::charger('chaine-registre');
        $echecs = [];
        $passes = 0;
        $parType = [];
        $nonRejouables = [];

        foreach ($donnees['vecteurs'] as $vecteur) {
            $entree = is_array($vecteur['entree'] ?? null) ? $vecteur['entree'] : [];
            $sortie = is_array($vecteur['sortie'] ?? null) ? $vecteur['sortie'] : [];
            $cle = 'extension';
            if (array_key_exists('parseur', $sortie)) {
                $cle = 'parseur';
                if ($sortie['parseur'] === null && RegistreParseurs::mimeSupporte((string) ($entree['mime'] ?? ''))) {
                    $nonRejouables[] = (string) ($vecteur['id'] ?? '');
                    continue;   // registre Django mocké vide : contexte non reproductible en GEDv2
                }
                $attendu = $sortie['parseur'] === null
                    ? null
                    : (self::FAMILLES[(string) $sortie['parseur']] ?? (string) $sortie['parseur']);
                $obtenu = RegistreParseurs::parseurPourMime((string) ($entree['mime'] ?? ''));
            } elseif (array_key_exists('extension', $sortie)) {
                $cle = 'extension';
                $attendu = (string) $sortie['extension'];
                $obtenu = RegistreParseurs::extensionDefaut((string) ($entree['mime'] ?? ''));
            } elseif (array_key_exists('support', $sortie)) {
                $cle = 'support';
                $attendu = (bool) $sortie['support'];
                $obtenu = RegistreParseurs::extensionSupportee((string) ($entree['extension'] ?? ''));
            } else {
                $echecs[] = (string) ($vecteur['id'] ?? '?') . ' : sortie de forme inconnue';
                continue;
            }

            $parType[$cle] ??= ['total' => 0, 'passes' => 0];
            $parType[$cle]['total']++;
            if ($obtenu === $attendu) {
                $passes++;
                $parType[$cle]['passes']++;
            } else {
                $echecs[] = sprintf(
                    '%s (%s) : attendu %s, obtenu %s',
                    (string) ($vecteur['id'] ?? '?'),
                    (string) ($vecteur['reference'] ?? ''),
                    var_export($attendu, true),
                    var_export($obtenu, true),
                );
            }
        }

        Vecteurs::ecrire('registre', [
            'version' => 1,
            'reference' => $donnees['reference'] ?? [],
            'categorie' => 'registre',
            'total' => count($donnees['vecteurs']),
            'passes' => $passes,
            'par_type' => $parType,
            'non_rejouables' => $nonRejouables,
            'ecarts' => $echecs,
        ]);

        self::assertCount(
            2,
            $nonRejouables,
            'le nombre de vecteurs Django mockes doit rester explicite : ' . implode(', ', $nonRejouables),
        );
        self::assertSame(
            [],
            $echecs,
            "vecteurs du registre en écart :\n" . implode("\n", $echecs),
        );
    }

    /**
     * `is_mime_type_supported` : un type MIME est supporté si un parseur le réclame, et seulement alors.
     * Rejoué sur les mêmes couples type MIME → parseur que les vecteurs.
     */
    public function testMimeSupporteSuitLeParseurPourMime(): void
    {
        $donnees = Vecteurs::charger('chaine-registre');
        foreach ($donnees['vecteurs'] as $vecteur) {
            $sortie = is_array($vecteur['sortie'] ?? null) ? $vecteur['sortie'] : [];
            $entree = is_array($vecteur['entree'] ?? null) ? $vecteur['entree'] : [];
            if (!array_key_exists('parseur', $sortie) || !isset($entree['mime'])) {
                continue;
            }
            $mime = (string) $entree['mime'];
            self::assertSame(
                RegistreParseurs::parseurPourMime($mime) !== null,
                RegistreParseurs::mimeSupporte($mime),
                'mimeSupporte et parseurPourMime doivent dire la même chose pour ' . $mime,
            );
        }
        self::assertFalse(RegistreParseurs::mimeSupporte('text/sdgsdf'));
        self::assertTrue(RegistreParseurs::mimeSupporte('application/pdf'));
        self::assertSame('.pdf', RegistreParseurs::extensionDefaut('application/pdf'));
    }

    public function testArgumentsConvertSuiventLOrdreDePaperless(): void
    {
        $arguments = RegistreParseurs::argumentsConvert('convert', 'entree.pdf[0]', 'sortie.webp', [
            'densite' => 300, 'echelle' => '500x5000>', 'alpha' => 'remove', 'detacher' => true,
            'detourer' => false, 'autoOrienter' => true, 'cropbox' => true,
        ]);
        self::assertSame(
            ['convert', '-density', '300', '-scale', '500x5000>', '-alpha', 'remove', '-strip',
                '-auto-orient', '-define', 'pdf:use-cropbox=true', 'entree.pdf[0]', 'sortie.webp'],
            $arguments,
        );
        self::assertSame(
            ['convert', 'entree.png', 'sortie.webp'],
            RegistreParseurs::argumentsConvert('convert', 'entree.png', 'sortie.webp'),
            'une option absente ne produit aucun argument',
        );
    }
}
