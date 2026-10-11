#!/usr/bin/env python3
"""Extracteur mecanique de l'inventaire Paperless-ngx (lot ged-oracle-paperless-1).

CHOIX D'OUTIL
-------------
La mission autorise « Python 3 avec le module ast pour les sources Python si disponible ;
sinon PHP ». Sur ce poste l'interpreteur Python n'est pas resolu par le PATH (le nom
`python` tombe sur le talon Microsoft Store) ; un Python 3.14 est toutefois present et
utilisable (voir `GED_PYTHON`). Le module standard `ast` donne un arbre syntaxique fiable
pour les sources Python de Paperless, ce qu'une lecture ligne a ligne ne peut pas garantir.
Le choix retenu est donc Python 3 + `ast`, avec :
  - un resolveur d'interpreteur cote PHP (src/Parite/InterpreteurPython.php) qui essaie
    GED_PYTHON, puis `python3`/`python`, puis les emplacements usuels Windows ;
  - un repli honnete : sans interpreteur, la couche 9 est ROUGE avec la cause nommee,
    jamais verte.

Le present script ne contient AUCUNE ligne du code de Paperless : il n'ecrit que des
identifiants, des chemins de fichiers et des numeros de ligne du depot de reference.
Un second passage sur le meme commit produit exactement le meme fichier (tri deterministe).

Sortie : docs/parite/inventaire-paperless.json
"""

from __future__ import annotations

import argparse
import ast
import json
import re
import subprocess
import sys
from pathlib import Path

# ---------------------------------------------------------------------------
# Utilitaires
# ---------------------------------------------------------------------------


def ligne(noeud: ast.AST) -> int:
    return int(getattr(noeud, "lineno", 1))


def nom_depuis_noeud(noeud: ast.AST | None) -> str:
    """Reconstitue un nom qualifie depuis un noeud (Name / Attribute)."""
    if noeud is None:
        return ""
    if isinstance(noeud, ast.Name):
        return noeud.id
    if isinstance(noeud, ast.Attribute):
        gauche = nom_depuis_noeud(noeud.value)
        return f"{gauche}.{noeud.attr}" if gauche else noeud.attr
    if isinstance(noeud, ast.Call):
        return nom_depuis_noeud(noeud.func)
    if isinstance(noeud, ast.Constant) and isinstance(noeud.value, str):
        return noeud.value
    return ""


def chaine_constante(noeud: ast.AST | None) -> str | None:
    if isinstance(noeud, ast.Constant) and isinstance(noeud.value, str):
        return noeud.value
    return None


def slug(texte: str) -> str:
    texte = texte.strip().strip("^$")
    texte = re.sub(r"[^0-9A-Za-z]+", "-", texte).strip("-").lower()
    return texte or "vide"


class Inventaire:
    """Collecte d'elements indexee par identifiant stable (premier gagne)."""

    def __init__(self, depot: Path) -> None:
        self.depot = depot
        self._elements: dict[str, dict[str, object]] = {}

    def ajouter(
        self,
        identifiant: str,
        categorie: str,
        type_element: str,
        libelle: str,
        fichier: str,
        noeud: ast.AST | int,
    ) -> None:
        if identifiant in self._elements:
            return
        numero = noeud if isinstance(noeud, int) else ligne(noeud)
        self._elements[identifiant] = {
            "id": identifiant,
            "categorie": categorie,
            "type": type_element,
            "libelle": libelle,
            "reference": f"{fichier}:{numero}",
        }

    def elements(self) -> list[dict[str, object]]:
        return sorted(self._elements.values(), key=lambda e: str(e["id"]))


def lire_arbre(chemin: Path) -> ast.Module:
    return ast.parse(chemin.read_text(encoding="utf-8"), filename=str(chemin))


def iter_classes(arbre: ast.Module):
    for noeud in ast.walk(arbre):
        if isinstance(noeud, ast.ClassDef):
            yield noeud


def bases_classe(noeud: ast.ClassDef) -> list[str]:
    return [nom_depuis_noeud(b) for b in noeud.bases]


# ---------------------------------------------------------------------------
# 1. API REST : routes, viewsets, actions, filtres
# ---------------------------------------------------------------------------

NOMS_FILTRES = {
    "ObjectFilter",
    "InboxFilter",
    "TitleContentFilter",
    "SharedByUser",
    "CustomFieldsFilter",
    "MimeTypeFilter",
    "CustomFieldQueryFilter",
    "BooleanFilter",
    "DateFilter",
    "Filter",
}


