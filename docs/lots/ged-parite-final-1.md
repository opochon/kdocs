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

## En cours

- Évolution de modèle : propriétaire de document, hiérarchie d'étiquettes (`is_root`), nom d'origine,
  requête sur champs personnalisés (`custom_field_query`).

## Reste

- Chaîne : `TikaDocumentParser` (à statuer couvert-autrement par `Ingestion\ParseurPiece`/`RegistreParseurs`).
- Commandes `decrypt_documents`, `prune_audit_logs`.
- Champs Document `archive_serial_number`, `page_count`, `storage_type`.
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
