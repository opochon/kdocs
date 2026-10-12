<?php

declare(strict_types=1);

namespace Ged\Ingestion;

/**
 * Surcharges de métadonnées appliquées à un document qui entre.
 *
 * Équivalent GEDv2 de `ConsumerPlugin.apply_overrides` : les valeurs fournies par la source (dossier,
 * courriel, API) ou par un circuit de travail écrasent les métadonnées devinées, mais SEULEMENT celles
 * qui sont fournies — une surcharge absente n'efface jamais l'existant. Les étiquettes s'ajoutent
 * (jamais ne remplacent) et les champs personnalisés se complètent, comme les « nested tags » et les
 * valeurs de champs de Paperless.
 *
 * Le document est un tableau de métadonnées GEDv2 (`.meta.json`) : la fonction est pure et rend un
 * nouveau tableau, ce qui la teste sans base ni disque.
 */
final class Surcharges
{
    /** Clés simples écrasées uniquement si la surcharge les porte. */
    private const SIMPLES = ['titre', 'correspondant', 'type', 'date', 'montant', 'asn', 'proprietaire', 'statut'];

    /**
     * @param array<string, mixed> $document
     * @param array<string, mixed> $surcharges
     * @return array<string, mixed>
     */
    public static function appliquer(array $document, array $surcharges): array
    {
        foreach (self::SIMPLES as $cle) {
            if (array_key_exists($cle, $surcharges) && $surcharges[$cle] !== null) {
                $document[$cle] = $surcharges[$cle];
            }
        }

        if (!empty($surcharges['etiquettes'])) {
            $document['etiquettes'] = self::fusionner(
                (array) ($document['etiquettes'] ?? []),
                (array) $surcharges['etiquettes'],
            );
        }

        if (!empty($surcharges['champs']) && is_array($surcharges['champs'])) {
            $document['champs'] = array_replace(
                (array) ($document['champs'] ?? []),
                $surcharges['champs'],
            );
        }

        return $document;
    }

    /**
     * Fusion d'étiquettes : ordre existant conservé, ajouts à la suite, aucun doublon.
     *
     * @param array<mixed> $existantes
     * @param array<mixed> $ajoutees
     * @return list<string>
     */
    private static function fusionner(array $existantes, array $ajoutees): array
    {
        $resultat = array_values(array_map('strval', $existantes));
        foreach ($ajoutees as $etiquette) {
            $etiquette = (string) $etiquette;
            if ($etiquette !== '' && !in_array($etiquette, $resultat, true)) {
                $resultat[] = $etiquette;
            }
        }

        return $resultat;
    }
}
