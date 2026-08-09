<?php

declare(strict_types=1);

/**
 * Les permissions de dossiers sont-elles verifiees COTE SERVEUR ?
 *
 * Tests\Unit\FolderPermissionTest prouve que FolderPermissionService::can()
 * decide correctement. Il ne prouve pas que quelqu'un l'appelle. Constat du
 * 2026-08-07 : le service etait ecrit, correct, couvert par 10 tests verts —
 * et reference par AUCUNE ligne de code applicatif. Un document interdit
 * restait servi par le controleur pendant que tous les voyants etaient verts.
 * C'est l'etat FANTOME du registre des secteurs.
 *
 * Cet oracle epingle le CABLAGE : que le garde est present sur chaque methode
 * du controleur qui sert ou modifie un document, et qu'il decide bien.
 * Il tombe si quelqu'un retire l'appel — c'est tout son objet.
 *
 * @see governance/sectors.json  secteur securite-acl
 * @see AGENTS.md  regle 2 : l'oracle prouve le cablage, pas la coherence du code
 */

namespace Tests\Feature;

use KDocs\Services\FolderPermissionService;
use PHPUnit\Framework\TestCase;

class FolderPermissionServerSideTest extends TestCase
{
    private const CONTROLEUR = __DIR__ . '/../../app/Controllers/Api/DocumentsApiController.php';

    /**
     * Methodes qui servent ou modifient le contenu d'un document, et doivent
     * donc garder. Lecture : show, content, download. Ecriture : update, delete.
     * Cette liste ne descend jamais : retirer une methode d'ici, c'est retirer
     * une protection.
     */
    private const METHODES_GARDEES = ['show', 'content', 'download', 'update', 'delete'];

    private function source(): string
    {
        $src = file_get_contents(self::CONTROLEUR);
        $this->assertIsString($src, 'DocumentsApiController illisible');
        return $src;
    }

    /**
     * Extrait le corps d'une methode publique, du prototype a la methode suivante.
     */
    private function corpsDeMethode(string $src, string $methode): ?string
    {
        $debut = strpos($src, "function {$methode}(");
        if ($debut === false) {
            return null;
        }
        $suivante = preg_match(
            '/\n    (?:public|protected|private)\s+function\s+/',
            $src,
            $m,
            PREG_OFFSET_CAPTURE,
            $debut + 10
        ) ? $m[0][1] : strlen($src);

        return substr($src, $debut, $suivante - $debut);
    }

    /**
     * Le controleur doit connaitre le service. Sans cela, aucune des methodes
     * ne peut garder quoi que ce soit.
     */
    public function testLeControleurReferenceLeServiceDePermissions(): void
    {
        $this->assertStringContainsString(
            'FolderPermissionService',
            $this->source(),
            "DocumentsApiController ne reference pas FolderPermissionService. "
            . "Les permissions de dossiers ne sont pas verifiees cote serveur : "
            . "le service existe et il est teste, mais personne ne l'appelle."
        );
    }

    /**
     * Chaque methode qui sert ou modifie un document consulte le garde.
     * @dataProvider methodesGardees
     */
    public function testChaqueMethodeSensibleConsulteLeGarde(string $methode): void
    {
        $corps = $this->corpsDeMethode($this->source(), $methode);
        $this->assertNotNull($corps, "Methode {$methode}() introuvable dans le controleur");

        $this->assertMatchesRegularExpression(
            '/folderPermission|FolderPermissionService|peutAccederAuDocument/i',
            $corps,
            "DocumentsApiController::{$methode}() ne consulte aucun garde de permission. "
            . "Un document interdit y est servi ou modifie sans controle."
        );
    }

    /** @return array<string,array{string}> */
    public static function methodesGardees(): array
    {
        $cas = [];
        foreach (self::METHODES_GARDEES as $m) {
            $cas[$m] = [$m];
        }
        return $cas;
    }

    /**
     * Le garde doit refuser pour de vrai. On verifie le comportement sur une
     * base en memoire : une interdiction explicite sur le dossier du document
     * doit fermer l'acces a l'utilisateur vise, et rester sans effet sur admin.
     */
    public function testLeGardeRefuseReellementUnDocumentInterdit(): void
    {
        $db = new \PDO('sqlite::memory:');
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $db->exec('CREATE TABLE logical_folders (id INTEGER PRIMARY KEY, parent_id INTEGER)');
        $db->exec('CREATE TABLE user_group_memberships (user_id INTEGER, group_id INTEGER)');
        // Schema aligne sur database/migrations/add_folder_permissions_table.php :
        // le sujet est porte par subject_type + subject_id, pas par user_id/group_id.
        $db->exec('CREATE TABLE folder_permissions (
            id INTEGER PRIMARY KEY, folder_id INTEGER, subject_type TEXT, subject_id INTEGER,
            can_read INTEGER, can_write INTEGER, can_delete INTEGER
        )');

        $db->exec('INSERT INTO logical_folders (id, parent_id) VALUES (7, NULL)');
        $db->exec("INSERT INTO folder_permissions (folder_id, subject_type, subject_id, can_read, can_write, can_delete)
                   VALUES (7, 'user', 42, 0, 0, 0)");

        $service = new FolderPermissionService($db);
        $doc     = ['id' => 100, 'folder_id' => 7];

        $this->assertFalse(
            $service->can(['id' => 42, 'role' => 'user'], $doc, 'read'),
            'un utilisateur explicitement interdit ne doit pas pouvoir lire'
        );

        $this->assertTrue(
            $service->can(['id' => 42, 'role' => 'admin'], $doc, 'read'),
            'admin conserve son acces'
        );

        $this->assertTrue(
            $service->can(['id' => 99, 'role' => 'user'], $doc, 'read'),
            'un utilisateur sans regle reste ouvert (comportement legacy assume)'
        );
    }
}
