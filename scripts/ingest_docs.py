#!/usr/bin/env python3
"""
ingest_docs.py - Importe les documents (PDF, images, DOCX) dans la base SQLite
Usage: python ingest_docs.py <dossier_source> [chemin_db]

Dépendances:
    pip install pymupdf python-docx pillow
    
Pour OCR (optionnel mais recommandé):
    pip install ocrmypdf
    + Tesseract installé sur le système
"""

import sqlite3
import hashlib
import json
import sys
import subprocess
import tempfile
from pathlib import Path
from datetime import datetime
from typing import Optional, Dict, Any, Tuple
import traceback

# Imports conditionnels
try:
    import fitz  # PyMuPDF
    HAS_PYMUPDF = True
except ImportError:
    HAS_PYMUPDF = False
    print("⚠ PyMuPDF non installé (pip install pymupdf)")

try:
    from docx import Document as DocxDocument
    HAS_DOCX = True
except ImportError:
    HAS_DOCX = False
    print("⚠ python-docx non installé (pip install python-docx)")

try:
    from PIL import Image
    import io
    HAS_PIL = True
except ImportError:
    HAS_PIL = False
    print("⚠ Pillow non installé (pip install pillow)")

DEFAULT_DB = "vpo_affaire.db"

# Extensions supportées
EXTENSIONS = {
    'pdf': ['.pdf'],
    'docx': ['.docx', '.doc'],
    'image': ['.png', '.jpg', '.jpeg', '.tiff', '.tif', '.bmp', '.gif'],
    'text': ['.txt', '.csv', '.json', '.xml', '.html', '.htm']
}

# Stats globales
stats = {
    "total": 0,
    "imported": 0,
    "skipped": 0,
    "errors": 0,
    "ocr_done": 0
}

def sha256_file(path: Path) -> str:
    """Calcule le SHA256 d'un fichier."""
    h = hashlib.sha256()
    with open(path, 'rb') as f:
        for chunk in iter(lambda: f.read(8192), b''):
            h.update(chunk)
    return h.hexdigest()

def get_doc_type(path: Path) -> Optional[str]:
    """Détermine le type de document."""
    ext = path.suffix.lower()
    for doc_type, exts in EXTENSIONS.items():
        if ext in exts:
            return doc_type
    return None

def extract_pdf_text(path: Path) -> Tuple[str, int, bool]:
    """
    Extrait le texte d'un PDF.
    Retourne: (texte, nb_pages, ocr_fait)
    """
    if not HAS_PYMUPDF:
        return "", 0, False
    
    doc = fitz.open(str(path))
    pages = []
    total_chars = 0
    
    for page in doc:
        text = page.get_text("text")
        pages.append(text)
        total_chars += len(text.strip())
    
    doc.close()
    
    page_count = len(pages)
    native_text = "\n\n--- PAGE ---\n\n".join(pages)
    
    # Si très peu de texte et plusieurs pages → probablement scan
    needs_ocr = total_chars < 100 * page_count and page_count > 0
    
    if needs_ocr:
        ocr_text = try_ocr_pdf(path)
        if ocr_text and len(ocr_text) > len(native_text):
            return ocr_text, page_count, True
    
    return native_text, page_count, False

def try_ocr_pdf(path: Path) -> Optional[str]:
    """Tente l'OCR sur un PDF avec ocrmypdf."""
    try:
        with tempfile.NamedTemporaryFile(suffix='.pdf', delete=False) as tmp:
            tmp_path = tmp.name
        
        # Lance ocrmypdf
        result = subprocess.run([
            'ocrmypdf',
            '--rotate-pages',
            '--deskew',
            '--clean',
            '--skip-text',  # Ne pas ré-OCR les pages avec texte
            '-l', 'fra+eng',  # Français + Anglais
            str(path),
            tmp_path
        ], capture_output=True, timeout=120)
        
        if result.returncode == 0:
            # Extrait le texte du PDF OCRisé
            doc = fitz.open(tmp_path)
            text = "\n\n".join(page.get_text("text") for page in doc)
            doc.close()
            Path(tmp_path).unlink(missing_ok=True)
            return text
        
        Path(tmp_path).unlink(missing_ok=True)
        
    except FileNotFoundError:
        print("    (ocrmypdf non installé, OCR ignoré)")
    except subprocess.TimeoutExpired:
        print("    (OCR timeout)")
    except Exception as e:
        print(f"    (OCR erreur: {e})")
    
    return None