def _parcourir_urlpatterns(
    inventaire: Inventaire,
    fichier: str,
    liste: ast.List,
    prefixe: str,
) -> None:
    for element in liste.elts:
        if isinstance(element, ast.Starred):
            continue  # *api_router.urls : traite via les enregistrements du routeur
        if not isinstance(element, ast.Call):
            continue
        fonction = nom_depuis_noeud(element.func)
        if fonction in {"path", "re_path"}:
            motif = chaine_constante(element.args[0]) if element.args else None
            if motif is None:
                continue
            complet = prefixe + motif
            nom = None
            for mot in element.keywords:
                if mot.arg == "name":
                    nom = chaine_constante(mot.value)
            vue = nom_depuis_noeud(element.args[1]) if len(element.args) > 1 else ""
            if nom:
                identifiant = f"api:route:{nom}"
                libelle = f"{fonction} {complet} -> {nom} [{vue}]"
            else:
                identifiant = f"api:route:anon:{slug(complet)}-{slug(vue)}"
                libelle = f"{fonction} {complet} [{vue}]"
            inventaire.ajouter(identifiant, "api", "route", libelle, fichier, element)
            # include(...) d'une liste : on descend avec le prefixe accumule.
            if len(element.args) > 1:
                cible = element.args[1]
                if isinstance(cible, ast.Call) and nom_depuis_noeud(cible.func) == "include":
                    if cible.args and isinstance(cible.args[0], ast.List):
                        _parcourir_urlpatterns(
                            inventaire,
                            fichier,
                            cible.args[0],
                            complet,
                        )
        elif fonction == "include" and element.args:
            if isinstance(element.args[0], ast.List):
                _parcourir_urlpatterns(inventaire, fichier, element.args[0], prefixe)


def extraire_routes(inventaire: Inventaire, depot: Path) -> None:
    chemin = depot / "src" / "paperless" / "urls.py"
    fichier = "src/paperless/urls.py"
    arbre = lire_arbre(chemin)
    for noeud in arbre.body:
        if isinstance(noeud, ast.Assign):
            for cible in noeud.targets:
                nom_cible = nom_depuis_noeud(cible)
                if nom_cible in {"urlpatterns", "websocket_urlpatterns"} and isinstance(
                    noeud.value,
                    ast.List,
                ):
                    _parcourir_urlpatterns(inventaire, fichier, noeud.value, "")
    # Enregistrements du routeur DRF.
    for noeud in ast.walk(arbre):
        if (
            isinstance(noeud, ast.Call)
            and isinstance(noeud.func, ast.Attribute)
            and noeud.func.attr == "register"
            and noeud.args
        ):
            prefixe = chaine_constante(noeud.args[0]) or ""
            vue = nom_depuis_noeud(noeud.args[1]) if len(noeud.args) > 1 else ""
            inventaire.ajouter(
                f"api:route:{slug(prefixe)}",
                "api",
                "route",
                f"router /api/{prefixe}/ -> {vue}",
                fichier,
                noeud,
            )


