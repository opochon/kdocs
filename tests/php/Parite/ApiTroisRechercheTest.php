<?php

declare(strict_types=1);

namespace Ged\Tests\Parite;

use Ged\Kernel;
use Ged\Socle\DepotFichiers;
use Ged\Tests\Jonctions\SupportJonctions;
use PHPUnit\Framework\TestCase;

/**
 * Famille « recherche et suivi » du lot ged-parite-api-3.
 *
 * Vecteurs extraits en LECTURE SEULE de test_api_tasks.py et test_api_trash.py (Paperless-ngx v2.20.5) :
 * liste des tâches, corbeille (liste, restauration, purge). Côté GEDv2, `GET /api/v1/taches` lit le suivi
 * réel (`Socle\Taches`, complété par `ingestion_travaux`), et `GET/POST /api/v1/corbeille` RESTAURE un
 * document sans jamais le purger : la règle produit « zéro suppression » refuse `empty` par un 409 nommé.
 * Cet écart assumé est mesuré séparément des échecs.
 */
final class ApiTroisRechercheTest extends TestCase
{
    use SupportJonctions;

    protected function setUp(): void
    {
        $this->setUpJonctions();
    }

    protected function tearDown(): void
    {
        $this->pdo->exec("DELETE FROM comptes WHERE identifiant LIKE 'api3-%'");
        $this->tearDownJonctions();
    }

    public function testRejoueLesVecteursDeTachesEtDeCorbeille(): void
    {
        $donnees = Vecteurs::charger('api-3-recherche');
        $app = Kernel::creer(dirname(__DIR__, 3));
        $compte = $this->compteEtDossiers();
        $admin = $this->compteAdministrateur();
        $jeton = $this->jetonDocuments($admin['compte'], $admin['identifiant'], 'documents_ecriture');
        $document = $this->deposer($compte['ecriture'], 'Pièce corbeille');
        $depot = new DepotFichiers($this->config());

        // La purge définitive (`empty`) est interdite par la règle « zéro suppression » : le vecteur
        // Paperless attend 200, GEDv2 répond 409 nommé. Écart ASSUMÉ, mesuré comme tel.
        $divergences = [
            'recherche:test_api_trash:005' => 409,
            'recherche:test_trash_api_empty_all:006' => 409,
        ];

        $passes = 0;
        $assumes = 0;
        $echecs = [];
        foreach ($donnees['vecteurs'] as $vecteur) {
            $id = (string) ($vecteur['id'] ?? '?');
            // Mise en scène fidèle au test Paperless : le document est mis à la corbeille juste avant
            // les gestes de restauration/purge, et remis dans le fonds quand le vecteur l'exige.
            if (in_array($id, ['recherche:test_api_trash:002', 'recherche:test_api_trash:003',
                'recherche:test_api_trash:004', 'recherche:test_api_trash:005'], true)) {
                $depot->miseALaCorbeille($document, 'test');
            }
            if ($id === 'recherche:test_api_trash_invalid_params:007') {
                // Le document doit être HORS corbeille pour que la restauration soit refusée (400).
                try {
                    $depot->restaurer($document);
                } catch (\RuntimeException) {
                    // Déjà dans le fonds : rien à faire.
                }
            }
            [$methode, $chemin, $corps] = $this->traduire($vecteur, $document);
            [$statut] = $this->appel($app, $methode, $chemin, $jeton, $corps);
            $attendu = (int) ($vecteur['sortie']['statut'] ?? 0);
            if ($statut === $attendu) {
                ++$passes;
            } elseif (isset($divergences[$id]) && $statut === $divergences[$id]) {
                ++$assumes;
            } else {
                $echecs[] = sprintf('%s (%s) : attendu %d, obtenu %d', $id,
                    (string) ($vecteur['reference'] ?? ''), $attendu, $statut);
            }
        }

        Vecteurs::ecrireApi('3-recherche', [
            'version' => 1,
            'reference' => $donnees['reference'] ?? [],
            'categorie' => '3-recherche',
            'total' => count($donnees['vecteurs']),
            'passes' => $passes,
            'ecarts_assumes' => $assumes,
            'ecarts' => $echecs,
            'non_rejouables' => count($donnees['non_rejouables'] ?? []),
        ]);
        self::assertSame([], $echecs, "vecteurs de recherche en écart :\n" . implode("\n", $echecs));
        self::assertSame(2, $assumes, 'les deux gestes de purge sont refusés par la règle zéro-suppression');

        // Non-accès : sans jeton, la corbeille refuse et se nomme.
        self::assertSame(401, $this->appel($app, 'GET', '/api/v1/corbeille')[0]);
    }

    /** @param array<string, mixed> $vecteur @return array{0:string, 1:string, 2:array<string, mixed>} */
    private function traduire(array $vecteur, int $document): array
    {
        $entree = is_array($vecteur['entree'] ?? null) ? $vecteur['entree'] : [];
        $chemin = (string) ($entree['chemin'] ?? '');
        if ($chemin === '/api/tasks/') {
            return ['GET', '/api/v1/taches', []];
        }
        if ($chemin === '/api/trash/') {
            if (($entree['methode'] ?? '') === 'GET') {
                return ['GET', '/api/v1/corbeille', []];
            }
            $corps = is_array($entree['corps'] ?? null) ? $entree['corps'] : [];
            $action = (string) ($corps['action'] ?? 'restore');
            $documents = [];
            foreach (is_array($corps['documents'] ?? null) ? $corps['documents'] : [] as $reference) {
                $documents[] = str_contains((string) $reference, 'document') ? $document : (int) $reference;
            }

            return ['POST', '/api/v1/corbeille', ['action' => $action, 'documents' => $documents]];
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
