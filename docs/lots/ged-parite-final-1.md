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

## Fait — chaîne, commandes, droits, statut (3e et 4e étapes)

- `TikaDocumentParser` (3 éléments) → **couvert-autrement** par `Ingestion\ParseurPiece` (métadonnées,
  vignette déléguée à `Apercu`, texte UTF-8 réparé ; bureautique = LibreOffice → PDF avant OCR) : preuves
  `ChaineParseursTest`, aucune dépendance lourde ajoutée.
- `commande:documents:decrypt_documents` → `Ingestion\DechiffreurPdf` + outil `tools/decrypter-documents.php`
  (outil externe `PDF_DECHIFFREUR`, refus **nommé** s'il est absent, jamais d'échec muet ; liste seule par
  défaut, `--appliquer` pour écrire).
- `commande:documents:prune_audit_logs` → `Securite\PurgeAudit` + outil `tools/purger-journal-audit.php`
  (rétention en jours, ligne illisible **conservée**, écriture atomique ; aucune donnée utilisateur).
- `permissions:classe:AcknowledgeTasksPermissions` → `Securite\PermissionsTaches` (administrateur ou
  permission fine `taches:acquitter`), rattaché à la file `ingestion_travaux` (`Socle\Taches`), pas de Celery.
- `api:route:anon:ws-status-…` → **équivalent fonctionnel léger** : `GET /api/v1/taches/statut/evenements`
  (flux SSE d'un événement `statut` — état réel des tâches — puis fermeture ; pas de WebSocket/Redis en
  mutualisé). `couvert-autrement` assumé.
- Preuve : `tests/php/Parite/FinalCommandesTest.php` (3 tests, vert : outil absent puis déchiffrement réel
  avec un faux outil externe, purge, 403 non-admin, SSE admin, 401 sans jeton).

## Fait — écrans (5e étape)

- `/application` : section **Référentiels** (émetteurs = Correspondent, emplacements = StoragePath) avec
  création, mise en veille/réactivation et liste ; **identité modifiable** (titre, langue) avec retour au
  `.env`. Routes `POST /application/emetteur|emplacement|configuration` (droit `application:administrer`).
- `/metadonnees` : colonne **Parente** + rattachement, mise en veille/réactivation des étiquettes et des
  champs (jamais de suppression).
