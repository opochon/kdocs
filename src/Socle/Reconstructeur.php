<?php

declare(strict_types=1);

namespace Ged\Socle;

use Ged\Base;
use Ged\Config;
use PDO;

/**
 * Reconstruit l'index documentaire DEPUIS le dossier (porte F12) : le fonds est vidé de l'index puis
 * relu intégralement — documents vivants, corbeille, versions dans `.versions/`. Le dossier fait foi :
 * ce qui n'est pas dans le dossier n'existe pas dans l'index reconstruit.
 *
 * Refuse de tourner tant qu'un document vivant est absent du fonds sans être à la corbeille :
 * reconstruire le ferait disparaître de l'index (zéro perte ; `--forcer` assume, en connaissance de cause).
 * Le journal des déplacements n'est pas reconstruisable depuis le dossier : il est remis à neuf —
 * c'est une trace d'histoire, pas une donnée du fonds.
 */
final class Reconstructeur
{
    private readonly DepotFichiers $depot;
    private readonly PDO $pdo;
    private readonly string $racine;

    public function __construct(Config $config)
    {
        $this->depot = new DepotFichiers($config);
        $this->pdo = Base::pdo($config);
        $this->racine = $this->depot->racine();
    }

    /** @return array{documents:int, corbeille:int, versions:int} */
    public function reconstruire(bool $forcer = false): array
    {
        $absents = $this->pdo->query('SELECT chemin_relatif FROM documents WHERE supprime_le IS NULL'
            . ' AND absent_depuis IS NOT NULL LIMIT 3')->fetchAll(PDO::FETCH_COLUMN);
        if ($absents !== [] && !$forcer) {
            throw new \RuntimeException('reconstruction : ' . count($absents) . ' document(s) vivant(s) absent(s) du fonds'
                . ' (ex. ' . $absents[0] . ') ; les retrouver ou forcer la reconstruction au prix de leurs lignes');
        }

        foreach (Schema::TABLES_INDEX as $table) {
            $this->pdo->exec("TRUNCATE TABLE $table");   // index uniquement : jamais comptes ni installation
        }

        $stats = ['documents' => 0, 'corbeille' => 0, 'versions' => 0];
        foreach ($this->lireFonds($this->racine) as $relatif => $absolu) {
            $stats['documents']++;
            [$id, $versions] = $this->indexer($relatif, $absolu);
            $stats['versions'] += $versions;
        }
        foreach ($this->lireFonds($this->racine . '/.corbeille') as $relatif => $absolu) {
            $stats['corbeille']++;
            [$id, $versions] = $this->indexer($relatif, $absolu);
            $stats['versions'] += $versions;
            $this->pdo->exec("UPDATE documents SET supprime_le = NOW() WHERE id = $id");
        }

        return $stats;
    }

    /**
     * Un document relu du dossier : ligne d'index depuis son `.meta.json` (ou son nom), lignes de versions
     * depuis les archives `.versions/<fichier>/vNNN_*`. Rend [identifiant posé, versions posées].
     *
     * @return array{0:int, 1:int}
     */
    private function indexer(string $relatif, string $absolu): array
    {
        $meta = is_file("$absolu.meta.json") ? (json_decode((string) file_get_contents("$absolu.meta.json"), true) ?: [])
            : Fichiers::metaMinimale(basename($relatif));
        $id = $this->depot->enregistrer($relatif, $meta, $absolu);

        $dossierVersions = dirname($absolu) . '/.versions/' . basename($relatif);
        $archives = glob($dossierVersions . '/v*_*') ?: [];
        sort($archives);
        $posees = 0;
        foreach ($archives as $archive) {
            if (preg_match('#/v(\d{3})_[0-9a-f]#', $archive, $m) !== 1) {
                continue;
            }
            $this->depot->poserVersion($id, (int) $m[1], basename($archive), $archive);
            $posees++;
        }

        return [$id, $posees];
    }

    /** Les fichiers documents d'une racine (règles de l'indexeur : internes et `.meta.json` exclus). */
    private function lireFonds(string $racine): array
    {
        $fichiers = [];
        if (!is_dir($racine)) {
            return $fichiers;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($racine, \FilesystemIterator::SKIP_DOTS),
            static fn (\SplFileInfo $f) => !Fichiers::estInterne($f->getFilename()),
        ));
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            if ($f->isFile() && !str_ends_with($f->getFilename(), '.meta.json')) {
                $absolu = str_replace('\\', '/', $f->getPathname());
                $fichiers[substr($absolu, strlen($racine) + 1)] = $absolu;
            }
        }
        ksort($fichiers);

        return $fichiers;
    }
}
