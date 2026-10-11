<?php

declare(strict_types=1);

namespace Ged\Tests\Jonctions;

use Ged\Base;
use Ged\Config;
use Ged\Ingestion\Schema as SchemaIngestion;
use Ged\Jonctions\Appairage;
use Ged\Jonctions\Dossiers;
use Ged\Jonctions\Jetons;
use Ged\Jonctions\Mailer;
use Ged\Jonctions\Profil;
use Ged\Jonctions\Schema as SchemaJonctions;
use Ged\Securite\DroitsDossiers;
use Ged\Securite\Schema as SchemaSecurite;
use Ged\Socle\Schema as SchemaSocle;
use PDO;
use Psr\Http\Message\UploadedFileInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use ZBateson\MailMimeParser\Message;

/**
 * Socle des oracles du lot V2-08c (appairage parti de la GED, courriel d'auto-configuration).
 *
 * Base de TEST uniquement (`gedv2_test`) ; le fonds, la zone d'ingestion et les courriels vivent dans un
 * dossier éphémère hors du dépôt (zéro donnée réelle). Les tables du Socle, des Droits et de l'Ingestion
 * sont installées mais JAMAIS effacées : le nettoyage ne touche que ce que ce test a créé (préfixe unique).
 */
trait SupportJonctions
{
    private PDO $pdo;
    private string $racine;
    private string $fonds;
    private string $travail;
    private string $mailsDir;
    private string $sel;
    private string|false $storageAvant;
    private string|false $travailAvant;
    private string|false $mailsAvant;
    private string|false $transportAvant;
    private string|false $debitAvant;

    protected function setUpJonctions(): void
    {
        $config = new Config(dirname(__DIR__, 3));
        self::assertSame('gedv2_test', $config->get('DB_NAME'), 'les tests ne touchent que gedv2_test');
        Base::assurerBase($config);
        $this->pdo = Base::pdo($config);

        // V2-08d : tout le secteur tient dans UNE table (`jonctions_jetons`) ; les noms de tables des
        // schémas antérieurs sont quand même écartés pour repartir d'un schéma neuf.
        $this->pdo->exec('DROP TABLE IF EXISTS jonctions_appairages');
        $this->pdo->exec('DROP TABLE IF EXISTS jonctions_mails_verification');
        $this->pdo->exec('DROP TABLE IF EXISTS jonctions_profils');
        $this->pdo->exec('DROP TABLE IF EXISTS jonctions_codes_appairage');
        $this->pdo->exec('DROP TABLE IF EXISTS jonctions_jetons');
        SchemaJonctions::installer($this->pdo);
        SchemaSecurite::installer($this->pdo);
        SchemaSocle::installer($this->pdo);
        SchemaIngestion::installer($this->pdo);
        // Travaux d'ingestion d'autres tests laissés en `recu` : sans ce nettoyage, le Pilote du test
        // d'origine CMM réserverait leur copie de travail (dossier temporaire déjà supprimé).
        $this->pdo->exec('DELETE FROM ingestion_travaux');

        $this->sel = bin2hex(random_bytes(5));
        $this->racine = sys_get_temp_dir() . '/gedv2-jonctions-' . $this->sel;
        $this->fonds = $this->racine . '/fonds';
        $this->travail = $this->racine . '/ingestion';
        $this->mailsDir = $this->racine . '/mails';
        mkdir($this->fonds, 0775, true);
        mkdir($this->travail, 0775, true);
        mkdir($this->mailsDir, 0775, true);

        $this->storageAvant = getenv('STORAGE_ROOT');
        $this->travailAvant = getenv('INGESTION_TRAVAIL');
        $this->mailsAvant = getenv('GED_MAIL_DOSSIER');
        $this->transportAvant = getenv('GED_MAIL_TRANSPORT');
        $this->debitAvant = getenv('API_DEBIT_DOSSIER');
        putenv('STORAGE_ROOT=' . $this->fonds);
        putenv('INGESTION_TRAVAIL=' . $this->travail);
        putenv('GED_MAIL_DOSSIER=' . $this->mailsDir);
        putenv('GED_MAIL_TRANSPORT=fichier');
        // Le compteur de débit de l'API documents vit dans le dossier éphémère du test, jamais dans var/.
        putenv('API_DEBIT_DOSSIER=' . $this->racine . '/debit');
    }

