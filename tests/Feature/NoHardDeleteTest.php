<?php

declare(strict_types=1);

/**
 * Cliquet de l'invariant « zero suppression dure » (governance/budgets.json).
 *
 * K-Docs ne supprime jamais de ligne par un chemin atteignable depuis le produit.
 * Ce controle compte les DELETE FROM presents dans app/ et apps/ et echoue des
 * qu'une valeur depasse son plafond. Les plafonds ne peuvent que descendre.
 *
 * Perimetre : app/ et apps/ seulement. tools/, tests/, database/ et connectors/
 * sont hors perimetre a dessein — reconstruire une base pour les tests est
 * legitime, mais cela n'appartient pas au produit et doit rester un outil externe
 * precede d'un dump.
 *
 * Purement statique : lecture de fichiers, aucun acces base ni reseau.
 */

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class NoHardDeleteTest extends TestCase
{
    private const BUDGETS_PATH = __DIR__ . '/../../governance/budgets.json';

    /** @var list<string> repertoires du produit, ceux que l'invariant protege */
    private const SCANNED = ['app', 'apps'];

    /** @var array<string,mixed> */
    private array $budgets;

    protected function setUp(): void
    {
        $raw = file_get_contents(self::BUDGETS_PATH);
        $this->assertIsString($raw, 'governance/budgets.json illisible');

        $parsed = json_decode($raw, true);
        $this->assertIsArray($parsed, 'governance/budgets.json invalide');
        $this->budgets = $parsed['budgets'] ?? [];
        $this->assertNotEmpty($this->budgets, 'aucun budget declare');
    }

    /**
     * Toutes les occurrences de DELETE FROM du produit, avec leur table.
     *
     * @return list<array{file:string,line:int,table:string}>
     */
    private function findHardDeletes(): array
    {
        $root  = dirname(__DIR__, 2);
        $found = [];

        foreach (self::SCANNED as $dir) {
            $base = $root . '/' . $dir;
            if (!is_dir($base)) {
                continue;
            }

            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($it as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $lines = file($file->getPathname());
                if ($lines === false) {
                    continue;
                }

                foreach ($lines as $i => $line) {
                    if (preg_match('/DELETE\s+FROM\s+`?([a-zA-Z_][a-zA-Z0-9_]*)`?/i', $line, $m)) {
                        $found[] = [
                            'file'  => str_replace($root . DIRECTORY_SEPARATOR, '', $file->getPathname()),
                            'line'  => $i + 1,
                            'table' => strtolower($m[1]),
                        ];
                    }
                }
            }
        }

        return $found;
    }

    /**
     * @param list<array{file:string,line:int,table:string}> $hits
     */
    private function describe(array $hits): string
    {
        return implode("\n", array_map(
            static fn (array $h): string => "  {$h['file']}:{$h['line']} -> {$h['table']}",
            $hits
        ));
    }

    /**
     * Le coeur de l'invariant : la table documents ne perd jamais une ligne.
     * Trois chemins la detruisaient avant le 2026-08-07 ; plafond fige a 0.
     */
    public function testAucuneSuppressionDureSurLaTableDocuments(): void
    {
        $max  = (int) ($this->budgets['hard-delete-documents']['max'] ?? 0);
        $hits = array_values(array_filter(
            $this->findHardDeletes(),
            static fn (array $h): bool => $h['table'] === 'documents'
        ));

        $this->assertLessThanOrEqual(
            $max,
            count($hits),
            "Suppression dure sur la table documents : plafond {$max}, trouve "
            . count($hits) . ".\nK-Docs ne supprime jamais de ligne — marquer deleted_at.\n"
            . $this->describe($hits)
        );
    }

    /**
     * La piste de revision ne doit pas pouvoir s'effacer elle-meme.
     */
    public function testSuppressionsSurLesTablesDeTracabiliteSousPlafond(): void
    {
        $budget = $this->budgets['hard-delete-tracabilite'] ?? [];
        $max    = (int) ($budget['max'] ?? 0);
        $tables = array_map('strtolower', $budget['tables'] ?? []);
        $this->assertNotEmpty($tables, 'aucune table de tracabilite declaree');

        $hits = array_values(array_filter(
            $this->findHardDeletes(),
            static fn (array $h): bool => in_array($h['table'], $tables, true)
        ));

        $this->assertLessThanOrEqual(
            $max,
            count($hits),
            "Suppressions sur les tables de tracabilite : plafond {$max}, trouve "
            . count($hits) . ".\n" . $this->describe($hits)
        );
    }

    /**
     * Cliquet global : le nombre total de suppressions dures du produit ne
     * remonte jamais. Ajouter un DELETE fait tomber ce test.
     */
    public function testTotalDesSuppressionsDuresSousPlafond(): void
    {
        $max  = (int) ($this->budgets['hard-delete-total']['max'] ?? 0);
        $hits = $this->findHardDeletes();

        $this->assertLessThanOrEqual(
            $max,
            count($hits),
            "Total des suppressions dures : plafond {$max}, trouve " . count($hits) . ".\n"
            . "Un cliquet ne remonte pas. Si la hausse est justifiee, c'est l'invariant "
            . "qu'il faut rediscuter, pas le plafond qu'il faut relever.\n"
            . $this->describe($hits)
        );
    }

    /**
     * Les trois chemins neutralises le restent : ils levent l'exception dediee
     * au lieu de detruire. Un retour en arriere silencieux est ainsi visible.
     */
    public function testLesTroisCheminsNeutralisesLeventBienLException(): void
    {
        $root = dirname(__DIR__, 2);

        $attendus = [
            'app/Services/TrashService.php'          => ['deletePermanently', 'emptyTrash'],
            'app/Repositories/DocumentRepository.php' => ['forceDelete'],
        ];

        foreach ($attendus as $rel => $methodes) {
            $src = file_get_contents($root . '/' . $rel);
            $this->assertIsString($src, "{$rel} illisible");

            foreach ($methodes as $methode) {
                $this->assertMatchesRegularExpression(
                    '/function\s+' . preg_quote($methode, '/') . '\s*\([^)]*\)[^{]*\{[^}]*HardDeleteForbiddenException/s',
                    $src,
                    "{$rel}::{$methode}() ne leve plus HardDeleteForbiddenException — "
                    . "la suppression dure a ete rebranchee."
                );
            }
        }

        $task = file_get_contents($root . '/app/Services/TaskService.php');
        $this->assertIsString($task, 'TaskService illisible');
        $this->assertStringNotContainsString(
            'DELETE FROM documents',
            $task,
            'TaskService::cleanupTrash detruit de nouveau des documents.'
        );
    }
}
