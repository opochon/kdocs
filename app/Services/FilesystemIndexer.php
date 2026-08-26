<?php
namespace KDocs\Services;
use KDocs\Core\Database;
use KDocs\Core\Config;
use KDocs\Services\Storage\InternalFolderRegistry;

class FilesystemIndexer
{
    private string $basePath;
    private array $allowedExtensions;
    private array $ignoreFolders;
    private $db;
    private string $progressFile;
    private int $totalItems = 0;
    private int $processedItems = 0;

    public function __construct()
    {
        $config = Config::load();
        $storageConfig = $config['storage'] ?? [];
        $basePath = Config::get('storage.base_path', __DIR__ . '/../../storage/documents');
        $resolved = realpath($basePath);
        $this->basePath = rtrim($resolved ?: $basePath, '/\\');
        $extensions = $storageConfig['allowed_extensions'] ?? ['pdf', 'jpg', 'jpeg', 'png', 'tiff', 'tif', 'doc', 'docx'];
        $this->allowedExtensions = is_array($extensions) ? $extensions : array_map('trim', explode(',', $extensions));
        $ignoreFolders = $storageConfig['ignore_folders'] ?? ['.git', 'node_modules', 'vendor', '__MACOSX', 'Thumbs.db'];
        $ignoreFolders = is_array($ignoreFolders) ? $ignoreFolders : array_map('trim', explode(',', $ignoreFolders));

        // .versions est exclu EN DUR, jamais par configuration : l'indexer
        // reviendrait a indexer les archives comme des documents, puis a
        // versionner les archives. La croissance serait sans fin.
        if (!in_array('.versions', $ignoreFolders, true)) {
            $ignoreFolders[] = '.versions';
        }

        // InternalFolderRegistry = source de vérité unique des dossiers
        // pipeline (consume, pending, toclassify, ...). L'arbre utilisateur
        // l'applique déjà par NOM ; sans elle ici, une indexation complète
        // re-importait les pièces splittées (storage/documents/pending/) en
        // lignes « documents » de plus : doublons par fichier physique,
        // compteurs gonflés, dossier surveillé qui apparaît (S2, 2026-08-25).
        foreach (InternalFolderRegistry::hiddenNames() as $internal) {
            if (!in_array($internal, $ignoreFolders, true)) {
                $ignoreFolders[] = $internal;
            }
        }

        $this->ignoreFolders = $ignoreFolders;
        $this->db = Database::getInstance();
        $this->progressFile = dirname(__DIR__, 2) . '/storage/.indexing_progress.json';
    }

    /**
     * Compte le nombre total d'elements a indexer (pour la barre de progression)
     */
    public function countItems(): int
    {
        if (!is_dir($this->basePath)) return 0;
        return $this->countDirectory($this->basePath);
    }

    private function countDirectory(string $path): int
    {
        $count = 1; // Le dossier lui-meme
        $items = @scandir($path);
        if ($items === false) return $count;

        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || in_array($item, $this->ignoreFolders)) continue;
            $itemPath = $path . DIRECTORY_SEPARATOR . $item;

