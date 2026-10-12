#!/usr/bin/env python3
"""Extraction mecanique des vecteurs de comportement de la chaine de consommation Paperless.

Lot ged-parite-chaine-1. Meme discipline que tools/parite/extraire-vecteurs.py : on lit en
LECTURE SEULE les tests de Paperless (C:\\ged\\ref\\paperless-ngx), et on n'ecrit que des couples
{entree, sortie} verifies par un test, jamais une ligne de code de Paperless.

Familles couvertes ici (le reste est liste dans la note de lot) :
  - registre : documents/tests/test_parsers.py -> registre MIME / extensions / parseur
  - ocr-text : paperless_tesseract/tests/test_parser.py -> post_process_text
  - codebarres : documents/tests/test_barcodes.py -> separate_pages (decoupe)
  - gabarit : documents/tests/test_file_handling.py -> TestFilenameGeneration (nom de fichier)

Sortie : tests/parite/vecteurs/chaine-<famille>.json
"""

from __future__ import annotations

import argparse
import ast
import json
import os
import re
import subprocess
import sys
from pathlib import Path


# ---------------------------------------------------------------------------
# Utilitaires AST
# ---------------------------------------------------------------------------


def _nom(noeud: ast.AST | None) -> str:
    if noeud is None:
        return ""
    if isinstance(noeud, ast.Name):
        return noeud.id
    if isinstance(noeud, ast.Attribute):
        gauche = _nom(noeud.value)
        return f"{gauche}.{noeud.attr}" if gauche else noeud.attr
    if isinstance(noeud, ast.Call):
        return _nom(noeud.func)
    return ""


def _terminal(noeud: ast.AST | None) -> str:
    return _nom(noeud).split(".")[-1]


def _texte(noeud: ast.AST | None) -> str | None:
    if isinstance(noeud, ast.Constant) and isinstance(noeud.value, str):
        return noeud.value
    return None


def _entier(noeud: ast.AST | None) -> int | None:
    if isinstance(noeud, ast.Constant) and isinstance(noeud.value, int) and not isinstance(noeud.value, bool):
        return noeud.value
    return None


def _booleen(noeud: ast.AST | None) -> bool | None:
    if isinstance(noeud, ast.Constant) and isinstance(noeud.value, bool):
        return noeud.value
    return None


def _appels(arbre: ast.AST) -> list[ast.Call]:
    return [n for n in ast.walk(arbre) if isinstance(n, ast.Call)]


def _fonctions_test(arbre: ast.Module) -> list[ast.FunctionDef]:
    resultat = []
    for noeud in ast.walk(arbre):
        if isinstance(noeud, ast.FunctionDef) and noeud.name.startswith("test_"):
            resultat.append(noeud)
    return resultat


def _reference(chemin: Path, depot: Path, noeud: ast.AST) -> str:
    relatif = chemin.relative_to(depot).as_posix()
    return f"{relatif}:{getattr(noeud, 'lineno', 1)}"


def _cree_vecteur(identifiant, entree, sortie, reference):
    return {"id": identifiant, "entree": entree, "sortie": sortie, "reference": reference}


# ---------------------------------------------------------------------------
# Famille : registre des parseurs
# ---------------------------------------------------------------------------


