<?php

declare(strict_types=1);

namespace Ged\Ui;

use Ged\Config;

/**
 * Écran « Journaux » (modèle Paperless `LogsComponent`, lot ged-parite-ecrans-1).
 *
 * Paperless lit ses fichiers `.log` ; GEDv2 lit son journal d'audit NDJSON — écrit en AJOUT SEUL par
 * `Ged\Securite\JournalAudit` — et le journal de scellement/rétention de l'archivage. Les deux vivent
 * dans des fichiers du fonds de travail : rien à lire en base, rien à modifier.
 *
 * L'écran est réservé aux administrateurs (permission `application:administrer`). Il est en LECTURE
 * (aucune écriture, aucune purge) et nomme une source illisible au lieu de rendre un 500.
 */
final class Journaux
{
    public const LIGNES_DEFAUT = 100;
    public const LIGNES_MIN = 10;
    public const LIGNES_MAX = 1000;

    public function __construct(private readonly Config $config)
    {
    }

    public static function depuisRacine(?string $racine = null): self
    {
        return new self(new Config($racine ?? dirname(__DIR__, 2)));
    }

    /**
     * @param array<string, mixed> $parametres
     * @return array{
     *   ecran:string, lignes:int, action:string, actions:list<string>,
     *   entrees:list<array{horodatage:string, action:string, utilisateur:string, contexte:array<string,mixed>, source:string}>,
     *   sources:list<array{nom:string, existe:bool}>,
     *   erreur:?string
     * }
     */
    public function donnees(array $parametres): array
    {
        $lignes = (int) ($parametres['lignes'] ?? self::LIGNES_DEFAUT);
        $lignes = max(self::LIGNES_MIN, min(self::LIGNES_MAX, $lignes));
        $action = trim((string) ($parametres['action'] ?? ''));

        $sources = $this->sources();
        $toutes = [];
        $erreurs = [];
        foreach ($sources as $source) {
            if (!$source['existe']) {
                continue;
            }
            try {
                $toutes = array_merge($toutes, $this->lire($source['nom'], $source['fichier']));
            } catch (\Throwable $e) {
                $erreurs[] = $source['nom'] . ' : ' . $e->getMessage();
            }
        }

        usort($toutes, static fn (array $a, array $b): int => strcmp($b['horodatage'], $a['horodatage']));

        $actions = [];
        foreach ($toutes as $entree) {
            $actions[$entree['action']] = true;
        }
        $actions = array_keys($actions);
        sort($actions);

        $retenues = $action === ''
            ? $toutes
            : array_values(array_filter($toutes, static fn (array $e): bool => $e['action'] === $action));

        return [
            'ecran' => 'journaux',
            'lignes' => $lignes,
            'action' => $action,
            'actions' => $actions,
            'entrees' => array_slice($retenues, 0, $lignes),
            'total_journal' => count($toutes),
            'sources' => array_map(static fn (array $s): array => ['nom' => $s['nom'], 'existe' => $s['existe']], $sources),
            'erreur' => $erreurs === [] ? null : implode(' | ', $erreurs),
        ];
    }

    /**
     * Les journaux lisibles : le journal d'audit et le journal de l'archivage, s'ils existent.
     *
     * @return list<array{nom:string, fichier:string, existe:bool}>
     */
    private function sources(): array
    {
        $audit = $this->config->chemin('SECURITE_DOSSIER', 'var/securite') . '/audit.ndjson';
        $archivage = $this->config->chemin('STORAGE_ROOT', 'storage/documents') . '/.archivage/journal.ndjson';

        return [
            ['nom' => 'audit', 'fichier' => $audit, 'existe' => is_file($audit)],
            ['nom' => 'archivage', 'fichier' => $archivage, 'existe' => is_file($archivage)],
        ];
    }

    /**
     * Lit un journal NDJSON, de la plus ancienne à la plus récente entrée. Une ligne illisible ne fait
     * pas tomber l'écran : elle est ignorée (le journal reste en ajout seul, on peut le relire après).
     *
     * @return list<array{horodatage:string, action:string, utilisateur:string, contexte:array<string,mixed>, source:string}>
     */
    private function lire(string $nom, string $fichier): array
    {
        $lignes = file($fichier, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lignes === false) {
            throw new \RuntimeException('journal illisible');
        }

        $entrees = [];
        foreach ($lignes as $ligne) {
            $decode = json_decode($ligne, true);
            if (!is_array($decode)) {
                continue;
            }
            $entrees[] = [
                'horodatage' => (string) ($decode['horodatage'] ?? ''),
                'action' => (string) ($decode['action'] ?? ''),
                'utilisateur' => (string) ($decode['utilisateur'] ?? ''),
                'contexte' => is_array($decode['contexte'] ?? null) ? $decode['contexte'] : [],
                'source' => $nom,
            ];
        }

        return $entrees;
    }
}
