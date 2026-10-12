<?php

declare(strict_types=1);

namespace Ged\Ingestion;

/**
 * Codes-barres d'une liasse : classification (séparateur, numéro d'archive) et découpe en documents.
 *
 * Équivalent GEDv2 de `documents.barcodes` : le LECTEUR de codes-barres est un moteur externe (comme
 * `zxing`/`pyzbar` chez Paperless) ; ce service porte la partie déterministe, celle qui décide, à partir
 * des codes-barres LUS et de la configuration, où couper la liasse et quel numéro d'archive attribuer.
 * Il ne réinvente pas la découpe de GEDv2 (`Ingestion\Cmd4Decoupeur`, qui découpe par contenu) : il
 * prépare les frontières PHYSIQUES (codes-barres) que la chaîne de consommation applique ensuite.
 *
 * Règles reprises du comportement de Paperless, prouvées par les vecteurs :
 *  - un séparateur est une valeur EXACTE (comparaison stricte) ;
 *  - un numéro d'archive est une valeur qui COMMENCE par le préfixe configuré ;
 *  - le premier code-barres ASN l'emporte, le préfixe est retiré, les parties non numériques ignorées ;
 *  - une page de séparation est retirée (conserver=false) ; avec l'option « conserver », elle reste —
 *    sauf la page 0, qui ferait boucler la consommation ;
 *  - un code-barres ASN sur la page 0 ne découpe pas (il désignerait la liasse entière).
 */
final class CodeBarres
{
    /** La valeur est exactement le motif de séparation configuré. */
    public static function estSeparateur(string $valeur, string $motif): bool
    {
        return $valeur === $motif;
    }

    /** La valeur commence par le préfixe de numéro d'archive configuré. */
    public static function estNumeroArchive(string $valeur, string $prefixe): bool
    {
        return $prefixe !== '' && str_starts_with($valeur, $prefixe);
    }

    /**
     * Numéro d'archive porté par une valeur, ou null : préfixe retiré, tout caractère non numérique
     * supprimé, conversion entière. Une valeur sans chiffre ne rend jamais 0, mais null.
     */
    public static function numeroArchive(?string $valeur, string $prefixe): ?int
    {
        if ($valeur === null || !self::estNumeroArchive($valeur, $prefixe)) {
            return null;
        }
        $chiffres = (string) preg_replace('/\D/', '', substr($valeur, strlen($prefixe)));

        return $chiffres === '' ? null : (int) $chiffres;
    }

    /**
     * Numéro d'archive du premier code-barres reconnu d'une liasse, ou null.
     *
     * @param list<array{page:int, valeur:string}> $codesBarres
     */
    public static function numeroArchiveDepuisCodes(array $codesBarres, string $prefixe): ?int
    {
        foreach ($codesBarres as $codeBarres) {
            $numero = self::numeroArchive($codeBarres['valeur'], $prefixe);
            if ($numero !== null) {
                return $numero;
            }
        }

        return null;
    }

    /**
     * Pages de coupe d'une liasse : page (0-indexée) → conserver la page ?
     *
     * @param list<array{page:int, valeur:string}> $codesBarres
     * @return array<int, bool>
     */
    public static function pagesDeSeparation(
        array $codesBarres,
        string $motifSeparateur,
        string $prefixeArchive,
        bool $conserverPages,
        bool $archiveActive,
    ): array {
        $pages = [];
        foreach ($codesBarres as $codeBarres) {
            $page = $codeBarres['page'];
            $valeur = $codeBarres['valeur'];
            if (!self::estSeparateur($valeur, $motifSeparateur)) {
                continue;
            }
            if ($conserverPages && $page === 0) {
                continue;   // garder la page 0 boucle : la liasse ne se viderait jamais
            }
            $pages[$page] = $conserverPages;
        }
        if (!$archiveActive) {
            return $pages;
        }
        foreach ($codesBarres as $codeBarres) {
            $page = $codeBarres['page'];
            if ($page !== 0 && self::estNumeroArchive($codeBarres['valeur'], $prefixeArchive)) {
                $pages[$page] = true;   // un code-barres ASN ouvre un document et garde sa page
            }
        }

        return $pages;
    }

    /**
     * Découpe une liasse de `$pages` pages selon les frontières données ; rend les groupes de pages
     * (0-indexées) de chaque document produit. Une frontière dont la valeur est vraie CONSERVE la page
     * dans le nouveau document ; les groupes vides ne produisent pas de document.
     *
     * @param array<int, bool> $coupes
     * @return list<list<int>>
     */
    public static function separer(int $pages, array $coupes): array
    {
        $documents = [[]];
        for ($page = 0; $page < $pages; $page++) {
            if (!array_key_exists($page, $coupes)) {
                $documents[array_key_last($documents)][] = $page;
                continue;
            }
            $documents[] = [];
            if ($coupes[$page] === true) {
                $documents[array_key_last($documents)][] = $page;
            }
        }

        return array_values(array_filter($documents, static fn (array $groupe): bool => $groupe !== []));
    }
}
