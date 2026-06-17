#!/usr/bin/env php
<?php
/**
 * K-DOCS — Générateur de Changelog
 * 
 * Met à jour PILOTAGE.md avec les modifications
 * 
 * Usage : php changelog.php [message]
 * Exemple : php changelog.php "Fix UTF-8 extraction"
 */

define('PILOTAGE_FILE', __DIR__ . '/../PILOTAGE.md');

class ChangelogGenerator
{
    public function generate(string $message): void
    {
        $date = date('Y-m-d');
        
        if (!file_exists(PILOTAGE_FILE)) {
            echo "Erreur : PILOTAGE.md non trouvé\n";
            exit(1);
        }
        
        $content = file_get_contents(PILOTAGE_FILE);
        
        // Mettre à jour la date
        $content = preg_replace(
            '/\*Dernière mise à jour : .*\*/',
            "*Dernière mise à jour : $date*",
            $content
        );
        
        // Ajouter dans HISTORIQUE RÉCENT
        $historyEntry = "- $message\n";
        
        // Chercher la section du jour
        if (strpos($content, "### $date") !== false) {
            // Ajouter sous la date existante
            $content = preg_replace(
                "/(### $date\n)/",
                "$1$historyEntry",
                $content
            );
        } else {
            // Nouvelle date
            $newSection = "### $date\n$historyEntry\n";
            $content = preg_replace(
                '/(## HISTORIQUE RÉCENT\n\n)/',
                "$1$newSection",
                $content
            );
        }
        
        file_put_contents(PILOTAGE_FILE, $content);
        
        echo "✓ PILOTAGE.md mis à jour : $message\n";
    }
}

$message = $argv[1] ?? null;

if (!$message) {
    echo "Usage : php changelog.php \"Description\"\n";
    exit(1);
}

$generator = new ChangelogGenerator();
$generator->generate($message);
