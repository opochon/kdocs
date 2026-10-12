# Lot ged-parite-api-1 — rattraper les écarts `api` de la parité Paperless-ngx 2.20.5

Base : `ged-parite-chaine-1` fusionné (`891d923`) : 780 éléments, 228 couverts, parité stricte 29,23 %.
Cible : la catégorie `api` encore `absent` — 226 éléments (hors exclusions Celery) : filtres de liste,
verbes REST des ressources, actions, routes et « vues » (ViewSets).

Méthode oracle d'abord, par famille : vecteurs extraits en LECTURE SEULE des tests Paperless
(`C:\ged\ref\paperless-ngx`, tag v2.20.5, commit `d1aa76e`) → rejeu PHPUnit contre la VRAIE couche de
requêtes de GEDv2 (`gedv2_test`) → rouge → implémentation → vert → commit. Aucune ligne de code Paperless
(GPL-3) reprise : seuls des couples `{entree, sortie}` sont extraits. Un commit par famille.

Les ressources dont le MODÈLE n'existe pas dans GEDv2 (champs personnalisés listables, liens de partage
globaux, comptes et règles de courrier, TOTP, tâches Celery, chemins de stockage comme entité) ne sont pas
marquées couvertes : elles sont listées « modèle absent » ci-dessous et relèvent du lot `modele` (à venir).

## Fait (commits)

- `28851a4` — **famille « filtres-documents »** (oracle + code + droits) : `tools/parite/extraire-api.py`
  (lecture seule de `test_api_documents.py`) + vecteurs commités
  `tests/parite/vecteurs/api-filtres-documents.json` (18 vecteurs rejouables, 2 `non_rejouables` Django/DRF) ;
  rejeu `tests/php/Parite/ApiFiltresDocumentsTest.php` contre `Jonctions\Catalogue::lister` (oracle lancé
  ROUGE avant le code : 2 échecs, implémentation mise de côté) ; garantie de droits par l'interface HTTP
  réelle `tests/php/Parite/ApiFiltresNonAccesTest.php` ; `src/Jonctions/Catalogue.php` porte les filtres
  (id/ids, titre/contenu et opérateurs, empreinte, mime, dates, étiquettes, À traiter, absence
  émetteur/type/dossier).
- `c847da1` — **bornes de filtres** (année/mois/jour de `created`/`added`/`modified`, contenu indexé,
  référentiels et emplacement) ; correcteur ciblé `tools/parite/corriger-correspondance-api.php` (le
  curateur global reconstruit la correspondance et perdrait les 55 éléments `chaine` du lot précédent).
- `77d6e16` — **famille « filtres-référentiels »** : `GET /api/v1/types?id=|nom=|nom_contient=`,
  `GET /api/v1/etiquettes?nom=|nom_contient=`, `GET /api/v1/emetteurs?nom=|nom_contient=`, au même
  périmètre de droits (`ApiFiltresReferentielsTest`).
- `ce5c7cf` — **phase 4 du rapport** (`tools/parite/juge.php`) : la couche 9 rend la mesure des vecteurs
  d'API, comme la phase 3 le fait pour la chaîne.

## Mesures exactes

### Parité (`recette/parite-paperless.json`, régénéré par le juge)

| Mesure | Avant | Après |
| --- | ---: | ---: |
| Éléments couverts | 228 / 780 | **251 / 780** |
| Parité stricte | 29,23 % | **32,18 %** |
| Parité hors exclusions | 31,15 % | **34,29 %** |
| `api` couverts | 49 / 318 | **72 / 318** (23 nouveaux, 0 non prouvé) |
| `api` absents | 226 | **203** |

### Comportement rejoué (phase 4 du rapport)

| Famille | Fichier de vecteurs | Passes | Total | Non rejouables | Taux |
| --- | --- | ---: | ---: | ---: | ---: |
| filtres-documents | `tests/parite/vecteurs/api-filtres-documents.json` | 18 | 18 | 2 | 100,00 % |

Les 2 vecteurs non rejouables sont `tags__id__all` avec une valeur non numérique (ObjectFilter de
Paperless avale le `ValueError` et rend la queryset inchangée) et `id__range` (filtre non déclaré :
Django/DRF ignore silencieusement un paramètre inconnu). GEDv2 refuse une entrée invalide par un 400 nommé ;
ces deux comportements Django ne sont pas contournés. Référence : `src/documents/filters.py`.

### PHPUnit

