<?php

declare(strict_types=1);

namespace Ged\Tests\Perf;

use Ged\Base;
use Ged\Config;
use Ged\Recherche\Schema as SchemaRecherche;
use Ged\Socle\Fichiers;
use Ged\Socle\Schema as SchemaSocle;
use PDO;

/**
 * Fonds de charge synthétique pour les oracles PERF-1 : 2 000 documents (`a_controler` en tête, le reste
 * `valide`) déposés dans un dossier TEMPORAIRE, indexés dans `gedv2_test` sous le verrou partagé de PHPUnit.
 *
 * Deux moyens de prouver qu'une requête ne relit plus le fonds entier :
 * - le compteur d'accès du serveur (`Handler_read_rnd_next` : ~6 lignes pour une requête indexée, ~2000
 *   pour un parcours de table) ;
 * - le temps, mesuré au plus juste sur plusieurs tours, comparé au parcours complet.
 *
 * Aucune donnée réelle : titres et émetteurs sont fictifs.
 */
trait SupportFondsPerf
{
    protected const PERF_DOCUMENTS = 2000;
    protected const PERF_A_CONTROLER = 10;

    private PDO $pdo;
    private Config $config;
    private string $bac;
    private string $fonds;
    private string $prefixe;
    private string|false $storageAvant;

    protected function setUpFondsPerf(): void
    {
        $this->config = new Config(dirname(__DIR__, 3));
        self::assertSame('gedv2_test', $this->config->get('DB_NAME'), 'les oracles de charge ne touchent que gedv2_test');
        Base::assurerBase($this->config);
        $this->pdo = Base::pdo($this->config);
        SchemaSocle::installer($this->pdo);
        SchemaRecherche::installer($this->pdo);

        $this->bac = sys_get_temp_dir() . '/gedv2-perf-' . bin2hex(random_bytes(4));
        $this->fonds = $this->bac . '/fonds';
        if (!is_dir($this->fonds) && !mkdir($this->fonds, 0775, true) && !is_dir($this->fonds)) {
            self::fail('fonds de charge : création impossible');
        }
        $this->prefixe = 'perf-' . bin2hex(random_bytes(4));
        $this->storageAvant = getenv('STORAGE_ROOT');
        putenv('STORAGE_ROOT=' . $this->fonds);

        $this->peuplerFondsPerf();
    }

    protected function tearDownFondsPerf(): void
    {
        try {
            $this->pdo->prepare('DELETE FROM documents WHERE chemin_relatif LIKE ?')
                ->execute([$this->prefixe . '/%']);
        } catch (\Throwable) {
            // base déjà nettoyée par un autre oracle : rien à faire.
        }
        try {
            $this->pdo->exec('TRUNCATE TABLE ' . SchemaRecherche::TABLE);
        } catch (\Throwable) {
            // table absente : rien à faire.
        }
        if (is_dir($this->fonds . '.absent')) {
            @rename($this->fonds . '.absent', $this->fonds);
        }
        $this->storageAvant === false ? putenv('STORAGE_ROOT') : putenv('STORAGE_ROOT=' . $this->storageAvant);
        self::supprimerDossierPerf($this->bac);
    }

    protected function pdo(): PDO
    {
        return $this->pdo;
    }

    protected function config(): Config
    {
        return $this->config;
    }

    protected function fonds(): string
    {
        return $this->fonds;
    }

    /** Un document du vivier `valide`, avec son fichier : la cible des aperçus. */
    protected function idValide(): int
    {
        return self::PERF_A_CONTROLER + 1;
    }

    /** Valeur cumulée d'un compteur d'accès du serveur (delta entre deux relevés = travail réel). */
    protected function compteurAcces(string $nom): int
    {
        $ligne = $this->pdo->query('SHOW SESSION STATUS LIKE ' . $this->pdo->quote($nom))->fetch(PDO::FETCH_ASSOC);

        return (int) ($ligne['Value'] ?? -1);
    }