def extraire_registre(depot: Path) -> list[dict[str, object]]:
    chemin = depot / "src" / "documents" / "tests" / "test_parsers.py"
    arbre = ast.parse(chemin.read_text(encoding="utf-8"), filename=str(chemin))
    vecteurs: list[dict[str, object]] = []

    for fonction in _fonctions_test(arbre):
        # Cas 1 : listes (mime, ext) + parseur attendu dans la meme fonction.
        liste_mimes: list[tuple[str, str]] = []
        parseur_attendu: str | None = None
        for noeud in ast.walk(fonction):
            if isinstance(noeud, ast.Assign) and isinstance(noeud.value, ast.List):
                paires = []
                for element in noeud.value.elts:
                    if isinstance(element, ast.Tuple) and len(element.elts) == 2:
                        mime, ext = _texte(element.elts[0]), _texte(element.elts[1])
                        if mime is not None and ext is not None:
                            paires.append((mime, ext))
                if paires:
                    liste_mimes = paires
            if isinstance(noeud, ast.Call) and _terminal(noeud.func) == "assertIsInstance" and len(noeud.args) == 2:
                classe = _nom(noeud.args[1]).split(".")[-1]
                if classe.endswith("DocumentParser"):
                    parseur_attendu = classe
        contexte_tika = "tika" in fonction.name
        for mime, ext in liste_mimes:
            base = f"registre:{fonction.name}:{mime}"
            vecteurs.append(_cree_vecteur(
                f"{base}:ext", {"mime": mime}, {"extension": ext},
                _reference(chemin, depot, fonction)))
            vecteurs.append(_cree_vecteur(
                f"{base}:ext-support", {"extension": ext}, {"support": True},
                _reference(chemin, depot, fonction)))
            if parseur_attendu is not None:
                vecteurs.append(_cree_vecteur(
                    f"{base}:parseur",
                    {"mime": mime, "tika": contexte_tika},
                    {"parseur": parseur_attendu, "support": True},
                    _reference(chemin, depot, fonction)))

        # Cas 2 : assertions simples sur un appel connu.
        for noeud in _appels(fonction):
            nom = _terminal(noeud.func)
            if nom not in {"assertEqual", "assertIsNone", "assertTrue", "assertFalse"} or len(noeud.args) < 1:
                continue
            cible = noeud.args[0]
            cible_nom = _terminal(cible.func) if isinstance(cible, ast.Call) else ""

            if cible_nom == "get_default_file_extension" and len(cible.args) == 1:
                mime = _texte(cible.args[0])
                if mime is None:
                    continue
                if nom == "assertEqual" and len(noeud.args) == 2:
                    ext = _texte(noeud.args[1])
                    vecteurs.append(_cree_vecteur(
                        f"registre:{fonction.name}:defaut-ext:{mime}", {"mime": mime}, {"extension": ext},
                        _reference(chemin, depot, noeud)))

            elif cible_nom == "is_file_ext_supported" and len(cible.args) == 1:
                ext = _texte(cible.args[0])
                if ext is None:
                    continue
                attendu = {"assertTrue": True, "assertFalse": False}.get(nom)
                if attendu is not None:
                    vecteurs.append(_cree_vecteur(
                        f"registre:{fonction.name}:support-ext:{ext or 'vide'}", {"extension": ext},
                        {"support": attendu}, _reference(chemin, depot, noeud)))

            elif cible_nom == "get_parser_class_for_mime_type" and len(cible.args) == 1:
                mime = _texte(cible.args[0])
                if mime is None:
                    continue
                if nom == "assertIsNone":
                    vecteurs.append(_cree_vecteur(
                        f"registre:{fonction.name}:parseur-absent:{mime}", {"mime": mime}, {"parseur": None},
                        _reference(chemin, depot, noeud)))

    return vecteurs


# ---------------------------------------------------------------------------
# Famille : post-traitement du texte OCR
# ---------------------------------------------------------------------------


def extraire_ocr(depot: Path) -> list[dict[str, object]]:
    chemin = depot / "src" / "paperless_tesseract" / "tests" / "test_parser.py"
    arbre = ast.parse(chemin.read_text(encoding="utf-8"), filename=str(chemin))
    vecteurs: list[dict[str, object]] = []
    for fonction in _fonctions_test(arbre):
        if fonction.name != "test_post_process_text":
            continue
        for noeud in ast.walk(fonction):
            if not isinstance(noeud, ast.Assign) or len(noeud.targets) != 1:
                continue
            if _nom(noeud.targets[0]) != "text_cases" or not isinstance(noeud.value, ast.List):
                continue
            for index, element in enumerate(noeud.value.elts, start=1):
                if not isinstance(element, ast.Tuple) or len(element.elts) != 2:
                    continue
                source, resultat = _texte(element.elts[0]), _texte(element.elts[1])
                if source is None or resultat is None:
                    continue
                vecteurs.append(_cree_vecteur(
                    f"ocr-text:{index:03d}", {"texte": source}, {"texte": resultat},
                    _reference(chemin, depot, element)))
    return vecteurs


