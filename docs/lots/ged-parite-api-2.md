# Lot ged-parite-api-2 — rattraper les écarts `api` de la parité Paperless-ngx 2.20.5

Base : `v2` = `f1838f4` (parité stricte 70,00 %, 546/780 ; catégorie `api` 96/318, 179 absents).
Cible : les 77 éléments de `C:\ged\lots\api-2-elements.txt` (copiés dans
`docs/lots/ged-parite-api-2-elements.txt`) : 32 filtres, 18 routes, 21 vues, 6 actions. Les 102 verbes REST
des ressources relèvent du lot suivant (api-3) et ne sont pas traités ici.

Méthode oracle d'abord, par famille : vecteurs extraits en LECTURE SEULE des tests de Paperless
(`C:\ged\ref\paperless-ngx`, tag v2.20.5, commit `d1aa76e`) → rejeu PHPUnit contre la VRAIE couche de
requêtes de GEDv2 (`gedv2_test`) → rouge → implémentation → vert → commit. Aucune ligne de code Paperless
(GPL-3) reprise : uniquement des couples `{entree, sortie}`.

## Fait (commits)

- `480eba7` — **oracle api-2** : `tools/parite/extraire-api-2.py` (lecture seule de
  `test_api_documents.py::test_document_filters`, `test_api_search.py::test_search_filtering`,
  `test_api_custom_fields.py::test_custom_field_filters`) + vecteurs commités
  `tests/parite/vecteurs/api-2-filtres-documents.json` (15 rejouables, 24 `non_rejouables` Django/DRF)
  et `api-2-filtres-ressources.json` (3 rejouables). Liste exacte des 77 éléments copiée.
- `adc18d5` — **famille 1 « filtres-documents »** : `Catalogue` câble les filtres de champs personnalisés
  (`champs_contient`, `champs_presents`, `champs_tous`, `champs_any`, `sans_champs`), valeurs lues dans
  `meta.champs` (le dossier fait foi), clés passées par `JSON_CONTAINS(JSON_KEYS(JSON_EXTRACT(…)), ?)`.
  Rejeu `ApiDeuxFiltresDocumentsTest` : 15 vecteurs + bornes des champs, vu ROUGE avant le code.
- `82925a8` + `8f21aed` (extracteur) — **famille 2 « filtres des autres ressources »** :
  `GET /api/v1/champs` (définitions, filtres `id`/`ids`/`nom`/`nom_contient`) et
  `GET /api/v1/partages` (liens de partage, filtres `cree_min`/`cree_max`/`expire_min`/`expire_max`,
  bornés aux documents lisibles). `ApiJeton` extrait de `DocumentsApi` (porte unique 401/403/429).
- `563b947` — **famille 3 « vues d'information et de recherche »** : `GET /api/v1/statistiques`,
  `GET /api/v1/next_asn`, `GET /api/v1/autocomplete`, `POST /api/v1/documents/selection_data`,
  `GET /api/v1/documents/{id}/metadonnees`, tous bornés par le prédicat de lecture des droits.
- `6c00cf0` — **famille 4 « actions »** : `POST /api/v1/documents/{id}/email`,
  `POST /api/v1/documents/email`, `POST /api/v1/documents/bulk_edit`, `POST /api/v1/documents/bulk_download`,
  `POST /api/v1/dossiers/tester`. Les envois passent par `Mailer` (transport « fichier » en test, aucun
  envoi réel) ; la masse réutilise `Socle\ModificationMasse`.
- `97fd9b5` — **correspondance api-2** : `tools/parite/corriger-correspondance-api-2.php` (additif, ciblé
  sur les 77 ids) : 20 `couvert`, 33 `couvert-autrement`, 18 `exclu`, 6 `absent` restants.
- `61a111c` — **permissions paramétrées** : `DroitsRoutes::permissionPour` résout les chemins concrets
  `/api/v1/documents/{id}/metadonnees` et `/api/v1/documents/{id}/email` (sans quoi la garde répondait 403
  non JSON en HTTP réel ; constat de la couche 2-contrat).

## Mesures exactes

### Parité (`recette/parite-paperless.json`, régénéré par le juge)

