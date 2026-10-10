<?php

declare(strict_types=1);

namespace Ged\Tests\Edition;

use Ged\Edition\Droits;
use Ged\Edition\Editeur;
use Ged\Edition\Ouvertures;
use Ged\Edition\Secours;
use Ged\Jonctions\Jetons;
use Ged\Securite\DroitsRoutes;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\UploadedFile;

/**
 * Oracle du lot ged-edition-serveur (F13, côté serveur).
 *
 * Chaque route est jouée comme le fait l'agent local : jeton d'appareil (`Authorization: Bearer …`),
 * jamais une session web. On prouve le téléchargement (empreinte + numéro de version), le dépôt d'une
 * version avec la version de base (conflit signalé sans rien perdre), l'ouverture informative prolongée
 * par le signal (et l'abandon sans signal), l'espace de secours (une copie par document et par appareil,
 * jamais une version) et la récupération d'une copie en nouvelle version.
 */
final class ApiEditionTest extends TestCase
{
    use SupportEdition;

    protected function setUp(): void
    {
        $this->setUpEdition();
    }

    protected function tearDown(): void
    {
        $this->tearDownEdition();
    }

    public function testTelechargerDonneLeFichierSonEmpreinteEtSaVersion(): void
    {
        [$code, $reponse, $corps] = $this->appel('GET', '/api/v1/edition/documents/' . $this->document . '/fichier', $this->jeton);

        self::assertSame(200, $code, $corps);
        self::assertSame(hash('sha256', $this->contenuV1), $reponse->getHeaderLine('X-Ged-Empreinte'));
        self::assertSame('1', $reponse->getHeaderLine('X-Ged-Version'));
        self::assertSame($this->contenuV1, $corps, 'le contenu servi est celui du fonds');

        // L'état seul, sans le fichier : le nom rangé porte l'extension d'origine, le type est connu.
        [$code, , $corps] = $this->appel('GET', '/api/v1/edition/documents/' . $this->document, $this->jeton);
        self::assertSame(200, $code, $corps);
        $etat = json_decode($corps, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($this->document, $etat['document']);
        self::assertSame(1, $etat['version']);
        self::assertSame(hash('sha256', $this->contenuV1), $etat['empreinte']);
        self::assertStringEndsWith('.docx', (string) $etat['nom']);
    }

    public function testJetonAbsentFauxOuRevoqueEstRefuseEtNomme(): void
    {
        $chemin = '/api/v1/edition/documents/' . $this->document . '/fichier';

        [$code, , $corps] = $this->appel('GET', $chemin);
        self::assertSame(401, $code);
        self::assertStringContainsString('jeton absent', $corps);

        [$code, , $corps] = $this->appel('GET', $chemin, str_repeat('f', 64));
        self::assertSame(401, $code);
        self::assertStringContainsString('jeton inconnu', $corps);

        $jetons = new Jetons($this->pdo);
        $jeton = $jetons->creerAppairage('kdocs', 'POSTE-REVOQUE', $this->compteId, $this->identifiant);
        $ligne = $jetons->resoudre($jeton);
        self::assertNotNull($ligne);
        self::assertTrue($jetons->revoquer($ligne['id']));
        [$code, , $corps] = $this->appel('GET', $chemin, $jeton);
        self::assertSame(401, $code);
        self::assertStringContainsString('révoqué', $corps);

        // Un jeton d'APPLICATION (non appairé, donc sans compte) n'ouvre pas l'édition.
        $application = (new Jetons($this->pdo))->creer('agent-non-appaire');
        [$code, , $corps] = $this->appel('GET', $chemin, $application);
        self::assertSame(403, $code);
        self::assertStringContainsString('compte', $corps);
    }

    public function testIdentifiantHorsDroitsOuInconnuEstRefuse(): void
    {
        // Document existant mais dans un dossier sans règle pour ce compte : refus nommé côté serveur.
        [$code, , $corps] = $this->appel('GET', '/api/v1/edition/documents/' . $this->documentInterdit . '/fichier', $this->jeton);
        self::assertSame(403, $code, $corps);
        self::assertStringContainsString('droit', $corps);

        // Même refus pour un dépôt de version : un identifiant hors droits ne route nulle part.
        $fichier = new UploadedFile($this->piece('hors-droits.docx', 'x'), 'hors-droits.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 1);
        [$code, , $corps] = $this->appel('POST', '/api/v1/edition/documents/' . $this->documentInterdit . '/versions', $this->jeton,
            ['version_base' => '1'], ['fichier' => $fichier]);
        self::assertSame(403, $code, $corps);

        // Document inconnu : 404 nommé, jamais un 500 muet.
        [$code, , $corps] = $this->appel('GET', '/api/v1/edition/documents/999999999/fichier', $this->jeton);
        self::assertSame(404, $code, $corps);
    }

    public function testDepotAvecVersionDeBaseAJourCreeUneVersion(): void
    {
        $contenu = "contenu-v2-$this->sel";
        $fichier = new UploadedFile($this->piece('rapport.docx', $contenu), 'rapport-edition.docx',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document', strlen($contenu));

        [$code, , $corps] = $this->appel('POST', '/api/v1/edition/documents/' . $this->document . '/versions', $this->jeton,
            ['version_base' => '1'], ['fichier' => $fichier]);
        self::assertSame(200, $code, $corps);
        $resultat = json_decode($corps, true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($resultat['conflit']);
        self::assertFalse($resultat['deja']);
        self::assertSame(2, $resultat['version']);
        self::assertSame(hash('sha256', $contenu), $resultat['empreinte']);

        // Le contenu courant est bien la nouvelle version ; les DEUX contenus sont archivés.
        [, , $servi] = $this->appel('GET', '/api/v1/edition/documents/' . $this->document . '/fichier', $this->jeton);
        self::assertSame($contenu, $servi);
        self::assertSame([1 => hash('sha256', $this->contenuV1), 2 => hash('sha256', $contenu)], $this->empreintes($this->document));

        // Rejeu du même contenu (reprise après coupure) : rien de neuf, empreinte confirmée.
        $rejeu = new UploadedFile($this->piece('rapport.docx', $contenu), 'rapport-edition.docx',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document', strlen($contenu));
        [$code, , $corps] = $this->appel('POST', '/api/v1/edition/documents/' . $this->document . '/versions', $this->jeton,
            ['version_base' => '2'], ['fichier' => $rejeu]);
        self::assertSame(200, $code, $corps);
        $resultat = json_decode($corps, true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($resultat['deja']);
        self::assertSame(2, $resultat['version']);
        self::assertCount(2, $this->empreintes($this->document));
    }

    public function testDepotAvecVersionDeBasePerimeeSignaleUnConflitSansRienPerdre(): void
    {
        $contenuB = "contenu-b-$this->sel";
        $fichierB = new UploadedFile($this->piece('b.docx', $contenuB), 'b.docx',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document', strlen($contenuB));
        [$code] = $this->appel('POST', '/api/v1/edition/documents/' . $this->document . '/versions', $this->jeton,
            ['version_base' => '1'], ['fichier' => $fichierB]);
        self::assertSame(200, $code);

        // L'agent travaillait encore sur la version 1 : sa base n'est plus la dernière.
        $contenuC = "contenu-parallele-$this->sel";
        $fichierC = new UploadedFile($this->piece('c.docx', $contenuC), 'c.docx',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document', strlen($contenuC));
        [$code, , $corps] = $this->appel('POST', '/api/v1/edition/documents/' . $this->document . '/versions', $this->jeton,
            ['version_base' => '1'], ['fichier' => $fichierC]);
        self::assertSame(200, $code, $corps);
        $resultat = json_decode($corps, true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($resultat['conflit'], 'une base périmée est signalée');
        self::assertSame(3, $resultat['version']);
        self::assertSame(2, $resultat['version_avant']);

        // Aucun contenu perdu : les trois empreintes sont archivées, la version parallèle est tracée.
        self::assertSame([
            1 => hash('sha256', $this->contenuV1),
            2 => hash('sha256', $contenuB),
            3 => hash('sha256', $contenuC),
        ], $this->empreintes($this->document));
        $trace = $this->pdo->prepare("SELECT COUNT(*) FROM document_collaboration WHERE document_id = ? AND type = 'evenement' AND action = 'version_parallele'");
        $trace->execute([$this->document]);
        self::assertSame(1, (int) $trace->fetchColumn(), 'la version parallèle est signalée dans la collaboration');
    }

    public function testOuvertureProlongeeParLeSignalPuisFermetureEtAbandon(): void
    {
        [$code, , $corps] = $this->appel('POST', '/api/v1/edition/documents/' . $this->document . '/ouverture', $this->jeton);
        self::assertSame(200, $code, $corps);
        $ouverture = json_decode($corps, true, 512, JSON_THROW_ON_ERROR)['ouverture'];
        self::assertSame($this->identifiant, $ouverture['auteur']);
        self::assertSame($this->poste, $ouverture['poste'], 'le poste vient du jeton, jamais du corps');

        $ouvertures = new Ouvertures($this->pdo);
        $active = $ouvertures->active($this->document);
        self::assertNotNull($active);
        self::assertFalse($active['abandonnee']);

        // Sans nouveau signal depuis 10 minutes : peut-être abandonné.
        $tard = time() + Ouvertures::DELAI_SIGNAL_S + 1;
        $abandon = $ouvertures->active($this->document, $tard);
        self::assertNotNull($abandon);
        self::assertTrue($abandon['abandonnee'], 'sans signal pendant 10 minutes, l’ouverture est peut-être abandonnée');

        // La route de signal répond et prolonge bien une ouverture active.
        [$code, , $corps] = $this->appel('POST', '/api/v1/edition/documents/' . $this->document . '/signal', $this->jeton);
        self::assertSame(200, $code, $corps);
        self::assertTrue(json_decode($corps, true, 512, JSON_THROW_ON_ERROR)['prolonge']);

        // Prolongation mesurable : un signal daté repousse le bail de 10 minutes À PARTIR de cet instant.
        self::assertTrue($ouvertures->signal($this->document, $this->identifiant, $this->poste, $tard));
        self::assertFalse((bool) $ouvertures->active($this->document, $tard + 1)['abandonnee']);
        self::assertTrue((bool) $ouvertures->active($this->document, $tard + Ouvertures::DELAI_SIGNAL_S + 1)['abandonnee']);

        // La fermeture lève le signal : plus d'ouverture active, mais la trace reste.
        [$code, , $corps] = $this->appel('POST', '/api/v1/edition/documents/' . $this->document . '/fermeture', $this->jeton);
        self::assertSame(200, $code, $corps);
        self::assertTrue(json_decode($corps, true, 512, JSON_THROW_ON_ERROR)['ferme']);
        self::assertNull($ouvertures->active($this->document));
        $traces = $this->pdo->prepare("SELECT COUNT(*) FROM document_collaboration WHERE document_id = ? AND type = 'evenement' AND action = 'ouverture'");
        $traces->execute([$this->document]);
        self::assertSame(1, (int) $traces->fetchColumn(), 'la fermeture ne supprime pas la trace');
    }

    public function testCopieDeSecoursUneParAppareilPuisRecuperationEnVersion(): void
    {
        $secours = new Secours($this->config());
        $copieV1 = "secours-jamais-enregistre-$this->sel";
        $fichier = new UploadedFile($this->piece('copie.docx', $copieV1), 'copie.docx',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document', strlen($copieV1));
        [$code, , $corps] = $this->appel('POST', '/api/v1/edition/documents/' . $this->document . '/secours', $this->jeton, [], ['fichier' => $fichier]);
        self::assertSame(200, $code, $corps);

        // Une copie de secours N'EST PAS une version : le document courant ne change pas.
        self::assertSame([1], array_keys($this->empreintes($this->document)));
        [, , $servi] = $this->appel('GET', '/api/v1/edition/documents/' . $this->document . '/fichier', $this->jeton);
        self::assertSame($this->contenuV1, $servi);

        $copies = $secours->lister($this->document);
        self::assertCount(1, $copies);
        self::assertSame($this->poste, $copies[0]['appareil']);
        self::assertSame($copieV1, file_get_contents($secours->racine() . '/' . $this->document . '/' . $copies[0]['id'] . '/' . $copies[0]['fichier']));
        self::assertStringStartsWith($this->secours, $secours->racine(), 'l’espace de secours est hors du fonds');
        self::assertStringNotContainsString($this->fonds, $secours->racine());

        // Une seconde copie du MÊME appareil remplace la précédente : une seule par document et par appareil.
        $copieV2 = "secours-plus-recent-$this->sel";
        $secours->deposer($this->document, $this->poste, $this->piece('copie.docx', $copieV2));
        $copies = $secours->lister($this->document);
        self::assertCount(1, $copies);
        self::assertSame(hash('sha256', $copieV2), $copies[0]['empreinte']);

        // Un autre appareil a SA copie : la liste en porte deux.
        $secours->deposer($this->document, 'POSTE-AUTRE', $this->piece('copie.docx', "secours-autre-$this->sel"));
        self::assertCount(2, $secours->lister($this->document));

        // Récupérer la copie crée une VERSION (le contenu courant est archivé, rien n'est écrasé).
        $version = $secours->recuperer($this->document, $copies[0]['id'], $this->identifiant);
        self::assertSame(2, $version);
        [, , $servi] = $this->appel('GET', '/api/v1/edition/documents/' . $this->document . '/fichier', $this->jeton);
        self::assertSame($copieV2, $servi);
        self::assertSame([1 => hash('sha256', $this->contenuV1), 2 => hash('sha256', $copieV2)], $this->empreintes($this->document));
    }

    public function testLesRoutesDuLotSontDeclarees(): void
    {
        self::assertSame('publique:api-edition', DroitsRoutes::permissionPour('GET', '/api/v1/edition/documents/204/fichier'));
        self::assertSame('publique:api-edition', DroitsRoutes::permissionPour('GET', '/api/v1/edition/documents/204'));
        self::assertSame('publique:api-edition', DroitsRoutes::permissionPour('POST', '/api/v1/edition/documents/204/versions'));
        self::assertSame('publique:api-edition', DroitsRoutes::permissionPour('POST', '/api/v1/edition/documents/204/ouverture'));
        self::assertSame('publique:api-edition', DroitsRoutes::permissionPour('POST', '/api/v1/edition/documents/204/signal'));
        self::assertSame('publique:api-edition', DroitsRoutes::permissionPour('POST', '/api/v1/edition/documents/204/fermeture'));
        self::assertSame('publique:api-edition', DroitsRoutes::permissionPour('POST', '/api/v1/edition/documents/204/secours'));
        self::assertSame('publique:api-edition', DroitsRoutes::permissionPour('GET', '/api/v1/edition/documents/204/secours'));

        self::assertSame('documents:modifier', DroitsRoutes::permissionPour('POST', '/documents/204/secours/recuperer'));
        self::assertSame('modifier', DroitsRoutes::niveauDossierPour('POST', '/documents/204/secours/recuperer'));
    }

    public function testLaPermissionDEditionSepareLectureEtModification(): void
    {
        // Le service refuse un jeton d'application (sans compte) ; la vérification par dossier passe par
        // DroitsDossiers comme la garde web. La réponse nomme toujours l'étape.
        [, , $corps] = $this->appel('POST', '/api/v1/edition/documents/' . $this->document . '/ouverture',
            (new Jetons($this->pdo))->creer('sans-appareil'));
        self::assertStringContainsString('/api/v1/edition/documents/' . $this->document . '/ouverture', $corps);

        self::assertTrue(Droits::peutModifier($this->config(), $this->compteId, ['documents:modifier'], $this->document));
        self::assertFalse(Droits::peutModifier($this->config(), $this->compteId, ['documents:modifier'], $this->documentInterdit));
        self::assertFalse(Droits::peutModifier($this->config(), $this->compteId, ['documents:lire'], $this->document));
    }

    public function testLEditeurExposeVersionEtEmpreinteCourantes(): void
    {
        $editeur = new Editeur($this->config());
        $etat = $editeur->document($this->document);
        self::assertNotNull($etat);
        self::assertSame(1, $etat['version']);
        self::assertSame(hash('sha256', $this->contenuV1), $etat['empreinte']);
        self::assertIsString($editeur->chemin($this->document));
        self::assertNull($editeur->document(999999999));
    }
}