def extraire_viewsets(inventaire: Inventaire, depot: Path) -> None:
    sources = {
        "src/documents/views.py": depot / "src" / "documents" / "views.py",
        "src/paperless_mail/views.py": depot / "src" / "paperless_mail" / "views.py",
        "src/paperless/views.py": depot / "src" / "paperless" / "views.py",
    }
    suffixes = ("ViewSet", "GenericAPIView", "View", "TemplateView")
    crud_par_base = {
        "ModelViewSet": ["list", "create", "retrieve", "update", "partial_update", "destroy"],
        "ReadOnlyModelViewSet": ["list", "retrieve"],
        "ViewSet": [],
        "ModelViewSet": ["list", "create", "retrieve", "update", "partial_update", "destroy"],
    }
    for fichier, chemin in sources.items():
        if not chemin.exists():
            continue
        arbre = lire_arbre(chemin)
        for classe in iter_classes(arbre):
            bases = bases_classe(classe)
            est_vue = any(b.endswith(suffixes) for b in bases) or classe.name.endswith(
                ("View", "ViewSet"),
            )
            if not est_vue:
                continue
            inventaire.ajouter(
                f"api:vue:{classe.name}",
                "api",
                "vue",
                f"classe {classe.name}({', '.join(bases)})",
                fichier,
                classe,
            )
            # Verbes deduits des bases DRF.
            for base in bases:
                court = base.split(".")[-1]
                for verbe in crud_par_base.get(court, []):
                    inventaire.ajouter(
                        f"api:verbe:{classe.name}.{verbe}",
                        "api",
                        "verbe",
                        f"{classe.name}.{verbe} (DRF {court})",
                        fichier,
                        classe,
                    )
            for membre in classe.body:
                if not isinstance(membre, ast.FunctionDef | ast.AsyncFunctionDef):
                    continue
                est_action = False
                methodes: list[str] = []
                detail = ""
                for decorateur in membre.decorator_list:
                    nom_dec = nom_depuis_noeud(decorateur.func if isinstance(decorateur, ast.Call) else decorateur)
                    if nom_dec.split(".")[-1] != "action":
                        continue
                    est_action = True
                    if isinstance(decorateur, ast.Call):
                        for mot in decorateur.keywords:
                            if mot.arg == "methods" and isinstance(mot.value, ast.List):
                                methodes = [
                                    str(chaine_constante(x)).upper()
                                    for x in mot.value.elts
                                    if chaine_constante(x)
                                ]
                            if mot.arg == "detail":
                                detail = "detail" if getattr(mot.value, "value", False) else "liste"
                if est_action:
                    inventaire.ajouter(
                        f"api:action:{classe.name}.{membre.name}",
                        "api",
                        "action",
                        f"@action {classe.name}.{membre.name} [{'/'.join(methodes) or 'GET'}] {detail}".strip(),
                        fichier,
                        membre,
                    )
                elif membre.name in {"get", "post", "put", "patch", "delete", "head", "options"}:
                    inventaire.ajouter(
                        f"api:verbe:{classe.name}.{membre.name}",
                        "api",
                        "verbe",
                        f"{classe.name}.{membre.name}",
                        fichier,
                        membre,
                    )


def extraire_filtres(inventaire: Inventaire, depot: Path) -> None:
    sources = {
        "src/documents/filters.py": depot / "src" / "documents" / "filters.py",
        "src/paperless_mail/filters.py": depot / "src" / "paperless_mail" / "filters.py",
    }
    for fichier, chemin in sources.items():
        if not chemin.exists():
            continue
        arbre = lire_arbre(chemin)
        for classe in iter_classes(arbre):
            if not classe.name.endswith("FilterSet"):
                continue
            for membre in classe.body:
                # Assignations explicites : tags__id__all = ObjectFilter(...)
                if isinstance(membre, ast.Assign) and isinstance(membre.value, ast.Call):
                    nom_filtre = nom_depuis_noeud(membre.value.func).split(".")[-1]
                    if nom_filtre.endswith("Filter"):
                        for cible in membre.targets:
                            parametre = nom_depuis_noeud(cible)
                            if parametre:
                                inventaire.ajouter(
                                    f"api:filtre:{classe.name}.{parametre}",
                                    "api",
                                    "filtre",
                                    f"{classe.name}.{parametre} ({nom_filtre})",
                                    fichier,
                                    membre,
                                )
                # Meta.fields = {...}
                if isinstance(membre, ast.ClassDef) and membre.name == "Meta":
                    for sous in membre.body:
                        if (
                            isinstance(sous, ast.Assign)
                            and isinstance(sous.value, ast.Dict)
                            and any(nom_depuis_noeud(c) == "fields" for c in sous.targets)
                        ):
                            for cle in sous.value.keys:
                                parametre = chaine_constante(cle)
                                if parametre:
                                    inventaire.ajouter(
                                        f"api:filtre:{classe.name}.{parametre}",
                                        "api",
                                        "filtre",
                                        f"{classe.name}.{parametre}",
                                        fichier,
                                        sous,
                                    )


# ---------------------------------------------------------------------------
# 2. Modele de donnees
# ---------------------------------------------------------------------------

CHAMPS_MODELES = re.compile(r"^models\.")


def _est_champ_modele(valeur: ast.AST) -> bool:
    return (
        isinstance(valeur, ast.Call)
        and isinstance(valeur.func, ast.Attribute)
        and isinstance(valeur.func.value, ast.Name)
        and valeur.func.value.id == "models"
    )


