#!/usr/bin/env python3
"""
query_db.py - Interroge la base SQLite avec recherche full-text
Usage: python query_db.py <recherche> [options]

Exemples:
    python query_db.py "pension alimentaire"
    python query_db.py "avocat" --type email
    python query_db.py "expertise" --from "2023-01-01" --to "2024-01-01"
    python query_db.py --stats
    python query_db.py --export-json results.json "divorce"
"""

import sqlite3
import json
import sys
import argparse
from pathlib import Path
from datetime import datetime
from typing import List, Dict, Any, Optional

DEFAULT_DB = "vpo_affaire.db"

def search_fts(conn: sqlite3.Connection, query: str, 
               doc_type: Optional[str] = None,
               date_from: Optional[str] = None,
               date_to: Optional[str] = None,
               limit: int = 50) -> List[Dict[str, Any]]:
    """
    Recherche full-text dans tous les contenus.
    """
    results = []
    
    # Prépare la requête FTS (escape les caractères spéciaux)
    fts_query = query.replace('"', '""')
    
    # Recherche dans les emails
    if doc_type is None or doc_type == 'email':
        sql = """
            SELECT 
                'email' as type,
                e.id,
                e.subject as title,
                e.sender,
                e.date_sent as date,
                snippet(emails_fts, 3, '>>>', '<<<', '...', 64) as snippet,
                e.file_path,
                e.has_attachments,
                bm25(emails_fts) as score
            FROM emails_fts
            JOIN emails e ON e.id = emails_fts.rowid
            WHERE emails_fts MATCH ?
        """
        params = [fts_query]
        
        if date_from:
            sql += " AND e.date_sent >= ?"
            params.append(date_from)
        if date_to:
            sql += " AND e.date_sent <= ?"
            params.append(date_to)
        
        sql += " ORDER BY score LIMIT ?"
        params.append(limit)
        
        try:
            cursor = conn.execute(sql, params)
            for row in cursor:
                results.append({
                    "type": row[0],
                    "id": row[1],
                    "title": row[2],
                    "sender": row[3],
                    "date": row[4],
                    "snippet": row[5],
                    "file_path": row[6],
                    "has_attachments": bool(row[7]),
                    "score": row[8]
                })
        except sqlite3.OperationalError as e:
            if "no such table" not in str(e):
                print(f"Erreur emails: {e}")
    
    # Recherche dans les documents
    if doc_type is None or doc_type == 'document':
        sql = """
            SELECT 
                'document' as type,
                d.id,
                d.filename as title,
                d.doc_type as sender,
                d.created_at as date,
                snippet(documents_fts, 1, '>>>', '<<<', '...', 64) as snippet,
                d.file_path,
                d.ocr_done,
                bm25(documents_fts) as score
            FROM documents_fts
            JOIN documents d ON d.id = documents_fts.rowid
            WHERE documents_fts MATCH ?
            ORDER BY score
            LIMIT ?
        """
        
        try:
            cursor = conn.execute(sql, [fts_query, limit])
            for row in cursor:
                results.append({
                    "type": row[0],
                    "id": row[1],
                    "title": row[2],
                    "doc_type": row[3],
                    "date": row[4],
                    "snippet": row[5],
                    "file_path": row[6],
                    "ocr_done": bool(row[7]),
                    "score": row[8]
                })
        except sqlite3.OperationalError as e:
            if "no such table" not in str(e):
                print(f"Erreur documents: {e}")
    
    # Recherche dans les pièces jointes
    if doc_type is None or doc_type == 'attachment':
        sql = """
            SELECT 
                'attachment' as type,
                a.id,
                a.filename as title,
                e.sender,
                e.date_sent as date,
                snippet(attachments_fts, 1, '>>>', '<<<', '...', 64) as snippet,
                a.vault_path as file_path,
                a.email_id,
                bm25(attachments_fts) as score
            FROM attachments_fts
            JOIN attachments a ON a.id = attachments_fts.rowid
            LEFT JOIN emails e ON e.id = a.email_id
            WHERE attachments_fts MATCH ?
            ORDER BY score
            LIMIT ?
        """
        
        try:
            cursor = conn.execute(sql, [fts_query, limit])
            for row in cursor:
                results.append({
                    "type": row[0],
                    "id": row[1],
                    "title": row[2],
                    "sender": row[3],
                    "date": row[4],
                    "snippet": row[5],
                    "file_path": row[6],
                    "email_id": row[7],
                    "score": row[8]
                })
        except sqlite3.OperationalError as e:
            if "no such table" not in str(e):
                print(f"Erreur attachments: {e}")
    
    # Trie par score global
    results.sort(key=lambda x: x.get("score", 0))
    
    return results[:limit]

