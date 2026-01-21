-- Migration pour ASN (Archive Serial Number) (Phase 2.3)
-- Numéro de série d'archive pour documents physiques

ALTER TABLE documents 
ADD COLUMN asn INT NULL COMMENT 'Archive Serial Number' AFTER id,
ADD UNIQUE KEY unique_asn (asn),
ADD INDEX idx_asn (asn);

-- Créer une fonction pour générer automatiquement l'ASN suivant
-- Note: En MySQL, on utilisera un trigger ou une logique applicative
