# Lot ged-parite-api-3 — rattraper les 108 écarts `api` restants de la parité Paperless-ngx 2.20.5

Base : `v2` = `0e3f618` (parité stricte 76,79 %, 599/780 ; catégorie `api` 149/318 couverts, 108 absents,
61 exclus). Liste exacte : `C:\ged\lots\api-3-elements.txt`, copiée dans
`docs/lots/ged-parite-api-3-elements.txt` (108 lignes).

Méthode : identique au lot `ged-parite-api-2` — vecteurs de comportement extraits en LECTURE SEULE des
tests de Paperless-ngx (`C:\ged\ref\paperless-ngx`, tag v2.20.5, commit `d1aa76e`) dans
`tests/parite/vecteurs/api-3-*.json` (6 familles), rejoués par PHPUnit contre la VRAIE couche HTTP de
GEDv2 (`gedv2_test`), rouge puis code puis vert. Aucune ligne de code Paperless (GPL-3) reprise : seuls
des couples `{entrée, sortie}` (méthode, chemin, corps éventuel, statut attendu).

## Fait (commits)

- `48e5aac` — liste des 108 éléments + note de lot.
- `ffa2f5b` — **oracle api-3** : `tools/parite/extraire-api-3.py` (lecture seule de
  `test_api_custom_fields.py`, `test_api_documents.py`, `test_api_objects.py`,
  `test_api_permissions.py`, `test_api_profile.py`, `test_api_uisettings.py`, `test_api_status.py`,
  `test_api_remote_version.py`, `test_api_bulk_download.py`, `test_api_tasks.py`, `test_api_trash.py`) et
  vecteurs commités `tests/parite/vecteurs/api-3-{ressources-classement,administration,securite,masse,
  recherche,partage}.json`. Vecteurs gelés au head de famille.
- `d6b2701` — **familles 1 à 6** : `src/Jonctions/RessourcesApi.php` (verbes REST des ressources, même
  porte jeton que `DocumentsApi`/`PariteApi`), routes dans `src/Jonctions/Module.php`, permissions dans
  `src/Securite/DroitsRoutes.php` (+ `tools/banc/contrat.php`), et oracles PHPUnit
  `tests/php/Parite/ApiTrois{Administration,Masse,Partage,Recherche,RessourcesClassement,Securite}Test.php`.
- `aafb516` — **correspondance api-3** : `tools/parite/corriger-correspondance-api-3.php` (additif,
  ciblé sur les 108 ids) : 42 `couvert`, 27 `couvert-autrement`, 39 `absent`.

## Mesures exactes

### Parité (`recette/parite-paperless.json`, régénéré par le juge)

| Mesure | Avant | Après |
| --- | ---: | ---: |
| Éléments couverts | 599 / 780 | **668 / 780** |
| Parité stricte | 76,79 % | **85,64 %** |
| Parité hors exclusions | 83,78 % | **93,43 %** |
| `api` couverts | 149 / 318 | **218 / 318** (+69, 0 non prouvé du lot) |
| `api` absents | 108 | **39** |
| `api` exclus | 61 | 61 (aucune exclusion ajoutée) |

Les 69 nouveaux `api` couverts se répartissent ainsi : 42 `couvert` (route nouvelle + preuve PHPUnit) et
27 `couvert-autrement` (route/service/écran existant + preuve PHPUnit ou parcours Playwright).

### Comportement rejoué (phase 4 du rapport)

| Famille | Fichier de vecteurs | Passes | Total | Non rejouables |
| --- | --- | ---: | ---: | ---: |
| ressources-classement | `tests/parite/vecteurs/api-3-ressources-classement.json` | 11 | 11 | 4 |
| administration | `tests/parite/vecteurs/api-3-administration.json` | 7 | 7 | 0 |
| securite | `tests/parite/vecteurs/api-3-securite.json` | 3 | 3 | 7 |
| masse | `tests/parite/vecteurs/api-3-masse.json` | 2 | 2 | 0 |
| recherche | `tests/parite/vecteurs/api-3-recherche.json` | 7 | 7 | 0 |
| partage | `tests/parite/vecteurs/api-3-partage.json` | 7 | 7 | 0 |

Écarts ASSUMÉS (nommés, mesurés à part, jamais comptés comme échecs) :
- `recherche:test_api_trash:005` et `recherche:test_trash_api_empty_all:006` : Paperless purge
  définitivement (`empty` → 200) ; GEDv2 refuse par un **409 nommé** (« la purge définitive est
  interdite : GEDv2 ne supprime aucune donnée utilisateur », règle produit 4). Seule la restauration est
  honorée.
- `partage:test_share_links_permissions_aware:007` : Paperless accorde une permission PAR OBJET
  (`assign_perm`) ; GEDv2 n'a pas de droit par objet, le document hors dossiers reste **403**.

Non rejouables (raison + référence) : chemins de stockage (`StoragePathViewSet`, `test_api_objects.py`),
profil/MFA/jeton (`test_api_profile.py`, django-allauth/django-otp), statut système et version distante
(`test_api_status.py`, `test_api_remote_version.py`, infrastructure Django) — voir `non_rejouables` dans
chaque fichier de vecteurs.

