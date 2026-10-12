# Lot ged-parite-final-1 — amener GEDv2 à 100 % hors exclusions (Paperless-ngx 2.20.5)

Base : `v2` = `6090fe9` — parité stricte **89,87 %** (701/780), hors exclusions **92,72 %**, **55 absents**,
24 exclus. Deux listes exactes copiées dans `docs/lots/` :
`final-porter.txt` (36 éléments, TOUS à porter) et `final-exclure-sso.txt` (19 éléments django-allauth/SSO,
posés en `exclu` **par le juge** — décision d'Olivier du 12.10.2026, hors périmètre GED).

Objectif : 0 absent parmi les 36, exclus = 24 + 19 = 43, couverts = 737/780, hors exclusions = **100,00 %**,
strict ~94,5 %.

Méthode : oracle d'abord, par famille. Vecteurs `{entrée, sortie}` extraits en LECTURE SEULE de
`C:\ged\ref\paperless-ngx` (tag v2.20.5, commit `d1aa76e`) dans `tests/parite/vecteurs/final-*.json`,
rejoués par PHPUnit (`tests/php/Parite/Final*Test.php`), rouge puis code puis vert. Aucune ligne de code
Paperless (GPLv3) reprise. `src/Parite/Juge.php` non modifié (le juge y ajoutera la raison
`authentification-allauth-sso` et posera les 19 exclusions).

## Fait (commits)

- (en cours) **Famille verbes REST** :
  - Registres du fonds neufs : `Socle\Emetteurs` (correspondants, `.ged/emetteurs.json`, règle
    d'appariement via `Matching`) et `Socle\Emplacements` (chemins de classement = StoragePath,
    `.ged/emplacements.json`, placeholders validés, `..` refusé).
  - `Socle\ConfigurationApp` : configuration applicative **inscriptible** (`.ged/configuration.json`,
    surcharge titre/langue superposée au `.env`).
  - Verbes : `GET/POST/PUT/PATCH/DELETE /api/v1/emetteurs[/{id}]`, `…/emplacements[/{id}]`,
    `PUT/DELETE /api/v1/configuration`.
  - Retraits = **mise en veille** (jamais de suppression, règle 4) : `DELETE /api/v1/etiquettes|champs|
    vues|comptes|groupes/{id}` (`actif = 0` / `actif = 0` en base pour comptes et groupes, colonne ajoutée
    par migration idempotente). Un compte mis en veille ne peut plus se connecter
    (`Authentification::verifier`).
  - Vecteurs `tests/parite/vecteurs/final-verbes.json` (16) + oracle `FinalVerbesTest` (2 tests, vert).
  - `ApiQuatreAppConfigTest` adapté : l'écart assumé « PATCH configuration 409 » est **fermé** (200,
    écriture réelle puis `DELETE` = retour au `.env`) ; seul reste l'écart logo (200 vs 404).
  - Routes déclarées : `Securite\DroitsRoutes`, `Jonctions\Module`, statuts contrat `tools/banc/contrat.php`.

## Fait — évolution de modèle (2e étape)

- `documents.proprietaire_id` (migration idempotente) + filtres `owner` / `owner__id` / `owner__id__none`,
  exposé dans la fiche (`owner`, `proprietaire_id`) ; un document sans propriétaire reste valide.
- Hiérarchie d'étiquettes : `Etiquettes` gagne `parent` + `actif` + `definirParent`, filtre
  `TagFilterSet.is_root` sur `GET /api/v1/etiquettes` (racine = parente nulle ; hors registre = racine).
- `custom_field_query` : `Socle\RequeteChamps` (DSL JSON `[champ, opérateur, valeur]`, `AND/OR/NOT`,
  bornes profondeur ≤ 10 / atomes ≤ 20, refus 400 nommé) traduit en SQL paramétré sur `meta.champs`.
- `original_filename` : filtre sur `meta.nom_origine`, `documents.nom` et `ingestion_travaux.nom_origine`
  (jointure) ; exposé dans la fiche.
- `Document.archive_serial_number` (meta.asn), `page_count` (compté sur le fichier réel via
  `Ingestion\ParseurPiece`), `storage_type` (`unencrypted`) : exposés par la fiche.
- Preuves : vecteurs `final-modele.json` (12) + `FinalModeleTest` (3 tests, vert).

## En cours

- Chaîne `TikaDocumentParser` (couvert-autrement), commandes, `AcknowledgeTasksPermissions`, `ws-status`.

## Reste

- Commandes `decrypt_documents`, `prune_audit_logs`.
- `AcknowledgeTasksPermissions`.
- `AcknowledgeTasksPermissions`.
- `ws-status` (équivalent léger SSE).
- Écrans (`/application`, `/metadonnees`, fiche document) + tests d'accès.
- `correspondance.json` + rapport, harness complet, porte qualité ECC.

## Mesures exactes

| Mesure | Avant | Après (à date) |
| --- | ---: | ---: |
| Éléments couverts | 701 / 780 | 701 / 780 (correspondance pas encore corrigée) |
| Parité stricte | 89,87 % | 89,87 % (idem) |
| Absents | 55 | 55 (correspondance pas encore corrigée) |

Note : la `correspondance.json` sera corrigée en fin de lot, quand toutes les preuves seront vertes.

## Porte qualité

À compléter en fin de lot (PHPStan niveau 6, revue ECC, chiffres harness).
