<?php

declare(strict_types=1);

namespace Ged\Tests\Jonctions;

use Ged\Base;
use Ged\Config;
use Ged\Ingestion\Schema as SchemaIngestion;
use Ged\Jonctions\Jetons;
use Ged\Jonctions\Schema as SchemaJonctions;
use Ged\Securite\DroitsDossiers;
use Ged\Securite\Schema as SchemaSecurite;
use Ged\Socle\Schema as SchemaSocle;
use PDO;
use Psr\Http\Message\UploadedFileInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Socle des oracles du lot V2-08b (appairage, destinations, .eml).
 *
 * Base de TEST uniquement (`gedv2_test`) ; le fonds et la zone d'ingestion vivent dans un dossier
 * éphémère hors du dépôt (zéro donnée réelle). Les tables du Socle, des Droits et de l'Ingestion sont
 * installées mais JAMAIS effacées : le nettoyage ne touche que ce que ce test a créé (préfixe unique).
 */
trait SupportJonctions
{
    private PDO $pdo;
    private string $racine;
    private string $fonds;
    private string $travail;
    private string $sel;
    private string|false $storageAvant;
    private string|false $travailAvant;

    protected function setUpJonctions(): void
    {
        $config = new Config(dirname(__DIR__, 3));
        self::assertSame('gedv2_test', $config->get('DB_NAME'), 'les tests ne touchent que gedv2_test');
        Base::assurerBase($config);
        $this->pdo = Base::pdo($config);

        // Le secteur isole SES tables : elles sont reconstruites à chaque test.
        $this->pdo->exec('DROP TABLE IF EXISTS jonctions_codes_appairage');
        $this->pdo->exec('DROP TABLE IF EXISTS jonctions_jetons');
        SchemaJonctions::installer($this->pdo);
        SchemaSecurite::installer($this->pdo);
        SchemaSocle::installer($this->pdo);
        SchemaIngestion::installer($this->pdo);
        // Travaux d'ingestion d'autres tests laissés en `recu` : sans ce nettoyage, le Pilote du test
        // d'origine CMM réserverait leur copie de travail (dossier temporaire déjà supprimé).
        $this->pdo->exec('DELETE FROM ingestion_travaux');

        $this->sel = bin2hex(random_bytes(5));
        $this->racine = sys_get_temp_dir() . '/gedv2-jonctions-' . $this->sel;
        $this->fonds = $this->racine . '/fonds';
        $this->travail = $this->racine . '/ingestion';
        mkdir($this->fonds, 0775, true);
        mkdir($this->travail, 0775, true);

        $this->storageAvant = getenv('STORAGE_ROOT');
        $this->travailAvant = getenv('INGESTION_TRAVAIL');
        putenv('STORAGE_ROOT=' . $this->fonds);
        putenv('INGESTION_TRAVAIL=' . $this->travail);
    }

    protected function tearDownJonctions(): void
    {
        $this->storageAvant === false ? putenv('STORAGE_ROOT') : putenv('STORAGE_ROOT=' . $this->storageAvant);
        $this->travailAvant === false ? putenv('INGESTION_TRAVAIL') : putenv('INGESTION_TRAVAIL=' . $this->travailAvant);

        $this->pdo->exec('DELETE FROM jonctions_codes_appairage');
        $this->pdo->exec('DELETE FROM jonctions_jetons');
        $this->pdo->prepare('DELETE FROM ingestion_travaux WHERE chemin LIKE ?')->execute([$this->racine . '%']);

        // Documents déposés par ce test : chemins uniques au test, on les retire de l'index partagé.
        $prefixe = 'Clients-' . $this->sel . '%';
        $ids = $this->pdo->prepare('SELECT id FROM documents WHERE chemin_relatif LIKE ? OR chemin_relatif LIKE ?');
        $ids->execute([$prefixe, 'Confidentiel-' . $this->sel . '%']);
        foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $this->pdo->exec('DELETE FROM document_versions WHERE document_id = ' . (int) $id);
            $this->pdo->exec('DELETE FROM document_deplacements WHERE document_id = ' . (int) $id);
            $this->pdo->exec('DELETE FROM recherche_textes WHERE document_id = ' . (int) $id);
        }
        $this->pdo->prepare('DELETE FROM documents WHERE chemin_relatif LIKE ? OR chemin_relatif LIKE ?')
            ->execute([$prefixe, 'Confidentiel-' . $this->sel . '%']);
        $this->pdo->prepare('DELETE FROM documents WHERE meta LIKE ? OR meta LIKE ?')
            ->execute(['%' . $this->sel . '%', '%' . $this->sel . '%']);

