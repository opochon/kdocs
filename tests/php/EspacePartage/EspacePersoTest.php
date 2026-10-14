<?php

declare(strict_types=1);

namespace Ged\Tests\EspacePartage;

use Ged\EspacePartage\EspacePerso;
use Ged\Securite\DroitsDossiers;
use Ged\Socle\DepotFichiers;
use PHPUnit\Framework\TestCase;

/**
 * Oracle de l'espace personnel (lot ged-mfiles-espace-1) : favoris ET récemment consultés. Un favori ou une
 * consultation est une donnée d'INDEX rangée dans le registre portable du fonds (`.ged/`), jamais une copie
 * du document ; la liste ne révèle jamais un document hors des droits du compte qui la lit.
 */
final class EspacePersoTest extends TestCase
{
    use SupportEspace;

    protected function setUp(): void
    {
        $this->setUpEspace();
    }

    protected function tearDown(): void
    {
        $this->tearDownEspace();
    }

    public function testAjouterUnFavoriLeListeAvecSonTitreSansCopierLeDocument(): void
    {
        $premier = $this->deposer('Facture synthétique');
        $this->deposer('Contrat synthétique');
        $espace = new EspacePerso($this->config);

        self::assertSame([], $espace->favoris('alice@exemple.test'), 'aucun favori au départ');
        $espace->ajouterFavori('alice@exemple.test', $premier);

        self::assertTrue($espace->estFavori('alice@exemple.test', $premier));
        $favoris = $espace->favoris('alice@exemple.test');
        self::assertSame([$premier], array_column($favoris, 'document'));
        self::assertSame(['Facture synthétique'], array_column($favoris, 'titre'));

        // Le favori est une donnée d'index du fonds, pas une copie : le registre est dans `.ged/`.
        self::assertFileExists($this->racine . '/fonds/.ged/favoris.json');
        $etat = json_decode((string) file_get_contents($this->racine . '/fonds/.ged/favoris.json'), true);
        self::assertSame($premier, $etat['alice@exemple.test'][0]['document']);
        // Aucun déplacement ni nouvelle version : le dossier des documents n'a pas bougé.
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM document_deplacements')->fetchColumn());
    }

    public function testUnFavoriEstIdempotentEtSeRetireSansEffacerLaTrace(): void
    {
        $id = $this->deposer('Pièce à épingler');
        $espace = new EspacePerso($this->config);
        $espace->ajouterFavori('alice@exemple.test', $id);
        $espace->ajouterFavori('alice@exemple.test', $id);

        self::assertCount(1, $espace->favoris('alice@exemple.test'), 'un favori n’est jamais dupliqué');

        $espace->retirerFavori('alice@exemple.test', $id);
        self::assertFalse($espace->estFavori('alice@exemple.test', $id));
        self::assertSame([], $espace->favoris('alice@exemple.test'));

        // Retrait = marqueur daté, jamais une suppression : la trace reste dans le registre.
        $etat = json_decode((string) file_get_contents($this->racine . '/fonds/.ged/favoris.json'), true);
        self::assertNotNull($etat['alice@exemple.test'][0]['retire_le'] ?? null);
        // Retirer un favori déjà retiré ne lève pas : le geste est idempotent.
        $espace->retirerFavori('alice@exemple.test', $id);
    }

    public function testUnFavoriEstPropreAUnUtilisateur(): void
    {
        $id = $this->deposer('Document partagé');
        $espace = new EspacePerso($this->config);
        $espace->ajouterFavori('alice@exemple.test', $id);

        self::assertTrue($espace->estFavori('alice@exemple.test', $id));
        self::assertFalse($espace->estFavori('bob@exemple.test', $id));
        self::assertSame([], $espace->favoris('bob@exemple.test'));
    }

    public function testUnDocumentInconnuEstRefuseParUnMessageNomme(): void
    {
        $espace = new EspacePerso($this->config);
        $this->expectExceptionMessage('document introuvable');
        $espace->ajouterFavori('alice@exemple.test', 999999);
    }

