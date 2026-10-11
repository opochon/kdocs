<?php

declare(strict_types=1);

namespace Ged\Ingestion;

/**
 * Contrôle PARTIEL d'une copie PDF/A, en PHP, sans veraPDF (pas de Java sur le poste) :
 *
 *  - en-tête PDF et absence de chiffrement ;
 *  - métadonnées XMP `pdfaid:part = 2` et `pdfaid:conformance = B` (l'identité PDF/A-2b déclarée) ;
 *  - OutputIntent présent, de type GTS_PDFA1 (profil sRGB) ;
 *  - polices : aucune police déclarée sans fichier embarqué (`/FontFile`, `/FontFile2`, `/FontFile3`).
 *
 * C'est un contrôle de SURFACE, pas une validation de conformité : il ne lit ni les flux compressés des
 * objets PDF (donc ne voit pas un `/BaseFont` enfermé dans un flux d'objets), ni les règles fines du
 * standard (transparence, jeux de caractères, ToUnicode…). **veraPDF reste la validation de recette.**
 */
final class ControlePdfA
{
    public const PORTEE = 'partiel';

    /**
     * @return array{ok:bool, portee:string, points:array<string, bool>, raison:string}
     */
    public static function controler(string $fichier): array
    {
        $points = [
            'xmp_pdfa_part2' => false,
            'xmp_conformance_b' => false,
            'output_intent_srgb' => false,
            'sans_chiffrement' => false,
            'polices_incorporees' => false,
        ];
        $brut = is_file($fichier) ? (string) file_get_contents($fichier) : '';
        if ($brut === '' || !str_starts_with($brut, '%PDF-')) {
            return self::bilan($points, 'la copie n’est pas un fichier PDF lisible');
        }

        $points['sans_chiffrement'] = preg_match('#/Encrypt\b#', $brut) !== 1
            && preg_match('#/Filter\s*/Standard#', $brut) !== 1;
        $points['xmp_pdfa_part2'] = self::xmp($brut, 'pdfaid:part') === '2';
        $points['xmp_conformance_b'] = strtoupper((string) self::xmp($brut, 'pdfaid:conformance')) === 'B';
        $points['output_intent_srgb'] = str_contains($brut, '/OutputIntent') && str_contains($brut, '/GTS_PDFA1')
            && str_contains($brut, '/DestOutputProfile');
        $points['polices_incorporees'] = self::policesIncorporees($brut);

        return self::bilan($points, '');
    }

    /**
     * XMP : les valeurs sont écrites tantôt avec des guillemets doubles, tantôt avec des apostrophes selon
     * le producteur ; le contrôle lit la valeur, pas un format d'échappement.
     */
    private static function xmp(string $brut, string $propriete): ?string
    {
        if (preg_match('/' . preg_quote($propriete, '/') . '\s*=\s*(["\'])(.*?)\1/', $brut, $m) === 1) {
            return $m[2];
        }

        return null;
    }

    /**
     * Aucune police déclarée (`/BaseFont`) ne doit rester sans fichier embarqué. Un PDF image seul sans
     * police est conforme (rien à incorporer). Voir la limite « surface » dans le commentaire de classe.
     */
    private static function policesIncorporees(string $brut): bool
    {
        $polices = preg_match_all('#/BaseFont\b#', $brut);
        if ($polices === 0) {
            return true;
        }
        $fichiers = preg_match_all('#/FontFile[23]?\b#', $brut);

        return $fichiers > 0 && $fichiers >= $polices;
    }

    /**
     * @param array<string, bool> $points
     * @return array{ok:bool, portee:string, points:array<string, bool>, raison:string}
     */
    private static function bilan(array $points, string $raison): array
    {
        $manquants = array_keys(array_filter($points, static fn (bool $ok): bool => !$ok));
        if ($raison === '' && $manquants !== []) {
            $raison = 'contrôle partiel en échec : ' . implode(', ', $manquants);
        }

        return ['ok' => $manquants === [] && $raison === '', 'portee' => self::PORTEE, 'points' => $points, 'raison' => $raison];
    }
}
