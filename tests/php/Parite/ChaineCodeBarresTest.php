<?php

declare(strict_types=1);

namespace Ged\Tests\Parite;

use Ged\Ingestion\CodeBarres;
use PHPUnit\Framework\TestCase;

/**
 * Rejeu de la découpe par codes-barres (famille « codebarres » du lot ged-parite-chaine-1).
 *
 * Vecteurs extraits de src/documents/tests/test_barcodes.py : les tests affirment le nombre de documents
 * produits par `BarcodePlugin.separate_pages` pour un PDF d'un nombre de pages donné et un ensemble de
 * pages de coupe (page → conserver la page). Le nombre de pages de chaque échantillon est lu dans le PDF
 * de référence par l'extracteur, pour que le vecteur soit complet. L'ORACLE est rejoué tel quel.
 *
 * La LECTURE des codes-barres (PDF → image → pyzbar/zxing) est Python : elle n'est pas rejouée ici, elle
 * est listée dans la note de lot. GEDv2 lit ses codes-barres par le même genre de moteur externe ; ce
 * service couvre la CLASSIFICATION (séparateur, numéro d'archive) et la DÉCOUPE, déterministes.
 */
final class ChaineCodeBarresTest extends TestCase
{
    public function testRejoueLaDecoupeParCodesBarres(): void
    {
        $donnees = Vecteurs::charger('chaine-codebarres');
        $echecs = [];
        $passes = 0;

        foreach ($donnees['vecteurs'] as $vecteur) {
            $entree = is_array($vecteur['entree'] ?? null) ? $vecteur['entree'] : [];
            $sortie = is_array($vecteur['sortie'] ?? null) ? $vecteur['sortie'] : [];
            $pages = (int) ($entree['pages'] ?? 0);
            $coupes = [];
            foreach ((array) ($entree['coupes'] ?? []) as $page => $garder) {
                $coupes[(int) $page] = (bool) $garder;
            }
            $documents = CodeBarres::separer($pages, $coupes);
            $attendu = (int) ($sortie['documents'] ?? 0);
            if (count($documents) === $attendu) {
                $passes++;
            } else {
                $echecs[] = sprintf(
                    '%s (%s) : attendu %d documents, obtenu %d',
                    (string) ($vecteur['id'] ?? '?'),
                    (string) ($vecteur['reference'] ?? ''),
                    $attendu,
                    count($documents),
                );
            }
        }

        Vecteurs::ecrire('codebarres', [
            'version' => 1,
            'reference' => $donnees['reference'] ?? [],
            'categorie' => 'codebarres',
            'total' => count($donnees['vecteurs']),
            'passes' => $passes,
            'ecarts' => $echecs,
        ]);

        self::assertSame([], $echecs, "vecteurs codes-barres en écart :\n" . implode("\n", $echecs));
    }

    public function testClassifieEtExtraitLeNumeroArchive(): void
    {
        // is_separator : la valeur du code-barres est exactement le motif de séparation configuré.
        self::assertTrue(CodeBarres::estSeparateur('PATCHT', 'PATCHT'));
        self::assertFalse(CodeBarres::estSeparateur('PATCH', 'PATCHT'));

        // is_asn : la valeur commence par le préfixe configuré (préfixe par défaut « ASN »).
        self::assertTrue(CodeBarres::estNumeroArchive('ASN00123', 'ASN'));
        self::assertFalse(CodeBarres::estNumeroArchive('00123', 'ASN'));

        // L'ASN est le premier code-barres reconnu, préfixe retiré, parties non numériques supprimées.
        self::assertSame(123, CodeBarres::numeroArchive('ASN00123', 'ASN'));
        self::assertSame(123, CodeBarres::numeroArchive('CUSTOM-PREFIX-123', 'CUSTOM-PREFIX-'));
        self::assertNull(CodeBarres::numeroArchive('ASNXYZXYZ', 'ASN'), 'aucun chiffre : pas de numéro');
        self::assertNull(CodeBarres::numeroArchive(null, 'ASN'));

        // Le premier code-barres ASN l'emporte ; un code-barres sans préfixe n'en est pas un.
        self::assertSame(7, CodeBarres::numeroArchiveDepuisCodes([
            ['page' => 0, 'valeur' => 'PATCHT'],
            ['page' => 1, 'valeur' => 'ASN0007'],
            ['page' => 3, 'valeur' => 'ASN0009'],
        ], 'ASN'));
    }

    public function testPagesDeSeparationSuiventLaConfiguration(): void
    {
        $codes = [
            ['page' => 0, 'valeur' => 'PATCHT'],
            ['page' => 2, 'valeur' => 'PATCHT'],
            ['page' => 4, 'valeur' => 'ASN0004'],
            ['page' => 0, 'valeur' => 'ASN0000'],
        ];
        // Séparation seule : la page du séparateur est retirée (conserver = false).
        self::assertSame(
            [0 => false, 2 => false],
            CodeBarres::pagesDeSeparation($codes, 'PATCHT', 'ASN', false, false),
        );
        // ASN actif : les pages ASN entrent aussi, en conservant la page, sauf la page 0.
        self::assertSame(
            [0 => false, 2 => false, 4 => true],
            CodeBarres::pagesDeSeparation($codes, 'PATCHT', 'ASN', false, true),
        );
        // Conserver les pages de séparation : sauf la page 0, qui bouclerait.
        self::assertSame(
            [2 => true, 4 => true],
            CodeBarres::pagesDeSeparation($codes, 'PATCHT', 'ASN', true, true),
        );
    }
}
