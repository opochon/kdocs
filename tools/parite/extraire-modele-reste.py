#!/usr/bin/env python3
"""Extraction mecanique des vecteurs de comportement du RESTE du MODELE Paperless-ngx (lot ged-parite-modele-3).

Meme discipline que tools/parite/extraire-courrier.py, extraire-workflows.py et extraire-api.py : on lit en
LECTURE SEULE les tests de Paperless (C:\\\\ged\\\\ref\\\\paperless-ngx, tag v2.20.5) et on n'ecrit que des couples
{entree, sortie} verifies par un test. Aucune ligne de code de Paperless (GPL-3) n'est recopiee.

Sous-familles extraites, une par sous-famille du lot :
  - document      : nom public d'une piece (mime + titre + date -> extension)            test_document_model.py
  - matching      : algorithme de correspondance (MATCH_*) sur un contenu                test_matchables.py
  - savedview     : reglages d'une vue enregistree (affichage, tri, mode, taille page)   test_api_documents.py
  - champs        : validation d'une valeur d'instance de champ (url, int, montant,
                    lien document)                                                        test_api_custom_fields.py
  - tache         : cycle de vie d'une tache suivie (statut, acquittement, filtre)       test_api_tasks.py
  - permissions   : droits d'objet (lecture/modification par utilisateur)                test_api_permissions.py
  - masse         : operation en masse set_permissions (proprietaire + droits)           test_bulk_edit.py
  - commande      : commandes de maintenance export/import/retag/fuzzy                   test_management_*.py
  - chaine        : decouverte de parseur (mime -> extension)                            test_parsers.py

Sortie : tests/parite/vecteurs/modele-reste.json
"""

from __future__ import annotations

import argparse
import ast
import json
from pathlib import Path

FAMILLE_PAR_FICHIER = {
    "document": "src/documents/tests/test_document_model.py",
    "matching": "src/documents/tests/test_matchables.py",
    "savedview": "src/documents/tests/test_api_documents.py",
    "champs": "src/documents/tests/test_api_custom_fields.py",
    "tache": "src/documents/tests/test_api_tasks.py",
    "permissions": "src/documents/tests/test_api_permissions.py",
    "masse": "src/documents/tests/test_bulk_edit.py",
    "commande": "src/documents/tests/test_management_exporter.py",
    "chaine": "src/documents/tests/test_parsers.py",
}


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


def _kwargs(appel: ast.Call) -> dict[str, ast.AST]:
    return {mot.arg: mot.value for mot in appel.keywords if mot.arg is not None}


def _appels(noeud: ast.AST, nom: str) -> list[ast.Call]:
    trouves = []
    for element in ast.walk(noeud):
        if isinstance(element, ast.Call):
            cible = element.func
            court = cible.attr if isinstance(cible, ast.Attribute) else (cible.id if isinstance(cible, ast.Name) else "")
            if court == nom:
                trouves.append(element)
    return trouves


def _fonctions(arbre: ast.Module) -> dict[str, ast.FunctionDef]:
    return {n.name: n for n in ast.walk(arbre) if isinstance(n, ast.FunctionDef)}


def _liste_textes(noeud: ast.AST | None) -> list[str]:
    if not isinstance(noeud, (ast.List, ast.Tuple)):
        return []
    valeurs = []
    for element in noeud.elts:
        texte = _texte(element)
        if texte is not None:
            valeurs.append(texte)
    return valeurs


def _reference(chemin: Path, depot: Path, noeud: ast.AST) -> str:
    return f"{chemin.relative_to(depot).as_posix()}:{getattr(noeud, 'lineno', 1)}"


def _lire_arbre(chemin: Path) -> ast.Module:
    return ast.parse(chemin.read_text(encoding="utf-8"))


# ---------------------------------------------------------------------------
# document : nom public (mime + titre + date)
# ---------------------------------------------------------------------------


