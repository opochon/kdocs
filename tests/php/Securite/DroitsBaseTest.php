<?php

declare(strict_types=1);

namespace Ged\Tests\Securite;

use Ged\Securite\DroitsDossiers;
use Ged\Securite\Authentification;
use Ged\Securite\DroitsRoutes;
use Ged\Securite\GardeCentrale;
use Ged\Securite\JournalAudit;
use Ged\Securite\RegistreSessions;
use Ged\Securite\Schema;
use Ged\Socle\Schema as SchemaSocle;
use Ged\Ui\Vue;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ResponseInterface;

/** Oracle V2-07b : les droits de chaque groupe ne fuient sur aucune surface. */
final class DroitsBaseTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $config = new \Ged\Config(dirname(__DIR__, 3));
        \Ged\Base::assurerBase($config);
        $this->pdo = \Ged\Base::pdo($config);
        SchemaSocle::installer($this->pdo);
        Schema::installer($this->pdo);
    }

    public function testOracleDroitsEstRespecteParToutesLesSurfaces(): void
    {
        $oracle = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/oracles/droits/droits.json'), true, 512, JSON_THROW_ON_ERROR);
        $droits = new DroitsDossiers($this->pdo);
        $suffixe = bin2hex(random_bytes(5));
        $comptes = $this->creerJeu($oracle, $suffixe);

        foreach ($oracle['cas'] as $cas) {
            foreach ($cas['surfaces'] as $surface) {
                self::assertSame(
                    $cas['attendu'],
                    $droits->autorise($comptes[$cas['compte']], $oracle['dossiers'][$cas['dossier']], $cas['action']),
                    sprintf('%s : %s ne doit pas %s le dossier %s', $surface, $cas['compte'], $cas['action'], $cas['dossier']),
                );
            }
        }
    }

    public function testHeritageEtRefusExpliciteSontPlusFortsQueLaPermissionDuRole(): void
    {
        $droits = new DroitsDossiers($this->pdo);
        $suffixe = bin2hex(random_bytes(5));
        $compte = $this->creerCompte('herite-' . $suffixe . '@gedv2.test', 'contributeur', $suffixe);
        $groupe = $droits->creerGroupe('groupe-' . $suffixe, 'contributeur');
        $droits->ajouterCompteAuGroupe($compte, $groupe);
        $racine = 7201;
        $enfant = 7202;
        $droits->poserDossier($racine, null, 'racine-' . $suffixe);
        $droits->poserDossier($enfant, $racine, 'racine-' . $suffixe . '/enfant');
        $droits->poserRegle($racine, $groupe, 'lire', true);
        $droits->poserRegle($racine, $groupe, 'modifier', true);

        self::assertTrue($droits->autorise($compte, $enfant, 'modifier'), 'la règle du parent est héritée');
        $droits->poserRegle($enfant, $groupe, 'modifier', false);
        self::assertFalse($droits->autorise($compte, $enfant, 'modifier'), 'le refus de l’enfant bloque l’héritage');
        self::assertFalse($droits->autorise($compte, 7999, 'lire'), 'un dossier absent est refusé par défaut');
    }

    public function testAuthentificationLitLeCompteHacheEtSesGroupesEnBase(): void
    {
        $suffixe = bin2hex(random_bytes(5));
        $compte = $this->creerCompte('auth-' . $suffixe . '@gedv2.test', 'aucun', $suffixe);
        $droits = new DroitsDossiers($this->pdo);
        $groupe = $droits->creerGroupe('auth-groupe-' . $suffixe, 'lecteur');
        $droits->ajouterCompteAuGroupe($compte, $groupe);
        $config = new \Ged\Config(dirname(__DIR__, 3));

        putenv('APP_ENV=dev');
        try {
            $authentifie = (new Authentification($config, $this->pdo))->verifier('AUTH-' . $suffixe . '@GEDV2.TEST', 'Synthétique-' . $suffixe);
            self::assertSame($compte, $authentifie['id']);
            self::assertContains('documents:lire', $authentifie['permissions']);
            self::assertNull((new Authentification($config, $this->pdo))->verifier('auth-' . $suffixe . '@gedv2.test', 'faux'));
        } finally {
            putenv('APP_ENV=test');
        }
    }

    public function testApercuEstCanoniqueEtControleAuDossierSurToutesSesRoutes(): void
    {
        $suffixe = bin2hex(random_bytes(5));
        $droits = new DroitsDossiers($this->pdo);
        $compte = $this->creerCompte('apercevoir-' . $suffixe . '@gedv2.test', 'aucun', $suffixe);
        $groupe = $droits->creerGroupe('apercevoir-groupe-' . $suffixe, 'lecteur');
        $droits->ajouterCompteAuGroupe($compte, $groupe);
        $dossier = 7301;
        $chemin = 'acl-' . $suffixe;
        $droits->poserDossier($dossier, null, $chemin);
        $droits->poserRegle($dossier, $groupe, 'lire', true);
        $this->pdo->prepare('INSERT INTO documents (chemin_relatif, nom, titre, empreinte, taille, mime, meta) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$chemin . '/visible.pdf', 'visible.pdf', 'Visible', str_repeat('a', 64), 1, 'application/pdf', '{}']);
        $document = (int) $this->pdo->lastInsertId();
        $repertoire = sys_get_temp_dir() . '/gedv2-garde-' . $suffixe;
        $sessions = new RegistreSessions($repertoire . '/sessions');
        $session = $sessions->ouvrir('apercevoir-' . $suffixe, $droits->permissionsPourCompte($compte), null, $compte);
        $garde = new GardeCentrale($sessions, new JournalAudit($repertoire . '/audit.ndjson'), new Vue(dirname(__DIR__, 3) . '/templates'), $droits);
        $requete = (new ServerRequestFactory())->createServerRequest('GET', '/documents/' . $document . '/apercu')
            ->withCookieParams(['gedv2_session' => $session['id']])
            ->withHeader('X-GED-Teste-Garde', '1');
        $gestionnaire = new class implements RequestHandlerInterface {
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): ResponseInterface
            {
                return new \Slim\Psr7\Response(200);
            }
        };

        self::assertSame('document.voir', DroitsRoutes::permissionPour('GET', '/documents/' . $document . '/apercu'));
        self::assertSame('documents:lire', DroitsRoutes::permissionCanonique('document.voir'));
        self::assertSame(200, $garde->process($requete, $gestionnaire)->getStatusCode(), 'l’aperçu autorisé atteint le gestionnaire');
        self::assertSame(403, $garde->process($requete->withUri($requete->getUri()->withPath('/documents/999999/apercu')), $gestionnaire)->getStatusCode(), 'un document hors ACL ne fuit pas');
    }

    /** @param array<string, mixed> $oracle @return array<string, int> */
    private function creerJeu(array $oracle, string $suffixe): array
    {
        $droits = new DroitsDossiers($this->pdo);
        $roles = ['lecteur', 'contributeur', 'archiveur'];
        $groupes = [];
        foreach ($roles as $role) {
            $groupes[$role] = $droits->creerGroupe($role . '-' . $suffixe, $role);
        }
        $comptes = [];
        foreach ($oracle['comptes'] as $role => $identifiant) {
            $comptes[$role] = $this->creerCompte($suffixe . '-' . $identifiant, 'aucun', $suffixe);
            if (isset($groupes[$role])) {
                $droits->ajouterCompteAuGroupe($comptes[$role], $groupes[$role]);
            }
        }
        $droits->poserDossier($oracle['dossiers']['racine'], null, 'oracle-' . $suffixe);
        $droits->poserDossier($oracle['dossiers']['confidentiel'], $oracle['dossiers']['racine'], 'oracle-' . $suffixe . '/confidentiel');
        $droits->poserDossier($oracle['dossiers']['public'], $oracle['dossiers']['racine'], 'oracle-' . $suffixe . '/public');
        $droits->poserRegle($oracle['dossiers']['racine'], $groupes['lecteur'], 'lire', true);
        $droits->poserRegle($oracle['dossiers']['racine'], $groupes['contributeur'], 'lire', true);
        $droits->poserRegle($oracle['dossiers']['racine'], $groupes['contributeur'], 'modifier', true);
        $droits->poserRegle($oracle['dossiers']['public'], $groupes['archiveur'], 'lire', true);
        $droits->poserRegle($oracle['dossiers']['public'], $groupes['archiveur'], 'archiver', true);

        return $comptes;
    }

    private function creerCompte(string $identifiant, string $role, string $suffixe): int
    {
        $this->pdo->prepare('INSERT INTO comptes (identifiant, empreinte, role) VALUES (?, ?, ?)')
            ->execute([$identifiant, password_hash('Synthétique-' . $suffixe, PASSWORD_DEFAULT), $role]);

        return (int) $this->pdo->lastInsertId();
    }
}
