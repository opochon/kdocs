<?php

declare(strict_types=1);

namespace Ged\Tests\Ingestion\Courrier;

use Ged\Base;
use Ged\Config;
use Ged\Kernel;
use Ged\Securite\DroitsRoutes;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * L'écran d'administration du courrier : chaque route déclare `application:administrer`, l'administrateur
 * crée un compte (jamais de secret en clair), teste la connexion sur le FAUX en mémoire, crée une règle,
 * traite la boîte et lit le journal des courriels traités.
 */
final class ModuleTest extends TestCase
{
    private Config $config;
    private string $donnees;
    private string $storageAvant;

    protected function setUp(): void
    {
        $this->config = new Config(dirname(__DIR__, 4));
        self::assertSame('gedv2_test', $this->config->get('DB_NAME'), 'les tests ne touchent que gedv2_test');
        Base::assurerBase($this->config);
        $pdo = Base::pdo($this->config);
        \Ged\Socle\Schema::installer($pdo);
        \Ged\Ingestion\Schema::installer($pdo);
        $pdo->exec("DELETE FROM ingestion_messages WHERE message_id LIKE 'courrier:%'");
        $this->nettoyerDocuments($pdo);

        $this->donnees = sys_get_temp_dir() . '/gedv2-courrier-ecran-' . bin2hex(random_bytes(4));
        mkdir($this->donnees, 0775, true);
        $this->storageAvant = (string) getenv('STORAGE_ROOT');
        putenv('COURRIER_DONNEES=' . $this->donnees);
        putenv('STORAGE_ROOT=' . $this->donnees . '/fonds');
    }

    protected function tearDown(): void
    {
        $pdo = Base::pdo($this->config);
        $pdo->exec("DELETE FROM ingestion_messages WHERE message_id LIKE 'courrier:%'");
        $this->nettoyerDocuments($pdo);
        $this->storageAvant === '' ? putenv('STORAGE_ROOT') : putenv('STORAGE_ROOT=' . $this->storageAvant);
        putenv('COURRIER_DONNEES');
        $this->supprimer($this->donnees);
    }

