-- Migration 007: Ajout des colonnes de matching pour classification automatique
-- Colonnes de matching pour correspondants
ALTER TABLE correspondents 
ADD COLUMN IF NOT EXISTS matching_algorithm VARCHAR(20) DEFAULT 'any',
ADD COLUMN IF NOT EXISTS matching_keywords TEXT,
ADD COLUMN IF NOT EXISTS is_insensitive BOOLEAN DEFAULT TRUE;

-- Colonnes de matching pour tags
ALTER TABLE tags 
ADD COLUMN IF NOT EXISTS matching_algorithm VARCHAR(20) DEFAULT 'any',
ADD COLUMN IF NOT EXISTS matching_keywords TEXT,
ADD COLUMN IF NOT EXISTS is_insensitive BOOLEAN DEFAULT TRUE;

-- Colonnes de matching pour types de documents
ALTER TABLE document_types 
ADD COLUMN IF NOT EXISTS matching_algorithm VARCHAR(20) DEFAULT 'any',
ADD COLUMN IF NOT EXISTS matching_keywords TEXT,
ADD COLUMN IF NOT EXISTS is_insensitive BOOLEAN DEFAULT TRUE,
ADD COLUMN IF NOT EXISTS consume_subfolder VARCHAR(100);

-- Colonnes documents (ajout colonnes pour classification)
ALTER TABLE documents
ADD COLUMN IF NOT EXISTS classification_suggestions JSON,
ADD COLUMN IF NOT EXISTS consume_subfolder VARCHAR(100),
ADD COLUMN IF NOT EXISTS status VARCHAR(20) DEFAULT 'pending';

-- Exemples de règles (optionnel - commenté pour éviter les erreurs si les données n'existent pas)
-- UPDATE document_types SET matching_keywords = 'facture, invoice, rechnung, montant dû, total à payer', consume_subfolder = 'factures' WHERE code = 'invoice' OR label LIKE '%facture%';
-- UPDATE document_types SET matching_keywords = 'contrat, contract, convention, accord' WHERE code = 'contract' OR label LIKE '%contrat%';
-- UPDATE tags SET matching_keywords = 'urgent, priorité, asap, immédiat' WHERE name LIKE '%urgent%';
