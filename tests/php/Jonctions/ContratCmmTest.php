<?php

declare(strict_types=1);

namespace Ged\Tests\Jonctions;

use Ged\Config;
use Ged\Ingestion\Decoupeur;
use Ged\Ingestion\Pilote;
use Ged\Ingestion\Travaux;
use Ged\Jonctions\Appairage;
use Ged\Jonctions\Dossiers;
use Ged\Jonctions\Jetons;
use Ged\Kernel;
use Ged\Recherche\IndexPleinTexte;
use Ged\Recherche\Requete;
use Ged\Recherche\Schema as SchemaRecherche;
use Ged\Recherche\Service;
use Ged\Securite\DroitsDossiers;
use Ged\Socle\DepotFichiers;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Psr7\UploadedFile;

/**
 * Oracle V2-08b — un test HTTP qui JOUE LE RÔLE DE CMM (section 5 du contrat).
 *
 * Échange du code d'appairage, `GET /api/v1/dossiers` selon les droits du compte, dépôt d'une pièce
 * jointe → ingestion, dépôt d'un `.eml` → dossier (tracé, indexé, aperçu nettoyé), 403 sans droit
 * d'écriture, 401 après révocation, idempotence.
 */
final class ContratCmmTest extends TestCase
{
    use SupportJonctions;

    private App $app;

    /** @var array{compte:int, identifiant:string, ecriture:string, enfant:string, refus:string} */
    private array $compte;

    protected function setUp(): void
    {
        $this->setUpJonctions();
        $this->compte = $this->compteEtDossiers();
        $this->app = Kernel::creer(dirname(__DIR__, 3));
    }

    protected function tearDown(): void
    {
        $this->tearDownJonctions();
    }