- Fiche document : **propriétaire**, **nom d'origine**, **pages**, **n° d'archive**, **type de stockage**
  (repli nommé « — » quand l'information manque, y compris sur la maquette).
- Tests : `EcransTest` (présence des data-testid + rendu) et E2E `configuration.spec.ts` (un administrateur
  crée un émetteur, le voit, le met en veille, modifie l'identité puis revient au `.env`).

## Reste

- Aucun reste fonctionnel côté lot. **Le juge** doit poser les 19 exclusions SSO et étendre
  `Juge::EXCLUSIONS_AUTORISEES` avec `authentification-allauth-sso` (décision d'Olivier, 12.10.2026) : le lot
  ne s'exclut jamais lui-même.

Note : les **19 exclusions SSO** restent `absent` dans `correspondance.json` — c'est **le juge** qui les pose
en `exclu` (`authentification-allauth-sso`), conformément à la décision d'Olivier du 12.10.2026. Au niveau du
lot, le hors-exclusions plafonne donc à `737 / 756 = 97,49 %` ; après l'ajout des 19 exclusions par le juge,
`couverts / (780 − 43)` = **100,00 %** hors exclusions.

## Mesures exactes (harness complet du 12.10.2026, arbre commité)

| Couche | Verdict | Chiffres |
| --- | --- | --- |
| 0-garde-fous | VERT | vendor + node_modules présents, `DB_NAME=gedv2_test` imposé |
| 1-unitaire | VERT | **863 tests, 11 538 assertions, 1 sauté** |
| 2-contrat | VERT | toutes les routes déclarées couvertes, contrats de service OK |
| 3-banc | ROUGE | sautée par `-SansBanc` (attendu) |
| 4-ecrans | VERT | **95 passed (5,3 min)** |
| 5-personas | VERT | administrateur 4/4 (médiane 700 ms), auditeur 4/4, comptable 4/4, débutant 4/4, fiduciaire 4/4, secrétaire 3/3 |
| 6-charge | VERT | p95 lecture **673 ms**, p95 écriture **242 ms**, 0 erreur, 0 collision, ingestion finie |
| 7-securite | VERT | sonde offensive : aucun contournement accepté |
| 8-statique | VERT | PHPStan niveau 6 : **0 erreur** hors baseline ; `composer audit` : aucune vulnérabilité |
| 9-parite | VERT | 780 éléments · couverts **737** · strict **94,49 %** · hors exclusions **97,49 %** · 43 écarts (19 absents + 24 exclus) |

| Mesure de parité | Avant (`6090fe9`) | Après |
| --- | ---: | ---: |
| Éléments couverts | 701 / 780 | **737 / 780** |
| Parité stricte | 89,87 % | **94,49 %** |
| Hors exclusions (24 exclus) | 92,72 % | **97,49 %** (737/756) |
| Absents | 55 | **19** (les SSO, pour le juge) |
| Exclus | 24 | 24 (le juge portera 24 + 19 = **43**) |

## Familles portées : écrans impactés et flux F à retester

| Famille | Ce qui a changé | Écran(s) | Flux F |
| --- | --- | --- | --- |
| (a) Correspondants (5 verbes) | registre d'émetteurs + CRUD | `/application` → Référentiels | F1, F3, F5 |
| (a) Chemins de stockage (5 verbes) | registre d'emplacements + CRUD | `/application` → Référentiels | F1, F3 |
| (a) Configuration (update/partial/destroy) | identité inscriptible (titre, langue) | `/application` → Référentiels | F12 |
| (a) Retraits Tag/Champ/Vue/Compte/Groupe | mise en veille (jamais suppression) | `/metadonnees`, `/vues`, `/administration/comptes` | F5, F10 |
| (a) Retraits WorkflowAction/Trigger | mise en veille de la définition | `/workflows/admin` | F6, F8 |
| (b) Document.owner | colonne + filtres `owner*` + fiche | fiche document, Bibliothèque/Recherche | F1, F10 |
| (b) Hiérarchie d'étiquettes | `parent` + `is_root` | `/metadonnees` → Étiquettes | F3, F5 |
| (b) `custom_field_query` | DSL JSON → SQL paramétré | Bibliothèque / Recherche (filtres) | F4, F5 |
| (b) `original_filename` | filtre + fiche (nom d'origine) | fiche document, Bibliothèque | F1, F5 |
| (b) `archive_serial_number` / `page_count` / `storage_type` | fiche document | fiche document, `/asn/{id}` | F1, F5 |
| (c) `TikaDocumentParser` | couvert-autrement par `ParseurPiece` | « À traiter », dépôt | F1, F2 |
| (d) `decrypt_documents` | outil CLI + `DechiffreurPdf` | (hors écran) | F1 |
| (d) `prune_audit_logs` | outil CLI + `PurgeAudit` | `/journaux` (rétention) | F12 |
| (e) `AcknowledgeTasksPermissions` | `PermissionsTaches` sur la file `ingestion_travaux` | `/taches` | F4, F8 |
| (f) `ws-status` | flux SSE léger | `/taches` (statut) | F4, F8 |

## Porte qualité (ECC, `docs/qualite/README.md`)

1. **Analyse statique** — `php vendor\bin\phpstan analyse` niveau 6 : **0 erreur** hors baseline (couche 8
   VERTE) ; `composer audit --locked` : **aucune vulnérabilité connue**. Baseline inchangée (vide), aucun
   `@phpstan-ignore` ajouté dans `src` ni `tests`.
2. **Revue du diff** contre les grilles `php-reviewer`, `security-reviewer`, `silent-failure-hunter` :
   - `php-reviewer` : `declare(strict_types=1)` sur tous les fichiers neufs, retours typés, fonctions courtes
     (outils CLI et services séparés des vues).
   - `security-reviewer` : **SQL 100 % paramétré** — filtre `owner` (`d.proprietaire_id = ?`), `nom_origine`
     (`LIKE ?` avec `echapperLike`), `asn` (`JSON_EXTRACT(d.meta, ?) = ?`), `custom_field_query` (marqueurs `?`,
     nom de champ validé `/^[A-Za-z0-9_]+$/`, opérateurs en liste blanche, bornes profondeur ≤ 10 / atomes ≤ 20) ;
     aucune concaténation d'entrée dans le SQL. CSRF vérifié par `GardeCentrale` : la sonde offensive couvre les
     3 nouvelles routes `POST /application/*` (CSRF absent refusé, rejeu refusé). Déchiffrement via `proc_open`
     en argv (jamais de shell). Aucun secret en clair.
   - `silent-failure-hunter` : **constat ÉLEVÉ corrigé** — `Ui\Document::champsPaperless` avalait trois
     `catch (Throwable)` ; ils **tracent** désormais (`error_log`) et retombent sur « — ». Les autres `catch`
     du lot nomment la panne (`ErreurApi`, `nommer`, `erreur`) ou rendent une raison nommée
     (`DechiffreurPdf`), jamais un 500 muet ni une erreur avalée.
3. **TDD** — chaque famille a ses vecteurs figés (`final-verbes.json`, `final-modele.json`) et son oracle
   `Final*Test` rouge avant le code, vert après ; les vecteurs des lots précédents et `src/Parite/Juge.php`
   sont intacts ; `ApiQuatreAppConfigTest` a été **resserré** (écart « PATCH configuration » fermé), jamais
   affaibli.

## Résultat

- Parité hors exclusions au niveau du lot : **97,49 %** (737/756) ; après les 19 exclusions SSO posées par le
  juge : **100,00 %** (737/737), exclus = **43** (24 + 19), couverts = **737/780**, strict **94,49 %**.
- Aucun élément du lot `final-porter.txt` n'est resté absent : **0 absent** parmi les 36 (32 `couvert`,
  4 `couvert-autrement`).

ETAT: fini
