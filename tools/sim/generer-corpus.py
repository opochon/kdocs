#!/usr/bin/env python3
"""
Espace de simulation GEDv2 — générateur de corpus SYNTHÉTIQUE et reproductible.

But : mesurer recherche, classement et parité sur du VOLUME proche du réel sans jamais
introduire de donnée réelle. Le corpus est un ARBRE DE DOSSIERS sur disque (le dossier fait foi),
régénérable depuis cette graine ; seuls ce générateur et la vérité qu'il produit sont commités.

Ce que le générateur écrit :
  var/sim/corpus/**.pdf, **.eml   — le corpus (hors dépôt, régénérable, gitignore)
  var/sim/lectures.json           — le texte « lu » de chaque pièce (ce qu'un OCR aurait rendu)
  var/sim/verite-corpus.json      — la VÉRITÉ figée (métadonnées + requêtes de recherche)

La vérité versionnée du dépôt est une COPIE de var/sim/verite-corpus.json, jamais recalculée par
l'application : elle vient du générateur, à graine fixe. Le corpus reste donc binaire-zéro dans git.

Usage (Python du venv CMD4, PyMuPDF requis) :
  <CMD4_PYTHON> tools/sim/generer-corpus.py
"""

from __future__ import annotations

import json
import random
import shutil
from datetime import date, datetime, timedelta
from email.message import EmailMessage
from email.utils import format_datetime
from pathlib import Path

import pymupdf as fitz  # PyMuPDF : CMD4_PYTHON le fournit déjà

SEED = 20261013  # graine fixe : la rejouabilité du corpus et de la vérité en dépend

RACINE = Path(__file__).resolve().parents[2]
SIM = RACINE / "var" / "sim"
CORPUS = SIM / "corpus"
LECTURES = SIM / "lectures.json"
VERITE_VAR = SIM / "verite-corpus.json"

PAGE_L, PAGE_H = 595.0, 842.0  # A4 points
MARGE, INTERLIGNE, TAILLE_POLICE = 55.0, 13.0, 9.5
THEME = "Fonds Documentaire des Essais"  # propriétaire de la GED (fictif), destinataire de la vérité

# Nomenclature GEDv2 (src/Socle/Nomenclature.php) : la vérité parle la langue de l'application.
TYPE_POLICE = "Police d’assurance"
TYPE_AVIS = "Avis d’impôt"

# Organisations fictives. Le nom contient une forme institutionnelle lisible par le classeur
# (SA/Sàrl/GmbH/Commune/Office/Banque/Assurances/Énergies) et aucune identité réelle.
ORGANISATIONS = [
    {"cle": "papeterie", "nom": "Papeterie des Sapins Fictive SA", "rue": "Rue du Marché 12", "npa": "2300",
     "ville": "La Chaux-de-Fonds", "ide": "CHE-100.000.001 TVA", "domaine": "papeterie-sapins.exemple.ch"},
    {"cle": "atelier", "nom": "Atelier Horizon Démonstration Sàrl", "rue": "Avenue Léopold-Robert 99", "npa": "2300",
     "ville": "La Chaux-de-Fonds", "ide": "CHE-100.000.002 TVA", "domaine": "atelier-horizon.exemple.ch"},
    {"cle": "gerance", "nom": "Régie Immobilière du Modèle SA", "rue": "Place du Test 3", "npa": "2400",
     "ville": "Le Locle", "ide": "CHE-100.000.003 TVA", "domaine": "regie-modele.exemple.ch"},
    {"cle": "assurance", "nom": "Assurances Alpines Fictives SA", "rue": "Quai Fictif 7", "npa": "2000",
     "ville": "Neuchâtel", "ide": "CHE-100.000.004 TVA", "domaine": "alpines-fictives.exemple.ch"},
    {"cle": "commune", "nom": "Commune de Modèlebourg", "rue": "Hôtel de Ville", "npa": "2999",
     "ville": "Modèlebourg", "ide": "", "domaine": "modelebourg.exemple.ch"},
    {"cle": "banque", "nom": "Banque Fictive du Jura SA", "rue": "Grand-Rue 1", "npa": "2800",
     "ville": "Delémont", "ide": "", "domaine": "banque-fictive-jura.exemple.ch"},
    {"cle": "energie", "nom": "Énergies du Lac Fictif SA", "rue": "Route de l'Usine 5", "npa": "2000",
     "ville": "Neuchâtel", "ide": "CHE-100.000.005 TVA", "domaine": "energies-lac.exemple.ch"},
    {"cle": "office", "nom": "Office Cantonal des Prestations Fictives", "rue": "Rue de l'État 10", "npa": "2000",
     "ville": "Neuchâtel", "ide": "", "domaine": "office-prestations.exemple.ch"},
    {"cle": "garage", "nom": "Garage du Banc d'Essai Sàrl", "rue": "Rue des Pistons 4", "npa": "2300",
     "ville": "La Chaux-de-Fonds", "ide": "CHE-100.000.006 TVA", "domaine": "garage-banc.exemple.ch"},
    {"cle": "fiscal", "nom": "Étude Fiscale Exemple SA", "rue": "Rue du Cadastre 8", "npa": "2000",
     "ville": "Neuchâtel", "ide": "CHE-100.000.007 TVA", "domaine": "etude-fiscale.exemple.ch"},
    {"cle": "telecom", "nom": "Télécom Modèle SA", "rue": "Avenue des Ondes 22", "npa": "2500",
     "ville": "Bienne", "ide": "CHE-100.000.008 TVA", "domaine": "telecom-modele.exemple.ch"},
    {"cle": "muster", "nom": "Muster Versicherung GmbH", "rue": "Hauptstrasse 14", "npa": "8000",
     "ville": "Zürich", "ide": "CHE-100.000.009 TVA", "domaine": "muster-versicherung.exemple.ch", "langues": ["de"]},
    {"cle": "studio", "nom": "Studio Assicurazioni Modello SAGL", "rue": "Via del Test 6", "npa": "6900",
     "ville": "Lugano", "ide": "CHE-100.000.010 TVA", "domaine": "studio-modello.exemple.ch", "langues": ["it"]},
    {"cle": "consulting", "nom": "Fictive Consulting SA", "rue": "Test Street 9", "npa": "1200",
     "ville": "Genève", "ide": "CHE-100.000.011 TVA", "domaine": "fictive-consulting.exemple.ch", "langues": ["en"]},
]
ORG = {o["cle"]: o for o in ORGANISATIONS}