def extract_docx_text(path: Path) -> str:
    """Extrait le texte d'un fichier DOCX."""
    if not HAS_DOCX:
        return ""
    
    try:
        doc = DocxDocument(str(path))
        paragraphs = [p.text for p in doc.paragraphs if p.text.strip()]
        return "\n\n".join(paragraphs)
    except Exception as e:
        print(f"    Erreur DOCX: {e}")
        return ""

def extract_image_text(path: Path) -> Tuple[str, bool]:
    """
    Extrait le texte d'une image via OCR.
    Retourne: (texte, ocr_fait)
    """
    try:
        result = subprocess.run([
            'tesseract',
            str(path),
            'stdout',
            '-l', 'fra+eng'
        ], capture_output=True, text=True, timeout=60)
        
        if result.returncode == 0:
            return result.stdout.strip(), True
            
    except FileNotFoundError:
        print("    (tesseract non installé)")
    except subprocess.TimeoutExpired:
        print("    (OCR timeout)")
    except Exception as e:
        print(f"    (OCR erreur: {e})")
    
    return "", False

def extract_text_file(path: Path) -> str:
    """Lit un fichier texte."""
    encodings = ['utf-8', 'latin-1', 'cp1252']
    for enc in encodings:
        try:
            return path.read_text(encoding=enc)
        except:
            continue
    return ""

def process_document(path: Path) -> Dict[str, Any]:
    """Traite un document et extrait ses métadonnées + texte."""
    doc_type = get_doc_type(path)
    
    data = {
        "file_hash": sha256_file(path),
        "file_path": str(path),
        "filename": path.name,
        "doc_type": doc_type,
        "size_bytes": path.stat().st_size,
        "extracted_text": "",
        "ocr_done": 0,
        "ocr_quality": None,
        "page_count": None,
        "quality_flags": []
    }
    
    if doc_type == 'pdf':
        text, pages, ocr = extract_pdf_text(path)
        data["extracted_text"] = text
        data["page_count"] = pages
        data["ocr_done"] = 1 if ocr else 0
        
    elif doc_type == 'docx':
        data["extracted_text"] = extract_docx_text(path)
        
    elif doc_type == 'image':
        text, ocr = extract_image_text(path)
        data["extracted_text"] = text
        data["ocr_done"] = 1 if ocr else 0
        
    elif doc_type == 'text':
        data["extracted_text"] = extract_text_file(path)
    
    # Évalue la qualité
    text_len = len(data["extracted_text"] or "")
    if text_len < 50:
        data["quality_flags"].append("low_text")
    if doc_type == 'pdf' and data["page_count"] and text_len < 100 * data["page_count"]:
        data["quality_flags"].append("sparse_text")
    
    data["quality_flags"] = json.dumps(data["quality_flags"]) if data["quality_flags"] else None
    
    return data

def insert_document(conn: sqlite3.Connection, data: Dict[str, Any]) -> Optional[int]:
    """Insère un document dans la DB."""
    cursor = conn.cursor()
    
    # Vérifie doublon
    cursor.execute("SELECT id FROM documents WHERE file_hash = ?", (data["file_hash"],))
    if cursor.fetchone():
        return None
    
    cursor.execute("""
        INSERT INTO documents (
            file_hash, file_path, filename, doc_type, size_bytes,
            extracted_text, ocr_done, ocr_quality, page_count, quality_flags
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    """, (
        data["file_hash"], data["file_path"], data["filename"], data["doc_type"],
        data["size_bytes"], data["extracted_text"], data["ocr_done"],
        data["ocr_quality"], data["page_count"], data["quality_flags"]
    ))
    
    return cursor.lastrowid

