<?php

declare(strict_types=1);

namespace Ged\Tests\Securite;

use Ged\Base;
use Ged\Config;
use Ged\Securite\DroitsDossiers;
use Ged\Securite\GardeCentrale;
use Ged\Securite\JournalAudit;
use Ged\Securite\RegistreSessions;
use Ged\Securite\Schema;
use Ged\Socle\DepotFichiers;
use Ged\Socle\Schema as SchemaSocle;
use Ged\Ui\Vue;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Oracle R1-droits (défaut 1) : un document RANGÉ est rattaché à son dossier de droits, créé à la
 * volée depuis son chemin dans le fonds (héritage du parent). Sans ce rattachement, la garde refuse
 * l'aperçu d'un document ingéré à TOUT compte réel, même administrateur.
 *
 * Le second défaut couvert ici : l'installateur propose un identifiant qui n'est pas une adresse de
 * courriel, alors que l'écran de connexion l'exige — les deux doivent être cohérents.
 */
final class RattachementDroitsTest extends TestCase
{
    private Config $config;
    private PDO $pdo;
    private string $bac;
    private string $storageAvant;

    protected function setUp(): void
    {
        $this->config = new Config(dirname(__DIR__, 3));
        self::assertSame('gedv2_test', $this->config->get('DB_NAME'), 'les tests ne touchent que gedv2_test');
        Base::assurerBase($this->config);
        $this->pdo = Base::pdo($this->config);
        SchemaSocle::installer($this->pdo);
        Schema::installer($this->pdo);

        $this->storageAvant = (string) getenv('STORAGE_ROOT');
        $this->bac = sys_get_temp_dir() . '/gedv2-r1-' . bin2hex(random_bytes(4));
        mkdir($this->bac . '/depots', 0775, true);
        putenv('STORAGE_ROOT=' . $this->bac . '/fonds');
    }

    protected function tearDown(): void
    {
        $this->storageAvant === '' ? putenv('STORAGE_ROOT') : putenv('STORAGE_ROOT=' . $this->storageAvant);
        $this->supprimerDossier($this->bac);
    }

    public function testUnDocumentRangeEstRattacheASonDossierDeDroitsEtHeriteDuParent(): void
    {
        $suffixe = bin2hex(random_bytes(5));
        $id = $this->deposer($suffixe);
        $chemin = (string) $this->pdo->query('SELECT chemin_relatif FROM documents WHERE id = ' . $id)->fetchColumn();
        $repertoire = '2026/Facture/Émetteur_' . $suffixe;
        self::assertStringStartsWith($repertoire . '/', $chemin, 'le dépôt range sous année/type/émetteur');

        $droits = new DroitsDossiers($this->pdo);
        self::assertNotNull($droits->dossierPourDocument($id), 'le document rangé a un dossier ACL : la garde peut décider');

        // La chaîne des dossiers suit le chemin, chaque niveau héritant de son parent.
        $an = $this->ligneDossier('2026');
        $type = $this->ligneDossier('2026/Facture');
        $emetteur = $this->ligneDossier($repertoire);
        self::assertNull($an['parent_id'], 'la racine du fonds n’a pas de parent');
        self::assertSame((int) $an['id'], (int) $type['parent_id'], 'le type hérite de l’année');
        self::assertSame((int) $type['id'], (int) $emetteur['parent_id'], 'l’émetteur hérite du type');

        // Rejouer le rattachement est idempotent : aucun doublon.
        $droits->assurerDossierPourChemin($chemin);
        $combien = (int) $this->pdo->query("SELECT COUNT(*) FROM securite_dossiers WHERE chemin_relatif IN ('2026', '2026/Facture', '" . $repertoire . "')")->fetchColumn();
        self::assertSame(3, $combien, 'un seul dossier par niveau de chemin');
    }

