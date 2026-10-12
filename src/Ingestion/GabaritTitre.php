<?php

declare(strict_types=1);

namespace Ged\Ingestion;

/**
 * Rend un titre qui contient des placeholders (`{correspondant}`, `{{ added_year }}`, …).
 *
 * Équivalent GEDv2 de `ConsumerPlugin._parse_title_placeholders` : un titre de surcharge peut citer le
 * correspondant, le type, le propriétaire, la date d'ajout ou le nom du fichier ; le rendu remplace ces
 * placeholders par leurs valeurs au moment de la consommation. Le contexte et le rendu sont ceux du
 * gabarit de chemin (`GabaritChemin::rendre`) : un placeholder inconnu fait ÉCHOUER le rendu (null) pour
 * que l'appelant retombe sur le titre d'origine, jamais sur un titre troué.
 */
final class GabaritTitre
{
    /**
     * Contexte d'un titre : les mêmes clés que le gabarit de chemin, plus `added_time`, `original_filename`
     * et `filename` (sans extension), comme `parse_w_workflow_placeholders`.
     *
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    public static function contexte(array $document): array
    {
        $contexte = GabaritChemin::contexte($document);
        $ajout = (string) ($document['date_ajout'] ?? '');
        $contexte['added_time'] = preg_match('/^\d{4}-\d{2}-\d{2} (\d{2}:\d{2})/', $ajout, $trouve) === 1
            ? $trouve[1] : GabaritChemin::VIDE;
        $contexte['original_filename'] = isset($document['nom_origine']) && $document['nom_origine'] !== ''
            ? pathinfo((string) $document['nom_origine'], PATHINFO_FILENAME) : GabaritChemin::VIDE;
        $contexte['filename'] = $document['filename'] ?? GabaritChemin::VIDE;

        return $contexte;
    }

    /**
     * Rend le titre, ou null si un placeholder est inconnu (l'appelant garde alors le titre d'origine).
     *
     * @param array<string, mixed> $document
     */
    public static function rendreTitre(string $titre, array $document): ?string
    {
        return GabaritChemin::rendre($titre, self::contexte($document));
    }
}
