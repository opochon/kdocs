<?php

declare(strict_types=1);

namespace Ged\Tests\Jonctions;

use Ged\Jonctions\Jetons;
use Ged\Kernel;
use Ged\Recherche\Schema as SchemaRecherche;
use Ged\Securite\DroitsRoutes;
use Ged\Socle\DepotFichiers;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\UploadedFile;

/**
 * Oracle du lot ged-api-documents — API REST des documents (parité fonctionnelle Paperless-ngx).
 *
 * Contrat HTTP de chaque route : statut, schéma JSON, et surtout DROITS : un compte sans droit ne voit
 * jamais un document hors de ses dossiers, ni en liste, ni en recherche, ni en téléchargement.
 * Les jetons sont ceux de `Jonctions\Jetons`, avec une portée dédiée lecture/écriture, jamais la session.
 */
final class DocumentsApiTest extends TestCase
{
    use SupportJonctions;

    protected function setUp(): void
    {
        $this->setUpJonctions();
    }

    protected function tearDown(): void
    {
        $this->tearDownJonctions();
    }

    public function testSansJetonOuSansCompteLApiRefuseEtNomme(): void
    {
        $app = Kernel::creer(dirname(__DIR__, 3));

        // Aucun jeton : 401 nommé, JSON d'erreur homogène.
        $nu = $this->http($app, 'GET', '/api/v1/documents');
        self::assertSame(401, $nu->getStatusCode());
        $erreur = $this->json($nu);
        self::assertSame('GET /api/v1/documents', $erreur['erreur']['etape']);
        self::assertStringContainsString('jeton absent', $erreur['erreur']['cause']);
        self::assertNotEmpty($erreur['erreur']['reference']);

        // Jeton inconnu.
        $faux = $this->http($app, 'GET', '/api/v1/documents', str_repeat('f', 64));
        self::assertSame(401, $faux->getStatusCode());
        self::assertStringContainsString('jeton inconnu', $this->json($faux)['erreur']['cause']);

        // Jeton d'application SANS compte : refusé (les droits ne peuvent pas être appliqués).
        $sansCompte = (new Jetons($this->pdo))->creer('sans-compte-' . $this->sel);
        $refus = $this->http($app, 'GET', '/api/v1/documents', $sansCompte);
        self::assertSame(403, $refus->getStatusCode());
        self::assertStringContainsString('aucun compte', $this->json($refus)['erreur']['cause']);

        // Jeton lié au compte mais de portée `depot` : la portée documents est exigée.
        $donnees = $this->compteEtDossiers();
        $depot = $this->jetonAppairage($donnees['compte'], $donnees['identifiant']);
        $mauvaisePortee = $this->http($app, 'GET', '/api/v1/documents', $depot);
        self::assertSame(403, $mauvaisePortee->getStatusCode());
        self::assertStringContainsString('portée insuffisante', $this->json($mauvaisePortee)['erreur']['cause']);

        // Jeton révoqué : 401 nommé.
        $lecture = $this->jetonDocuments($donnees['compte'], $donnees['identifiant']);
        $ligne = (new Jetons($this->pdo))->resoudre($lecture);
        self::assertNotNull($ligne);
        (new Jetons($this->pdo))->revoquer($ligne['id']);
        $revoque = $this->http($app, 'GET', '/api/v1/documents', $lecture);
        self::assertSame(401, $revoque->getStatusCode());
        self::assertStringContainsString('révoqué', $this->json($revoque)['erreur']['cause']);
    }

