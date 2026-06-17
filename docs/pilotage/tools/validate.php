#!/usr/bin/env php
<?php
/**
 * K-DOCS — Validateur de Conventions
 * 
 * Vérifie que le code respecte les règles définies dans PILOTAGE.md
 * 
 * Usage : php validate.php [chemin]
 * Exemple : php validate.php app/Services/
 */

define('RED', "\033[31m");
define('GREEN', "\033[32m");
define('YELLOW', "\033[33m");
define('RESET', "\033[0m");

class KDocsValidator
{
    private array $errors = [];
    private array $warnings = [];
    private int $filesChecked = 0;
    
    private array $forbiddenPatterns = [
        // Requêtes SQL non préparées
        '/\$this->db->query\s*\(\s*["\'].*\$/' => 'Requête SQL avec variable non préparée détectée',
        '/->query\s*\(\s*"[^"]*"\s*\.\s*\$/' => 'Concaténation SQL dangereuse',
        
        // Credentials hardcodés
        '/["\']sk-ant-[a-zA-Z0-9]+["\']/' => 'Clé API Anthropic hardcodée',
        '/["\']sk-[a-zA-Z0-9]{48}["\']/' => 'Clé API OpenAI hardcodée',
        '/password\s*=\s*["\'][^"\']+["\']/' => 'Mot de passe hardcodé potentiel',
        
        // getenv direct (sauf config.php)
        '/getenv\s*\(/' => 'Utilisation de getenv() - utiliser Config::get() à la place',
    ];
    
    private array $architectureRules = [
        'Controllers' => [
            'forbidden' => [
                '/\$this->db->/' => 'Accès DB direct dans Controller (utiliser Repository)',
                '/new\s+PDO/' => 'Création PDO dans Controller',
                '/file_get_contents|file_put_contents|fopen/' => 'I/O fichier dans Controller (utiliser Service)',
            ],
        ],
        'Services' => [
            'forbidden' => [
                '/\$_GET|\$_POST|\$_REQUEST/' => 'Accès superglobales dans Service (passer par Controller)',
            ],
        ],
        'Models' => [
            'forbidden' => [
                '/\$this->db->/' => 'Accès DB dans Model (utiliser Repository)',
                '/curl_init|file_get_contents.*http/' => 'Appel HTTP dans Model',
            ],
        ],
        'Repositories' => [
            'forbidden' => [
                '/curl_init/' => 'Appel HTTP dans Repository',
                '/new\s+\w+Service/' => 'Instanciation de Service dans Repository',
            ],
        ],
    ];
    
    public function validate(string $path): bool
    {
        if (is_file($path)) {
            $this->validateFile($path);
        } elseif (is_dir($path)) {
            $this->validateDirectory($path);
        } else {
            $this->errors[] = "Chemin invalide : $path";
            return false;
        }
        
        $this->printResults();
        return empty($this->errors);
    }
    
    private function validateDirectory(string $dir): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $this->validateFile($file->getPathname());
            }
        }
    }
    
    private function validateFile(string $filepath): void
    {
        $this->filesChecked++;
        $content = file_get_contents($filepath);
        $relativePath = $this->getRelativePath($filepath);
        
        $isConfig = basename($filepath) === 'config.php';
        
        foreach ($this->forbiddenPatterns as $pattern => $message) {
            if ($isConfig && strpos($pattern, 'getenv') !== false) {
                continue;
            }
            
            if (preg_match($pattern, $content, $matches)) {
                $lineNum = $this->findLineNumber($content, $matches[0]);
                $this->errors[] = "$relativePath:$lineNum — $message";
            }
        }
        
        foreach ($this->architectureRules as $folder => $rules) {
            if (strpos($filepath, "/$folder/") !== false || strpos($filepath, "\\$folder\\") !== false) {
                foreach ($rules['forbidden'] as $pattern => $message) {
                    if (preg_match($pattern, $content, $matches)) {
                        $lineNum = $this->findLineNumber($content, $matches[0]);
                        $this->errors[] = "$relativePath:$lineNum — $message";
                    }
                }
            }
        }
        
        $this->checkNamingConventions($filepath, $content);
    }
    
    private function checkNamingConventions(string $filepath, string $content): void
    {
        $relativePath = $this->getRelativePath($filepath);
        $filename = basename($filepath, '.php');
        
        if (!preg_match('/^[A-Z][a-zA-Z0-9]*$/', $filename)) {
            $this->warnings[] = "$relativePath — Nom de fichier devrait être en PascalCase";
        }
        
        if (preg_match('/^class\s+(\w+)/m', $content, $matches)) {
            $className = $matches[1];
            if ($className !== $filename) {
                $this->warnings[] = "$relativePath — Nom de classe '$className' ne correspond pas au fichier '$filename'";
            }
        }
    }
    
    private function findLineNumber(string $content, string $match): int
    {
        $pos = strpos($content, $match);
        if ($pos === false) return 0;
        return substr_count(substr($content, 0, $pos), "\n") + 1;
    }
    
    private function getRelativePath(string $filepath): string
    {
        $markers = ['kdocs/', 'kdocs\\', 'www/'];
        foreach ($markers as $marker) {
            $pos = strpos($filepath, $marker);
            if ($pos !== false) {
                return substr($filepath, $pos);
            }
        }
        return basename($filepath);
    }
    
    private function printResults(): void
    {
        echo "\n";
        echo "══════════════════════════════════════════════════════════════\n";
        echo "  K-DOCS VALIDATOR — Rapport de conformité\n";
        echo "══════════════════════════════════════════════════════════════\n\n";
        
        echo "Fichiers analysés : {$this->filesChecked}\n\n";
        
        if (!empty($this->errors)) {
            echo RED . "ERREURS (" . count($this->errors) . ") :\n" . RESET;
            foreach ($this->errors as $error) {
                echo RED . "  ✗ " . RESET . "$error\n";
            }
            echo "\n";
        }
        
        if (!empty($this->warnings)) {
            echo YELLOW . "AVERTISSEMENTS (" . count($this->warnings) . ") :\n" . RESET;
            foreach ($this->warnings as $warning) {
                echo YELLOW . "  ⚠ " . RESET . "$warning\n";
            }
            echo "\n";
        }
        
        if (empty($this->errors) && empty($this->warnings)) {
            echo GREEN . "✓ Aucun problème détecté !\n" . RESET;
        } elseif (empty($this->errors)) {
            echo GREEN . "✓ Pas d'erreurs bloquantes\n" . RESET;
        } else {
            echo RED . "✗ Corrections requises avant commit\n" . RESET;
        }
        
        echo "\n";
    }
}

$path = $argv[1] ?? 'app/';

if (!file_exists($path)) {
    echo RED . "Erreur : Chemin '$path' introuvable\n" . RESET;
    echo "Usage : php validate.php [chemin]\n";
    exit(1);
}

$validator = new KDocsValidator();
$success = $validator->validate($path);
exit($success ? 0 : 1);
