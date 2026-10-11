#!/usr/bin/env python3
"""Extraction mecanique des vecteurs de comportement Paperless (lot ged-oracle-paperless-1).

Pour les fonctions deterministes, les tests de Paperless servent d'oracle. On extrait
UNIQUEMENT des valeurs d'entree et de sortie (jamais de code) vers
tests/parite/vecteurs/<categorie>.json, rejouables cote GEDv2.

Categories couvertes ici :
  - dates          : documents/tests/test_date_parsing.py -> parse_date
  - correspondance : documents/tests/test_matchables.py  -> matching.matches

Choix d'outil : Python 3 + ast (voir tools/parite/extraire-paperless.py).
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


def constante(noeud: ast.AST | None):
    if isinstance(noeud, ast.Constant):
        return noeud.value
    return None


def texte_str(noeud: ast.AST | None) -> str | None:
    valeur = constante(noeud)
    return valeur if isinstance(valeur, str) else None


def liste_str(noeud: ast.AST | None) -> list[str]:
    if isinstance(noeud, ast.Tuple | ast.List):
        resultat = []
        for element in noeud.elts:
            valeur = texte_str(element)
            if valeur is not None:
                resultat.append(valeur)
        return resultat
    valeur = texte_str(noeud)
    return [valeur] if valeur is not None else []


def date_depuis_appel(noeud: ast.AST) -> str | None:
    """datetime.datetime(2018, 2, 13, ...) -> '2018-02-13'."""
    if not isinstance(noeud, ast.Call):
        return None
    nom = _nom(noeud.func)
    if not nom.endswith("datetime"):
        return None
    morceaux = [constante(a) for a in noeud.args[:3]]
    if len(morceaux) < 3 or not all(isinstance(m, int) for m in morceaux):
        return None
    return f"{morceaux[0]:04d}-{morceaux[1]:02d}-{morceaux[2]:02d}"


def _nom(noeud: ast.AST) -> str:
    if isinstance(noeud, ast.Name):
        return noeud.id
    if isinstance(noeud, ast.Attribute):
        return _nom(noeud.value) + "." + noeud.attr
    return ""


def extraire_dates(depot: Path) -> list[dict[str, object]]:
    chemin = depot / "src" / "documents" / "tests" / "test_date_parsing.py"
    arbre = ast.parse(chemin.read_text(encoding="utf-8"), filename=str(chemin))
    vecteurs: list[dict[str, object]] = []
    for classe in [n for n in arbre.body if isinstance(n, ast.ClassDef)]:
        for fonction in [n for n in classe.body if isinstance(n, ast.FunctionDef)]:
            if not fonction.name.startswith("test_"):
                continue
            textes: dict[str, str] = {}
            langues: list[str] = []
            for noeud in ast.walk(fonction):
                if isinstance(noeud, ast.Assign) and len(noeud.targets) == 1:
                    cible = _nom(noeud.targets[0])
                    valeur = texte_str(noeud.value)
                    if cible == "text" and valeur is not None:
                        textes["text"] = valeur
                    if isinstance(noeud.value, ast.List):
                        valeurs = [texte_str(e) for e in noeud.value.elts]
                        if cible.endswith("DATE_PARSER_LANGUAGES"):
                            langues = [v for v in valeurs if v is not None]
            if "text" not in textes:
                continue
            for noeud in ast.walk(fonction):
                if not isinstance(noeud, ast.Assert):
                    continue
                test = noeud.test
                if not isinstance(test, ast.Compare):
                    continue
                gauche = test.left
                if not isinstance(gauche, ast.Call) or not _nom(gauche.func).endswith("parse_date"):
                    continue
                for operateur, comparateur in zip(test.ops, test.comparators):
                    if isinstance(operateur, ast.Is) and constante(comparateur) is None:
                        vecteurs.append(_vecteur_dates(fonction, textes["text"], langues, None, chemin, depot))
                    elif isinstance(operateur, ast.Eq):
                        date = date_depuis_appel(comparateur)
                        if date is not None:
                            vecteurs.append(_vecteur_dates(fonction, textes["text"], langues, date, chemin, depot))
    return vecteurs


def _vecteur_dates(
    fonction: ast.FunctionDef,
    texte: str,
    langues: list[str],
    sortie: str | None,
    chemin: Path,
    depot: Path,
) -> dict[str, object]:
    relatif = chemin.relative_to(depot).as_posix()
    return {
        "id": f"dates:{fonction.name}",
        "entree": {"texte": texte, "langues": langues},
        "sortie": sortie,
        "reference": f"{relatif}:{fonction.lineno}",
    }


def extraire_correspondance(depot: Path) -> list[dict[str, object]]:
    chemin = depot / "src" / "documents" / "tests" / "test_matchables.py"
    arbre = ast.parse(chemin.read_text(encoding="utf-8"), filename=str(chemin))
    relatif = chemin.relative_to(depot).as_posix()
    vecteurs: list[dict[str, object]] = []
    compteur = 0
    for noeud in ast.walk(arbre):
        if not isinstance(noeud, ast.Call):
            continue
        if _nom(noeud.func).split(".")[-1] != "_test_matching":
            continue
        if len(noeud.args) < 4:
            continue
        match_texte = texte_str(noeud.args[0])
        algorithme = texte_str(noeud.args[1])
        should = liste_str(noeud.args[2])
        no_match = liste_str(noeud.args[3])
        if match_texte is None or algorithme is None:
            continue
        for texte, attendu in [(t, True) for t in should] + [(t, False) for t in no_match]:
            compteur += 1
            vecteurs.append(
                {
                    "id": f"correspondance:{compteur:03d}",
                    "entree": {"match_texte": match_texte, "algorithme": algorithme, "doc_texte": texte},
                    "sortie": attendu,
                    "reference": f"{relatif}:{noeud.lineno}",
                },
            )
    return vecteurs


def infos_commit(depot: Path) -> dict[str, str]:
    def git(*args: str) -> str:
        try:
            resultat = subprocess.run(
                ["git", "-C", str(depot), *args], capture_output=True, text=True, check=True,
            )
            return resultat.stdout.strip()
        except (OSError, subprocess.CalledProcessError):
            return ""

    return {
        "commit": git("rev-parse", "HEAD"),
        "commit_court": git("rev-parse", "--short", "HEAD"),
        "tag": git("describe", "--tags"),
    }


def main() -> int:
    parser = argparse.ArgumentParser(description="Vecteurs de comportement Paperless")
    parser.add_argument("--depot", default=None)
    parser.add_argument("--sortie", default="tests/parite/vecteurs")
    args = parser.parse_args()

    depot = Path(args.depot or os.environ.get("PAPERLESS_DEPOT", r"C:\ged\ref\paperless-ngx"))
    if not (depot / "src" / "documents" / "tests" / "test_date_parsing.py").exists():
        print(f"ERREUR: depot Paperless introuvable : {depot}", file=sys.stderr)
        return 2

    categories = {
        "dates": extraire_dates(depot),
        "correspondance": extraire_correspondance(depot),
    }
    sortie = Path(args.sortie)
    sortie.mkdir(parents=True, exist_ok=True)
    reference = infos_commit(depot)
    for nom, vecteurs in categories.items():
        document = {
            "version": 1,
            "categorie": nom,
            "reference": reference,
            "total": len(vecteurs),
            "vecteurs": vecteurs,
        }
        (sortie / f"{nom}.json").write_text(
            json.dumps(document, ensure_ascii=False, indent=2) + "\n", encoding="utf-8",
        )
        print(f"OK: {len(vecteurs)} vecteurs pour {nom}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