700 tests, 10 012 assertions, 1 sauté (préexistant), 0 échec. Nouveaux : `ApiFiltresDocumentsTest` (2),
`ApiFiltresNonAccesTest` (1), `ApiFiltresReferentielsTest` (1).

## Restants `api` (203 éléments, raison + référence)

Rien n'est marqué couvert sans test qui passe. Les 203 absents se rangent en familles ; chacune porte sa
raison et sa référence Paperless.

| Famille d'écarts | Nb | Raison (modèle / fonction) | Référence Paperless |
| --- | ---: | --- | --- |
| Comptes et règles de courrier, courrier traité | 17 | Modèle absent : GEDv2 lit l'IMAP par `Ingestion\Imap\ImapBoite` mais n'expose ni compte, ni règle, ni courrier traité comme entités. Lot `modele`. | `src/paperless_mail/views.py`, `filters.py:9`, `urls.py:73-81` |
| TOTP et authentification sociale (Django allauth) | 13 | Fonction absente du modèle GEDv2 : sessions par `Securite\Authentification`, pas de second facteur ni de fournisseur social. | `src/paperless/views.py:97-412`, `urls.py:173-334` |
| Champs personnalisés listables et filtres associés | 15 | Modèle partiel : `Socle\Champs` gère les champs par type (`POST /metadonnees/champ`), mais il n'existe pas d'entité champ listable/CRUD REST. Lot `modele`. | `src/documents/views.py:2823`, `filters.py:209,728-749` |
| Liens de partage comme ressource globale | 10 | GEDv2 gère le partage PAR DOCUMENT (`POST /documents/{id}/partages`, `Socle\Collaboration`), sans ressource globale listable. | `src/documents/views.py:2602-2623`, `filters.py:793` |
| Chemin de stockage comme entité | 11 | Modèle absent : GEDv2 range par dossier (`documents.chemin_relatif`), pas par entité nommée/identifiée. | `src/documents/views.py:2306-2347`, `filters.py:114` |
| Étiquettes et émetteurs sans identifiant, sans hiérarchie | 5 | GEDv2 porte étiquettes et émetteurs comme VALEURS texte (JSON / colonne), sans `id` ni parenté. | `src/documents/filters.py:81,90-95,712,763` |
| Propriétaire, numéro d'archive, nom d'origine | 6 | Modèle absent : pas de propriétaire de document ; l'ASN est contrôlé à l'ingestion (`Ingestion\ControleAvant`) mais pas listable ; le nom d'origine vit dans `ingestion_travaux`. | `src/documents/filters.py:716,726,763` |
| Tâches (file Celery/Redis) | 6 | Infrastructure exclue (aucun démon imposé en mutualisé) : jobs courts par `Ingestion\Travaux` + registre, pas de vue de tâches. | `src/documents/views.py:2532` |
| Utilisateurs, groupes, configuration applicative | 24 | Modèle partiel (`comptes`, `securite_groupes`) mais pas de CRUD REST : GEDv2 administre par les écrans `/administration/*` et `application:administrer`. Lot `api`/V2-05. | `src/paperless/views.py:105-168,349`, `urls.py:80-155` |
| Verbes REST des référentiels sans entité | 12 | Mêmes raisons que « étiquettes/émetteurs » et « chemin de stockage » : `CorrespondentViewSet`, `TagViewSet`, `StoragePathViewSet`. | `src/documents/views.py:388-462` |
| CRUD REST des types (DocumentTypeViewSet) | 5 | `Socle\Nomenclature` sait ajouter/renommer/désactiver, mais ne l'expose pas en CRUD REST ; lot `api`. | `src/documents/views.py:462` |
| CRUD REST des vues enregistrées (SavedViewViewSet) | 5 | `Socle\Vues` enregistre et relit (`GET /api/v1/vues`) ; pas d'update/destroy REST ; lot `api`. | `src/documents/views.py:1452` |
| Workflows (règles, circuits, déclencheurs, actions) | 18 | Modèle différent : GEDv2 a ses propres règles/circuits (`Workflows\*`, routes `/workflows/*`) ; la forme REST Paperless n'a pas d'équivalent 1:1. | `src/documents/views.py:2762-2805` |
| Statistiques, journaux, santé, saisie assistée, masse, export | 24 | Fonctions non portées : GEDv2 a ses propres écrans (`/recherche`, `/bibliotheque/masse`, `/sante`) ; pas de routes REST Paperless équivalentes. | `src/documents/views.py:1780-2698,2941`, `urls.py:66-145,193` |
| Corbeille, profil, dépôt, modification en masse (verbes) | 7 | Couverts autrement fonctionnellement (service corbeille `Socle\DepotFichiers`, profil `/profil`, dépôt `POST /api/v1/documents`, masse `POST /bibliotheque/masse`) mais les verbes REST Paperless ne sont pas 1:1 ; lot `api`. | `src/documents/views.py:1513,1666,3123`, `src/paperless/views.py:189-195` |