    public function testUnCompteNeVoitJamaisUnDocumentHorsDeSesDossiers(): void
    {
        $app = Kernel::creer(dirname(__DIR__, 3));
        SchemaRecherche::installer($this->pdo);
        $donnees = $this->compteEtDossiers();
        $visible = $this->deposerDans($donnees['ecriture'], 'Facture partagee', 'contenu-visible-' . $this->sel, 'Partage SA');
        $cache = $this->deposerDans($donnees['refus'], 'Facture confidentielle', 'contenu-cache-' . $this->sel, 'Confidentiel SA');
        $lecture = $this->jetonDocuments($donnees['compte'], $donnees['identifiant']);

        // LISTE : le document hors dossiers n'apparaît pas.
        $page = $this->json($this->http($app, 'GET', '/api/v1/documents', $lecture));
        foreach (['total', 'page', 'taille', 'pages', 'documents'] as $cle) {
            self::assertArrayHasKey($cle, $page, 'schéma de page incomplet');
        }
        $ids = array_column($page['documents'], 'id');
        self::assertContains($visible, $ids);
        self::assertNotContains($cache, $ids);

        // RECHERCHE plein texte : le périmètre des droits est le même que la liste.
        $cherche = $this->json($this->http($app, 'GET', '/api/v1/documents', $lecture, null, ['q' => 'Facture']));
        $ids = array_column($cherche['documents'], 'id');
        self::assertContains($visible, $ids);
        self::assertNotContains($cache, $ids);

        // FICHE et TÉLÉCHARGEMENT : refus nommé pour l'autre dossier, jamais le contenu.
        $ficheCachee = $this->http($app, 'GET', '/api/v1/documents/' . $cache, $lecture);
        self::assertSame(403, $ficheCachee->getStatusCode(), 'une fiche hors droits doit être refusée');
        self::assertStringContainsString('droit lire insuffisant', $this->json($ficheCachee)['erreur']['cause']);

        $fichierCache = $this->http($app, 'GET', '/api/v1/documents/' . $cache . '/fichier', $lecture);
        self::assertSame(403, $fichierCache->getStatusCode());
        self::assertStringNotContainsString('contenu-cache-' . $this->sel, (string) $fichierCache->getBody());

        $fiche = $this->http($app, 'GET', '/api/v1/documents/' . $visible, $lecture);
        self::assertSame(200, $fiche->getStatusCode());
        self::assertSame($visible, $this->json($fiche)['id']);
        $fichier = $this->http($app, 'GET', '/api/v1/documents/' . $visible . '/fichier', $lecture);
        self::assertSame(200, $fichier->getStatusCode());
        self::assertSame('contenu-visible-' . $this->sel, (string) $fichier->getBody());

        // RÉFÉRENTIELS : l'émetteur du document caché ne fuit pas non plus.
        $emetteurs = $this->json($this->http($app, 'GET', '/api/v1/emetteurs', $lecture));
        self::assertContains('Partage SA', $emetteurs);
        self::assertNotContains('Confidentiel SA', $emetteurs);
    }

    public function testLaRecherchePleinTexteRespecteLesDroits(): void
    {
        $app = Kernel::creer(dirname(__DIR__, 3));
        SchemaRecherche::installer($this->pdo);
        $donnees = $this->compteEtDossiers();
        $visible = $this->deposerDans($donnees['ecriture'], 'Piece A', 'a-' . $this->sel, 'Emetteur A');
        $cache = $this->deposerDans($donnees['refus'], 'Piece B', 'b-' . $this->sel, 'Emetteur B');

        // Le mot n'existe que dans le CONTENU indexé (jamais dans le titre) : c'est le plein texte qui répond.
        $indexer = $this->pdo->prepare('INSERT INTO recherche_textes (cle, chemin, document_id, titre, contenu, signature) VALUES (?, ?, ?, ?, ?, ?)');
        $mot = 'zorglub' . $this->sel;
        foreach ([$visible => 'Piece A', $cache => 'Piece B'] as $id => $titre) {
            $chemin = (string) $this->pdo->query('SELECT chemin_relatif FROM documents WHERE id = ' . (int) $id)->fetchColumn();
            $indexer->execute([hash('sha256', $chemin), $chemin, $id, $titre, "texte $mot", hash('sha256', 'sig-' . $id)]);
        }
        $lecture = $this->jetonDocuments($donnees['compte'], $donnees['identifiant']);
        $resultat = $this->json($this->http($app, 'GET', '/api/v1/documents', $lecture, null, ['q' => $mot]));
        $ids = array_column($resultat['documents'], 'id');
        self::assertContains($visible, $ids, 'le plein texte doit rendre le document lisible');
        self::assertNotContains($cache, $ids, 'le plein texte ne doit jamais rendre un document hors droits');
    }

    public function testEcritureModifieLesMetadonneesEtRefuseLeDeplacementSansDroit(): void
    {
        $app = Kernel::creer(dirname(__DIR__, 3));
        $donnees = $this->compteEtDossiers();
        $id = $this->deposerDans($donnees['ecriture'], 'Titre initial', 'meta-' . $this->sel, 'Emetteur');
        $ecriture = $this->jetonDocuments($donnees['compte'], $donnees['identifiant'], Jetons::PORTEE_ECRITURE);
        $lecture = $this->jetonDocuments($donnees['compte'], $donnees['identifiant']);

        // Un jeton de lecture ne peut pas écrire.
        $refuse = $this->http($app, 'POST', '/api/v1/documents/' . $id, $lecture, ['titre' => 'Interdit']);
        self::assertSame(403, $refuse->getStatusCode());

        // Le jeton d'écriture modifie les métadonnées ; la fiche reflète le changement.
        $modif = $this->http($app, 'POST', '/api/v1/documents/' . $id, $ecriture, ['titre' => 'Titre modifié', 'etiquettes' => ['Urgent', '2026']]);
        self::assertSame(200, $modif->getStatusCode(), (string) $modif->getBody());
        $fiche = $this->json($modif);
        self::assertSame('Titre modifié', $fiche['titre']);
        self::assertSame(['Urgent', '2026'], $fiche['etiquettes']);
        $enBase = (string) $this->pdo->query('SELECT titre FROM documents WHERE id = ' . $id)->fetchColumn();
        self::assertSame('Titre modifié', $enBase);

        // Déplacer vers un dossier SANS droit d'arrivée : refus 403 nommé, et le document ne bouge pas.
        $avant = (string) $this->pdo->query('SELECT chemin_relatif FROM documents WHERE id = ' . $id)->fetchColumn();
        $deplace = $this->http($app, 'POST', '/api/v1/documents/' . $id, $ecriture, ['dossier' => $donnees['refus']]);
        self::assertSame(403, $deplace->getStatusCode());
        self::assertSame($avant, (string) $this->pdo->query('SELECT chemin_relatif FROM documents WHERE id = ' . $id)->fetchColumn());

        // Une métadonnée inconnue est refusée 400, jamais silencieusement ignorée.
        $invalide = $this->http($app, 'POST', '/api/v1/documents/' . $id, $ecriture, ['champ_inconnu' => 'x']);
        self::assertSame(400, $invalide->getStatusCode());
    }