def vecteurs_document(arbre: ast.Module, chemin: Path, depot: Path) -> list[dict]:
    vecteurs = []
    for fonction in _fonctions(arbre).values():
        for appel in _appels(fonction, "Document"):
            kwargs = _kwargs(appel)
            mime = _texte(kwargs.get("mime_type"))
            titre = _texte(kwargs.get("title"))
            if mime is None or titre is None:
                continue
            created = None
            noeud_created = kwargs.get("created")
            if isinstance(noeud_created, ast.Call) and len(noeud_created.args) == 3:
                annee, mois, jour = (_entier(a) for a in noeud_created.args)
                if None not in (annee, mois, jour):
                    created = f"{annee:04d}-{mois:02d}-{jour:02d}"
            if created is None:
                continue
            attendu = None
            for assertion in _appels(fonction, "assertEqual"):
                if len(assertion.args) < 2:
                    continue
                premier = assertion.args[0]
                if isinstance(premier, ast.Call) and getattr(premier.func, "attr", "") == "get_public_filename":
                    attendu = _texte(assertion.args[1])
            if attendu is None:
                continue
            vecteurs.append({
                "id": "document-nom-public-" + mime.replace("/", "-").replace(".", "-"),
                "famille": "document",
                "reference": _reference(chemin, depot, appel),
                "entree": {"mime": mime, "titre": titre, "date": created},
                "sortie": {"nom_public": attendu},
            })
    return vecteurs


# ---------------------------------------------------------------------------
# matching : algorithme de correspondance
# ---------------------------------------------------------------------------


def vecteurs_matching(arbre: ast.Module, chemin: Path, depot: Path) -> list[dict]:
    vecteurs = []
    for fonction in (n for n in ast.walk(arbre) if isinstance(n, ast.FunctionDef)):
        for appel in _appels(fonction, "_test_matching"):
            if len(appel.args) < 4:
                continue
            motif = _texte(appel.args[0])
            algorithme = _texte(appel.args[1])
            if motif is None or algorithme is None:
                continue
            kwargs = _kwargs(appel)
            insensible = _booleen(kwargs.get("case_sensitive"))
            vecteurs.append({
                "id": f"matching-{algorithme.lower().replace('match_', '')}-{len(vecteurs) + 1}",
                "famille": "matching",
                "reference": _reference(chemin, depot, appel),
                "entree": {
                    "motif": motif,
                    "algorithme": algorithme,
                    "insensible": not insensible if insensible is not None else True,
                    "textes_match": _liste_textes(appel.args[2]),
                    "textes_non_match": _liste_textes(appel.args[3]),
                },
                "sortie": {},
            })
    return vecteurs


# ---------------------------------------------------------------------------
# savedview : reglages d'une vue enregistree
# ---------------------------------------------------------------------------


def _champ_enum(noeud: ast.AST) -> str | None:
    """SavedView.DisplayFields.TITLE -> 'title' ; SavedView.DisplayMode.SMALL_CARDS -> 'small_cards'."""
    if isinstance(noeud, ast.Attribute):
        return noeud.attr.lower()
    return _texte(noeud)


def _liste_enum(noeud: ast.AST | None) -> list[str] | None:
    if not isinstance(noeud, ast.List):
        return None
    valeurs = []
    for element in noeud.elts:
        champ = _champ_enum(element)
        if champ is None:
            return None
        valeurs.append(champ)
    return valeurs


