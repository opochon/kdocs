<?php
/**
 * K-DOCS - Agent Lock Manager
 * Vérifie et gère les verrous de fichiers entre agents
 * 
 * Usage:
 *   php agent_lock.php status              # Voir tous les verrous
 *   php agent_lock.php lock <file> <agent> # Verrouiller un fichier
 *   php agent_lock.php unlock <file>       # Déverrouiller
 *   php agent_lock.php check <file>        # Vérifier si verrouillé
 *   php agent_lock.php log <agent> <msg>   # Ajouter au journal
 */

define('LOCKS_FILE', __DIR__ . '/storage/cache/agent_locks.json');
define('LOG_FILE', __DIR__ . '/storage/logs/agents.log');

// Créer les dossiers si nécessaire
if (!is_dir(dirname(LOCKS_FILE))) mkdir(dirname(LOCKS_FILE), 0755, true);
if (!is_dir(dirname(LOG_FILE))) mkdir(dirname(LOG_FILE), 0755, true);

function getLocks(): array {
    if (!file_exists(LOCKS_FILE)) return [];
    return json_decode(file_get_contents(LOCKS_FILE), true) ?: [];
}

function saveLocks(array $locks): void {
    file_put_contents(LOCKS_FILE, json_encode($locks, JSON_PRETTY_PRINT));
}

function agentLog(string $agent, string $message): void {
    $timestamp = date('Y-m-d H:i:s');
    $line = "[$timestamp] [$agent] $message\n";
    file_put_contents(LOG_FILE, $line, FILE_APPEND);
    echo $line;
}

$command = $argv[1] ?? 'status';

switch ($command) {
    case 'status':
        $locks = getLocks();
        if (empty($locks)) {
            echo "Aucun verrou actif.\n";
        } else {
            echo "\n=== VERROUS ACTIFS ===\n\n";
            foreach ($locks as $file => $info) {
                $age = time() - strtotime($info['since']);
                $ageMin = round($age / 60);
                echo "  📁 $file\n";
                echo "     Agent: {$info['agent']}\n";
                echo "     Depuis: {$info['since']} ({$ageMin} min)\n";
                echo "     Tâche: {$info['task']}\n\n";
            }
        }
        break;
        
    case 'lock':
        $file = $argv[2] ?? null;
        $agent = $argv[3] ?? null;
        $task = $argv[4] ?? 'Non spécifié';
        
        if (!$file || !$agent) {
            echo "Usage: php agent_lock.php lock <file> <agent> [task]\n";
            exit(1);
        }
        
        $locks = getLocks();
        
        if (isset($locks[$file])) {
            echo "❌ ERREUR: Fichier déjà verrouillé par {$locks[$file]['agent']}\n";
            exit(1);
        }
        
        $locks[$file] = [
            'agent' => $agent,
            'since' => date('Y-m-d H:i:s'),
            'task' => $task
        ];
        saveLocks($locks);
        agentLog($agent, "LOCK: $file - $task");
        echo "✅ Verrou ajouté: $file\n";
        break;
        
    case 'unlock':
        $file = $argv[2] ?? null;
        $agent = $argv[3] ?? 'unknown';
        
        if (!$file) {
            echo "Usage: php agent_lock.php unlock <file> [agent]\n";
            exit(1);
        }
        
        $locks = getLocks();
        
        if (!isset($locks[$file])) {
            echo "⚠️ Fichier non verrouillé: $file\n";
            exit(0);
        }
        
        unset($locks[$file]);
        saveLocks($locks);
        agentLog($agent, "UNLOCK: $file");
        echo "✅ Verrou retiré: $file\n";
        break;
        
    case 'check':
        $file = $argv[2] ?? null;
        
        if (!$file) {
            echo "Usage: php agent_lock.php check <file>\n";
            exit(1);
        }
        
        $locks = getLocks();
        
        // Vérifier aussi les patterns (ex: templates/* matche templates/foo.php)
        $isLocked = false;
        $lockedBy = null;
        
        foreach ($locks as $pattern => $info) {
            if ($pattern === $file || 
                fnmatch($pattern . '*', $file) || 
                fnmatch($pattern . '/*', $file) ||
                strpos($file, rtrim($pattern, '/')) === 0) {
                $isLocked = true;
                $lockedBy = $info;
                break;
            }
        }
        
        if ($isLocked) {
            echo "🔒 VERROUILLÉ par {$lockedBy['agent']} depuis {$lockedBy['since']}\n";
            echo "   Tâche: {$lockedBy['task']}\n";
            exit(1);
        } else {
            echo "✅ Disponible: $file\n";
            exit(0);
        }
        break;
        
    case 'log':
        $agent = $argv[2] ?? 'unknown';
        $message = implode(' ', array_slice($argv, 3));
        
        if (empty($message)) {
            echo "Usage: php agent_lock.php log <agent> <message>\n";
            exit(1);
        }
        
        agentLog($agent, $message);
        break;
        
    case 'clear':
        saveLocks([]);
        echo "✅ Tous les verrous supprimés.\n";
        break;
        
    case 'clear-old':
        $maxAge = ($argv[2] ?? 60) * 60; // minutes -> secondes
        $locks = getLocks();
        $removed = 0;
        
        foreach ($locks as $file => $info) {
            $age = time() - strtotime($info['since']);
            if ($age > $maxAge) {
                unset($locks[$file]);
                $removed++;
                agentLog('system', "AUTO-UNLOCK (timeout): $file");
            }
        }
        
        saveLocks($locks);
        echo "✅ $removed verrou(s) expiré(s) supprimé(s).\n";
        break;
        
    default:
        echo "Commandes disponibles:\n";
        echo "  status              Voir tous les verrous\n";
        echo "  lock <f> <a> [t]    Verrouiller fichier\n";
        echo "  unlock <f> [a]      Déverrouiller\n";
        echo "  check <f>           Vérifier si verrouillé\n";
        echo "  log <a> <msg>       Ajouter au journal\n";
        echo "  clear               Supprimer tous les verrous\n";
        echo "  clear-old [min]     Supprimer verrous > N minutes (défaut: 60)\n";
}
