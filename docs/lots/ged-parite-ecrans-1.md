# Lot ged-parite-ecrans-1 — rattraper les écarts `ecran` de la parité Paperless-ngx

Base : `ged-parite-modele-3` fusionné (`d06dd61`) : 780 éléments, **529 couverts**, parité stricte **67,82 %**.
Cible : la catégorie `ecran` (30 éléments, 12 couverts, **17 absents**, 1 exclu, strict 40,00 %).
Secteur : **Interface** (`docs/secteurs/interface.md` → chemins `templates/`, `public/assets/`, `src/Ui/`,
`tests/e2e/`). Les quelques lignes hors secteur (routes de données, permissions, harness) sont signalées
dans la rubrique « Demandes » de la fiche de secteur et dans ce journal.

Méthode oracle d'abord : (1) scénarios utilisateur dans `tests/parite/vecteurs/ecrans.json` (lecture seule
du `src-ui` de `C:\ged\ref\paperless-ngx`, aucun code GPL copié) ; (2) un parcours E2E Playwright par écran
qui AGIT par l'écran réel ; (3) constat des rouges ; (4) implémentation ; (5) `correspondance.json` et
rapport de parité remis à jour.

## Fait (commits)

- _(en cours)_ **oracle** : `tests/parite/vecteurs/ecrans.json` (17 scénarios) et
  `tests/php/Parite/EcransPariteTest.php` (cohérence scénario ↔ gabarit ↔ correspondance) ; ce test est le
  rouge de départ.

## En cours

- Implémentation des écrans neufs (tableau de bord, journaux, corbeille, réglages, accès par numéro
  d'archive) et des preuves E2E des écrans réutilisés.

## Reste

- `correspondance.json`, régénération `docs/parite/PARITE-PAPERLESS.md`, harness `-SansBanc` vert.

## Mesures exactes

À compléter à chaque étape (voir la section « Mesures » en fin de lot).
