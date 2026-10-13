#!/usr/bin/env python3
"""Extracteur mecanique de l'inventaire des fonctions M-Files (lot ged-mfiles-oracle-1).

CHOIX DE SOURCE
---------------
La mission interdit toute copie de code ou de decompilation M-Files : l'oracle se batit
UNIQUEMENT depuis la documentation PUBLIQUE. M-Files publie sa surface fonctionnelle comme
un service Web REST documente sous `developer.m-files.com/APIs/REST-API/Reference/`.
Chaque ressource documentee y porte son titre, son chemin (avec ses parametres) et les
verbes HTTP qu'elle accepte. C'est cette documentation publique, et elle seule, que
l'extracteur lit : il n'ecrit que des titres, des chemins et des verbes, jamais une ligne
de code M-Files.

DEUX MODES
----------
- `--capturer` : va lire la documentation publique en ligne (une page d'index + une page
  par ressource) et ecrit une CAPTURE versionnee (`docs/parite/mfiles-rest-api.capture.json`).
  C'est un geste humain, ponctuel : la capture fige ce qui a ete publie a une date.
- mode par defaut : lit la CAPTURE versionnee et emet l'INVENTAIRE
  (`docs/parite/inventaire-mfiles.json`). Aucune requete reseau : le harness rejoue donc la
  couche de parite M-Files hors ligne, et un second passage produit exactement le meme
  fichier (tri deterministe).

Ce script ne contient AUCUNE ligne du code de M-Files ni de sa documentation au dela des
identifiants publics (titre, chemin, verbe, URL) : la capture est un index, pas une copie.

Sorties :
  docs/parite/mfiles-rest-api.capture.json   (mode --capturer)
  docs/parite/inventaire-mfiles.json         (mode par defaut)
"""

from __future__ import annotations

import argparse
import html
import json
import re
import sys
import urllib.request
from pathlib import Path

HOTE = "https://developer.m-files.com"
INDEX = "/APIs/REST-API/Reference/resources/"
LICENCE = "documentation publique M-Files (developer.m-files.com), consultee sans copie de code"

# Une page de ressource HTML : titre, chemin, verbes.
RE_H1 = re.compile(r"<h1[^>]*>(.*?)</h1>", re.S)
RE_CHEMIN = re.compile(r'<h2 class="url-with-parameters"[^>]*>(.*?)</h2>', re.S)
RE_VERBE = re.compile(r'<h3 class="method"[^>]*>\s*(GET|POST|PUT|DELETE|PATCH)\s*</h3>', re.I)
RE_LIEN = re.compile(r'href="(/APIs/REST-API/Reference/resources/[^"#?]*)"')


def sans_balises(fragment: str) -> str:
    """Texte d'un fragment HTML : balises retirees, entites resolues, espaces normalises."""
    texte = re.sub(r"<[^>]+>", "", fragment)
    return re.sub(r"\s+", " ", html.unescape(texte)).strip()


def lire(url: str, essais: int = 3) -> str:
    """Lit une page publique avec un User-Agent honnete et un repli borne."""
    derniere: Exception | None = None
    for _ in range(essais):
        try:
            requete = urllib.request.Request(url, headers={"User-Agent": "GEDv2-parite/1 (lecture de doc publique)"})
            with urllib.request.urlopen(requete, timeout=30) as reponse:
                return reponse.read().decode("utf-8", errors="replace")
        except Exception as erreur:  # reseau : on retente, puis on nomme l'echec
            derniere = erreur
    raise RuntimeError(f"lecture impossible : {url} ({derniere})")


def parser_page(page: str) -> dict[str, object]:
    """Extrait {titre, chemin, methodes} d'une page de ressource publique."""
    titre = sans_balises(RE_H1.search(page).group(1)) if RE_H1.search(page) else ""
    chemin = sans_balises(RE_CHEMIN.search(page).group(1)) if RE_CHEMIN.search(page) else ""
    methodes = sorted({m.upper() for m in RE_VERBE.findall(page)})
    return {"titre": titre, "chemin": chemin, "methodes": methodes}


