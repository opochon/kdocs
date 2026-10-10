#!/usr/bin/env python3
"""Édition atomique de pages PDF pour GEDv2 avec PyMuPDF.

Ce script est exécuté par CMD4_PYTHON : il n'ajoute donc aucune dépendance au
venv du produit. Chaque sortie est d'abord écrite dans le même dossier, puis
remplacée atomiquement ; le fichier source n'est jamais ouvert en écriture.
"""

import json
import os
import sys
import tempfile

import pymupdf as fitz  # PyMuPDF >= 1.28 : import fitz ecrit un avertissement sur stdout et casse la reponse JSON


def open_pdf(path):
    return fitz.open(path)


def write(path, document):
    directory = os.path.dirname(path) or "."
    fd, temporary = tempfile.mkstemp(prefix=".gedv2-pages-", suffix=".pdf", dir=directory)
    try:
        os.close(fd)
        document.save(temporary, garbage=4, deflate=True)
        os.replace(temporary, path)
    except Exception:
        try:
            os.unlink(temporary)
        except FileNotFoundError:
            pass
        raise


def copy_pages(source, first=0, last=None):
    result = fitz.open()
    result.insert_pdf(source, from_page=first, to_page=source.page_count - 1 if last is None else last)
    return result


def require_page(source, number):
    if number < 1 or number > source.page_count:
        raise ValueError("numéro de page hors de la pièce")


def main(command):
    action = command["action"]
    if action == "diagnostic":
        print(json.dumps({"moteur": "PyMuPDF", "version": fitz.VersionBind}))
        return
    if action == "count":
        with open_pdf(command["source"]) as source:
            print(json.dumps({"pages": source.page_count}))
        return
    if action == "rotation":
        with open_pdf(command["source"]) as source:
            number = int(command["page"])
            require_page(source, number)
            print(json.dumps({"rotation": source[number - 1].rotation % 360}))
        return
    if action == "turn":
        with open_pdf(command["source"]) as source:
            number, degrees = int(command["page"]), int(command["degrees"])
            require_page(source, number)
            if degrees not in (90, 180, 270):
                raise ValueError("rotation attendue : 90, 180 ou 270 degrés")
            source[number - 1].set_rotation((source[number - 1].rotation + degrees) % 360)
            write(command["output"], source)
        return
    if action == "split":
        with open_pdf(command["source"]) as source:
            after = int(command["after"])
            if after < 1 or after >= source.page_count:
                raise ValueError("scission impossible : choisissez une page intérieure à la pièce")
            with copy_pages(source, 0, after - 1) as left, copy_pages(source, after) as right:
                write(command["left"], left)
                write(command["right"], right)
        return
    if action == "merge":
        merged = fitz.open()
        try:
            for path in command["sources"]:
                with open_pdf(path) as source:
                    merged.insert_pdf(source)
            if merged.page_count == 0:
                raise ValueError("fusion impossible : aucune page")
            write(command["output"], merged)
        finally:
            merged.close()
        return
    if action == "remove":
        with open_pdf(command["source"]) as source:
            number = int(command["page"])
            require_page(source, number)
            if source.page_count < 2:
                raise ValueError("retrait impossible : une pièce doit garder au moins une page")
            output = fitz.open()
            removed = fitz.open()
            try:
                if number > 1:
                    output.insert_pdf(source, from_page=0, to_page=number - 2)
                if number < source.page_count:
                    output.insert_pdf(source, from_page=number, to_page=source.page_count - 1)
                removed.insert_pdf(source, from_page=number - 1, to_page=number - 1)
                write(command["output"], output)
                write(command["removed"], removed)
            finally:
                output.close()
                removed.close()
        return
    raise ValueError("édition inconnue")


if __name__ == "__main__":
    try:
        main(json.load(sys.stdin))
    except Exception as error:
        print("édition PDF : " + str(error), file=sys.stderr)
        sys.exit(2)
