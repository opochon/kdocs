"""
« Courrier du matin » SYNTHETIQUE de 100 pages — banc d'ingestion K-Docs (27-09-2026).

Un seul PDF, comme un lot scanne d'un coup : ~30 documents de 1 a 6 pages, fictifs,
dont la verite (pages, type, correspondant, date, montant) est ecrite dans
verite.json. C'est l'ORACLE du moteur d'ingestion : on compare ce que la GED
decoupe, lit, regroupe et classe a cette verite, page par page.

Difficultes volontaires (celles d'un vrai courrier) :
  - ~40 % des pages sont des SCANS (image sans couche texte) : l'OCR/la vision doit lire ;
  - des documents multi-pages AVEC « Page x/y », d'autres SANS (suite de lettre) ;
  - deux pages blanches de separation : elles ne doivent ni devenir un document, ni se perdre ;
  - des factures consecutives du MEME fournisseur : la frontiere est au numero, pas a l'emetteur ;
  - un document INTERCALE : la derniere page d'un contrat arrive apres un recu (regroupement) ;
  - une page scannee a l'envers (rotation 180).

Usage : python tools/banc/generer-courrier.py   ->  tests/corpus-banc/courrier-100p/
"""
import io
import json
import random
from pathlib import Path

import fitz  # PyMuPDF
from fpdf import FPDF

RACINE = Path(__file__).resolve().parents[2]
SORTIE = RACINE / "tests" / "corpus-banc" / "courrier-100p"
TVA = 0.081
random.seed(20260927)

TIERS = {
    "papeterie": ("Papeterie des Sapins Fictive SA", "Rue du Marche 12", "2300 La Chaux-de-Fonds", "CHE-100.000.001 TVA"),
    "atelier": ("Atelier Numerique Exemple Sarl", "Avenue Leopold-Robert 99", "2300 La Chaux-de-Fonds", "CHE-100.000.002 TVA"),
    "gerance": ("Gerance Immobiliere Modele SA", "Place du Test 3", "2400 Le Locle", "CHE-100.000.003 TVA"),
    "assurance": ("Assurances Demonstration SA", "Quai Fictif 7", "2000 Neuchatel", "CHE-100.000.004 TVA"),
    "commune": ("Commune de Modeleville", "Hotel de Ville", "2999 Modeleville", ""),
    "banque": ("Banque Fictive du Jura", "Grand-Rue 1", "2800 Delemont", ""),
    "energie": ("Energies Exemple SA", "Route de l'Usine 5", "2000 Neuchatel", "CHE-100.000.005 TVA"),
    "office": ("Office cantonal fictif des prestations", "Rue de l'Etat 10", "2000 Neuchatel", ""),
    "garage": ("Garage du Banc d'Essai Sarl", "Rue des Pistons 4", "2300 La Chaux-de-Fonds", "CHE-100.000.006 TVA"),
}
DEST = ("Karbonic Banc de test", "Chemin de l'Essai 1", "2300 La Chaux-de-Fonds")
LOREM = ("Nous vous remercions de votre confiance. Le present document est etabli pour le banc de test "
         "de la GED et ne concerne aucune personne reelle. ")


class Page(FPDF):
    pass


def entete(p, tiers):
    nom, rue, ville, ide = TIERS[tiers]
    p.set_font("Helvetica", "B", 13)
    p.cell(0, 7, nom, new_x="LMARGIN", new_y="NEXT")
    p.set_font("Helvetica", "", 10)
    for l in (rue, ville, ide):
        if l:
            p.cell(0, 5, l, new_x="LMARGIN", new_y="NEXT")
    p.ln(5)
    p.set_x(120)
    p.multi_cell(0, 5, "\n".join(DEST))
    p.ln(5)


def pied(p, ref, n, total, numeroter):
    if numeroter:
        p.set_y(-18)
        p.set_font("Helvetica", "", 9)
        p.cell(0, 5, f"{ref}  -  Page {n}/{total}", align="C")


def nouvelle_page():
    p = Page()
    p.set_auto_page_break(False)
    p.add_page()
    return p