            if (is_dir($itemPath)) {
                $count += $this->countDirectory($itemPath);
            } else {
                $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
                if (in_array($ext, $this->allowedExtensions)) {
                    $count++;
                }
            }
        }
        return $count;
    }

    /**
     * Indexation complete avec suivi de progression
     */
    public function indexAll(bool $trackProgress = false): array
    {
        if (!is_dir($this->basePath)) {
            return ['error' => "Chemin inexistant: {$this->basePath}"];
        }

        $stats = ['folders' => 0, 'files' => 0, 'new' => 0, 'updated' => 0];

        if ($trackProgress) {
            $this->totalItems = $this->countItems();
            $this->processedItems = 0;
            $this->updateProgress('running', $stats, 'Demarrage...');
        }

        $this->indexDirectory('', $stats, null, $trackProgress);
        $this->recomputeFileCounts();
        $this->setLastIndexTime();

        if ($trackProgress) {
            $this->updateProgress('completed', $stats, 'Termine');
        }

        return $stats;
    }

    /**
     * Recalcule document_folders.file_count depuis le contenu reel.
     *
     * La colonne existait depuis toujours et n'etait alimentee nulle part :
     * les 40 dossiers annoncaient 0, dont un qui portait 39 fichiers. Une
     * colonne derivee qui ment est pire qu'une colonne absente — l'interface
     * s'en sert pour afficher des compteurs, et l'utilisateur les croit.
     *
     * Recalcul global plutot qu'incremental : l'indexation est deja un
     * parcours complet, et une valeur derivee doit pouvoir se reconstruire
     * entierement a tout moment.
     *
     * @see tests/integration/test_stockage_coherence.php
     */
    private function recomputeFileCounts(): void
    {
        $this->db->exec(
            "UPDATE document_folders f
             SET f.file_count = (
                 SELECT COUNT(*) FROM documents d
                 WHERE d.folder_id = f.id AND d.deleted_at IS NULL
             )"
        );
    }

    /**
     * Met a jour le fichier de progression
     */
    private function updateProgress(string $status, array $stats, string $currentItem = ''): void
    {
        $progress = [
            'status' => $status,
            'started_at' => $this->getProgressData()['started_at'] ?? time(),
            'updated_at' => time(),
            'total' => $this->totalItems,
            'processed' => $this->processedItems,
            'percent' => $this->totalItems > 0 ? round(($this->processedItems / $this->totalItems) * 100, 1) : 0,
            'stats' => $stats,
            'current_item' => $currentItem
        ];

        @file_put_contents($this->progressFile, json_encode($progress, JSON_PRETTY_PRINT));
    }

    /**
     * Recupere les donnees de progression actuelles
     */
    public function getProgressData(): array
    {
        if (!file_exists($this->progressFile)) {
            return ['status' => 'idle'];
        }

        $data = @json_decode(file_get_contents($this->progressFile), true);
        if (!$data) {
            return ['status' => 'idle'];
        }

        // Si le processus a ete interrompu (pas de mise a jour depuis 30s)
        if (isset($data['status']) && in_array($data['status'], ['running', 'starting'])) {
            if (time() - ($data['updated_at'] ?? 0) > 30) {
                $data['status'] = 'stale';
            }
        }

        return $data;
    }

    /**
     * Reinitialise la progression
     */
    public function resetProgress(): void
    {
        @unlink($this->progressFile);
    }

    /**
     * Initialise une nouvelle session de progression
     */
    public function initProgress(): void
    {
        $progress = [
            'status' => 'starting',
            'started_at' => time(),
            'updated_at' => time(),
            'total' => 0,
            'processed' => 0,
            'percent' => 0,
            'stats' => ['folders' => 0, 'files' => 0, 'new' => 0, 'updated' => 0],
            'current_item' => 'Initialisation...'
        ];
        @file_put_contents($this->progressFile, json_encode($progress, JSON_PRETTY_PRINT));
    }

    private function indexDirectory(string $relativePath, array &$stats, ?int $parentId = null, bool $trackProgress = false): void
    {
        $fullPath = $this->basePath . ($relativePath ? DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath) : '');
        if (!is_dir($fullPath)) return;

        try {
            $folderId = $this->upsertFolder($relativePath, $parentId);
            $stats['folders']++;
            $this->processedItems++;

            if ($trackProgress && $this->processedItems % 10 === 0) {
                $this->updateProgress('running', $stats, $relativePath ?: '[Racine]');
            }
        } catch (\Exception $e) { return; }

        $items = @scandir($fullPath);
        if ($items === false) return;

        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || in_array($item, $this->ignoreFolders)) continue;
            $itemPath = $relativePath ? $relativePath . '/' . $item : $item;
            $itemFullPath = $fullPath . DIRECTORY_SEPARATOR . $item;

            if (is_dir($itemFullPath)) {
                $this->indexDirectory($itemPath, $stats, $folderId, $trackProgress);
            } else {
                $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
                if (in_array($ext, $this->allowedExtensions)) {
                    try {
                        $isNew = $this->upsertDocument($itemPath, $folderId, $itemFullPath);
                        $stats['files']++;
                        $isNew ? $stats['new']++ : $stats['updated']++;
                        $this->processedItems++;

                        if ($trackProgress && $this->processedItems % 5 === 0) {
                            $this->updateProgress('running', $stats, $item);
                        }
                    } catch (\Exception $e) {}
                }
            }
        }
    }

    private function upsertFolder(string $relativePath, ?int $parentId): int
    {
        $name = $relativePath ? basename($relativePath) : '[Racine]';
        $depth = $relativePath ? substr_count($relativePath, '/') + 1 : 0;

        $stmt = $this->db->prepare("SELECT id FROM document_folders WHERE path = ?");
        $stmt->execute([$relativePath]);
        $existing = $stmt->fetch();

        if ($existing) {
            $stmt = $this->db->prepare("UPDATE document_folders SET name = ?, parent_id = ?, depth = ?, last_scanned = NOW() WHERE id = ?");
            $stmt->execute([$name, $parentId, $depth, $existing['id']]);
            return (int)$existing['id'];
        }

        $stmt = $this->db->prepare("INSERT INTO document_folders (path, name, parent_id, depth, last_scanned) VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([$relativePath, $name, $parentId, $depth]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Archive l'etat courant d'un fichier comme version, dans un sous-dossier
     * cache voisin — modele `.versions/`, convention comparable a `.DS_Store`.
     *
     * DECISION D'ARCHITECTURE, 2026-08-09 (direction Karbonic).
     *
     * C'est le HASH qui determine qu'une version existe. A l'upload comme a
     * l'indexation : si le checksum en base differe de celui du fichier, le
     * fichier a ete modifie et cela fait une version. Meme regle des deux cotes,
     * ce qui couvre le cas du dossier partage modifie hors de la GED.
     *
     * Consequence, et c'est la difficulte propre au modele filesystem-first :
     * quand un fichier est modifie de l'exterieur, ses octets d'origine ont deja
     * disparu au moment ou on detecte le changement. On ne peut pas reconstruire
     * a posteriori ce qu'on n'a pas garde. Le versioning sur un dossier
     * accessible en filesystem exige donc une COPIE COMPLETE au depart — sans
     * base de comparaison, un delta ne veut rien dire. C'est ce que fait
     * l'instantane initial pose a la premiere indexation.
     *
     * Le mode sans acces filesystem — la GED seule ecrit — n'a pas ce cout :
     * elle archive au moment du write. C'est le mode d'acces qui decide, pas la
     * fonctionnalite.
     *
     * La version courante reste le fichier nu, a sa place, ouvrable directement.
     * Seules les archives vivent dans `.versions/`.
     */
    /**
     * Le fichier est-il reellement SUIVI par git ?
     *
     * On interroge git, on ne devine pas. Chercher un `.git` en remontant
     * l'arborescence est un faux ami : storage/documents vit dans le depot
     * GEDv1 et n'a pourtant aucun fichier suivi — .gitignore ligne 37 exclut
     * /storage/documents/**. Un fichier dans un depot n'est pas un fichier
     * versionne.
     *
     * Utile le jour ou la GED se pose sur un stockage reellement gere par git :
     * inutile de doubler une histoire qui existe deja.
     */
    /** Le fichier vit-il dans un arbre de stockage de la GED (documents, consume, processed, trash) ? */
    private function estDansUnArbreStockage(string $fullPath): bool
    {
        $racines = [
            Config::get('storage.base_path', ''),
            Config::get('storage.documents', ''),
            Config::get('storage.consume', ''),
            Config::get('storage.processed', ''),
            Config::get('storage.trash', ''),
        ];

        $cible = str_replace('\\', '/', strtolower((string) realpath($fullPath)));
        if ($cible === '') {
            $cible = str_replace('\\', '/', strtolower($fullPath));
        }

        foreach ($racines as $racine) {
            $racineResolved = str_replace('\\', '/', strtolower((string) realpath((string) $racine)));
            if ($racineResolved !== '' && str_starts_with($cible, rtrim($racineResolved, '/') . '/')) {
                return true;
            }
        }

        // Un deploiement peut vivre sous sa propre arborescence (ex.
        // C:\wamp64\www\kdocs\storage\consume) alors que la config courante
        // pointe ailleurs : les segments storage/<nom interne> suffisent a
        // reconnaitre un arbre de stockage GED, quelle que soit sa racine.
        return (bool) preg_match('#/storage/(documents|consume|processed|trash|temp|thumbnails|pending)(/|$)#', $cible);
    }

    private function estSuiviParGit(string $fullPath): bool
    {
        $dossier = dirname($fullPath);
        $cmd = sprintf(
            'git -C %s ls-files --error-unmatch %s 2>&1',
            escapeshellarg($dossier),
            escapeshellarg(basename($fullPath))
        );

        @exec($cmd, $sortie, $code);

        return $code === 0;
    }

    private function enregistrerVersion(
        int $documentId,
        string $filename,
        string $fullPath,
        int $filesize,
        string $mimeType,
        string $checksum,
        ?string $checksumPrecedent
    ): void {
        try {
            // Un fichier reellement SUIVI par git est deja versionne : le
            // recopier serait redondant. Attention au faux ami — etre situe
            // dans l'arborescence d'un depot ne suffit pas. storage/documents
            // vit dans le depot GEDv1 et n'a pourtant aucun fichier suivi
            // (.gitignore ligne 37 : /storage/documents/**). Seul git peut
            // repondre, on le lui demande.
            // LIMITE (2026-08-25) : la garde ne s'applique qu'HORS des arbres
            // de stockage de la GED. Le deploiement C:\wamp64\www\kdocs est
            // lui-meme un depot git dont l'index contient des documents de
            // production (consume/ et un contrat de storage/documents) —
            // accident de deploiement, pas une decision de versioning. Pour
            // un DOCUMENT de la GED, l'attendu A3 prime : la version se range
            // a cote du fichier, quelle que soit la hygiene du repo sous
            // lequel il vit par hasard.
            if (!$this->estDansUnArbreStockage($fullPath) && $this->estSuiviParGit($fullPath)) {
                return;
            }

            $dossier  = dirname($fullPath) . DIRECTORY_SEPARATOR . '.versions' . DIRECTORY_SEPARATOR . $filename;
            $ext      = pathinfo($filename, PATHINFO_EXTENSION);
            $numero   = \KDocs\Models\DocumentVersion::countByDocument($documentId) + 1;
            $archive  = $dossier . DIRECTORY_SEPARATOR . sprintf('v%03d_%s%s', $numero, substr($checksum, 0, 8), $ext !== '' ? '.' . $ext : '');

            if (!is_dir($dossier) && !@mkdir($dossier, 0775, true) && !is_dir($dossier)) {
                error_log("Versioning: impossible de creer {$dossier}");
                return;
            }

            // Invisible « façon mac » sous Windows : l'attribut caché posé sur
            // chaque .versions/ le fait disparaître de l'Explorateur (comme un
            // dotfile sous Unix) sans jamais gêner l'application, qui accède
            // au chemin directement. Sans effet ailleurs que Windows.
            if (PHP_OS_FAMILY === 'Windows') {
                @exec('attrib +H ' . escapeshellarg(dirname($dossier)) . ' 2>NUL');
            }

            // L'archive est une copie, jamais un deplacement : le fichier courant
            // ne bouge pas de sa place et reste ouvrable sans l'application.
            if (!@copy($fullPath, $archive)) {
                error_log("Versioning: copie impossible vers {$archive}");
                return;
            }

            \KDocs\Models\DocumentVersion::create([
                'document_id'     => $documentId,
                'filename'        => $filename,
                'file_path'       => $archive,
                'file_size'       => $filesize,
                'mime_type'       => $mimeType,
                'checksum'        => $checksum,
                'changes_summary' => $checksumPrecedent === null
                    ? 'Instantane initial — base de comparaison du versioning'
                    : 'Modification detectee par divergence de hash (' . substr($checksumPrecedent, 0, 8) . ' -> ' . substr($checksum, 0, 8) . ')',
            ]);
        } catch (\Throwable $e) {
            // Une version non enregistree ne doit jamais faire echouer
            // l'indexation : on perd une archive, pas le document.
            error_log('Versioning #' . $documentId . ' : ' . $e->getMessage());
        }
    }

    /**
     * Instantane initial de TOUS les documents deja indexes.
     *
     * A lancer une seule fois, a l'activation du versioning sur un fonds
     * existant. L'indexation ordinaire ne pose d'instantane que sur les
     * documents nouveaux : un fichier deja connu et inchange n'a aucune raison
     * d'etre recopie a chaque passage.
     *
     * Sans cette passe, un fonds existant n'a pas de base de comparaison : le
     * jour ou un fichier est modifie hors de la GED, on sait que le hash a
     * change mais on n'a rien a quoi le comparer, et l'etat d'avant est perdu.
     *
     * Cout assume : une copie complete du fonds. C'est le prix du versioning
     * sur un stockage accessible en filesystem. Un fonds auquel la GED seule
     * ecrit n'en a pas besoin — elle archive au moment du write.
     *
     * @return array{documents:int, archives:int, ignores:int, erreurs:int}
     */
    public function snapshotInitial(bool $verbose = false): array
    {
        $stats = ['documents' => 0, 'archives' => 0, 'ignores' => 0, 'erreurs' => 0];

        // Les fixtures de test (eval/) sont exclues : elles sont regenerees a
        // chaque campagne, les archiver ne protege rien et coute du disque.
        $stmt = $this->db->query(
            "SELECT d.id, d.filename, d.file_path, d.file_size, d.mime_type, d.checksum
             FROM documents d
             WHERE d.deleted_at IS NULL AND d.file_path IS NOT NULL
               AND COALESCE(d.relative_path, '') NOT LIKE 'eval/%'
               AND d.file_path NOT LIKE '%\\\\eval\\\\%'
               AND NOT EXISTS (SELECT 1 FROM document_versions v WHERE v.document_id = d.id)"
        );

        foreach ($stmt as $doc) {
            $stats['documents']++;
            $chemin = (string) $doc['file_path'];

            if (!is_file($chemin)) {
                $stats['ignores']++;
                if ($verbose) echo "   ignore (fichier absent) : {$doc['filename']}\n";
                continue;
            }

            $avant = \KDocs\Models\DocumentVersion::countByDocument((int) $doc['id']);

            $this->enregistrerVersion(
                (int) $doc['id'],
                (string) $doc['filename'],
                $chemin,
                (int) ($doc['file_size'] ?: @filesize($chemin) ?: 0),
                (string) ($doc['mime_type'] ?: 'application/octet-stream'),
                (string) ($doc['checksum'] ?: @md5_file($chemin) ?: ''),
                null
            );

            if (\KDocs\Models\DocumentVersion::countByDocument((int) $doc['id']) > $avant) {
                $stats['archives']++;
                if ($verbose) echo "   archive : {$doc['filename']}\n";
            } else {
                $stats['erreurs']++;
                if ($verbose) echo "   ECHEC : {$doc['filename']}\n";
            }
        }

        return $stats;
    }

    private function upsertDocument(string $relativePath, int $folderId, string $fullPath): bool
    {
        // Garde d'entrée : un chemin interne (pending/, consume/, .versions/…)
        // n'est jamais un document de bibliothèque, même si un appelant
        // oubliait le filtre amont (sonde test_dossier_surveille_invisible.php).
        if (InternalFolderRegistry::isHiddenPath($relativePath)) {
            return false;
        }

        $filename = basename($relativePath);
        $filesize = @filesize($fullPath);
        if ($filesize === false) throw new \Exception("Impossible de lire la taille");

        $checksum = @md5_file($fullPath);
        if ($checksum === false) throw new \Exception("Impossible de calculer le checksum");

        $mimeType = @mime_content_type($fullPath) ?: 'application/octet-stream';

        // Fallback par extension si MIME type générique
        if ($mimeType === 'application/octet-stream' || empty($mimeType)) {
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $mimeMap = [
                'doc' => 'application/msword',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'xls' => 'application/vnd.ms-excel',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'ppt' => 'application/vnd.ms-powerpoint',
                'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'odt' => 'application/vnd.oasis.opendocument.text',
                'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
                'odp' => 'application/vnd.oasis.opendocument.presentation',
                'rtf' => 'application/rtf',
                'pdf' => 'application/pdf',
            ];
            $mimeType = $mimeMap[$ext] ?? $mimeType;
        }

        $stmt = $this->db->prepare("SELECT id, checksum FROM documents WHERE relative_path = ?");
        $stmt->execute([$relativePath]);
        $existing = $stmt->fetch();

        if ($existing) {
            if ($existing['checksum'] === $checksum) return false;

            // Le hash a change : le fichier a ete modifie, potentiellement hors
            // de la GED (dossier partage). C'est le declencheur de version retenu
            // — decision du 2026-08-09 : c'est le hash qui determine qu'une
            // version existe, a l'upload comme a l'indexation.
            $this->enregistrerVersion((int) $existing['id'], $filename, $fullPath, $filesize, $mimeType, $checksum, (string) $existing['checksum']);

            $stmt = $this->db->prepare("UPDATE documents SET checksum = ?, file_size = ?, file_path = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$checksum, $filesize, $fullPath, $existing['id']]);
            return false;
        }

        $stmt = $this->db->prepare("INSERT INTO documents (filename, original_filename, file_path, relative_path, folder_id, file_size, mime_type, checksum, is_indexed, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, FALSE, NOW())");
        $stmt->execute([$filename, $filename, $fullPath, $relativePath, $folderId, $filesize, $mimeType, $checksum]);
        $documentId = (int) $this->db->lastInsertId();

        // Instantane initial : sans lui, un fichier modifie hors de la GED n'a
        // pas de version de reference a laquelle se comparer. C'est la v1.
        $this->enregistrerVersion($documentId, $filename, $fullPath, $filesize, $mimeType, $checksum, null);

        return true;
    }

    private function getLastIndexTime(): ?int
    {
        try {
            $stmt = $this->db->query("SELECT value FROM settings WHERE `key` = 'filesystem_last_index'");
            $result = $stmt->fetch();
            return $result ? (int)$result['value'] : null;
        } catch (\Exception $e) { return null; }
    }

    private function setLastIndexTime(): void
    {
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS settings (`key` VARCHAR(100) PRIMARY KEY, value TEXT, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
            $stmt = $this->db->prepare("INSERT INTO settings (`key`, value) VALUES ('filesystem_last_index', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)");
            $stmt->execute([(string)time()]);
        } catch (\Exception $e) {}
    }
}