def extraire_modeles(inventaire: Inventaire, depot: Path) -> None:
    sources = {
        "src/documents/models.py": depot / "src" / "documents" / "models.py",
        "src/paperless_mail/models.py": depot / "src" / "paperless_mail" / "models.py",
    }
    for fichier, chemin in sources.items():
        if not chemin.exists():
            continue
        arbre = lire_arbre(chemin)
        # Seules les classes de premier niveau sont des entites du modele.
        for classe in [n for n in arbre.body if isinstance(n, ast.ClassDef)]:
            bases = bases_classe(classe)
            inventaire.ajouter(
                f"modele:classe:{classe.name}",
                "modele",
                "classe",
                f"classe {classe.name}({', '.join(bases)})",
                fichier,
                classe,
            )
            for membre in classe.body:
                # Classes imbriquees de choix (IntegerChoices / TextChoices) : types de workflow.
                if isinstance(membre, ast.ClassDef) and any(
                    b.endswith("Choices") for b in bases_classe(membre)
                ):
                    for sous in membre.body:
                        if isinstance(sous, ast.Assign):
                            for cible in sous.targets:
                                nom_choix = nom_depuis_noeud(cible)
                                if nom_choix:
                                    inventaire.ajouter(
                                        f"modele:type:{membre.name}.{nom_choix}",
                                        "modele",
                                        "type",
                                        f"{membre.name}.{nom_choix}",
                                        fichier,
                                        sous,
                                    )
                    continue
                if isinstance(membre, ast.Assign) and _est_champ_modele(membre.value):
                    for cible in membre.targets:
                        nom_champ = nom_depuis_noeud(cible)
                        if nom_champ:
                            inventaire.ajouter(
                                f"modele:champ:{classe.name}.{nom_champ}",
                                "modele",
                                "champ",
                                f"{classe.name}.{nom_champ}",
                                fichier,
                                membre,
                            )
                # Dictionnaire des types de champ personnalise.
                if (
                    isinstance(membre, ast.Assign)
                    and isinstance(membre.value, ast.Dict)
                    and any(
                        nom_depuis_noeud(c) == "TYPE_TO_DATA_STORE_NAME_MAP"
                        for c in membre.targets
                    )
                ):
                    for cle in membre.value.keys:
                        type_champ = chaine_constante(cle)
                        if type_champ is None:
                            # Cles du genre CustomField.FieldDataType.STRING : on garde l'identifiant.
                            dernier = nom_depuis_noeud(cle).split(".")[-1]
                            type_champ = dernier or None
                        if type_champ:
                            inventaire.ajouter(
                                f"modele:customfield-type:{type_champ}",
                                "modele",
                                "type",
                                f"CustomField type {type_champ}",
                                fichier,
                                membre,
                            )


# ---------------------------------------------------------------------------
# 3. Operations en masse
# ---------------------------------------------------------------------------


def extraire_masse(inventaire: Inventaire, depot: Path) -> None:
    chemin = depot / "src" / "documents" / "bulk_edit.py"
    fichier = "src/documents/bulk_edit.py"
    arbre = lire_arbre(chemin)
    for noeud in arbre.body:
        if isinstance(noeud, ast.FunctionDef | ast.AsyncFunctionDef):
            inventaire.ajouter(
                f"masse:fonction:{noeud.name}",
                "masse",
                "fonction",
                f"bulk_edit.{noeud.name}",
                fichier,
                noeud,
            )


# ---------------------------------------------------------------------------
# 4. Chaine de consommation
# ---------------------------------------------------------------------------

ETAPES_CONSUMER = {
    "run",
    "_store",
    "apply_overrides",
    "_write",
    "_parse_title_placeholders",
    "pre_check_file_exists",
    "pre_check_duplicate",
    "pre_check_directories",
    "pre_check_asn_value",
}


