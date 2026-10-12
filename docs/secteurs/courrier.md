# Secteur COURRIER — comptes, règles, journal des courriels traités

Agent : DeepCode (lot ged-parite-modele-2) · Juge : Claude sur le poste principal

## Mission
Porter le modèle courrier de Paperless-ngx 2.20.5 (famille `modele` : `MailAccount`, `MailRule`,
`ProcessedMail` et leurs énumérations) à la manière de GEDv2 : l'ingestion de courriels par comptes IMAP et
règles de tri, en réutilisant la chaîne de consommation (`Contrats\Depot`), le service de correspondance
(`Ged\Classement\Correspondance`), les workflows (déclencheurs) et le modèle de droits. Le client IMAP est une
**interface injectable** (faux en mémoire en test, aucun réseau). Les mots de passe de comptes sont **chiffrés
au repos**.

## Chemins
`src/Ingestion/Courrier/`, `src/Ingestion/Module.php`, `src/Ingestion/Schema.php`, `src/Ingestion/Migrations.php`,
`tests/php/Ingestion/Courrier/`, `tests/php/Parite/ModeleCourrierTest.php`, `tests/parite/vecteurs/modele-courrier.json`,
`tests/e2e/courrier.spec.ts`, `tools/parite/extraire-courrier.py`, `tools/parite/corriger-correspondance-courrier.php`,
`templates/ecrans/courrier*.twig`.

Lignes HORS secteur, signalées (à valider par le juge) : `src/Securite/DroitsRoutes.php` (12 routes en
`application:administrer` + reconnaissance des chemins paramétrés), `tools/banc/sonde-securite.php` (charges
CSRF défensives des mêmes routes), `templates/ecrans/application.twig` (un lien « Administration du courrier »).

## Décisions
- **Le dossier fait foi** : comptes et règles vivent dans `var/courrier/comptes.json` et `regles.json`
  (configuration, pas de table). Le **journal ProcessedMail** est porté par `ingestion_messages`, enrichie par une
  migration versionnée v2 : aucune table de plus, le plafond d'installateur ne bouge pas.
- **Aucun effacement** : « supprimer » range le courriel en `GED-corbeille` ; « purger le journal » marque la
  ligne (`statut = purge`). Comptes et règles retirés sont MARQUÉS inactifs.
- **Un seul moteur** : `Moteur::traiter` applique les règles actives dans l'ordre, dépose par le contrat `Depot`
  (mêmes métadonnées, même rangement) et journalise le courriel traité (anti double traitement).
- **Secrets** : `Coffre` (AES-256-GCM, clé `GED_SECRET_KEY` en base64 ou clé locale `var/courrier/.secret.key`)
  chiffre le mot de passe avant écriture ; l'écran ne rend qu'un masque.

## Demandes
- Au Socle : rien (aucune table nouvelle ; `ingestion_messages` porte le journal).
- Aux Droits/au Harnais : commiter les lignes signalées ci-dessus (elles ne portaient aucune modification
  concurrente dans ce worktree).
- À l'Interface : le lien de `templates/ecrans/application.twig` (une ligne) est commité avec le lot.

## Journal ged-parite-modele-2

- **Oracle d'abord** : `tools/parite/extraire-courrier.py` (lecture seule des tests Paperless) et 42 vecteurs
  commis (`tests/parite/vecteurs/modele-courrier.json`, 4 non rejouables motivés - OAuth/Django/Celery).
- **Rejeu** : `tests/php/Parite/ModeleCourrierTest.php` rejoue 42/42 vecteurs sur le vrai moteur et `gedv2_test`.
- **Comptes et secrets** : `Coffre`, `Compte`, `Comptes` + test de non-fuite.
- **Migration** : `Ingestion\Migrations` v2 (colonnes courrier), montée/retour testés sur `gedv2_test`.
- **Règles et filtres** : `Regle`, `Regles`, `Filtres` (réutilise `Classement\Correspondance`).
- **Actions, port, moteur, journal** : `PortBoite` (interface), `BoiteFausse` (test), `BoiteImap` (production),
  `Moteur`, `Traites`.
- **Écran** : `/courrier` (comptes, règles, journal, test de connexion), réservé à `application:administrer`,
  parcours Playwright `tests/e2e/courrier.spec.ts`.
- **Parité** : `correspondance.json` (100 éléments courrier couverts), phase 6 du rapport, `PARITE-PAPERLESS.md`
  régénéré.