def pages_facture(tiers, numero, date, lignes, titre="FACTURE", numeroter=True, lignes_par_page=12):
    """Rend une facture sur 1..n pages ; retourne (liste de FPDF, montant TTC signe)."""
    blocs = [lignes[i:i + lignes_par_page] for i in range(0, len(lignes), lignes_par_page)] or [[]]
    total = len(blocs)
    pages, ht = [], 0.0
    for k, bloc in enumerate(blocs, start=1):
        p = nouvelle_page()
        if k == 1:
            entete(p, tiers)
            p.set_font("Helvetica", "B", 14)
            p.cell(0, 8, f"{titre} N° {numero}", new_x="LMARGIN", new_y="NEXT")
            p.set_font("Helvetica", "", 10)
            p.cell(0, 6, f"Date : {date}      Echeance : 30 jours net", new_x="LMARGIN", new_y="NEXT")
        else:
            p.set_font("Helvetica", "", 10)
            p.cell(0, 6, f"{TIERS[tiers][0]} - {titre} {numero} (suite)" if numeroter else "(suite)", new_x="LMARGIN", new_y="NEXT")
        p.ln(3)
        p.set_font("Helvetica", "B", 10)
        for t, w in (("Designation", 100), ("Qte", 20), ("Prix", 30), ("Total CHF", 30)):
            p.cell(w, 6, t, border=1)
        p.ln()
        p.set_font("Helvetica", "", 10)
        for des, q, pu in bloc:
            tot = round(q * pu, 2)
            ht += tot
            p.cell(100, 6, des, border=1)
            p.cell(20, 6, f"{q}", border=1, align="R")
            p.cell(30, 6, f"{pu:.2f}", border=1, align="R")
            p.cell(30, 6, f"{tot:.2f}", border=1, align="R")
            p.ln()
        if k == total:
            tva = round(ht * TVA, 2)
            ttc = round(ht + tva, 2)
            signe = -1 if titre.startswith("NOTE") else 1
            for lib, v in (("Total HT", ht), ("TVA 8.1 %", tva), ("Total TTC CHF", ttc)):
                p.cell(150, 6, lib, align="R")
                p.cell(30, 6, f"{signe * v:.2f}", align="R")
                p.ln()
            p.ln(3)
            p.cell(0, 5, "Payable par QR-facture. IBAN CH00 0000 0000 0000 0000 0 (fictif).", new_x="LMARGIN", new_y="NEXT")
        pied(p, f"{titre} {numero}", k, total, numeroter)
        pages.append(p)
    return pages, round((-1 if titre.startswith("NOTE") else 1) * round(ht * (1 + TVA), 2), 2)


def pages_texte(tiers, date, objet, paragraphes, ref, numeroter=True, par_page=4, titre_suite=True):
    """Lettre, contrat, decision, releve : paragraphes repartis sur plusieurs pages."""
    blocs = [paragraphes[i:i + par_page] for i in range(0, len(paragraphes), par_page)]
    total = len(blocs)
    pages = []
    for k, bloc in enumerate(blocs, start=1):
        p = nouvelle_page()
        if k == 1:
            entete(p, tiers)
            p.set_font("Helvetica", "", 10)
            p.cell(0, 6, f"{TIERS[tiers][2].split(' ', 1)[1]}, le {date}", new_x="LMARGIN", new_y="NEXT")
            p.ln(2)
            p.set_font("Helvetica", "B", 11)
            p.multi_cell(0, 6, f"Concerne : {objet}")
            p.ln(2)
        elif titre_suite:
            p.set_font("Helvetica", "I", 9)
            p.cell(0, 5, f"{objet} (suite)", new_x="LMARGIN", new_y="NEXT")
            p.ln(2)
        p.set_font("Helvetica", "", 10)
        for para in bloc:
            p.multi_cell(0, 5, para)
            p.ln(2)
        pied(p, ref, k, total, numeroter)
        pages.append(p)
    return pages