def extraire_chaine(inventaire: Inventaire, depot: Path) -> None:
    # consumer.py : classes et etapes.
    fichier = "src/documents/consumer.py"
    arbre = lire_arbre(depot / "src" / "documents" / "consumer.py")
    for classe in iter_classes(arbre):
        if classe.name.startswith("Consumer"):
            inventaire.ajouter(
                f"chaine:classe:{classe.name}",
                "chaine",
                "classe",
                f"consumer.{classe.name}",
                fichier,
                classe,
            )
            for membre in classe.body:
                if isinstance(membre, ast.FunctionDef | ast.AsyncFunctionDef) and membre.name in ETAPES_CONSUMER:
                    inventaire.ajouter(
                        f"chaine:etape:{classe.name}.{membre.name}",
                        "chaine",
                        "etape",
                        f"{classe.name}.{membre.name}",
                        fichier,
                        membre,
                    )
    # Parsers : documents/parsers.py + modules tesseract/text/tika + mail.
    parsers = {
        "src/documents/parsers.py": depot / "src" / "documents" / "parsers.py",
        "src/paperless_tesseract/parsers.py": depot / "src" / "paperless_tesseract" / "parsers.py",
        "src/paperless_text/parsers.py": depot / "src" / "paperless_text" / "parsers.py",
        "src/paperless_tika/parsers.py": depot / "src" / "paperless_tika" / "parsers.py",
        "src/paperless_mail/parsers.py": depot / "src" / "paperless_mail" / "parsers.py",
    }
    for ref, chemin in parsers.items():
        if not chemin.exists():
            continue
        arbre = lire_arbre(chemin)
        for noeud in arbre.body:
            if isinstance(noeud, ast.FunctionDef | ast.AsyncFunctionDef):
                inventaire.ajouter(
                    f"chaine:parseur:{ref.split('/')[1]}.{noeud.name}",
                    "chaine",
                    "parseur",
                    f"{noeud.name}",
                    ref,
                    noeud,
                )
        for classe in iter_classes(arbre):
            for membre in classe.body:
                if isinstance(membre, ast.FunctionDef | ast.AsyncFunctionDef) and membre.name in {
                    "parse",
                    "extract_text",
                    "extract_metadata",
                    "get_thumbnail",
                    "get_page_count",
                    "get_date",
                    "get_text",
                }:
                    inventaire.ajouter(
                        f"chaine:parseur:{classe.name}.{membre.name}",
                        "chaine",
                        "parseur",
                        f"{classe.name}.{membre.name}",
                        ref,
                        membre,
                    )
    # Codes-barres et ASN.
    ref = "src/documents/barcodes.py"
    arbre = lire_arbre(depot / "src" / "documents" / "barcodes.py")
    for classe in iter_classes(arbre):
        inventaire.ajouter(
            f"chaine:codebarres:{classe.name}",
            "chaine",
            "codebarres",
            f"barcodes.{classe.name}",
            ref,
            classe,
        )
        for membre in classe.body:
            if isinstance(membre, ast.FunctionDef | ast.AsyncFunctionDef) and membre.name in {
                "is_separator",
                "is_asn",
                "run",
            }:
                inventaire.ajouter(
                    f"chaine:codebarres:{classe.name}.{membre.name}",
                    "chaine",
                    "codebarres",
                    f"{classe.name}.{membre.name}",
                    ref,
                    membre,
                )
    # Gabarit de nom de fichier.
    ref = "src/documents/templating/filepath.py"
    arbre = lire_arbre(depot / "src" / "documents" / "templating" / "filepath.py")
    for noeud in arbre.body:
        if isinstance(noeud, ast.FunctionDef | ast.AsyncFunctionDef):
            inventaire.ajouter(
                f"chaine:nom-fichier:{noeud.name}",
                "chaine",
                "nom-fichier",
                f"filepath.{noeud.name}",
                ref,
                noeud,
            )
    # Detection de doublons (cle explicite, en plus de l'etape consumer).
    inventaire.ajouter(
        "chaine:doublon:pre_check_duplicate",
        "chaine",
        "doublon",
        "ConsumerPreflightPlugin.pre_check_duplicate",
        "src/documents/consumer.py",
        _trouver_ligne_fonction(depot / "src" / "documents" / "consumer.py", "pre_check_duplicate"),
    )


def _trouver_ligne_fonction(chemin: Path, nom: str) -> int:
    arbre = lire_arbre(chemin)
    for noeud in ast.walk(arbre):
        if isinstance(noeud, ast.FunctionDef | ast.AsyncFunctionDef) and noeud.name == nom:
            return ligne(noeud)
    return 1


# ---------------------------------------------------------------------------
# 5. Correspondance automatique
# ---------------------------------------------------------------------------

FONCTIONS_MATCHING = {
    "matches",
    "match_correspondents",
    "match_document_types",
    "match_tags",
    "match_storage_paths",
    "_split_match",
}


