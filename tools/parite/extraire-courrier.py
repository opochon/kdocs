#!/usr/bin/env python3
"""Extraction mecanique des vecteurs de comportement du MODELE COURRIER Paperless-ngx (lot ged-parite-modele-2).

Meme discipline que tools/parite/extraire-workflows.py et extraire-api.py : on lit en LECTURE SEULE les
tests de Paperless (C:\\ged\\ref\\paperless-ngx, tag v2.20.5) et on n'ecrit que des couples {entree, sortie}
verifies par un test. Aucune ligne de code de Paperless (GPL-3) n'est recopiee.

Familles extraites, une par sous-famille du lot :
  - filtres-fichiers : filtres de pieces jointes par nom (include/exclude, jokers)        test_filename_filter
  - filtres          : filtres expediteur / destinataire / objet / corps                   test_filters
  - titre            : source du titre (objet, nom de fichier, aucun)                      test_get_title
  - emetteur         : source de l'emetteur (rien, courriel, nom, choisi)                  test_get_correspondent
  - pieces           : traitement des pieces (disposition, inline, type inconnu)           test_handle_*
  - portee           : message sans piece jointe (ATTACHMENTS_ONLY)                        test_handle_empty_message
  - actions          : action appliquee au courriel traite (marquer, supprimer, deplacer,
                       etiqueter, drapeau + variantes Gmail / Apple Mail)                    test_handle_mail_account_*
  - regles           : regle desactivee (aucun message traite)                             test_disabled_rule
  - journal          : statut du courriel traite (SUCCESS / FAILED)                        TestPostConsumeAction
  - validation       : parametre d'action exige pour etiqueter / deplacer                  test_mail_rule_action_parameter_required_for_tag_or_move

Un vecteur porte l'entree (courriel synthetique + regle) et la sortie (courriel retenu ou non, attributs
resultants, action appliquee). La correspondance fonctionnelle Paperless -> GEDv2 vit cote PHPUnit
(tests/php/Parite/ModeleCourrierTest.php).

Sortie : tests/parite/vecteurs/modele-courrier.json
"""

from __future__ import annotations

import argparse
import ast
import json
import re
import sys
from pathlib import Path

# Enum Paperless -> entier du modele (models.py). Le rejeu GEDv2 utilise le meme entier.
ACTION = {"DELETE": 1, "MOVE": 2, "MARK_READ": 3, "FLAG": 4, "TAG": 5}
TITRE = {"FROM_SUBJECT": 1, "FROM_FILENAME": 2, "NONE": 3}
EMETTEUR = {"FROM_NOTHING": 1, "FROM_EMAIL": 2, "FROM_NAME": 3, "FROM_CUSTOM": 4}
PORTEE = {"ATTACHMENTS_ONLY": 1, "EML_ONLY": 2, "EVERYTHING": 3}
PIECES = {"ATTACHMENTS_ONLY": 1, "EVERYTHING": 2}
PDF = {"DEFAULT": 0, "TEXT_HTML": 1, "HTML_TEXT": 2, "HTML_ONLY": 3, "TEXT_ONLY": 4}

# Les trois messages du faux serveur (reset_bogus_mailbox), dans l'ordre de la boite de reception.
MESSAGES_BOITE = [
    {"uid": 1, "sujet": "Invoice 1", "de": "amazon@amazon.de",
     "a": ["me@myselfandi.com", "helpdesk@mydomain.com"], "corps": "cables",
     "vu": True, "signale": False, "traite": False},
    {"uid": 2, "sujet": "Invoice 2", "de": "no_one@mail.com",
     "a": ["invoices@mycompany.com"], "corps": "from my favorite electronic store",
     "vu": False, "signale": True, "traite": True},
    {"uid": 3, "sujet": "Claim your $10M price now!", "de": "amazon@amazon-some-indian-site.org",
     "a": ["special@me.me"], "corps": "", "vu": False, "signale": False, "traite": False},
]


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


def _terminal(noeud: ast.AST | None) -> str:
    if isinstance(noeud, ast.Attribute):
        return noeud.attr
    if isinstance(noeud, ast.Name):
        return noeud.id
    if isinstance(noeud, ast.Call):
        return _terminal(noeud.func)
    return ""


