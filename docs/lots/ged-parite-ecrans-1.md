# Lot ged-parite-ecrans-1 — rattraper les écarts `ecran` de la parité Paperless-ngx

Base : `ged-parite-modele-3` fusionné (`d06dd61`) : 780 éléments, **529 couverts**, parité stricte **67,82 %**.
Cible : la catégorie `ecran` (30 éléments, 12 couverts, **17 absents**, 1 exclu, strict 40,00 %).
Secteur : **Interface** (`docs/secteurs/interface.md` → chemins `templates/`, `public/assets/`, `src/Ui/`,
`tests/e2e/`). Les quelques lignes hors secteur (routes de données, permissions, harness, préférences)
sont signalées dans la rubrique « Demandes » de la fiche de secteur et dans ce journal.

Méthode oracle d'abord : (1) scénarios utilisateur dans `tests/parite/vecteurs/ecrans.json` (lecture seule
du `src-ui` de `C:\ged\ref\paperless-ngx`, aucun code GPL copié) ; (2) un parcours E2E Playwright par écran
qui AGIT par l'écran réel ; (3) constat des rouges ; (4) implémentation ; (5) `correspondance.json` et
rapport de parité remis à jour.

## Fait (commits)

- `07c637f` — **oracle** : `tests/parite/vecteurs/ecrans.json` (17 scénarios utilisateur, référence
  Paperless en lecture seule) et `tests/php/Parite/EcransPariteTest.php` (cohérence scénario ↔ gabarit ↔
  correspondance), vu ROUGE avant l'implémentation.
- _(à venir)_ **écrans** : tableau de bord, journaux (admin), corbeille (admin, restauration seule),
  réglages de l'utilisateur (général, notifications, permissions, vues), accès par numéro d'archive ;
  réutilisation de `/application` (configuration, chemins de stockage), `/taches`, `/vues`,
  `/administration/comptes|groupes`.
- _(à venir)_ **preuves E2E** : `tableau-de-bord.spec.ts`, `journaux.spec.ts`, `corbeille.spec.ts`,
  `reglages.spec.ts` (4 parcours), `asn.spec.ts`, `configuration.spec.ts`, `utilisateurs.spec.ts` +
  `preparer-ecrans.php` / `nettoyer-ecrans.php`.

## En cours

- `correspondance.json`, régénération `docs/parite/PARITE-PAPERLESS.md`, couche 9 relue par le harness.

## Reste

- Harness `-SansBanc` vert sur les couches touchées (1, 4, 7, 8, 9), chiffres exacts ci-dessous, `ETAT: fini`.

## Mesures exactes

- Parcours neufs rejoués en isolé (2026-10-12) : **8/8 verts** sur `asn`, `corbeille`, `journaux`,
  `reglages` (4 parcours) et `tableau-de-bord` ; `configuration` et `utilisateurs` **2/2 verts**.
- PHPStan niveau 6 : **0 erreur**, baseline vide inchangée.
