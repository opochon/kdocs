#!/usr/bin/env python3
"""
init_db.py - Initialise la base SQLite + FTS5 pour l'affaire VPO vs OPO
Usage: python init_db.py [chemin_db]
"""

import sqlite3
import sys
from pathlib import Path

DEFAULT_DB = "vpo_affaire.db"

SCHEMA = """
-- Table principale des emails
CREATE TABLE IF NOT EXISTS emails (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    message_id TEXT UNIQUE,          -- Message-ID header si disponible
    file_hash TEXT UNIQUE,           -- SHA256 du fichier source
    file_path TEXT,                  -- Chemin original du .msg
    subject TEXT,
    sender TEXT,
    sender_email TEXT,
    recipients TEXT,                 -- JSON array
    cc TEXT,                         -- JSON array
    date_sent TEXT,                  -- ISO format
    date_parsed TEXT,                -- Quand on l'a parsé
    body_text TEXT,
    body_html TEXT,
    has_attachments INTEGER DEFAULT 0,
    attachment_count INTEGER DEFAULT 0,
    parsed_version INTEGER DEFAULT 1,
    quality_flags TEXT,              -- JSON: truncated, encoding_issues, etc.
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

-- Table des pièces jointes
CREATE TABLE IF NOT EXISTS attachments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email_id INTEGER,
    file_hash TEXT,                  -- SHA256 du contenu
    filename TEXT,
    content_type TEXT,
    size_bytes INTEGER,
    vault_path TEXT,                 -- Chemin dans le vault local
    extracted_text TEXT,             -- Texte extrait (OCR ou natif)
    ocr_done INTEGER DEFAULT 0,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (email_id) REFERENCES emails(id)
);

-- Table des documents autonomes (hors emails)
CREATE TABLE IF NOT EXISTS documents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    file_hash TEXT UNIQUE,
    file_path TEXT,
    filename TEXT,
    doc_type TEXT,                   -- pdf, docx, img, etc.
    size_bytes INTEGER,
    extracted_text TEXT,
    ocr_done INTEGER DEFAULT 0,
    ocr_quality REAL,                -- Score de confiance OCR
    page_count INTEGER,
    parsed_version INTEGER DEFAULT 1,
    quality_flags TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

-- Table de liens (pour traçabilité)
CREATE TABLE IF NOT EXISTS links (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source_type TEXT,                -- 'email', 'attachment', 'document'
    source_id INTEGER,
    target_type TEXT,
    target_id INTEGER,
    link_type TEXT,                  -- 'attachment', 'reference', 'reply', etc.
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

-- Index FTS5 pour recherche full-text sur les emails
CREATE VIRTUAL TABLE IF NOT EXISTS emails_fts USING fts5(
    subject,
    sender,
    recipients,
    body_text,
    content='emails',
    content_rowid='id',
    tokenize='unicode61 remove_diacritics 2'
);

-- Index FTS5 pour les pièces jointes
CREATE VIRTUAL TABLE IF NOT EXISTS attachments_fts USING fts5(
    filename,
    extracted_text,
    content='attachments',
    content_rowid='id',
    tokenize='unicode61 remove_diacritics 2'
);

-- Index FTS5 pour les documents
CREATE VIRTUAL TABLE IF NOT EXISTS documents_fts USING fts5(
    filename,
    extracted_text,
    content='documents',
    content_rowid='id',
    tokenize='unicode61 remove_diacritics 2'
);

-- Triggers pour maintenir FTS synchronisé (emails)
CREATE TRIGGER IF NOT EXISTS emails_ai AFTER INSERT ON emails BEGIN
    INSERT INTO emails_fts(rowid, subject, sender, recipients, body_text)
    VALUES (new.id, new.subject, new.sender, new.recipients, new.body_text);
END;

CREATE TRIGGER IF NOT EXISTS emails_ad AFTER DELETE ON emails BEGIN
    INSERT INTO emails_fts(emails_fts, rowid, subject, sender, recipients, body_text)
    VALUES ('delete', old.id, old.subject, old.sender, old.recipients, old.body_text);
END;

CREATE TRIGGER IF NOT EXISTS emails_au AFTER UPDATE ON emails BEGIN
    INSERT INTO emails_fts(emails_fts, rowid, subject, sender, recipients, body_text)
    VALUES ('delete', old.id, old.subject, old.sender, old.recipients, old.body_text);
    INSERT INTO emails_fts(rowid, subject, sender, recipients, body_text)
    VALUES (new.id, new.subject, new.sender, new.recipients, new.body_text);
END;

-- Triggers pour FTS (attachments)
CREATE TRIGGER IF NOT EXISTS attachments_ai AFTER INSERT ON attachments BEGIN
    INSERT INTO attachments_fts(rowid, filename, extracted_text)
    VALUES (new.id, new.filename, new.extracted_text);
END;

CREATE TRIGGER IF NOT EXISTS attachments_ad AFTER DELETE ON attachments BEGIN
    INSERT INTO attachments_fts(attachments_fts, rowid, filename, extracted_text)
    VALUES ('delete', old.id, old.filename, old.extracted_text);
END;

CREATE TRIGGER IF NOT EXISTS attachments_au AFTER UPDATE ON attachments BEGIN
    INSERT INTO attachments_fts(attachments_fts, rowid, filename, extracted_text)
    VALUES ('delete', old.id, old.filename, old.extracted_text);
    INSERT INTO attachments_fts(rowid, filename, extracted_text)
    VALUES (new.id, new.filename, new.extracted_text);
END;

-- Triggers pour FTS (documents)
CREATE TRIGGER IF NOT EXISTS documents_ai AFTER INSERT ON documents BEGIN
    INSERT INTO documents_fts(rowid, filename, extracted_text)
    VALUES (new.id, new.filename, new.extracted_text);
END;

CREATE TRIGGER IF NOT EXISTS documents_ad AFTER DELETE ON documents BEGIN
    INSERT INTO documents_fts(documents_fts, rowid, filename, extracted_text)
    VALUES ('delete', old.id, old.filename, old.extracted_text);
END;

CREATE TRIGGER IF NOT EXISTS documents_au AFTER UPDATE ON documents BEGIN
    INSERT INTO documents_fts(documents_fts, rowid, filename, extracted_text)
    VALUES ('delete', old.id, old.filename, old.extracted_text);
    INSERT INTO documents_fts(rowid, filename, extracted_text)
    VALUES (new.id, new.filename, new.extracted_text);
END;

-- Index classiques pour perfs
CREATE INDEX IF NOT EXISTS idx_emails_date ON emails(date_sent);
CREATE INDEX IF NOT EXISTS idx_emails_sender ON emails(sender_email);
CREATE INDEX IF NOT EXISTS idx_emails_hash ON emails(file_hash);
CREATE INDEX IF NOT EXISTS idx_attachments_email ON attachments(email_id);
CREATE INDEX IF NOT EXISTS idx_attachments_hash ON attachments(file_hash);
CREATE INDEX IF NOT EXISTS idx_documents_hash ON documents(file_hash);

-- Vue pratique pour recherche unifiée
CREATE VIEW IF NOT EXISTS search_all AS
SELECT 
    'email' as type,
    e.id,
    e.subject as title,
    e.sender,
    e.date_sent as date,
    e.body_text as content,
    e.file_path
FROM emails e
UNION ALL
SELECT 
    'attachment' as type,
    a.id,
    a.filename as title,
    (SELECT sender FROM emails WHERE id = a.email_id) as sender,
    (SELECT date_sent FROM emails WHERE id = a.email_id) as date,
    a.extracted_text as content,
    a.vault_path as file_path
FROM attachments a
UNION ALL
SELECT 
    'document' as type,
    d.id,
    d.filename as title,
    NULL as sender,
    d.created_at as date,
    d.extracted_text as content,
    d.file_path
FROM documents d;
"""

def init_database(db_path: str) -> None:
    """Initialise la base de données avec le schéma complet."""
    print(f"Initialisation de la base: {db_path}")
    
    conn = sqlite3.connect(db_path)
    conn.executescript(SCHEMA)
    conn.commit()
    
    # Vérification
    cursor = conn.execute("SELECT name FROM sqlite_master WHERE type='table'")
    tables = [row[0] for row in cursor.fetchall()]
    print(f"Tables créées: {', '.join(tables)}")
    
    cursor = conn.execute("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE '%_fts'")
    fts_tables = [row[0] for row in cursor.fetchall()]
    print(f"Index FTS5: {', '.join(fts_tables)}")
    
    conn.close()
    print("✓ Base initialisée avec succès")

def main():
    db_path = sys.argv[1] if len(sys.argv) > 1 else DEFAULT_DB
    
    if Path(db_path).exists():
        response = input(f"La base {db_path} existe déjà. Écraser ? (o/N) ")
        if response.lower() != 'o':
            print("Annulé.")
            return
        Path(db_path).unlink()
    
    init_database(db_path)

if __name__ == "__main__":
    main()