# ---------------------------------------------------------------------------
# Famille : codes-barres (decoupe)
# ---------------------------------------------------------------------------


def extraire_codebarres(depot: Path) -> list[dict[str, object]]:
    """Vecteurs de `BarcodePlugin.separate_pages` : pages a couper (page -> conserver) -> decoupe.

    La lecture des codes-barres elle-meme (PDF -> image -> pyzbar/zxing) est Python ;
    seules les valeurs DECLAREES par les tests (entrees de `separate_pages`) sont rejouables ici.
    """
    chemin = depot / "src" / "documents" / "tests" / "test_barcodes.py"
    arbre = ast.parse(chemin.read_text(encoding="utf-8"), filename=str(chemin))
    vecteurs: list[dict[str, object]] = []
    for fonction in _fonctions_test(arbre):
        if "separate_pages" not in fonction.name:
            continue
        pages_pdf = _pages_echantillon(depot, fonction)
        if pages_pdf is None:
            continue
        for noeud in _appels(fonction):
            if _terminal(noeud.func) != "separate_pages" or len(noeud.args) != 1:
                continue
            coupes = _coupes_depuis(noeud.args[0], fonction)
            if not coupes:
                continue
            # Le nombre de documents attendu en sortie est affirme juste apres : assertEqual(len(...), N).
            attendu = None
            for autre in _appels(fonction):
                if _terminal(autre.func) == "assertEqual" and len(autre.args) == 2:
                    gauche = _terminal(autre.args[0].func) if isinstance(autre.args[0], ast.Call) else ""
                    if gauche == "len":
                        attendu = _entier(autre.args[1])
            if attendu is None:
                continue
            identifiant = "codebarres:" + fonction.name + ":" + "-".join(
                f"{p}{'t' if v else 'f'}" for p, v in sorted(coupes.items(), key=lambda kv: int(kv[0])))
            vecteurs.append(_cree_vecteur(
                identifiant, {"pages": pages_pdf, "coupes": coupes}, {"documents": attendu},
                _reference(chemin, depot, noeud)))
    return vecteurs


def _coupes_depuis(argument: ast.AST, fonction: ast.FunctionDef) -> dict[str, bool]:
    """Lit le dictionnaire page->conserver, litteral ou reference par `assertDictEqual`."""
    if isinstance(argument, ast.Dict):
        return _lit_coupes(argument)
    if isinstance(argument, ast.Name):
        for noeud in _appels(fonction):
            if _terminal(noeud.func) == "assertDictEqual" and len(noeud.args) == 2 and _nom(noeud.args[0]) == argument.id:
                return _lit_coupes(noeud.args[1])
    return {}


def _lit_coupes(dictionnaire: ast.AST) -> dict[str, bool]:
    if not isinstance(dictionnaire, ast.Dict):
        return {}
    coupes: dict[str, bool] = {}
    for cle, valeur in zip(dictionnaire.keys, dictionnaire.values):
        page = _entier(cle)
        garder = _booleen(valeur)
        if page is not None and garder is not None:
            coupes[str(page)] = garder
    return coupes


def _pages_echantillon(depot: Path, fonction: ast.FunctionDef) -> int | None:
    """Nombre de pages du PDF echantillon du test, compte brut sur les objets `/Type /Page`.

    Le test ne l'affirme pas : il decoupe un PDF donne. On le lit dans l'echantillon de reference
    (lecture seule) pour que le vecteur soit COMPLET et rejouable.
    """
    nom: str | None = None
    for noeud in ast.walk(fonction):
        if (
            isinstance(noeud, ast.Assign)
            and len(noeud.targets) == 1
            and _nom(noeud.targets[0]) == "test_file"
            and isinstance(noeud.value, ast.BinOp)
            and isinstance(noeud.value.op, ast.Div)
        ):
            nom = _texte(noeud.value.right)
    if nom is None:
        return None
    for dossier in ("barcodes", ""):
        chemin = depot / "src" / "documents" / "tests" / "samples" / dossier / nom
        if chemin.is_file():
            donnees = chemin.read_bytes()
            return len(re.findall(rb"/Type\s*/Page\b", donnees))
    return None


