#!/usr/bin/env python3
"""Extraction mecanique des vecteurs de comportement de l'API Paperless-ngx (lot ged-parite-api-1).

Meme discipline que tools/parite/extraire-chaine.py : on lit en LECTURE SEULE les tests de Paperless
(C:\\ged\\ref\\paperless-ngx, tag v2.20.5), et on n'ecrit que des couples {entree, sortie} verifies par
un test, jamais une ligne de code de Paperless (GPL-3).

Famille couverte ici :
  - filtres-documents : src/documents/tests/test_api_documents.py
      test_document_filters, test_document_checksum_filter,
      test_documents_title_content_filter, test_documents_title_content_filter_strips_boundary_whitespace

Un vecteur porte un CORPUS synthetique (les documents crees par le test), une REQUETE (les parametres
de la requete listes par le test, noms Paperless) et la SORTIE attendue (claves de documents retenus).
La correspondance fonctionnelle Paperless -> GEDv2 vit cote PHPUnit (tests/php/Parite/ApiFiltresDocumentsTest.php) :
un parametre Paperless sans equivalent dans le modele GEDv2 (champs personnalises, nom d'origine, id de
tags) n'est jamais cable dans le rejeu et part dans `non_rejouables` avec sa raison.

Sortie : tests/parite/vecteurs/api-<famille>.json
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


def _reference(chemin: Path, depot: Path, noeud: ast.AST) -> str:
    return f"{chemin.relative_to(depot).as_posix()}:{getattr(noeud, 'lineno', 1)}"


def _fonctions_test(arbre: ast.Module) -> dict[str, ast.FunctionDef]:
    return {
        noeud.name: noeud
        for noeud in ast.walk(arbre)
        if isinstance(noeud, ast.FunctionDef) and noeud.name.startswith("test_")
    }


# ---------------------------------------------------------------------------
# Resolution des valeurs d'une f-string : {doc1.id} / {tag_2.id} / {tag_3.name}
# ---------------------------------------------------------------------------


def _gabarit(noeud: ast.AST | None) -> str | None:
    """Rend la chaine d'une URL, avec les expressions dynamiques sous la forme {var.attr}."""
    if isinstance(noeud, ast.Constant) and isinstance(noeud.value, str):
        return noeud.value
    if isinstance(noeud, ast.JoinedStr):
        morceaux = []
        for valeur in noeud.values:
            if isinstance(valeur, ast.Constant) and isinstance(valeur.value, str):
                morceaux.append(valeur.value)
            elif isinstance(valeur, ast.FormattedValue):
                morceaux.append("{" + _nom(valeur.value) + "}")
            else:
                return None
        return "".join(morceaux)
    return None


def _resoudre_jeton(jeton: str, docs: dict[str, dict], tags: dict[str, dict]) -> str | None:
    """Resout un jeton `{doc1.id}` / `{tag_2.id}` vers la cle du document ou le nom du tag.

    Un jeton peut melanger litteral et plusieurs placeholders (cas malforme `{tag.id}a{tag.id}`
    volontairement present dans les tests Paperless) : on resout alors chaque placeholder.
    """
    if "{" not in jeton:
        return jeton

    def remplacer(trouve: re.Match[str]) -> str:
        variable, attribut = trouve.group(1), trouve.group(2)
        if attribut != "id":
            return "\0"
        if variable in docs:
            return docs[variable]["cle"]
        if variable in tags:
            return tags[variable]["nom"]
        return "\0"

    resolu = re.sub(r"\{([A-Za-z_][A-Za-z0-9_]*)\.([A-Za-z_][A-Za-z0-9_]*)\}", remplacer, jeton)
    if "\0" in resolu or "{" in resolu:
        return None
    return resolu


# ---------------------------------------------------------------------------
# Lecture du corps d'un test : corpus (documents, tags) puis requetes/assertions
# ---------------------------------------------------------------------------


def _lire_kwargs(appel: ast.Call) -> dict[str, ast.AST]:
    return {mot.arg: mot.value for mot in appel.keywords if mot.arg is not None}


