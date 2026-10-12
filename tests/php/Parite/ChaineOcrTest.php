<?php

declare(strict_types=1);

namespace Ged\Tests\Parite;

use Ged\Ingestion\TexteOcr;
use PHPUnit\Framework\TestCase;

/**
 * Rejeu du post-traitement du texte OCR (famille « ocr-text » du lot ged-parite-chaine-1).
 *
 * Vecteurs extraits de src/paperless_tesseract/tests/test_parser.py (test_post_process_text) :
 * espaces multiples repliés, espaces en début de ligne après un saut de ligne retirés, espaces de fin
 * retirés — y compris sur un texte non latin. L'ORACLE est rejoué tel quel, aucun cas particulier.
 */
final class ChaineOcrTest extends TestCase
{
    public function testRejoueLePostTraitementDuTexteOcr(): void
    {
        $donnees = Vecteurs::charger('chaine-ocr-text');
        $echecs = [];
        $passes = 0;

        foreach ($donnees['vecteurs'] as $vecteur) {
            $entree = is_array($vecteur['entree'] ?? null) ? $vecteur['entree'] : [];
            $attendu = is_array($vecteur['sortie'] ?? null) ? ($vecteur['sortie']['texte'] ?? null) : null;
            $obtenu = TexteOcr::postTraiter((string) ($entree['texte'] ?? ''));
            if ($obtenu === $attendu) {
                $passes++;
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

        Vecteurs::ecrire('ocr-text', [
            'version' => 1,
            'reference' => $donnees['reference'] ?? [],
            'categorie' => 'ocr-text',
            'total' => count($donnees['vecteurs']),
            'passes' => $passes,
            'ecarts' => $echecs,
        ]);

        self::assertSame([], $echecs, "vecteurs OCR en écart :\n" . implode("\n", $echecs));
    }

    public function testEntreeVideNeRendPasDeTexte(): void
    {
        self::assertNull(TexteOcr::postTraiter(''), 'un texte vide n’a pas de contenu OCR');
    }
}
