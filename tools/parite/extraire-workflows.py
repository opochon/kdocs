#!/usr/bin/env python3
"""Extraction mecanique des vecteurs de comportement des WORKFLOWS Paperless-ngx (lot ged-parite-modele-1).

Meme discipline que tools/parite/extraire-api.py : on lit en LECTURE SEULE les tests de Paperless
(C:\\ged\\ref\\paperless-ngx, tag v2.20.5) et on n'ecrit que des couples {entree, sortie} verifies par un
test, jamais une ligne de code de Paperless (GPL-3).

Familles extraites, une par sous-famille du lot :
  - declencheurs   : filtres de WorkflowTrigger (source, chemin, nom, regle de courrier, correspondance
                     de contenu, etiquettes, type, emetteur, dossier, requete de champs personnalises)
  - planification  : WorkflowTrigger planifie (schedule_date_field, offset, recurrence)
  - affectation    : WorkflowAction d'affectation (assign_*)
  - retrait        : WorkflowAction de retrait (remove_*)
  - courriel-webhook : WorkflowActionEmail et WorkflowActionWebhook

Un vecteur porte le document, le declencheur, le type de declencheur et les actions ; la sortie porte
« declenche » (le workflow a-t-il ete retenu) et/ou les attributs resultants. La correspondance
fonctionnelle Paperless -> GEDv2 vit cote PHPUnit (tests/php/Parite/ModeleWorkflowsTest.php).

Tout test qui s'appuie sur Celery, Django/DRF, un chemin de bac a sable non materialisable ou une
horloge implicite non fixable part dans `non_rejouables` avec sa raison et sa reference.

Sortie : tests/parite/vecteurs/modele-workflows.json
"""

from __future__ import annotations

import argparse
import ast
import json
import os
import subprocess
import sys
from pathlib import Path

MAINTENANT = "2026-04-10"

TYPE_TIRAGE = {
    "CONSUMPTION": 1,
    "DOCUMENT_ADDED": 2,
    "DOCUMENT_UPDATED": 3,
    "SCHEDULED": 4,
}

MATCHING = {
    "MATCH_NONE": 0, "MATCH_ANY": 1, "MATCH_ALL": 2,
    "MATCH_LITERAL": 3, "MATCH_REGEX": 4, "MATCH_FUZZY": 5, "MATCH_AUTO": 6,
}

SOURCE = {
    "ConsumeFolder": 1, "ApiUpload": 2, "MailFetch": 3, "WebUI": 4,
}

TYPE_ACTION = {
    "ASSIGNMENT": 1, "REMOVAL": 2, "EMAIL": 3, "WEBHOOK": 4,
}

DATES = {
    "ADDED": "added", "CREATED": "created", "MODIFIED": "modified", "CUSTOM_FIELD": "custom_field",
}

CHAMPS_AFFECTATION = [
    "assign_title", "assign_correspondent", "assign_document_type", "assign_storage_path", "assign_owner",
]

# Le nom de l'attribut Paperless -> le nom du champ dans le vecteur GEDv2.
LISTES = {
    "assign_tags": "tags", "remove_tags": "tags",
    "assign_view_users": "users", "assign_change_users": "users", "remove_view_users": "users",
    "remove_change_users": "users", "remove_owners": "users",
    "assign_view_groups": "groups", "assign_change_groups": "groups",
    "remove_view_groups": "groups", "remove_change_groups": "groups",
    "assign_custom_fields": "custom_fields", "remove_custom_fields": "custom_fields",
    "remove_correspondents": "correspondents", "remove_document_types": "doctypes",
    "remove_storage_paths": "paths",
}

ATTRIBUTS_DOC = {
    "title": "titre", "correspondent": "emetteur", "document_type": "type",
    "storage_path": "dossier", "owner": "proprietaire",
}


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
    if isinstance(noeud, ast.Constant) and isinstance(noeud.value, str):
        return noeud.value
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


def _booleen(noeud: ast.AST | None) -> bool:
    if isinstance(noeud, ast.Constant):
        if isinstance(noeud.value, bool):
            return noeud.value
    return False


def _kwargs(appel: ast.Call) -> dict[str, ast.AST]:
    return {mot.arg: mot.value for mot in appel.keywords if mot.arg is not None}