def capturer() -> dict[str, object]:
    """Lit la documentation publique en ligne et rend la capture a versionner."""
    index = lire(HOTE + INDEX)
    pages = sorted({lien for lien in RE_LIEN.findall(index) if lien != INDEX})
    ressources: list[dict[str, object]] = []
    for chemin_page in pages:
        page = lire(HOTE + chemin_page)
        infos = parser_page(page)
        if not infos["titre"] or not infos["chemin"]:
            raise RuntimeError(f"page publique incomplete : {chemin_page}")
        # Une page sans verbe est une page de conteneur (elle ne documente que des sous-ressources) :
        # elle est conservee dans la capture, sans operation a inventorier.
        ressources.append({
            "page": chemin_page,
            "titre": infos["titre"],
            "chemin": infos["chemin"],
            "methodes": infos["methodes"],
        })
    ressources.sort(key=lambda r: str(r["page"]))
    return {
        "source": HOTE + INDEX,
        "licence": LICENCE,
        "total": len(ressources),
        "ressources": ressources,
    }


def identifiant(methode: str, chemin: str) -> str:
    """Identifiant stable `mfiles:rest:<verbe>:<chemin-slug>`."""
    slug = re.sub(r"[^0-9A-Za-z]+", "-", chemin).strip("-").lower()
    return f"mfiles:rest:{methode.lower()}:{slug}"


def inventaire(capture: dict[str, object]) -> dict[str, object]:
    """Transforme la capture en inventaire rejouable, trie par identifiant."""
    elements: list[dict[str, object]] = []
    for ressource in capture["ressources"]:
        chemin = str(ressource["chemin"])
        titre = str(ressource["titre"])
        page = str(ressource["page"])
        for methode in ressource["methodes"]:
            verbe = str(methode)
            elements.append({
                "id": identifiant(verbe, chemin),
                "categorie": "mfiles",
                "type": "route-rest",
                "libelle": f"{verbe} {chemin} — {titre}",
                "reference": f"{HOTE}{page}#{verbe.lower()}",
            })
    elements.sort(key=lambda e: str(e["id"]))
    ids = {str(e["id"]) for e in elements}
    if len(ids) != len(elements):
        duplicats = sorted(i for i in ids if sum(1 for e in elements if e["id"] == i) > 1)
        raise RuntimeError("identifiants M-Files dupliques : " + ", ".join(duplicats))
    return {
        "version": 1,
        "reference": {
            "produit": "M-Files (service Web REST)",
            "source": capture.get("source", ""),
            "licence": capture.get("licence", LICENCE),
        },
        "total": len(elements),
        "comptage": {
            "par_categorie": {"mfiles": len(elements)},
            "par_verbe": compter(elements, "libelle"),
            "ressources_documentees": len(capture["ressources"]),
            "ressources_sans_operation": sum(1 for r in capture["ressources"] if not r["methodes"]),
        },
        "elements": elements,
    }


def compter(elements: list[dict[str, object]], cle: str) -> dict[str, int]:
    """Compte par categorie ou par verbe (le verbe est le premier mot du libelle)."""
    comptes: dict[str, int] = {}
    for element in elements:
        valeur = str(element["categorie"]) if cle == "categorie" else str(element["libelle"]).split(" ", 1)[0]
        comptes[valeur] = comptes.get(valeur, 0) + 1
    return dict(sorted(comptes.items()))


def ecrire(chemin: Path, donnees: dict[str, object]) -> None:
    chemin.write_text(
        json.dumps(donnees, ensure_ascii=False, indent=2, sort_keys=False) + "\n",
        encoding="utf-8",
    )


def main() -> int:
    racine = Path(__file__).resolve().parents[2]
    analyseur = argparse.ArgumentParser(description="Inventaire des fonctions M-Files depuis la documentation publique.")
    analyseur.add_argument("--capturer", action="store_true", help="relire la documentation publique en ligne et ecrire la capture")
    analyseur.add_argument("--capture", default=str(racine / "docs/parite/mfiles-rest-api.capture.json"))
    analyseur.add_argument("--sortie", default=str(racine / "docs/parite/inventaire-mfiles.json"))
    arguments = analyseur.parse_args()

    capture_chemin = Path(arguments.capture)
    sortie = Path(arguments.sortie)

    if arguments.capturer:
        capture = capturer()
        ecrire(capture_chemin, capture)
        print(f"capture M-Files : {capture['total']} ressources publiques -> {capture_chemin}")
    else:
        if not capture_chemin.is_file():
            print(f"capture M-Files absente : {capture_chemin}", file=sys.stderr)
            return 2
        capture = json.loads(capture_chemin.read_text(encoding="utf-8"))

    donnees = inventaire(capture)
    ecrire(sortie, donnees)
    print(f"inventaire M-Files : {donnees['total']} elements -> {sortie}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