def get_email_detail(conn: sqlite3.Connection, email_id: int) -> Optional[Dict]:
    """Récupère le détail complet d'un email."""
    cursor = conn.execute("""
        SELECT * FROM emails WHERE id = ?
    """, [email_id])
    
    row = cursor.fetchone()
    if not row:
        return None
    
    columns = [d[0] for d in cursor.description]
    email = dict(zip(columns, row))
    
    # Récupère les pièces jointes
    cursor = conn.execute("""
        SELECT filename, size_bytes, vault_path, extracted_text
        FROM attachments WHERE email_id = ?
    """, [email_id])
    
    email["attachments"] = [
        {"filename": r[0], "size": r[1], "path": r[2], "text": r[3][:200] if r[3] else None}
        for r in cursor
    ]
    
    return email

def get_document_detail(conn: sqlite3.Connection, doc_id: int) -> Optional[Dict]:
    """Récupère le détail complet d'un document."""
    cursor = conn.execute("""
        SELECT * FROM documents WHERE id = ?
    """, [doc_id])
    
    row = cursor.fetchone()
    if not row:
        return None
    
    columns = [d[0] for d in cursor.description]
    return dict(zip(columns, row))

def get_stats(conn: sqlite3.Connection) -> Dict[str, Any]:
    """Retourne les statistiques de la base."""
    stats = {}
    
    # Emails
    cursor = conn.execute("SELECT COUNT(*) FROM emails")
    stats["emails_count"] = cursor.fetchone()[0]
    
    cursor = conn.execute("SELECT COUNT(*) FROM emails WHERE has_attachments = 1")
    stats["emails_with_attachments"] = cursor.fetchone()[0]
    
    cursor = conn.execute("SELECT MIN(date_sent), MAX(date_sent) FROM emails")
    row = cursor.fetchone()
    stats["emails_date_range"] = {"from": row[0], "to": row[1]}
    
    # Pièces jointes
    cursor = conn.execute("SELECT COUNT(*) FROM attachments")
    stats["attachments_count"] = cursor.fetchone()[0]
    
    cursor = conn.execute("SELECT COUNT(*) FROM attachments WHERE extracted_text IS NOT NULL AND extracted_text != ''")
    stats["attachments_with_text"] = cursor.fetchone()[0]
    
    # Documents
    cursor = conn.execute("SELECT COUNT(*) FROM documents")
    stats["documents_count"] = cursor.fetchone()[0]
    
    cursor = conn.execute("SELECT doc_type, COUNT(*) FROM documents GROUP BY doc_type")
    stats["documents_by_type"] = {r[0]: r[1] for r in cursor}
    
    cursor = conn.execute("SELECT COUNT(*) FROM documents WHERE ocr_done = 1")
    stats["documents_ocr_done"] = cursor.fetchone()[0]
    
    # Top senders
    cursor = conn.execute("""
        SELECT sender_email, COUNT(*) as cnt 
        FROM emails 
        WHERE sender_email IS NOT NULL
        GROUP BY sender_email 
        ORDER BY cnt DESC 
        LIMIT 10
    """)
    stats["top_senders"] = [{"email": r[0], "count": r[1]} for r in cursor]
    
    return stats