Total : 203. Les ViewSets `api:vue:*` (24) reprennent la raison de leur ressource.

## Écrans impactés et flux F à retester

- `GET /api/v1/documents` et ses filtres : aucun écran propre (API sous jeton) ; le flux de lecture de
  Recherche/Bibliothèque n'est pas modifié (les filtres sont opt-in et le banc de charge couvre la
  Bibliothèque). Flux F2 (recherche) et F4 (bibliothèque) à rejouer au jugement.
- `GET /api/v1/types`, `/etiquettes`, `/emetteurs`, `/vues` : référentiels appelés par les choix d'écrans
  (`Socle\Nomenclature`, `Socle\Vues`) ; aucun écran ne les interroge par cette API (écrans = session).

## Porte qualité

- **Analyse statique** : `php vendor\bin\phpstan analyse --memory-limit=2G` → **0 erreur, niveau 6**,
  `phpstan-baseline.neon` vide inchangée. `composer audit` : « No security vulnerability advisories found ».
- **TDD** : oracle rouge avant le code (vecteurs commités, rejeu lancé rouge en mettant l'implémentation de
  côté : 2 échecs avant, vert après). Aucun test affaibli, aucun vecteur modifié après coup.
- **Revue du diff** (grilles php-reviewer / security-reviewer / silent-failure-hunter) : aucun constat
  CRITIQUE ou ÉLEVÉ.
  - Injection : toutes les valeurs passent par des marqueurs `?` liés ; les seules concaténations SQL sont
    des constantes (`LIMIT`), des marqueurs générés (`array_fill`) et des opérateurs choisis dans une table
    interne. Les motifs LIKE sont échappés (`%, _`).
  - Erreurs : `types()` journalise une nomenclature illisible et retombe sur les types livrés (jamais une
    liste vide muette) ; une entrée de filtre invalide lève `ErreurApi` 400 nommée, jamais un 500 muet ;
    aucun `catch (Throwable)` qui avale sans tracer.
  - Style : `declare(strict_types=1)`, classes `final`, méthodes courtes, aucun secret, aucune donnée réelle.

## Harness (`powershell -File tools\harness.ps1 -SansBanc`)

Rejeu du 2026-10-12 (14 m 21 s). `tools/composer.phar` (outil gitignoré, absent de ce worktree) restauré
depuis la copie de référence avant le rejeu : sans lui la couche 8 est ROUGE à tort.

| Couche | État | Détail |
| --- | --- | --- |
| 0-garde-fous | VERT | vendor + node_modules, `DB_NAME=gedv2_test` imposé |
| 1-unitaire | VERT | 700 tests, 10 012 assertions, 1 sauté |
| 3-banc | ROUGE | sautée par `-SansBanc` (attendu) |
| 4-écrans | VERT | 80 passés (4,8 m) |
| 7-sécurité | VERT | aucun contournement accepté |
| 2-contrat | VERT | toutes les routes déclarées couvertes |
| 6-charge | VERT | p95 lecture 635 ms, p95 écriture 234 ms, 0 erreur, 0 collision, ingestion finie |
| 8-statique | VERT | PHPStan niveau 6 : 0 erreur ; `composer audit` : aucune vulnérabilité |
| 5-personas | VERT | 6 personas, tous les gestes trouvés (médianes 341–678 ms) |
| 9-parité | VERT | 780 éléments, 251 couverts, strict 32,18 %, hors exclusions 34,29 %, 0 non prouvé |

La couche 3 étant sautée par `-SansBanc`, aucun banc n'a été rejoué. Les oracles de classement et
d'ingestion restent verts dans la couche 1 (`OracleCourrier100pTest`, `OracleClassementBruiteTest`,
`OracleClassementRegressionTest`, `PiloteTest` : 0 échec). Seuls restent non commités les fichiers
régénérés par le harness (`recette/registre.json`, `recette/preuves/**`, `recette/parite-paperless.json`,
`var/**`).

ETAT: fini

