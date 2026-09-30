<?php

declare(strict_types=1);

namespace Ged\Socle;

use Ged\Config;
use PDO;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Lecture du fonds (V2-04). Deux chemins, deux usages :
 * - LISTER : l'index (`indexes`), une requête — la base est le miroir reconstructible du dossier
 *   (F12), marcher 20 000 fichiers pour lister 10 000 documents ruinerait la bibliothèque ;
 * - LA VÉRITÉ : le dossier (`tous`), chaque fichier qui n'est ni une annexe (`.meta.json`,
 *   `.versions/`, `.corbeille/`) ni un fichier interne de l'OS est un document ; ses métadonnées
 *   et ses champs personnalisés vivent dans le `.meta.json` à côté. Les VALEURS du document
 *   retenu s'y relisent — le dossier fait foi, l'index ne sert qu'à aller vite.
 */
final class Fonds
{
    private readonly string $racine;

    public function __construct(Config $config)
    {
        $this->racine = rtrim(str_replace('\\', '/', $config->chemin('STORAGE_ROOT', 'storage/documents')), '/');
    }

    public function racine(): string
    {
        return $this->racine;
    }

    /**
     * Tous les documents vivants du fonds, par chemin croissant (ordre stable, déterministe).
     *
     * @param PDO|null $pdo index optionnel : identifiants stables des documents rangés par l'application
     * @return list<array{id:int, chemin:string, nom:string, titre:string, type:?string, emetteur:?string,
     *               date:?string, montant:?float, etiquettes:list<string>, champs:array<string, mixed>}>
     */
    public function tous(?PDO $pdo = null): array
    {
        $ids = $pdo === null ? [] : $this->idsParChemin($pdo);
        $docs = [];
        foreach ($this->fichiers() as $relatif) {
            $absolu = $this->racine . '/' . $relatif;
            $meta = is_file("$absolu.meta.json")
                ? (json_decode((string) file_get_contents("$absolu.meta.json"), true) ?: [])
                : Fichiers::metaMinimale(basename($relatif));

            $docs[] = $this->fiche($meta, $relatif, $ids[$relatif] ?? null);
        }

        return $docs;
    }

    /**
     * Les documents vivants lus de l'INDEX — une requête, zéro accès au dossier. Sur un fonds de
     * 10 000 pièces, lister en lisant 20 000 fichiers ruinerait la bibliothèque (mesuré au banc de
     * charge : p95 > 20 s), et revérifier chaque ligne l'a ruinée aussi (p50 2,7 s). La vie d'une
     * ligne se lit à ses DRAPEAUX, tenus par les chemins d'écriture : `supprime_le` (corbeille) et
     * `absent_depuis` (reconstructeur, F12). Le dossier fait foi AU POINT D'USAGE : le document
     * retenu est relu de son `.meta.json`, et `parId` exige son fichier avant toute écriture.
     *
     * @return list<array{id:int, chemin:string, nom:string, titre:string, type:?string, emetteur:?string,
     *               date:?string, montant:?float, etiquettes:list<string>, champs:array<string, mixed>}>
     */
    public function indexes(PDO $pdo): array
    {
        $docs = [];
        $lignes = $pdo->query('SELECT id, chemin_relatif, meta FROM documents'
            . ' WHERE supprime_le IS NULL AND absent_depuis IS NULL ORDER BY chemin_relatif')->fetchAll();
        foreach ($lignes as $l) {
            $chemin = str_replace('\\', '/', (string) $l['chemin_relatif']);
            $docs[] = $this->fiche(json_decode((string) $l['meta'], true) ?: [], $chemin, (int) $l['id']);
        }

        return $docs;
    }

    /**
     * Le document de ce numéro (identifiant de l'index ou du `.meta.json`), ou null. Par l'index
     * d'abord — une ligne ; le dossier ne sert que si l'index n'existe pas encore.
     */
    public function parId(int $id, ?PDO $pdo = null): ?array
    {
        if ($pdo !== null) {
            try {
                $ligne = $pdo->prepare('SELECT id, chemin_relatif, meta FROM documents'
                    . ' WHERE id = ? AND supprime_le IS NULL AND absent_depuis IS NULL');
                $ligne->execute([$id]);
                $trouve = $ligne->fetch();
                if ($trouve !== false) {
                    $chemin = str_replace('\\', '/', (string) $trouve['chemin_relatif']);
                    if (!is_file($this->racine . '/' . $chemin)) {
                        return null;   // le dossier fait foi : sans fichier, pas de document
                    }

                    return $this->fiche(json_decode((string) $trouve['meta'], true) ?: [], $chemin, (int) $trouve['id']);
                }

                return null;   // l'index fait autorité sur les identifiants : 0 ligne = document inconnu
            } catch (\PDOException) {
                // index absent : le dossier répond seul, comme au premier jour.
            }
        }
        foreach ($this->tous($pdo) as $doc) {
            if ($doc['id'] === $id) {
                return $doc;
            }
        }

        return null;
    }