def process_directory(source_dir: Path, db_path: str) -> None:
    """Traite tous les documents d'un dossier."""
    conn = sqlite3.connect(db_path)
    
    # Collecte tous les fichiers supportés
    all_files = []
    for doc_type, exts in EXTENSIONS.items():
        for ext in exts:
            all_files.extend(source_dir.rglob(f"*{ext}"))
            all_files.extend(source_dir.rglob(f"*{ext.upper()}"))
    
    # Déduplique
    all_files = list(set(all_files))
    stats["total"] = len(all_files)
    
    print(f"Trouvé {stats['total']} documents")
    print("-" * 50)
    
    for i, doc_path in enumerate(all_files, 1):
        try:
            doc_type = get_doc_type(doc_path)
            print(f"[{i}/{stats['total']}] [{doc_type}] {doc_path.name[:50]}...", end=" ")
            
            data = process_document(doc_path)
            doc_id = insert_document(conn, data)
            
            if doc_id is None:
                print("(doublon)")
                stats["skipped"] += 1
                continue
            
            conn.commit()
            stats["imported"] += 1
            
            if data["ocr_done"]:
                stats["ocr_done"] += 1
            
            text_len = len(data["extracted_text"] or "")
            status = f"✓ {text_len} chars"
            if data["ocr_done"]:
                status += " (OCR)"
            print(status)
            
        except Exception as e:
            print(f"✗ ERREUR: {e}")
            stats["errors"] += 1
            traceback.print_exc()
    
    conn.close()

def print_stats():
    """Affiche les statistiques."""
    print("\n" + "=" * 50)
    print("RÉSUMÉ")
    print("=" * 50)
    print(f"Total documents:        {stats['total']}")
    print(f"Importés:               {stats['imported']}")
    print(f"Doublons ignorés:       {stats['skipped']}")
    print(f"Erreurs:                {stats['errors']}")
    print(f"OCR effectués:          {stats['ocr_done']}")
    print("=" * 50)

def main():
    if len(sys.argv) < 2:
        print("Usage: python ingest_docs.py <dossier_source> [chemin_db]")
        sys.exit(1)
    
    source_dir = Path(sys.argv[1])
    db_path = sys.argv[2] if len(sys.argv) > 2 else DEFAULT_DB
    
    if not source_dir.exists():
        print(f"ERREUR: Dossier non trouvé: {source_dir}")
        sys.exit(1)
    
    if not Path(db_path).exists():
        print(f"ERREUR: Base non trouvée: {db_path}")
        print("Lance d'abord: python init_db.py")
        sys.exit(1)
    
    print(f"Source:  {source_dir}")
    print(f"Base:    {db_path}")
    print()
    
    # Vérifie les outils disponibles
    print("Outils disponibles:")
    print(f"  PyMuPDF (PDF):     {'✓' if HAS_PYMUPDF else '✗'}")
    print(f"  python-docx:       {'✓' if HAS_DOCX else '✗'}")
    print(f"  Pillow:            {'✓' if HAS_PIL else '✗'}")
    
    # Vérifie tesseract
    try:
        subprocess.run(['tesseract', '--version'], capture_output=True)
        print("  Tesseract OCR:     ✓")
    except:
        print("  Tesseract OCR:     ✗")
    
    # Vérifie ocrmypdf
    try:
        subprocess.run(['ocrmypdf', '--version'], capture_output=True)
        print("  ocrmypdf:          ✓")
    except:
        print("  ocrmypdf:          ✗")
    
    print()
    
    process_directory(source_dir, db_path)
    print_stats()

if __name__ == "__main__":
    main()
