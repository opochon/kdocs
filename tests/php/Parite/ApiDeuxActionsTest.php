<?php

declare(strict_types=1);

namespace Ged\Tests\Parite;

use Ged\Kernel;
use Ged\Recherche\Schema as SchemaRecherche;
use Ged\Socle\DepotFichiers;
use Ged\Tests\Jonctions\SupportJonctions;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Actions de l'API (famille 4 du lot ged-parite-api-2).
 *
 * Correspondances fonctionnelles avec Paperless-ngx 2.20.5 (src/documents/views.py) :
 *   - DocumentViewSet.email_document  -> POST /api/v1/documents/{id}/email ;
 *   - DocumentViewSet.email_documents -> POST /api/v1/documents/email ;
 *   - BulkEditObjectsView             -> POST /api/v1/documents/bulk_edit ;
 *   - BulkDownloadView                -> POST /api/v1/documents/bulk_download ;
 *   - StoragePathViewSet.test         -> POST /api/v1/dossiers/tester.
 *
 * Les envois passent par le mécanisme d'envoi de GEDv2 (transport « fichier » en test : aucun envoi réel).
 * Chaque action respecte les droits : un document hors dossiers fait refuser l'envoi, l'édition ou le ZIP.
 */
final class ApiDeuxActionsTest extends TestCase
{
    use SupportJonctions;

    protected function setUp(): void
    {
        $this->setUpJonctions();
        SchemaRecherche::installer($this->pdo);
    }

    protected function tearDown(): void
    {
        $this->tearDownJonctions();
    }

    public function testLesActionsEmailMasseTelechargementEtDossierRespectentLesDroits(): void
    {
        $app = Kernel::creer(dirname(__DIR__, 3));
        $donnees = $this->compteEtDossiers();
        $a = $this->deposer($donnees['ecriture'], 'facture-a');
        $b = $this->deposer($donnees['ecriture'], 'contrat-b');
        $cache = $this->deposer($donnees['refus'], 'confidentiel-c');
        $lecture = $this->jetonDocuments($donnees['compte'], $donnees['identifiant']);
        $ecriture = $this->jetonDocuments($donnees['compte'], $donnees['identifiant'], 'documents_ecriture');

        // email_document (détail) : un courriel portant le PDF est écrit par le transport de test.
        [$statutMail, $resumeMail] = $this->appel($app, 'POST', '/api/v1/documents/' . $a . '/email', $lecture,
            ['addresses' => 'destinataire@exemple.test', 'subject' => 'Votre facture', 'message' => 'Bonjour.']);
        self::assertSame(200, $statutMail);
        self::assertSame(1, $resumeMail['documents']);
        $eml = $this->dernierMail();
        self::assertStringContainsString('Votre facture', $eml);
        self::assertStringContainsString('application/pdf', $eml, 'le document est joint');

        // email_documents : plusieurs documents, plusieurs adresses.
        [$statutMails] = $this->appel($app, 'POST', '/api/v1/documents/email', $lecture,
            ['documents' => [$a, $b], 'addresses' => 'un@exemple.test, deux@exemple.test', 'subject' => 'Lot', 'message' => 'Voici.']);
        self::assertSame(200, $statutMails);

        // Un document hors dossiers fait refuser tout l'envoi (403 nommé), sans courriel partiel.
        [$statutRefus, $refus] = $this->appel($app, 'POST', '/api/v1/documents/email', $lecture,
            ['documents' => [$a, $cache], 'addresses' => 'x@exemple.test', 'subject' => 'Interdit', 'message' => 'Non.']);
        self::assertSame(403, $statutRefus);
        self::assertStringContainsString('insuffisant', (string) $refus['erreur']['cause']);
        self::assertCount(3, $this->mails(), 'aucun courriel supplémentaire pour l’envoi refusé');

        // Adresse invalide : 400 nommé, jamais un envoi silencieux.
        [$statutAdresse] = $this->appel($app, 'POST', '/api/v1/documents/email', $lecture,
            ['documents' => [$a], 'addresses' => 'pas-une-adresse', 'subject' => 'X', 'message' => 'Y.']);
        self::assertSame(400, $statutAdresse);

        // bulk_edit : le type est posé sur les deux documents.
        [$statutMasse, $bilan] = $this->appel($app, 'POST', '/api/v1/documents/bulk_edit', $ecriture,
            ['documents' => [$a, $b], 'action' => 'type', 'valeur' => 'Contrat']);
        self::assertSame(200, $statutMasse);
        self::assertSame('termine', $bilan['etat']);
        self::assertSame(2, $bilan['traites']);
        self::assertSame('Contrat', (new DepotFichiers($this->config()))->fiche($a)['type'] ?? null);

        // bulk_download : une archive ZIP contenant les fichiers.
        $requete = (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/documents/bulk_download')
            ->withHeader('Authorization', 'Bearer ' . $lecture)->withParsedBody(['documents' => [$a, $b]]);
        $reponse = $app->handle($requete);
        self::assertSame(200, $reponse->getStatusCode());
        self::assertSame('application/zip', $reponse->getHeaderLine('Content-Type'));
        self::assertStringStartsWith('PK', (string) $reponse->getBody(), 'l’archive ZIP est servie');

        // dossiers/tester : le dossier d'écriture est déposable, le dossier refusé ne l'est pas.
        [$statutOk, $ok] = $this->appel($app, 'POST', '/api/v1/dossiers/tester', $ecriture, ['dossier' => $donnees['ecriture']]);
        self::assertSame(200, $statutOk);
        self::assertTrue($ok['deposable']);
        [$statutNon, $non] = $this->appel($app, 'POST', '/api/v1/dossiers/tester', $ecriture, ['dossier' => $donnees['refus']]);
        self::assertSame(200, $statutNon);
        self::assertFalse($non['deposable']);

        // Sans jeton, chaque action refuse 401 et se nomme.
        foreach ([['POST', '/api/v1/documents/' . $a . '/email'], ['POST', '/api/v1/documents/email'],
            ['POST', '/api/v1/documents/bulk_edit'], ['POST', '/api/v1/documents/bulk_download'],
            ['POST', '/api/v1/dossiers/tester']] as [$methode, $chemin]) {
            [$code, $corps] = $this->appel($app, $methode, $chemin, null, ['documents' => [$a], 'dossier' => 'x']);
            self::assertSame(401, $code, "$methode $chemin doit refuser sans jeton");
            self::assertArrayHasKey('etape', $corps['erreur']);
        }

        // Une portée de lecture suffit pour l'email, pas pour l'écriture en masse.
        [$statutPortee] = $this->appel($app, 'POST', '/api/v1/documents/bulk_edit', $lecture,
            ['documents' => [$a], 'action' => 'type', 'valeur' => 'Facture']);
        self::assertSame(403, $statutPortee, 'la portée lecture ne peut pas modifier en masse');
    }

    private function deposer(string $dossier, string $titre): int
    {
        return (new DepotFichiers($this->config()))->deposer($this->piece($titre . '.pdf', $titre),
            ['titre' => $titre, 'dossier' => $dossier, 'emetteur' => 'Émetteur']);
    }
}
