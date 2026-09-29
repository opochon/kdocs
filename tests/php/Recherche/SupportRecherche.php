<?php

declare(strict_types=1);

namespace Ged\Tests\Recherche;

use Ged\Base;
use Ged\Config;
use Ged\Recherche\IndexPleinTexte;
use Ged\Recherche\Schema as SchemaRecherche;
use Ged\Recherche\Service;
use Ged\Socle\DepotFichiers;
use Ged\Socle\Fichiers;
use Ged\Socle\Schema as SchemaSocle;
use PDO;

/**
 * Socle commun des oracles du secteur Recherche : base de TEST uniquement, racine documentaire éphémère
 * hors du dépôt (aucune donnée réelle), fonds déposé par `Ged\Socle\DepotFichiers`.
 *
 * La table `documents` appartient au Socle : elle est installée mais JAMAIS effacée ici. Un chemin déjà
 * indexé (seconde exécution) est réutilisé et son fichier repose, ce qui évite une collision sur
 * l'unicité du chemin sans toucher aux données d'un autre secteur.
 */
trait SupportRecherche
{
    private PDO $pdo;
    private Config $config;
    private string $racine;
    private string|false $storageAvant;

    protected function setUpRecherche(): void
    {
        $this->config = new Config(dirname(__DIR__, 3));
        self::assertSame('gedv2_test', $this->config->get('DB_NAME'), 'les tests ne touchent que gedv2_test');
        Base::assurerBase($this->config);
        $this->pdo = Base::pdo($this->config);
        // Tables du Socle installées (idempotent) ; seule la table du secteur est reconstruite.
        SchemaSocle::installer($this->pdo);
        $this->pdo->exec('DROP TABLE IF EXISTS ' . SchemaRecherche::TABLE);
        SchemaRecherche::installer($this->pdo);

        $this->storageAvant = getenv('STORAGE_ROOT');
        $this->racine = sys_get_temp_dir() . '/gedv2-recherche-' . bin2hex(random_bytes(4));
        mkdir($this->racine . '/depots', 0775, true);
        mkdir($this->racine . '/fonds', 0775, true);
        putenv('STORAGE_ROOT=' . $this->racine . '/fonds');   // Config lit l'environnement d'abord
    }

    protected function tearDownRecherche(): void
    {
        $this->storageAvant === false ? putenv('STORAGE_ROOT')
            : putenv('STORAGE_ROOT=' . $this->storageAvant);
        $this->supprimer($this->racine);
    }

    /** Fonds documentaire absolu du test. */
    private function fonds(): string
    {
        return $this->racine . '/fonds';
    }

    /**
     * Le corpus de l'oracle de porte, seule vérité partagée : les tests unitaires ne le dupliquent pas.
     *
     * @return list<array<string, mixed>>
     */
    private function corpusOracle(): array
    {
        $oracle = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/tests/oracles/recherche/recherche.json'),
            true, 512, JSON_THROW_ON_ERROR);

        return $oracle['corpus'];
    }

    /** Un moteur branché sur le fonds du test ; l'installation automatique du schéma peut être coupée. */
    private function moteur(bool $autoInstaller = true): Service
    {
        return new Service($this->pdo, IndexPleinTexte::depuis($this->config, $this->pdo), $autoInstaller);
    }

    /**
     * Dépose le corpus de l'oracle par le contrat `Depot` : rend ref => ['id' => int, 'titre' => string].
     *
     * @param list<array<string, mixed>> $corpus
     * @return array<string, array{id:int, titre:string}>
     */
    private function deposerCorpus(array $corpus): array
    {
        $depot = new DepotFichiers($this->config);
        $deposes = [];
        foreach ($corpus as $document) {
            $meta = [
                'titre' => (string) $document['titre'],
                'type' => $document['type'] ?? null,
                'emetteur' => $document['emetteur'] ?? null,
                'date' => $document['date'] ?? null,
                'montant' => $document['montant'] ?? null,
                'texte' => (string) ($document['texte'] ?? ''),
            ];
            $source = $this->pieceSource((string) $document['ref'], (string) ($document['texte'] ?? ''));
            $relatif = self::cheminAttendu($meta, 'txt');
            $existant = $this->idExistant($relatif);
            if ($existant === null) {
                $id = $depot->deposer($source, $meta);
            } else {
                $this->reposerFichier($relatif, $source, $meta);
                $id = $existant;
            }
            $deposes[(string) $document['ref']] = ['id' => $id, 'titre' => (string) $meta['titre']];
        }

        // L'index plein texte est bâti HORS requête (job) : le banc joue ce job une fois, comme le planificateur.
        IndexPleinTexte::depuis($this->config, $this->pdo)->synchroniser(null);

        return $deposes;
    }

    /** @param array<string, mixed> $meta */
    private static function cheminAttendu(array $meta, string $extension): string
    {
        $annee = isset($meta['date']) && preg_match('#^\d{4}-\d{2}-\d{2}$#', (string) $meta['date'])
            ? substr((string) $meta['date'], 0, 4) : date('Y');
        $segments = [$annee, isset($meta['type']) && $meta['type'] !== null && $meta['type'] !== ''
            ? Fichiers::assainirSegment((string) $meta['type']) : Fichiers::assainirSegment('A classer')];
        if (isset($meta['emetteur']) && $meta['emetteur'] !== null && $meta['emetteur'] !== '') {
            $segments[] = Fichiers::assainirSegment((string) $meta['emetteur']);
        }

        return implode('/', $segments) . '/' . Fichiers::nomRangé((string) $meta['titre'], $extension);
    }

    private function idExistant(string $relatif): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM documents WHERE chemin_relatif = ? LIMIT 1');
        $stmt->execute([$relatif]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /** @param array<string, mixed> $meta */
    private function reposerFichier(string $relatif, string $source, array $meta): void
    {
        $absolu = $this->fonds() . '/' . $relatif;
        if (!is_dir(dirname($absolu))) {
            mkdir(dirname($absolu), 0775, true);
        }
        copy($source, $absolu);
        file_put_contents($absolu . '.meta.json', Fichiers::json($meta, true));
    }

    private function pieceSource(string $ref, string $texte): string
    {
        $fichier = $this->racine . '/depots/' . $ref . '.txt';
        file_put_contents($fichier, $texte);

        return $fichier;
    }

    private function supprimer(string $chemin): void
    {
        if (!is_dir($chemin)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($chemin, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($chemin);
    }
}
