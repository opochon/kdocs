#!/usr/bin/env python3
"""Extraction mecanique des vecteurs de comportement de l'API Paperless-ngx (lot ged-parite-api-4).

Meme discipline que tools/parite/extraire-api-2.py et extraire-api-3.py : on lit en LECTURE SEULE les
tests de Paperless (C:\\ged\\ref\\paperless-ngx, tag v2.20.5, commit d1aa76e) et on n'ecrit que des
couples {entree, sortie} verifies par un test. AUCUNE ligne de code Paperless (GPL-3) n'est reprise.

Familles couvertes ici (les 79 ecarts `api` requalifies par le juge + les absents d'api-3) :

  - taches     : GET/POST /api/tasks/ (liste, filtre, acknowledge, run)  (test_api_tasks.py)
  - totp       : GET/POST/DELETE /api/profile/totp/, deactivate_totp      (test_api_profile.py,
                 test_api_permissions.py)
  - jeton      : POST /api/token/ (obtention du jeton, code MFA)           (test_api_permissions.py)
  - statut     : GET /api/status/, GET /api/remote_version/                (test_api_status.py,
                 test_api_remote_version.py)
  - app-config : GET/PATCH /api/config/, GET /logo/                        (test_api_app_config.py)

Chaque vecteur porte la methode HTTP, le chemin Paperless (gabarit `{objet}`) et le statut attendu.
Le rejeu cote GEDv2 traduit le chemin Paperless en route GEDv2 et verifie le statut. Les appels qui
dependent de Django/DRF (permissions objet, proprietaire, MFA mocke, OAuth, Celery, mocks) partent dans
`non_rejouables` avec leur raison.
"""

from __future__ import annotations

import argparse
import ast
import json
import os
import subprocess
import sys
from pathlib import Path

# ---------------------------------------------------------------------------
# Utilitaires AST (identiques a extraire-api-3.py, notre propre code)
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
    """Reconstruit une URL (constante ou f-string) en gabarit `{variable}`."""
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
    "HTTP_401_UNAUTHORIZED": 401,
    "HTTP_403_FORBIDDEN": 403,
    "HTTP_404_NOT_FOUND": 404,
    "HTTP_405_METHOD_NOT_ALLOWED": 405,
    "HTTP_500_INTERNAL_SERVER_ERROR": 500,
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
    appels: list[tuple[str, ast.Call]] = []
    for noeud in ast.walk(fonction):
        if not isinstance(noeud, ast.Call):
            continue
        nom = _nom(noeud.func)
        if not (nom.startswith("self.client.") or nom.startswith("rest_api_client.")):
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
    # Style pytest : `assert response.status_code == status.HTTP_200_OK` (test_remote_version.py).
    for noeud in ast.walk(fonction):
        if not isinstance(noeud, ast.Assert) or not isinstance(noeud.test, ast.Compare):
            continue
        comparaison = noeud.test
        if len(comparaison.ops) != 1 or not isinstance(comparaison.ops[0], ast.Eq):
            continue
        gauche, droite = comparaison.left, comparaison.comparators[0]
        if not (isinstance(gauche, ast.Attribute) and gauche.attr == "status_code"):
            continue
        variable = _nom(gauche.value)
        statut = _statut(droite)
        if statut is not None:
            resultats.append((variable, statut, noeud.lineno))
    return resultats


# ---------------------------------------------------------------------------
# Familles : fichiers et fonctions retenues
# ---------------------------------------------------------------------------

RAISON_MOCK = "test avec mock d'un service (MFA, Celery, reseau GitHub) : non rejouable tel quel."
RAISON_DRF = "permissions objet / proprietaire / superuser Django : comportement DRF, sans equivalent GEDv2."
RAISON_JETON = "obtention de jeton avec code MFA mocke : comportement django-otp, rejoue autrement cote GEDv2."