    public function testDeposerUnFichierPasseParLAChaineDIngestionEtEstIdempotent(): void
    {
        $app = Kernel::creer(dirname(__DIR__, 3));
        $donnees = $this->compteEtDossiers();
        $ecriture = $this->jetonDocuments($donnees['compte'], $donnees['identifiant'], Jetons::PORTEE_ECRITURE);
        $lecture = $this->jetonDocuments($donnees['compte'], $donnees['identifiant']);

        $contenu = 'depot-api-' . $this->sel;
        $chemin = $this->piece('depot.pdf', $contenu);

        $refus = $this->pousser($app, $lecture, $chemin, 'depot.pdf', ['metadonnees' => json_encode(['titre' => 'Refusé'])]);
        self::assertSame(403, $refus->getStatusCode(), 'un jeton de lecture ne dépose pas');

        $premier = $this->pousser($app, $ecriture, $chemin, 'depot.pdf', ['metadonnees' => json_encode(['titre' => 'Depot API'])]);
        self::assertSame(202, $premier->getStatusCode(), (string) $premier->getBody());
        $resume = $this->json($premier);
        self::assertArrayHasKey('travail', $resume);
        self::assertSame('/api/v1/depots/' . $resume['travail'], $resume['suivi']);

        // Même contenu deux fois (source distincte, empreinte identique) : même travail, aucun doublon.
        $second = $this->pousser($app, $ecriture, $this->piece('depot2.pdf', $contenu), 'depot2.pdf', ['metadonnees' => json_encode(['titre' => 'Depot API'])]);
        self::assertSame(202, $second->getStatusCode(), (string) $second->getBody());
        self::assertSame($resume['travail'], $this->json($second)['travail']);

        $source = (string) $this->pdo->query('SELECT source FROM ingestion_travaux WHERE id = ' . (int) $resume['travail'])->fetchColumn();
        self::assertSame('api', $source);
    }

    public function testPaginationBorneeEtVersionTelechargeable(): void
    {
        $app = Kernel::creer(dirname(__DIR__, 3));
        $donnees = $this->compteEtDossiers();
        $lecture = $this->jetonDocuments($donnees['compte'], $donnees['identifiant']);
        $un = $this->deposerDans($donnees['ecriture'], 'Un', 'un-' . $this->sel, 'E');
        $this->deposerDans($donnees['ecriture'], 'Deux', 'deux-' . $this->sel, 'E');
        $this->deposerDans($donnees['enfant'], 'Trois', 'trois-' . $this->sel, 'E');

        $page1 = $this->json($this->http($app, 'GET', '/api/v1/documents', $lecture, null, ['taille' => '2', 'page' => '1']));
        self::assertSame(2, $page1['taille']);
        self::assertSame(3, $page1['total']);
        self::assertCount(2, $page1['documents']);
        $page2 = $this->json($this->http($app, 'GET', '/api/v1/documents', $lecture, null, ['taille' => '2', 'page' => '2']));
        self::assertCount(1, $page2['documents']);

        // La taille de page est bornée à 100, la page à 1 minimum.
        $borne = $this->json($this->http($app, 'GET', '/api/v1/documents', $lecture, null, ['taille' => '10000', 'page' => '0']));
        self::assertSame(100, $borne['taille']);
        self::assertSame(1, $borne['page']);

        // Versions : la v1 reste téléchargeable après une nouvelle version, le courant est bien la v2.
        $depot = new DepotFichiers($this->config());
        $nouveau = $this->piece('v2.pdf', 'contenu-v2-' . $this->sel);
        $depot->remplacerContenu($un, $nouveau, 'test');

        $v1 = $this->http($app, 'GET', '/api/v1/documents/' . $un . '/versions/1/fichier', $lecture);
        self::assertSame(200, $v1->getStatusCode());
        self::assertSame('un-' . $this->sel, (string) $v1->getBody());
        self::assertSame('1', $v1->getHeaderLine('X-Ged-Version'));

        $courant = $this->http($app, 'GET', '/api/v1/documents/' . $un . '/fichier', $lecture);
        self::assertSame('contenu-v2-' . $this->sel, (string) $courant->getBody());
        self::assertSame('2', $courant->getHeaderLine('X-Ged-Version'));

        $absente = $this->http($app, 'GET', '/api/v1/documents/' . $un . '/versions/99/fichier', $lecture);
        self::assertSame(404, $absente->getStatusCode());
    }