def paras(sujet, n):
    return [f"Article {i}. {sujet}. " + LOREM * 3 for i in range(1, n + 1)]


def articles(n, base):
    cat = [("Ramettes A4 80 g", 6.90), ("Classeurs federaux", 4.50), ("Toner noir", 89.00), ("Enveloppes C5 (500)", 38.00),
           ("Intervention sur site (h)", 140.00), ("Licence antivirus (an)", 45.00), ("Cable reseau 5 m", 9.80),
           ("Pneu hiver 205/55", 132.00), ("Main d'oeuvre atelier (h)", 118.00), ("Kilowattheure HT", 0.27)]
    return [(cat[(base + i) % len(cat)][0], 1 + (base * 7 + i * 3) % 9, cat[(base + i) % len(cat)][1]) for i in range(n)]


def composer():
    """Liste ordonnee d'ELEMENTS : un document (pages + verite) ou une page blanche."""
    D = []

    def doc(type_, tiers, date, pages, montant=None, scan=False, numerote=True, cas=None, dossier=None):
        D.append({"type": type_, "correspondant": TIERS[tiers][0], "date": date, "montant_ttc": montant,
                  "pages": pages, "scan": scan, "numerote": numerote, "cas": cas, "dossier": dossier})
        return D[-1]

    def fac(tiers, num, date, n_lignes, base, scan=False, numerote=True, cas=None, titre="FACTURE"):
        pages, ttc = pages_facture(tiers, num, date, articles(n_lignes, base), titre=titre, numeroter=numerote)
        return doc("Note de crédit" if titre.startswith("NOTE") else "Facture", tiers, date, pages, ttc, scan, numerote, cas)

    fac("papeterie", "PS-2026-101", "05.01.2026", 3, 0)
    doc("Décompte", "gerance", "08.01.2026", pages_texte("gerance", "08.01.2026", "Decompte de charges 2025", paras("Charges", 6), "GIM-2026-01", par_page=3), scan=True)
    D.append({"blanche": True})
    doc("Relevé", "banque", "10.01.2026", pages_texte("banque", "10.01.2026", "Releve de compte courant - decembre 2025", paras("Mouvements du mois", 9), "REL-12-2025", par_page=3), cas="releve 3 pages numerotees")
    fac("atelier", "AN-5001", "12.01.2026", 16, 3, scan=True, numerote=False, cas="facture 2 pages SANS numerotation, scan")
    doc("Contrat", "gerance", "15.01.2026", pages_texte("gerance", "15.01.2026", "Contrat de bail commercial - locaux Chemin de l'Essai 1", paras("Clause du bail", 20), "BAIL-2026-07", par_page=4), cas="contrat 5 pages numerotees")
    doc("Reçu", "garage", "16.01.2026", pages_texte("garage", "16.01.2026", "RECU", ["Recu la somme de CHF 74.55 en especes. Ticket R-2001."], "R-2001", numeroter=False), 74.55, scan=True, numerote=False)
    doc("Décision", "office", "18.01.2026", pages_texte("office", "18.01.2026", "Decision sur opposition - dossier fictif 2026-114", paras("Considerant", 14), "DEC-2026-114", numeroter=False, par_page=4, titre_suite=False),
        scan=True, numerote=False, cas="decision 4 pages scannees SANS numerotation ni rappel d'objet (le plus dur)")
    fac("atelier", "AN-5001-NC", "20.01.2026", 1, 5, titre="NOTE DE CREDIT")
    # Attestation adressee au proprietaire de la GED : Document RH (sinon Courrier) — regle d'Olivier 28-09.
    doc("Document RH", "commune", "22.01.2026", pages_texte("commune", "22.01.2026", "Attestation de travail - collaborateur fictif Jean Exemple", ["Nous attestons que M. Jean Exemple, collaborateur FICTIF du banc, est employe a 100 % depuis le 1er janvier 2024."], "ATT-01", numeroter=False), dossier="RH", numerote=False)
    fac("assurance", "AD-778812", "25.01.2026", 2, 1, scan=True)
    fac("papeterie", "PS-2026-102", "02.02.2026", 4, 2, cas="meme fournisseur que la precedente et la suivante : frontiere au numero")
    fac("papeterie", "PS-2026-103", "03.02.2026", 2, 4, cas="meme fournisseur, facture suivante immediate")
    contrat = doc("Contrat", "atelier", "05.02.2026", pages_texte("atelier", "05.02.2026", "Contrat de maintenance informatique n° CM-2026-04", paras("Prestation", 20), "CM-2026-04", par_page=4),
                  cas="INTERCALE : la page 5/5 (annexe) arrive APRES le recu suivant — regroupement attendu")
    doc("Reçu", "papeterie", "06.02.2026", pages_texte("papeterie", "06.02.2026", "RECU", ["Recu la somme de CHF 118.90. Merci de votre achat. Ticket R-2002."], "R-2002", numeroter=False), 118.90, numerote=False)
    fac("energie", "EE-2026-0212", "12.02.2026", 30, 9, cas="facture energie 3 pages numerotees, 30 lignes")
    D.append({"blanche": True})
    doc("Courrier", "gerance", "18.02.2026", pages_texte("gerance", "18.02.2026", "Travaux dans l'immeuble", paras("Planning", 2), "TRV-2026", numeroter=False), scan=True, numerote=False)
    fac("garage", "GB-4410", "20.02.2026", 5, 7, scan=True, cas="page scannee a l'envers", )
    doc("Contrat", "assurance", "01.03.2026", pages_texte("assurance", "01.03.2026", "Police responsabilite civile entreprise n° RC-000123", paras("Condition generale", 24), "RC-000123", par_page=4), cas="contrat 6 pages")
    doc("Relevé", "banque", "10.03.2026", pages_texte("banque", "10.03.2026", "Releve de compte courant - fevrier 2026", paras("Mouvements du mois", 6), "REL-02-2026", par_page=3), scan=True)
    fac("atelier", "AN-5044", "12.03.2026", 3, 6)
    doc("Courrier", "commune", "15.03.2026", pages_texte("commune", "15.03.2026", "Taxe des dechets 2026 - information", ["La taxe de base des dechets pour les entreprises est fixee a CHF 180.00 pour 2026."], "TD-2026", numeroter=False), numerote=False)
    doc("Reçu", "energie", "16.03.2026", pages_texte("energie", "16.03.2026", "RECU", ["Recu la somme de CHF 51.20 (acompte). Ticket R-2003."], "R-2003", numeroter=False), 51.20, scan=True, numerote=False)
    doc("Courrier", "office", "20.03.2026", pages_texte("office", "20.03.2026", "Convocation - dossier fictif 2026-114", paras("Modalite", 3), "CONV-114", par_page=3), numerote=False)
    fac("papeterie", "PS-2026-104", "25.03.2026", 14, 8, cas="facture 2 pages numerotees")
    doc("Contrat", "garage", "01.04.2026", pages_texte("garage", "01.04.2026", "Contrat de leasing vehicule utilitaire n° LV-889", paras("Condition", 16), "LV-889", par_page=4), scan=True, cas="contrat 4 pages scanne")
    fac("energie", "EE-2026-0412", "12.04.2026", 4, 3, scan=True)
    doc("Courrier", "gerance", "20.04.2026", pages_texte("gerance", "20.04.2026", "Indexation du loyer", paras("Base de calcul", 5), "IDX-2026", par_page=3))
    return D, contrat