# ---------------------------------------------------------------------------
# Famille : gabarit de nom de fichier
# ---------------------------------------------------------------------------

# Cas a expressions Jinja ({% if %}, filtres enchaines, arithmetique) : listes comme ecarts Python/Jinja
# dans la note de lot, jamais contournes. Marqueur pour tracer ce qui est ecarte.
JINJA = ("{%", "{{ document.", " | get_cf_value", "|localize_date", " | localize_date")


def _valeur(noeud: ast.AST | None, variables: dict[str, object]) -> object:
    """Resout une expression simple en valeur Python (str/int/bool/None) ou descripteur d'objet."""
    if noeud is None:
        return None
    if isinstance(noeud, ast.Constant):
        return noeud.value
    if isinstance(noeud, ast.Name):
        return variables.get(noeud.id)
    if isinstance(noeud, ast.Call):
        nom = _terminal(noeud.func)
        if nom in {"date"} and len(noeud.args) == 3:
            a, m, j = (_entier(x) for x in noeud.args)
            if None not in (a, m, j):
                return {"__date__": f"{a:04d}-{m:02d}-{j:02d}"}
        if nom == "datetime" and len(noeud.args) >= 3:
            a, m, j = (_entier(x) for x in noeud.args[:3])
            if None not in (a, m, j):
                h = _entier(noeud.args[3]) if len(noeud.args) > 3 else 0
                mi = _entier(noeud.args[4]) if len(noeud.args) > 4 else 0
                s = _entier(noeud.args[5]) if len(noeud.args) > 5 else 0
                return {"__datetime__": f"{a:04d}-{m:02d}-{j:02d} {h:02d}:{mi:02d}:{s:02d}"}
        if nom == "make_aware" and noeud.args:
            return _valeur(noeud.args[0], variables)
        if nom == "create" and "objects.create" in _nom(noeud.func):
            hote = _nom(noeud.func).split(".objects")[0]
            for mot in noeud.keywords:
                if hote == "StoragePath" and mot.arg == "path":
                    return {"__storage_path__": _texte(mot.value)}
                if hote == "Correspondent" and mot.arg == "name":
                    return {"__correspondant__": _texte(mot.value)}
            return None
        if nom == "create_user" and noeud.args:
            return {"__utilisateur__": _texte(noeud.args[0])}
    return None


def _doc_depuis_create(call: ast.Call, variables: dict[str, object]) -> dict[str, object] | None:
    if _nom(call.func) != "Document.objects.create":
        return None
    doc: dict[str, object] = {"titre": "", "mime": "application/pdf", "pk": 0, "asn": None,
        "nom_origine": None, "correspondant": None, "proprietaire": None, "date_creation": None,
        "date_ajout": None, "storage_var": None, "storage_inline": None}
    for mot in call.keywords:
        valeur = _valeur(mot.value, variables)
        if mot.arg == "title" and isinstance(valeur, str):
            doc["titre"] = valeur
        elif mot.arg == "mime_type" and isinstance(valeur, str):
            doc["mime"] = valeur
        elif mot.arg == "pk" and isinstance(valeur, int):
            doc["pk"] = valeur
        elif mot.arg == "checksum" and isinstance(valeur, str):
            doc["checksum"] = valeur
        elif mot.arg == "archive_serial_number" and isinstance(valeur, int):
            doc["asn"] = valeur
        elif mot.arg == "original_filename" and isinstance(valeur, str):
            doc["nom_origine"] = valeur
        elif mot.arg == "created" and isinstance(valeur, dict) and "__date__" in valeur:
            doc["date_creation"] = valeur["__date__"]
        elif mot.arg == "added" and isinstance(valeur, dict) and "__datetime__" in valeur:
            doc["date_ajout"] = valeur["__datetime__"]
        elif mot.arg == "storage_path" and isinstance(mot.value, ast.Name):
            doc["storage_var"] = mot.value.id
        elif mot.arg == "storage_path" and isinstance(valeur, dict) and "__storage_path__" in valeur:
            doc["storage_inline"] = valeur["__storage_path__"]
        elif mot.arg == "correspondent":
            if isinstance(valeur, dict) and valeur.get("__correspondant__"):
                doc["correspondant"] = valeur["__correspondant__"]
            elif isinstance(mot.value, ast.Name):
                doc["correspondant_var"] = mot.value.id
        elif mot.arg == "owner":
            if isinstance(valeur, dict) and valeur.get("__utilisateur__"):
                doc["proprietaire"] = valeur["__utilisateur__"]
            elif isinstance(mot.value, ast.Name):
                doc["proprietaire_var"] = mot.value.id
    return doc