def _creations(fonction: ast.FunctionDef) -> dict[str, dict]:
    """Variables locales creees par `Classe.objects.create(...)` avec leurs mots-cles."""
    creations: dict[str, dict] = {}
    for noeud in ast.walk(fonction):
        if not isinstance(noeud, ast.Assign) or len(noeud.targets) != 1 or not isinstance(noeud.targets[0], ast.Name):
            continue
        appel = noeud.value
        if not isinstance(appel, ast.Call) or not _nom(appel.func).endswith(".objects.create"):
            continue
        creations[noeud.targets[0].id] = {"classe": _nom(appel.func).split(".")[0], "kwargs": _kwargs(appel)}
    return creations


def _set_et_add(fonction: ast.FunctionDef) -> dict[str, dict[str, list[ast.AST]]]:
    """`variable.attribut.set([...])` / `.add(...)` : les listes affectees a un objet local."""
    resultat: dict[str, dict[str, list[ast.AST]]] = {}
    for noeud in ast.walk(fonction):
        if not isinstance(noeud, ast.Call) or not isinstance(noeud.func, ast.Attribute):
            continue
        if not isinstance(noeud.func.value, ast.Attribute) or not isinstance(noeud.func.value.value, ast.Name):
            continue
        methode = noeud.func.attr
        if methode not in ("set", "add"):
            continue
        variable = noeud.func.value.value.id
        attribut = noeud.func.value.attr
        valeurs: list[ast.AST] = []
        if methode == "set" and noeud.args and isinstance(noeud.args[0], ast.List):
            valeurs = list(noeud.args[0].elts)
        else:
            valeurs = list(noeud.args)
        resultat.setdefault(variable, {}).setdefault(attribut, []).extend(valeurs)
    return resultat


def _chaine_ou_joint(noeud: ast.AST | None) -> str | None:
    if isinstance(noeud, ast.Constant) and isinstance(noeud.value, str):
        return noeud.value
    return None


def _entier_ou_ref(noeud: ast.AST | None) -> int | None:
    entier = _entier(noeud)
    if entier is not None:
        return entier
    nom = _nom(noeud)
    for cle, valeur in MATCHING.items():
        if nom.endswith(cle):
            return valeur
    for cle, valeur in TYPE_TIRAGE.items():
        if nom.endswith(cle):
            return valeur
    for cle, valeur in TYPE_ACTION.items():
        if nom.endswith(cle):
            return valeur
    for cle, valeur in SOURCE.items():
        if nom.endswith(cle):
            return valeur
    return None


def _sources(noeud: ast.AST | None) -> list[int]:
    """`f"{DocumentSource.ApiUpload},{DocumentSource.ConsumeFolder}"` -> [2, 1]."""
    if isinstance(noeud, ast.JoinedStr):
        sources = []
        for valeur in noeud.values:
            if isinstance(valeur, ast.FormattedValue):
                nom = _nom(valeur.value)
                for cle, code in SOURCE.items():
                    if nom.endswith(cle):
                        sources.append(code)
        return sources
    if isinstance(noeud, ast.Constant) and isinstance(noeud.value, str):
        return [code for cle, code in SOURCE.items() if cle in noeud.value]
    return []


# --------------------------------------------------------------------------- symboles du setUp


def _symboles_setup(arbre: ast.Module) -> dict[str, dict]:
    """`self.c = Correspondent.objects.create(name=...)` -> nom, classe, identifiant."""
    symboles: dict[str, dict] = {}
    for noeud in ast.walk(arbre):
        if not isinstance(noeud, ast.Assign) or len(noeud.targets) != 1:
            continue
        cible = noeud.targets[0]
        if not (isinstance(cible, ast.Attribute) and isinstance(cible.value, ast.Name) and cible.value.id == "self"):
            continue
        appel = noeud.value
        if not isinstance(appel, ast.Call) or not _nom(appel.func).endswith(".objects.create"):
            continue
        classe = _nom(appel.func).split(".")[0]
        kwargs = _kwargs(appel)
        nom = _texte(kwargs.get("name")) or _texte(kwargs.get("path")) or cible.attr
        symboles[cible.attr] = {"classe": classe, "nom": nom}
    return symboles


