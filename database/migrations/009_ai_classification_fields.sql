-- Migration 009: Support IA pour les champs de classification
-- Ajouter support IA avec prompts personnalisés

ALTER TABLE classification_fields
ADD COLUMN IF NOT EXISTS use_ai BOOLEAN DEFAULT FALSE COMMENT 'Utiliser l\'IA (Claude) au lieu des mots-clés',
ADD COLUMN IF NOT EXISTS ai_prompt TEXT COMMENT 'Prompt personnalisé pour l\'IA';