| Mesure | Avant | Après |
| --- | ---: | ---: |
| Éléments couverts | 546 / 780 | **599 / 780** |
| Parité stricte | 70,00 % | **76,79 %** |
| Parité hors exclusions | — | **83,78 %** |
| `api` couverts | 96 / 318 | **149 / 318** (53 nouveaux, 0 non prouvé) |
| `api` absents | 179 | **108** (102 verbes → api-3, 6 filtres de modèle) |
| `api` exclus | — | 61 (18 ajoutés par ce lot) |

### Comportement rejoué (phase 4 du rapport)

| Famille | Fichier de vecteurs | Passes | Total | Non rejouables | Taux |
| --- | --- | ---: | ---: | ---: | ---: |
| 2-filtres-documents | `tests/parite/vecteurs/api-2-filtres-documents.json` | 15 | 15 | 24 | 100,00 % |
| 2-filtres-ressources | `tests/parite/vecteurs/api-2-filtres-ressources.json` | 3 | 3 | 0 | 100,00 % |

Non rejouables (raison + référence) : nom d'origine, propriétaire, identifiants d'étiquettes, chemin de
stockage comme entité, filtres `custom_fields__id__*` par identifiant Paperless (GEDv2 adresse un champ par
sa clé), bornes datées Paperless (`created__date__lt`…), tokenisation Whoosh sur la recherche combinée
(`test_search_filtering`) — `src/documents/filters.py`.

### PHPUnit

834 tests, 11 214 assertions, 1 sauté (préexistant), 0 échec. Nouveaux : `ApiDeuxFiltresDocumentsTest` (2),
`ApiDeuxFiltresRessourcesTest` (2), `ApiDeuxVuesTest` (1), `ApiDeuxActionsTest` (1).

## Vision des 77 éléments

- **20 couverts** par une route nouvelle du lot (citée + preuve PHPUnit).
- **33 couverts autrement** : la fonction existe déjà (mêmes colonnes, écrans ou services), prouvée par un
  test qui passe (ex. `sans_emetteur`/`sans_type`/`sans_dossier`, `/journaux`, `/reglages`, `/corbeille`,
  `/administration/comptes`, `/application`, partages `Socle\Collaboration`, recherche `GET /api/v1/documents?q=`).
- **18 exclus** (raison fermée du juge, référence Paperless) :
  - `infrastructure-celery-redis` (4) : `PaperlessTaskFilterSet.acknowledged`/`status`/`task_name`/`type`
    (`src/documents/filters.py:800-807`, `views.py:2532`) ;
  - `infrastructure-django` (14) : django-allauth (déconnexion et fournisseurs sociaux, signup/login
    annulé/erreur), MFA TOTP (`TOTPView`, `deactivate_totp`), statut système Django (`SystemStatusView`),
    OAuth courrier (`OauthCallbackView`) — `src/paperless/views.py:97-412`, `src/paperless_mail/views.py:181`.