    /** Retire les documents déposés par CE test (titre ou compte de l'écran), pour ne pas polluer les rejeux. */
    private function nettoyerDocuments(\PDO $pdo): void
    {
        $ids = $pdo->query("SELECT id FROM documents WHERE meta LIKE '%Boîte écran%' OR meta LIKE '%Invoice 7%'")
            ->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            foreach (['document_versions', 'document_deplacements', 'recherche_textes'] as $table) {
                // `$table` vient d'une liste interne figée : aucune entrée utilisateur dans le SQL.
                $pdo->prepare("DELETE FROM $table WHERE document_id = ?")->execute([(int) $id]);
            }
        }
        $pdo->exec("DELETE FROM documents WHERE meta LIKE '%Boîte écran%' OR meta LIKE '%Invoice 7%'");
    }

    public function testChaqueRouteCourrierDeclareSaPermission(): void
    {
        $routes = [
            ['GET', '/courrier'],
            ['POST', '/courrier/comptes'],
            ['GET', '/courrier/comptes/12'],
            ['POST', '/courrier/comptes/12'],
            ['POST', '/courrier/comptes/12/basculer'],
            ['POST', '/courrier/comptes/12/tester'],
            ['POST', '/courrier/comptes/12/traiter'],
            ['POST', '/courrier/regles'],
            ['GET', '/courrier/regles/12'],
            ['POST', '/courrier/regles/12'],
            ['POST', '/courrier/regles/12/basculer'],
            ['POST', '/courrier/journal/purger'],
        ];
        foreach ($routes as [$methode, $chemin]) {
            self::assertSame('application:administrer', DroitsRoutes::permissionPour($methode, $chemin), "$methode $chemin");
        }
        // Patron littéral enregistré par Slim.
        self::assertSame('application:administrer', DroitsRoutes::permissionPour('GET', '/courrier/comptes/{id:[0-9]+}'));
        self::assertSame('application:administrer', DroitsRoutes::permissionPour('POST', '/courrier/regles/{id:[0-9]+}/basculer'));
    }

    public function testLAdministrateurCreeUnCompteLeTesteCreeUneRegleEtTraiteLeCourrier(): void
    {
        [$code, $corps, $location] = $this->requete('GET', '/courrier');
        self::assertSame(200, $code);
        self::assertStringContainsString('ecran-courrier', $corps);

        [$code, , $location] = $this->requete('POST', '/courrier/comptes', [
            'nom' => 'Boîte écran', 'serveur' => 'imap.faux.test', 'port' => 993, 'securite' => 2,
            'utilisateur' => 'ecran@exemple.test', 'mot_de_passe' => 'secret-de-test', 'actif' => '1',
        ]);
        self::assertSame(303, $code, $corps);
        self::assertSame('/courrier', $location);

        // Le mot de passe n'est jamais écrit en clair, ni rendu par l'écran.
        $fichier = (string) file_get_contents($this->donnees . '/comptes.json');
        self::assertStringNotContainsString('secret-de-test', $fichier);
        [, $liste, ] = $this->requete('GET', '/courrier');
        self::assertStringNotContainsString('secret-de-test', $liste);
        self::assertStringContainsString('**********', $liste);

        // Test de connexion sur le FAUX en mémoire (aucun réseau).
        [$code, $corps, ] = $this->requete('POST', '/courrier/comptes/1/tester', []);
        self::assertSame(200, $code, $corps);
        self::assertStringContainsString('courrier-essai-ok', $corps);

        // Création d'une règle : le compte reçoit ses pièces en .pdf et déplace les courriels « Invoice ».
        [$code, $corps, ] = $this->requete('POST', '/courrier/regles', [
            'nom' => 'Factures', 'compte' => 'Boîte écran', 'dossier' => 'INBOX',
            'filtre_objet' => 'Invoice', 'filtre_fichiers_inclure' => '*.pdf', 'portee' => 1,
            'action' => 2, 'parametre_action' => 'Factures', 'titre_depuis' => 1, 'emetteur_depuis' => 2, 'actif' => '1',
        ]);
        self::assertSame(303, $code, $corps);

        // La boîte de test contient un courriel « Invoice » avec une pièce .pdf.
        file_put_contents($this->donnees . '/boite-fausse.json', (string) json_encode([
            'messages' => [[
                'uid' => 1, 'sujet' => 'Invoice 7', 'de' => 'fournisseur@exemple.test', 'de_nom' => 'Fournisseur',
                'a' => ['ged@exemple.test'], 'corps' => 'ci-joint la facture', 'vu' => false, 'signale' => false,
                'pieces' => [['nom' => 'facture-7.pdf', 'type' => 'application/pdf', 'disposition' => 'attachment', 'contenu' => '%PDF-1.4']],
            ]],
        ], JSON_THROW_ON_ERROR));

        [$code, $corps, ] = $this->requete('POST', '/courrier/comptes/1/traiter', []);
        self::assertSame(200, $code, $corps);
        self::assertStringContainsString('courrier-rapport-traites', $corps);
        self::assertStringContainsString('1 courriel(s) traité(s)', $corps);
        self::assertStringContainsString('Invoice 7', $corps, 'le journal montre le courriel traité');

        // Un second passage ne retraite pas le même courriel (idempotence).
        [$code, $corps, ] = $this->requete('POST', '/courrier/comptes/1/traiter', []);
        self::assertSame(200, $code);
        self::assertStringContainsString('0 courriel(s) traité(s)', $corps);
    }

    /** @param array<string, mixed> $corps @return array{int, string, string} */
    private function requete(string $methode, string $chemin, array $corps = []): array
    {
        $requete = (new ServerRequestFactory())->createServerRequest($methode, $chemin);
        if ($corps !== []) {
            $requete = $requete->withParsedBody($corps);
        }
        $reponse = Kernel::creer(dirname(__DIR__, 4))->handle($requete);

        return [$reponse->getStatusCode(), (string) $reponse->getBody(), $reponse->getHeaderLine('Location')];
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