def _valeur(noeud: ast.AST | None, symboles: dict[str, dict]) -> object:
    """Resout une valeur : constante, self.X, self.X.pk, DocumentSource.X, MatchingModel.X…"""
    if noeud is None:
        return None
    texte = _texte(noeud)
    if texte is not None:
        return texte
    entier = _entier(noeud)
    if entier is not None:
        return entier
    nom = _nom(noeud)
    for prefixe, valeur in MATCHING.items():
        if nom.endswith(prefixe):
            return valeur
    for prefixe, valeur in TYPE_TIRAGE.items():
        if nom.endswith(prefixe):
            return valeur
    for prefixe, valeur in TYPE_ACTION.items():
        if nom.endswith(prefixe):
            return valeur
    for prefixe, valeur in DATES.items():
        if nom.endswith(prefixe):
            return valeur
    if nom in ("True", "False"):
        return nom == "True"
    if nom.startswith("self.") and nom.count(".") >= 2 and nom.endswith(".pk"):
        symbole = symboles.get(nom.split(".")[1])
        return symbole["nom"] if symbole else nom
    if nom.startswith("self."):
        cle = nom.split(".")[1]
        if cle in symboles:
            # `self.dt` d'un type de document porte le nom ; `self.sp` le chemin.
            return symboles[cle]["nom"]
    return None


def _liste(noeud: ast.AST | None, symbole: dict[str, dict]) -> list[object]:
    if isinstance(noeud, ast.List):
        return [_valeur(element, symbole) for element in noeud.elts if _valeur(element, symbole) is not None]
    valeur = _valeur(noeud, symbole)
    return [valeur] if valeur is not None else []


# --------------------------------------------------------------------------- document et trigger


def _date_relative(noeud: ast.AST | None) -> str | None:
    """`timezone.now() - timedelta(days=N)` -> date MAINTENANT - N jours (ou +)."""
    if noeud is None:
        return None
    # Un appel `timezone.now() - timedelta(days=N)` est un BinOp.
    if not isinstance(noeud, ast.BinOp):
        return None
    signe = 1 if isinstance(noeud.op, ast.Sub) else -1
    for noeud_interne in ast.walk(noeud):
        if isinstance(noeud_interne, ast.Call) and _terminal(noeud_interne.func) == "timedelta":
            kwargs = _kwargs(noeud_interne)
            jours = _entier(kwargs.get("days"))
            if jours is not None:
                from datetime import datetime, timedelta
                base = datetime.strptime(MAINTENANT, "%Y-%m-%d")
                return (base - timedelta(days=signe * jours)).strftime("%Y-%m-%d")
    return None


def _dates_locales(fonction: ast.FunctionDef) -> dict[str, str]:
    """`created = timezone.now() - timedelta(days=2)` -> {'created': 'MAINTENANT - 2 jours'}."""
    dates: dict[str, str] = {}
    for noeud in ast.walk(fonction):
        if not isinstance(noeud, ast.Assign) or len(noeud.targets) != 1 or not isinstance(noeud.targets[0], ast.Name):
            continue
        date = _date_relative(noeud.value)
        if date is not None:
            dates[noeud.targets[0].id] = date
    return dates


def _document(creation: dict | None, liste_attr: dict[str, list[ast.AST]], symboles: dict[str, dict]) -> dict:
    document = {
        "titre": "sample test",
        "emetteur": None,
        "type": None,
        "dossier": None,
        "proprietaire": None,
        "etiquettes": [],
        "nom_fichier": None,
        "chemin": None,
        "contenu": "",
        "source": 1,
        "mailrule": None,
        "droits": {"lecture": {"utilisateurs": [], "groupes": []}, "modification": {"utilisateurs": [], "groupes": []}},
        "champs": {},
        "dates": {"added": MAINTENANT, "created": MAINTENANT, "modified": MAINTENANT},
    }
    if creation is not None:
        kwargs = creation["kwargs"]
        document["titre"] = _texte(kwargs.get("title")) or "sample test"
        document["emetteur"] = _valeur(kwargs.get("correspondent"), symboles)
        document["type"] = _valeur(kwargs.get("document_type"), symboles)
        document["dossier"] = _valeur(kwargs.get("storage_path"), symboles)
        document["proprietaire"] = _valeur(kwargs.get("owner"), symboles)
        document["nom_fichier"] = _texte(kwargs.get("original_filename")) or _texte(kwargs.get("filename"))
        document["contenu"] = _texte(kwargs.get("content")) or ""
        document["mailrule"] = _entier(kwargs.get("mailrule"))
        for champ in ("added", "created", "modified"):
            date = _date_relative(kwargs.get(champ))
            if date is not None:
                document["dates"][champ] = date
    for etq in liste_attr.get("tags", []):
        valeur = _valeur(etq, symboles)
        if valeur is not None:
            document["etiquettes"].append(valeur)
    return document


