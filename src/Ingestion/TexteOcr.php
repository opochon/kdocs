<?php

declare(strict_types=1);

namespace Ged\Ingestion;

/**
 * Post-traitement du texte rendu par l'OCR (Tesseract, via le moteur d'ingestion).
 *
 * Équivalent GEDv2 de `paperless_tesseract.parsers.post_process_text` : le texte OCR arrive avec des
 * espaces parasites — suites d'espaces issues de la reconnaissance, indentation après un saut de ligne,
 * blancs de fin de fichier. On replie les suites d'espaces et de tabulations sur un espace unique, on
 * retire l'indentation des lignes, puis on supprime les blancs de fin. Le saut de ligne est PRÉSERVÉ
 * (c'est la structure du document) ; le caractère nul, que certains PDF contiennent, devient un espace
 * plutôt qu'un octet qui casserait l'index et le `.meta.json`. Un texte vide ne rend rien (null), pour
 * ne pas écrire un contenu vide dans l'index.
 */
final class TexteOcr
{
    /**
     * Rend le texte nettoyé, ou null s'il n'y a rien à nettoyer.
     */
    public static function postTraiter(?string $texte): ?string
    {
        if ($texte === null || $texte === '') {
            return null;
        }

        // 1. Suites d'espaces/tabulations (hors sauts de ligne) repliées sur un espace unique.
        $texte = (string) preg_replace('/[^\S\r\n]+/', ' ', $texte);
        // 2. Indentation en début de ligne retirée, sans toucher aux sauts de ligne eux-mêmes.
        $texte = (string) preg_replace('/([\n\r]+)[^\S\n\r]+/', '$1', $texte);
        // 3. Blancs de fin de chaîne retirés (hors sauts de ligne).
        $texte = (string) preg_replace('/[^\S\n\r]+$/', '', $texte);

        return str_replace("\0", ' ', trim($texte));
    }
}