def _corpus(fonction: ast.FunctionDef) -> tuple[dict[str, dict], dict[str, dict], set[str]]:
    """Documents et tags declares par le test, plus les documents porteurs de champs personnalises."""
    docs: dict[str, dict] = {}
    tags: dict[str, dict] = {}
    avec_champs: set[str] = set()

    for noeud in ast.walk(fonction):
        if not isinstance(noeud, ast.Assign) or len(noeud.targets) != 1 or not isinstance(noeud.targets[0], ast.Name):
            continue
        variable = noeud.targets[0].id
        appel = noeud.value
        if not isinstance(appel, ast.Call):
            continue
        hote = _nom(appel.func)
        kwargs = _lire_kwargs(appel)

        if hote == "Document.objects.create":
            mime = _texte(kwargs.get("mime_type"))
            docs[variable] = {
                "cle": variable,
                "titre": _texte(kwargs.get("title")) or "",
                "empreinte": _texte(kwargs.get("checksum")) or "",
                "mime": mime or "application/pdf",
                "contenu": _texte(kwargs.get("content")) or "",
                "etiquettes": [],
                "inbox": False,
            }
        elif hote == "Tag.objects.create":
            tags[variable] = {
                "nom": _texte(kwargs.get("name")) or "",
                "inbox": _booleen(kwargs.get("is_inbox_tag")) or False,
            }
        elif hote == "CustomFieldInstance.objects.create":
            document = kwargs.get("document")
            if isinstance(document, ast.Name):
                avec_champs.add(document.id)

    # Affectations de tags : `doc.tags.add(tag)`.
    for noeud in ast.walk(fonction):
        if not isinstance(noeud, ast.Expr) or not isinstance(noeud.value, ast.Call):
            continue
        appel = noeud.value
        if _terminal(appel.func) != "add" or not appel.args:
            continue
        if not isinstance(appel.func, ast.Attribute) or not isinstance(appel.func.value, ast.Attribute):
            continue
        if appel.func.value.attr != "tags" or not isinstance(appel.func.value.value, ast.Name):
            continue
        document = appel.func.value.value.id
        tag = appel.args[0]
        if document in docs and isinstance(tag, ast.Name) and tag.id in tags:
            docs[document]["etiquettes"].append(tags[tag.id]["nom"])
            if tags[tag.id]["inbox"]:
                docs[document]["inbox"] = True

    return docs, tags, avec_champs


def _requete(url: str, parametres: dict | None, docs: dict[str, dict], tags: dict[str, dict]) -> dict | list | None:
    """Traduit l'URL/les parametres d'un GET en dictionnaire {param: valeur} (valeurs resolues)."""
    requete: dict[str, object] = {}
    if "?" in url:
        brut = url.split("?", 1)[1]
        for morceau in brut.split("&"):
            if "=" not in morceau:
                return None
            cle, valeur = morceau.split("=", 1)
            jetons = valeur.split(",")
            resolus = [_resoudre_jeton(jeton, docs, tags) for jeton in jetons]
            if any(jeton is None for jeton in resolus):
                return None
            requete[cle] = resolus[0] if len(resolus) == 1 else resolus
    if parametres:
        for cle, valeur in parametres.items():
            if cle in requete:
                continue
            requete[cle] = valeur
    return requete


# ---------------------------------------------------------------------------
# Assertions
# ---------------------------------------------------------------------------


def _doc_reference(noeud: ast.AST, docs: dict[str, dict]) -> str | None:
    """Resout `doc1.id` en cle de document."""
    if isinstance(noeud, ast.Attribute) and noeud.attr == "id" and isinstance(noeud.value, ast.Name):
        return docs.get(noeud.value.id, {}).get("cle")
    return None


def _resultat_id(noeud: ast.AST) -> bool:
    """Reconnait `results[i]["id"]`."""
    if not isinstance(noeud, ast.Subscript) or _texte(noeud.slice) != "id":
        return False
    interieur = noeud.value
    return isinstance(interieur, ast.Subscript) and isinstance(interieur.value, ast.Name) and interieur.value.id == "results"


