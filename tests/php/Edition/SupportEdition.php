<?php

declare(strict_types=1);

namespace Ged\Tests\Edition;

use Ged\Base;
use Ged\Config;
use Ged\Jonctions\Jetons;
use Ged\Jonctions\Schema as SchemaJonctions;
use Ged\Securite\DroitsDossiers;
use Ged\Securite\Schema as SchemaSecurite;
use Ged\Socle\DepotFichiers;
use Ged\Socle\Fichiers;
use Ged\Socle\Schema as SchemaSocle;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Socle des oracles du lot ged-edition-serveur (F13, côté serveur).
 *
 * Base de TEST uniquement (`gedv2_test`) ; le fonds documentaire et l'espace de secours vivent dans un
 * dossier éphémère hors du dépôt (zéro donnée réelle). Un compte RÉEL (rôle manager : permission
 * `documents:modifier`) et son jeton d'appareil sont posés à chaque test ; les lignes créées portent un
 * marqueur unique retiré au tearDown (jamais de suppression en dehors de ce que ce test a créé).
 */
trait SupportEdition
{
    private PDO $pdo;
    private string $sel;
    private string $racine;
    private string $fonds;
    private string $secours;
    private int $compteId;
    private string $identifiant;
    private string $poste;
    private string $jeton;
    /** Document autorisé (droits lire + modifier). */
    private int $document;
    /** Document hors droits du compte (dossier sans règle : refus par défaut). */
    private int $documentInterdit;
    private string $dossierAutorise;
    private string $contenuV1;

    protected function setUpEdition(): void
    {
        $config = new Config(dirname(__DIR__, 3));
        self::assertSame('gedv2_test', $config->get('DB_NAME'), 'les tests ne touchent que gedv2_test');
        Base::assurerBase($config);
        $this->pdo = Base::pdo($config);
        SchemaSocle::installer($this->pdo);
        SchemaSecurite::installer($this->pdo);
        SchemaJonctions::installer($this->pdo);

        $this->sel = bin2hex(random_bytes(5));
        $this->racine = sys_get_temp_dir() . '/gedv2-edition-' . $this->sel;
        $this->fonds = $this->racine . '/documents';
        $this->secours = $this->racine . '/secours';
        mkdir($this->fonds, 0775, true);
        mkdir($this->secours, 0775, true);
        putenv('STORAGE_ROOT=' . $this->fonds);
        putenv('SECOURS_ROOT=' . $this->secours);

        $this->identifiant = 'edition-' . $this->sel . '@exemple.test';
        $this->poste = 'POSTE-TEST-' . $this->sel;
        $this->pdo->prepare('INSERT INTO comptes (identifiant, empreinte, role) VALUES (?, ?, ?)')
            ->execute([$this->identifiant, password_hash('secret-' . $this->sel, PASSWORD_DEFAULT), 'manager']);
        $this->compteId = (int) $this->pdo->lastInsertId();

        $this->dossierAutorise = 'Edition-' . $this->sel;
        $this->contenuV1 = "contenu-v1-$this->sel";

        $depot = new DepotFichiers($config);
        $this->document = $depot->deposer(
            $this->piece('rapport-edition.docx', $this->contenuV1),
            ['titre' => 'Rapport edition ' . $this->sel, 'type' => 'Rapport', 'date' => '2026-10-01',
                'dossier' => $this->dossierAutorise, 'statut' => 'valide'],
        );
        $this->documentInterdit = $depot->deposer(
            $this->piece('confidentiel.docx', "confidentiel-$this->sel"),
            ['titre' => 'Confidentiel ' . $this->sel, 'type' => 'Rapport', 'date' => '2026-10-01',
                'dossier' => 'Confidentiel-' . $this->sel, 'statut' => 'valide'],
        );

        // Le compte est membre d'un groupe manager, avec lire + déposer + modifier sur le dossier autorisé ;
        // le dossier « Confidentiel » n'a aucune règle : le refus est par défaut.
        $droits = new DroitsDossiers($this->pdo);
        $groupe = $droits->creerGroupe('edition-' . $this->sel, 'manager');
        $droits->ajouterCompteAuGroupe($this->compteId, $groupe);
        $dossierId = $droits->dossierPourDocument($this->document);
        if ($dossierId === null) {
            throw new \RuntimeException('dossier de droits introuvable après dépôt');
        }
        foreach (['lire', 'deposer', 'modifier'] as $niveau) {
            $droits->poserRegle($dossierId, $groupe, $niveau, true);
        }

        $this->jeton = (new Jetons($this->pdo))->creerAppairage('kdocs', $this->poste, $this->compteId, $this->identifiant);
    }

