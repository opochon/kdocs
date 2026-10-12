# Lot ged-parite-modele-2 — rattraper les écarts `modele` de la famille COURRIER

Base : `ged-parite-modele-1` fusionné (`a582edb`) : 780 éléments, 339 couverts, parité stricte 43,46 %.
Cible : les éléments `modele` de la famille Courrier (`MailAccount`, `MailRule`, `ProcessedMail` et leurs
énumérations) et les éléments `api` correspondants (test, process, bulk_delete du courrier traité).
Secteur : Ingestion (nouveau sous-namespace `Ged\Ingestion\Courrier`), documenté dans `docs/secteurs/courrier.md`.

Méthode oracle d'abord : vecteurs de comportement extraits en LECTURE SEULE des tests Paperless
(`C:\ged\ref\paperless-ngx`, tag v2.20.5) → rejeu PHPUnit contre le vrai moteur courrier et `gedv2_test` →
rouge → implémentation → vert → commit par sous-famille.

## Fait (commits)

- `9d3a414` — **oracle** : `tools/parite/extraire-courrier.py` (lecture seule) et 42 vecteurs commis
  (`tests/parite/vecteurs/modele-courrier.json` ; familles filtres-fichiers, filtres, titre, émetteur,
  pièces, portée, actions, règles, validation, journal ; 4 non rejouables OAuth/Django/Celery motivés).
- `281abbc` — **comptes et secrets + migration** : `Coffre` (AES-256-GCM, clé `GED_SECRET_KEY` ou clé locale),
  `Compte`, `Comptes` (dossier `var/courrier`, masque d'écran) ; `Ingestion\Migrations` v2 : colonnes du
  journal ProcessedMail sur `ingestion_messages`, montée idempotente et retour non destructif testés.
- `c1bf1a0` — **règles et filtres** : `Regle` (énumérations Paperless telles quelles), `Regles` (magasin),
  `Filtres` (sous-chaînes, jokers de pièces insensibles à la casse, âge, critères d'action déjà faits).
- `edbe6be` — **actions, port, moteur, journal** : `PortBoite` (interface injectable), `BoiteFausse` (test, aucun
  réseau), `BoiteImap` (production, compose le client IMAP pur étendu), `Moteur` (dépôt par le contrat `Depot`,
  actions supprimer/déplacer/marquer/drapeau/étiqueter), `Traites` (anti double traitement, purge = marque).
- `e25ccdd` — **écran** : `/courrier` (comptes, règles, journal, test de connexion), réservé à
  `application:administrer`, `src/Ingestion/Module.php` appelé par le Kernel sans le modifier ; parcours
  Playwright `tests/e2e/courrier.spec.ts` agissant comme l'utilisateur.
- `d04fa86` — **parité** : `correspondance.json` (100 éléments courrier couverts), phase 6 du rapport,
  correcteur ciblé `tools/parite/corriger-correspondance-courrier.php`, rejeu `ModeleCourrierTest`.

## Mesures exactes

### Parité (`recette/parite-paperless.json`, régénéré par le juge)

| Mesure | Avant | Après |
| --- | ---: | ---: |
| Éléments couverts | 339 / 780 | **435 / 780** |
| Parité stricte | 43,46 % | **55,77 %** |
| Parité hors exclusions | 46,31 % | **59,43 %** |
| `modele` couverts | 163 / 313 | **233 / 313** |
| `api` couverts | 72 / 318 | **96 / 318** |
| `ecran` couverts | 10 / 30 | **12 / 30** |

(Chiffres régénérés par `php tools/parite/juge.php` après le rejeu PHPUnit ; voir la ligne « 9-parité ».)

### Comportement rejoué (phase 6 du rapport)

| Famille | Passes | Total | Non rejouables |
| --- | ---: | ---: | ---: |
| filtres-fichiers | 9 | 9 | — |
| filtres | 8 | 8 | — |
| titre | 3 | 3 | — |
| émetteur | 5 | 5 | — |
| pièces | 4 | 4 | — |
| portée | 1 | 1 | — |
| actions | 8 | 8 | — |
| règles | 1 | 1 | — |
| validation | 1 | 1 | — |
| journal | 2 | 2 | — |
| **total** | **42** | **42** | **4** |

Les 4 non rejouables : `AUTH=PLAIN`/UTF-8 (client réel), jeton OAuth XOAUTH2, rafraîchissement OAuth Gmail/
Outlook (aucun réseau en test, écart assumé), orchestration Celery. Référence :
`src/paperless_mail/tests/test_mail.py`.

### PHPUnit

Suite complète : **813 tests, 10 531 assertions, 1 sauté** (chiffres du rejeu JUnit ; à confirmer par le
rejeu final du harness). Nouveaux : `CoffreTest` (6), `MigrationCourrierTest` (4), `RegleTest` (7),
`TraitesTest` (3), `ModuleTest` (2), `ModeleCourrierTest` (1).

## Restants de la famille `modele` (raison + référence)

| Famille d'écarts | Raison | Référence Paperless |
| --- | --- | --- |
| Documents, vues enregistrées, tâches, champs personnalisés listables | Modèle partiel ; lots suivants. | `src/documents/models.py` |
| Affichage (`SavedView`, `UiSettings`, `ApplicationConfiguration`) | Modèle absent ; lot suivant. | `src/documents/models.py` |
| Rendu PDF du corps du courriel (`PdfLayout` appliqué) | Le choix de mise en page est conservé dans les métadonnées et exposé ; le rendu PDF du corps est un lot d'aperçu. | `src/paperless_mail/preprocessor.py` |
| OAuth Gmail/Outlook (jeton, rafraîchissement, callback) | Écart assumé : non réalisable sans réseau réel. | `src/paperless_mail/oauth.py` |

## Écrans impactés et flux F à retester

- `/application` : nouveau lien « Administration du courrier » (barre d'actions).
- `/courrier`, `/courrier/comptes/{id}`, `/courrier/regles/{id}` : écran d'administration — parcours Playwright dédié.
- Flux F3 (courriel entrant) : le moteur courrier remplace l'adresse d'ingestion légère pour les comptes/règles ;
  la chaîne de dépôt reste identique. F1–F2 inchangés.

## Porte qualité

- **Analyse statique** : `php vendor\bin\phpstan analyse --memory-limit=2G` → **0 erreur, niveau 6**,
  `phpstan-baseline.neon` vide inchangée. `composer audit` : aucune vulnérabilité.
- **TDD** : rejeu d'abord vu rouge (SourceDossier introuvable, source du titre de vecteur), puis vert ;
  vecteurs commis non modifiés.
- **Revue du diff** (php-reviewer / security-reviewer / silent-failure-hunter) : aucun constat CRITIQUE ou ÉLEVÉ.
  - Injection : requêtes préparées ; seules concaténations SQL sont des noms de colonnes d'une liste interne
    figée et un `LIMIT` borné par `int`.
  - Secrets : mot de passe chiffré AES-256-GCM, jamais en clair (fichier, écran, journal), test de non-fuite ;
    le coffre ne lève jamais un secret dans un message d'erreur.
  - Erreurs : une action qui échoue est journalisée `FAILED` avec sa cause, jamais avalée ; la boîte réelle
    lève une erreur nommée.
  - DDL : `Schema::installer` n'est appelé que par les outils CLI et les amorces de test ; `DdlHorsHttpTest` vert
    (`Migrations` appelle `Schema::assurerTables`, pas `installer`).

## Harness (`powershell -File tools\harness.ps1 -SansBanc`)

À compléter par le rejeu final (voir la note de clôture).
