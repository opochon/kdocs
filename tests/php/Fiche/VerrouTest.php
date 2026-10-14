<?php

declare(strict_types=1);

namespace Ged\Tests\Fiche;

use Ged\Fiche\Verrou;
use PHPUnit\Framework\TestCase;

/**
 * Oracle du STATUT DE VERROUILLAGE / CHECK-OUT — équivalent GEDv2 de
 * `GET|PUT /objects/{type}/{objectid}/{version}/checkedout`. L'état vit dans le `.meta.json` (le dossier
 * fait foi, donc reconstructible) : un compte verrouille un objet en cours d'édition, un AUTRE compte ne
 * peut pas le libérer sans force, un verrou expiré ne bloque plus, et chaque geste est tracé.
 */
final class VerrouTest extends TestCase
{
    use SupportFiche;

    protected function setUp(): void
    {
        $this->setUpCorpus();
    }

    protected function tearDown(): void
    {
        $this->tearDownCorpus();
    }

    public function testPoseLitEtRetireLeVerrouDUneFiche(): void
    {
        $id = $this->deposerFiche('contrat.txt', 'Contrat synthétique', 'contenu');
        $verrou = new Verrou($this->config);

        self::assertFalse($verrou->etat($id)['verrouille'], 'un document neuf n’est pas verrouillé');
        $pose = $verrou->poser($id, 'alice@exemple.test');
        self::assertTrue($pose['verrouille']);
        self::assertSame('alice@exemple.test', $pose['par']);

        $etat = $verrou->etat($id);
        self::assertTrue($etat['verrouille']);
        self::assertSame('alice@exemple.test', $etat['par']);
        self::assertNotSame('', $etat['depuis']);
        // L'état est écrit dans le `.meta.json` à côté du fichier : le dossier fait foi.
        $ligne = $this->pdo->query('SELECT chemin_relatif FROM documents WHERE id = ' . $id)->fetchColumn();
        $meta = json_decode((string) file_get_contents($this->racine . '/fonds/' . (string) $ligne . '.meta.json'), true);
        self::assertSame('alice@exemple.test', $meta['verrou']['par']);

        $verrou->retirer($id, 'alice@exemple.test');
        self::assertFalse($verrou->etat($id)['verrouille']);
        // Le geste est tracé dans l'historique de collaboration.
        $actions = array_column($this->pdo->query("SELECT action FROM document_collaboration WHERE document_id = $id AND type = 'evenement' ORDER BY id")->fetchAll(), 'action');
        self::assertContains('verrou posé', $actions);
        self::assertContains('verrou levé', $actions);
    }

    public function testUnAutreCompteNeRetirePasLeVerrouSansForce(): void
    {
        $id = $this->deposerFiche('contrat.txt', 'Contrat synthétique', 'contenu');
        $verrou = new Verrou($this->config);
        $verrou->poser($id, 'alice@exemple.test');

        try {
            $verrou->retirer($id, 'bob@exemple.test');
            self::fail('un autre compte ne doit pas pouvoir lever le verrou d’autrui');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('alice@exemple.test', $e->getMessage());
        }
        self::assertTrue($verrou->etat($id)['verrouille']);

        // L'administrateur force la libération ; aucun autre compte ne peut poser tant qu'il tient.
        try {
            $verrou->poser($id, 'bob@exemple.test');
            self::fail('un document verrouillé ne se verrouille pas par un autre compte');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('déjà verrouillé', $e->getMessage());
        }

        $verrou->retirer($id, 'admin@exemple.test', true);
        self::assertFalse($verrou->etat($id)['verrouille']);
    }

    public function testUnVerrouExpireNeBloquePlus(): void
    {
        $id = $this->deposerFiche('contrat.txt', 'Contrat synthétique', 'contenu');
        $verrou = new Verrou($this->config);
        $verrou->poser($id, 'alice@exemple.test', 1);

        $ligne = (string) $this->pdo->query('SELECT chemin_relatif FROM documents WHERE id = ' . $id)->fetchColumn();
        $fichier = $this->racine . '/fonds/' . $ligne . '.meta.json';
        $meta = json_decode((string) file_get_contents($fichier), true);
        $meta['verrou']['expire_le'] = '2000-01-01T00:00:00+00:00';
        file_put_contents($fichier, json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        self::assertFalse($verrou->etat($id)['verrouille'], 'un verrou expiré ne tient plus');
        $repose = $verrou->poser($id, 'bob@exemple.test');
        self::assertSame('bob@exemple.test', $repose['par']);
    }
}