    public function testParcoursCmmDeBoutEnBout(): void
    {
        $jeton = $this->relier();

        // GET /api/v1/appairage/moi : statut « Relié à GED de <compte> ».
        [$code, $moi] = $this->appel($this->app, 'GET', '/api/v1/appairage/moi', $jeton);
        self::assertSame(200, $code, json_encode($moi));
        self::assertSame($this->compte['identifiant'], $moi['compte']);
        self::assertTrue($moi['droits']['ingestion']);
        self::assertSame(2, $moi['dossiers_ecriture']);

        // GET /api/v1/dossiers : ce que CE compte peut écrire, refus par défaut.
        [$code, $dossiers] = $this->appel($this->app, 'GET', '/api/v1/dossiers', $jeton);
        self::assertSame(200, $code, json_encode($dossiers));
        $chemins = array_column($dossiers, 'chemin');
        self::assertContains($this->compte['ecriture'], $chemins);
        self::assertNotContains($this->compte['refus'], $chemins, 'un dossier sans droit n’est pas listé');

        [$code, $enfants] = $this->appel($this->app, 'GET', '/api/v1/dossiers?parent=' . rawurlencode($this->compte['ecriture']), $jeton);
        self::assertSame(200, $code);
        self::assertSame([$this->compte['enfant']], array_column($enfants, 'chemin'));

        // Dépôt d'une pièce jointe → ingestion, avec l'origine CMM conservée.
        $origine = [
            'application' => 'cmm', 'message_id' => '<message-' . $this->sel . '@exemple.test>',
            'compte_mail' => 'bob@exemple.test', 'de' => 'alice@exemple.test',
            'a' => 'bob@exemple.test', 'objet' => 'Facture ' . $this->sel, 'date' => '2026-09-30',
        ];
        $contenuPj = '%PDF-1.4 piece jointe ' . $this->sel;
        $idTravail = $this->deposerPj($jeton, $contenuPj, $origine);
        self::assertGreaterThan(0, $idTravail);

        $chemin = (string) $this->pdo->query('SELECT chemin FROM ingestion_travaux WHERE id = ' . $idTravail)->fetchColumn();
        $accompagnement = json_decode((string) file_get_contents($chemin . '.depot.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($origine, $accompagnement['metadonnees']['origine']);

        // Idempotence ingestion : même empreinte = même travail, même réponse.
        self::assertSame($idTravail, $this->deposerPj($jeton, $contenuPj, $origine));

        // Dépôt d'un .eml → dossier : rangé sous le dossier autorisé, statut a_controler.
        [$code, $corps] = $this->envoyer(
            $this->app,
            $jeton,
            $this->fichierEml($this->compte['identifiant']),
            ['destination' => 'dossier', 'dossier' => $this->compte['ecriture'], 'origine' => json_encode($origine)],
        );
        self::assertSame(202, $code, json_encode($corps));
        self::assertSame('a_controler', $corps['etat']);
        $document = (int) $corps['document'];
        self::assertGreaterThan(0, $document);

        $ligne = $this->pdo->query('SELECT chemin_relatif, meta FROM documents WHERE id = ' . $document)->fetch();
        self::assertStringStartsWith($this->compte['ecriture'] . '/', (string) $ligne['chemin_relatif'], 'rangé dans le dossier demandé');
        self::assertFileExists($this->fonds . '/' . $ligne['chemin_relatif'], 'le dossier fait foi : le fichier est là');
        $meta = json_decode((string) $ligne['meta'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($origine, $meta['origine'], 'l’origine CMM est écrite dans le .meta.json');
        self::assertStringContainsString('CORPS-' . $this->sel, (string) $meta['texte'], 'le texte du message est indexé');

        // Idempotence dossier : même empreinte = même document, pas de doublon.
        [, $corps2] = $this->envoyer(
            $this->app,
            $jeton,
            $this->fichierEml($this->compte['identifiant']),
            ['destination' => 'dossier', 'dossier' => $this->compte['ecriture'], 'origine' => json_encode($origine)],
        );
        self::assertSame($document, (int) $corps2['document']);

        // Le message est trouvable par la recherche plein texte (index bâti hors requête). L'index est
        // PARTAGÉ : on restaure l'état d'absence des autres lignes après le job, sinon un fonds préparé
        // par un autre parcours serait marqué absent.
        $config = new Config(dirname(__DIR__, 3));
        SchemaRecherche::installer($this->pdo);
        $absences = $this->pdo->query('SELECT cle, absent_le FROM recherche_textes')->fetchAll(\PDO::FETCH_KEY_PAIR);
        try {
            IndexPleinTexte::depuis($config, $this->pdo)->synchroniser(null);
            $resultat = (new Service($this->pdo, IndexPleinTexte::depuis($config, $this->pdo)))->rechercher(new Requete($this->sel));
            self::assertContains($document, array_map(static fn ($t): int => $t->id, $resultat->trouvailles), 'le .eml est indexé');
        } finally {
            $restauration = $this->pdo->prepare('UPDATE recherche_textes SET absent_le = ? WHERE cle = ?');
            foreach ($absences as $cle => $absent) {
                $restauration->execute([$absent, $cle]);
            }
        }

        // Aperçu = HTML nettoyé, sans script ni ressource distante, pièces jointes listées.
        $reponse = $this->app->handle((new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', '/documents/' . $document . '/apercu'));
        self::assertSame(200, $reponse->getStatusCode());
        $html = (string) $reponse->getBody();
        self::assertStringContainsString('CORPS-' . $this->sel, $html);
        self::assertStringContainsString('facture-' . $this->sel . '.pdf', $html, 'les pièces jointes sont listées');
        self::assertStringNotContainsString('window.__cmm_xss', $html);
        self::assertStringNotContainsString('charge.png', $html);

        // 403 nommé : le compte n'a pas le droit d'écrire dans Confidentiel.
        [$code, $refus] = $this->envoyer(
            $this->app,
            $jeton,
            new UploadedFile($this->piece('refus-' . $this->sel . '.pdf', '%PDF-1.4 refus ' . $this->sel), 'refus.pdf', 'application/pdf', 32),
            ['destination' => 'dossier', 'dossier' => $this->compte['refus']],
        );
        self::assertSame(403, $code, json_encode($refus));
        self::assertStringContainsString('droit', $refus['erreur']['cause']);
        self::assertSame('POST /api/v1/depots', $refus['erreur']['etape']);

        // DELETE /api/v1/appairage/moi : révocation ; le jeton est ensuite refusé 401, nommé.
        [$code, $revoque] = $this->appel($this->app, 'DELETE', '/api/v1/appairage/moi', $jeton);
        self::assertSame(200, $code, json_encode($revoque));
        [$code, $apres] = $this->appel($this->app, 'GET', '/api/v1/appairage/moi', $jeton);
        self::assertSame(401, $code);
        self::assertStringContainsString('révoqué', $apres['erreur']['cause']);
        [$code] = $this->envoyer($this->app, $jeton, $this->fichierEml($this->compte['identifiant']),
            ['destination' => 'dossier', 'dossier' => $this->compte['ecriture']]);
        self::assertSame(401, $code, 'un jeton révoqué ne dépose plus');
    }

    public function testLOrigineCmmArriveDansLeMetaJsonParLaChaineDIngestion(): void
    {
        $jeton = $this->jetonAppairage($this->compte['compte'], $this->compte['identifiant']);
        $origine = ['application' => 'cmm', 'message_id' => '<ingestion-' . $this->sel . '@exemple.test>', 'objet' => 'PJ ' . $this->sel];
        $idTravail = $this->deposerPj($jeton, '%PDF-1.4 lot ' . $this->sel, $origine, false);
        self::assertGreaterThan(0, $idTravail);

        $config = new Config(dirname(__DIR__, 3));
        $pilote = new Pilote(new Travaux($this->pdo), [], new DecoupeurPieceUnique(), new DepotFichiers($config), null, $this->travail);
        // Un tic découpe le lot, le suivant range la pièce.
        for ($i = 0; $i < 4; ++$i) {
            $pilote->tic(5);
        }

        $meta = $this->pdo->query("SELECT meta FROM documents WHERE meta LIKE '%ingestion-" . $this->sel . "@exemple.test%' LIMIT 1")->fetchColumn();
        self::assertIsString($meta, 'la pièce d’ingestion est rangée');
        $donnees = json_decode($meta, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('cmm', $donnees['origine']['courriel']['application'] ?? null, 'l’origine CMM suit la chaîne d’ingestion');
        self::assertSame('<ingestion-' . $this->sel . '@exemple.test>', $donnees['origine']['courriel']['message_id'] ?? null);
    }

    /** Joue CMM : échange le code d'appairage contre un jeton. */
    private function relier(): string
    {
        $appairage = new Appairage(
            new Jetons($this->pdo), $this->pdo, new Dossiers($this->pdo, new DroitsDossiers($this->pdo)), 'GEDv2',
        );
        $url = $appairage->ouvrir('cmm', 'poste-cmm', 'https://cmm.exemple.test/retour', 'alea', $this->compte['compte'], $this->compte['identifiant']);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $parametres);

        [$code, $corps] = $this->appel($this->app, 'POST', '/api/v1/appairage/jeton', null, ['code' => (string) $parametres['code']]);
        self::assertSame(200, $code, json_encode($corps));
        self::assertSame($this->compte['identifiant'], $corps['compte']);

        return (string) $corps['jeton'];
    }

    /** @param array<string, mixed> $origine @return int identifiant du travail d'ingestion */
    private function deposerPj(string $jeton, string $contenu, array $origine, bool $verifierStatut = true): int
    {
        [, $corps] = $this->envoyer(
            $this->app,
            $jeton,
            new UploadedFile($this->piece('pj-' . bin2hex(random_bytes(3)) . '.pdf', $contenu), 'piece-jointe.pdf', 'application/pdf', strlen($contenu)),
            ['destination' => 'ingestion', 'origine' => json_encode($origine)],
        );
        if ($verifierStatut) {
            self::assertSame('recu', $corps['etat'] ?? null, json_encode($corps));
        }

        return (int) ($corps['travail'] ?? 0);
    }

    private function fichierEml(string $destinataire): UploadedFile
    {
        $nom = 'message-' . $this->sel . '.eml';
        $chemin = $this->piece($nom, $this->eml($destinataire));

        return new UploadedFile($chemin, $nom, 'message/rfc822', (int) filesize($chemin));
    }

    private function eml(string $destinataire): string
    {
        $corps = "Bonjour,\n\nVoici le CORPS-{$this->sel} demandé.\n";
        $html = '<html><body><p>CORPS-' . $this->sel . ' <a href="https://courriel.exemple.test/piece">voir</a></p>'
            . '<p><img src="https://courriel.exemple.test/charge.png" alt="logo"></p>'
            . '<script>window.__cmm_xss=1</script><p onclick="alert(1)">Fin</p></body></html>';
        $piece = chunk_split(base64_encode('%PDF-1.4 piece jointe ' . $this->sel));

        return "From: Alice Exemple <alice@exemple.test>\r\n"
            . "To: {$destinataire}\r\n"
            . "Subject: Facture {$this->sel}\r\n"
            . "Date: Tue, 30 Sep 2026 09:00:00 +0200\r\n"
            . "Message-ID: <message-{$this->sel}@exemple.test>\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: multipart/mixed; boundary=\"LIMITE\"\r\n\r\n"
            . "--LIMITE\r\nContent-Type: multipart/alternative; boundary=\"ALT\"\r\n\r\n"
            . "--ALT\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n{$corps}\r\n"
            . "--ALT\r\nContent-Type: text/html; charset=utf-8\r\n\r\n{$html}\r\n"
            . "--ALT--\r\n"
            . "--LIMITE\r\nContent-Type: application/pdf; name=\"facture-{$this->sel}.pdf\"\r\n"
            . "Content-Transfer-Encoding: base64\r\n"
            . "Content-Disposition: attachment; filename=\"facture-{$this->sel}.pdf\"\r\n\r\n{$piece}\r\n"
            . "--LIMITE--\r\n";
    }
}

/** Moteur simulé : une pièce par lot, copie du fichier reçu. */
final class DecoupeurPieceUnique implements Decoupeur
{
    public function decouper(string $fichier, string $dossierSortie): array
    {
        @mkdir($dossierSortie, 0775, true);
        $sortie = $dossierSortie . '/piece-1.pdf';
        file_put_contents($sortie, (string) file_get_contents($fichier));

        return [['fichier' => $sortie, 'pages' => [1], 'texte' => 'texte simulé', 'analyse' => []]];
    }
}