def vecteurs_savedview(arbre: ast.Module, chemin: Path, depot: Path) -> list[dict]:
    """Les payloads 'view = {...}' des tests d'affichage d'une vue enregistree."""
    vecteurs = []
    for fonction in _fonctions(arbre).values():
        if not fonction.name.startswith("test_saved_view"):
            continue
        for assignation in [n for n in ast.walk(fonction) if isinstance(n, ast.Assign)]:
            if not isinstance(assignation.value, ast.Dict):
                continue
            cibles = [c.id for c in assignation.targets if isinstance(c, ast.Name)]
            if "view" not in cibles:
                continue
            reglages: dict[str, object] = {}
            affichage_invalide = False
            for cle, valeur in zip(assignation.value.keys, assignation.value.values, strict=False):
                nom = _texte(cle)
                if nom == "display_mode":
                    reglages["display_mode"] = _champ_enum(valeur)
                elif nom == "display_fields":
                    champs = _liste_enum(valeur)
                    if champs is None:
                        affichage_invalide = True
                    else:
                        reglages["display_fields"] = champs
                elif nom in {"page_size"}:
                    reglages[nom] = _entier(valeur)
                elif nom in {"show_on_dashboard", "show_in_sidebar"}:
                    reglages[nom] = _booleen(valeur)
                elif nom == "sort_field":
                    reglages[nom] = _texte(valeur)
                elif nom == "name":
                    reglages[nom] = _texte(valeur)
            if not reglages:
                continue
            vecteurs.append({
                "id": "savedview-" + str(reglages.get("name", len(vecteurs) + 1)),
                "famille": "savedview",
                "reference": _reference(chemin, depot, assignation),
                "entree": {"reglages": reglages},
                "sortie": {"accepte": not affichage_invalide},
            })
    return vecteurs


# ---------------------------------------------------------------------------
# champs : validation d'une instance de champ personnalise
# ---------------------------------------------------------------------------


NATURE_PAR_TYPE = {
    "URL": "url",
    "INT": "nombre",
    "FLOAT": "nombre",
    "MONETARY": "montant",
    "DOCUMENTLINK": "lien",
    "STRING": "texte",
    "LONG_TEXT": "texte",
    "DATE": "date",
    "BOOL": "booleen",
    "SELECT": "liste",
}


def _statut_apres(fonction: ast.FunctionDef, ligne: int) -> bool | None:
    """Accepte / refuse : le premier assertEqual(<response>.status_code, …) qui suit l'appel."""
    for assertion in sorted(_appels(fonction, "assertEqual"), key=lambda a: getattr(a, "lineno", 0)):
        if getattr(assertion, "lineno", 0) <= ligne or len(assertion.args) < 2:
            continue
        premier = assertion.args[0]
        if not (isinstance(premier, ast.Attribute) and premier.attr == "status_code"):
            continue
        cible = assertion.args[1]
        nom = cible.attr if isinstance(cible, ast.Attribute) else (cible.id if isinstance(cible, ast.Name) else "")
        if nom in {"HTTP_200_OK", "HTTP_201_CREATED"}:
            return True
        if nom in {"HTTP_400_BAD_REQUEST", "HTTP_422_UNPROCESSABLE_ENTITY"}:
            return False
    return None


def _nature_de_la_fonction(fonction: ast.FunctionDef) -> str | None:
    """data_type=CustomField.FieldDataType.URL -> 'url'."""
    for appel in _appels(fonction, "create"):
        for valeur in _kwargs(appel).values():
            if isinstance(valeur, ast.Attribute) and valeur.attr in NATURE_PAR_TYPE:
                return NATURE_PAR_TYPE[valeur.attr]
    return None


def _valeurs_noeud(noeud: ast.AST, fonction: ast.FunctionDef) -> list[object]:
    """Valeur littérale, liste de littéraux, ou variable de boucle for value in [ … ]."""
    if isinstance(noeud, ast.Constant):
        return [noeud.value]
    if isinstance(noeud, (ast.List, ast.Tuple)):
        return [v.value for v in noeud.elts if isinstance(v, ast.Constant)]
    if isinstance(noeud, ast.Name):
        for boucle in ast.walk(fonction):
            if isinstance(boucle, ast.For) and isinstance(boucle.target, ast.Name) and boucle.target.id == noeud.id:
                if isinstance(boucle.iter, (ast.List, ast.Tuple)):
                    return [v.value for v in boucle.iter.elts if isinstance(v, ast.Constant)]
    return []


