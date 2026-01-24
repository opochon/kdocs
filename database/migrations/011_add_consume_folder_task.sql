-- Migration: Ajout de la tâche planifiée pour scanner le dossier consume
-- Date: 2026-01-22
-- Description: Ajoute une tâche planifiée pour scanner régulièrement le dossier consume
--              et traiter les fichiers qui y sont déposés

-- Ajouter la tâche de scan du dossier consume
-- Planification: toutes les minutes (* * * * *)
-- Le système vérifie d'abord s'il y a des fichiers avant de scanner
-- Un mécanisme de verrouillage empêche les scans simultanés
INSERT IGNORE INTO scheduled_tasks (name, task_type, schedule_cron, is_active) VALUES
('Scan dossier consume', 'scan_consume_folder', '* * * * *', TRUE);
