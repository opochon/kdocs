#!/usr/bin/env python3
"""
ingest_msg.py - Importe les emails .msg dans la base SQLite
Usage: python ingest_msg.py <dossier_source> [chemin_db]

Dépendances:
    pip install extract-msg python-dateutil
"""

import sqlite3
import hashlib
import json
import sys
import re
from pathlib import Path
from datetime import datetime
from typing import Optional, Dict, Any, List
import traceback

try:
    import extract_msg
except ImportError:
    print("ERREUR: install extract-msg avec: pip install extract-msg")
    sys.exit(1)

DEFAULT_DB = "vpo_affaire.db"
VAULT_DIR = "vault"  # Dossier pour stocker les pièces jointes

# Stats globales
stats = {
    "total": 0,
    "imported": 0,
    "skipped": 0,
    "errors": 0,
    "attachments": 0
}

def sha256_file(path: Path) -> str:
    """Calcule le SHA256 d'un fichier."""
    h = hashlib.sha256()
    with open(path, 'rb') as f:
        for chunk in iter(lambda: f.read(8192), b''):
            h.update(chunk)
    return h.hexdigest()

def sha256_bytes(data: bytes) -> str:
    """Calcule le SHA256 de bytes."""
    return hashlib.sha256(data).hexdigest()

def clean_text(text: Optional[str]) -> Optional[str]:
    """Nettoie le texte (encoding, whitespace)."""
    if not text:
        return None
    # Normalise les fins de ligne et espaces multiples
    text = re.sub(r'\r\n', '\n', text)
    text = re.sub(r'[ \t]+', ' ', text)
    text = re.sub(r'\n{3,}', '\n\n', text)
    return text.strip()

def extract_email_address(sender: str) -> Optional[str]:
    """Extrait l'adresse email d'un champ sender."""
    if not sender:
        return None
    # Pattern basique pour email
    match = re.search(r'[\w\.-]+@[\w\.-]+\.\w+', sender)
    return match.group(0).lower() if match else None

def parse_date(date_str: Optional[str]) -> Optional[str]:
    """Parse une date Outlook en ISO format."""
    if not date_str:
        return None
    try:
        from dateutil import parser
        dt = parser.parse(date_str)
        return dt.isoformat()
    except:
        return date_str  # Retourne tel quel si parsing échoue

def save_attachment(att_data: bytes, filename: str, vault_dir: Path) -> str:
    """Sauvegarde une pièce jointe dans le vault, retourne le chemin."""
    file_hash = sha256_bytes(att_data)
    # Structure: vault/ab/cd/abcd1234...ext
    subdir = vault_dir / file_hash[:2] / file_hash[2:4]
    subdir.mkdir(parents=True, exist_ok=True)
    
    ext = Path(filename).suffix if filename else ''
    dest = subdir / f"{file_hash}{ext}"
    
    if not dest.exists():
        dest.write_bytes(att_data)
    
    return str(dest)

def parse_msg(path: Path, vault_dir: Path) -> Dict[str, Any]:
    """Parse un fichier .msg et extrait toutes les infos."""
    msg = extract_msg.Message(str(path))
    
    # Extraire les infos de base
    data = {
        "file_hash": sha256_file(path),
        "file_path": str(path),
        "message_id": getattr(msg, 'messageId', None),
        "subject": clean_text(msg.subject),
        "sender": clean_text(msg.sender),
        "sender_email": extract_email_address(msg.sender),
        "recipients": json.dumps(msg.to.split(';') if msg.to else []),
        "cc": json.dumps(msg.cc.split(';') if msg.cc else []),
        "date_sent": parse_date(msg.date),
        "date_parsed": datetime.now().isoformat(),
        "body_text": clean_text(msg.body),
        "body_html": getattr(msg, 'htmlBody', None),
        "attachments": []
    }
    
    # Qualité
    quality_flags = []
    if not data["body_text"] and not data["body_html"]:
        quality_flags.append("no_body")
    if not data["date_sent"]:
        quality_flags.append("no_date")
    if not data["sender"]:
        quality_flags.append("no_sender")
    data["quality_flags"] = json.dumps(quality_flags) if quality_flags else None
    
    # Pièces jointes
    for att in msg.attachments:
        try:
            filename = att.longFilename or att.shortFilename or "unnamed"
            att_data = att.data
            if att_data:
                vault_path = save_attachment(att_data, filename, vault_dir)
                data["attachments"].append({
                    "filename": filename,
                    "file_hash": sha256_bytes(att_data),
                    "size_bytes": len(att_data),
                    "vault_path": vault_path,
                    "content_type": getattr(att, 'mimeType', None)
                })
        except Exception as e:
            print(f"  ⚠ Erreur pièce jointe {filename}: {e}")
    
    msg.close()
    return data

