<?php

declare(strict_types=1);

namespace Ged\Tests\Parite;

use Ged\Kernel;
use Ged\Socle\Registre;
use Ged\Socle\Taches;
use Ged\Tests\Jonctions\SupportJonctions;
use PHPUnit\Framework\TestCase;

/**
 * Famille « tâches » du lot ged-parite-api-4.
 *
 * Vecteurs extraits en LECTURE SEULE de `test_api_tasks.py` (Paperless-ngx v2.20.5) : liste, filtre,
 * acquittement et lancement d'une tâche. Côté GEDv2, `GET /api/v1/taches` lit le suivi réel
 * (`Socle\Taches`, complété par `ingestion_travaux`, sans Celery) ; `POST /api/v1/taches/acknowledge`
 * acquitte, `POST /api/v1/taches/run` programme une tâche de maintenance connue. Les filtres
 * `PaperlessTaskFilterSet` (task_id, status, task_name, type, acknowledged) sont rejoués comme autant
 * d'entrées de la même route.
 */
final class ApiQuatreTachesTest extends TestCase
{
    use SupportJonctions;

    protected function setUp(): void
    {
        $this->setUpJonctions();
        Registre::oublier();
    }

    protected function tearDown(): void
    {
        $this->pdo->exec("DELETE FROM comptes WHERE identifiant LIKE 'api4-%'");
        $this->tearDownJonctions();
    }

    public function testRejoueLesVecteursDeTachesEtLeursFiltres(): void
    {
        $donnees = Vecteurs::charger('api-4-taches');
        $app = Kernel::creer(dirname(__DIR__, 3));
        $admin = $this->compteAdministrateurApi4();
        $jetonAdmin = $this->jetonDocuments($admin['compte'], $admin['identifiant'], 'documents_ecriture');
        $contributeur = $this->compteEtDossiers();
        $jetonContributeur = $this->jetonDocuments($contributeur['compte'], $contributeur['identifiant'], 'documents_ecriture');

        // Le rejeu de `run/` sur une erreur simulée n'a pas de sens hors mock Celery : GEDv2 réussit
        // toujours une tâche connue. Écart ASSUMÉ, mesuré comme tel (jamais compté comme échec).
        $divergences = ['taches:test_run_train_classifier_task:007' => 200];

        $passes = 0;
        $assumes = 0;
        $echecs = [];
        foreach ($donnees['vecteurs'] as $vecteur) {
            $id = (string) ($vecteur['id'] ?? '?');
            [$methode, $chemin, $corps, $jeton] = $this->traduire($vecteur, $id, $jetonAdmin, $jetonContributeur);
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

        Vecteurs::ecrireApi('4-taches', [
            'version' => 1,
            'reference' => $donnees['reference'] ?? [],
            'categorie' => '4-taches',
            'total' => count($donnees['vecteurs']),
            'passes' => $passes,
            'ecarts_assumes' => $assumes,
            'ecarts' => $echecs,
            'non_rejouables' => count($donnees['non_rejouables'] ?? []),
        ]);
        self::assertSame([], $echecs, "vecteurs de tâches en écart :\n" . implode("\n", $echecs));
        self::assertSame(1, $assumes, 'le run sur erreur simulée est mesuré comme écart assumé');

        // Filtres PaperlessTaskFilterSet : la route les honore réellement.
        $tache = (new Taches($this->config()))->ouvrir('CONSUME_FILE', 'MANUAL_TASK', 'filtre.pdf', 'api4');
        [, $liste] = $this->appel($app, 'GET', '/api/v1/taches?task_name=CONSUME_FILE&status=PENDING', $jetonAdmin);
        self::assertGreaterThanOrEqual(1, $liste['count'], 'filtre task_name + status');
        foreach ($liste['results'] as $ligne) {
            self::assertSame('CONSUME_FILE', $ligne['task_name']);
            self::assertSame('PENDING', $ligne['status']);
        }
        [, $parId] = $this->appel($app, 'GET', '/api/v1/taches?task_id=' . $tache['task_id'], $jetonAdmin);
        self::assertSame($tache['task_id'], $parId['results'][0]['task_id'] ?? null, 'filtre task_id');
        [, $parType] = $this->appel($app, 'GET', '/api/v1/taches?type=MANUAL_TASK&acknowledged=false', $jetonAdmin);
        self::assertGreaterThanOrEqual(1, $parType['count'], 'filtre type + acknowledged=false');
        foreach ($parType['results'] as $ligne) {
            self::assertSame('MANUAL_TASK', $ligne['type']);
            self::assertFalse($ligne['acknowledged']);
        }

        // Non-accès : sans jeton, la liste refuse et se nomme.
        self::assertSame(401, $this->appel($app, 'GET', '/api/v1/taches')[0]);
    }

    /** @param array<string, mixed> $vecteur @return array{0:string, 1:string, 2:array<string, mixed>|null, 3:?string} */
    private function traduire(array $vecteur, string $id, string $jetonAdmin, string $jetonContributeur): array
    {
        $entree = is_array($vecteur['entree'] ?? null) ? $vecteur['entree'] : [];
        $chemin = (string) ($entree['chemin'] ?? '');
        $methode = (string) ($entree['methode'] ?? 'GET');
        $corps = is_array($entree['corps'] ?? null) ? $entree['corps'] : null;

        if ($chemin === '/api/tasks/') {
            return ['GET', '/api/v1/taches', null, $jetonAdmin];
        }
        if ($chemin === '/api/tasks/acknowledge/') {
            $demandeParticuliere = str_contains($id, 'requires_change_permission');
            $tache = (new Taches($this->config()))->ouvrir('CONSUME_FILE', 'MANUAL_TASK', 'ack-' . $id . '.pdf', 'api4');

            return ['POST', '/api/v1/taches/acknowledge', ['tasks' => [$tache['id']]],
                $demandeParticuliere && str_ends_with($id, '004') ? $jetonContributeur : $jetonAdmin];
        }
        if ($chemin === '/api/tasks/run/') {
            $nom = (string) ($corps['task_name'] ?? '');
            $nom = str_contains($nom, 'CHECK_SANITY') ? 'CHECK_SANITY' : 'TRAIN_CLASSIFIER';

            return ['POST', '/api/v1/taches/run', ['task_name' => $nom],
                str_contains($id, 'requires_superuser') ? $jetonContributeur : $jetonAdmin];
        }

        return [$methode, $chemin, $corps, $jetonAdmin];
    }

    /** @return array{compte:int, identifiant:string} */
    private function compteAdministrateurApi4(): array
    {
        $identifiant = 'api4-admin-' . bin2hex(random_bytes(4)) . '@exemple.test';
        $this->pdo->prepare('INSERT INTO comptes (identifiant, empreinte, role) VALUES (?, ?, ?)')
            ->execute([$identifiant, password_hash('motdepassefort123', PASSWORD_DEFAULT), 'administrateur']);

        return ['compte' => (int) $this->pdo->lastInsertId(), 'identifiant' => $identifiant];
    }
}
