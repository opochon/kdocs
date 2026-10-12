<?php

declare(strict_types=1);

namespace Ged\Socle;

use Ged\Config;
use PDO;
use RuntimeException;

/**
 * Suivi des tâches à la manière GEDv2 (modèle Paperless `PaperlessTask`, `TaskName`, `TaskType`).
 *
 * Paperless délègue chaque traitement à Celery et lit l'état dans Redis. GEDv2 n'a AUCUN démon : les
 * traitements restent des jobs courts, reprenables, et leur avancement est tracé dans le fonds
 * (`.ged/taches.json`, via `Registre`) — même philosophie que les vues et les champs : le suivi suit le
 * dossier, il survit à une reconstruction. Ce journal est en ajout seul : une tâche finie ou en échec reste
 * comme trace, jamais effacée.
 *
 * Le suivi COTÉ UTILISATEUR est attendu ; l'infrastructure Celery/Redis ne l'est pas (exclusion validée).
 * `depuisTravaux()` reflète en plus la file réelle de consommation (`ingestion_travaux`) pour que l'écran
 * montre ce qui tourne vraiment, sans deuxième file.
 */
final class Taches
{
    public const PENDING = 'PENDING';
    public const STARTED = 'STARTED';
    public const SUCCESS = 'SUCCESS';
    public const FAILURE = 'FAILURE';

    /** @var list<string> */
    public const STATUTS = [self::PENDING, self::STARTED, self::SUCCESS, self::FAILURE];

    /** Noms de tâches suivies (modèle Paperless `TaskName`). */
    public const NOMS = ['CONSUME_FILE', 'TRAIN_CLASSIFIER', 'CHECK_SANITY', 'INDEX_OPTIMIZE'];

    /** Types de tâches (modèle Paperless `TaskType`). */
    public const TYPES = ['MANUAL_TASK', 'SCHEDULED_TASK', 'AUTO'];

    private const REGISTRE = 'taches';

    private readonly Registre $registre;

    public function __construct(Config $config)
    {
        $this->registre = new Registre($config);
    }

    /**
     * Ouvre une tâche suivie (état PENDING, non acquittée).
     *
     * @return array<string, mixed>
     */
    public function ouvrir(string $nom, string $type, string $fichier, string $auteur = ''): array
    {
        // Paperless nomme ses tâches en minuscules (« consume_file ») ; GEDv2 garde la forme canonique
        // du modèle (CONSUME_FILE) tout en acceptant les deux écritures.
        $nom = mb_strtoupper(trim($nom));
        $type = mb_strtoupper(trim($type));
        if (!in_array($nom, self::NOMS, true)) {
            throw new RuntimeException("tâche : nom inconnu « $nom » (" . implode(', ', self::NOMS) . ')');
        }
        if (!in_array($type, self::TYPES, true)) {
            throw new RuntimeException("tâche : type inconnu « $type » (" . implode(', ', self::TYPES) . ')');
        }
        $fichier = trim($fichier);
        if ($fichier === '') {
            throw new RuntimeException('tâche : le nom du fichier suivi manque');
        }

        $maintenant = date('c');
        $id = 0;
        $this->registre->modifier(self::REGISTRE, static function (array $etat) use ($nom, $type, $fichier, $auteur, $maintenant, &$id): array {
            $taches = isset($etat['taches']) && is_array($etat['taches']) ? $etat['taches'] : [];
            $id = self::prochainId($taches);
            $taches[] = [
                'id' => $id,
                'task_id' => bin2hex(random_bytes(16)),
                'task_name' => $nom,
                'task_file_name' => $fichier,
                'type' => $type,
                'status' => self::PENDING,
                'acknowledged' => false,
                'date_created' => $maintenant,
                'date_started' => null,
                'date_done' => null,
                'result' => null,
                'auteur' => $auteur,
            ];

            return ['taches' => $taches];
        });

        $tache = $this->etat($id);
        if ($tache === null) {
            throw new RuntimeException('tâche : création non relue');
        }

        return $tache;
    }

    public function demarrer(int $id): void
    {
        $this->changer($id, self::STARTED, ['date_started' => date('c')]);
    }

    public function terminer(int $id, string $resultat): void
    {
        $this->changer($id, self::SUCCESS, ['date_done' => date('c'), 'result' => $resultat]);
    }

    public function echouer(int $id, string $erreur): void
    {
        $this->changer($id, self::FAILURE, ['date_done' => date('c'), 'result' => $erreur]);
    }

    /** Marque une tâche comme acquittée : l'utilisateur a vu son issue, la trace reste. */
    public function accuser(int $id, bool $acquittee = true): void
    {
        $this->changer($id, null, ['acknowledged' => $acquittee]);
    }

    /**
     * @param array<string, mixed> $champs
     */
    private function changer(int $id, ?string $statut, array $champs): void
    {
        $trouvee = false;
        $this->registre->modifier(self::REGISTRE, static function (array $etat) use ($id, $statut, $champs, &$trouvee): array {
            $taches = isset($etat['taches']) && is_array($etat['taches']) ? $etat['taches'] : [];
            foreach ($taches as &$t) {
                $t = (array) $t;
                if ((int) ($t['id'] ?? 0) !== $id) {
                    continue;
                }
                $trouvee = true;
                if ($statut !== null) {
                    $t['status'] = $statut;
                }
                foreach ($champs as $cle => $valeur) {
                    $t[$cle] = $valeur;
                }
            }

            return ['taches' => $taches];
        });
        if (!$trouvee) {
            throw new RuntimeException("tâche : aucune tâche nº $id");
        }
    }