FAMILLES: dict[str, list[tuple[str, list[str], str, str | None]]] = {
    "taches": [
        ("src/documents/tests/test_api_tasks.py",
         ["test_get_tasks", "test_get_single_task_status", "test_get_single_task_status_not_valid",
          "test_acknowledge_tasks", "test_acknowledge_tasks_requires_change_permission",
          "test_run_train_classifier_task", "test_run_task_requires_superuser"], "vecteurs", None),
    ],
    "totp": [
        ("src/documents/tests/test_api_profile.py",
         ["test_get_totp", "test_activate_totp", "test_deactivate_totp", "test_update_auth_token",
          "test_profile_not_logged_in"], "vecteurs", None),
        ("src/documents/tests/test_api_permissions.py",
         ["test_deactivate_totp"], "vecteurs", None),
    ],
    "jeton": [
        ("src/documents/tests/test_api_permissions.py",
         ["test_get_token_mfa_enabled"], "vecteurs", None),
    ],
    "statut": [
        ("src/documents/tests/test_api_status.py",
         ["test_system_status", "test_system_status_insufficient_permissions"], "vecteurs", None),
    ],
    "version": [
        ("src/documents/tests/test_api_remote_version.py",
         ["test_remote_version_enabled_no_update_prefix"], "vecteurs", None),
    ],
    "app-config": [
        ("src/documents/tests/test_api_app_config.py",
         ["test_api_get_config", "test_api_update_config", "test_create_not_allowed",
          "test_api_replace_app_logo"], "vecteurs", None),
    ],
}

# Familles du lot dont Paperless n'a PAS de test dedie : le contrat DRF est transcrit ici, a la main,
# sous la meme forme {entree, sortie}. La reference cite la classe de vue, la note dit pourquoi.
MANUELS: dict[str, dict] = {
    "partage": {
        "note": (
            "Paperless n'a pas de test d'update d'un lien de partage : `ShareLinkViewSet` est un "
            "ModelViewSet (src/documents/views.py:2602), PUT/PATCH rendent 200. Vecteurs transcrits du "
            "contrat DRF, aucun code Paperless repris."
        ),
        "vecteurs": [
            {
                "id": "partage:sharelink_partial_update:001",
                "entree": {"methode": "PATCH", "chemin": "/api/share_links/{id}/",
                           "corps": {"expiration": "{expiration}"}},
                "sortie": {"statut": 200},
                "reference": "src/documents/views.py:2602",
            },
            {
                "id": "partage:sharelink_update:002",
                "entree": {"methode": "PUT", "chemin": "/api/share_links/{id}/",
                           "corps": {"expiration": "{expiration}"}},
                "sortie": {"statut": 200},
                "reference": "src/documents/views.py:2602",
            },
        ],
        "non_rejouables": [],
    },
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
                                    "raison": raison or RAISON_DRF, "reference": reference})
                else:
                    vecteurs.append({"id": identifiant, "entree": entree,
                                     "sortie": {"statut": statut}, "reference": reference})
    return vecteurs, ecartes


def _constantes_classe(arbre: ast.Module, fonction: ast.FunctionDef) -> dict[str, str]:
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
    parser = argparse.ArgumentParser(description="Vecteurs de comportement de l'API Paperless (lot api-4)")
    parser.add_argument("--depot", default=None)
    parser.add_argument("--sortie", default="tests/parite/vecteurs")
    args = parser.parse_args()

    depot = Path(args.depot or os.environ.get("PAPERLESS_DEPOT", r"C:\ged\ref\paperless-ngx"))
    if not (depot / "src" / "documents" / "tests" / "test_api_tasks.py").exists():
        print(f"ERREUR: depot Paperless introuvable : {depot}", file=sys.stderr)
        return 2

    sortie = Path(args.sortie)
    sortie.mkdir(parents=True, exist_ok=True)
    reference = infos_commit(depot)
    for famille, definition in FAMILLES.items():
        vecteurs, ecartes = extraire_famille(depot, famille, definition)
        document = {"version": 1, "categorie": famille, "reference": reference, "total": len(vecteurs),
                    "vecteurs": vecteurs, "non_rejouables": ecartes}
        (sortie / f"api-4-{famille}.json").write_text(json.dumps(document, ensure_ascii=False, indent=2) + "\n",
                                                      encoding="utf-8")
        print(f"OK: {len(vecteurs)} vecteurs, {len(ecartes)} non rejouables pour {famille} (api-4-{famille}.json)")
    # Familles SANS test dedie cote Paperless : le contrat DRF est transcrit a la main (meme discipline
    # {entree, sortie}), la reference cite la classe de vue. Aucune ligne de code Paperless reprise.
    for famille, manuel in MANUELS.items():
        document = {"version": 1, "categorie": famille, "reference": reference, "total": len(manuel["vecteurs"]),
                    "vecteurs": manuel["vecteurs"], "non_rejouables": manuel.get("non_rejouables", []),
                    "note": manuel.get("note", "")}
        (sortie / f"api-4-{famille}.json").write_text(json.dumps(document, ensure_ascii=False, indent=2) + "\n",
                                                      encoding="utf-8")
        print(f"OK: {len(manuel['vecteurs'])} vecteurs (manuel) pour {famille} (api-4-{famille}.json)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