    public function testUnCompteAdminReelOuvreLApercuDUnDocumentRange(): void
    {
        $suffixe = bin2hex(random_bytes(5));
        $id = $this->deposer($suffixe);
        $compte = $this->creerCompte('admin-' . $suffixe . '@gedv2.test', 'admin');
        $session = $this->session($compte, 'admin-' . $suffixe . '@gedv2.test');

        self::assertSame(200, $this->garde()->process($this->requete($id, $session['id']), $this->gestionnaire())->getStatusCode(),
            'un administrateur réel ouvre l’aperçu d’un document ingéré');
    }

    public function testUnCompteReelSansRegleDeDossierResteRefuse(): void
    {
        $suffixe = bin2hex(random_bytes(5));
        $id = $this->deposer($suffixe);
        $compte = $this->creerCompte('lecteur-' . $suffixe . '@gedv2.test', 'lecteur');   // documents:lire, mais aucun groupe
        $session = $this->session($compte, 'lecteur-' . $suffixe . '@gedv2.test');

        self::assertSame(403, $this->garde()->process($this->requete($id, $session['id']), $this->gestionnaire())->getStatusCode(),
            'F10 : document rangé mais aucune règle de dossier → refus');
    }

    public function testLInstallateurExigeUneAdresseDeCourrielCommeIdentifiant(): void
    {
        $commande = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 3) . '/tools/installer.php')
            . ' gedv2_test --admin=admin 2>&1';
        exec($commande, $sorties, $code);

        self::assertNotSame(0, $code, 'un identifiant qui n’est pas un courriel est refusé');
        self::assertStringContainsString('courriel', strtolower(implode("\n", $sorties)), 'l’erreur se nomme');
    }

    private function deposer(string $suffixe): int
    {
        $source = $this->bac . '/depots/facture-' . $suffixe . '.pdf';
        file_put_contents($source, "%PDF-1.4\nfacture synthétique $suffixe\n%%EOF\n");

        return (new DepotFichiers($this->config))->deposer($source, [
            'titre' => 'Facture de test ' . $suffixe,
            'type' => 'Facture',
            'emetteur' => 'Émetteur ' . $suffixe,
            'date' => '2026-03-01',
        ]);
    }

    /** @return array{id:int|string, parent_id:int|string|null, chemin_relatif:string} */
    private function ligneDossier(string $chemin): array
    {
        $stmt = $this->pdo->prepare('SELECT id, parent_id, chemin_relatif FROM securite_dossiers WHERE chemin_relatif = ?');
        $stmt->execute([$chemin]);
        $ligne = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($ligne, "dossier ACL attendu : $chemin");

        return $ligne;
    }

    private function creerCompte(string $identifiant, string $role): int
    {
        $this->pdo->prepare('INSERT INTO comptes (identifiant, empreinte, role) VALUES (?, ?, ?)')
            ->execute([$identifiant, password_hash('Synthétique-R1', PASSWORD_DEFAULT), $role]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array{id:string} */
    private function session(int $compte, string $identifiant): array
    {
        $repertoire = $this->bac . '/securite';
        mkdir($repertoire, 0775, true);

        return (new RegistreSessions($repertoire . '/sessions'))
            ->ouvrir($identifiant, (new DroitsDossiers($this->pdo))->permissionsPourCompte($compte), null, $compte);
    }

    private function garde(): GardeCentrale
    {
        $repertoire = $this->bac . '/securite';

        return new GardeCentrale(new RegistreSessions($repertoire . '/sessions'),
            new JournalAudit($repertoire . '/audit.ndjson'), new Vue(dirname(__DIR__, 3) . '/templates'),
            new DroitsDossiers($this->pdo));
    }

    private function requete(int $document, string $session): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', '/documents/' . $document . '/apercu')
            ->withCookieParams(['gedv2_session' => $session])
            ->withHeader('X-GED-Teste-Garde', '1');
    }

    private function gestionnaire(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): ResponseInterface
            {
                return new \Slim\Psr7\Response(200);
            }
        };
    }

    private function supprimerDossier(string $chemin): void
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