    /** @return array<string, mixed>|null */
    public function etat(int $id): ?array
    {
        foreach ($this->brutes() as $t) {
            if ((int) ($t['id'] ?? 0) === $id) {
                return self::normaliser($t);
            }
        }

        return null;
    }

    /**
     * Tâches les plus récentes d'abord (borné), acquittées comprises : le suivi n'oublie rien.
     *
     * @return list<array<string, mixed>>
     */
    public function lister(int $limite = 50): array
    {
        $taches = array_map(static fn (array $t): array => self::normaliser($t), $this->brutes());
        usort($taches, static fn (array $a, array $b): int => ((int) $b['id']) <=> ((int) $a['id']));

        return array_slice($taches, 0, max(1, $limite));
    }

    /** @return array{total:int, en_attente:int, en_cours:int, terminees:int, echouees:int, non_acquittees:int} */
    public function bilan(): array
    {
        $bilan = ['total' => 0, 'en_attente' => 0, 'en_cours' => 0, 'terminees' => 0, 'echouees' => 0, 'non_acquittees' => 0];
        foreach ($this->brutes() as $t) {
            $bilan['total']++;
            match ($t['status'] ?? self::PENDING) {
                self::STARTED => $bilan['en_cours']++,
                self::SUCCESS => $bilan['terminees']++,
                self::FAILURE => $bilan['echouees']++,
                default => $bilan['en_attente']++,
            };
            if (($t['acknowledged'] ?? false) !== true) {
                $bilan['non_acquittees']++;
            }
        }

        return $bilan;
    }

    /**
     * Suivi réel de la consommation (`ingestion_travaux`), projeté dans la même forme que les tâches
     * suivies. L'écran montre ainsi ce qui tourne VRAIMENT, sans file parallèle ; la table absente
     * (première seconde d'une installation) ne lève pas, elle rend une liste vide.
     *
     * @return list<array<string, mixed>>
     */
    public function depuisTravaux(PDO $pdo, int $limite = 50): array
    {
        try {
            $lignes = $pdo->query('SELECT id, nom_origine, etat, erreur, essais, parent_id, cree_le FROM ingestion_travaux ORDER BY id DESC LIMIT ' . max(1, $limite))->fetchAll();
        } catch (\PDOException) {
            return [];
        }
        $statut = ['recu' => self::PENDING, 'decoupe' => self::STARTED, 'pret' => self::PENDING,
            'range' => self::SUCCESS, 'erreur' => self::FAILURE];
        $taches = [];
        foreach ($lignes as $ligne) {
            $etat = (string) $ligne['etat'];
            $taches[] = [
                'task_id' => 'travail-' . (int) $ligne['id'],
                'task_name' => 'CONSUME_FILE',
                'task_file_name' => (string) $ligne['nom_origine'],
                'type' => 'AUTO',
                'status' => $statut[$etat] ?? self::PENDING,
                'acknowledged' => false,
                'date_created' => (string) $ligne['cree_le'],
                'date_started' => null,
                'date_done' => null,
                'result' => $ligne['erreur'] !== null ? (string) $ligne['erreur'] : $etat,
            ];
        }

        return $taches;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function brutes(): array
    {
        $brut = $this->registre->lire(self::REGISTRE);
        $taches = $brut['taches'] ?? [];
        if (!is_array($taches)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn (mixed $t): array => (array) $t, $taches), static fn (array $t): bool => isset($t['id'])));
    }

    /**
     * @param array<string, mixed> $t
     * @return array<string, mixed>
     */
    private static function normaliser(array $t): array
    {
        return [
            'id' => (int) ($t['id'] ?? 0),
            'task_id' => (string) ($t['task_id'] ?? ''),
            'task_name' => (string) ($t['task_name'] ?? ''),
            'task_file_name' => (string) ($t['task_file_name'] ?? ''),
            'type' => (string) ($t['type'] ?? ''),
            'status' => in_array($t['status'] ?? '', self::STATUTS, true) ? (string) $t['status'] : self::PENDING,
            'acknowledged' => ($t['acknowledged'] ?? false) === true,
            'date_created' => (string) ($t['date_created'] ?? ''),
            'date_started' => isset($t['date_started']) && is_string($t['date_started']) ? $t['date_started'] : null,
            'date_done' => isset($t['date_done']) && is_string($t['date_done']) ? $t['date_done'] : null,
            'result' => isset($t['result']) && is_scalar($t['result']) ? (string) $t['result'] : null,
            'auteur' => (string) ($t['auteur'] ?? ''),
        ];
    }

    /**
     * @param list<array<string, mixed>> $taches
     */
    private static function prochainId(array $taches): int
    {
        $max = 0;
        foreach ($taches as $t) {
            $max = max($max, (int) (((array) $t)['id'] ?? 0));
        }

        return $max + 1;
    }
}