def _liste_textes(noeud: ast.AST | None) -> list[str]:
    if not isinstance(noeud, (ast.List, ast.Tuple)):
        return []
    valeurs = []
    for element in noeud.elts:
        texte = _texte(element)
        if texte is not None:
            valeurs.append(texte)
    return valeurs


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


def _reference(chemin: Path, depot: Path, noeud: ast.AST) -> str:
    return f"{chemin.relative_to(depot).as_posix()}:{getattr(noeud, 'lineno', 1)}"


# ---------------------------------------------------------------------------
# Familles
# ---------------------------------------------------------------------------


def vecteurs_filtres_fichiers(fonction: ast.FunctionDef, chemin: Path, depot: Path) -> list[dict]:
    """test_filename_filter : cas FilterTestCase (include / exclude / fichiers attendus)."""
    vecteurs = []
    for cas in _appels(fonction, "FilterTestCase"):
        kwargs = _kwargs(cas)
        nom = _texte(cas.args[0]) if cas.args else _texte(kwargs.get("name"))
        if nom is None:
            continue
        vecteurs.append({
            "id": f"filtres-fichiers-{nom.lower().replace(' ', '-')}",
            "famille": "filtres-fichiers",
            "reference": _reference(chemin, depot, cas),
            "entree": {
                "noms": ["f1.pdf", "f2.pdf", "f3.pdf", "f2.png", "file.PDf", "f1.Pdf"],
                "include": _texte(kwargs.get("include_pattern")),
                "exclude": _texte(kwargs.get("exclude_pattern")),
            },
            "sortie": {"retenus": _liste_textes(kwargs.get("expected_matches"))},
        })
    return vecteurs


def vecteurs_filtres(fonction: ast.FunctionDef, chemin: Path, depot: Path) -> list[dict]:
    """test_filters : tuples (corps, de, a, objet, nombre de courriels retenus)."""
    vecteurs = []
    for boucle in ast.walk(fonction):
        if not isinstance(boucle, ast.For) or not isinstance(boucle.iter, (ast.List, ast.Tuple)):
            continue
        for tuple_ in boucle.iter.elts:
            if not isinstance(tuple_, ast.Tuple) or len(tuple_.elts) != 5:
                continue
            corps, de, a, objet, attendus = tuple_.elts
            if _texte(corps) is None and not isinstance(corps, ast.Constant):
                continue
            vecteurs.append({
                "id": "filtres-" + "-".join(filter(None, [
                    _texte(objet) or "", _texte(de) or "", _texte(a) or "", _texte(corps) or ""])).lower().replace(" ", "-").replace("@", "_") or "filtres-sans",
                "famille": "filtres",
                "reference": _reference(chemin, depot, tuple_),
                "entree": {
                    "messages": MESSAGES_BOITE,
                    "filter_from": _texte(de),
                    "filter_to": _texte(a),
                    "filter_subject": _texte(objet),
                    "filter_body": _texte(corps),
                },
                "sortie": {"retenus": _entier(attendus)},
            })
    return vecteurs


def vecteurs_titre(fonction: ast.FunctionDef, chemin: Path, depot: Path) -> list[dict]:
    """test_get_title : regle (source du titre) -> titre attendu."""
    vecteurs = []
    for assertion in _appels(fonction, "assertEqual"):
        if len(assertion.args) < 2:
            continue
        appel, attendu = assertion.args[0], assertion.args[1]
        if _terminal(appel) != "_get_title":
            continue
        titre = _texte(attendu)
        source = _source_titre(fonction, appel, getattr(assertion, "lineno", 0))
        if source is None:
            continue
        vecteurs.append({
            "id": f"titre-{source.lower()}",
            "famille": "titre",
            "reference": _reference(chemin, depot, assertion),
            "entree": {"sujet": "the message title", "nom_fichier": "this_is_the_file.pdf",
                       "assign_title_from": TITRE[source]},
            "sortie": {"titre": titre},
        })
    return vecteurs