# Mots-clés de recherche : rares, sans accent, sans vocabulaire de type. Chacun est injecté dans un
# sous-ensemble FERMÉ de pièces ; les requêtes attendent exactement ce sous-ensemble.
MOTS_CLES = ["ramonage", "elagage", "desinsectisation", "debouchage", "etancheite",
             "carrelage", "vitrerie", "serrurerie"]

# Désignations d'articles neutres : aucun mot ne doit déclencher un type (facture, contrat…).
ARTICLES = [
    ("Ramettes A4 80 g", 6.90), ("Classeurs fédéraux", 4.50), ("Toner noir", 89.00),
    ("Enveloppes C5 (500)", 38.00), ("Intervention sur site (h)", 140.00), ("Licence antivirus (an)", 45.00),
    ("Câble réseau 5 m", 9.80), ("Pneu hiver 205/55", 132.00), ("Main d'œuvre atelier (h)", 118.00),
    ("Kilowattheure", 0.27), ("Déplacement (km)", 0.95), ("Nettoyage vitres (m2)", 4.20),
]

# Prépositions/qualificatifs plurilingues pour les en-têtes.
LIBELLES = {
    "fr": {"date": "Date", "objet": "Concerne", "echeance": "Échéance", "total": "Total TTC",
           "reçu": "Reçu", "facture": "FACTURE", "mouvements": "Mouvements du mois", "charges": "Charges"},
    "de": {"date": "Datum", "objet": "Betreff", "echeance": "Zahlbar bis", "total": "Total",
           "reçu": "Quittung", "facture": "RECHNUNG", "mouvements": "Bewegungen", "charges": "Kosten"},
    "it": {"date": "Data", "objet": "Oggetto", "echeance": "Scadenza", "total": "Totale",
           "reçu": "Ricevuta", "facture": "FATTURA", "mouvements": "Movimenti", "charges": "Spese"},
    "en": {"date": "Date", "objet": "Subject", "echeance": "Due", "total": "Total",
           "reçu": "Receipt", "facture": "INVOICE", "mouvements": "Movements", "charges": "Charges"},
}

# Indices de vocabulaire de type par langue : la ligne doit rendre le type attendu par le classeur.
MOT_TYPE = {
    ("Facture", "fr"): "FACTURE", ("Facture", "de"): "RECHNUNG", ("Facture", "it"): "FATTURA",
    ("Facture", "en"): "INVOICE",
    ("Note de crédit", "fr"): "NOTE DE CRÉDIT", ("Note de crédit", "de"): "GUTSCHRIFT",
    ("Note de crédit", "en"): "CREDIT NOTE",
    ("Reçu", "fr"): "REÇU", ("Reçu", "de"): "QUITTUNG",
    ("Contrat", "fr"): "CONTRAT", ("Contrat", "de"): "VERTRAG",
    ("Document RH", "fr"): "ATTESTATION DE TRAVAIL",
    ("Décompte", "fr"): "DÉCOMPTE", ("Décompte", "de"): "ABRECHNUNG",
    ("Relevé", "fr"): "RELEVÉ DE COMPTE", ("Relevé", "de"): "KONTOAUSZUG", ("Relevé", "it"): "ESTRATTO CONTO",
    ("Attestation", "fr"): "ATTESTATION",
    (TYPE_POLICE, "fr"): "POLICE D'ASSURANCE", (TYPE_POLICE, "de"): "VERSICHERUNGSPOLICE",
    (TYPE_POLICE, "it"): "POLIZZA ASSICURATIVA",
    (TYPE_AVIS, "fr"): "AVIS D'IMPÔT", (TYPE_AVIS, "de"): "STEUERVERANLAGUNG", (TYPE_AVIS, "it"): "AVVISO FISCALE",
    ("Décision", "fr"): "DÉCISION", ("Décision", "de"): "VERFÜGUNG", ("Décision", "it"): "DECISIONE",
    ("Courrier", "fr"): "", ("Courrier", "en"): "",
}

LOREM = ("Nous vous remercions de votre confiance. Le présent document est établi pour "
         "l'espace de simulation de la GED et ne concerne aucune personne réelle. ")


