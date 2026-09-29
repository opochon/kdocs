# Secteur JONCTIONS — K-Time, ClearMyMails, API d'entrée

Agent : DeepCode (V2-08a, API d'entrée) · Juge : Claude Code · Lot : V2-08

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
`src/Jonctions/`, `tests/php/Jonctions/`, `docs/api/`

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