    protected function tearDownJonctions(): void
    {
        $this->storageAvant === false ? putenv('STORAGE_ROOT') : putenv('STORAGE_ROOT=' . $this->storageAvant);
        $this->travailAvant === false ? putenv('INGESTION_TRAVAIL') : putenv('INGESTION_TRAVAIL=' . $this->travailAvant);
        $this->mailsAvant === false ? putenv('GED_MAIL_DOSSIER') : putenv('GED_MAIL_DOSSIER=' . $this->mailsAvant);
        $this->transportAvant === false ? putenv('GED_MAIL_TRANSPORT') : putenv('GED_MAIL_TRANSPORT=' . $this->transportAvant);
        $this->debitAvant === false ? putenv('API_DEBIT_DOSSIER') : putenv('API_DEBIT_DOSSIER=' . $this->debitAvant);

        $this->pdo->exec('DELETE FROM jonctions_jetons');
        $this->pdo->prepare('DELETE FROM ingestion_travaux WHERE chemin LIKE ?')->execute([$this->racine . '%']);

        // Documents déposés par ce test : chemins uniques au test, on les retire de l'index partagé.
        $prefixe = 'Clients-' . $this->sel . '%';
        $ids = $this->pdo->prepare('SELECT id FROM documents WHERE chemin_relatif LIKE ? OR chemin_relatif LIKE ?');
        $ids->execute([$prefixe, 'Confidentiel-' . $this->sel . '%']);
        foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $this->pdo->exec('DELETE FROM document_versions WHERE document_id = ' . (int) $id);
            $this->pdo->exec('DELETE FROM document_deplacements WHERE document_id = ' . (int) $id);
            $this->pdo->exec('DELETE FROM recherche_textes WHERE document_id = ' . (int) $id);
        }
        $this->pdo->prepare('DELETE FROM documents WHERE chemin_relatif LIKE ? OR chemin_relatif LIKE ?')
            ->execute([$prefixe, 'Confidentiel-' . $this->sel . '%']);
        $this->pdo->prepare('DELETE FROM documents WHERE meta LIKE ? OR meta LIKE ?')
            ->execute(['%' . $this->sel . '%', '%' . $this->sel . '%']);

        $this->pdo->prepare('DELETE FROM securite_dossiers_droits WHERE groupe_id IN (SELECT id FROM securite_groupes WHERE nom LIKE ?)')
            ->execute(['jonctions-' . $this->sel . '%']);
        $this->pdo->prepare('DELETE FROM securite_groupes_comptes WHERE groupe_id IN (SELECT id FROM securite_groupes WHERE nom LIKE ?)')
            ->execute(['jonctions-' . $this->sel . '%']);
        $this->pdo->prepare('DELETE FROM securite_dossiers WHERE chemin_relatif LIKE ? OR chemin_relatif LIKE ?')
            ->execute(['Clients-' . $this->sel . '%', 'Confidentiel-' . $this->sel . '%']);
        $this->pdo->prepare('DELETE FROM securite_groupes WHERE nom LIKE ?')->execute(['jonctions-' . $this->sel . '%']);
        $this->pdo->prepare('DELETE FROM comptes WHERE identifiant LIKE ?')->execute(['cmm-' . $this->sel . '%']);