    public function testLesReferentielsSontExposes(): void
    {
        $app = Kernel::creer(dirname(__DIR__, 3));
        $donnees = $this->compteEtDossiers();
        $lecture = $this->jetonDocuments($donnees['compte'], $donnees['identifiant']);

        $types = $this->json($this->http($app, 'GET', '/api/v1/types', $lecture));
        self::assertNotEmpty($types);
        self::assertArrayHasKey('nom', $types[0]);

        $vues = $this->json($this->http($app, 'GET', '/api/v1/vues', $lecture));
        self::assertIsArray($vues);

        self::assertSame(404, $this->http($app, 'GET', '/api/v1/documents/999999999', $lecture)->getStatusCode());
    }

    public function testLeDebitParJetonEstBorne(): void
    {
        putenv('API_DEBIT_MAX=3');
        $app = Kernel::creer(dirname(__DIR__, 3));
        $donnees = $this->compteEtDossiers();
        $lecture = $this->jetonDocuments($donnees['compte'], $donnees['identifiant']);

        try {
            for ($i = 0; $i < 3; ++$i) {
                self::assertSame(200, $this->http($app, 'GET', '/api/v1/documents', $lecture)->getStatusCode(), "requête $i");
            }
            $trop = $this->http($app, 'GET', '/api/v1/documents', $lecture);
            self::assertSame(429, $trop->getStatusCode());
            self::assertStringContainsString('débit', $this->json($trop)['erreur']['cause']);
        } finally {
            putenv('API_DEBIT_MAX');
        }
    }

    public function testLesNouvellesRoutesSontDeclareesHorsSession(): void
    {
        foreach (['GET /api/v1/documents', 'POST /api/v1/documents', 'GET /api/v1/types', 'GET /api/v1/etiquettes', 'GET /api/v1/emetteurs', 'GET /api/v1/vues'] as $definition) {
            [$methode, $chemin] = explode(' ', $definition, 2);
            self::assertNotNull(DroitsRoutes::permissionPour($methode, $chemin), "$definition non déclarée");
        }
        self::assertNotNull(DroitsRoutes::permissionPour('GET', '/api/v1/documents/12/fichier'));
        self::assertNotNull(DroitsRoutes::permissionPour('POST', '/api/v1/documents/12'));
        self::assertNotNull(DroitsRoutes::permissionPour('GET', '/api/v1/documents/12/versions/3/fichier'));
        self::assertNull(DroitsRoutes::permissionPour('GET', '/api/v1/inconnu'));
    }

    /** Dépose une pièce dans un dossier du fonds (droits ACL rattachés par le Socle). */
    private function deposerDans(string $dossier, string $titre, string $contenu, string $emetteur = ''): int
    {
        $chemin = $this->piece($titre . '.pdf', $contenu);
        $meta = ['titre' => $titre, 'dossier' => $dossier];
        if ($emetteur !== '') {
            $meta['emetteur'] = $emetteur;
        }

        return (new DepotFichiers($this->config()))->deposer($chemin, $meta);
    }

    private function pousser(App $app, string $jeton, string $chemin, string $nom, array $champs = []): ResponseInterface
    {
        $requete = (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/documents')
            ->withHeader('Authorization', 'Bearer ' . $jeton)
            ->withUploadedFiles(['fichier' => new UploadedFile($chemin, $nom, 'application/pdf', (int) filesize($chemin))]);
        if ($champs !== []) {
            $requete = $requete->withParsedBody($champs);
        }

        return $app->handle($requete);
    }

    /** @param array<string, mixed>|null $corps */
    private function http(App $app, string $methode, string $chemin, ?string $jeton = null, ?array $corps = null, array $query = []): ResponseInterface
    {
        $url = $chemin;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }
        $requete = (new ServerRequestFactory())->createServerRequest($methode, $url);
        if ($jeton !== null) {
            $requete = $requete->withHeader('Authorization', 'Bearer ' . $jeton);
        }
        if ($corps !== null) {
            $requete = $requete->withParsedBody($corps);
        }

        return $app->handle($requete);
    }

    /** @return array<mixed> */
    private function json(ResponseInterface $reponse): array
    {
        return (array) json_decode((string) $reponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