def extraire_correspondance(inventaire: Inventaire, depot: Path) -> None:
    # Algorithmes de correspondance declares sur MatchingModel.
    fichier = "src/documents/models.py"
    arbre = lire_arbre(depot / "src" / "documents" / "models.py")
    for classe in iter_classes(arbre):
        if classe.name == "MatchingModel":
            for membre in classe.body:
                if isinstance(membre, ast.Assign):
                    for cible in membre.targets:
                        nom_const = nom_depuis_noeud(cible)
                        if nom_const.startswith("MATCH_"):
                            inventaire.ajouter(
                                f"correspondance:algo:{nom_const}",
                                "correspondance",
                                "algo",
                                nom_const,
                                fichier,
                                membre,
                            )
    # Fonctions de matching.
    fichier = "src/documents/matching.py"
    arbre = lire_arbre(depot / "src" / "documents" / "matching.py")
    for noeud in arbre.body:
        if isinstance(noeud, ast.FunctionDef | ast.AsyncFunctionDef) and noeud.name in FONCTIONS_MATCHING:
            inventaire.ajouter(
                f"correspondance:fonction:{noeud.name}",
                "correspondance",
                "fonction",
                f"matching.{noeud.name}",
                fichier,
                noeud,
            )


# ---------------------------------------------------------------------------
# 6. Ecrans
# ---------------------------------------------------------------------------


def extraire_ecrans(inventaire: Inventaire, depot: Path) -> None:
    fichier = "src-ui/src/app/app-routing.module.ts"
    contenu = (depot / fichier).read_text(encoding="utf-8")
    motifs = list(re.finditer(r"path:\s*'([^']*)'", contenu))
    for index, motif in enumerate(motifs):
        chemin = motif.group(1)
        suite = contenu[motif.end() : motif.end() + 600]
        composant = re.search(r"component:\s*(\w+)", suite)
        nom = composant.group(1) if composant else "redirect"
        numero = contenu[: motif.start()].count("\n") + 1
        inventaire.ajouter(
            f"ecran:route:{slug(chemin) or 'racine'}",
            "ecran",
            "route",
            f"route UI '{chemin}' -> {nom}",
            fichier,
            numero,
        )
    # Sections de reglages declarees dans l'enum SettingsNavIDs.
    fichier = "src-ui/src/app/components/admin/settings/settings.component.ts"
    contenu = (depot / fichier).read_text(encoding="utf-8")
    bloc = re.search(r"enum SettingsNavIDs\s*\{(.*?)\}", contenu, re.S)
    if bloc:
        depart = contenu[: bloc.start(1)].count("\n") + 1
        for membre in re.finditer(r"^\s*(\w+)\s*=\s*\d+", bloc.group(1), re.M):
            nom = membre.group(1)
            inventaire.ajouter(
                f"ecran:reglage:{nom.lower()}",
                "ecran",
                "reglage",
                f"reglages/{nom}",
                fichier,
                depart + bloc.group(1)[: membre.start(1)].count("\n"),
            )


# ---------------------------------------------------------------------------
# 7. Commandes de gestion
# ---------------------------------------------------------------------------


def extraire_commandes(inventaire: Inventaire, depot: Path) -> None:
    apps = {
        "documents": depot / "src" / "documents" / "management" / "commands",
        "paperless_mail": depot / "src" / "paperless_mail" / "management" / "commands",
    }
    for app, dossier in apps.items():
        if not dossier.exists():
            continue
        for chemin in sorted(dossier.glob("*.py")):
            if chemin.name.startswith("_"):
                continue
            arbre = lire_arbre(chemin)
            if not any(isinstance(n, ast.ClassDef) and n.name == "Command" for n in ast.walk(arbre)):
                continue
            nom = chemin.stem
            inventaire.ajouter(
                f"commande:{app}:{nom}",
                "commande",
                "commande",
                f"{app} manage.py {nom}",
                f"src/{app}/management/commands/{chemin.name}",
                1,
            )


# ---------------------------------------------------------------------------
# 8. Permissions
# ---------------------------------------------------------------------------

FONCTIONS_PERMISSIONS = {
    "get_groups_with_only_permission",
    "set_permissions_for_object",
    "get_document_count_filter_for_user",
    "get_objects_for_user_owner_aware",
    "has_perms_owner_aware",
}