def print_results(results: List[Dict], verbose: bool = False):
    """Affiche les résultats de recherche."""
    if not results:
        print("Aucun résultat trouvé.")
        return
    
    print(f"\n{len(results)} résultat(s) trouvé(s):\n")
    print("-" * 80)
    
    for i, r in enumerate(results, 1):
        type_icon = {"email": "📧", "document": "📄", "attachment": "📎"}.get(r["type"], "?")
        
        print(f"{i}. {type_icon} [{r['type'].upper()}] {r.get('title', 'Sans titre')}")
        
        if r.get("sender"):
            print(f"   De: {r['sender']}")
        if r.get("date"):
            print(f"   Date: {r['date']}")
        if r.get("snippet"):
            snippet = r["snippet"].replace(">>>", "\033[1m").replace("<<<", "\033[0m")
            print(f"   ...{snippet}...")
        
        if verbose and r.get("file_path"):
            print(f"   Fichier: {r['file_path']}")
        
        print()

def main():
    parser = argparse.ArgumentParser(description="Recherche dans la base VPO")
    parser.add_argument("query", nargs="?", help="Termes de recherche")
    parser.add_argument("--db", default=DEFAULT_DB, help="Chemin de la base")
    parser.add_argument("--type", choices=["email", "document", "attachment"], help="Filtrer par type")
    parser.add_argument("--from", dest="date_from", help="Date début (YYYY-MM-DD)")
    parser.add_argument("--to", dest="date_to", help="Date fin (YYYY-MM-DD)")
    parser.add_argument("--limit", type=int, default=20, help="Nombre max de résultats")
    parser.add_argument("--stats", action="store_true", help="Afficher les statistiques")
    parser.add_argument("--detail", help="Afficher détail (email:ID ou doc:ID)")
    parser.add_argument("--export-json", help="Exporter en JSON")
    parser.add_argument("-v", "--verbose", action="store_true", help="Mode verbeux")
    
    args = parser.parse_args()
    
    if not Path(args.db).exists():
        print(f"ERREUR: Base non trouvée: {args.db}")
        sys.exit(1)
    
    conn = sqlite3.connect(args.db)
    
    # Mode stats
    if args.stats:
        stats = get_stats(conn)
        print("\n=== STATISTIQUES DE LA BASE ===\n")
        print(f"Emails:              {stats['emails_count']}")
        print(f"  - avec PJ:         {stats['emails_with_attachments']}")
        print(f"  - période:         {stats['emails_date_range']['from']} → {stats['emails_date_range']['to']}")
        print(f"\nPièces jointes:      {stats['attachments_count']}")
        print(f"  - avec texte:      {stats['attachments_with_text']}")
        print(f"\nDocuments:           {stats['documents_count']}")
        for dtype, count in stats.get("documents_by_type", {}).items():
            print(f"  - {dtype}:          {count}")
        print(f"  - OCR fait:        {stats['documents_ocr_done']}")
        print("\nTop expéditeurs:")
        for sender in stats.get("top_senders", [])[:5]:
            print(f"  {sender['count']:4d}  {sender['email']}")
        conn.close()
        return
    
    # Mode détail
    if args.detail:
        parts = args.detail.split(":")
        if len(parts) == 2:
            dtype, did = parts[0], int(parts[1])
            if dtype == "email":
                detail = get_email_detail(conn, did)
            elif dtype == "doc":
                detail = get_document_detail(conn, did)
            else:
                print("Type inconnu. Utilise email:ID ou doc:ID")
                sys.exit(1)
            
            if detail:
                print(json.dumps(detail, indent=2, ensure_ascii=False, default=str))
            else:
                print("Non trouvé")
        conn.close()
        return
    
    # Mode recherche
    if not args.query:
        parser.print_help()
        sys.exit(1)
    
    results = search_fts(
        conn, 
        args.query,
        doc_type=args.type,
        date_from=args.date_from,
        date_to=args.date_to,
        limit=args.limit
    )
    
    if args.export_json:
        with open(args.export_json, 'w', encoding='utf-8') as f:
            json.dump(results, f, indent=2, ensure_ascii=False, default=str)
        print(f"Exporté vers {args.export_json}")
    else:
        print_results(results, args.verbose)
    
    conn.close()

if __name__ == "__main__":
    main()