def insert_email(conn: sqlite3.Connection, data: Dict[str, Any]) -> Optional[int]:
    """Insère un email dans la DB, retourne l'ID ou None si doublon."""
    cursor = conn.cursor()
    
    # Vérifie doublon par hash
    cursor.execute("SELECT id FROM emails WHERE file_hash = ?", (data["file_hash"],))
    if cursor.fetchone():
        return None  # Déjà présent
    
    cursor.execute("""
        INSERT INTO emails (
            message_id, file_hash, file_path, subject, sender, sender_email,
            recipients, cc, date_sent, date_parsed, body_text, body_html,
            has_attachments, attachment_count, quality_flags
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    """, (
        data["message_id"], data["file_hash"], data["file_path"],
        data["subject"], data["sender"], data["sender_email"],
        data["recipients"], data["cc"], data["date_sent"], data["date_parsed"],
        data["body_text"], data["body_html"],
        1 if data["attachments"] else 0, len(data["attachments"]),
        data["quality_flags"]
    ))
    
    return cursor.lastrowid

def insert_attachments(conn: sqlite3.Connection, email_id: int, attachments: List[Dict]) -> int:
    """Insère les pièces jointes, retourne le nombre inséré."""
    cursor = conn.cursor()
    count = 0
    
    for att in attachments:
        # Vérifie doublon
        cursor.execute(
            "SELECT id FROM attachments WHERE email_id = ? AND file_hash = ?",
            (email_id, att["file_hash"])
        )
        if cursor.fetchone():
            continue
        
        cursor.execute("""
            INSERT INTO attachments (
                email_id, file_hash, filename, content_type, size_bytes, vault_path
            ) VALUES (?, ?, ?, ?, ?, ?)
        """, (
            email_id, att["file_hash"], att["filename"],
            att["content_type"], att["size_bytes"], att["vault_path"]
        ))
        count += 1
    
    return count

def process_directory(source_dir: Path, db_path: str, vault_dir: Path) -> None:
    """Traite tous les .msg d'un dossier (récursif)."""
    conn = sqlite3.connect(db_path)
    
    msg_files = list(source_dir.rglob("*.msg"))
    stats["total"] = len(msg_files)
    
    print(f"Trouvé {stats['total']} fichiers .msg")
    print("-" * 50)
    
    for i, msg_path in enumerate(msg_files, 1):
        try:
            print(f"[{i}/{stats['total']}] {msg_path.name[:60]}...", end=" ")
            
            data = parse_msg(msg_path, vault_dir)
            email_id = insert_email(conn, data)
            
            if email_id is None:
                print("(doublon, ignoré)")
                stats["skipped"] += 1
                continue
            
            att_count = insert_attachments(conn, email_id, data["attachments"])
            stats["attachments"] += att_count
            
            conn.commit()
            stats["imported"] += 1
            
            status = f"✓ {att_count} PJ" if att_count else "✓"
            print(status)
            
        except Exception as e:
            print(f"✗ ERREUR: {e}")
            stats["errors"] += 1
            traceback.print_exc()
            continue
    
    conn.close()

def print_stats():
    """Affiche les statistiques finales."""
    print("\n" + "=" * 50)
    print("RÉSUMÉ")
    print("=" * 50)
    print(f"Total fichiers .msg:    {stats['total']}")
    print(f"Importés:               {stats['imported']}")
    print(f"Doublons ignorés:       {stats['skipped']}")
    print(f"Erreurs:                {stats['errors']}")
    print(f"Pièces jointes:         {stats['attachments']}")
    print("=" * 50)

def main():
    if len(sys.argv) < 2:
        print("Usage: python ingest_msg.py <dossier_source> [chemin_db]")
        print("Exemple: python ingest_msg.py 'C:\\Users\\opochon\\Documents\\Affaire VPO vs OPO'")
        sys.exit(1)
    
    source_dir = Path(sys.argv[1])
    db_path = sys.argv[2] if len(sys.argv) > 2 else DEFAULT_DB
    vault_dir = Path(VAULT_DIR)
    
    if not source_dir.exists():
        print(f"ERREUR: Dossier non trouvé: {source_dir}")
        sys.exit(1)
    
    if not Path(db_path).exists():
        print(f"ERREUR: Base non trouvée: {db_path}")
        print("Lance d'abord: python init_db.py")
        sys.exit(1)
    
    vault_dir.mkdir(exist_ok=True)
    
    print(f"Source:  {source_dir}")
    print(f"Base:    {db_path}")
    print(f"Vault:   {vault_dir}")
    print()
    
    process_directory(source_dir, db_path, vault_dir)
    print_stats()

if __name__ == "__main__":
    main()