def _format_depuis_appel(call: ast.Call) -> tuple[object, object, bool]:
    """Rend (FILENAME_FORMAT, FILENAME_FORMAT_REMOVE_NONE, present) d'un override_settings."""
    if _terminal(call.func) != "override_settings":
        return None, None, False
    format_, remove, present = None, None, False
    for mot in call.keywords:
        if mot.arg == "FILENAME_FORMAT":
            format_ = _texte(mot.value) if not isinstance(mot.value, ast.Constant) or mot.value.value is not None else None
            if isinstance(mot.value, ast.Constant) and mot.value.value is None:
                format_ = None
            present = True
        elif mot.arg == "FILENAME_FORMAT_REMOVE_NONE":
            remove = _booleen(mot.value)
    return format_, remove, present


def _vecteurs_gabarit_fonction(chemin: Path, depot: Path, fonction: ast.FunctionDef) -> list[dict[str, object]]:
    variables: dict[str, object] = {}
    storage_paths: dict[str, str] = {}
    format_base: object = None
    remove_base = False
    for decorateur in fonction.decorator_list:
        if isinstance(decorateur, ast.Call):
            format_, remove, present = _format_depuis_appel(decorateur)
            if present:
                format_base = format_
            if remove is not None:
                remove_base = remove

    evenements: list[ast.AST] = []
    evenements.extend(n for n in ast.walk(fonction) if isinstance(n, (ast.Assign, ast.With, ast.Expr)))
    evenements.sort(key=lambda n: getattr(n, "lineno", 0))

    vecteurs: list[dict[str, object]] = []
    format_courant: object = format_base
    remove_courant = remove_base

    for evenement in evenements:
        if isinstance(evenement, ast.Assign) and len(evenement.targets) == 1:
            cible = evenement.targets[0]
            if isinstance(cible, ast.Name):
                nom = cible.id
                if isinstance(evenement.value, ast.Call):
                    doc = _doc_depuis_create(evenement.value, variables)
                    if doc is not None:
                        variables[nom] = doc
                        continue
                    valeur = _valeur(evenement.value, variables)
                    if isinstance(valeur, dict) and "__storage_path__" in valeur:
                        storage_paths[nom] = valeur["__storage_path__"]
                    variables[nom] = valeur
            elif isinstance(cible, ast.Attribute) and cible.attr == "path" and isinstance(cible.value, ast.Name):
                text = _texte(evenement.value)
                if text is not None:
                    storage_paths[cible.value.id] = text
        elif isinstance(evenement, ast.With):
            for item in evenement.items:
                if isinstance(item.context_expr, ast.Call):
                    format_, remove, present = _format_depuis_appel(item.context_expr)
                    if present:
                        format_courant = format_
                    if remove is not None:
                        remove_courant = remove
        elif isinstance(evenement, ast.Expr):
            for appel in _appels(evenement):
                if _terminal(appel.func) != "assertEqual" or len(appel.args) != 2:
                    continue
                gauche, droite = appel.args
                if not isinstance(gauche, ast.Call) or _terminal(gauche.func) != "generate_filename":
                    continue
                if not gauche.args or not isinstance(gauche.args[0], ast.Name):
                    continue
                doc = variables.get(gauche.args[0].id)
                if not isinstance(doc, dict):
                    continue
                attendu = _texte(droite)
                if attendu is None and isinstance(droite, ast.Call) and _terminal(droite.func) == "Path" and droite.args:
                    attendu = _texte(droite.args[0])
                if attendu is None:
                    continue
                archive = any(mot.arg == "archive_filename" and _booleen(mot.value) for mot in gauche.keywords)
                gabarit: object = None
                storage_var = doc.get("storage_var")
                if isinstance(storage_var, str) and storage_var in storage_paths:
                    gabarit = storage_paths[storage_var]
                elif isinstance(doc.get("storage_inline"), str):
                    gabarit = doc["storage_inline"]
                elif format_courant is not None:
                    gabarit = format_courant
                if isinstance(gabarit, str) and any(m in gabarit for m in JINJA):
                    continue  # ecart Jinja documente dans la note
                document = {k: v for k, v in doc.items()
                            if v is not None and not k.endswith("_var") and k != "storage_inline"}
                for cle in ("correspondant_var", "proprietaire_var"):
                    if cle in doc:
                        cible = variables.get(str(doc[cle]))
                        if isinstance(cible, dict):
                            document["correspondant" if cle == "correspondant_var" else "proprietaire"] = (
                                cible.get("__correspondant__") or cible.get("__utilisateur__"))
                identifiant = f"gabarit:{fonction.name}:{len(vecteurs) + 1:03d}"
                vecteurs.append(_cree_vecteur(
                    identifiant,
                    {"gabarit": gabarit, "document": document, "retirer_none": bool(remove_courant),
                     "archive": bool(archive)},
                    {"chemin": attendu},
                    _reference(chemin, depot, appel)))
    return vecteurs


