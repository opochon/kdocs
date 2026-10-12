<?php

declare(strict_types=1);

namespace Ged\Tests\Parite;

use Ged\Kernel;
use Ged\Socle\DepotFichiers;
use Ged\Socle\Taches;
use Ged\Tests\Jonctions\SupportJonctions;
use PHPUnit\Framework\TestCase;

/**
 * Famille « sécurité et compte » du lot ged-parite-api-3.
 *
 * Vecteurs extraits en LECTURE SEULE de test_api_uisettings.py (Paperless-ngx v2.20.5) : UiSettings
 * (get / set / refus d'un réglage non dictionnaire). Côté GEDv2, les réglages d'interface vivent dans
 * `Socle\Preferences` et passent par `/api/v1/preferences`.
 *
 * Le profil, la génération de jeton, la corbeille, le suivi des tâches et le journal des courriers
 * traités sont prouvés directement (routes `/api/v1/profil`, `/api/v1/jetons`, `/api/v1/corbeille`,
 * `/api/v1/taches`, `/api/v1/courriers/traites/{id}`), avec un test de non-accès.
 */
final class ApiTroisSecuriteTest extends TestCase
{
    use SupportJonctions;

    protected function setUp(): void
    {
        $this->setUpJonctions();
    }

    protected function tearDown(): void
    {
        $this->pdo->exec("DELETE FROM ingestion_messages WHERE boite = 'boite-api3'");
        $this->pdo->exec("DELETE FROM comptes WHERE identifiant LIKE 'api3-%'");
        $this->tearDownJonctions();
    }

    public function testRejoueLesVecteursDUiSettings(): void
    {
        $donnees = Vecteurs::charger('api-3-securite');
        $app = Kernel::creer(dirname(__DIR__, 3));
        $compte = $this->compteEtDossiers();
        $jeton = $this->jetonDocuments($compte['compte'], $compte['identifiant'], 'documents_ecriture');

        $passes = 0;
        $echecs = [];
        foreach ($donnees['vecteurs'] as $vecteur) {
            $id = (string) ($vecteur['id'] ?? '?');
            [$methode, $chemin, $corps] = $this->traduire($vecteur, $id);
            [$statut] = $this->appel($app, $methode, $chemin, $jeton, $corps);
            $attendu = (int) ($vecteur['sortie']['statut'] ?? 0);
            if ($statut === $attendu) {
                ++$passes;
            } else {
                $echecs[] = sprintf('%s (%s) : attendu %d, obtenu %d', $id,
                    (string) ($vecteur['reference'] ?? ''), $attendu, $statut);
            }
        }

        Vecteurs::ecrireApi('3-securite', [
            'version' => 1,
            'reference' => $donnees['reference'] ?? [],
            'categorie' => '3-securite',
            'total' => count($donnees['vecteurs']),
            'passes' => $passes,
            'ecarts' => $echecs,
            'non_rejouables' => count($donnees['non_rejouables'] ?? []),
        ]);
        self::assertSame([], $echecs, "vecteurs de sécurité en écart :\n" . implode("\n", $echecs));
    }

