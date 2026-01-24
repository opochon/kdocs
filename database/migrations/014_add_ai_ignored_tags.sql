-- Migration 014: Ajout colonne pour tags suggérés ignorés
-- Date: 2026-01-23
-- Description: Permet de mémoriser les tags suggérés marqués comme non pertinents

ALTER TABLE documents 
ADD COLUMN IF NOT EXISTS ai_ignored_tags JSON NULL COMMENT 'Tags suggérés marqués comme non pertinents pour ce document';