def _assertions(instructions: list[ast.stmt], docs: dict[str, dict]) -> tuple[int | None, list[str]]:
    total: int | None = None
    cles: list[str] = []
    for instruction in instructions:
        if not isinstance(instruction, ast.Expr) or not isinstance(instruction.value, ast.Call):
            continue
        appel = instruction.value
        nom = _terminal(appel.func)
        if nom == "assertEqual" and len(appel.args) == 2:
            gauche, droite = appel.args
            if isinstance(gauche, ast.Call) and _terminal(gauche.func) == "len" and gauche.args and _nom(gauche.args[0]) == "results":
                total = _entier(droite)
            elif _resultat_id(gauche):
                cle = _doc_reference(droite, docs)
                if cle is not None:
                    cles.append(cle)
        elif nom == "assertCountEqual" and len(appel.args) == 2 and isinstance(appel.args[1], ast.List):
            for element in appel.args[1].elts:
                cle = _doc_reference(element, docs)
                if cle is not None:
                    cles.append(cle)
    ordre = list(docs)
    cles = sorted(set(cles), key=lambda cle: ordre.index(cle) if cle in ordre else 999)
    return total, cles


# ---------------------------------------------------------------------------
# Famille : filtres de documents
# ---------------------------------------------------------------------------

# Parametres Paperless DONT le modele existe dans GEDv2 et que le rejeu cablera.
PARAMETRES_REJOUABLES = {
    "is_in_inbox",
    "tags__id__in",
    "tags__id__all",
    "tags__id__none",
    "id",
    "id__in",
    "mime_type",
    "checksum__iexact",
    "title_content",
}


def _raison_non_rejouable(requete: dict) -> str | None:
    for cle, valeur in requete.items():
        if cle == "id__range":
            return "Django/DRF ignore silencieusement les parametres de filtre inconnus (ID_KWARGS n'a ni " \
                   "range ni lt ; src/documents/filters.py:48) : GEDv2 nomme une erreur 400, un filtre " \
                   "silencieusement lache n'est pas une convention GEDv2."
        if cle == "tags__id__all" and isinstance(valeur, str) and "," not in valeur:
            return "ObjectFilter de Paperless rend la queryset inchangee quand une valeur n'est pas une " \
                   "liste d'entiers (ValueError avale, src/documents/filters.py: ObjectFilter.filter) : " \
                   "comportement Django assume, non rejoue."
        if cle.startswith("custom_fields"):
            return "champs personnalises : aucun modele GEDv2 (modele absent, lot modele) ; " \
                   "reference src/documents/filters.py DocumentFilterSet.custom_fields__icontains."
        if cle.startswith("original_filename"):
            return "nom d'origine du fichier non conserve dans le .meta.json de GEDv2 (vit dans " \
                   "ingestion_travaux.nom_origine) : modele absent."
        if cle not in PARAMETRES_REJOUABLES:
            return f"parametre sans equivalent GEDv2 : {cle}"
    return None


def _vecteurs_depuis_instructions(
    chemin: Path,
    depot: Path,
    fonction: ast.FunctionDef,
    docs: dict[str, dict],
    tags: dict[str, dict],
    avec_champs: set[str],
) -> tuple[list[dict], list[dict]]:
    vecteurs: list[dict] = []
    ecartes: list[dict] = []

    # Parse le corps a plat : un GET ouvre une fenetre d'assertions, la suivante la ferme.
    courant: dict | None = None
    for instruction in fonction.body:
        appel_get = _get_si_client(instruction)
        if appel_get is not None:
            if courant is not None:
                _finaliser(chemin, depot, courant, docs, vecteurs, ecartes)
            url = _gabarit(appel_get.args[0]) if appel_get.args else None
            parametres = _parametres_dict(appel_get.args[1]) if len(appel_get.args) > 1 else None
            if url is None:
                courant = None
                continue
            requete = _requete(url, parametres, docs, tags)
            courant = {"requete": requete, "ligne": appel_get.lineno, "assertions": [], "fonction": fonction.name}
            continue
        if courant is not None:
            courant["assertions"].append(instruction)

    if courant is not None:
        _finaliser(chemin, depot, courant, docs, vecteurs, ecartes)

    return vecteurs, ecartes


def _get_si_client(instruction: ast.stmt) -> ast.Call | None:
    if not isinstance(instruction, ast.Assign) or not isinstance(instruction.value, ast.Call):
        return None
    appel = instruction.value
    if _nom(appel.func) != "self.client.get":
        return None
    return appel


