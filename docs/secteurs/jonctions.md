# Secteur JONCTIONS — K-Time, ClearMyMails, API d'entrée

Agent : DeepCode (V2-08a API d'entrée, V2-08b appairage/destinations/.eml) · Juge : Claude Code · Lot en cours : V2-08b

## Mission
La GED ne refait pas ce que les produits voisins font déjà ; elle se branche proprement, par API, jamais par la base
d'un autre.
- **ClearMyMails** = le pont lourd du courriel (Gmail, Microsoft 365 / Graph, règles de tri, historique). Il pousse les
  pièces vers la GED par **l'API d'entrée** ; la GED les traite exactement comme le dossier d'ingestion (même chaîne,
  même dédoublonnage, source `cmm`). L'adresse d'ingestion IMAP (secteur Ingestion) reste la porte légère.
- **K-Time** : la GED **détecte** une facture fournisseur (QR suisse, montant, échéance, IBAN) et la **propose** à
  K-Time ; K-Time l'introduit. Jamais d'écriture WinBiz par la GED. Le dépôt K-Time est en **lecture seule** pour nous.
- **API d'entrée** : `POST /api/v1/depots` (fichier + métadonnées facultatives), jeton par application, idempotente
  (même empreinte = même réponse), réponse = identifiant du travail d'ingestion, puis `GET` pour suivre son état.

## Chemins
`src/Jonctions/`, `tests/php/Jonctions/`, `tests/e2e/jonctions.spec.ts`, `docs/api/`,
`templates/ecrans/appairage.twig`, `templates/ecrans/appareils.twig`

## Lot V2-08 — porte
1. API d'entrée : 3 fichiers poussés deux fois → 3 documents, 0 doublon ; jeton absent ou révoqué → 401 nommé.
2. ClearMyMails (mode simulé en test, réel en recette) : un message à 2 pièces jointes → 2 documents, source `cmm`,
   lien vers le message d'origine dans la fiche.
3. K-Time : une facture QR du corpus apparaît côté K-Time comme proposition complète (fournisseur, montant, échéance,
   référence), sans aucune écriture WinBiz ; un refus K-Time revient dans la fiche.
4. Aucune jonction en panne ne bloque la GED : file d'envoi avec reprise, erreurs visibles dans « Application ».

## Reprendre de GEDv1
`erpconnect` (détection facture → K-Time), `MailSyncService` (désactivé : ne pas reprendre, CMM le remplace),
l'API REST existante (`/api/documents`) comme liste de courses, pas comme code.

## Demandes
- À l'Ingestion (livrée dans ce lot) : la source `Ged\Jonctions\SourceApi` écrit dans la zone de travail et passe par
  `Travaux::recevoir` ; il reste à la brancher dans `Fabrique::sources()` (demande écrite dans `docs/secteurs/ingestion.md`).
- Aux Droits (livrée dans ce lot) : jetons d'application (création, révocation, portée `depot`) et déclaration des deux
  routes en `publique:api-depot(-suivi)` (signalé dans `docs/secteurs/droits-securite.md`).
- Au Socle (V2-08b, ajout minimal fait) : `DepotFichiers::cheminRangement()` honore une clé de métadonnées `dossier` —
  un dépôt direct par l'API dans un dossier autorisé garde ce chemin au lieu du rangement année/type/émetteur (section 2).
- À l'Ingestion (V2-08b, ajout minimal fait) : `Pilote::decouper()` lit l'accompagnement `<fichier>.depot.json` de la
  source API et propage l'origine CMM dans chaque pièce ; `Pilote::ranger()` l'écrit sous `origine.courriel` du `.meta.json`.
- À l'Aperçu (V2-08b, ajouts minimaux faits) : `Apercu\Source` connaît la catégorie `message` (`.eml`) et résout l'`id`
  depuis l'index quand le `.meta.json` n'en porte pas ; `Apercu\Module` rend le HTML nettoyé de `Ged\Jonctions\Courriel`.
  Recherche n'est pas modifiée : `SourceRecherche` indexait déjà le `texte` du `.meta.json`.
- À l'Interface (V2-08b, ajout minimal à commiter par le juge) : lien statique « Appareils reliés » dans
  `templates/ecrans/application.twig` (2 lignes).