    /** Durée minimale (ms) d'une action rejouée : le bruit de mesure disparaît, le régime à chaud reste. */
    protected function dureeMinimale(callable $action, int $tours = 3): float
    {
        $meilleure = INF;
        for ($i = 0; $i < $tours; $i++) {
            $debut = microtime(true);
            $action();
            $meilleure = min($meilleure, (microtime(true) - $debut) * 1000.0);
        }

        return $meilleure;
    }

    /** Éloigne (ou ramène) le fonds : une requête qui le relit encore ne peut plus répondre. */
    protected function eloignerFonds(bool $absent): void
    {
        if ($absent) {
            self::assertTrue(rename($this->fonds, $this->fonds . '.absent'), 'le fonds de test doit pouvoir être éloigné');
        } else {
            self::assertTrue(rename($this->fonds . '.absent', $this->fonds), 'le fonds de test doit pouvoir être ramené');
        }
    }

    /**
     * Écrit 2 000 documents du fonds (fichier + `.meta.json`) et leurs lignes d'index, exactement comme le
     * fait le banc de charge : la table `documents` du Socle est reconstruite pour ce rejeu (base de TEST).
     */
    private function peuplerFondsPerf(): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['recherche_textes', 'document_versions', 'document_deplacements', 'documents'] as $table) {
            try {
                $this->pdo->exec("TRUNCATE TABLE $table");
            } catch (\PDOException) {
                // table d'un secteur absent : rien à vider.
            }
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        $texte = "maintenance contrat facture devis attestation\n" . str_repeat('pièce de charge synthétique ', 40);
        $dossierRelatif = $this->prefixe . '/2026/Facture';
        $dossier = $this->fonds . '/' . $dossierRelatif;
        if (!is_dir($dossier) && !mkdir($dossier, 0775, true) && !is_dir($dossier)) {
            self::fail('fonds de charge : création du rangement impossible');
        }

        $insertion = $this->pdo->prepare('INSERT INTO documents (id, chemin_relatif, nom, empreinte, taille, mime, meta, '
            . 'titre, type, emetteur, date_document, montant, etiquettes, statut, source, proprietaire_id) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

        $this->pdo->beginTransaction();
        try {
            for ($i = 1; $i <= self::PERF_DOCUMENTS; $i++) {
                $statut = $i <= self::PERF_A_CONTROLER ? 'a_controler' : 'valide';
                $nomFichier = sprintf('piece-%05d.txt', $i);
                $relatif = $dossierRelatif . '/' . $nomFichier;
                $absolu = $this->fonds . '/' . $relatif;
                file_put_contents($absolu, $texte);
                $meta = [
                    'id' => $i,
                    'titre' => sprintf('Pièce perf %05d', $i),
                    'type' => 'Facture',
                    'emetteur' => 'Émetteur Perf',
                    'date' => sprintf('2026-%02d-%02d', (($i - 1) % 12) + 1, (($i - 1) % 28) + 1),
                    'montant' => 100.0 + $i,
                    'etiquettes' => ['perf'],
                    'statut' => $statut,
                    'source' => 'dossier',
                    'texte' => $texte,
                    'raison' => 'confiance IA insuffisante (0.62 < 0.8)',
                    'classement' => ['source' => 'regle', 'confiance' => 0.62, 'preuve' => 'mot « facture » dans le texte'],
                    'origine' => ['lot' => 0, 'pages' => '1'],
                ];
                file_put_contents($absolu . '.meta.json', Fichiers::json($meta, true));
                [$entetes, $valeurs] = Fichiers::colonnesIndex($meta);
                $insertion->execute([$i, $relatif, $nomFichier, hash('sha256', $texte), strlen($texte),
                    'text/plain', Fichiers::json($meta), ...$valeurs]);
            }
            $this->pdo->commit();
        } catch (\Throwable $erreur) {
            $this->pdo->rollBack();

            throw $erreur;
        }
    }

    private static function supprimerDossierPerf(string $chemin): void
    {
        if (!is_dir($chemin)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($chemin, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($chemin);
    }
}