def extraire_gabarit(depot: Path) -> list[dict[str, object]]:
    chemin = depot / "src" / "documents" / "tests" / "test_file_handling.py"
    arbre = ast.parse(chemin.read_text(encoding="utf-8"), filename=str(chemin))
    vecteurs: list[dict[str, object]] = []
    for classe in [n for n in arbre.body if isinstance(n, ast.ClassDef) and n.name == "TestFilenameGeneration"]:
        for fonction in [n for n in classe.body if isinstance(n, ast.FunctionDef)]:
            vecteurs.extend(_vecteurs_gabarit_fonction(chemin, depot, fonction))
    return vecteurs


# ---------------------------------------------------------------------------
# Commit de reference
# ---------------------------------------------------------------------------


def infos_commit(depot: Path) -> dict[str, str]:
    def git(*args: str) -> str:
        try:
            resultat = subprocess.run(["git", "-C", str(depot), *args], capture_output=True, text=True, check=True)
            return resultat.stdout.strip()
        except (OSError, subprocess.CalledProcessError):
            return ""

    return {
        "commit": git("rev-parse", "HEAD"),
        "commit_court": git("rev-parse", "--short", "HEAD"),
        "tag": git("describe", "--tags"),
    }


CATEGORIES = {
    "registre": ("chaine-registre", extraire_registre),
    "ocr-text": ("chaine-ocr-text", extraire_ocr),
    "codebarres": ("chaine-codebarres", extraire_codebarres),
    "gabarit": ("chaine-gabarit", extraire_gabarit),
}


def main() -> int:
    parser = argparse.ArgumentParser(description="Vecteurs de comportement de la chaine Paperless")
    parser.add_argument("--depot", default=None)
    parser.add_argument("--sortie", default="tests/parite/vecteurs")
    args = parser.parse_args()

    depot = Path(args.depot or os.environ.get("PAPERLESS_DEPOT", r"C:\ged\ref\paperless-ngx"))
    if not (depot / "src" / "documents" / "tests" / "test_parsers.py").exists():
        print(f"ERREUR: depot Paperless introuvable : {depot}", file=sys.stderr)
        return 2

    sortie = Path(args.sortie)
    sortie.mkdir(parents=True, exist_ok=True)
    reference = infos_commit(depot)
    for nom, (fichier, extraction) in CATEGORIES.items():
        vecteurs = extraction(depot)
        document = {
            "version": 1,
            "categorie": nom,
            "reference": reference,
            "total": len(vecteurs),
            "vecteurs": vecteurs,
        }
        (sortie / f"{fichier}.json").write_text(
            json.dumps(document, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
        print(f"OK: {len(vecteurs)} vecteurs pour {nom} ({fichier}.json)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