def extraire_permissions(inventaire: Inventaire, depot: Path) -> None:
    fichier = "src/documents/permissions.py"
    arbre = lire_arbre(depot / "src" / "documents" / "permissions.py")
    for classe in iter_classes(arbre):
        inventaire.ajouter(
            f"permissions:classe:{classe.name}",
            "permissions",
            "classe",
            f"{classe.name}",
            fichier,
            classe,
        )
    for noeud in arbre.body:
        if isinstance(noeud, ast.FunctionDef | ast.AsyncFunctionDef) and noeud.name in FONCTIONS_PERMISSIONS:
            inventaire.ajouter(
                f"permissions:fonction:{noeud.name}",
                "permissions",
                "fonction",
                f"{noeud.name}",
                fichier,
                noeud,
            )
    # Proprietaire : champ owner des sous-classes de ModelWithOwner.
    fichier = "src/documents/models.py"
    arbre = lire_arbre(depot / "src" / "documents" / "models.py")
    for classe in iter_classes(arbre):
        for membre in classe.body:
            if isinstance(membre, ast.Assign) and any(
                nom_depuis_noeud(c) == "owner" for c in membre.targets
            ):
                inventaire.ajouter(
                    f"permissions:proprietaire:{classe.name}",
                    "permissions",
                    "proprietaire",
                    f"owner sur {classe.name}",
                    fichier,
                    membre,
                )


# ---------------------------------------------------------------------------
# Comptage independant (garde-fou d'exhaustivite)
# ---------------------------------------------------------------------------


def compter_independamment(depot: Path, elements: list[dict[str, object]]) -> dict[str, int]:
    """Compte brut, par une methode distincte de l'extraction (regex ligne a ligne)."""
    def texte(rel: str) -> str:
        chemin = depot / rel
        return chemin.read_text(encoding="utf-8") if chemin.exists() else ""

    classes_modeles = len(re.findall(r"^class \w+", texte("src/documents/models.py"), re.M))
    classes_mail = len(re.findall(r"^class \w+", texte("src/paperless_mail/models.py"), re.M))
    routes_nommees = len(re.findall(r"\bname\s*=\s*\"\w+\"", texte("src/paperless/urls.py")))
    actions = sum(
        len(re.findall(r"@action\b", texte(f)))
        for f in (
            "src/documents/views.py",
            "src/paperless_mail/views.py",
            "src/paperless/views.py",
        )
    )
    fonctions_masse = len(re.findall(r"^def \w+", texte("src/documents/bulk_edit.py"), re.M))
    chemin_ui = depot / "src-ui" / "src" / "app" / "app-routing.module.ts"
    routes_ui = len(re.findall(r"path:\s*'", chemin_ui.read_text(encoding="utf-8"))) if chemin_ui.exists() else 0
    commandes = 0
    for app in ("documents", "paperless_mail"):
        dossier = depot / "src" / app / "management" / "commands"
        if dossier.exists():
            for f in dossier.glob("*.py"):
                if "class Command" in f.read_text(encoding="utf-8"):
                    commandes += 1

    par_categorie: dict[str, int] = {}
    for element in elements:
        categorie = str(element["categorie"])
        par_categorie[categorie] = par_categorie.get(categorie, 0) + 1

    return {
        "modeles_classes": classes_modeles + classes_mail,
        "api_routes_nommees": routes_nommees,
        "api_actions": actions,
        "masse_fonctions": fonctions_masse,
        "ecran_routes_ui": routes_ui,
        "commandes": commandes,
        "par_categorie": par_categorie,
    }


# ---------------------------------------------------------------------------
# Commit de reference
# ---------------------------------------------------------------------------


def infos_commit(depot: Path) -> dict[str, str]:
    def git(*args: str) -> str:
        try:
            resultat = subprocess.run(
                ["git", "-C", str(depot), *args],
                capture_output=True,
                text=True,
                check=True,
            )
            return resultat.stdout.strip()
        except (OSError, subprocess.CalledProcessError):
            return ""

    return {
        "commit": git("rev-parse", "HEAD"),
        "commit_court": git("rev-parse", "--short", "HEAD"),
        "tag": git("describe", "--tags"),
    }


def construire(depot: Path) -> dict[str, object]:
    inventaire = Inventaire(depot)
    extraire_routes(inventaire, depot)
    extraire_viewsets(inventaire, depot)
    extraire_filtres(inventaire, depot)
    extraire_modeles(inventaire, depot)
    extraire_masse(inventaire, depot)
    extraire_chaine(inventaire, depot)
    extraire_correspondance(inventaire, depot)
    extraire_ecrans(inventaire, depot)
    extraire_commandes(inventaire, depot)
    extraire_permissions(inventaire, depot)
    elements = inventaire.elements()
    comptage = compter_independamment(depot, elements)
    par_categorie: dict[str, int] = {}
    for element in elements:
        categorie = str(element["categorie"])
        par_categorie[categorie] = par_categorie.get(categorie, 0) + 1
    return {
        "version": 1,
        "reference": infos_commit(depot),
        "total": len(elements),
        "comptage": {"par_categorie": par_categorie, "total": len(elements)},
        "comptage_independant": comptage,
        "elements": elements,
    }