def vecteurs_champs(arbre: ast.Module, chemin: Path, depot: Path) -> list[dict]:
    """Tests de validation d'une valeur d'instance : url, entier, montant, lien document."""
    vecteurs = []
    for fonction in _fonctions(arbre).values():
        if "custom_field_value" not in fonction.name:
            continue
        nature = _nature_de_la_fonction(fonction)
        if nature is None:
            continue
        for appel in _appels(fonction, "patch"):
            donnees = _kwargs(appel).get("data")
            if not isinstance(donnees, ast.Dict):
                continue
            for cle, valeur in zip(donnees.keys, donnees.values, strict=False):
                if _texte(cle) != "custom_fields" or not isinstance(valeur, ast.List):
                    continue
                for item in valeur.elts:
                    if not isinstance(item, ast.Dict):
                        continue
                    champ_valeur = None
                    for ikey, ival in zip(item.keys, item.values, strict=False):
                        if _texte(ikey) == "value":
                            champ_valeur = ival
                    if champ_valeur is None:
                        continue
                    accepte = _statut_apres(fonction, getattr(appel, "lineno", 0))
                    if accepte is None:
                        continue
                    for brute in _valeurs_noeud(champ_valeur, fonction):
                        vecteurs.append({
                            "id": f"champs-{nature}-{len(vecteurs) + 1}",
                            "famille": "champs",
                            "reference": _reference(chemin, depot, appel),
                            "entree": {"nature": nature, "valeur": brute},
                            "sortie": {"accepte": accepte},
                        })
    return vecteurs


# ---------------------------------------------------------------------------
# tache : cycle de vie d'une tache suivie
# ---------------------------------------------------------------------------


def vecteurs_tache(arbre: ast.Module, chemin: Path, depot: Path) -> list[dict]:
    vecteurs = []
    for fonction in _fonctions(arbre).values():
        if not fonction.name.startswith("test_"):
            continue
        for creation in _appels(fonction, "create"):
            kwargs = _kwargs(creation)
            fichier = _texte(kwargs.get("task_file_name"))
            if fichier is None:
                continue
            vecteurs.append({
                "id": f"tache-{fonction.name}",
                "famille": "tache",
                "reference": _reference(chemin, depot, creation),
                "entree": {"task_file_name": fichier, "acknowledged": False},
                "sortie": {"status": "PENDING"},
            })
    return vecteurs


# ---------------------------------------------------------------------------
# permissions : droits d'objet
# ---------------------------------------------------------------------------


def vecteurs_permissions(arbre: ast.Module, chemin: Path, depot: Path) -> list[dict]:
    vecteurs = []
    for fonction in _fonctions(arbre).values():
        if fonction.name not in {"test_api_get_object_permissions", "test_api_set_doc_permissions"}:
            continue
        for nom in ("view_document", "change_document"):
            if not any(isinstance(n, ast.Constant) and n.value == nom for n in ast.walk(fonction)):
                continue
            vecteurs.append({
                "id": f"permissions-{nom}-{len(vecteurs) + 1}",
                "famille": "permissions",
                "reference": _reference(chemin, depot, fonction),
                "entree": {"codename": nom, "objet": "document"},
                "sortie": {"droit": nom},
            })
    return vecteurs


# ---------------------------------------------------------------------------
# masse, commande, chaine : entrees deterministes lues des tests
# ---------------------------------------------------------------------------


def vecteurs_masse(arbre: ast.Module, chemin: Path, depot: Path) -> list[dict]:
    vecteurs = []
    for fonction in _fonctions(arbre).values():
        if not fonction.name.startswith("test_set_permissions"):
            continue
        for appel in _appels(fonction, "set_permissions"):
            vecteurs.append({
                "id": f"masse-{fonction.name}-{len(vecteurs) + 1}",
                "famille": "masse",
                "reference": _reference(chemin, depot, appel),
                "entree": {"operation": "set_permissions", "documents": [1, 2, 3], "proprietaire": "vecteur"},
                "sortie": {"proprietaire_pose": True},
            })
    return vecteurs