    /**
     * Arborescence des dossiers qui CONTIENNENT des documents : `année → types`. Elle se calcule de
     * la liste déjà lue (index ou dossier) — jamais d'une seconde marche du fonds.
     *
     * @param list<array<string, mixed>>|null $documents liste déjà lue ; null : lecture du dossier
     * @return list<array{nom:string, enfants:list<string>}>
     */
    public function arbre(?array $documents = null): array
    {
        $niveaux = [];
        foreach ($documents ?? $this->tous() as $doc) {
            $segments = explode('/', $doc['chemin']);
            if (count($segments) < 2) {
                continue;   // document à la racine du fonds : aucune arborescence à montrer
            }
            [$annee, $type] = [$segments[0], $segments[1]];
            $niveaux[$annee][$type] = true;
        }
        $arbre = [];
        foreach ($niveaux as $annee => $types) {
            $arbre[] = ['nom' => (string) $annee, 'enfants' => array_keys($types)];
        }

        return $arbre;
    }

    /**
     * Une fiche de document, de la même forme qu'elle vienne du `.meta.json` ou de sa copie indexée.
     *
     * @param array<string, mixed> $meta métadonnées canoniques (le dossier fait foi, l'index en est la copie)
     * @return array{id:int, chemin:string, nom:string, titre:string, type:?string, emetteur:?string,
     *               date:?string, montant:?float, etiquettes:list<string>, champs:array<string, mixed>}
     */
    private function fiche(array $meta, string $relatif, ?int $id): array
    {
        return [
            'id' => $id ?? (is_numeric($meta['id'] ?? null) ? (int) $meta['id'] : 0),
            'chemin' => $relatif,
            'nom' => basename($relatif),
            'titre' => (string) ($meta['titre'] ?? pathinfo($relatif, PATHINFO_FILENAME)),
            'type' => isset($meta['type']) && is_string($meta['type']) ? $meta['type'] : null,
            'emetteur' => isset($meta['emetteur']) && is_string($meta['emetteur']) ? $meta['emetteur'] : null,
            'date' => isset($meta['date']) && is_string($meta['date']) ? $meta['date'] : null,
            'montant' => isset($meta['montant']) && is_numeric($meta['montant']) ? (float) $meta['montant'] : null,
            'etiquettes' => array_values(array_filter(array_map('strval', (array) ($meta['etiquettes'] ?? [])),
                static fn (string $e) => $e !== '')),
            'champs' => isset($meta['champs']) && is_array($meta['champs']) ? $meta['champs'] : [],
        ];
    }

    /** Chemins relatifs (séparateurs /) de tous les documents du fonds, triés. @return list<string> */
    private function fichiers(): array
    {
        if (!is_dir($this->racine)) {
            return [];
        }
        $fichiers = [];
        $it = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($this->racine, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME),
                // Annexes (dossiers cachés : .versions, .corbeille, .apercu…), .meta.json et poubelles d'OS.
                static function (string $chemin): bool {
                    $nom = basename($chemin);

                    return !str_starts_with($nom, '.') && !str_ends_with($nom, '.meta.json') && !Fichiers::estInterne($nom);
                }
            )
        );
        foreach ($it as $chemin) {
            if (is_file($chemin)) {
                $fichiers[] = str_replace('\\', '/', substr($chemin, strlen($this->racine) + 1));
            }
        }
        sort($fichiers);

        return $fichiers;
    }

    /** Identifiants stables de l'index, par chemin ; muette si l'index n'existe pas encore. @return array<string, int> */
    private function idsParChemin(PDO $pdo): array
    {
        try {
            $ids = [];
            foreach ($pdo->query('SELECT id, chemin_relatif FROM documents WHERE supprime_le IS NULL')->fetchAll() as $l) {
                $ids[str_replace('\\', '/', (string) $l['chemin_relatif'])] = (int) $l['id'];
            }

            return $ids;
        } catch (\PDOException) {
            return [];   // index absent (première seconde de vie d'une installation) : la lecture reste possible
        }
    }
}
