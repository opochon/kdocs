<?php

declare(strict_types=1);

namespace Ged\Tests\EspacePartage;

use Ged\Base;
use Ged\Config;
use Ged\Socle\Registre;
use Ged\Socle\Schema;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Socle commun des oracles de l'espace personnel & du partage (lot ged-mfiles-espace-1) : base gedv2_test
 * remise à neuf pour les tables du Socle, racine documentaire éphémère hors du dépôt (aucune donnée réelle,
 * aucun corpus commité). Le schéma de sécurité est posé pour exercer le filtrage ACL des favoris/récents.
 */
trait SupportEspace
{
    private PDO $pdo;
    private string $racine;      // racine documentaire du test (STORAGE_ROOT)
    private Config $config;
    private string|false $storageRootAvant;
    private string|false $securiteAvant;

    protected function setUpEspace(): void
    {
        $this->config = new Config(dirname(__DIR__, 3));
        self::assertSame('gedv2_test', $this->config->get('DB_NAME'), 'les tests ne touchent que gedv2_test');
        Base::assurerBase($this->config);
        $this->pdo = Base::pdo($this->config);
        // Tables du Socle seules et de la collaboration (les vecteurs de partage en ont besoin) ; on ne
        // touche pas à `recherche_textes`, dépendance d'autres suites qui tournent dans le même processus.
        $this->pdo->exec('DROP TABLE IF EXISTS document_collaboration, document_collaboration_evenements, '
            . 'document_notes, document_liens, document_partages, documents, document_versions, document_deplacements');
        Schema::installer($this->pdo);
        \Ged\Securite\Schema::installer($this->pdo);

        Registre::oublier();
        $this->storageRootAvant = getenv('STORAGE_ROOT');
        $this->securiteAvant = getenv('SECURITE_DOSSIER');
        $this->racine = sys_get_temp_dir() . '/gedv2-espace-' . bin2hex(random_bytes(4));
        mkdir($this->racine . '/depots', 0775, true);
        putenv('STORAGE_ROOT=' . $this->racine . '/fonds');   // Config lit l'environnement d'abord
        putenv('SECURITE_DOSSIER=' . $this->racine . '/securite');   // le journal d'audit reste hors du dépôt
    }

    protected function tearDownEspace(): void
    {
        Registre::oublier();
        $this->storageRootAvant === false ? putenv('STORAGE_ROOT')
            : putenv('STORAGE_ROOT=' . $this->storageRootAvant);
        $this->securiteAvant === false ? putenv('SECURITE_DOSSIER')
            : putenv('SECURITE_DOSSIER=' . $this->securiteAvant);
        $this->supprimerRacine($this->racine);
    }

    /** Un fichier source à déposer, hors de la racine documentaire (comme une pièce extraite d'un lot). */
    private function pieceADeposer(string $nom, string $contenu): string
    {
        $f = $this->racine . '/depots/' . $nom;
        file_put_contents($f, $contenu);

        return $f;
    }

    /**
     * Dépose une pièce synthétique dans le fonds et rend son identifiant d'index.
     */
    private function deposerPiece(string $titre, ?string $dossier = null): int
    {
        $nom = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $titre) ?? 'piece') . '-' . bin2hex(random_bytes(3)) . '.txt';
        $options = [
            'titre' => $titre, 'type' => 'Facture', 'emetteur' => 'Atelier Fictif SA',
            'date' => '2026-10-03', 'texte' => 'contenu ' . $titre, 'statut' => 'valide',
        ];
        if ($dossier !== null) {
            $options['dossier'] = $dossier;
        }

        return (new \Ged\Socle\DepotFichiers($this->config))->deposer($this->pieceADeposer($nom, 'contenu ' . $titre), $options);
    }

    /** Crée un compte de test au rôle `lecteur` (identifiant unique) et rend son identifiant. */
    private function compteUnique(string $etiquette = 'lecteur'): int
    {
        $identifiant = 'compte-' . bin2hex(random_bytes(4)) . '-' . $etiquette;
        $this->pdo->prepare('INSERT INTO comptes (identifiant, empreinte, role) VALUES (?, ?, ?)')
            ->execute([$identifiant, 'x', 'lecteur']);

        return (int) $this->pdo->lastInsertId();
    }

    private function supprimerRacine(string $chemin): void
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