    protected function tearDownEdition(): void
    {
        putenv('STORAGE_ROOT');
        putenv('SECOURS_ROOT');

        $ids = [$this->document, $this->documentInterdit];
        $marques = implode(',', array_fill(0, count($ids), '?'));
        foreach (['document_collaboration', 'document_versions', 'document_deplacements', 'recherche_textes'] as $table) {
            $this->pdo->prepare("DELETE FROM `$table` WHERE document_id IN ($marques)")->execute($ids);
        }
        $this->pdo->prepare("DELETE FROM documents WHERE id IN ($marques)")->execute($ids);
        $this->pdo->prepare('DELETE FROM securite_dossiers_droits WHERE groupe_id IN (SELECT id FROM securite_groupes WHERE nom = ?)')
            ->execute(['edition-' . $this->sel]);
        $this->pdo->prepare('DELETE FROM securite_groupes_comptes WHERE groupe_id IN (SELECT id FROM securite_groupes WHERE nom = ?)')
            ->execute(['edition-' . $this->sel]);
        $this->pdo->prepare('DELETE FROM securite_groupes WHERE nom = ?')->execute(['edition-' . $this->sel]);
        $this->pdo->prepare('DELETE FROM securite_dossiers WHERE chemin_relatif LIKE ? OR chemin_relatif LIKE ?')
            ->execute(['Edition-' . $this->sel . '%', 'Confidentiel-' . $this->sel . '%']);
        $this->pdo->prepare('DELETE FROM jonctions_jetons WHERE compte_identifiant = ?')->execute([$this->identifiant]);
        $this->pdo->prepare('DELETE FROM comptes WHERE identifiant = ?')->execute([$this->identifiant]);

        $this->supprimerDossier($this->racine);
    }

    protected function config(): Config
    {
        return new Config(dirname(__DIR__, 3));
    }

    protected function app(): App
    {
        return \Ged\Kernel::creer(dirname(__DIR__, 3));
    }

    /**
     * Joue une requête de l'API (hors session) et rend [statut, réponse, corps brut].
     *
     * @param array<string, mixed> $corps
     * @param array<string, UploadedFileInterface> $fichiers
     * @return array{int, ResponseInterface, string}
     */
    protected function appel(string $methode, string $chemin, ?string $jeton = null, array $corps = [], array $fichiers = []): array
    {
        $requete = (new ServerRequestFactory())->createServerRequest($methode, $chemin);
        if ($jeton !== null) {
            $requete = $requete->withHeader('Authorization', 'Bearer ' . $jeton);
        }
        if ($corps !== []) {
            $requete = $requete->withParsedBody($corps);
        }
        if ($fichiers !== []) {
            $requete = $requete->withUploadedFiles($fichiers);
        }
        $reponse = $this->app()->handle($requete);

        return [$reponse->getStatusCode(), $reponse, (string) $reponse->getBody()];
    }

    /** Écrit une pièce hors du fonds ; son extension fixe le MIME du document. */
    protected function piece(string $nom, string $contenu): string
    {
        $chemin = $this->racine . '/' . bin2hex(random_bytes(4)) . '-' . $nom;
        file_put_contents($chemin, $contenu);

        return $chemin;
    }

    /** @return array<int, string> empreintes archivées d'un document, indexées par numéro. */
    protected function empreintes(int $document): array
    {
        $requete = $this->pdo->prepare('SELECT numero, empreinte FROM document_versions WHERE document_id = ? ORDER BY numero');
        $requete->execute([$document]);
        $empreintes = [];
        foreach ($requete->fetchAll(PDO::FETCH_ASSOC) as $ligne) {
            $empreintes[(int) $ligne['numero']] = (string) $ligne['empreinte'];
        }

        return $empreintes;
    }

    protected function empreinteContenu(string $contenu): string
    {
        return Fichiers::empreinte($this->piece('calcule.docx', $contenu));
    }

    private function supprimerDossier(string $chemin): void
    {
        if (!is_dir($chemin)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($chemin, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $element) {
            $element->isDir() ? rmdir($element->getPathname()) : unlink($element->getPathname());
        }
        rmdir($chemin);
    }
}