def fpdf_vers_fitz(p):
    return fitz.open(stream=bytes(p.output()), filetype="pdf")


def ajouter_page(sortie, p, scan, rotation=0):
    src = fpdf_vers_fitz(p)
    if not scan:
        sortie.insert_pdf(src)
    else:
        # Scan : image en niveaux de gris a 130 dpi, AUCUNE couche texte.
        pix = src[0].get_pixmap(dpi=130, colorspace=fitz.csGRAY)
        pg = sortie.new_page(width=src[0].rect.width, height=src[0].rect.height)
        pg.insert_image(pg.rect, stream=pix.tobytes("png"))
    if rotation:
        sortie[-1].set_rotation(rotation)


def assembler(D, contrat, cible=100):
    sortie = fitz.open()
    verite, retenue, n_doc = [], None, 0
    rotation_faite = False
    for el in D:
        if el.get("blanche"):
            sortie.new_page()
            verite.append({"blanche": True, "pages": [len(sortie)]})
            continue
        n_doc += 1
        entree = {k: el[k] for k in ("type", "correspondant", "date", "montant_ttc", "scan", "numerote", "cas", "dossier")}
        entree["n"] = n_doc
        entree["pages"] = []
        pages = el["pages"]
        a_retenir = el is contrat
        for i, p in enumerate(pages):
            if a_retenir and i == len(pages) - 1:
                retenue = (entree, p, el["scan"])
                continue
            rot = 0
            if el.get("cas") and "envers" in el["cas"] and not rotation_faite:
                rot, rotation_faite = 180, True
            ajouter_page(sortie, p, el["scan"], rot)
            entree["pages"].append(len(sortie))
        verite.append(entree)
        if retenue and not a_retenir:
            e, p, scan = retenue
            ajouter_page(sortie, p, scan)
            e["pages"].append(len(sortie))
            retenue = None
    # Complement jusqu'a la cible : un melange realiste (facture 2 p., lettre 2 p., recu 1 p.),
    # alterne texte / scan, tronque proprement a la cible.
    k = 0
    while len(sortie) < cible:
        k += 1
        tiers = ["papeterie", "garage", "energie", "atelier", "gerance", "assurance"][k % 6]
        date = f"{(k % 27) + 1:02d}.05.2026"
        scan = bool(k % 2)
        reste = cible - len(sortie)
        genre = k % 3 if reste >= 2 else 2
        if genre == 0:
            pages, montant = pages_facture(tiers, f"CPL-{k:03d}", date, articles(16, k), numeroter=bool(k % 4))
            type_ = "Facture"
        elif genre == 1:
            pages, montant = pages_texte(tiers, date, f"Information n° {k}", paras("Point", 5), f"INF-{k:03d}", par_page=3), None
            type_ = "Courrier"
        else:
            montant = round(20 + k * 13.35, 2)
            pages = pages_texte(tiers, date, "RECU", [f"Recu la somme de CHF {montant:.2f}. Ticket R-3{k:03d}."], f"R-3{k:03d}", numeroter=False)
            type_ = "Reçu"
        n_doc += 1
        entree = {"n": n_doc, "type": type_, "correspondant": TIERS[tiers][0], "date": date, "montant_ttc": montant,
                  "scan": scan, "numerote": genre != 2, "cas": None, "dossier": None, "pages": []}
        for p in pages:
            ajouter_page(sortie, p, scan)
            entree["pages"].append(len(sortie))
        verite.append(entree)
    return sortie, verite


def main():
    SORTIE.mkdir(parents=True, exist_ok=True)
    D, contrat = composer()
    doc, verite = assembler(D, contrat)
    doc.save(str(SORTIE / "courrier-du-matin-100p.pdf"), garbage=4, deflate=True)
    docs = [v for v in verite if not v.get("blanche")]
    (SORTIE / "verite.json").write_text(json.dumps({
        "_role": "ORACLE du moteur d'ingestion : chaque document du lot, ses pages (numerotees a partir de 1), son type, son correspondant. Genere, ne pas editer.",
        "_genere_par": "tools/banc/generer-courrier.py",
        "fichier": "courrier-du-matin-100p.pdf",
        "pages": len(doc),
        "documents": len(docs),
        "pages_scannees": sum(len(v["pages"]) for v in docs if v["scan"]),
        "elements": verite,
    }, ensure_ascii=False, indent=1), encoding="utf-8")
    print(f"{len(doc)} pages, {len(docs)} documents, {sum(len(v['pages']) for v in docs if v['scan'])} pages scannees -> {SORTIE}")


if __name__ == "__main__":
    main()