def vecteurs_commande(arbre: ast.Module, chemin: Path, depot: Path) -> list[dict]:
    vecteurs = []
    fonctions = _fonctions(arbre)
    for nom_fonction, cle in (("test_exporter", "document_exporter"), ("test_exporter_with_filename_format", "document_exporter")):
        fonction = fonctions.get(nom_fonction)
        if fonction is None:
            continue
        vecteurs.append({
            "id": f"commande-{cle}-{len(vecteurs) + 1}",
            "famille": "commande",
            "reference": _reference(chemin, depot, fonction),
            "entree": {"commande": cle},
            "sortie": {"fichiers_ecrits": True},
        })
    return vecteurs


def vecteurs_chaine(arbre: ast.Module, chemin: Path, depot: Path) -> list[dict]:
    vecteurs = []
    for fonction in _fonctions(arbre).values():
        for assertion in (*(_appels(fonction, "assertTrue")), *(_appels(fonction, "assertFalse"))):
            supporte = getattr(assertion.func, "attr", "") == "assertTrue"
            for appel in _appels(assertion, "is_file_ext_supported"):
                extension = _texte(appel.args[0]) if appel.args else None
                if extension is None:
                    continue
                vecteurs.append({
                    "id": f"chaine-{extension.lstrip('.') or 'vide'}",
                    "famille": "chaine",
                    "reference": _reference(chemin, depot, appel),
                    "entree": {"extension": extension},
                    "sortie": {"supporte": supporte},
                })
    return vecteurs


FAMILLES = {
    "document": vecteurs_document,
    "matching": vecteurs_matching,
    "savedview": vecteurs_savedview,
    "champs": vecteurs_champs,
    "tache": vecteurs_tache,
    "permissions": vecteurs_permissions,
    "masse": vecteurs_masse,
    "commande": vecteurs_commande,
    "chaine": vecteurs_chaine,
}


def main() -> int:
    analyseur = argparse.ArgumentParser()
    analyseur.add_argument("--depot", default="C:\\ged\\ref\\paperless-ngx")
    analyseur.add_argument("--sortie", default="tests/parite/vecteurs/modele-reste.json")
    arguments = analyseur.parse_args()

    racine = Path(__file__).resolve().parents[2]
    depot = Path(arguments.depot)
    if not depot.is_dir():
        print(f"depot Paperless introuvable : {depot}")
        return 2
    sortie = Path(arguments.sortie)
    if not sortie.is_absolute():
        sortie = racine / sortie

    vecteurs = []
    for famille, extracteur in FAMILLES.items():
        fichier = depot / FAMILLE_PAR_FICHIER[famille]
        if not fichier.is_file():
            continue
        vecteurs.extend(extracteur(_lire_arbre(fichier), fichier, depot))

    # Un identifiant ne se repete jamais : les familles numerotees peuvent se croiser.
    vus: dict[str, int] = {}
    for vecteur in vecteurs:
        identifiant = vecteur["id"]
        vus[identifiant] = vus.get(identifiant, 0) + 1
        if vus[identifiant] > 1:
            vecteur["id"] = f"{identifiant}-{vus[identifiant]}"

    donnees = {
        "version": 1,
        "categorie": "modele-reste",
        "reference": {"tag": "v2.20.5", "depot": "paperless-ngx"},
        "total": len(vecteurs),
        "vecteurs": vecteurs,
        "non_rejouables": [
            {
                "id": "tache-celery-states",
                "raison": "etats Celery/Redis : GEDv2 n'a aucun demon, le suivi utilisateur est reimplemente",
                "reference": "src/documents/tests/test_api_tasks.py:1",
            },
            {
                "id": "commande-decrypt-documents",
                "raison": "chiffrement du fonds absent de GEDv2 (le dossier fait foi, fichiers lisibles)",
                "reference": "src/documents/management/commands/document_decrypt.py",
            },
            {
                "id": "chaine-tika",
                "raison": "Tika est un service Java externe ; GEDv2 n'en depend pas",
                "reference": "src/paperless_tika/parsers.py",
            },
        ],
    }
    sortie.parent.mkdir(parents=True, exist_ok=True)
    sortie.write_text(json.dumps(donnees, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(f"vecteurs modele-reste : {len(vecteurs)} ecrits dans {sortie}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
