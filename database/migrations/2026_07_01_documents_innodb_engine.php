<?php
/**
 * Migration: documents_innodb_engine
 * Created: 2026-07-01
 *
 * document_notes (InnoDB) FK → documents.id échoue si documents reste MyISAM.
 */

return new class {
    public function up(PDO $db): void
    {
        $row = $db->query("SHOW TABLE STATUS LIKE 'documents'")->fetch(PDO::FETCH_ASSOC);
        $engine = strtoupper((string) ($row['Engine'] ?? ''));
        if ($engine !== 'INNODB') {
            $db->exec('ALTER TABLE documents ENGINE=InnoDB');
        }
    }

    public function down(PDO $db): void
    {
        // Pas de retour MyISAM — perte d'intégrité FK document_notes.
    }
};