- **6 absents** (modèle absent, raison + référence) : `DocumentFilterSet.custom_field_query` (DSL JSON),
  `.original_filename` (vit dans `ingestion_travaux.nom_origine`), `.owner`, `.owner__id`,
  `.owner__id__none` (pas de propriétaire de document), `TagFilterSet.is_root` (pas de hiérarchie
  d'étiquettes).

## Restants `api` (108, raison + référence)

| Famille | Nb | Raison | Lot |
| --- | ---: | --- | --- |
| Verbes REST des ressources (`api:verbe:*`) | 102 | CRUD/`retrieve`/`list` des ViewSets (étiquettes, émetteurs, types, chemins de stockage, vues enregistrées, champs, partages, workflows, utilisateurs, groupes, config, tâches) : hors périmètre de ce lot, réservés à `api-3`. | api-3 |
| Filtres de modèle absent (les 6 ci-dessus) | 6 | Modèle GEDv2 absent (propriétaire, nom d'origine, hiérarchie d'étiquettes, DSL de requête). | à trancher |

## Écrans impactés et flux F à retester

- Aucune route écran n'est modifiée : les ajouts sont sous `/api/v1` (jeton, hors session). Les écrans
  touchés sont ceux des preuves « couvert autrement » : `/journaux`, `/reglages`, `/corbeille`,
  `/administration/comptes`, `/administration/groupes`, `/application`, `/tableau-de-bord`, la recherche et
  la modification en masse. La couche 4 les rejoue (94 parcours verts, 5,2 min).
- Flux F à rejouer au jugement : **F2** (dépôt écran/mobile), **F4** (validation à la main), **F5**
  (retrouver un document : recherche unifiée, statistiques, autocomplétion), **F10** (droits : partages,
  statistiques, sélection, téléchargement, envoi de courriel), **F12** (reconstruction : le juge régénère
  la correspondance à l'identique). Le flux de lecture Recherche/Bibliothèque n'est pas modifié (les filtres
  et vues sont opt-in).

## Lignes hors secteur (signalées, à commiter par le juge)

- `src/Securite/DroitsRoutes.php` : permissions des 17 routes ajoutées + résolution des chemins paramétrés.
- `tools/banc/contrat.php` : statuts attendus (401) des routes ajoutées.
- `src/Jonctions/Mailer.php` : méthode générique `envoyerAvecPieces` (secteur API/Jonctions).
- `src/Jonctions/DocumentsApi.php` : délègue l'authentification à `ApiJeton` (même comportement, tests verts).

## Porte qualité

- **Analyse statique** : `php vendor\bin\phpstan analyse --memory-limit=2G` → **0 erreur, niveau 6**,
  `phpstan-baseline.neon` inchangée. `php tools\composer.phar audit` → « No security vulnerability
  advisories found ».
- **TDD** : chaque famille a son oracle commité, vu ROUGE avant le code (vecteurs d'API et routes mises de
  côté par `git stash`), vert après. Aucun vecteur modifié après coup, aucun test affaibli.
- **Revue du diff** (grilles ECC php-reviewer / security-reviewer / silent-failure-hunter) : aucun constat
  CRITIQUE ou ÉLEVÉ.
  - Injection : toutes les valeurs passent par des marqueurs `?` ; `JSON_KEYS/JSON_CONTAINS` reçoivent la
    clé en paramètre ; les seules concaténations SQL sont des constantes et des marqueurs générés ; les
    motifs LIKE sont échappés (`%, _`) ; `LIMIT` vient d'un entier borné.
  - Erreurs : toute exception inattendue est journalisée (`error_log`) puis rendue en JSON 500 nommé ;
    aucun `catch (Throwable)` qui avale sans tracer. Un refus est toujours nommé (étape, cause, référence).
  - Droits : chaque route exige un jeton lié à un compte et une portée ; les listes (statistiques,
    autocomplétion, sélection, partages, téléchargement, courriel) intègrent le prédicat de lecture DANS la
    requête ; l'édition en masse réutilise les contrôles par dossier de `ModificationMasse`.
  - Observation : `PariteApi` duplique les petits gabarits `json`/`erreur`/`echec` de `DocumentsApi` (trois
    méthodes courtes) ; la porte d'authentification, elle, est partagée (`ApiJeton`).

## Harness (`powershell -ExecutionPolicy Bypass -File tools\harness.ps1 -SansBanc`)

Rejeu final du 2026-10-12 (16 m 07 s). `tools/composer.phar` (outil gitignoré, absent de ce worktree)
restauré depuis la copie de référence avant le rejeu : sans lui la couche 8 est ROUGE à tort.

| Couche | État | Détail |
| --- | --- | --- |
| 0-garde-fous | VERT | vendor + node_modules, `DB_NAME=gedv2_test` imposé |
| 1-unitaire | VERT | 834 tests, 11 214 assertions, 1 sauté |
| 3-banc | ROUGE | sautée par `-SansBanc` (attendu) |
| 4-écrans | VERT | 94 passés (5,2 m) |
| 7-sécurité | VERT | aucun contournement accepté |
| 2-contrat | VERT | toutes les routes déclarées couvertes |
| 6-charge | VERT | p95 lecture 681 ms, p95 écriture 244 ms, 0 erreur, 0 collision, ingestion finie |
| 8-statique | VERT | PHPStan niveau 6 : 0 erreur ; `composer audit` : aucune vulnérabilité |
| 5-personas | VERT | 6 personas, tous les gestes trouvés (médianes 366–696 ms) |
| 9-parité | VERT | 780 éléments, 599 couverts, strict 76,79 %, hors exclusions 83,78 %, 0 non prouvé |

La couche 3 étant sautée par `-SansBanc`, aucun banc n'a été rejoué. Seuls restent non commités les
fichiers régénérés par le harness (`recette/registre.json`, `recette/preuves/**`,
`recette/parite-paperless.json`, `var/**`).

ETAT: fini