        $this->supprimer($this->racine);
    }

    protected function config(): Config
    {
        return new Config(dirname(__DIR__, 3));
    }

    /** Service de courrier du test : transport « fichier », dossier éphémère (jamais d'envoi réel). */
    protected function mailer(): Mailer
    {
        return Mailer::depuisConfig($this->config());
    }

    protected function profil(): Profil
    {
        return new Profil($this->pdo, $this->mailer());
    }

    protected function service(string $gedNom = 'GED de test'): Appairage
    {
        return new Appairage(
            new Jetons($this->pdo),
            $this->pdo,
            new Dossiers($this->pdo, new DroitsDossiers($this->pdo)),
            $this->profil(),
            $this->mailer(),
            $gedNom,
        );
    }

    /**
     * Un compte réel avec un groupe « contributeur » et deux dossiers ACL : `Clients-<sel>` (écriture)
     * et `Confidentiel-<sel>` (refus explicite). Rend compte, identifiant et chemins.
     *
     * @return array{compte:int, identifiant:string, ecriture:string, enfant:string, refus:string}
     */
    protected function compteEtDossiers(): array
    {
        $identifiant = 'cmm-' . $this->sel . '@exemple.test';
        $this->pdo->prepare('INSERT INTO comptes (identifiant, empreinte, role) VALUES (?, ?, ?)')
            ->execute([$identifiant, password_hash('secret-' . $this->sel, PASSWORD_DEFAULT), 'contributeur']);
        $compte = (int) $this->pdo->lastInsertId();

        $droits = new DroitsDossiers($this->pdo);
        $groupe = $droits->creerGroupe('jonctions-' . $this->sel, 'contributeur');
        $droits->ajouterCompteAuGroupe($compte, $groupe);

        $ecriture = 'Clients-' . $this->sel;
        $enfant = $ecriture . '/Prives';
        $refus = 'Confidentiel-' . $this->sel;
        $droits->poserDossier($groupe * 10 + 1, null, $ecriture);
        $droits->poserDossier($groupe * 10 + 2, $groupe * 10 + 1, $enfant);
        $droits->poserDossier($groupe * 10 + 3, null, $refus);
        $droits->poserRegle($groupe * 10 + 1, $groupe, 'modifier', true);
        $droits->poserRegle($groupe * 10 + 3, $groupe, 'modifier', false);

        return ['compte' => $compte, 'identifiant' => $identifiant, 'ecriture' => $ecriture, 'enfant' => $enfant, 'refus' => $refus];
    }

    /** Jeton d'appairage lié à un compte, valeur en clair (jamais relue après). */
    protected function jetonAppairage(int $compte, string $identifiant, string $poste = 'poste-test'): string
    {
        return (new Jetons($this->pdo))->creerAppairage('cmm', $poste, $compte, $identifiant);
    }

    /**
     * Jeton de l'API documents (lot ged-api-documents) : LIÉ À UN COMPTE, portée lecture ou écriture.
     * Valeur en clair rendue une seule fois.
     */
    protected function jetonDocuments(int $compte, string $identifiant, string $portee = 'documents_lecture'): string
    {
        return (new Jetons($this->pdo))->creerPourCompte('api-documents', $portee, $compte, $identifiant);
    }

    /**
     * Rend le profil du compte VÉRIFIÉ et son adresse : écrit l'adresse, suit le lien du courriel de
     * vérification. Évite de répéter le cérémonial dans chaque oracle.
     */
    protected function profilVerifie(?int $compteId, string $identifiant, string $adresse): Profil
    {
        $profil = $this->profil();
        $profil->definirAdresse($compteId, $identifiant, $adresse);
        $jeton = $this->lienDe($this->dernierMail(), '#/profil/mail/verifier\?jeton=([A-Za-z0-9_-]+)#');
        self::assertSame($adresse, $profil->verifier($jeton));

        return $profil;
    }

    /** Écrit une pièce dans le dossier temporaire du test ; nom d'origine donné à l'appelant. */
    protected function piece(string $nom, string $contenu): string
    {
        $chemin = $this->racine . '/' . bin2hex(random_bytes(4)) . '-' . $nom;
        file_put_contents($chemin, $contenu);

        return $chemin;
    }

    /**
     * Courriels écrits par le transport « fichier », du plus ancien au plus récent.
     *
     * @return list<array{fichier:string, contenu:string}>
     */
    protected function mails(): array
    {
        $fichiers = glob($this->mailsDir . '/*.eml') ?: [];
        usort($fichiers, static fn (string $a, string $b): int => (filemtime($a) <=> filemtime($b)) ?: strcmp($a, $b));

        return array_map(static fn (string $f): array => ['fichier' => $f, 'contenu' => (string) file_get_contents($f)], $fichiers);
    }

    /** Le dernier courriel écrit (celui de l'action qu'on vient de jouer). */
    protected function dernierMail(): string
    {
        $mails = $this->mails();
        self::assertNotEmpty($mails, 'aucun courriel écrit par le transport fichier de test');

        return $mails[array_key_last($mails)]['contenu'];
    }

    /** Le premier courriel contenant une chaîne donnée (utile quand plusieurs sont écrits d'affilée). */
    protected function mailContenant(string $motif): string
    {
        foreach ($this->mails() as $mail) {
            if (str_contains($mail['contenu'], $motif)) {
                return $mail['contenu'];
            }
        }
        self::fail("aucun courriel ne contient « $motif »");
    }

    /**
     * Décode une pièce jointe JSON d'un `.eml` (même lecteur que Courriel : zbateson/mail-mime-parser).
     *
     * @return array<string, mixed>
     */
    protected function pieceJson(string $eml, string $nom): array
    {
        $message = Message::from($eml, false);
        foreach ($message->getAllAttachmentParts() as $partie) {
            if (trim((string) $partie->getFilename()) === $nom) {
                return json_decode((string) $partie->getContent(), true, 512, JSON_THROW_ON_ERROR);
            }
        }
        self::fail("pièce $nom absente du courriel");
    }

    /** Extrait le premier groupe d'une expression régulière du corps texte d'un `.eml`. */
    protected function lienDe(string $eml, string $motif): string
    {
        $message = Message::from($eml, false);
        $texte = (string) $message->getTextContent();
        if (preg_match($motif, $texte, $trouve) !== 1) {
            self::fail('lien introuvable dans le courriel');
        }

        return $trouve[1];
    }

    /**
     * Envoie un dépôt multipart et rend [statut, corps JSON].
     *
     * @param array<string, mixed> $champs
     * @return array{int, array<string, mixed>}
     */
    protected function envoyer(App $app, string $jeton, UploadedFileInterface $fichier, array $champs = []): array
    {
        $requete = (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/depots')
            ->withUploadedFiles(['fichier' => $fichier]);
        if ($jeton !== '') {
            $requete = $requete->withHeader('Authorization', 'Bearer ' . $jeton);
        }
        if ($champs !== []) {
            $requete = $requete->withParsedBody($champs);
        }
        $reponse = $app->handle($requete);

        return [$reponse->getStatusCode(), json_decode((string) $reponse->getBody(), true, 512, JSON_THROW_ON_ERROR) ?: []];
    }

    /** Une requête JSON jouée rôle de CMM (hors session). @return array{int, array<string,mixed>} */
    protected function appel(App $app, string $methode, string $chemin, ?string $jeton = null, ?array $corps = null): array
    {
        $requete = (new ServerRequestFactory())->createServerRequest($methode, $chemin);
        if ($jeton !== null) {
            $requete = $requete->withHeader('Authorization', 'Bearer ' . $jeton);
        }
        if ($corps !== null) {
            $requete = $requete->withParsedBody($corps);
        }
        $reponse = $app->handle($requete);

        return [$reponse->getStatusCode(), json_decode((string) $reponse->getBody(), true, 512, JSON_THROW_ON_ERROR) ?: []];
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