def _trigger(creation: dict, liste_attr: dict[str, list[ast.AST]], symbole: dict[str, dict]) -> dict:
    kwargs = creation["kwargs"]
    trigger: dict[str, object] = {}
    type_ = _entier_ou_ref(kwargs.get("type"))
    if type_ is not None:
        trigger["type"] = type_
    sources = _sources(kwargs.get("sources"))
    if sources:
        trigger["sources"] = sources
    for cle in ("filter_path", "filter_filename"):
        valeur = _texte(kwargs.get(cle))
        if valeur is not None:
            trigger[cle] = valeur
    if "filter_mailrule" in kwargs:
        valeur = _valeur(kwargs["filter_mailrule"], symbole)
        trigger["filter_mailrule"] = valeur
    match = _texte(kwargs.get("match"))
    if match is not None:
        trigger["match"] = match
    algo = _entier_ou_ref(kwargs.get("matching_algorithm"))
    if algo is not None:
        trigger["matching_algorithm"] = algo
    if "is_insensitive" in kwargs:
        trigger["is_insensitive"] = _booleen(kwargs["is_insensitive"])
    for champ_retire in ("schedule_offset_days", "schedule_recurring_interval_days"):
        valeur = _entier(kwargs.get(champ_retire))
        if valeur is not None:
            trigger[champ_retire] = valeur
    if "schedule_is_recurring" in kwargs:
        trigger["schedule_is_recurring"] = _booleen(kwargs["schedule_is_recurring"])
    date_field = _texte(kwargs.get("schedule_date_field")) or _valeur(kwargs.get("schedule_date_field"), symbole)
    if date_field is not None:
        trigger["schedule_date_field"] = date_field
    if "schedule_date_custom_field" in kwargs:
        trigger["schedule_date_custom_field"] = _valeur(kwargs["schedule_date_custom_field"], symbole)
    # La requete de champs personnalises est un JSON de Paperless : on la traduit en forme GEDv2 minimale.
    requete = _requete_champs(kwargs.get("filter_custom_field_query"), symbole)
    if requete is not None:
        trigger["filter_custom_field_query"] = requete
    for cle, genre in (("filter_has_tags", "tags"), ("filter_has_all_tags", "tags"), ("filter_has_not_tags", "tags"),
                       ("filter_has_not_correspondents", "correspondents"),
                       ("filter_has_not_document_types", "doctypes"), ("filter_has_not_storage_paths", "paths")):
        valeurs = [_valeur(valeur, symbole) for valeur in liste_attr.get(cle, [])]
        valeurs = [v for v in valeurs if v is not None]
        if valeurs:
            trigger[cle] = valeurs
    for cle in ("filter_has_correspondent", "filter_has_document_type", "filter_has_storage_path"):
        if cle in kwargs:
            trigger[cle] = _valeur(kwargs[cle], symbole)
    return trigger


def _requete_champs(noeud: ast.AST | None, symbole: dict[str, dict]) -> str | None:
    """`json.dumps(["AND", [[cf1.id, "exact", "valeur"]]])` -> `Champ 1=egal:valeur`."""
    if noeud is None:
        return None
    if not isinstance(noeud, ast.Call) or _terminal(noeud.func) != "dumps" or not noeud.args:
        return None
    expression = noeud.args[0]
    if not isinstance(expression, ast.List) or len(expression.elts) < 2:
        return None
    conditions = expression.elts[1]
    if not isinstance(conditions, ast.List):
        return None
    morceaux = []
    for condition in conditions.elts:
        if not isinstance(condition, ast.List) or len(condition.elts) != 3:
            continue
        champ = _valeur(condition.elts[0], symbole)
        operateur = _texte(condition.elts[1])
        valeur = _texte(condition.elts[2])
        if champ is None or operateur is None or valeur is None:
            continue
        morceaux.append(f"{champ}={'egal' if operateur == 'exact' else operateur}:{valeur}")
    return ",".join(morceaux) if morceaux else None