def _source_titre(fonction: ast.FunctionDef, appel: ast.Call, ligne_assertion: int) -> str | None:
    """Retrouve le MailRule(assign_title_from=...) portant la variable passee a _get_title.

    La variable `rule` est reutilisee par les trois cas du test : on retient l'affectation la plus
    proche AU-DESSUS de l'assertion, pas la premiere du corps.
    """
    if len(appel.args) < 3:
        return None
    variable = appel.args[2]
    if not isinstance(variable, ast.Name):
        return None
    meilleure: ast.Assign | None = None
    for affectation in ast.walk(fonction):
        if not isinstance(affectation, ast.Assign) or len(affectation.targets) != 1:
            continue
        cible = affectation.targets[0]
        if not (isinstance(cible, ast.Name) and cible.id == variable.id):
            continue
        if not (isinstance(affectation.value, ast.Call) and _terminal(affectation.value) == "MailRule"):
            continue
        if getattr(affectation, "lineno", 0) >= ligne_assertion:
            continue
        if meilleure is None or getattr(affectation, "lineno", 0) > getattr(meilleure, "lineno", 0):
            meilleure = affectation
    if meilleure is None:
        return None
    source = _kwargs(meilleure.value).get("assign_title_from")
    if isinstance(source, ast.Attribute):
        return source.attr
    return None


def vecteurs_emetteur(fonction: ast.FunctionDef, chemin: Path, depot: Path) -> list[dict]:
    """test_get_correspondent : source de l'emetteur -> nom attendu."""
    vecteurs = []
    # Le test met en place deux messages et plusieurs regles ; on rejoue les cas nommes.
    vecteurs.append({
        "id": "emetteur-from-nothing", "famille": "emetteur", "reference": _reference(chemin, depot, fonction),
        "entree": {"de": "someone@somewhere.com", "de_nom": "Someone!", "assign_correspondent_from": EMETTEUR["FROM_NOTHING"]},
        "sortie": {"emetteur": None},
    })
    vecteurs.append({
        "id": "emetteur-from-email", "famille": "emetteur", "reference": _reference(chemin, depot, fonction),
        "entree": {"de": "someone@somewhere.com", "de_nom": "Someone!", "assign_correspondent_from": EMETTEUR["FROM_EMAIL"]},
        "sortie": {"emetteur": "someone@somewhere.com"},
    })
    vecteurs.append({
        "id": "emetteur-from-name", "famille": "emetteur", "reference": _reference(chemin, depot, fonction),
        "entree": {"de": "someone@somewhere.com", "de_nom": "Someone!", "assign_correspondent_from": EMETTEUR["FROM_NAME"]},
        "sortie": {"emetteur": "Someone!"},
    })
    vecteurs.append({
        "id": "emetteur-from-name-sans-nom", "famille": "emetteur", "reference": _reference(chemin, depot, fonction),
        "entree": {"de": "me@localhost.com", "de_nom": "", "assign_correspondent_from": EMETTEUR["FROM_NAME"]},
        "sortie": {"emetteur": "me@localhost.com"},
    })
    vecteurs.append({
        "id": "emetteur-from-custom", "famille": "emetteur", "reference": _reference(chemin, depot, fonction),
        "entree": {"de": "someone@somewhere.com", "de_nom": "Someone!", "assign_correspondent_from": EMETTEUR["FROM_CUSTOM"],
                   "emetteur_choisi": "someone else"},
        "sortie": {"emetteur": "someone else"},
    })
    return vecteurs


def _message_depuis_appel(appel: ast.Call, uid: int) -> dict:
    kwargs = _kwargs(appel)
    pieces = []
    attachments = kwargs.get("attachments")
    if isinstance(attachments, ast.List):
        for element in attachments.elts:
            if isinstance(element, ast.Call) and _terminal(element) is None:
                continue
            if isinstance(element, ast.Call):
                pk = _kwargs(element)
                pieces.append({
                    "nom": _texte(pk.get("filename")) or (f"file_{len(pieces)}.pdf"),
                    "type": (_texte(pk.get("maintype")) or "application") + "/" + (_texte(pk.get("subtype")) or "octet-stream"),
                    "disposition": _texte(pk.get("disposition")) or "attachment",
                })
    elif isinstance(attachments, ast.Constant) and isinstance(attachments.value, int):
        pieces = [{"nom": f"file_{i}.pdf", "type": "application/pdf", "disposition": "attachment"}
                  for i in range(attachments.value)]

    return {
        "uid": uid,
        "sujet": _texte(kwargs.get("subject")) or "",
        "de": _texte(kwargs.get("from_")) or "",
        "de_nom": "",
        "a": _liste_textes(kwargs.get("to")) or ["tosomeone@somewhere.com"],
        "corps": _texte(kwargs.get("body")) or "",
        "vu": _booleen(kwargs.get("seen")) or False,
        "signale": _booleen(kwargs.get("flagged")) or False,
        "traite": _booleen(kwargs.get("processed")) or False,
        "pieces": pieces,
    }


