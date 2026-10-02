#!/usr/bin/env python3
"""Édition atomique de pages PDF pour GEDv2.

Le moteur d'ingestion est déjà un service Python. Cette petite frontière garde la manipulation
PDF hors de PHP et ne modifie jamais un original : chaque commande écrit d'abord un fichier temporaire.
"""

import json
import os
import sys
import tempfile

from pypdf import PdfReader, PdfWriter


def pages(path):
    return list(PdfReader(path).pages)


def write(path, selected):
    directory = os.path.dirname(path) or "."
    fd, temporary = tempfile.mkstemp(prefix=".gedv2-pages-", suffix=".pdf", dir=directory)
    try:
        writer = PdfWriter()
        for page in selected:
            writer.add_page(page)
        with os.fdopen(fd, "wb") as output:
            writer.write(output)
        os.replace(temporary, path)
    except Exception:
        try:
            os.unlink(temporary)
        except FileNotFoundError:
            pass
        raise


def require_page(all_pages, number):
    if number < 1 or number > len(all_pages):
        raise ValueError("numéro de page hors de la pièce")


def main(command):
    action = command["action"]
    if action == "count":
        print(json.dumps({"pages": len(pages(command["source"]))}))
        return
    if action == "rotation":
        source = pages(command["source"])
        number = int(command["page"])
        require_page(source, number)
        print(json.dumps({"rotation": int(source[number - 1].get("/Rotate", 0)) % 360}))
        return
    if action == "turn":
        source = pages(command["source"])
        number, degrees = int(command["page"]), int(command["degrees"])
        require_page(source, number)
        if degrees not in (90, 180, 270):
            raise ValueError("rotation attendue : 90, 180 ou 270 degrés")
        source[number - 1].rotate(degrees)
        write(command["output"], source)
        return
    if action == "split":
        source = pages(command["source"])
        after = int(command["after"])
        if after < 1 or after >= len(source):
            raise ValueError("scission impossible : choisissez une page intérieure à la pièce")
        write(command["left"], source[:after])
        write(command["right"], source[after:])
        return
    if action == "merge":
        merged = []
        for source in command["sources"]:
            merged.extend(pages(source))
        if not merged:
            raise ValueError("fusion impossible : aucune page")
        write(command["output"], merged)
        return
    if action == "remove":
        source = pages(command["source"])
        number = int(command["page"])
        require_page(source, number)
        if len(source) < 2:
            raise ValueError("retrait impossible : une pièce doit garder au moins une page")
        removed = source[number - 1]
        write(command["output"], source[:number - 1] + source[number:])
        write(command["removed"], [removed])
        return
    raise ValueError("édition inconnue")


if __name__ == "__main__":
    try:
        main(json.load(sys.stdin))
    except Exception as error:
        print("édition PDF : " + str(error), file=sys.stderr)
        sys.exit(2)