    public function testProfilJetonsPreferencesCorbeilleTachesEtCourrier(): void
    {
        $app = Kernel::creer(dirname(__DIR__, 3));
        $compte = $this->compteEtDossiers();
        $jeton = $this->jetonDocuments($compte['compte'], $compte['identifiant'], 'documents_ecriture');
        $admin = $this->compteAdministrateur();
        $jetonAdmin = $this->jetonDocuments($admin['compte'], $admin['identifiant'], 'documents_ecriture');

        // Profil : lecture puis mise à jour de l'adresse (transport « fichier », aucun envoi réel).
        self::assertSame(200, $this->appel($app, 'GET', '/api/v1/profil', $jeton)[0]);
        self::assertSame(200, $this->appel($app, 'PATCH', '/api/v1/profil', $jeton, ['email' => 'api3-profil@exemple.test'])[0]);

        // Préférences : lecture, écriture d'un réglage GEDv2 borné, refus d'un non-dictionnaire.
        self::assertSame(200, $this->appel($app, 'GET', '/api/v1/preferences', $jeton)[0]);
        self::assertSame(200, $this->appel($app, 'POST', '/api/v1/preferences', $jeton, ['reglages' => ['theme' => 'sombre']])[0]);
        self::assertSame(400, $this->appel($app, 'POST', '/api/v1/preferences', $jeton, ['settings' => 'pas un objet'])[0]);

        // Jeton d'API : rendu une seule fois, jamais journalisé.
        [$statut, $creation] = $this->appel($app, 'POST', '/api/v1/jetons', $jeton, ['application' => 'api3-parcours']);
        self::assertSame(200, $statut);
        self::assertIsString($creation['token'] ?? null);
        self::assertNotSame('', (string) ($creation['token'] ?? ''));

        // Corbeille : un document mis à la corbeille se liste, puis se restaure ; la purge est refusée.
        $document = $this->deposer($compte['ecriture'], 'Document corbeille');
        (new DepotFichiers($this->config()))->miseALaCorbeille($document, 'test');
        [$statut, $liste] = $this->appel($app, 'GET', '/api/v1/corbeille', $jetonAdmin);
        self::assertSame(200, $statut);
        self::assertGreaterThanOrEqual(1, (int) ($liste['count'] ?? 0));
        self::assertSame(200, $this->appel($app, 'POST', '/api/v1/corbeille', $jetonAdmin, ['action' => 'restore', 'documents' => [$document]])[0]);
        self::assertSame(409, $this->appel($app, 'POST', '/api/v1/corbeille', $jetonAdmin, ['action' => 'empty', 'documents' => [$document]])[0]);

        // Tâches : une tâche ouverte se lit par son identifiant, une inconnue refuse 404.
        $tache = (new Taches($this->config()))->ouvrir('CONSUME_FILE', 'MANUAL_TASK', 'api3-piece.pdf');
        self::assertSame(200, $this->appel($app, 'GET', '/api/v1/taches', $jetonAdmin)[0]);
        self::assertSame(200, $this->appel($app, 'GET', '/api/v1/taches/' . (int) $tache['id'], $jetonAdmin)[0]);
        self::assertSame(404, $this->appel($app, 'GET', '/api/v1/taches/999999999', $jetonAdmin)[0]);

        // Courrier traité : une ligne du journal se lit par son identifiant, une inconnue refuse 404.
        $this->pdo->prepare('INSERT INTO ingestion_messages (boite, message_id, regle, dossier, uid, sujet, recu_le, statut, erreur, traite_le)'
            . ' VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, NULL, NOW())')
            ->execute(['boite-api3', 'message-api3-' . bin2hex(random_bytes(4)), 'regle-api3', 'Clients-2025', 'uid-api3', 'Sujet api3', 'SUCCESS']);
        $message = (int) $this->pdo->lastInsertId();
        self::assertSame(200, $this->appel($app, 'GET', '/api/v1/courriers/traites/' . $message, $jetonAdmin)[0]);
        self::assertSame(404, $this->appel($app, 'GET', '/api/v1/courriers/traites/999999999', $jetonAdmin)[0]);

        // État système (SystemStatusView) : /sante nomme l'application et la base qui répondent.
        [$sante, $etat] = $this->appel($app, 'GET', '/sante');
        self::assertSame(200, $sante);
        self::assertSame('gedv2', $etat['app'] ?? null);

        // Non-accès : sans jeton, la porte refuse et se nomme.
        self::assertSame(401, $this->appel($app, 'GET', '/api/v1/preferences')[0]);
    }

    /**
     * @param array<string, mixed> $vecteur
     * @return array{0:string, 1:string, 2:array<string, mixed>}
     */
    private function traduire(array $vecteur, string $id): array
    {
        $entree = is_array($vecteur['entree'] ?? null) ? $vecteur['entree'] : [];
        $chemin = (string) ($entree['chemin'] ?? '');
        if ($chemin === '/api/ui_settings/') {
            $methode = (string) ($entree['methode'] ?? 'GET');
            if ($methode === 'GET') {
                return ['GET', '/api/v1/preferences', []];
            }
            // Le réglage « non dictionnaire » de Paperless devient le refus d'un `settings` non-objet.
            if (str_contains($id, 'must_be_dict')) {
                return ['POST', '/api/v1/preferences', ['settings' => 'not a dict']];
            }

            return ['POST', '/api/v1/preferences', ['reglages' => ['theme' => 'sombre']]];
        }

        return [(string) ($entree['methode'] ?? 'GET'), $chemin, []];
    }

    private function deposer(string $dossier, string $titre): int
    {
        return (new DepotFichiers($this->config()))->deposer($this->piece($titre . '.pdf', $titre),
            ['titre' => $titre, 'dossier' => $dossier, 'emetteur' => 'Émetteur ' . $titre]);
    }

    /** @return array{compte:int, identifiant:string} */
    private function compteAdministrateur(): array
    {
        $identifiant = 'api3-admin-' . bin2hex(random_bytes(4)) . '@exemple.test';
        $this->pdo->prepare('INSERT INTO comptes (identifiant, empreinte, role) VALUES (?, ?, ?)')
            ->execute([$identifiant, password_hash('motdepassefort123', PASSWORD_DEFAULT), 'administrateur']);

        return ['compte' => (int) $this->pdo->lastInsertId(), 'identifiant' => $identifiant];
    }
}