def _action(creation: dict, liste_attr: dict[str, list[ast.AST]], symbole: dict[str, dict], ordre: int) -> dict:
    kwargs = creation["kwargs"]
    action: dict[str, object] = {"type": _entier_ou_ref(kwargs.get("type")) or 1, "order": ordre}
    for cle in CHAMPS_AFFECTATION:
        if cle in kwargs:
            action[cle] = _valeur(kwargs[cle], symbole)
    for cle in ("remove_all_tags", "remove_all_document_types", "remove_all_correspondents",
                "remove_all_storage_paths", "remove_all_owners", "remove_all_permissions",
                "remove_all_custom_fields"):
        if cle in kwargs:
            action[cle] = _booleen(kwargs[cle])
    for attribut, valeurs in liste_attr.items():
        genre = LISTES.get(attribut)
        if genre is None:
            continue
        resolus = [_valeur(valeur, symbole) for valeur in valeurs]
        action[attribut] = [v for v in resolus if v is not None]
    # Variantes courriel et webhook : les valeurs vivent dans des objets distincts.
    if creation["classe"] == "WorkflowActionEmail":
        action["email"] = {
            "subject": _texte(kwargs.get("subject")) or "",
            "body": _texte(kwargs.get("body")) or "",
            "to": _texte(kwargs.get("to")) or "",
            "include_document": _booleen(kwargs.get("include_document")),
        }
    if creation["classe"] == "WorkflowActionWebhook":
        action["webhook"] = {
            "url": _texte(kwargs.get("url")) or "",
            "use_params": _booleen(kwargs.get("use_params")) if "use_params" in kwargs else True,
            "as_json": _booleen(kwargs.get("as_json")),
            "body": _texte(kwargs.get("body")),
            "params": _dict_simple(kwargs.get("params"), symbole),
            "headers": _dict_simple(kwargs.get("headers"), symbole),
            "include_document": _booleen(kwargs.get("include_document")),
        }
    return action


def _dict_simple(noeud: ast.AST | None, symbole: dict[str, dict]) -> dict:
    if not isinstance(noeud, ast.Dict):
        return {}
    resultat = {}
    for cle, valeur in zip(noeud.keys, noeud.values):
        nom = _texte(cle)
        if nom is None:
            continue
        resultat[nom] = _valeur(valeur, symbole)
    return resultat


# --------------------------------------------------------------------------- consommation et instances


def _consommation(fonction: ast.FunctionDef, symboles: dict[str, dict]) -> dict:
    """`tasks.consume_file(ConsumableDocument(source=..., original_file=...), None)` -> document."""
    for noeud in ast.walk(fonction):
        if not isinstance(noeud, ast.Call) or _terminal(noeud.func) != "consume_file" or not noeud.args:
            continue
        consommable = noeud.args[0]
        if not isinstance(consommable, ast.Call):
            continue
        kwargs = _kwargs(consommable)
        source = _entier_ou_ref(kwargs.get("source")) or 1
        fichier = _terminal(kwargs.get("original_file")) or "simple"
        return {"source": source, "nom_fichier": fichier + ".pdf", "chemin": "bac-a-sable/" + fichier + ".pdf"}
    return {}


def _type_evenement(fonction: ast.FunctionDef) -> int | None:
    for noeud in ast.walk(fonction):
        if not isinstance(noeud, ast.Call):
            continue
        nom = _nom(noeud.func)
        if nom.endswith("document_matches_workflow") and len(noeud.args) >= 3:
            return _entier_ou_ref(noeud.args[2])
        if nom.endswith("run_workflows") and noeud.args:
            return _entier_ou_ref(noeud.args[0])
        if nom.endswith("check_scheduled_workflows"):
            return 4
        if nom.endswith("consume_file"):
            return 1
    return None


