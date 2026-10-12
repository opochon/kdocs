#!/usr/bin/env python3
"""Extraction mecanique des vecteurs de comportement de l'API Paperless-ngx (lot ged-parite-api-2).

Meme discipline que tools/parite/extraire-api.py (lot ged-parite-api-1) : on lit en LECTURE SEULE les
tests de Paperless (C:\\ged\\ref\\paperless-ngx, tag v2.20.5), et on n'ecrit que des couples {entree,
sortie} verifies par un test, jamais une ligne de code de Paperless (GPL-3).

Familles couvertes ici :
  - filtres-documents  : src/documents/tests/test_api_documents.py::test_document_filters
                         (documents + etiquettes + champs personnalises)
                         src/documents/tests/test_api_search.py::test_search_filtering
                         (filtres de liste combinés a la recherche `query=`, ensembles complets)
  - filtres-ressources : src/documents/tests/test_api_objects.py::test_object_filters
                         (listes de referentiels filtrees par identifiant -> nom cote GEDv2)

Un parametre Paperless sans equivalent dans le modele GEDv2 (nom d'origine, proprietaire, identifiant
d'etiquette, chemin de stockage comme entite) part dans `non_rejouables`, avec sa raison et sa reference.

Sortie : tests/parite/vecteurs/api-2-<famille>.json
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
# Utilitaires AST (repris de tools/parite/extraire-api.py, lot api-1)
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


def _lire_kwargs(appel: ast.Call) -> dict[str, ast.AST]:
    return {mot.arg: mot.value for mot in appel.keywords if mot.arg is not None}


def _fonctions_test(arbre: ast.Module) -> dict[str, ast.FunctionDef]:
    return {
        noeud.name: noeud
        for noeud in ast.walk(arbre)
        if isinstance(noeud, ast.FunctionDef) and noeud.name.startswith("test_")
    }


def _cible(noeud: ast.Assign) -> str | None:
    """Nom de variable ou attribut (`doc1` ou `self.tag1`)."""
    if len(noeud.targets) != 1:
        return None
    cible = noeud.targets[0]
    if isinstance(cible, ast.Name):
        return cible.id
    if isinstance(cible, ast.Attribute):
        return cible.attr
    return None


# ---------------------------------------------------------------------------
# Corpus : documents, etiquettes, referentiels, champs personnalises
# ---------------------------------------------------------------------------

_CREATE_MODELE = {
    "Tag": "tags",
    "Correspondent": "correspondents",
    "DocumentType": "types",
    "StoragePath": "chemins",
    "CustomField": "champs",
}


def _corpus(fonction: ast.FunctionDef) -> dict[str, dict]:
    """Relit les objets declares par le test (documents, tags, referentiels, champs, instances)."""
    objets: dict[str, dict] = {"documents": {}, "tags": {}, "correspondents": {}, "types": {},
                               "chemins": {}, "champs": {}, "instances": [], "inboxes": set()}

    for noeud in ast.walk(fonction):
        if not isinstance(noeud, ast.Assign) or not isinstance(noeud.value, ast.Call):
            continue
        variable = _cible(noeud)
        if variable is None:
            continue
        appel = noeud.value
        hote = _terminal(appel.func)
        kwargs = _lire_kwargs(appel)

        modele = _nom(appel.func).split(".")[0]
        if _nom(appel.func).endswith("Document.objects.create"):
            objets["documents"][variable] = {
                "cle": variable,
                "titre": _texte(kwargs.get("title")) or "",
                "empreinte": _texte(kwargs.get("checksum")) or "",
                "mime": _texte(kwargs.get("mime_type")) or "application/pdf",
                "contenu": _texte(kwargs.get("content")) or "",
                "etiquettes": [],
                "inbox": False,
                "statut": "valide",
                "type": _nom(kwargs["document_type"]) if isinstance(kwargs.get("document_type"), ast.Name) else None,
                "emetteur": _nom(kwargs["correspondent"]) if isinstance(kwargs.get("correspondent"), ast.Name) else None,
                "chemin": None,
                "chemin_var": _nom(kwargs["storage_path"]) if isinstance(kwargs.get("storage_path"), ast.Name) else None,
                "date": _texte(kwargs.get("created")),
                "ajoute": _texte(kwargs.get("added")),
                "champs": {},
                "proprietaire": _nom(kwargs["owner"]) if isinstance(kwargs.get("owner"), ast.Name) else None,
                "nom_origine": _texte(kwargs.get("original_filename")),
            }
        elif modele in _CREATE_MODELE:
            hote = modele
            famille = _CREATE_MODELE[hote]
            if hote == "StoragePath":
                objets[famille][variable] = {"nom": _texte(kwargs.get("name")) or "",
                                             "chemin": _texte(kwargs.get("path")) or ""}
            elif hote == "CustomField":
                objets[famille][variable] = {"nom": _texte(kwargs.get("name")) or "",
                                             "nature": _nom(kwargs.get("data_type")).split(".")[-1] if kwargs.get("data_type") else "string"}
            else:
                objets[famille][variable] = _texte(kwargs.get("name")) or ""
                marqueur = kwargs.get("is_inbox_tag")
                if hote == "Tag" and isinstance(marqueur, ast.Constant) and marqueur.value is True:
                    objets["inboxes"].add(variable)
        elif _terminal(appel.func) == "create" and _nom(appel.func).endswith("CustomFieldInstance.objects.create"):
            objets["instances"].append({
                "variable": variable,
                "document": _nom(kwargs.get("document")),
                "champ": _nom(kwargs.get("field")),
                "valeur": _valeur_instance(kwargs),
            })

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
        if document in objets["documents"] and isinstance(tag, ast.Name) and tag.id in objets["tags"]:
            objets["documents"][document]["etiquettes"].append(objets["tags"][tag.id])
            if tag.id in objets["inboxes"]:
                objets["documents"][document]["inbox"] = True

    _resoudre_referentiels(objets)
    _appliquer_instances(objets)
    return objets


def _resoudre_referentiels(objets: dict[str, dict]) -> None:
    """Remplace les noms de variables par la valeur du referentiel correspondant."""
    for doc in objets["documents"].values():
        if doc["type"] in objets["types"]:
            doc["type"] = objets["types"][doc["type"]]
        if doc["emetteur"] in objets["correspondents"]:
            doc["emetteur"] = objets["correspondents"][doc["emetteur"]]
        variateur = doc.pop("chemin_var", None)
        if variateur is not None and variateur in objets["chemins"]:
            doc["chemin"] = objets["chemins"][variateur]["nom"] + "/" + doc["cle"] + ".pdf"


def _valeur_instance(kwargs: dict[str, ast.AST]) -> str:
    for cle in ("value_text", "value_int", "value_float", "value_date", "value_url", "value_select", "value_bool"):
        if cle in kwargs:
            return _texte(kwargs[cle]) if _texte(kwargs[cle]) is not None else _nom(kwargs[cle])
    return ""


def _appliquer_instances(objets: dict[str, dict]) -> None:
    for instance in objets["instances"]:
        document = objets["documents"].get(instance["document"])
        champ = objets["champs"].get(instance["champ"])
        if document is None or champ is None:
            continue
        document["champs"][champ["nom"]] = instance["valeur"]


def _statut(doc: dict) -> str:
    return "a_controler" if doc.get("inbox") else "valide"


def _document_du_vecteur(doc: dict) -> dict:
    return {
        "cle": doc["cle"],
        "titre": doc["titre"],
        "empreinte": doc["empreinte"],
        "mime": doc["mime"],
        "contenu": doc["contenu"],
        "etiquettes": list(doc["etiquettes"]),
        "statut": _statut(doc),
        "type": doc["type"],
        "emetteur": doc["emetteur"],
        "chemin": doc["chemin"],
        "date": doc["date"],
        "ajoute": doc["ajoute"],
        "champs": dict(doc["champs"]),
        "proprietaire": doc["proprietaire"],
        "nom_origine": doc["nom_origine"],
    }


def _champs_du_vecteur(objets: dict) -> list[dict]:
    return [{"nom": c["nom"], "nature": c["nature"]} for c in objets["champs"].values()]


# ---------------------------------------------------------------------------
# Resolution des jetons dynamiques ({doc1.id}, {cf1.id}, {tag.id}...)
# ---------------------------------------------------------------------------


def _gabarit(noeud: ast.AST | None) -> str | None:
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


def _gabarit_expression(noeud: ast.AST) -> str | None:
    """Reconstruit `"&x=" + str(c.id)` ou `"&x=" + cf1_d1.value` en gabarit `&x={c.id}`."""
    if isinstance(noeud, ast.Constant) and isinstance(noeud.value, str):
        return noeud.value
    if isinstance(noeud, ast.BinOp) and isinstance(noeud.op, ast.Add):
        gauche = _gabarit_expression(noeud.left)
        droite = _gabarit_expression(noeud.right)
        if gauche is None or droite is None:
            return None
        return gauche + droite
    if isinstance(noeud, ast.JoinedStr):
        return _gabarit(noeud)
    if isinstance(noeud, ast.Call) and _terminal(noeud.func) == "str" and noeud.args:
        return "{" + _nom(noeud.args[0]) + "}"
    if isinstance(noeud, ast.Attribute):
        return "{" + _nom(noeud) + "}"
    return None


def _resoudre_jeton(jeton: str, objets: dict) -> str | None:
    if "{" not in jeton:
        return jeton

    def remplacer(trouve: re.Match[str]) -> str:
        variable, attribut = trouve.group(1), trouve.group(2)
        variable = variable.split(".")[-1]
        doc = objets["documents"].get(variable)
        champ = objets["champs"].get(variable)
        if doc is not None and attribut in ("id", "cle"):
            return doc["cle"]
        if champ is not None and attribut in ("id", "name"):
            return champ["nom"]
        for instance in objets["instances"]:
            if instance.get("variable") == variable and attribut == "value":
                return str(instance["valeur"])
        for famille in ("tags", "correspondents", "types"):
            if variable in objets[famille] and attribut in ("id", "name"):
                return objets[famille][variable]
        chemin = objets["chemins"].get(variable)
        if chemin is not None and attribut in ("id", "name", "path"):
            return chemin["nom"]
        return "\0"

    resolu = re.sub(r"\{([A-Za-z_][A-Za-z0-9_.]*?)\.([A-Za-z_][A-Za-z0-9_]*)\}", remplacer, jeton)
    if "\0" in resolu or "{" in resolu:
        return None
    return resolu


def _requete_parametres(suffixe: str, objets: dict) -> dict | None:
    """`&a=1&b=2` -> {a: "1", b: [".."]} ; None si un jeton reste irresolu."""
    parametres: dict[str, object] = {}
    for morceau in suffixe.split("&"):
        if morceau == "":
            continue
        if "=" not in morceau:
            return None
        cle, valeur = morceau.split("=", 1)
        jetons = valeur.split(",")
        resolus = [_resoudre_jeton(jeton, objets) for jeton in jetons]
        if any(jeton is None for jeton in resolus):
            return None
        parametres[cle] = resolus[0] if len(resolus) == 1 else resolus
    return parametres


# ---------------------------------------------------------------------------
# Famille filtres-documents : test_document_filters (self.client.get directs)
# ---------------------------------------------------------------------------

_PARAMETRES_DOCUMENTS_REJOUABLES = {
    "is_in_inbox", "tags__id__in", "tags__id__all", "tags__id__none", "id", "id__in",
    "mime_type", "checksum__iexact", "title_content", "custom_fields__icontains",
}

RAISON_RECHERCHE_COMBINEE = (
    "recherche ambiante `query=test` : Paperless (Whoosh) apparie des jetons entiers, la recherche de "
    "GEDv2 (BOOLEAN MODE prefixe) retient aussi « test2 » (src/documents/views.py:1290). Ecart de "
    "tokenisation assume : le filtre n'est pas rejoue avec la recherche qui l'accompagne."
)


def _raison_non_rejouable(cle: str, valeur: object = None) -> str | None:
    if cle == "tags__id__all" and isinstance(valeur, str) and "," not in valeur and not valeur.isdigit():
        return ("ObjectFilter de Paperless rend la queryset inchangee quand une valeur n'est pas une liste "
                "d'entiers (ValueError avale, src/documents/filters.py) : comportement Django assume, non rejoue.")
    if cle in ("original_filename", "original_filename__istartswith", "original_filename__iendswith",
               "original_filename__icontains", "original_filename__iexact"):
        return "nom d'origine du fichier non porte par le document GEDv2 (vit dans ingestion_travaux.nom_origine) : modele absent."
    if cle in ("owner", "owner__id", "owner__id__none", "owner__isnull"):
        return "proprietaire de document absent du modele GEDv2 : modele absent."
    if cle in ("archive_serial_number",):
        return "numero d'archive Paperless : GEDv2 numerote par l'identifiant d'index (filtre id) ; filtre dedie absent."
    if cle in ("storage_path__id", "storage_path__id__in", "storage_path__name", "storage_path__isnull",
               "storage_path__id__none"):
        return "chemin de stockage comme entite absent : GEDv2 range par dossier (chemin_relatif) ; correspondance fonctionnelle = dossier/sans_dossier."
    if cle in ("correspondent__id", "correspondent__id__in", "correspondent__id__none", "correspondent__name", "correspondent__isnull"):
        return "emetteur identifie par son NOM en GEDv2 (pas d'id) : correspondance fonctionnelle = emetteur/sans_emetteur."
    if cle in ("document_type__id", "document_type__id__in", "document_type__id__none", "document_type__name", "document_type__isnull"):
        return "type identifie par son NOM en GEDv2 (pas d'id) : correspondance fonctionnelle = type/sans_type."
    if cle in ("tags__id", "tags__name"):
        return "etiquette identifiee par son NOM en GEDv2 (pas d'id) : correspondance fonctionnelle = etiquette/sans_etiquettes."
    if cle.startswith("custom_field_query"):
        return "DSL JSON de requete sur champs personnalises (CustomFieldQueryParser) non porte ; reference src/documents/filters.py:749."
    if cle.startswith("custom_fields__id"):
        return "filtre par identifiant de definition de champ : GEDv2 adresse un champ par sa CLE (slug), pas par un id Paperless."
    if cle in ("created__date__lt", "created__date__gt", "created__date__gte", "created__date__lte",
               "added__date__lt", "added__date__gt", "added__date__gte", "added__date__lte"):
        return "operateur de borne Paperless ; GEDv2 a ses propres bornes (date_min/date_apres, ajoute_min/ajoute_apres)."
    return None


def _vecteurs_get_directs(chemin: Path, depot: Path, fonction: ast.FunctionDef, objets: dict) -> tuple[list[dict], list[dict]]:
    vecteurs: list[dict] = []
    ecartes: list[dict] = []
    corpus = [_document_du_vecteur(d) for d in objets["documents"].values()]
    champs = _champs_du_vecteur(objets)

    courant: dict | None = None

    def finaliser() -> None:
        if courant is None or courant["requete"] is None:
            return
        reference = f"{chemin.relative_to(depot).as_posix()}:{courant['ligne']}"
        identifiant = f"filtres-documents:{fonction.name}:{len(vecteurs) + len(ecartes) + 1:03d}"
        for cle, valeur in courant["requete"].items():
            raison = _raison_non_rejouable(cle, valeur)
            if raison is not None:
                ecartes.append({"id": identifiant, "requete": courant["requete"], "corpus": corpus,
                                "champs": champs, "raison": raison, "reference": reference})
                return
            if cle not in _PARAMETRES_DOCUMENTS_REJOUABLES:
                ecartes.append({"id": identifiant, "requete": courant["requete"], "corpus": corpus,
                                "champs": champs,
                                "raison": f"parametre sans equivalent GEDv2 : {cle}", "reference": reference})
                return
        total, cles = _assertions(courant["assertions"], objets)
        if total is None and cles == []:
            return
        sortie: dict[str, object] = {"total": total if total is not None else len(cles)}
        if cles:
            sortie["retenus"] = cles
        vecteurs.append({"id": identifiant, "entree": {"requete": courant["requete"], "corpus": corpus,
                                                       "champs": champs}, "sortie": sortie, "reference": reference})

    for instruction in fonction.body:
        appel = _get_direct(instruction)
        if appel is not None:
            if courant is not None:
                finaliser()
            courant = None
            url = _gabarit(appel.args[0]) if appel.args else None
            if url is None or "?" not in url:
                continue
            parametres = _requete_parametres(url.split("?", 1)[1], objets)
            if parametres is None:
                continue
            courant = {"requete": parametres, "ligne": appel.lineno, "assertions": []}
            continue
        if courant is not None:
            courant["assertions"].append(instruction)
    finaliser()
    return vecteurs, ecartes


def _get_direct(instruction: ast.stmt) -> ast.Call | None:
    if not isinstance(instruction, ast.Assign) or not isinstance(instruction.value, ast.Call):
        return None
    if _nom(instruction.value.func) != "self.client.get":
        return None
    return instruction.value


def _doc_reference(noeud: ast.AST, objets: dict) -> str | None:
    if isinstance(noeud, ast.Attribute) and noeud.attr == "id" and isinstance(noeud.value, ast.Name):
        return objets["documents"].get(noeud.value.id, {}).get("cle")
    if isinstance(noeud, ast.Attribute) and noeud.attr == "value":
        document = _valeur_document(noeud, objets)
        return document
    return None


def _valeur_document(noeud: ast.Attribute, objets: dict) -> str | None:
    # `cf1_d1.value` : on remonte la valeur par le corpus (champ du document).
    nom = _nom(noeud)
    if "." not in nom:
        return None
    instance = nom.split(".")[0]
    # Les variables d'instance ne sont pas nommees dans le corpus : on rend None (assertion ecartee).
    del instance
    return None


def _resultat_id(noeud: ast.AST) -> bool:
    if not isinstance(noeud, ast.Subscript) or _texte(noeud.slice) != "id":
        return False
    interieur = noeud.value
    return isinstance(interieur, ast.Subscript) and isinstance(interieur.value, ast.Name) and interieur.value.id == "results"


def _assertions(instructions: list[ast.stmt], objets: dict) -> tuple[int | None, list[str]]:
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
                cle = _doc_reference(droite, objets)
                if cle is not None:
                    cles.append(cle)
        elif nom == "assertCountEqual" and len(appel.args) == 2 and isinstance(appel.args[1], ast.List):
            for element in appel.args[1].elts:
                cle = _doc_reference(element, objets)
                if cle is not None:
                    cles.append(cle)
    ordre = list(objets["documents"])
    cles = _trier(cles, ordre)
    return total, cles


def _trier(cles: list[str], ordre: list[str]) -> list[str]:
    return sorted(set(cles), key=lambda cle: ordre.index(cle) if cle in ordre else 999)


# ---------------------------------------------------------------------------
# Famille filtres-documents : test_search_filtering (closure `search_query`)
# ---------------------------------------------------------------------------


def _search_vectors(chemin: Path, depot: Path, fonction: ast.FunctionDef, objets: dict) -> tuple[list[dict], list[dict]]:
    vecteurs: list[dict] = []
    ecartes: list[dict] = []
    corpus = [_document_du_vecteur(d) for d in objets["documents"].values()]
    champs = _champs_du_vecteur(objets)
    ordre = list(objets["documents"])

    for instruction in ast.walk(fonction):
        if not isinstance(instruction, ast.Expr) or not isinstance(instruction.value, ast.Call):
            continue
        appel = instruction.value
        if _terminal(appel.func) not in ("assertCountEqual", "assertEqual"):
            continue
        if len(appel.args) != 2 or not isinstance(appel.args[1], ast.List):
            continue
        premier = appel.args[0]
        if not (isinstance(premier, ast.Call) and _terminal(premier.func) == "search_query" and premier.args):
            continue
        suffixe = _gabarit_expression(premier.args[0])
        if suffixe is None:
            continue
        parametres = _requete_parametres(suffixe, objets)
        if parametres is None:
            continue
        identifiant = f"filtres-documents:test_search_filtering:{len(vecteurs) + len(ecartes) + 1:03d}"
        reference = f"{chemin.relative_to(depot).as_posix()}:{appel.lineno}"
        cles = []
        for element in appel.args[1].elts:
            cle = _doc_reference(element, objets)
            if cle is not None:
                cles.append(cle)
        if not cles:
            continue
        raison = None
        for cle in parametres:
            raison = _raison_non_rejouable(cle)
            if raison is not None:
                break
        entree = {"requete": {"q": "test", **parametres}, "corpus": corpus, "champs": champs}
        if raison is not None:
            ecartes.append({"id": identifiant, "entree": entree, "raison": raison, "reference": reference})
            continue
        vecteurs.append({"id": identifiant, "entree": entree,
                         "sortie": {"total": len(cles), "retenus": _trier(cles, ordre)}, "reference": reference})
    return vecteurs, ecartes


# ---------------------------------------------------------------------------
# Famille filtres-ressources : test_object_filters (listes de referentiels)
# ---------------------------------------------------------------------------

_RESSOURCES = {
    "tags": ("api:ressource:tags", ["tags"]),
    "correspondents": ("api:ressource:correspondents", ["correspondents"]),
    "document_types": ("api:ressource:document_types", ["types"]),
    "storage_paths": ("api:ressource:storage_paths", ["chemins"]),
}


def _vecteurs_referentiels(chemin: Path, depot: Path, fonction: ast.FunctionDef, objets: dict) -> tuple[list[dict], list[dict]]:
    vecteurs: list[dict] = []
    ecartes: list[dict] = []
    courant: dict | None = None

    def finaliser() -> None:
        if courant is None:
            return
        reference = f"{chemin.relative_to(depot).as_posix()}:{courant['ligne']}"
        identifiant = f"filtres-ressources:test_object_filters:{len(vecteurs) + len(ecartes) + 1:03d}"
        ressource = courant["ressource"]
        entree = {"ressource": ressource, "requete": courant["requete"]}
        if ressource == "storage_paths":
            ecartes.append({"id": identifiant, "entree": entree,
                            "raison": "chemin de stockage comme entite absent : GEDv2 range par dossier (chemin_relatif).",
                            "reference": reference})
            return
        total = courant["total"]
        if total is None:
            return
        vecteurs.append({"id": identifiant, "entree": entree, "sortie": {"total": total}, "reference": reference})

    for instruction in fonction.body:
        appel = _get_direct(instruction)
        if appel is not None:
            if courant is not None:
                finaliser()
            courant = None
            url = _gabarit(appel.args[0]) if appel.args else None
            if url is None:
                continue
            sans = url.split("/api/", 1)[-1].split("?", 1)[0].strip("/")
            ressource = sans
            valeur = url.split("?", 1)[1] if "?" in url else ""
            parametres = _requete_parametres(valeur, objets)
            if parametres is None or ressource not in _RESSOURCES:
                continue
            courant = {"ressource": ressource, "requete": parametres, "ligne": appel.lineno, "total": None}
            continue
        if courant is not None:
            total = _assertion_len(instruction)
            if total is not None:
                courant["total"] = total
    finaliser()
    return vecteurs, ecartes


def _assertion_len(instruction: ast.stmt) -> int | None:
    if not isinstance(instruction, ast.Expr) or not isinstance(instruction.value, ast.Call):
        return None
    appel = instruction.value
    if _terminal(appel.func) != "assertEqual" or len(appel.args) != 2:
        return None
    gauche, droite = appel.args
    if isinstance(gauche, ast.Call) and _terminal(gauche.func) == "len" and gauche.args and _nom(gauche.args[0]) == "results":
        return _entier(droite)
    return None


# ---------------------------------------------------------------------------
# Commit de reference et ecriture
# ---------------------------------------------------------------------------


def infos_commit(depot: Path) -> dict[str, str]:
    def git(*args: str) -> str:
        try:
            resultat = subprocess.run(["git", "-C", str(depot), *args], capture_output=True, text=True, check=True)
            return resultat.stdout.strip()
        except (OSError, subprocess.CalledProcessError):
            return ""

    return {"commit": git("rev-parse", "HEAD"), "commit_court": git("rev-parse", "--short", "HEAD"),
            "tag": git("describe", "--tags")}


def extraire_filtres_documents(depot: Path) -> tuple[list[dict], list[dict]]:
    vecteurs: list[dict] = []
    ecartes: list[dict] = []

    chemin = depot / "src" / "documents" / "tests" / "test_api_documents.py"
    arbre = ast.parse(chemin.read_text(encoding="utf-8"), filename=str(chemin))
    fonction = _fonctions_test(arbre).get("test_document_filters")
    if fonction is not None:
        objets = _corpus(fonction)
        vs, es = _vecteurs_get_directs(chemin, depot, fonction, objets)
        vecteurs += vs
        ecartes += es

    chemin = depot / "src" / "documents" / "tests" / "test_api_search.py"
    arbre = ast.parse(chemin.read_text(encoding="utf-8"), filename=str(chemin))
    fonction = _fonctions_test(arbre).get("test_search_filtering")
    if fonction is not None:
        # Les filtres combines a la recherche ambiante (`query=test`) ne sont pas rejoues : Paperless
        # (Whoosh) apparie des jetons ENTIERS, la recherche de GEDv2 (BOOLEAN MODE prefixe `+test*`)
        # retient aussi « test2 ». L'ecart de tokenisation est assume ; les filtres eux-memes sont
        # rejoues par les vecteurs de `test_document_filters` et par le test de comportement.
        objets = _corpus(fonction)
        vs, es = _search_vectors(chemin, depot, fonction, objets)
        for vecteur in vs:
            ecartes.append({"id": vecteur["id"], "entree": vecteur["entree"],
                            "raison": RAISON_RECHERCHE_COMBINEE, "reference": vecteur["reference"]})
        ecartes += es

    return vecteurs, ecartes


def _constante_classe(arbre: ast.Module, fonction: ast.FunctionDef) -> dict[str, str]:
    """Constantes de la classe de test (`ENDPOINT = "/api/custom_fields/"`)."""
    for classe in ast.walk(arbre):
        if not isinstance(classe, ast.ClassDef):
            continue
        if not any(noeud is fonction for noeud in classe.body):
            continue
        constantes: dict[str, str] = {}
        for instruction in classe.body:
            if isinstance(instruction, ast.Assign) and len(instruction.targets) == 1 and isinstance(instruction.targets[0], ast.Name):
                valeur = _texte(instruction.value)
                if valeur is not None:
                    constantes[instruction.targets[0].id] = valeur
        return constantes
    return {}


def extraire_filtres_ressources(depot: Path) -> tuple[list[dict], list[dict]]:
    """Filtres de la liste des champs personnalises (CustomFieldFilterSet.id / .name)."""
    chemin = depot / "src" / "documents" / "tests" / "test_api_custom_fields.py"
    arbre = ast.parse(chemin.read_text(encoding="utf-8"), filename=str(chemin))
    fonction = _fonctions_test(arbre).get("test_custom_field_filters")
    if fonction is None:
        return [], []
    constantes = _constante_classe(arbre, fonction)
    objets = _corpus(fonction)
    prefixe = constantes.get("ENDPOINT", "/api/custom_fields/")

    vecteurs: list[dict] = []
    ecartes: list[dict] = []
    courant: dict | None = None

    def finaliser() -> None:
        if courant is None:
            return
        reference = f"{chemin.relative_to(depot).as_posix()}:{courant['ligne']}"
        identifiant = f"filtres-ressources:test_custom_field_filters:{len(vecteurs) + len(ecartes) + 1:03d}"
        entree = {"ressource": "champs", "requete": courant["requete"], "champs": _champs_du_vecteur(objets)}
        raison = None
        for cle in courant["requete"]:
            if cle not in ("id", "id__in", "name__icontains", "nom", "nom_contient"):
                raison = f"parametre sans equivalent GEDv2 : {cle}"
                break
        if raison is not None:
            ecartes.append({"id": identifiant, "entree": entree, "raison": raison, "reference": reference})
            return
        if courant["total"] is None:
            return
        vecteurs.append({"id": identifiant, "entree": entree, "sortie": {"total": courant["total"]},
                         "reference": reference})

    for instruction in fonction.body:
        appel = _get_direct(instruction)
        if appel is not None:
            if courant is not None:
                finaliser()
            courant = None
            url = _gabarit(appel.args[0]) if appel.args else None
            if url is None or "?" not in url:
                continue
            url = url.replace("{self.ENDPOINT}", prefixe).replace("{ENDPOINT}", prefixe)
            parametres = _requete_parametres(url.split("?", 1)[1], objets)
            if parametres is None:
                continue
            courant = {"requete": parametres, "ligne": appel.lineno, "total": None}
            continue
        if courant is not None:
            total = _assertion_len(instruction)
            if total is not None:
                courant["total"] = total
    finaliser()
    return vecteurs, ecartes


CATEGORIES = {
    "filtres-documents": extraire_filtres_documents,
    "filtres-ressources": extraire_filtres_ressources,
}


def main() -> int:
    parser = argparse.ArgumentParser(description="Vecteurs de comportement de l'API Paperless (lot api-2)")
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
    for nom, extraction in CATEGORIES.items():
        vecteurs, ecartes = extraction(depot)
        document = {"version": 1, "categorie": nom, "reference": reference, "total": len(vecteurs),
                    "vecteurs": vecteurs, "non_rejouables": ecartes}
        (sortie / f"api-2-{nom}.json").write_text(json.dumps(document, ensure_ascii=False, indent=2) + "\n",
                                                  encoding="utf-8")
        print(f"OK: {len(vecteurs)} vecteurs, {len(ecartes)} non rejouables pour {nom} (api-2-{nom}.json)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
