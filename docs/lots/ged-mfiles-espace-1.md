# Lot `ged-mfiles-espace-1` — espace perso & partage (favoris, récemment consultés, liens de partage)

Agent : DeepCode · Secteur : `docs/secteurs/espace-partage.md` · Juge : à désigner (porte rejouée).

## Objectif

Porter les 10 éléments M-Files `absent` du registre de parité relevant de l'espace personnel et du partage :
favoris (lister, savoir, ajouter, retirer), récemment consultés (lister, enregistrer), liens de partage
(lister, créer, télécharger par le lien, révoquer).

## Cadrage

- Contrat lu : `AGENTS.md`, `docs/parite/PARITE-MFILES.md`, `docs/qualite/README.md`.
- Base : `gedv2_test` uniquement (phpunit la force) ; corpus synthétique, noms fictifs.
- Principe : un favori / une consultation sont des données d'**index** (registre portable du fonds
  `.ged/`), jamais une copie du document ; le lien de partage n'expose jamais un document hors droits.
- Oracle d'abord : chaque élément a un test rouge avant le code.

## Journal d'avancement (au fil de l'eau)

- 14.10.2026 — Cadrage terminé. Chemins du secteur posés (`docs/secteurs/espace-partage.md`). Ouverture
  du journal.

_(la suite est écrite au fil du lot : oracle rouge, code vert, interface, preuves, registre, porte qualité)_
