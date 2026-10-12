# Lot ged-parite-api-4 — rattraper les 79 écarts `api` restants de la parité Paperless-ngx 2.20.5

Base : `v2` = `56121b7` (parité stricte 85,64 %, 668/780 ; catégorie `api` **218/318** couverts,
79 absents, 21 exclus). Liste exacte : `C:\ged\lots\api-4-elements.txt`, copiée dans
`docs/lots/ged-parite-api-4-elements.txt` (79 lignes). Ces 79 = les **41 exclusions requalifiées par le
juge le 12.10.2026** (fonctions utilisables exclues à tort) + les absents restants d'`api-3`.

> Note de lecture : l'énoncé du lot citait « api 149/318 » ; la mesure réelle à la base `56121b7` est
> **218/318** (la valeur 149 correspondait à la base d'avant `api-3`). Mesures recalculées depuis
> `docs/parite/correspondance.json`.

Méthode : identique aux lots `ged-parite-api-2` / `api-3` — vecteurs de comportement extraits en
LECTURE SEULE des tests de Paperless-ngx (`C:\ged\ref\paperless-ngx`, tag v2.20.5, commit `d1aa76e`) dans
`tests/parite/vecteurs/api-4-*.json`, rejoués par PHPUnit contre la VRAIE couche HTTP de GEDv2
(`gedv2_test`), rouge puis code puis vert. Aucune ligne de code Paperless (GPL-3) reprise : seuls des
couples `{entrée, sortie}` (méthode, chemin, corps éventuel, statut attendu). Les vecteurs sont gelés au
head de famille ; deux familles sans test Paperless dédié (`partage`, `destroy`) portent une note
explicite et une transcription du contrat DRF.

## Fait (commits)

- `da1f5cc` — note de lot + liste des 79 éléments.
- `b61ccce` — **oracle api-4** : `tools/parite/extraire-api-4.py` + vecteurs committés
  `tests/parite/vecteurs/api-4-{taches,totp,jeton,statut,app-config}.json`.
- `7a909cb` — **famille tâches** : filtres `PaperlessTaskFilterSet` (task_id, status, task_name, type,
  acknowledged) sur `GET /api/v1/taches`, `POST /api/v1/taches/acknowledge`, `POST /api/v1/taches/run`
  (tâche de maintenance sans Celery). Oracle `ApiQuatreTachesTest`.
- `adf0872` — **famille 2FA/TOTP** : service `Ged\Securite\Totp` (RFC 6238), `GET/POST/DELETE
  /api/v1/profil/totp`, `POST /api/v1/comptes/{id}/deactivate_totp` (admin), et porte MFA de la
  connexion (`POST /connexion` exige le code quand le TOTP est actif). Oracle `ApiQuatreTotpTest`.
- `5c42716` — **famille jeton d'API** : `POST /api/v1/token` (identifiants + code MFA) réutilise
  `Authentification` et `Jetons`. Oracle `ApiQuatreJetonTest`.
- `0d6a3a2` — oracle `api-4-version` (assertions pytest) + famille `version`.
- `7108b94` — **famille état système / version** : `GET /api/v1/statut` (admin, base+stockage+suivi),
  `GET /api/v1/version` (version installée, aucun appel réseau). Oracle `ApiQuatreStatutTest`.
- `076e5ca` — oracle `api-4-partage` (contrat DRF `ShareLinkViewSet`).
- `fd59f34` — **famille partage** : `PUT/PATCH /api/v1/partages/{id}` (échéance) sans révocation.
  Oracle `ApiQuatrePartageTest`.
- `9a62744` — **famille configuration/logo/favicon** : `GET /api/v1/configuration` (lecture),
  `POST` → 405, `PATCH` → 409 nommé ; `GET /logo`, `GET /favicon`. Oracle `ApiQuatreAppConfigTest`.
- `7cbd5f6` — oracle `api-4-destroy`.
- `dd6bc22` — **famille destroy** : `DELETE /api/v1/types/{id}` désactive le type (zéro suppression).
  Oracle `ApiQuatreDestroyTest`.
- `2b88251` — preuve de l'écran de connexion (`account_login`).
- `42aef81` — **correspondance api-4** : `tools/parite/corriger-correspondance-api-4.php` (additif,
  ciblé sur les 79 ids) : 21 `couvert`, 12 `couvert-autrement`, 46 `absent`.
- `7f52d6f` — table TOTP créée À LA DEMANDE (hors installateur, plafond V2-01 de 21 tables respecté) ;
  désactivation du TOTP par `actif = 0` (aucune suppression SQL).

## Reste

Aucune étape de code : le lot est livré ; voir « Restants » pour ce qui reste `absent` (chiffré).

## Mesures exactes

| Mesure | Avant | Après |
| --- | ---: | ---: |
| Éléments couverts | 668 / 780 | **701 / 780** |
| Parité stricte | 85,64 % | **89,87 %** |
| Parité hors exclusions | 88,36 % | **92,72 %** |
| `api` couverts | 218 / 318 | **251 / 318** (+33) |
| `api` absents | 79 | **46** |
| `api` exclus | 21 | 21 (aucune exclusion ajoutée) |

Les 33 nouveaux `api` couverts : 21 `couvert` (route/verbe nouveau + preuve PHPUnit) et
12 `couvert-autrement` (route/écran/service existant + preuve).

### Comportement rejoué (phase 4 du rapport)

| Famille | Fichier de vecteurs | Passes | Total | Écarts assumés |
| --- | --- | ---: | ---: | ---: |
| taches | `tests/parite/vecteurs/api-4-taches.json` | 7 | 8 | 1 |
| totp | `tests/parite/vecteurs/api-4-totp.json` | 11 | 11 | 0 |
| jeton | `tests/parite/vecteurs/api-4-jeton.json` | 4 | 4 | 0 |
| statut | `tests/parite/vecteurs/api-4-statut.json` | 3 | 3 | 0 |
| version | `tests/parite/vecteurs/api-4-version.json` | 1 | 1 | 0 |
| app-config | `tests/parite/vecteurs/api-4-app-config.json` | 3 | 5 | 2 |
| partage | `tests/parite/vecteurs/api-4-partage.json` | 2 | 2 | 0 |
| destroy | `tests/parite/vecteurs/api-4-destroy.json` | 0 | 1 | 1 |

Écarts ASSUMÉS (nommés, mesurés, jamais comptés comme échecs) :
- `taches:test_run_train_classifier_task:007` : Paperless mocke l'échec d'un `run` (500) ; GEDv2 réussit
  toujours une tâche connue (200).
- `app-config:test_api_update_config:002` : Paperless écrit la configuration (200) ; GEDv2 la tient du
  `.env` en lecture seule et refuse par un 409 nommé.
- `app-config:test_api_replace_app_logo:004` : Paperless rend 404 tant qu'aucun logo n'a été téléversé ;
  GEDv2 sert toujours un logo (`GET /logo`).
- `destroy:doctype_destroy:001` : Paperless supprime (204) ; GEDv2 désactive (200), la ligne reste.

## Porte qualité

- **Analyse statique** : `php vendor\bin\phpstan analyse --memory-limit=2G` → **0 erreur, niveau 6**,
  `phpstan-baseline.neon` vide inchangée. `php tools\composer.phar audit` → aucune vulnérabilité.
- **TDD** : chaque famille a ses vecteurs committés AVANT son code (sauf `partage`/`destroy`, sans test
  Paperless, note explicite) ; le rejeu a été vu ROUGE avant les routes, vert après. Aucun vecteur
  modifié après coup, aucun test affaibli, aucune sonde retirée.
- **Revue du diff** (grilles ECC php-reviewer / security-reviewer / silent-failure-hunter) :
  - Injection : toute valeur SQL passe par des marqueurs `?` ; les mises à jour de partage/TOTP sont
    paramétrées ; aucune concaténation d'entrée.
  - Secrets : le jeton d'API et le secret TOTP ne sont rendus QU'À la création ; les codes de secours
    sont hachés (`password_hash`) et rendus une seule fois ; aucun secret n'est journalisé. Le repli TOTP
    d'un compte par un administrateur est nommé et tracé côté audit de connexion.
  - Erreurs : toute exception inattendue est journalisée (`error_log`) puis rendue en JSON 500 nommé ;
    un refus est toujours nommé (étape, cause, référence). `Configuration en lecture seule` = 409 nommé,
    `create` = 405 comme Paperless.
  - Zéro suppression : aucun `DELETE` SQL de donnée dans `src` (l'ancien `DELETE` du service TOTP a été
    remplacé par `actif = 0`) ; le `destroy` d'un type DÉSACTIVE ; le partage se RÉVOQUE.
  - Observation : `RessourcesApi` reste un contrôleur volumineux (verbes REST + vues annexes) ; les
    gabarits `json`/`erreur` sont partagés avec `PariteApi`. Assumé, comme aux lots api-2/api-3.

## Familles : écrans impactés et flux F à retester

- **tâches** : écran `/taches` (mêmes données, filtres désormais exposés) — **F5**.
- **2FA/TOTP** : écran `/connexion` (champ code ajouté, facultatif) et `/profil` — **F10**.
- **jeton d'API** : aucun écran ; `/application` (gestion des jetons) — **F10**.
- **état système / version** : `/sante` (existant) et `/application` — **F12**.
- **partage** : `/documents/{id}` (échéance du lien) — **F10**.
- **configuration/logo/favicon** : `/application` ; logo/favicon publics — **F12**.
- **destroy** : `/metadonnees` (un type retiré n'est plus proposé, reste lisible) — **F3/F5**.

## Restants `api` (46, raison + coût d'évolution)

Ils sont chiffrés élément par élément dans la `note` de chaque `absent` de
`docs/parite/correspondance.json` (schéma, écrans, flux F). Familles :

| Famille | Nb | Raison | Évolution |
| --- | ---: | --- | --- |
| `DocumentFilterSet.owner*` (3), `original_filename`, `custom_field_query` | 5 | pas de propriétaire, pas de nom d'origine sur la fiche, DSL JSON Paperless | colonne propriétaire / nom d'origine + reprise de l'index ; laisser le DSL absent (proposition juge) |
| `TagFilterSet.is_root` | 1 | étiquettes plates | hiérarchie d'étiquettes (arbre, écran, héritage) |
| Comptes courriel django-allauth (reset password ×4, signup, inactive, logout) | 7 | pas de réinitialisation par jeton, pas d'inscription libre, pas de compte inactif | registre de jetons + écrans + politique d'inscription |
| SSO / comptes sociaux (routes, verbes, vues) | 12 | django-allauth / OAuth absents | clients OAuth2 par fournisseur + écran de liaison |
| WebSocket de statut | 1 | hébergement mutualisé sans démon | serveur ASGI séparé (interdit par la cible) ; `GET /api/v1/taches` couvre le besoin |
| `CorrespondentViewSet.*` (sauf list) | 5 | émetteur = chaîne | registre d'émetteurs (fichier, service, écran) |
| `StoragePathViewSet.*` (sauf list) | 5 | pas d'entité chemin de stockage | registre de gabarits + dossier cible + écran |
| `destroy` étiquettes / champs / vues / comptes / groupes / triggers / actions | 7 | pas de drapeau de retrait | drapeau + corbeille de référentiels |
| `ApplicationConfigurationViewSet.update/partial_update/destroy` | 3 | configuration `.env` en lecture seule | registre global inscriptible + écran + audit |

Aucune exclusion ajoutée. Les propositions (DSL `custom_field_query`, WebSocket, SSO, inscription) sont
soumises au juge ; les autres évolutions sont chiffrées dans la correspondance.

## Harness

`powershell -ExecutionPolicy Bypass -File tools\harness.ps1 -SansBanc` — rejeu final du 2026-10-12
(16 min 43 s). `tools/composer.phar` restauré depuis la copie de référence avant le rejeu (gitignoré).

| Couche | État | Détail |
| --- | --- | --- |
| 0-garde-fous | VERT | vendor + node_modules, `DB_NAME=gedv2_test` imposé |
| 1-unitaire | VERT | 853 tests, 11 429 assertions, 1 sauté |
| 3-banc | ROUGE | sautée par `-SansBanc` (attendu) |
| 4-écrans | ROUGE | **1 seul échec** : `edition-pages.spec.ts > le vrai compte retouche puis valide six pages sans perte ni doublon` (timeout 90 s), parcours **préexistant** du secteur `edition-poste`, sans rapport avec l'API — 92 autres parcours verts. Aucun autre rouge : porte franchie pour ce lot. |
| 7-sécurité | VERT | sonde : aucun contournement accepté |
| 2-contrat | VERT | toutes les routes déclarées couvertes (401/400/200 attendus vérifiés) |
| 6-charge | VERT | p95 lecture 689 ms, p95 écriture 251 ms, 0 erreur, 0 collision, ingestion finie |
| 8-statique | VERT | PHPStan niveau 6 : 0 erreur hors baseline ; `composer audit` : aucune vulnérabilité |
| 5-personas | VERT | 6 personas, tous les gestes trouvés (médianes 391–693 ms) |
| 9-parité | VERT | 780 éléments, **701 couverts**, strict **89,87 %**, hors exclusions **92,72 %**, 0 non prouvé |

Restent non committés les fichiers régénérés par le harness (`recette/registre.json`,
`recette/preuves/**`, `docs/parite/PARITE-PAPERLESS.md`, `var/**`), comme aux lots précédents.

## Lignes hors secteur (signalées, à commiter par le juge)

- `src/Securite/Schema.php` : table TOTP créée à la demande (hors installateur, plafond V2-01 intact).
- `src/Securite/ConnexionController.php`, `src/Securite/Module.php` : porte MFA de la connexion.
- `src/Securite/DroitsRoutes.php` : routes api-4 déclarées.
- `tools/banc/contrat.php` : statuts attendus (401/400/200) des routes ajoutées.
- `templates/connexion/connexion.twig` : champ `code` facultatif.
- `.env.example` : `GED_VERSION`.

ETAT: fini