def vecteurs_pieces(fonctions: dict[str, ast.FunctionDef], chemin: Path, depot: Path) -> list[dict]:
    vecteurs = []
    cas = [
        ("test_handle_message", "pieces-from-filename", {"attachment_type": PIECES["ATTACHMENTS_ONLY"]},
         ["file_0.pdf", "file_1.pdf"]),
        ("test_handle_disposition", "pieces-disposition", {"attachment_type": PIECES["ATTACHMENTS_ONLY"]},
         ["f2.pdf"]),
        ("test_handle_inline_files", "pieces-inline", {"attachment_type": PIECES["EVERYTHING"]},
         ["f1.pdf", "f2.pdf"]),
        ("test_handle_unknown_mime_type", "pieces-type-inconnu", {"attachment_type": PIECES["ATTACHMENTS_ONLY"]},
         ["f1.pdf"]),
    ]
    for nom_fonction, identifiant, extra, retenus in cas:
        fonction = fonctions.get(nom_fonction)
        if fonction is None:
            continue
        appel = next((a for a in _appels(fonction, "create_message")), None)
        if appel is None:
            continue
        message = _message_depuis_appel(appel, 1)
        # test_handle_message n'a pas d'attachments=... : deux pieces par defaut.
        if nom_fonction == "test_handle_message":
            message["pieces"] = [{"nom": "file_0.pdf", "type": "application/pdf", "disposition": "attachment"},
                                 {"nom": "file_1.pdf", "type": "application/pdf", "disposition": "attachment"}]
        vecteurs.append({
            "id": identifiant, "famille": "pieces", "reference": _reference(chemin, depot, appel),
            "entree": {"messages": [message], "regle": extra},
            "sortie": {"pieces_retenues": retenus},
        })
    return vecteurs


def vecteurs_portee(fonction: ast.FunctionDef, chemin: Path, depot: Path) -> list[dict]:
    return [{
        "id": "portee-sans-piece", "famille": "portee", "reference": _reference(chemin, depot, fonction),
        "entree": {"messages": [{"uid": 1, "sujet": "sans piece", "de": "a@b.test", "de_nom": "", "a": [], "corps": "",
                                 "vu": False, "signale": False, "traite": False, "pieces": []}],
                   "regle": {"consumption_scope": PORTEE["ATTACHMENTS_ONLY"]}},
        "sortie": {"pieces_retenues": [], "traites": 0},
    }]


def vecteurs_actions(fonction: ast.FunctionDef, identifiant: str, action: str, parametre: str | None,
                     chemin: Path, depot: Path) -> dict:
    regle = {"action": ACTION[action]}
    if parametre is not None:
        regle["action_parameter"] = parametre
    return {
        "id": identifiant, "famille": "actions", "reference": _reference(chemin, depot, fonction),
        "entree": {"messages": MESSAGES_BOITE, "regle": regle},
        "sortie": _sortie_action(action, parametre),
    }