def _declenchement(fonction: ast.FunctionDef, famille: str, symboles: dict[str, dict]) -> bool | None:
    """Le workflow est-il retenu ? Deduit des journaux attendus et des assertions du test Paperless."""
    for noeud in ast.walk(fonction):
        if isinstance(noeud, ast.JoinedStr):
            for valeur in noeud.values:
                if isinstance(valeur, ast.Constant) and isinstance(valeur.value, str):
                    if "Document did not match" in valeur.value:
                        return False
                    if "Document matched " in valeur.value:
                        return True
    # Une action d'affectation ou de retrait prouvee par ses attributs resultants : le workflow a agi.
    # (Pour un retrait, `assertIsNone(owner)` prouve l'action, pas l'absence de declenchement.)
    if famille in ("affectation", "retrait") and _attributs_attendus(fonction, symboles):
        return True
    for noeud in ast.walk(fonction):
        if not isinstance(noeud, ast.Expr) or not isinstance(noeud.value, ast.Call):
            continue
        appel = noeud.value
        nom = _terminal(appel.func)
        if nom in ("assertTrue", "assertFalse") and appel.args:
            cible = _nom(appel.args[0])
            if "matched" in cible:
                return nom == "assertTrue"
        if nom in ("assert_called_once", "assert_called_once_with"):
            return True
        if nom == "assertEqual" and len(appel.args) == 2:
            gauche, droite = appel.args
            if isinstance(gauche, ast.Attribute) and gauche.attr == "owner":
                valeur = _valeur(droite, symboles)
                return valeur is not None and "user" in str(valeur)
        if nom == "assertIsNone" and appel.args:
            gauche = appel.args[0]
            if isinstance(gauche, ast.Attribute) and gauche.attr == "owner":
                return False
    # Une action d'affectation ou de retrait prouvee par ses attributs resultants : le workflow a agi.
    if famille in ("affectation", "retrait") and _attributs_attendus(fonction, symboles):
        return True
    if famille == "courriel-webhook":
        return None
    return None


def _attributs_attendus(fonction: ast.FunctionDef, symboles: dict[str, dict]) -> dict:
    """Attributs resultants lus dans les assertions de Paperless."""
    attributs: dict[str, object] = {}
    for noeud in ast.walk(fonction):
        if not isinstance(noeud, ast.Expr) or not isinstance(noeud.value, ast.Call):
            continue
        appel = noeud.value
        nom = _terminal(appel.func)
        if nom == "assertEqual" and len(appel.args) == 2:
            gauche, droite = appel.args
            if isinstance(gauche, ast.Attribute):
                attr = gauche.attr
                if attr in ATTRIBUTS_DOC:
                    valeur = _valeur(droite, symboles)
                    if valeur is not None:   # une valeur dynamique (gabarit) n'est pas materialisable
                        attributs[ATTRIBUTS_DOC[attr]] = valeur
                if attr == "count":
                    racine = _nom(gauche.value)
                    if "tags" in racine and "custom" not in racine:
                        attributs["etiquettes_nombre"] = _entier(droite)
        elif nom == "assertIsNone" and appel.args and isinstance(appel.args[0], ast.Attribute):
            attr = appel.args[0].attr
            if attr in ATTRIBUTS_DOC:
                attributs[ATTRIBUTS_DOC[attr]] = None
    return attributs


# --------------------------------------------------------------------------- assemblage


CIBLES = {
    "test_document_added_no_match_trigger_type": "declencheurs",
    "test_document_added_no_match_filename": "declencheurs",
    "test_workflow_no_match_source": "declencheurs",
    "test_document_added_no_match_content_matching": "declencheurs",
    "test_document_added_match_content_matching": "declencheurs",
    "test_document_added_no_match_tags": "declencheurs",
    "test_document_added_no_match_all_tags": "declencheurs",
    "test_document_added_excluded_tags": "declencheurs",
    "test_document_added_excluded_correspondent": "declencheurs",
    "test_document_added_excluded_document_types": "declencheurs",
    "test_document_added_excluded_storage_paths": "declencheurs",
    "test_document_added_no_match_doctype": "declencheurs",
    "test_document_added_no_match_correspondent": "declencheurs",
    "test_document_added_no_match_storage_path": "declencheurs",
    "test_document_added_custom_field_query_no_match": "declencheurs",
    "test_document_added_custom_field_query_match": "declencheurs",
    "test_document_added_workflow": "affectation",
    "test_removal_action_document_updated_workflow": "retrait",
    "test_removal_action_document_updated_removeall": "retrait",
    "test_workflow_email_action": "courriel-webhook",
    "test_workflow_webhook_action_body": "courriel-webhook",
    "test_workflow_scheduled_trigger_created": "planification",
    "test_workflow_scheduled_trigger_added": "planification",
    "test_workflow_scheduled_trigger_custom_field": "planification",
    "test_workflow_scheduled_trigger_too_early": "planification",
    "test_offset_positive_means_after": "planification",
    "test_workflow_scheduled_trigger_negative_offset_created": "planification",
}

