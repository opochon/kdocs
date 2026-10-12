#!/usr/bin/env python3
"""Extraction mecanique des vecteurs de comportement de l'API Paperless-ngx (lot ged-parite-api-3).

Meme discipline que tools/parite/extraire-api.py et extraire-api-2.py : on lit en LECTURE SEULE les
tests de Paperless (C:\\ged\\ref\\paperless-ngx, tag v2.20.5, commit d1aa76e) et on n'ecrit que des
couples {entree, sortie} verifies par un test. AUCUNE ligne de code Paperless (GPL-3) n'est reprise.

Familles couvertes ici (les verbes REST des ressources et les vues annexes) :

  - ressources-classement : CustomField, SavedView, StoragePath (test_api_custom_fields.py,
                            test_api_documents.py, test_api_objects.py)
  - administration        : User, Group (test_api_permissions.py)
  - securite              : Profile, GenerateAuthToken, UiSettings, SystemStatus, RemoteVersion
                            (test_api_profile.py, test_api_uisettings.py, test_api_status.py,
                            test_api_remote_version.py)
  - masse                 : BulkDownload (test_api_bulk_download.py)
  - recherche             : Tasks, Trash (test_api_tasks.py, test_api_trash.py)
  - partage               : ShareLink (test_api_documents.py)

Chaque vecteur porte la methode HTTP, le chemin Paperless (gabarit `{objet}`) et le statut attendu.
Le rejeu cote GEDv2 traduit le chemin Paperless en route GEDv2 et verifie le statut. Les appels qui
dependent de Django/DRF (permissions objet, proprietaire, MFA, OAuth, Celery, mocks) partent dans
`non_rejouables` avec leur raison.
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


def _gabarit(noeud: ast.AST | None, constantes: dict[str, str] | None = None) -> str | None:
    """Reconstruit une URL (constante ou f-string) en gabarit `{variable}`.

    `self.ENDPOINT` est remplace par la constante de classe ; `doc.pk` devient `{doc}`.
    """
    constantes = constantes or {}
    if isinstance(noeud, ast.Constant) and isinstance(noeud.value, str):
        return noeud.value
    if isinstance(noeud, ast.JoinedStr):
        morceaux = []
        for valeur in noeud.values:
            if isinstance(valeur, ast.Constant) and isinstance(valeur.value, str):
                morceaux.append(valeur.value)
            elif isinstance(valeur, ast.FormattedValue):
                nom = _nom(valeur.value)
                if nom in constantes:
                    morceaux.append(constantes[nom])
                    continue
                nom = nom.split(".")[0]
                morceaux.append("{" + nom + "}")
            else:
                return None
        return "".join(morceaux)
    if isinstance(noeud, ast.BinOp) and isinstance(noeud.op, ast.Add):
        gauche = _gabarit(noeud.left, constantes)
        droite = _gabarit(noeud.right, constantes)
        if gauche is None or droite is None:
            return None
        return gauche + droite
    if isinstance(noeud, ast.Attribute):
        nom = _nom(noeud)
        if nom in constantes:
            return constantes[nom]
        return "{" + nom.split(".")[0] + "}"
    return None


_STATUTS = {
    "HTTP_200_OK": 200,
    "HTTP_201_CREATED": 201,
    "HTTP_204_NO_CONTENT": 204,
    "HTTP_400_BAD_REQUEST": 400,
    "HTTP_403_FORBIDDEN": 403,
    "HTTP_404_NOT_FOUND": 404,
    "HTTP_405_METHOD_NOT_ALLOWED": 405,
}


def _statut(noeud: ast.AST | None) -> int | None:
    direct = _entier(noeud)
    if direct is not None:
        return direct
    nom = _nom(noeud)
    return _STATUTS.get(nom.split(".")[-1])


def _dict_litteral(noeud: ast.AST | None) -> dict | None:
    """Dictionnaire litteral : valeurs constantes seulement, le reste en `{objet}`."""
    if not isinstance(noeud, ast.Dict):
        return None
    resultat: dict[str, object] = {}
    for cle, valeur in zip(noeud.keys, noeud.values):
        nom = _texte(cle)
        if nom is None:
            return None
        if isinstance(valeur, ast.Constant):
            resultat[nom] = valeur.value
        elif isinstance(valeur, ast.List):
            elements = []
            for e in valeur.elts:
                if isinstance(e, ast.Constant):
                    elements.append(e.value)
                else:
                    elements.append("{" + _nom(e) + "}")
            resultat[nom] = elements
        elif isinstance(valeur, ast.Dict):
            sous = _dict_litteral(valeur)
            if sous is None:
                return None
            resultat[nom] = sous
        else:
            resultat[nom] = "{" + _nom(valeur) + "}"
    return resultat


def _corps(appel: ast.Call) -> dict | None:
    for mot in appel.keywords:
        if mot.arg in ("data", "json") and isinstance(mot.value, ast.Dict):
            return _dict_litteral(mot.value)
    for argument in appel.args[1:2]:
        if isinstance(argument, ast.Dict):
            return _dict_litteral(argument)
    return None


def _appels_client(fonction: ast.FunctionDef) -> list[tuple[str, ast.Call]]:
    """Appels `self.client.<verbe>(...)` avec la variable de resultat eventuelle."""
    appels: list[tuple[str, ast.Call]] = []
    for noeud in ast.walk(fonction):
        if not isinstance(noeud, ast.Call):
            continue
        nom = _nom(noeud.func)
        if not nom.startswith("self.client."):
            continue
        verbe = nom.split(".")[-1]
        if verbe not in ("get", "post", "put", "patch", "delete"):
            continue
        appels.append((verbe, noeud))
    appels.sort(key=lambda paire: paire[1].lineno)
    return appels


def _variable_resultat(fonction: ast.FunctionDef, appel: ast.Call) -> str | None:
    for noeud in ast.walk(fonction):
        if isinstance(noeud, ast.Assign) and isinstance(noeud.value, ast.Call) and noeud.value is appel:
            if len(noeud.targets) == 1 and isinstance(noeud.targets[0], ast.Name):
                return noeud.targets[0].id
    return None


def _assertions(fonction: ast.FunctionDef) -> list[tuple[str, int, int]]:
    """Assertions `self.assertEqual(<var>.status_code, <statut>)` : (var, statut, ligne)."""
    resultats: list[tuple[str, int, int]] = []
    for noeud in ast.walk(fonction):
        if not isinstance(noeud, ast.Expr) or not isinstance(noeud.value, ast.Call):
            continue
        appel = noeud.value
        if _terminal(appel.func) != "assertEqual" or len(appel.args) != 2:
            continue
        gauche, droite = appel.args
        if not (isinstance(gauche, ast.Attribute) and gauche.attr == "status_code"):
            continue
        variable = _nom(gauche.value)
        statut = _statut(droite)
        if statut is not None:
            resultats.append((variable, statut, appel.lineno))
    return resultats


# ---------------------------------------------------------------------------
# Familles : fichiers et fonctions retenues
# ---------------------------------------------------------------------------

RAISON_DJANGO = (
    "permissions objet / proprietaire / force_authenticate : comportement du framework Django-"
    "rest-framework, sans equivalent GEDv2 (le modele de droits est par dossier et par portee de jeton)."
)
RAISON_MFA = "MFA TOTP (django-allauth / django-otp) : modele GEDv2 absent, voir note de lot."
RAISON_SOCIAL = "comptes sociaux django-allauth / OAuth : infrastructure Django, hors GEDv2."
RAISON_CELERY = "tache Celery / WebSocket : infrastructure celery-redis, hors GEDv2."
RAISON_MOCK = "test avec mock d'un service externe (reseau, cache, index Paperless) : non rejouable."
RAISON_INFRA = "vue d'infrastructure Django (system status, version distante) : hors modele GEDv2."

RAISON_STORAGE = (
    "chemin de stockage comme entite absent : GEDv2 range par dossier (`documents.chemin_relatif` et "
    "`securite_dossiers`) ; la correspondance fonctionnelle est le dossier, pas un gabarit de chemin."
)

# (fichier, fonctions, mode, raison). mode = "vecteurs" (rejoue cote GEDv2) ou "non_rejouables".
FAMILLES: dict[str, list[tuple[str, list[str], str, str | None]]] = {
    "ressources-classement": [
        ("src/documents/tests/test_api_custom_fields.py",
         ["test_create_custom_field", "test_create_custom_field_nonunique_name",
          "test_custom_field_update_offloaded_once"], "vecteurs", None),
        ("src/documents/tests/test_api_documents.py",
         ["test_saved_view_create_update_patch", "test_saved_view_display_options"], "vecteurs", None),
        ("src/documents/tests/test_api_objects.py",
         ["test_api_create_storage_path", "test_api_create_invalid_storage_path",
          "test_api_create_storage_path_rejects_traversal", "test_api_update_storage_path",
          "test_api_delete_storage_path"], "non_rejouables", RAISON_STORAGE),
    ],
    "administration": [
        ("src/documents/tests/test_api_permissions.py",
         ["test_get_users", "test_create_user", "test_update_user", "test_get_groups",
          "test_create_group", "test_update_group"], "vecteurs", None),
    ],
    "securite": [
        ("src/documents/tests/test_api_profile.py",
         ["test_get_profile", "test_update_profile", "test_update_auth_token", "test_get_totp",
          "test_activate_totp"], "non_rejouables", RAISON_MFA),
        ("src/documents/tests/test_api_uisettings.py",
         ["test_api_get_ui_settings", "test_api_set_ui_settings", "test_settings_must_be_dict"], "vecteurs", None),
        ("src/documents/tests/test_api_status.py", ["test_system_status"], "non_rejouables", RAISON_INFRA),
        ("src/documents/tests/test_api_remote_version.py",
         ["test_remote_version_enabled_no_update_prefix"], "non_rejouables", RAISON_INFRA),
    ],
    "masse": [
        ("src/documents/tests/test_api_bulk_download.py",
         ["test_download_default", "test_download_insufficient_permissions"], "vecteurs", None),
    ],
    "recherche": [
        ("src/documents/tests/test_api_tasks.py",
         ["test_get_tasks", "test_get_single_task_status", "test_get_single_task_status_not_valid"], "vecteurs", None),
        ("src/documents/tests/test_api_trash.py",
         ["test_api_trash", "test_trash_api_empty_all", "test_api_trash_invalid_params"], "vecteurs", None),
    ],
    "partage": [
        ("src/documents/tests/test_api_documents.py",
         ["test_create_share_links", "test_share_links_permissions_aware"], "vecteurs", None),
    ],
}


def extraire_famille(depot: Path, famille: str,
                     definition: list[tuple[str, list[str], str, str | None]]) -> tuple[list[dict], list[dict]]:
    vecteurs: list[dict] = []
    ecartes: list[dict] = []
    for relatif, fonctions, mode, raison in definition:
        chemin = depot / relatif
        arbre = ast.parse(chemin.read_text(encoding="utf-8"), filename=str(chemin))
        toutes = {
            noeud.name: noeud
            for noeud in ast.walk(arbre)
            if isinstance(noeud, ast.FunctionDef) and noeud.name.startswith("test_")
        }
        for fonction_nom in fonctions:
            fonction = toutes.get(fonction_nom)
            if fonction is None:
                continue
            constantes = _constantes_classe(arbre, fonction)
            assertions = _assertions(fonction)
            for verbe, appel in _appels_client(fonction):
                url = _gabarit(appel.args[0], constantes) if appel.args else None
                if url is None or "?" in url or "{" + "self" + "}" in url:
                    continue
                variable = _variable_resultat(fonction, appel)
                statut = None
                ligne = appel.lineno
                for var, code, ligne_assertion in assertions:
                    if variable is not None and var == variable and ligne_assertion > appel.lineno:
                        statut = code
                        ligne = ligne_assertion
                        break
                if statut is None:
                    continue
                reference = f"{relatif}:{ligne}"
                identifiant = f"{famille}:{fonction_nom}:{len(vecteurs) + len(ecartes) + 1:03d}"
                entree = {"methode": verbe.upper(), "chemin": url}
                corps = _corps(appel)
                if corps:
                    entree["corps"] = corps
                if mode == "non_rejouables":
                    ecartes.append({"id": identifiant, "entree": entree,
                                    "raison": raison or RAISON_DJANGO, "reference": reference})
                else:
                    vecteurs.append({"id": identifiant, "entree": entree,
                                     "sortie": {"statut": statut}, "reference": reference})
    return vecteurs, ecartes


def _constantes_classe(arbre: ast.Module, fonction: ast.FunctionDef) -> dict[str, str]:
    """Constantes de la classe de test (`ENDPOINT = "/api/custom_fields/"`)."""
    for classe in ast.walk(arbre):
        if not isinstance(classe, ast.ClassDef):
            continue
        if not any(noeud is fonction for noeud in classe.body):
            continue
        constantes: dict[str, str] = {}
        for instruction in classe.body:
            if isinstance(instruction, ast.Assign) and len(instruction.targets) == 1 \
                    and isinstance(instruction.targets[0], ast.Name):
                valeur = _texte(instruction.value)
                if valeur is not None:
                    constantes["self." + instruction.targets[0].id] = valeur
        return constantes
    return {}


def infos_commit(depot: Path) -> dict[str, str]:
    def git(*args: str) -> str:
        try:
            resultat = subprocess.run(["git", "-C", str(depot), *args], capture_output=True, text=True, check=True)
            return resultat.stdout.strip()
        except (OSError, subprocess.CalledProcessError):
            return ""

    return {"commit": git("rev-parse", "HEAD"), "commit_court": git("rev-parse", "--short", "HEAD"),
            "tag": git("describe", "--tags")}


def main() -> int:
    parser = argparse.ArgumentParser(description="Vecteurs de comportement de l'API Paperless (lot api-3)")
    parser.add_argument("--depot", default=None)
    parser.add_argument("--sortie", default="tests/parite/vecteurs")
    args = parser.parse_args()

    depot = Path(args.depot or os.environ.get("PAPERLESS_DEPOT", r"C:\ged\ref\paperless-ngx"))
    if not (depot / "src" / "documents" / "tests" / "test_api_objects.py").exists():
        print(f"ERREUR: depot Paperless introuvable : {depot}", file=sys.stderr)
        return 2

    sortie = Path(args.sortie)
    sortie.mkdir(parents=True, exist_ok=True)
    reference = infos_commit(depot)
    for famille, definition in FAMILLES.items():
        vecteurs, ecartes = extraire_famille(depot, famille, definition)
        document = {"version": 1, "categorie": famille, "reference": reference, "total": len(vecteurs),
                    "vecteurs": vecteurs, "non_rejouables": ecartes}
        (sortie / f"api-3-{famille}.json").write_text(json.dumps(document, ensure_ascii=False, indent=2) + "\n",
                                                      encoding="utf-8")
        print(f"OK: {len(vecteurs)} vecteurs, {len(ecartes)} non rejouables pour {famille} (api-3-{famille}.json)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