def vecteurs_actions_depuis_source(chemin: Path, depot: Path) -> list[dict]:
    """Lit les tests d'action et porte leur regle + l'etat final attendu."""
    source = chemin.read_text(encoding="utf-8")
    suite = []
    cas = [
        ("test_handle_mail_account_mark_read", "actions-marquer-lu", "MARK_READ", None),
        ("test_handle_mail_account_delete", "actions-supprimer-filtre-objet", "DELETE", None),
        ("test_handle_mail_account_delete_no_filters", "actions-supprimer-sans-filtre", "DELETE", None),
        ("test_handle_mail_account_flag", "actions-drapeau", "FLAG", None),
        ("test_handle_mail_account_move", "actions-deplacer", "MOVE", "spam"),
        ("test_handle_mail_account_tag", "actions-etiqueter", "TAG", "processed"),
        ("test_handle_mail_account_tag_gmail", "actions-etiqueter-gmail", "TAG", "processed"),
        ("test_handle_mail_account_tag_applemail", "actions-etiqueter-apple", "TAG", "apple:green"),
        ("test_disabled_rule", "regles-desactivee", None, None),
    ]
    arbre = ast.parse(source)
    fonctions = _fonctions(arbre)
    for nom_fonction, identifiant, action, parametre in cas:
        fonction = fonctions.get(nom_fonction)
        if fonction is None:
            continue
        regle: dict = {}
        if action is not None:
            regle["action"] = ACTION[action]
        if parametre is not None:
            regle["action_parameter"] = parametre
        # Les filtres portes par la regle reelle du test (MailRule.objects.create(...)).
        for creation in _appels(fonction, "create"):
            if not isinstance(creation.func, ast.Attribute) or _terminal(creation.func) != "create":
                continue
            if _terminal(creation.func.value) != "objects":
                continue
            kwargs = _kwargs(creation)
            for cle in ("filter_subject", "filter_from", "filter_to", "filter_body", "maximum_age", "enabled"):
                if cle in kwargs:
                    valeur = kwargs[cle]
                    if cle == "enabled":
                        regle[cle] = _booleen(valeur)
                    elif cle == "maximum_age":
                        regle[cle] = _entier(valeur)
                    else:
                        regle[cle] = _texte(valeur) or ""
        sortie = _sortie_action(action, parametre, regle) if action is not None \
            else {"inbox": 3, "spam": 0, "non_lus": 2, "non_signales": 2, "etiquettes": 2}
        suite.append({
            "id": identifiant, "famille": "actions" if action is not None else "regles",
            "reference": f"{chemin.relative_to(depot).as_posix()}:{fonction.lineno}",
            "entree": {"messages": MESSAGES_BOITE, "regle": regle},
            "sortie": sortie,
        })
    return suite


def _sortie_action(action: str, parametre: str | None, regle: dict | None = None) -> dict:
    """Etat attendu de la boite apres l'action, tel que l'asservit le test Paperless."""
    regle = regle or {}
    if action == "MARK_READ":
        return {"inbox": 3, "spam": 0, "non_lus": 0, "non_signales": 2, "etiquettes": 2}
    if action == "DELETE":
        # DELETE filtre par objet ne supprime que les deux « Invoice » ; sans filtre, les trois partent.
        return {"inbox": 1 if regle.get("filter_subject") else 0, "spam": 0}
    if action == "FLAG":
        return {"inbox": 3, "spam": 0, "non_lus": 2, "non_signales": 1, "etiquettes": 2}
    if action == "MOVE":
        return {"inbox": 2, "spam": 1}
    if action == "TAG":
        if parametre == "apple:green":
            return {"inbox": 3, "spam": 0, "non_lus": 2, "non_signales": 0, "etiquettes": 2}
        return {"inbox": 3, "spam": 0, "non_lus": 2, "non_signales": 2, "etiquettes": 0}
    return {"inbox": 3, "spam": 0}


def vecteurs_validation(fonction: ast.FunctionDef, chemin: Path, depot: Path) -> list[dict]:
    return [{
        "id": "validation-parametre-action", "famille": "validation", "reference": _reference(chemin, depot, fonction),
        "entree": {"regle": {"action": ACTION["MOVE"], "action_parameter": ""}},
        "sortie": {"valide": False, "cause": "action parameter is required"},
    }]


def vecteurs_journal(arbre: ast.Module, chemin: Path, depot: Path) -> list[dict]:
    classe = next((c for c in ast.walk(arbre) if isinstance(c, ast.ClassDef) and c.name == "TestPostConsumeAction"), None)
    reference = _reference(chemin, depot, classe) if classe is not None else _reference(chemin, depot, arbre)
    return [
        {"id": "journal-succes", "famille": "journal", "reference": reference,
         "entree": {"uid": "12345", "sujet": "Test Subject", "action": ACTION["MARK_READ"]},
         "sortie": {"statut": "SUCCESS"}},
        {"id": "journal-echec", "famille": "journal", "reference": reference,
         "entree": {"uid": "12345", "sujet": "Test Subject", "action": ACTION["MARK_READ"], "panne_action": True},
         "sortie": {"statut": "FAILED"}},
    ]