### PHPUnit

843 tests, 11 328 assertions, 1 sauté (préexistant), 0 échec. Nouveaux : `ApiTroisRessourcesClassementTest`
(2), `ApiTroisAdministrationTest` (2), `ApiTroisSecuriteTest` (2), `ApiTroisMasseTest` (1),
`ApiTroisRechercheTest` (1), `ApiTroisPartageTest` (1).

## Familles : écrans impactés et flux F à retester

Aucun écran n'est modifié : les ajouts sont sous `/api/v1` (jeton, hors session). Les écrans touchés par
les preuves « couvert-autrement » sont `/application` (configuration), `/workflows` et `/workflows/admin`
(workflows), `/corbeille`, `/taches`, `/administration/comptes`, `/administration/groupes`, `/reglages`,
`/profil`. Flux F à rejouer au jugement : **F2** (dépôt écran/mobile — `POST /api/v1/documents`),
**F5** (retrouver un document — recherche, statistiques, autocomplétion), **F10** (droits : partages,
corbeille, comptes/groupes), **F12** (reconstruction : le juge régénère la correspondance à l'identique).

## Restants `api` (39, raison + référence)

| Famille | Nb | Raison | Référence |
| --- | ---: | --- | --- |
| Filtres de modèle absent | 6 | `custom_field_query` (DSL JSON), `original_filename`, `owner`, `owner__id`, `owner__id__none`, `TagFilterSet.is_root` : modèles absents | `src/documents/filters.py:95,726,749,763` |
| `CorrespondentViewSet.*` (sauf list) | 5 | Émetteur = chaîne du document, pas d'entité ; create/retrieve/update/partial/destroy supposent un registre d'émetteurs (fichier `.ged/emetteurs.json`, service Socle, écran) | `src/documents/models.py` |
| `StoragePathViewSet.*` (sauf list) | 5 | Pas d'entité « chemin de stockage » : GEDv2 range par dossier ; la liste est exposée par `GET /api/v1/dossiers` | `src/documents/models.py` |
| `destroy` des référentiels (Tag, DocumentType, CustomField, SavedView) | 4 | GEDv2 ne supprime jamais un référentiel (règle 4) ; un `destroy` suppose un drapeau de retrait + corbeille de référentiel | `src/documents/models.py` |
| `destroy` comptes/groupes (User, Group) | 2 | GEDv2 ne supprime jamais un compte ni un groupe ; retrait par révocation des jetons / vidage du groupe | `src/paperless/views.py` |
| `ApplicationConfigurationViewSet.create/update/partial_update/destroy` | 4 | Configuration en `.env` (lecture) ; pas de registre global inscriptible par API (registre du fonds + écran + audit à créer) | `src/paperless/views.py` |
| `destroy` workflows (Workflow, Trigger, Action) | 3 | GEDv2 désactive une définition, ne la supprime pas | `src/documents/models.py` |
| `ShareLinkViewSet.update/partial_update` | 2 | L'échéance d'un partage n'est pas modifiable : on révoque puis recrée | `src/documents/views.py:2602` |
| `FaviconView.get`, `RemoteVersionView.get`, `TOTPView.get/post/delete`, `SocialAccountProvidersView.get`, `DisconnectSocialAccountView.post`, `OauthCallbackView.get` | 8 | Hors modèle GEDv2 (asset statique, pas de contrôle de version distante, MFA, django-allauth/OAuth) | `src/paperless/urls.py`, `src/paperless/views.py:97-412`, `src/paperless_mail/views.py:181` |

Le coût des évolutions de modèle (propriétaire, nom d'origine, hiérarchie d'étiquettes, registre
d'émetteurs, registre de configuration, corbeille de référentiels) est décrit dans les `note` de chaque
`absent` de `docs/parite/correspondance.json` : schéma (table ou registre du fonds), écrans concernés
(`/metadonnees`, `/application`, `/administration/*`) et flux F touchés (F1 dépôt, F4 validation,
F10 droits, F12 reconstruction). Aucune de ces évolutions n'a été faite dans ce lot.

## Revue des exclusions api-2 (propositions, non appliquées)

Le lot api-2 a classé `exclu` des *vues* et *routes* d'infrastructure Django (TOTP, statut système, version
distante, fournisseurs sociaux, OAuth) **alors que leurs `api:verbe:*` correspondants sont restés
`absent`** : incohérence de la porte. Conformément à la mission, je ne reclasse rien ; je propose au juge :

1. `api:verbe:TOTPView.{get,post,delete}` ↔ `api:vue:TOTPView` / `api:route:totp_view` (déjà `exclu`
   `infrastructure-django`) : aligner sur `exclu` (ou laisser `absent`, mais pas les deux catégories).
2. `api:verbe:RemoteVersionView.get` ↔ `api:route:remoteversion` (déjà `exclu`) : même alignement.
3. `api:verbe:FaviconView.get` ↔ `api:route:favicon` (déjà `exclu`) : même alignement.
4. `api:verbe:SocialAccountProvidersView.get`, `api:verbe:DisconnectSocialAccountView.post`,
   `api:verbe:OauthCallbackView.get` ↔ routes `api:route:anon:*` (déjà `exclu`) : même alignement.
5. `api:verbe:SystemStatusView.get` : ce lot le couvre via la route réelle `GET /sante` (preuve PHPUnit
   `ApiTroisSecuriteTest`) → **proposition de revoir l'exclusion de `api:route:system_status`**, devenue
   discutable puisque GEDv2 a un état système réel.
6. `api:route:tasks` exclu (`infrastructure-celery-redis`) : discutable, car `TasksViewSet.list/retrieve`
   sont maintenant couverts par `GET /api/v1/taches` (service `Socle\Taches` + `ingestion_travaux`, sans
   Celery). **Proposition d'examiner la route.**
7. `api:vue:RemoteVersionView` : si elle n'existe pas dans l'inventaire, la mention d'api-2 est à vérifier ;
   le verbe `RemoteVersionView.get` est bien resté `absent`.

## Lignes hors secteur (signalées, à commiter par le juge)

- `src/Securite/DroitsRoutes.php` : 42 routes déclarées + résolution des chemins paramétrés
  `/api/v1/<ressource>/{id}`.
- `tools/banc/contrat.php` : statuts attendus (401) des routes ajoutées.
- `docs/parite/correspondance.json` + `tools/parite/corriger-correspondance-api-3.php` (secteur parité).
- `tools/composer.phar` (outil gitignoré, absent de ce worktree) restauré depuis la copie de référence
  avant le harness ; non commité (gitignoré).

## Porte qualité

- **Analyse statique** : `php vendor\bin\phpstan analyse --memory-limit=2G` → **0 erreur, niveau 6**,
  `phpstan-baseline.neon` inchangée. `php tools\composer.phar audit` → « No security vulnerability
  advisories found ».
- **TDD** : chaque famille a ses vecteurs commités AVANT son code ; le rejeu a été vu ROUGE avant les
  routes, vert après. Aucun vecteur modifié après coup, aucun test affaibli, aucune sonde retirée.
- **Revue du diff** (grilles ECC php-reviewer / security-reviewer / silent-failure-hunter) :
  - Injection : toutes les valeurs passent par des marqueurs `?` ; `Registre`/`Nomenclature` valident les
    entrées ; les chemins réutilisent les services Socle (slug, bornes, unicité). Les motifs `LIKE`
    restent échappés dans `Catalogue`.
  - Erreurs : toute exception inattendue est journalisée (`error_log`) puis rendue en JSON 500 nommé ; un
    refus est toujours nommé (étape, cause, référence). Jeton et réglages ne sont jamais journalisés ; le
    jeton d'API et le `slug` d'un partage ne sont rendus qu'à la création.
  - Droits : chaque route passe par `ApiJeton` (jeton lié à un compte, portées lecture/écriture) ; les
    verbes d'administration (comptes, groupes, tâches, corbeille) exigent en plus le rôle
    administrateur ; les listes de corbeille intègrent le prédicat de lecture DANS la requête.
  - Zéro suppression : aucun `DELETE` de donnée utilisateur ; le `destroy` d'un partage RÉVOQUE, la purge
    de corbeille est refusée 409 nommé, les référentiels ne se suppriment pas.
- **Observation** : `RessourcesApi` duplique les petits gabarits `json`/`erreur`/`echec` de `PariteApi`
  (trois méthodes courtes), même compromis assumé qu'à api-2 ; la porte d'authentification est partagée
  (`ApiJeton`).

## Harness (`powershell -ExecutionPolicy Bypass -File tools\harness.ps1 -SansBanc`)

Rejeu final du 2026-10-12. `tools/composer.phar` restauré depuis la copie de référence avant le rejeu.

| Couche | État | Détail |
| --- | --- | --- |
| 0-garde-fous | VERT | vendor + node_modules, `DB_NAME=gedv2_test` imposé |
| 1-unitaire | VERT | 843 tests, 11 328 assertions, 1 sauté |
| 3-banc | ROUGE | sautée par `-SansBanc` (attendu) |
| 4-écrans | VERT | 94 parcours verts (4,3 m) |
| 7-sécurité | VERT | aucun contournement accepté |
| 2-contrat | VERT | toutes les routes déclarées couvertes |
| 6-charge | VERT | p95 lecture 667 ms, p95 écriture 242 ms, 0 erreur, 0 collision, ingestion finie |
| 8-statique | VERT | PHPStan niveau 6 : 0 erreur ; `composer audit` : aucune vulnérabilité |
| 5-personas | VERT | 6 personas, tous les gestes trouvés (médianes 352–695 ms) |
| 9-parité | VERT | 780 éléments, 668 couverts, strict 85,64 %, hors exclusions 93,43 %, 0 non prouvé |

La couche 3 étant sautée par `-SansBanc`, aucun banc n'a été rejoué. Seuls restent non commités les
fichiers régénérés par le harness (`recette/registre.json`, `recette/preuves/**`,
`recette/parite-paperless.json`, `docs/parite/PARITE-PAPERLESS.md`, `var/**`).

ETAT: fini