    public function testLaListeDesFavorisNeRevelePasUnDocumentHorsDroits(): void
    {
        $id = $this->deposer('Pièce réservée');
        $espace = new EspacePerso($this->config);
        $espace->ajouterFavori('alice@exemple.test', $id);

        $compte = $this->compte('alice@exemple.test');
        self::assertSame([], $espace->favoris('alice@exemple.test', $compte), 'sans droit de lecture, rien ne fuit');

        $droits = new DroitsDossiers($this->pdo);
        $groupe = $droits->creerGroupe('Espace ' . bin2hex(random_bytes(3)), 'lecteur');
        $droits->ajouterCompteAuGroupe($compte, $groupe);
        $dossier = $droits->dossierPourDocument($id);
        self::assertNotNull($dossier);
        $droits->poserRegle($dossier, $groupe, 'lire', true);

        self::assertSame([$id], array_column($espace->favoris('alice@exemple.test', $compte), 'document'));
    }

    public function testUneConsultationEstEnregistreeSansDoublonDansLOrdreDuPlusRecent(): void
    {
        $premier = $this->deposer('Premier consulté');
        $second = $this->deposer('Second consulté');
        $espace = new EspacePerso($this->config);

        $espace->enregistrerConsultation('alice@exemple.test', $premier);
        $espace->enregistrerConsultation('alice@exemple.test', $second);
        $espace->enregistrerConsultation('alice@exemple.test', $premier);

        $recents = $espace->recents('alice@exemple.test');
        self::assertSame([$premier, $second], array_column($recents, 'document'), 'le plus récent d’abord, sans doublon');
        self::assertSame(['Premier consulté', 'Second consulté'], array_column($recents, 'titre'));
        self::assertFileExists($this->racine . '/fonds/.ged/recents.json');
    }

    public function testLesRecentsSontBornesEtFiltresParLesDroits(): void
    {
        $visible = $this->deposer('Visible', 'espace/visible');
        $cache = $this->deposer('Caché', 'espace/cache');
        $espace = new EspacePerso($this->config);
        $espace->enregistrerConsultation('alice@exemple.test', $visible);
        $espace->enregistrerConsultation('alice@exemple.test', $cache);

        self::assertSame(1, count($espace->recents('alice@exemple.test', null, 1)), 'la liste est bornée');

        $compte = $this->compte('alice@exemple.test');
        $droits = new DroitsDossiers($this->pdo);
        $groupe = $droits->creerGroupe('Espace ' . bin2hex(random_bytes(3)), 'lecteur');
        $droits->ajouterCompteAuGroupe($compte, $groupe);
        $dossier = $droits->dossierPourDocument($visible);
        self::assertNotNull($dossier);
        $droits->poserRegle($dossier, $groupe, 'lire', true);

        self::assertSame([$visible], array_column($espace->recents('alice@exemple.test', $compte), 'document'));
    }

    public function testEnregistrerLaConsultationDUnePieceInconnueEstRefuse(): void
    {
        $espace = new EspacePerso($this->config);
        $this->expectExceptionMessage('document introuvable');
        $espace->enregistrerConsultation('alice@exemple.test', 999999);
    }

    private function deposer(string $titre, ?string $dossier = null): int
    {
        $nom = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $titre) ?? 'piece') . '-' . bin2hex(random_bytes(3)) . '.txt';
        $options = [
            'titre' => $titre, 'type' => 'Facture', 'emetteur' => 'Atelier Fictif SA',
            'date' => '2026-10-03', 'texte' => 'contenu ' . $titre, 'statut' => 'valide',
        ];
        if ($dossier !== null) {
            $options['dossier'] = $dossier;
        }

        return (new DepotFichiers($this->config))->deposer($this->pieceADeposer($nom, 'contenu ' . $titre), $options);
    }

    private function compte(string $identifiant): int
    {
        // Identifiant unique : un compte 'alice@exemple.test' laissé par une autre suite ne doit pas
        // prêter son rôle (un admin répondrait « autorisé » sans règle de dossier).
        $identifiant = 'compte-' . bin2hex(random_bytes(4)) . '-' . $identifiant;
        $this->pdo->prepare('INSERT INTO comptes (identifiant, empreinte, role) VALUES (?, ?, ?)')
            ->execute([$identifiant, 'x', 'lecteur']);

        return (int) $this->pdo->lastInsertId();
    }
}
