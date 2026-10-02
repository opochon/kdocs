<?php

declare(strict_types=1);

/**
 * État exclusif des parcours Playwright qui changent de fonds.
 *
 * La seule base autorisée est `gedv2_test`; elle ne peut donc pas être déclinée par parcours. Chaque
 * préparation prend déjà `gedv2_test.lock` et appelle cette remise à zéro sous ce verrou. Les workers
 * Playwright sont volontairement limités à un : entre la préparation et son écran, aucun autre fonds ne
 * peut remplacer l'index. Seules les tables dérivées du fonds synthétique sont vidées; comptes, rôles et
 * installation restent en place.
 */
function remettreEtatFondsE2e(PDO $pdo): void
{
    $tables = [
        'workflow_journal', 'workflow_instances', 'workflow_definitions',
        'recherche_textes', 'ingestion_messages', 'ingestion_travaux',
        'jonctions_jetons', 'classement_choix',
        'securite_dossiers_droits', 'securite_dossiers',
        'document_versions', 'document_deplacements', 'documents',
    ];

    foreach ($tables as $table) {
        try {
            $pdo->exec("DELETE FROM `$table`");
        } catch (PDOException) {
            // Le secteur n'est pas encore installé pour ce parcours : il ne laisse donc aucun état à vider.
        }
    }
}
