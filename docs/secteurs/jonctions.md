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
- À l'Ingestion : une source `SourceApi` qui écrit dans la zone de travail et passe par `Travaux::recevoir`.
- Aux Droits : jetons d'application (création, révocation, portée `depot`).
