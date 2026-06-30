<?php
/**
 * Déduplication des tags en doublon (même nom, casse insensible).
 * Conserve l'ID le plus bas, réassigne document_tags, supprime les doublons.
 * Usage : php tools/dedup-tags.php [--apply]
 */
declare(strict_types=1);
define('KDOCS_ROOT', dirname(__DIR__));
require KDOCS_ROOT . '/vendor/autoload.php';
require_once KDOCS_ROOT . '/app/helpers.php';
\KDocs\Core\Config::load();

$apply = in_array('--apply', $argv ?? [], true);
$db = \KDocs\Core\Database::getInstance();

// groupes de doublons par LOWER(name)
$groups = $db->query("SELECT LOWER(name) lname, MIN(id) canon, COUNT(*) c FROM tags GROUP BY LOWER(name) HAVING c > 1")->fetchAll(PDO::FETCH_ASSOC);

if (!$groups) {
    echo "Aucun doublon de tag.\n";
    exit(0);
}

foreach ($groups as $g) {
    $canon = (int) $g['canon'];
    $lname = $g['lname'];
    // IDs doublons (non canon)
    $stmt = $db->prepare("SELECT id FROM tags WHERE LOWER(name) = ? AND id <> ?");
    $stmt->execute([$lname, $canon]);
    $dups = array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id'));
    if (!$dups) continue;

    echo "Doublon « {$lname} » : canon={$canon}, dups=" . implode(',', $dups) . "\n";

    // Vérifier d'autres références (attribution_rule_conditions field_type='tag', value=tag_id)
    $placeholders = implode(',', array_fill(0, count($dups), '?'));
    try {
        $chk = $db->prepare("SELECT COUNT(*) FROM attribution_rule_conditions WHERE field_type='tag' AND value IN ($placeholders)");
        $chk->execute($dups);
        $n = (int) $chk->fetchColumn();
        if ($n > 0) echo "  [warn] $n condition(s) d'attribution référencent les doublons — non reassignées\n";
    } catch (\Throwable $e) {}

    if (!$apply) {
        echo "  (dry-run) réassignerait document_tags puis supprimerait tags " . implode(',', $dups) . "\n";
        continue;
    }

    // Réassigner document_tags : INSERT IGNORE (document_id, canon) pour les docs ayant un doublon
    foreach ($dups as $dup) {
        $db->prepare("INSERT IGNORE INTO document_tags (document_id, tag_id) SELECT document_id, ? FROM document_tags WHERE tag_id = ?")
           ->execute([$canon, $dup]);
        // supprimer les liaisons vers le doublon
        $db->prepare("DELETE FROM document_tags WHERE tag_id = ?")->execute([$dup]);
    }

    // Supprimer les tags doublons (CASCADE nettoie mail_rule_tags etc.)
    $del = $db->prepare("DELETE FROM tags WHERE id IN ($placeholders)");
    $del->execute($dups);
    echo "  [apply] doublons supprimés, document_tags réassignés vers {$canon}\n";
}

echo $apply ? "Terminé (appliqué).\n" : "Terminé (dry-run — utiliser --apply).\n";
