<?php

declare(strict_types=1);

namespace Ged\Tests\Archivage;

use Ged\Archivage\ErreurScellement;
use Ged\Archivage\Scellement;
use Ged\Socle\DepotFichiers;
use Ged\Workflows\Moteur;
use Ged\Workflows\Regles;
use PHPUnit\Framework\TestCase;

/**
 * Oracle de la garde (clause 2 du lot V2-09) : toute mutation d'un document scellé est refusée AU NIVEAU DU
 * SERVICE — mise à jour, corbeille, règle de workflow — et l'indexeur qui constate un fichier modifié sur
 * disque lève une alerte « scellé altéré » sans jamais créer de nouvelle version silencieuse.
 */
final class GardeTest extends TestCase
{
    use SupportArchivage;

    protected function setUp(): void
    {
        $this->setUpArchivage();
    }

    protected function tearDown(): void
    {
        $this->tearDownArchivage();
    }

    private function scellerFacture(string $nom = 'facture-garde.pdf', string $date = '2026-03-01'): array
    {
        $id = $this->deposer($nom, ['titre' => 'Facture garde ' . $nom, 'type' => 'facture', 'date' => $date]);
        $this->scelleur()->sceller($id, 'olivia');

        return [$id, Scellement::lire($this->absolu($id), $id)];
    }

    public function testMiseAJourRefuseeEtNommee(): void
    {
        [$id] = $this->scellerFacture();
        $metaAvant = $this->fiche($id)['meta'];

        try {
            $this->depot()->mettreAJour($id, ['titre' => 'Titre piraté', 'type' => 'contrat'], 'olivia');
            self::fail('une mise à jour d’un document scellé doit être refusée');
        } catch (ErreurScellement $e) {
            self::assertStringContainsString('mise à jour', $e->getMessage(), 'l’étape doit être nommée');
            self::assertStringContainsString('scellé', $e->getMessage());
            self::assertStringContainsString('2036-03-01', $e->getMessage(), 'l’échéance doit être nommée');
            self::assertSame(403, $e->getCode());
        }

        self::assertSame($metaAvant, $this->fiche($id)['meta'], 'aucune écriture ne doit avoir eu lieu');
    }

    public function testCorbeilleRefuseeEtNommee(): void
    {
        [$id] = $this->scellerFacture('facture-corbeille.pdf');

        try {
            $this->depot()->miseALaCorbeille($id, 'olivia');
            self::fail('la corbeille d’un document scellé doit être refusée');
        } catch (ErreurScellement $e) {
            self::assertStringContainsString('corbeille', $e->getMessage());
        }

        self::assertFileExists($this->absolu($id), 'le fichier reste à sa place');
        self::assertNull($this->fiche($id)['supprime_le']);
    }

    public function testWorkflowNeModifieJamaisUnDocumentScelle(): void
    {
        $id = $this->deposer('facture-workflow.pdf', ['titre' => 'Facture workflow', 'type' => 'facture', 'date' => '2026-03-01']);
        $this->scelleur()->sceller($id, 'olivia');
        $metaAvant = $this->fiche($id)['meta'];

        $regles = new Regles($this->pdo);
        $regles->creer([
            'nom' => 'archivage-garde-' . bin2hex(random_bytes(4)),
            'declencheur' => 'document_modifie',
            'conditions' => [['champ' => 'emetteur', 'operateur' => 'contient', 'valeur' => (string) $metaAvant['emetteur']]],
            'actions' => [['type' => 'poser', 'champ' => 'titre', 'valeur' => 'Titre écrit par la règle']],
            'actif' => true,
        ]);

        $moteur = new Moteur(\Ged\Base::pdo($this->config), new DepotFichiers($this->config));
        $plan = $moteur->executer('document_modifie', $this->fiche($id), 'workflow');

        self::assertNotSame([], $plan['erreurs'], 'la garde doit remonter l’erreur, jamais écrire en silence');
        self::assertSame($metaAvant, $this->fiche($id)['meta'], 'le workflow n’a rien pu écrire');
    }

    public function testIndexeurAlerteSansNouvelleVersionSiLeFichierChangeSurDisque(): void
    {
        [$id, $scellement] = $this->scellerFacture('facture-alteree.pdf');
        $absolu = $this->absolu($id);
        $versionsAvant = glob(dirname($absolu) . '/.versions/' . basename($absolu) . '/*') ?: [];

        // Un octet change hors application (explorateur, logiciel tiers).
        file_put_contents($absolu, (string) file_get_contents($absolu) . 'X');
        $this->indexer();
        $stats = $this->indexer();

        self::assertSame(1, $stats['scelles_alteres'], 'l’indexeur doit compter une alerte « scellé altéré »');
        self::assertSame(0, $stats['versions'], 'aucune nouvelle version ne doit être créée');
        $versionsApres = glob(dirname($absolu) . '/.versions/' . basename($absolu) . '/*') ?: [];
        self::assertSame(count($versionsAvant), count($versionsApres), 'le dossier .versions/ ne bouge pas');
        self::assertSame($scellement['empreinte_fichier'], $this->fiche($id)['empreinte'], 'l’index ne suit pas une altération');

        $alertes = dirname($absolu) . '/.scellement/alertes.ndjson';
        self::assertFileExists($alertes, 'l’alerte doit être écrite dans le dossier');
        self::assertStringContainsString('scellé altéré', (string) file_get_contents($alertes));
    }

    public function testUnDocumentNonScelleResteMutable(): void
    {
        $id = $this->deposer('facture-libre.pdf', ['titre' => 'Facture libre', 'type' => 'facture', 'date' => '2026-03-01']);

        $this->depot()->mettreAJour($id, ['titre' => 'Facture libre corrigée'], 'olivia');

        self::assertSame('Facture libre corrigée', $this->fiche($id)['meta']['titre'] ?? null);
    }
}