# --------------------------------------------------------------------------- helpers

def slug(texte: str) -> str:
    """Slug ASCII stable pour un nom de fichier (pas de dépendance externe)."""
    table = str.maketrans({"à": "a", "á": "a", "â": "a", "ä": "a", "ç": "c", "è": "e", "é": "e",
                           "ê": "e", "ë": "e", "î": "i", "ï": "i", "ô": "o", "ö": "o", "ù": "u",
                           "û": "u", "ü": "u", "œ": "oe", "’": "", "'": "", "é": "e"})
    bas = texte.lower().translate(table)
    garde = [c if c.isalnum() else "-" for c in bas]
    return "-".join("".join(garde).split("-")).strip("-")[:70]


def iso(d: date) -> str:
    return d.strftime("%Y-%m-%d")


def ch(d: date) -> str:
    return d.strftime("%d.%m.%Y")


def tirage(rng: random.Random, jour_min: date, jour_max: date) -> date:
    return jour_min + timedelta(days=rng.randint(0, (jour_max - jour_min).days))


def montant(rng: random.Random, minimum: float, maximum: float) -> float:
    return round(rng.uniform(minimum, maximum), 2)


# --------------------------------------------------------------------------- rendu PDF

def fond_scan(page: fitz.Page) -> None:
    """Allure de scan sans image lourde : bandeau gris, filets fins (le texte reste en couche invisible)."""
    page.draw_rect(fitz.Rect(0, 0, PAGE_L, 88), color=None, fill=(0.90, 0.90, 0.90))
    for y in range(120, int(PAGE_H) - 60, 46):
        page.draw_line(fitz.Point(MARGE, y), fitz.Point(PAGE_L - MARGE, y), color=(0.86, 0.86, 0.86), width=0.4)


def ecrire_page(page: fitz.Page, lignes: list[str], invisible: bool) -> None:
    y = MARGE + 6
    for ligne in lignes:
        if y > PAGE_H - MARGE:
            break
        if ligne:
            page.insert_text((MARGE, y), ligne, fontname="helv", fontsize=TAILLE_POLICE,
                             render_mode=3 if invisible else 0)
        y += INTERLIGNE


def rendre_pdf(plan: dict, page_vers_pdf: Path, pages_lignes: list[list[str]]) -> None:
    doc = fitz.open()
    for lignes in pages_lignes:
        page = doc.new_page(width=PAGE_L, height=PAGE_H)
        if plan["scan"]:
            fond_scan(page)
        ecrire_page(page, lignes, plan["scan"])
    doc.set_metadata({"title": plan["titre"], "author": ORG[plan["org"]]["nom"], "subject": plan["type"],
                      "producer": "GEDv2 simulateur", "creator": "ged-sim-corpus",
                      "creationDate": "D:20261013120000Z", "modDate": "D:20261013120000Z"})
    page_vers_pdf.parent.mkdir(parents=True, exist_ok=True)
    doc.save(str(page_vers_pdf), garbage=4, deflate=True, no_new_id=True)
    doc.close()


def pdf_octets(lignes: list[str]) -> bytes:
    """Petit PDF d'une page (pièce jointe d'e-mail), en mémoire."""
    doc = fitz.open()
    page = doc.new_page(width=PAGE_L, height=PAGE_H)
    ecrire_page(page, lignes, False)
    octets = doc.tobytes(garbage=4, deflate=True, no_new_id=True)
    doc.close()
    return octets


# --------------------------------------------------------------------------- plans

def entete(org: dict, langue: str, plan: dict) -> list[str]:
    """En-tête émetteur : la première ligne EST l'émetteur (le classeur lit la première organisation)."""
    lignes = [org["nom"], org["rue"], f"{org['npa']} {org['ville']}"]
    if org["ide"]:
        lignes.append(org["ide"])
    lignes.append("")
    lignes.append(f"Destinataire : {THEME}")
    lignes.append("Madame A. Exemple")
    lignes.append("Chemin de l'Essai 1")
    lignes.append("2300 La Chaux-de-Fonds")
    lignes.append("")
    return lignes


def ligne_date(plan: dict) -> str:
    org = ORG[plan["org"]]
    if plan["langue"] == "fr":
        return f"{org['ville']}, le {ch(plan['date'])}"
    if plan["langue"] == "de":
        return f"{org['ville']}, den {ch(plan['date'])}"
    if plan["langue"] == "it":
        return f"{org['ville']}, il {ch(plan['date'])}"
    return f"Date : {ch(plan['date'])}"


def ligne_objet(plan: dict, objet: str) -> str:
    return f"{LIBELLES[plan['langue']]['objet']} : {objet}"


def ligne_type(plan: dict) -> str:
    mot = MOT_TYPE.get((plan["type"], plan["langue"]), "")
    if plan["type"] == "Courrier" or mot == "":
        return ligne_objet(plan, plan["objet"])
    ref = plan["reference"]
    if plan["type"] in ("Note de crédit", "Reçu"):
        return f"{mot} N° {ref}"
    return f"{mot} N° {ref}"


def articles(rng: random.Random, n: int, base: int) -> list[tuple[str, int, float]]:
    lignes = []
    for i in range(n):
        nom, prix = ARTICLES[(base + i) % len(ARTICLES)]
        lignes.append((nom, 1 + (base * 7 + i * 3) % 9, prix))
    return lignes