- Au harnais (V2-08b) : statuts attendus des routes publiques dans `tools/banc/contrat.php` (fichier sans modification
  concurrente, commité par DeepCode) et charges CSRF défensives dans `tools/banc/sonde-securite.php` (modifié par GLM :
  à commiter par le juge).

## Journal V2-08a — API d'entrée (porte 1)

- Oracle d'abord : `tests/php/Jonctions/DepotApiTest.php` écrit rouge (classes `Ged\Jonctions\{Schema,Jetons,SourceApi,DepotApi}`
  absentes : 7 erreurs), puis vert — 7 tests, 50 assertions.
- Fait : `POST /api/v1/depots` (fichier `multipart/form-data` + métadonnées facultatives) et `GET /api/v1/depots/{id}`.
  Jeton par application (`Jetons` : création, révocation, portée `depot`, empreinte sha256 en base, jamais le clair ;
  portée `*` possible pour un jeton d'administration). L'authentification se fait dans le secteur, hors session :
  `Authorization: Bearer …`.
- Fait : idempotence par empreinte — `SourceApi` écrit la copie stable dans la zone de travail dédiée
  (`<INGESTION_TRAVAIL>/api`) puis passe par `Travaux::recevoir` ; à empreinte connue, le nouveau fichier part dans
  `doublons/` (jamais supprimé) et la réponse est identique (même code 202, même identifiant). Les métadonnées
  accompagnent la copie dans `<fichier>.depot.json` (le dossier fait foi).
- Fait : erreurs nommées (étape, cause, référence) — 401 jeton absent/inconnu/révoqué, 403 portée insuffisante,
  413 fichier trop gros, 415 type refusé, 400 transfert incomplet, 404 travail introuvable. Types acceptés : les mêmes
  que le dossier d'ingestion ; taille max `API_DEPOT_MAX_OCTETS` (25 Mio par défaut).
- Fait : `SourceApi` implémente `Ged\Ingestion\Source` ; `relever()` rend les fichiers écrits mais pas encore
  enregistrés (reprise après panne) et ignore ceux déjà connus.
- Signalé : `src/Securite/DroitsRoutes.php` (secteur Droits) déclare les deux routes en `publique:api-depot(-suivi)`
  pour qu'elles échappent à la session ; la porte réelle est le jeton. Demande écrite dans la fiche Droits ; sonde
  offensive rejouée verte.
- Preuves : `vendor\bin\phpunit tests\php\Jonctions` vert (7 tests, 50 assertions) ; `php tools\banc\sonde-securite.php`
  vert. Harness `-SansBanc` : couches 0 et 7 vertes ; relance isolée de PHPUnit verte (241 tests, 1072 assertions) ;
  la couche 4 reste rouge sur 5–6 parcours `recherche`/`bibliotheque` (résultats vides) parce qu'un agent concurrent
  remet à neuf la base partagée `gedv2_test` — défaut hors secteur, déjà consigné dans la fiche Droits.
- Documentation : `docs/api/depots.yaml` (OpenAPI court : dépôt et suivi, jeton Bearer, schémas d'erreur).
- Commit : `1759a67` `jonctions: API d'entrée sous jeton, idempotente (V2-08a)`.

ETAT: fini


## Jugement V2-08a (Claude Code, 29-09) — porte franchie
Rejoué sur le code commité : unitaire 212/212, 3 bancs verts, écrans 26/26, couche 7 verte (les deux routes publiques d'API tiennent par le jeton). Demande à l'Ingestion faite : `SourceApi` est dans `Fabrique::sources()`. Reste : V2-08b (ClearMyMails pousse vers l'API, source `cmm`), V2-08c (K-Time).
ETAT: fini (jugé)

## À commiter par le juge (fichiers portant des modifications d'un autre agent)

- `src/Securite/DroitsRoutes.php` (modifié par GLM, V2-04) — lignes ajoutées par V2-08b :
  - registre `PERMISSIONS` : `GET|DELETE /api/v1/appairage/moi` → `publique:api-appairage-moi`,
    `POST /api/v1/appairage/jeton` → `publique:api-appairage`, `GET /api/v1/dossiers` → `publique:api-dossiers`,
    `GET|POST /appairage` → `documents:traiter`, `GET /application/appareils` → `documents:traiter`,
    `POST /application/appareils/{id:[0-9]+}/revoquer` → `documents:traiter` ;
  - `permissionPour()` : reconnaissance de la route paramétrée `POST /application/appareils/[0-9]+/revoquer`.
- `tools/banc/sonde-securite.php` (modifié par GLM, V2-04) — deux charges défensives ajoutées dans `$chargesMutations` :
  `POST /appairage` et `POST /application/appareils/{id:[0-9]+}/revoquer`.

## Journal V2-08b — appairage, destinations, `.eml`

- Oracle d'abord, rouge : `tests/php/Jonctions/AppairageTest.php`, `CourrielTest.php`, `ContratCmmTest.php` écrits rouges
  (classe `Ged\Jonctions\Appairage` absente : 7 erreurs), puis verts — 14 tests, 136 assertions. Parcours Playwright
  connecté `tests/e2e/jonctions.spec.ts` (2 parcours) : appairage → échange du code (rôle CMM) → écran Appareils reliés
  → révocation ; et refus 403 sans session.
- Fait (section 1) : `GET /appairage` (écran au système visuel existant, session normale), `POST /appairage` (acceptation,
  CSRF), code à usage unique 5 min (`jonctions_codes_appairage`, empreinte sha256, revendication atomique), échange
  `POST /api/v1/appairage/jeton`, statut `GET /api/v1/appairage/moi`, révocation `DELETE /api/v1/appairage/moi`, écran
  Application › Appareils reliés (liste + révoquer). Le jeton est LIÉ AU COMPTE (`compte_id`/`compte_identifiant` sur
  `jonctions_jetons`), haché, révocable ; jeton inconnu/révoqué → 401 nommé, code inconnu/expiré/rejoué → 400 nommé.
- Fait (section 2) : `GET /api/v1/dossiers?parent=` ne liste QUE les dossiers ACL où le compte peut écrire
  (`Ged\Securite\DroitsDossiers`, héritage parent, refus par défaut) ; `POST /api/v1/depots` accepte
  `destination=ingestion|dossier`, `dossier`, `origine` ; `destination=dossier` passe par `Ged\Contrats\Depot::deposer`
  (statut `a_controler`, chemin conservé) et refuse 403 nommé sans droit d'écriture. Idempotence par empreinte dans les deux.
- Fait (section 3) : `.eml` accepté comme document ; `Ged\Jonctions\Courriel` (zbateson/mail-mime-parser) lit les en-têtes
  et le corps (texte indexé parce que porté par le `.meta.json`), produit un aperçu HTML NETTOYÉ (scripts et éléments
  distants retirés, tous attributs supprimés, images remplacées par leur texte alternatif) et liste les pièces jointes.
  `origine` écrite dans le `.meta.json` (dépôt direct : `origine` ; chaîne d'ingestion : `origine.courriel`).
- Ajouts minimaux signalés (fichiers sans modification concurrente, commités par DeepCode) : `src/Socle/DepotFichiers.php`
  (clé `dossier`), `src/Ingestion/Pilote.php` (origine CMM), `src/Apercu/{Source,Module,Piece}.php`,
  `templates/partiels/visionneuse.twig`, `public/assets/js/visionneuse.js`, `templates/ecrans/application.twig`,
  `tools/banc/contrat.php`.
- Preuves : `vendor\bin\phpunit tests\php\Jonctions` vert (14 tests, 136 assertions) ; suite PHPUnit complète verte
  (262 tests, 1254 assertions) ; `php tools\banc\contrat.php` vert ; `php tools\banc\sonde-securite.php` vert ;
  `npx playwright test tests/e2e/jonctions.spec.ts` vert (2 parcours) ; aperçu Playwright isolé vert (7 parcours).
  Harness `-SansBanc` : couches 0, 1, 2, 6 et 7 VERTES ; couche 3 volontairement sautée ; couche 4 rouge sur
  `apercu`/`bibliotheque`/`maquettes` (seuil de première page PDF sous charge ; 404 d'aperçu de l'oracle Bibliothèque)
  — vérifié hors secteur par `git stash` des ajouts transverses : `bibliotheque.spec.ts` échoue identiquement sans mes
  modifications ; défaut déjà consigné (V2-07c bis, V2-08a).
- Commit : `d9d49fe` `jonctions: appairage, destinations et .eml (V2-08b)`.

ETAT: fini