        $this->pdo->prepare('DELETE FROM securite_dossiers_droits WHERE groupe_id IN (SELECT id FROM securite_groupes WHERE nom LIKE ?)')
            ->execute(['jonctions-' . $this->sel . '%']);
        $this->pdo->prepare('DELETE FROM securite_groupes_comptes WHERE groupe_id IN (SELECT id FROM securite_groupes WHERE nom LIKE ?)')
            ->execute(['jonctions-' . $this->sel . '%']);
        $this->pdo->prepare('DELETE FROM securite_dossiers WHERE chemin_relatif LIKE ? OR chemin_relatif LIKE ?')
            ->execute(['Clients-' . $this->sel . '%', 'Confidentiel-' . $this->sel . '%']);
        $this->pdo->prepare('DELETE FROM securite_groupes WHERE nom LIKE ?')->execute(['jonctions-' . $this->sel . '%']);
        $this->pdo->prepare('DELETE FROM comptes WHERE identifiant LIKE ?')->execute(['cmm-' . $this->sel . '%']);

        $this->supprimer($this->racine);
    }

    /**
     * Un compte réel avec un groupe « contributeur » et deux dossiers ACL : `Clients-<sel>` (écriture)
     * et `Confidentiel-<sel>` (refus explicite). Rend compte, identifiant et chemins.
     *
     * @return array{compte:int, identifiant:string, ecriture:string, enfant:string, refus:string}
     */
    protected function compteEtDossiers(): array
    {
        $identifiant = 'cmm-' . $this->sel . '@exemple.test';
        $this->pdo->prepare('INSERT INTO comptes (identifiant, empreinte, role) VALUES (?, ?, ?)')
            ->execute([$identifiant, password_hash('secret-' . $this->sel, PASSWORD_DEFAULT), 'contributeur']);
        $compte = (int) $this->pdo->lastInsertId();

        $droits = new DroitsDossiers($this->pdo);
        $groupe = $droits->creerGroupe('jonctions-' . $this->sel, 'contributeur');
        $droits->ajouterCompteAuGroupe($compte, $groupe);

        $ecriture = 'Clients-' . $this->sel;
        $enfant = $ecriture . '/Prives';
        $refus = 'Confidentiel-' . $this->sel;
        $droits->poserDossier($groupe * 10 + 1, null, $ecriture);
        $droits->poserDossier($groupe * 10 + 2, $groupe * 10 + 1, $enfant);
        $droits->poserDossier($groupe * 10 + 3, null, $refus);
        $droits->poserRegle($groupe * 10 + 1, $groupe, 'modifier', true);
        $droits->poserRegle($groupe * 10 + 3, $groupe, 'modifier', false);

        return ['compte' => $compte, 'identifiant' => $identifiant, 'ecriture' => $ecriture, 'enfant' => $enfant, 'refus' => $refus];
    }

    /** Jeton d'appairage lié à un compte, valeur en clair (jamais relue après). */
    protected function jetonAppairage(int $compte, string $identifiant, string $poste = 'poste-test'): string
    {
        return (new Jetons($this->pdo))->creerAppairage('cmm', $poste, $compte, $identifiant);
    }

    /** Écrit une pièce dans le dossier temporaire du test ; nom d'origine donné à l'appelant. */
    protected function piece(string $nom, string $contenu): string
    {
        $chemin = $this->racine . '/' . bin2hex(random_bytes(4)) . '-' . $nom;
        file_put_contents($chemin, $contenu);

        return $chemin;
    }

    /**
     * Envoie un dépôt multipart et rend [statut, corps JSON].
     *
     * @param array<string, mixed> $champs
     * @return array{int, array<string, mixed>}
     */
    protected function envoyer(App $app, string $jeton, UploadedFileInterface $fichier, array $champs = []): array
    {
        $requete = (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/depots')
            ->withUploadedFiles(['fichier' => $fichier]);
        if ($jeton !== '') {
            $requete = $requete->withHeader('Authorization', 'Bearer ' . $jeton);
        }
        if ($champs !== []) {
            $requete = $requete->withParsedBody($champs);
        }
        $reponse = $app->handle($requete);

        return [$reponse->getStatusCode(), json_decode((string) $reponse->getBody(), true, 512, JSON_THROW_ON_ERROR) ?: []];
    }

    /** Une requête JSON jouée rôle de CMM (hors session). @return array{int, array<string,mixed>} */
    protected function appel(App $app, string $methode, string $chemin, ?string $jeton = null, ?array $corps = null): array
    {
        $requete = (new ServerRequestFactory())->createServerRequest($methode, $chemin);
        if ($jeton !== null) {
            $requete = $requete->withHeader('Authorization', 'Bearer ' . $jeton);
        }
        if ($corps !== null) {
            $requete = $requete->withParsedBody($corps);
        }
        $reponse = $app->handle($requete);

        return [$reponse->getStatusCode(), json_decode((string) $reponse->getBody(), true, 512, JSON_THROW_ON_ERROR) ?: []];
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