def verifier_completude(inventaire: dict[str, object]) -> list[str]:
    """Echoue si un comptage independant ne colle pas a l'extraction structuree."""
    erreurs: list[str] = []
    comptage = inventaire["comptage_independant"]  # type: ignore[assignment]
    assert isinstance(comptage, dict)
    elements = inventaire["elements"]  # type: ignore[assignment]
    assert isinstance(elements, list)
    par_categorie: dict[str, int] = {}
    par_type: dict[str, int] = {}
    for element in elements:
        assert isinstance(element, dict)
        par_categorie[str(element["categorie"])] = par_categorie.get(str(element["categorie"]), 0) + 1
        par_type[str(element["type"])] = par_type.get(str(element["type"]), 0) + 1

    # Classes de premier niveau des deux fichiers de modele (les classes imbriquees de
    # choix sont comptees a part, en categorie "type").
    classe_totale = len(
        [
            e
            for e in elements
            if e.get("type") == "classe"
            and str(e.get("reference", "")).split(":")[0].endswith("models.py")
        ],
    )
    if classe_totale != comptage["modeles_classes"]:
        erreurs.append(
            f"classes de modele : extraites={classe_totale} comptage_independant={comptage['modeles_classes']}",
        )
    actions = par_type.get("action", 0)
    if actions != comptage["api_actions"]:
        erreurs.append(
            f"actions : extraites={actions} comptage_independant={comptage['api_actions']}",
        )
    fonctions_masse = par_type.get("fonction", 0) - par_type.get("__inexistant__", 0)
    masse = len([e for e in elements if str(e["categorie"]) == "masse"])
    if masse != comptage["masse_fonctions"]:
        erreurs.append(
            f"fonctions de masse : extraites={masse} comptage_independant={comptage['masse_fonctions']}",
        )
    ecrans = par_categorie.get("ecran", 0)
    if ecrans < comptage["ecran_routes_ui"]:
        erreurs.append(
            f"ecrans : extraits={ecrans} < routes UI comptees={comptage['ecran_routes_ui']}",
        )
    commandes = par_categorie.get("commande", 0)
    if commandes != comptage["commandes"]:
        erreurs.append(
            f"commandes : extraites={commandes} comptage_independant={comptage['commandes']}",
        )
    if comptage.get("par_categorie") != inventaire["comptage"]["par_categorie"]:  # type: ignore[index]
        erreurs.append("repartition par categorie instable entre les deux comptages")
    return erreurs


def main() -> int:
    parser = argparse.ArgumentParser(description="Inventaire mecanique de Paperless-ngx")
    parser.add_argument("--depot", default=None, help="racine du depot Paperless (lecture seule)")
    parser.add_argument("--sortie", default="docs/parite/inventaire-paperless.json")
    parser.add_argument("--verifier", action="store_true", help="ne compare que le comptage independant")
    args = parser.parse_args()

    import os

    depot = Path(args.depot or os.environ.get("PAPERLESS_DEPOT", r"C:\ged\ref\paperless-ngx"))
    if not (depot / "src" / "paperless" / "urls.py").exists():
        print(f"ERREUR: depot Paperless introuvable ou inattendu : {depot}", file=sys.stderr)
        return 2

    inventaire = construire(depot)
    erreurs = verifier_completude(inventaire)
    if erreurs:
        for erreur in erreurs:
            print(f"ERREUR comptage: {erreur}", file=sys.stderr)
        return 1

    if args.verifier:
        print(f"OK: comptage independant coherent ({inventaire['total']} elements)")
        return 0

    sortie = Path(args.sortie)
    sortie.parent.mkdir(parents=True, exist_ok=True)
    sortie.write_text(
        json.dumps(inventaire, ensure_ascii=False, indent=2, sort_keys=False) + "\n",
        encoding="utf-8",
    )
    print(
        f"OK: {inventaire['total']} elements ecrits dans {sortie} "
        f"(commit {inventaire['reference']['commit_court']})",
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