NON_REJOUABLES = {
    "test_workflow_fnmatch_path": "chemin de bac a sable Django (self.dirs.scratch_dir) non materialisable dans un vecteur ; le filtre de chemin reste prouve par les tests unitaires GEDv2.",
    "test_workflow_scheduled_trigger_modified": "le champ `modified` est auto_now dans Paperless : le test le MOCKE (documents.models.Document.objects.filter) ; hors modele GEDv2.",
    "test_new_trigger_type_raises_exception": "comportement propre a Django : Paperless leve une Exception Python pour un type de declencheur inconnu ; GEDv2 ne leve pas (correspondance fausse).",
    "test_consumption_trigger_requires_filter_configuration": "validation de serializer DRF (WorkflowTriggerSerializer) ; pas de modele de serializer dans GEDv2.",
}


def _reference(chemin: Path, depot: Path, fonction: ast.FunctionDef) -> str:
    return f"{chemin.relative_to(depot).as_posix()}:{fonction.lineno}"


def _vecteur(nom: str, fonction: ast.FunctionDef, symbole: dict[str, dict], chemin: Path, depot: Path) -> dict | None:
    famille = CIBLES[nom]
    creations = _creations(fonction)
    listes = _set_et_add(fonction)
    type_evenement = _type_evenement(fonction)

    declencheur_var = next((v for v, c in creations.items() if c["classe"] == "WorkflowTrigger"), None)
    if declencheur_var is None:
        return None
    trigger = _trigger(creations[declencheur_var], listes.get(declencheur_var, {}), symbole)

    actions: list[dict] = []
    for creation in creations.values():
        if creation["classe"] == "WorkflowAction":
            action = _action(creation, listes.get(_cle_de(fonction, creation), {}), symbole, len(actions))
            # Courriel et webhook sont portes par leur variante (email=/webhook=), pas par l'action nue.
            if int(action.get("type") or 1) in (3, 4):
                continue
            actions.append(action)
    # Variantes courriel et webhook liees par `email=` / `webhook=`.
    for creation in creations.values():
        if creation["classe"] != "WorkflowAction":
            continue
        for cle, code in (("email", 3), ("webhook", 4)):
            cible = creation["kwargs"].get(cle)
            if isinstance(cible, ast.Name) and cible.id in creations:
                action = _action(creations[cible.id], listes.get(cible.id, {}), symbole, 0)
                action["type"] = code
                actions.append(action)

    document_var = next((v for v, c in creations.items() if c["classe"] == "Document"), None)
    document = _document(creations[document_var] if document_var else None,
                         listes.get(document_var, {}) if document_var else {}, symbole)
    # Les dates relatives passees par une variable locale (`created = now() - timedelta(days=2)`).
    dates_locales = _dates_locales(fonction)
    if document_var is not None:
        for champ in ("added", "created", "modified"):
            valeur = creations[document_var]["kwargs"].get(champ)
            if isinstance(valeur, ast.Name) and valeur.id in dates_locales:
                document["dates"][champ] = dates_locales[valeur.id]
    for cle, valeur in _consommation(fonction, symbole).items():
        document[cle] = valeur

    # Champs personnalises : `CustomFieldInstance.objects.create(document=doc, field=self.cf1, value_text=...)`.
    for noeud in ast.walk(fonction):
        if not isinstance(noeud, ast.Call) or _nom(noeud.func) != "CustomFieldInstance.objects.create":
            continue
        kwargs = _kwargs(noeud)
        champ = _valeur(kwargs.get("field"), symbole)
        if champ is None:
            continue
        valeur: object = _texte(kwargs.get("value_text"))
        if valeur is None:
            valeur = _date_relative(kwargs.get("value_date"))
        if valeur is None:
            valeur = _entier(kwargs.get("value_int"))
        document["champs"][str(champ)] = valeur

    derniere = None
    for noeud in ast.walk(fonction):
        if isinstance(noeud, ast.Call) and _nom(noeud.func) == "WorkflowRun.objects.create":
            date = _date_relative(_kwargs(noeud).get("run_at"))
            if date is not None:
                derniere = date

    if type_evenement is None:
        declencheur_type = _entier_ou_ref(creations[declencheur_var]["kwargs"].get("type"))
        type_evenement = declencheur_type if declencheur_type is not None else 2

    declenche = _declenchement(fonction, famille, symbole)
    if declenche is None:
        return None

    entree = {"type": type_evenement, "document": document, "trigger": trigger, "actions": actions}
    sortie: dict[str, object] = {"declenche": declenche}
    if famille in ("affectation", "retrait"):
        attributs = _attributs_attendus(fonction, symbole)
        if attributs:
            sortie["attributs"] = attributs
    if famille == "planification" and derniere is not None:
        sortie["derniere_execution"] = derniere
    return {
        "id": f"{famille}:{nom}",
        "famille": famille,
        "entree": entree,
        "sortie": sortie,
        "reference": _reference(chemin, depot, fonction),
    }