def page_facture(plan: dict, org: dict, articles_bloc: list[tuple[str, int, float]], k: int, total_pages: int,
                 ht_courant: float, dernier: bool) -> list[str]:
    lignes: list[str] = []
    if k == 0:
        lignes += entete(org, plan["langue"], plan)
        lignes.append(ligne_type(plan))
        lignes.append(f"{LIBELLES[plan['langue']]['date']} : {ch(plan['date'])}")
        lignes.append(f"{LIBELLES[plan['langue']]['echeance']} : 30 jours net")
        lignes.append("")
    else:
        lignes.append(f"{org['nom']} — {plan['reference']} (suite)")
        lignes.append("")
    lignes.append("Désignation | Qté | Prix | Total")
    for nom, qte, prix in articles_bloc:
        lignes.append(f"{nom} | {qte} | {prix:.2f} | {qte * prix:.2f}")
    if dernier:
        tva = round(ht_courant * 0.081, 2)
        lignes.append("")
        lignes.append(f"Total HT | | | {ht_courant:.2f}")
        lignes.append(f"TVA 8.1 % | | | {tva:.2f}")
        lignes.append(f"{LIBELLES[plan['langue']]['total']} | | | {round(ht_courant + tva, 2):.2f}")
        lignes.append("Paiement par QR-facture. IBAN CH00 0000 0000 0000 0000 0 (fictif).")
    lignes.append(f"Page {k + 1}/{total_pages}")
    return lignes


def pages_facture(plan: dict, rng: random.Random) -> list[list[str]]:
    org = ORG[plan["org"]]
    n_lignes = plan["pages"] * 12
    bloc = articles(rng, n_lignes, plan["graine"])
    paquets = [bloc[i:i + 12] for i in range(0, len(bloc), 12)] or [[]]
    total = len(paquets)
    ht = 0.0
    pages = []
    for k, paquet in enumerate(paquets):
        ht = round(ht + sum(q * p for _, q, p in paquet), 2)
        pages.append(page_facture(plan, org, paquet, k, total, ht, k == total - 1))
    plan["montant"] = round(ht * 1.081, 2)
    return pages