def _parametres_dict(noeud: ast.AST) -> dict | None:
    if not isinstance(noeud, ast.Dict):
        return None
    parametres: dict[str, object] = {}
    for cle, valeur in zip(noeud.keys, noeud.values):
        nom = _texte(cle)
        if nom is None:
            continue
        if isinstance(valeur, ast.Constant):
            parametres[nom] = valeur.value
        elif isinstance(valeur, ast.List):
            parametres[nom] = [_texte(element) for element in valeur.elts if _texte(element) is not None]
        else:
            return None
    return parametres


def _finaliser(chemin: Path, depot: Path, courant: dict, docs: dict[str, dict], vecteurs: list[dict], ecartes: list[dict]) -> None:
    requete = courant["requete"]
    if requete is None:
        return
    reference = f"{chemin.relative_to(depot).as_posix()}:{courant['ligne']}"
    identifiant = f"filtres-documents:{courant['fonction']}:{len(vecteurs) + len(ecartes) + 1:03d}"
    raison = _raison_non_rejouable(requete)
    if raison is not None:
        ecartes.append({"id": identifiant, "requete": requete, "raison": raison, "reference": reference})
        return
    total, cles = _assertions(courant["assertions"], docs)
    if total is None and cles == []:
        return
    sortie = {"total": total if total is not None else len(cles)}
    if cles:
        sortie["retenus"] = cles
    vecteurs.append({"id": identifiant, "entree": {"requete": requete}, "sortie": sortie, "reference": reference})


def extraire_filtres_documents(depot: Path) -> tuple[list[dict], list[dict]]:
    chemin = depot / "src" / "documents" / "tests" / "test_api_documents.py"
    arbre = ast.parse(chemin.read_text(encoding="utf-8"), filename=str(chemin))
    fonctions = _fonctions_test(arbre)

    cibles = [
        "test_document_filters",
        "test_document_checksum_filter",
        "test_documents_title_content_filter",
        "test_documents_title_content_filter_strips_boundary_whitespace",
    ]

    vecteurs: list[dict] = []
    ecartes: list[dict] = []
    for nom in cibles:
        fonction = fonctions.get(nom)
        if fonction is None:
            continue
        docs, tags, avec_champs = _corpus(fonction)
        corpus = [_document_du_vecteur(doc, avec_champs) for doc in docs.values()]
        vs, es = _vecteurs_depuis_instructions(chemin, depot, fonction, docs, tags, avec_champs)
        for vecteur in vs:
            vecteur["entree"]["corpus"] = corpus
            vecteurs.append(vecteur)
        for ecarte in es:
            ecarte["entree"] = {"corpus": corpus, "requete": ecarte.pop("requete")}
            ecartes.append(ecarte)
    return vecteurs, ecartes


def _document_du_vecteur(doc: dict, avec_champs: set[str]) -> dict:
    return {
        "cle": doc["cle"],
        "titre": doc["titre"],
        "empreinte": doc["empreinte"],
        "mime": doc["mime"],
        "contenu": doc["contenu"],
        "etiquettes": list(doc["etiquettes"]),
        "statut": "a_controler" if doc["inbox"] else "valide",
    }


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
    "filtres-documents": ("api-filtres-documents", extraire_filtres_documents),
}


def main() -> int:
    parser = argparse.ArgumentParser(description="Vecteurs de comportement de l'API Paperless")
    parser.add_argument("--depot", default=None)
    parser.add_argument("--sortie", default="tests/parite/vecteurs")
    args = parser.parse_args()

    depot = Path(args.depot or os.environ.get("PAPERLESS_DEPOT", r"C:\ged\ref\paperless-ngx"))
    if not (depot / "src" / "documents" / "tests" / "test_api_documents.py").exists():
        print(f"ERREUR: depot Paperless introuvable : {depot}", file=sys.stderr)
        return 2

    sortie = Path(args.sortie)
    sortie.mkdir(parents=True, exist_ok=True)
    reference = infos_commit(depot)
    for nom, (fichier, extraction) in CATEGORIES.items():
        vecteurs, ecartes = extraction(depot)
        document = {
            "version": 1,
            "categorie": nom,
            "reference": reference,
            "total": len(vecteurs),
            "vecteurs": vecteurs,
            "non_rejouables": ecartes,
        }
        (sortie / f"{fichier}.json").write_text(
            json.dumps(document, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
        print(f"OK: {len(vecteurs)} vecteurs, {len(ecartes)} non rejouables pour {nom} ({fichier}.json)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