def _cle_de(fonction: ast.FunctionDef, creation: dict) -> str:
    """Retrouve le nom de variable d'une creation (pour retrouver ses listes `.add`)."""
    for noeud in ast.walk(fonction):
        if (isinstance(noeud, ast.Assign) and len(noeud.targets) == 1 and isinstance(noeud.targets[0], ast.Name)
                and isinstance(noeud.value, ast.Call) and _nom(noeud.value.func) == "WorkflowAction.objects.create"
                and _kwargs(noeud.value) == creation["kwargs"]):
            return noeud.targets[0].id
    return ""


def extraire(depot: Path) -> tuple[list[dict], list[dict]]:
    chemin = depot / "src" / "documents" / "tests" / "test_workflows.py"
    arbre = ast.parse(chemin.read_text(encoding="utf-8"), filename=str(chemin))
    fonctions = {n.name: n for n in ast.walk(arbre) if isinstance(n, ast.FunctionDef)}
    symbole = _symboles_setup(arbre)

    vecteurs: list[dict] = []
    ecartes: list[dict] = []
    for nom in CIBLES:
        fonction = fonctions.get(nom)
        if fonction is None:
            ecartes.append({"id": f"introuvable:{nom}", "raison": "fonction absente du fichier de reference",
                            "reference": "src/documents/tests/test_workflows.py"})
            continue
        vecteur = _vecteur(nom, fonction, symbole, chemin, depot)
        if vecteur is None:
            ecartes.append({"id": f"non-extrait:{nom}",
                            "raison": "le test ne se laisse pas reduire a un couple {entree, sortie} mecanique",
                            "reference": _reference(chemin, depot, fonction)})
        else:
            vecteurs.append(vecteur)
    for nom, raison in NON_REJOUABLES.items():
        fonction = fonctions.get(nom)
        ecartes.append({"id": f"non-rejouable:{nom}", "raison": raison,
                        "reference": _reference(chemin, depot, fonction) if fonction else "src/documents/tests/test_workflows.py"})
    return vecteurs, ecartes


def infos_commit(depot: Path) -> dict[str, str]:
    def git(*args: str) -> str:
        try:
            resultat = subprocess.run(["git", "-C", str(depot), *args], capture_output=True, text=True, check=True)
            return resultat.stdout.strip()
        except (OSError, subprocess.CalledProcessError):
            return ""

    return {"commit": git("rev-parse", "HEAD"), "commit_court": git("rev-parse", "--short", "HEAD"), "tag": git("describe", "--tags")}


def main() -> int:
    parser = argparse.ArgumentParser(description="Vecteurs de comportement des workflows Paperless")
    parser.add_argument("--depot", default=None)
    parser.add_argument("--sortie", default="tests/parite/vecteurs")
    args = parser.parse_args()

    depot = Path(args.depot or os.environ.get("PAPERLESS_DEPOT", r"C:\ged\ref\paperless-ngx"))
    if not (depot / "src" / "documents" / "tests" / "test_workflows.py").exists():
        print(f"ERREUR: depot Paperless introuvable : {depot}", file=sys.stderr)
        return 2

    sortie = Path(args.sortie)
    sortie.mkdir(parents=True, exist_ok=True)
    vecteurs, ecartes = extraire(depot)
    document = {
        "version": 1,
        "categorie": "modele-workflows",
        "reference": infos_commit(depot),
        "total": len(vecteurs),
        "vecteurs": vecteurs,
        "non_rejouables": ecartes,
    }
    (sortie / "modele-workflows.json").write_text(
        json.dumps(document, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(f"OK: {len(vecteurs)} vecteurs, {len(ecartes)} non rejouables pour modele-workflows")
    return 0


if __name__ == "__main__":
    sys.exit(main())
