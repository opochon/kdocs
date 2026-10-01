<?php

declare(strict_types=1);

namespace Ged\Ui;

use Ged\Apercu\Outils;
use Ged\Apercu\Source;

/**
 * Ce que la visionneuse doit charger pour un document réel, construit du chemin et du `.meta.json`
 * (le dossier fait foi). Partagé par la fiche document, l'écran « À traiter » et la Bibliothèque :
 * une seule règle pour la catégorie, le message nommé d'un format non rendable, et le compte de pages.
 */
final class Rendu
{
    /**
     * @param array<string, mixed> $meta métadonnées canoniques du document (`origine.pages` compris)
     *
     * @return array{url:string, categorie:string, pages:int, titre:string, message:string}
     */
    public static function pour(int $id, string $cheminAbsolu, array $meta): array
    {
        $extension = strtolower(pathinfo($cheminAbsolu, PATHINFO_EXTENSION));
        $categorie = Source::categorie($extension);
        $pages = self::compterPages($meta);
        $titre = (string) ($meta['titre'] ?? '');
        $url = '/documents/' . $id . '/apercu/fichier';

        if ($categorie === 'pdf') {
            return ['url' => $url, 'categorie' => 'pdf', 'pages' => $pages, 'titre' => $titre, 'message' => ''];
        }
        if ($categorie === 'image') {
            return ['url' => $url, 'categorie' => 'image', 'pages' => 1, 'titre' => $titre, 'message' => ''];
        }
        if ($categorie === 'bureautique') {
            if (Outils::libreOffice() === null) {
                return ['url' => '', 'categorie' => 'bureautique', 'pages' => $pages, 'titre' => $titre, 'message' => Outils::MSG_LIBREOFFICE];
            }

            return ['url' => $url, 'categorie' => 'pdf', 'pages' => $pages, 'titre' => $titre, 'message' => ''];
        }

        return ['url' => '', 'categorie' => 'inconnu', 'pages' => 0, 'titre' => $titre, 'message' => Outils::MSG_FORMAT];
    }

    /**
     * Le nombre de pages du document : `pages` explicite, sinon les PLAGES d'origine écrites à
     * l'ingestion (« 1-13 », « 25-28,30 »). Un scan de 13 pages ne s'affiche jamais « 1 page ».
     *
     * @param array<string, mixed> $meta
     */
    public static function compterPages(array $meta): int
    {
        if (isset($meta['pages']) && is_numeric($meta['pages'])) {
            return max(1, (int) $meta['pages']);
        }
        $origine = is_array($meta['origine'] ?? null) ? $meta['origine'] : [];
        if (isset($origine['pages'])) {
            $nombre = self::compterPlages($origine['pages']);
            if ($nombre > 0) {
                return $nombre;
            }
        }

        return 1;
    }

    /** « 25-28,30 » → 5, « 13 » → 13, « 1-13 » → 13 ; toute valeur illisible rend 0. */
    private static function compterPlages(mixed $valeur): int
    {
        if (is_numeric($valeur)) {
            return max(0, (int) $valeur);
        }
        if (!is_string($valeur)) {
            return 0;
        }
        $total = 0;
        foreach (explode(',', $valeur) as $morceau) {
            if (preg_match('/^(\d+)(?:-(\d+))?$/', trim($morceau), $m) === 1) {
                $debut = (int) $m[1];
                $fin = ($m[2] ?? '') !== '' ? (int) $m[2] : $debut;
                if ($fin >= $debut) {
                    $total += $fin - $debut + 1;
                }
            }
        }

        return $total;
    }
}