def pages_texte(plan: dict, rng: random.Random) -> list[list[str]]:
    """Lettres, contrats, polices, décisions… : en-tête + paragraphes répartis sur les pages."""
    org = ORG[plan["org"]]
    par_page = max(3, 22 // max(1, min(8, plan["pages"])))
    n_paras = max(2, plan["pages"] * par_page - 1)
    paragraphes = []
    for i in range(1, n_paras + 1):
        paragraphes.append(f"Article {i}. {plan['objet']} — {LOREM} Clause {i} : les conditions "
                           f"générales s'appliquent au présent envoi.")
    paquets = [paragraphes[i:i + par_page] for i in range(0, len(paragraphes), par_page)] or [[]]
    total = len(paquets)
    pages = []
    for k, paquet in enumerate(paquets):
        lignes = []
        if k == 0:
            lignes += entete(org, plan["langue"], plan)
            if plan["type"] in ("Contrat", "Police d’assurance"):
                lignes.append(ligne_type(plan))
                lignes.append(ligne_date(plan))
                lignes.append(ligne_objet(plan, plan["objet"]))
            else:
                lignes.append(ligne_date(plan))
                if plan["type"] == "Courrier":
                    lignes.append(ligne_objet(plan, plan["objet"]))
                else:
                    lignes.append(ligne_type(plan))
                    lignes.append(ligne_objet(plan, plan["objet"]))
            lignes.append("")
        else:
            lignes.append(f"{plan['objet']} (suite)")
            lignes.append("")
        lignes += paquet
        lignes.append(f"Page {k + 1}/{total}")
        pages.append(lignes)
    return pages


def pages_releve(plan: dict, rng: random.Random) -> list[list[str]]:
    org = ORG[plan["org"]]
    n = plan["pages"] * 14
    mouvements = []
    for i in range(1, n + 1):
        signe = "+" if i % 3 else "-"
        mouvements.append(f"{signe} {i:03d} Opération fictive n° {i} | {rng.uniform(5, 900):.2f}")
    paquets = [mouvements[i:i + 14] for i in range(0, len(mouvements), 14)]
    total = len(paquets)
    pages = []
    for k, paquet in enumerate(paquets):
        lignes = []
        if k == 0:
            lignes += entete(org, plan["langue"], plan)
            lignes.append(ligne_type(plan))
            lignes.append(f"{LIBELLES[plan['langue']]['date']} : {ch(plan['date'])}")
            lignes.append(LIBELLES[plan["langue"]]["mouvements"])
            lignes.append("")
        else:
            lignes.append(f"{plan['reference']} (suite)")
            lignes.append("")
        lignes += paquet
        lignes.append(f"Page {k + 1}/{total}")
        pages.append(lignes)
    return pages


def pages_decompte(plan: dict, rng: random.Random) -> list[list[str]]:
    org = ORG[plan["org"]]
    n = plan["pages"] * 10
    postes = [f"Poste {i} — {LOREM[:60]} | {rng.uniform(20, 500):.2f}" for i in range(1, n + 1)]
    paquets = [postes[i:i + 10] for i in range(0, len(postes), 10)]
    total = len(paquets)
    pages = []
    for k, paquet in enumerate(paquets):
        lignes = []
        if k == 0:
            lignes += entete(org, plan["langue"], plan)
            lignes.append(ligne_type(plan))
            lignes.append(ligne_date(plan))
            lignes.append("")
        else:
            lignes.append(f"{plan['reference']} (suite)")
            lignes.append("")
        lignes += paquet
        lignes.append(f"Page {k + 1}/{total}")
        pages.append(lignes)
    return pages


def pages_simple(plan: dict, rng: random.Random) -> list[list[str]]:
    """Reçu, attestation, avis d'impôt, décision, courrier : quelques lignes, une ou deux pages."""
    org = ORG[plan["org"]]
    corps = [
        LOREM,
        f"{plan['objet']}. {LOREM}",
        f"Référence du dossier : {plan['reference']}.",
    ]
    for i in range(1, plan["pages"] * 6):
        corps.append(f"Point {i}. {LOREM[:90]}")
    paquets = [corps[i:i + 22] for i in range(0, len(corps), 22)]
    total = len(paquets)
    pages = []
    for k, paquet in enumerate(paquets):
        lignes = []
        if k == 0:
            lignes += entete(org, plan["langue"], plan)
            lignes.append(ligne_date(plan))
            lignes.append(ligne_type(plan) if plan["type"] != "Courrier" else ligne_objet(plan, plan["objet"]))
            if plan["type"] != "Courrier":
                lignes.append(ligne_objet(plan, plan["objet"]))
            lignes.append("")
        else:
            lignes.append(f"{plan['objet']} (suite)")
            lignes.append("")
        lignes += paquet
        lignes.append(f"Page {k + 1}/{total}")
        pages.append(lignes)
    return pages


def rendre_pages(plan: dict, rng: random.Random) -> list[list[str]]:
    if plan["famille"] in ("facture", "note_credit"):
        return pages_facture(plan, rng)
    if plan["famille"] == "releve":
        return pages_releve(plan, rng)
    if plan["famille"] == "decompte":
        return pages_decompte(plan, rng)
    return pages_texte(plan, rng)


def rendre_simple(plan: dict, rng: random.Random) -> list[list[str]]:
    # Un courrier « gros » (dossier annuel scanné) suit un vrai remplissage multi-pages.
    if plan["pages"] > 6:
        return pages_texte(plan, rng)
    return pages_simple(plan, rng)


# --------------------------------------------------------------------------- injection de mots-clés

def injecter_mot_cle(plan: dict, mot: str, pages: list[list[str]]) -> None:
    """Ajoute le mot-clé dans le corps, sans toucher l'en-tête (émetteur/date/type restent lisibles)."""
    if plan["famille"] in ("facture", "note_credit"):
        for ligne in pages[0]:
            if " | " in ligne and "Désignation" not in ligne:
                pages[0][pages[0].index(ligne)] = f"{ligne.split(' | ')[0]} {mot} | " + " | ".join(ligne.split(" | ")[1:])
                return
    if plan["famille"] in ("releve", "decompte"):
        pages[0].insert(len(pages[0]) - 1, f"{mot} | 120.00")
        return
    if plan["famille"] == "recu":
        for i, ligne in enumerate(pages[0]):
            if ligne.startswith("Reçu"):
                pages[0][i] = f"{ligne} Motif : {mot}."
                return
    if pages and len(pages[0]) > 1:
        # Courrier, contrat, police, attestation, décision, avis, e-mail : une phrase de corps.
        insertion = max(1, len(pages[0]) - 2)
        pages[0].insert(insertion, f"Objet traité : {mot}.")


# --------------------------------------------------------------------------- e-mails

def corps_email(plan: dict) -> list[str]:
    """Corps d'e-mail UNIQUE : sert au .eml ET au texte lu, donc la signature porte l'émetteur dans les deux."""
    org = ORG[plan["org"]]
    marqueur = (f"FACTURE N° {plan['reference']}" if plan["type"] == "Facture"
                else f"Concerne : {plan['objet']}")
    corps = ["Bonjour,", "", marqueur, "", f"{plan['objet']}. {LOREM}", f"Référence : {plan['reference']}."]
    if plan.get("mot_cle"):
        corps.append(f"Objet traité : {plan['mot_cle']}.")
    corps += ["", "Cordialement,", org["nom"], org["rue"], f"{org['npa']} {org['ville']}"]
    return corps


def rendre_email(plan: dict, rng: random.Random) -> bytes:
    org = ORG[plan["org"]]
    message = EmailMessage()
    message["From"] = f"{org['nom']} <contact@{org['domaine']}>"
    message["To"] = f"{THEME} <ged@exemple-simulation.ch>"
    message["Subject"] = plan["objet"]
    message["Date"] = format_datetime(datetime(plan["date"].year, plan["date"].month, plan["date"].day, 9, 0))
    message["Message-ID"] = f"<{plan['reference'].lower()}@{org['domaine']}>"
    message.set_content("\n".join(corps_email(plan)))
    for i in range(plan.get("pieces_jointes", 0)):
        lignes = [org["nom"], f"Pièce jointe {plan['reference']}-{i + 1}", plan["date"].strftime("%d.%m.%Y")]
        message.add_attachment(pdf_octets(lignes), maintype="application", subtype="pdf",
                               filename=f"{slug(plan['reference'])}-{i + 1}.pdf")
    # Frontière MIME fixe : le .eml est reproductible octet pour octet (aucune frontière aléatoire).
    message.set_boundary(f"=-gedv2-{plan['reference']}")
    return message.as_bytes()


def texte_email(plan: dict) -> str:
    org = ORG[plan["org"]]
    entete = [f"De : {org['nom']} <contact@{org['domaine']}>",
              f"À : {THEME} <ged@exemple-simulation.ch>",
              f"Objet : {plan['objet']}", f"Date : {ch(plan['date'])}", ""]
    return "\n".join(entete + corps_email(plan))


# --------------------------------------------------------------------------- construction du plan

def construire_plans(rng: random.Random) -> list[dict]:
    plans: list[dict] = []

    def ajouter(famille: str, type_: str, org_cle: str, langue: str, pages: int, scan: bool,
                dmin: date, dmax: date, reference: str, objet: str, graine: int,
                pieces_jointes: int = 0) -> None:
        plans.append({"famille": famille, "type": type_, "org": org_cle, "langue": langue, "pages": pages,
                      "scan": scan, "date": tirage(rng, dmin, dmax), "reference": reference, "objet": objet,
                      "titre": f"{type_} {reference}" if type_ != "Courrier" else objet,
                      "graine": graine, "montant": None, "pieces_jointes": pieces_jointes, "mot_cle": None})

    deb, fin = date(2024, 1, 1), date(2026, 10, 1)

    def reference(prefixe: str, i: int, annee: int) -> str:
        return f"{prefixe}-{annee}-{i:04d}"

    familles = [
        ("facture", 45), ("note_credit", 8), ("recu", 20), ("decompte", 15), ("releve", 18),
        ("contrat", 25), ("police", 12), ("attestation", 10), ("avis_impot", 8), ("decision", 10), ("courrier", 25),
    ]
    types_org = {
        "facture": ["energie", "atelier", "papeterie", "garage", "telecom", "office", "fiscal"],
        "note_credit": ["papeterie", "atelier", "energie"],
        "recu": ["garage", "papeterie", "energie", "commune", "atelier"],
        "decompte": ["gerance", "assurance", "office"],
        "releve": ["banque", "office"],
        "contrat": ["gerance", "atelier", "assurance", "telecom", "garage"],
        "police": ["assurance", "muster", "studio"],
        "attestation": ["commune", "office", "fiscal"],
        "avis_impot": ["office", "fiscal"],
        "decision": ["office", "commune", "studio"],
        "courrier": ["commune", "office", "gerance", "banque", "fiscal", "consulting"],
    }
    type_par_famille = {
        "facture": "Facture", "note_credit": "Note de crédit", "recu": "Reçu", "decompte": "Décompte",
        "releve": "Relevé", "contrat": "Contrat", "police": TYPE_POLICE, "attestation": "Attestation",
        "avis_impot": TYPE_AVIS, "decision": "Décision", "courrier": "Courrier",
    }

    compteurs: dict[str, int] = {}
    for famille, n in familles:
        for i in range(n):
            compteurs[famille] = compteurs.get(famille, 0) + 1
            idx = compteurs[famille]
            cle_org = types_org[famille][i % len(types_org[famille])]
            org = ORG[cle_org]
            langue = org.get("langues", ["fr"])[0] if "langues" in org else "fr"
            if langue == "fr" and rng.random() < 0.12:
                langue = rng.choice(["de", "it", "en"])
            type_ = type_par_famille[famille]
            if (type_, langue) not in MOT_TYPE and type_ != "Courrier":
                langue = "fr"
            if famille == "courrier" and langue not in ("fr", "en"):
                langue = "fr"

            if famille in ("facture", "note_credit"):
                pages = rng.choice([1, 1, 2, 2, 3, 4, 5])
                prefixe = {"Facture": "FA", "Note de crédit": "NC"}[type_]
                ref = reference(prefixe, idx, rng.choice([2024, 2025, 2026]))
                objet = f"{'Facture' if type_ == 'Facture' else 'Note de crédit'} {ref}"
            elif famille == "recu":
                pages = 1
                ref = reference("RC", idx, rng.choice([2024, 2025, 2026]))
                objet = f"Reçu {ref}"
            elif famille == "releve":
                pages = rng.choice([3, 4, 5])
                ref = reference("REL", idx, rng.choice([2024, 2025, 2026]))
                objet = f"Relevé de compte {ref}"
            elif famille == "decompte":
                pages = rng.choice([3, 4, 6])
                ref = reference("DEC", idx, rng.choice([2024, 2025, 2026]))
                objet = f"Décompte de charges {ref}"
            elif famille == "contrat":
                pages = rng.choice([4, 5, 6, 8, 12, 20])
                ref = reference("CT", idx, rng.choice([2024, 2025, 2026]))
                objet = f"Contrat {ref}"
            elif famille == "police":
                pages = rng.choice([4, 6, 8, 12])
                ref = reference("PO", idx, rng.choice([2024, 2025, 2026]))
                objet = f"Police d'assurance {ref}"
            elif famille == "attestation":
                pages = 1
                ref = reference("AT", idx, rng.choice([2024, 2025, 2026]))
                objet = f"Attestation {ref}"
            elif famille == "avis_impot":
                pages = 2
                ref = reference("IM", idx, rng.choice([2024, 2025]))
                objet = f"Avis d'impôt {ref}"
            elif famille == "decision":
                pages = rng.choice([2, 3, 4])
                ref = reference("DJ", idx, rng.choice([2024, 2025, 2026]))
                objet = f"Décision {ref}"
            else:  # courrier
                pages = rng.choice([1, 2, 3])
                ref = reference("CR", idx, rng.choice([2024, 2025, 2026]))
                objet = rng.choice(["Travaux dans l'immeuble", "Indexation du loyer", "Taxe des déchets",
                                    "Information sur les horaires", "Convocation de chantier",
                                    "Modification des conditions", "Votre demande de renseignements"])
            scan = rng.random() < 0.40
            ajouter(famille, type_, cle_org, langue, pages, scan, deb, fin, ref, objet, graine=idx)

    # GROS PDF multi-pages : contrats, police, factures, relevés, courriers scannés.
    ajouter("contrat", "Contrat", "gerance", "fr", 120, False, deb, fin, "CT-BAIL-2026-0001",
            "Contrat de bail commercial", graine=900)
    ajouter("contrat", "Contrat", "atelier", "fr", 150, False, deb, fin, "CT-MAINT-2026-0002",
            "Contrat de maintenance informatique", graine=901)
    ajouter("police", TYPE_POLICE, "assurance", "fr", 80, False, deb, fin, "PO-RC-2026-0003",
            "Police responsabilité civile d'entreprise", graine=902)
    ajouter("facture", "Facture", "energie", "fr", 70, False, deb, fin, "FA-EN-2026-9001",
            "Facture d'électricité annuelle", graine=903)
    ajouter("releve", "Relevé", "banque", "fr", 60, True, deb, fin, "REL-2026-9002",
            "Relevé de compte courant annuel", graine=904)
    ajouter("courrier", "Courrier", "commune", "fr", 50, True, deb, fin, "CR-2026-9003",
            "Dossier communal annuel", graine=905)

    # E-MAILS .eml : expéditeur, destinataire, sujet, corps, pièces jointes.
    # Le type attendu s'accorde au marqueur du corps : « FACTURE N° » ou « Concerne : », jamais à contretemps.
    sujets_facture = ["Transmission de facture", "Facture de prestation mensuelle", "Justificatif de facturation"]
    sujets_courrier = ["Votre demande de renseignements", "Information chantier", "Confirmation d'intervention",
                       "Documents de fin d'année", "Prise de contact", "Suivi de dossier", "Rappel de rendez-vous"]
    for i in range(1, 36):
        cle_org = ["papeterie", "atelier", "assurance", "energie", "office", "commune", "fiscal"][i % 7]
        facture = i % 3 == 0
        type_ = "Facture" if facture else "Courrier"
        sujet = (sujets_facture if facture else sujets_courrier)[i % 3]
        ajouter("email", type_, cle_org, "fr", 1, False, deb, fin, f"EM-{i:04d}", sujet, graine=1000 + i,
                pieces_jointes=(1 + i % 3))

    # Mots-clés : un sous-ensemble fermé par mot-clé, réparti sur des pièces plausibles (jamais les e-mails).
    candidats = [p for p in plans if p["famille"] != "email" and p["pages"] <= 20]
    rng.shuffle(candidats)
    pool = candidats[:len(MOTS_CLES) * 9]  # 9 pièces par mot-clé : sous-ensemble fermé et mesurable
    for i, mot in enumerate(MOTS_CLES):
        for plan in pool[i::len(MOTS_CLES)]:
            plan.setdefault("mots_cles", [])
            plan["mots_cles"].append(mot)
    # Un e-mail sur sept porte aussi un mot-clé (mesure du plein texte sur les .eml).
    for i, mot in enumerate(MOTS_CLES):
        emails = [p for p in plans if p["famille"] == "email"]
        cible = emails[i % len(emails)]
        cible["mot_cle"] = mot
        cible.setdefault("mots_cles", []).append(mot)

    return plans


# --------------------------------------------------------------------------- écriture

def chemin_corpus(plan: dict, index: int) -> str:
    lot = f"lot-{index // 25 + 1:02d}"
    if plan["famille"] == "email":
        nom = f"{slug(plan['reference'])}-{slug(plan['objet'])}.eml"
    else:
        nom = f"{slug(plan['reference'])}-{slug(plan['objet'])}.pdf"
    return f"{lot}/{nom}"


def ecrire_corpus(plans: list[dict], rng: random.Random) -> tuple[list[dict], dict[str, str]]:
    """Écrit les fichiers du corpus (PDF/EML) puis rend la vérité des documents et le texte lu de chacun.

    Le mot-clé est injecté AVANT l'écriture : le fichier réel et sa lecture restent cohérents.
    """
    if CORPUS.exists():
        shutil.rmtree(CORPUS)  # régénérable, hors dépôt : on repart propre à chaque graine
    CORPUS.mkdir(parents=True)

    documents: list[dict] = []
    textes: dict[str, str] = {}
    for index, plan in enumerate(plans):
        email = plan["famille"] == "email"
        if email:
            pages = [texte_email(plan).split("\n")]
        elif plan["famille"] in ("recu", "attestation", "avis_impot", "decision", "courrier"):
            pages = rendre_simple(plan, rng)
        else:
            pages = rendre_pages(plan, rng)
        if not email and plan.get("mots_cles"):
            injecter_mot_cle(plan, plan["mots_cles"][0], pages)

        fichier = chemin_corpus(plan, index)
        cible = CORPUS / fichier
        cible.parent.mkdir(parents=True, exist_ok=True)
        if email:
            cible.write_bytes(rendre_email(plan, rng))
        else:
            rendre_pdf(plan, cible, pages)

        doc_id = f"sim-{index + 1:04d}"
        textes[doc_id] = "\n".join("\n".join(p) for p in pages)
        documents.append({
            "id": doc_id,
            "fichier": f"var/sim/corpus/{fichier}",
            "fichier_relatif": fichier,
            "famille": plan["famille"],
            "type": plan["type"],
            "emetteur": ORG[plan["org"]]["nom"],
            "destinataire": THEME,
            "date": iso(plan["date"]),
            "langue": plan["langue"],
            "pages": 1 if email else len(pages),
            "scan": plan["scan"],
            "montant": plan["montant"],
            "reference": plan["reference"],
            "objet": plan["objet"],
            "titre": f"{plan['type']} {plan['reference']}" if plan["type"] != "Courrier" else plan["objet"],
            "mots_cles": plan.get("mots_cles", []),
        })
    return documents, textes


def construire_requetes(documents: list[dict]) -> list[dict]:
    par_mot: dict[str, list[str]] = {}
    for d in documents:
        for mot in d["mots_cles"]:
            par_mot.setdefault(mot, []).append(d["id"])

    requetes = []
    for mot in MOTS_CLES:
        attendus = sorted(par_mot.get(mot, []))
        if attendus:
            requetes.append({"id": f"mot-{mot}", "q": mot, "attendu": attendus,
                             "note": f"mot-clé rare présent dans {len(attendus)} pièce(s)"})

    contrats = sorted(d["id"] for d in documents if d["type"] == "Contrat")
    if contrats:
        requetes.append({"id": "filtre-type-contrat", "q": "", "filtres": {"type": "Contrat"},
                         "attendu": contrats, "note": "filtre de type seul, sans terme"})

    emetteur = "Énergies du Lac Fictif SA"
    pieces_energie = sorted(d["id"] for d in documents if d["emetteur"] == emetteur)
    if pieces_energie:
        requetes.append({"id": "filtre-emetteur-energie", "q": "", "filtres": {"emetteur": emetteur},
                         "attendu": pieces_energie, "note": "filtre émetteur seul"})

    factures_2026 = sorted(d["id"] for d in documents if d["type"] == "Facture" and d["date"].startswith("2026"))
    if factures_2026:
        requetes.append({"id": "filtre-dates-factures-2026", "q": "",
                         "filtres": {"type": "Facture", "date_debut": "2026-01-01", "date_fin": "2026-12-31"},
                         "attendu": factures_2026, "note": "type + plage de dates"})

    # Requête par fragment : le plein texte (préfixe) ou le repli LIKE doit retrouver le mot entier.
    mot_fragment = MOTS_CLES[0]
    requetes.append({"id": "fragment-like", "q": mot_fragment[:-2], "attendu": sorted(par_mot.get(mot_fragment, [])),
                     "note": f"fragment de « {mot_fragment} » : plein texte en préfixe, sinon LIKE"})
    return requetes


def main() -> None:
    rng = random.Random(SEED)
    SIM.mkdir(parents=True, exist_ok=True)
    plans = construire_plans(rng)
    rng_ecriture = random.Random(SEED + 1)
    documents, textes = ecrire_corpus(plans, rng_ecriture)
    requetes = construire_requetes(documents)

    lectures = {"seed": SEED,
                "_role": "Texte lu de chaque pièce (ce qu'un OCR aurait rendu) ; régénérable.",
                "documents": [{"id": d["id"], "fichier": d["fichier"], "texte": textes[d["id"]]}
                              for d in documents]}
    LECTURES.write_text(json.dumps(lectures, ensure_ascii=False, indent=1), encoding="utf-8")

    verite = {
        "version": 1,
        "seed": SEED,
        "_synthetique": True,
        "_genere_par": "tools/sim/generer-corpus.py",
        "_role": ("Vérité figée de l'espace de simulation : classement attendu et requêtes de recherche. "
                  "Écrite par le générateur, jamais déduite par l'application."),
        "corpus": "var/sim/corpus (régénéré, hors dépôt)",
        "monde": "Synthetique — identites et organisations fictives, aucune donnee reelle.",
        "documents": documents,
        "requetes": requetes,
    }
    VERITE_VAR.write_text(json.dumps(verite, ensure_ascii=False, indent=1), encoding="utf-8")

    pages_total = sum(d["pages"] for d in documents)
    gros = sum(1 for d in documents if d["pages"] >= 50)
    print(f"{len(documents)} documents, {pages_total} pages, {gros} gros PDF (>= 50 pages), "
          f"{len(requetes)} requetes -> {CORPUS}")
    print(f"verite : {VERITE_VAR}")


if __name__ == "__main__":
    main()