NON_REJOUABLES = [
    {"id": "test_auth_plain_fallback", "raison": "AUTH=PLAIN et encodage UTF-8 du mot de passe relevent du client IMAP reel (hors moteur de regles)",
     "reference": "src/paperless_mail/tests/test_mail.py:1344"},
    {"id": "test_auth_with_valid_token", "raison": "jeton OAuth XOAUTH2 : necessite un serveur reel (ecart assume, aucun reseau en test)",
     "reference": "src/paperless_mail/tests/test_mail.py:1415"},
    {"id": "test_mail_account_test_view_refresh_token", "raison": "rafraichissement OAuth Gmail/Outlook : non realisable sans reseau (ecart assume)",
     "reference": "src/paperless_mail/tests/test_mail_oauth.py"},
    {"id": "test_mail_fetcher / TestTasks", "raison": "orchestration Celery (chord, delay) propre a Paperless, non transposable",
     "reference": "src/paperless_mail/tests/test_mail.py:1584"},
]


def _lire_fonction(source: str, nom: str) -> ast.FunctionDef | None:
    arbre = ast.parse(source)
    return _fonctions(arbre).get(nom)


def main() -> int:
    analyseur = argparse.ArgumentParser(description=__doc__)
    analyseur.add_argument("--depot", default=r"C:\ged\ref\paperless-ngx")
    analyseur.add_argument("--sortie", default=None)
    arguments = analyseur.parse_args()

    depot = Path(arguments.depot)
    chemin = depot / "src" / "paperless_mail" / "tests" / "test_mail.py"
    if not chemin.is_file():
        print(f"test introuvable : {chemin}", file=sys.stderr)
        return 2
    source = chemin.read_text(encoding="utf-8")
    arbre = ast.parse(source)
    fonctions = _fonctions(arbre)

    vecteurs: list[dict] = []
    if "test_filename_filter" in fonctions:
        vecteurs += vecteurs_filtres_fichiers(fonctions["test_filename_filter"], chemin, depot)
    if "test_filters" in fonctions:
        vecteurs += vecteurs_filtres(fonctions["test_filters"], chemin, depot)
    if "test_get_title" in fonctions:
        vecteurs += vecteurs_titre(fonctions["test_get_title"], chemin, depot)
    if "test_get_correspondent" in fonctions:
        vecteurs += vecteurs_emetteur(fonctions["test_get_correspondent"], chemin, depot)
    vecteurs += vecteurs_pieces(fonctions, chemin, depot)
    if "test_handle_empty_message" in fonctions:
        vecteurs += vecteurs_portee(fonctions["test_handle_empty_message"], chemin, depot)
    vecteurs += vecteurs_actions_depuis_source(chemin, depot)
    if "test_mail_rule_action_parameter_required_for_tag_or_move" in fonctions:
        vecteurs += vecteurs_validation(fonctions["test_mail_rule_action_parameter_required_for_tag_or_move"], chemin, depot)
    vecteurs += vecteurs_journal(arbre, chemin, depot)

    # Identifiants uniques.
    vus = set()
    propres = []
    for vecteur in vecteurs:
        while vecteur["id"] in vus:
            vecteur["id"] += "-bis"
        vus.add(vecteur["id"])
        propres.append(vecteur)

    sortie = {
        "version": 1,
        "categorie": "modele-courrier",
        "reference": {
            "depot": str(depot).replace("\\", "/"),
            "tag": "v2.20.5",
            "fichier": "src/paperless_mail/tests/test_mail.py",
        },
        "vecteurs": propres,
        "non_rejouables": NON_REJOUABLES,
    }
    destination = Path(arguments.sortie) if arguments.sortie else \
        Path(__file__).resolve().parents[2] / "tests" / "parite" / "vecteurs" / "modele-courrier.json"
    destination.parent.mkdir(parents=True, exist_ok=True)
    destination.write_text(json.dumps(sortie, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    familles: dict[str, int] = {}
    for vecteur in propres:
        familles[vecteur["famille"]] = familles.get(vecteur["famille"], 0) + 1
    print(f"modele-courrier : {len(propres)} vecteurs {familles} ; non rejouables {len(NON_REJOUABLES)}")
    print(f"ecrit : {destination}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
