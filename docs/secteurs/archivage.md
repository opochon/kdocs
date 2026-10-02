# Secteur ARCHIVAGE LÉGAL — scellement, horodatage, rétention

Agent : DeepCode · Juge : Claude Code · Lot : V2-09

## Mission
Un document scellé devient intouchable pendant sa durée légale (10 ans par défaut, CO art. 958f ; OLICO pour la forme) :
- **Scellement** : empreinte sha256 du fichier et de son `.meta.json`, jeton d'horodatage RFC 3161 (TSA) sur cette
  empreinte, le tout écrit dans le dossier (`.scellement/<fichier>.tsr` + `.json`) — le dossier fait foi.
- **Intouchable** : toute écriture (mise à jour des métadonnées, nouvelle version, corbeille, déplacement) sur un document
  scellé est refusée **au niveau du service** (Socle `DepotFichiers`, corbeille, workflows), pas route par route — c'est
  le défaut exact de GEDv1 (5 routes gardées, le reste ouvert).
- **Vérification** : un outil rejoue l'empreinte et le jeton ; un fichier altéré hors application est signalé.
- **Rétention** : échéance calculée par type (table de durées modifiable), liste « à échéance », jamais d'effacement
  automatique — la fin de rétention propose, un humain décide (et le journal le trace).

## Chemins
`src/Archivage/`, `tests/php/Archivage/`, `tests/oracles/archivage/`, `tools/verifier-scellements.php`,
`tools/sceller.php`, `src/Ui/`, `templates/`, `public/assets/`, `tests/e2e/`, `.env.example`

## Demandes

- **À la vue d'ensemble (Kernel)** : `src/Kernel.php` liste en dur les secteurs dont il appelle le `Module`.
  `Archivage` n'y figure pas : mes routes ne sont donc pas enregistrées. Ajouter `'Archivage'` à la liste
  (une seule ligne), puis je poserai `src/Archivage/Module.php` avec ses permissions dans `DroitsRoutes`.
  En attendant, le scellement s'opère par le service (`Ged\Archivage\Scelleur`) et l'outil
  `tools/sceller.php` ; aucune route morte n'est déclarée.
- **À l'Interface** : bouton « Sceller » sur la fiche document (`data-testid="document-sceller"`) et un
  écran/liste « rétention » branché sur `Ged\Archivage\Retention::aEcheance()` et `::lever()` — le geste
  doit rester un acte humain tracé. Détails dans `docs/secteurs/interface.md`.
- **Au Socle** : la garde d'écriture est branchée (appels d'une ligne signalés) dans
  `DepotFichiers::mettreAJour()`, `DepotFichiers::miseALaCorbeille()` et `Indexeur::passer()`.
  À confirmer au prochain lot Socle ; détails dans `docs/secteurs/socle.md`.
- **Au Harness** : `TSA_URL` est absente de `.env.example`. La ajouter (vide, commentée « TSA RFC 3161
  réelle ; simulée en test ») pour que la recette sache quoi renseigner.
- **Aux Droits** : aucune permission nouvelle tant que le Kernel n'appelle pas `Archivage\Module`
  (`archivage:sceller`, `archivage:gerer`, `archivage:lire` à déclarer alors).


## Lot V2-09 — porte (F11)
1. Sceller un document du corpus → jeton TSA vérifiable (TSA simulée en test, réelle en recette via `TSA_URL`).
2. Toute mutation d'un document scellé refusée et nommée, par TOUTES les voies (service, écran, API, workflow, indexeur
   si le fichier change sur disque → alerte « scellé altéré », jamais nouvelle version silencieuse).
3. `tools/verifier-scellements.php` : 0 écart sur un fonds sain ; l'écart est trouvé si un octet change.
4. Rétention : échéances justes sur horloge figée ; aucune suppression.

## Reprendre de GEDv1 (lecture seule, audit `GEDv1/var/audit-v2/deepcode-stockage.md`)
`app/Services/Compliance/TsaService.php` (client RFC 3161 réel et testé, jamais câblé : le câbler),
`RetentionPolicyService` (table de durées), l'idée de `LegalArchiveService` — mais la garde au niveau du service.

## Journal V2-09 — 29-09-2026

**Oracle d'abord**
- `tests/php/Archivage/` (SupportArchivage + TsaTest, ScellementTest, GardeTest, VerificateurTest, RetentionTest),
  écrits AVANT le code : vu rouge « Class Ged\Archivage\… not found » (26 erreurs), puis vert après implémentation.
- `tests/oracles/archivage/porte-v2-09.php` rejoue les quatre clauses, sans PHPUnit.

**Fait, clause par clause**
1. **Scellement horodaté, dans le dossier.** `Ged\Archivage\Scelleur` calcule le sha256 du fichier ET de son
   `.meta.json`, scelle l'empreinte composite et demande un jeton RFC 3161 à un `Horodateur` injectable
   (`TsaReelle`, client DER réel avec transport curl injectable ; `TsaSimulee` en test, aucun réseau).
   Justificatif + jeton écrits à côté du document : `.scellement/<fichier>.json` + `.tsr`. Idempotent ; un
   scellement levé puis re-scellé archivera l'ancien justificatif en copie (jamais de suppression).
2. **Intouchable au niveau du service.** `Ged\Archivage\Garde` est branchée par appels d'une ligne **signalés**
   dans `DepotFichiers::mettreAJour()` (erreur 403 nommée : opération, document, échéance), `miseALaCorbeille()`
   (corbeille refusée) et `Indexeur::passer()`. Comme les actions de workflow passent par
   `Depot::mettreAJour()`, elles couvrent aussi « écran, API, workflow ». L'indexeur, lui, constate un fichier
   changé sur disque sans archiver de version : `Garde::constaterAlteration()` écrit une alerte
   « scellé altéré » dans `.scellement/alertes.ndjson` et le passage rend `scelles_alteres`.
3. **Vérificateur.** `tools/verifier-scellements.php` rejoue, dossier seul et sans réseau, empreinte du
   fichier, empreinte de la meta, empreinte composite et jeton (structure DER, statut granted, empreinte
   couverte, sha256 du jeton) : 0 écart sur un fonds sain (code 0), l'octet changé est trouvé et nommé (code 1).
4. **Rétention.** Durées par type (CO art. 958f, 10 ans par défaut) modifiables dans
   `<fonds>/.archivage/durees.json` ; échéance = date du document (sinon instant du scellement) + durée.
   `Retention::aEcheance()` liste à échéance, `::lever()` marque le justificatif (`leve_le`/`leve_par`) et
   journalise l'acte : **aucun effacement automatique**, un humain décide.

**Preuves**
- `php vendor/bin/phpunit tests/php/Archivage` : **26 tests, 113 assertions, vert**.
- `php tests/oracles/archivage/porte-v2-09.php` : **7 étapes OK, 0 échec, code 0** (TSA simulée en test).
- Outil sur fonds sain : `VERDICT : 0 écart sur 1 scellement(s)` (code 0) ; après un octet changé :
  `VERDICT : N écart(s) …` (code 1), écart nommé « empreinte du fichier différente ».

**Harness `-SansBanc` (ce dépôt, après mes commits)**
- 0-garde-fous VERT ; 1-unitaire ROUGE : 234 tests, **33 échecs tous du secteur Socle V2-04 en cours d'un
  autre agent** (`ChampsTest`/`NomenclatureTest`/`VuesTest` : classes à venir ; `CorbeilleTest` : TRUNCATE
  non commité de `Securite\RegistreSessions` ; `InstallateurTest` : 22 tables > 20) — **0 échec Archivage** ;
  4-écrans ROUGE (11/16, CSP inline-script non commité d'un autre secteur + seuil d'aperçu sous charge) ;
  7-sécurité VERT ; 3/2/5/6 ROUGE (banc sauté, couches non écrites).

**Commits**
- `d1736a9` — scellement horodaté (TSA), rétention, service de scellement, tests et `tools/sceller.php`.
- `2eccee4` — garde du service, vérificateur, outil, porte V2-09, branchements Socle et demandes.
- `ac96261` — durcissement du vérificateur (fichier ou meta disparus = écart nommé, jamais une exception).

**Décisions retenues**
- **Aucune table** : le scellement, le jeton et la table des durées vivent dans le dossier ; rien à
  reconstruire en base (`Archivage\Schema::installer()` est volontairement vide).
- La garde lit le dossier par le nom du document, avec un repli par `document_id` du justificatif : un
  renommage hors application ne fait pas disparaître le scellement.
- La TSA réelle est câblée (`TSA_URL`) mais jamais appelée en test : transport injecté, `TsaSimulee`.

**Dettes / à suivre (signalées, non bloquantes)**
- Routes et UI : `Kernel` liste les secteurs en dur, `Archivage` doit y être ajouté (demande déposée) ;
  ensuite `Archivage\Module` + bouton « Sceller » + écran rétention (demande à l'Interface, aux Droits).
- `TSA_URL` à documenter dans `.env.example` (demande au Harness).
- La signature PKI de la TSA n'est pas vérifiée cryptographiquement (`openssl ts`) : contrôle structurel seulement.
- Un renommage hors application d'un document scellé est constaté par l'indexeur (déplacement tracé) ; le
  rapatriement automatique du dossier `.scellement/` reste à faire côté Socle.

ETAT: fini


## Jugement V2-09 (Claude Code, 29-09) — porte franchie
Rejoué sur le code commité : oracle `porte-v2-09.php` 7/7, 26 tests verts, bancs verts. La garde entre par des appels d'une ligne signalés dans `DepotFichiers` et `Indexeur` (Socle) : c'est le bon niveau. Les rouges du harness de ce passage viennent du lot V2-07c (Droits), pas de celui-ci. Reste : TSA réelle en recette (`TSA_URL`), écran de scellement et liste « à échéance » dans l'interface.
ETAT: fini (jugé)

## Journal V2-09b — archivage visible et utilisable — 02-10-2026

**Oracle d’abord**
- `tests/php/Archivage/InterfaceArchivageTest.php` a été posé avant le code et rejoué rouge :
  `Ged\\Archivage\\EcranArchives` et `Ged\\Archivage\\TacheVerification` absents.
- `tests/e2e/archivage.spec.ts` prépare un fonds et deux comptes **fictifs** dans `gedv2_test` ; la pièce
  passe par le vrai `Pilote`, puis est validée dans « À traiter », scellée, vérifiée saine, puis altérée
  d’un octet. Le parcours couvre bureau et iPhone 13 ainsi que le refus 403 d’un compte réel sans droit.

**Fait**
- `Archivage\\Module` expose `POST /documents/{id}/sceller`, `GET /archives` et
  `POST /archives/verifier`. Le scellement ne passe que pour un document `valide`; la confirmation dit
  explicitement qu’il est définitif pendant la rétention. Sans `TSA_URL` hors test, la fiche nomme
  « horodatage externe non configuré » et désactive l’acte au lieu d’échouer silencieusement.
- La fiche lit le justificatif dans le dossier : état non scellé/scellé, instant, jeton TSA et échéance.
  L’écran Archives lit les seuls justificatifs actifs, filtre par type et échéance, et sa liste « À échéance »
  appelle `Retention::aEcheance(90)` (dépassée ou dans les 90 jours), sans aucun effacement.
- La vérification est une tâche dans `<fonds>/.archivage/taches/` : chaque reprise traite au plus cinq
  justificatifs et moins d’une seconde. Les écarts restent nommés (fichier modifié, meta absente, jeton…).
- `Scelleur::depuisConfig()` choisit obligatoirement `TsaSimulee` en `APP_ENV=test`; `.env.example`
  documente `TSA_URL` pour la recette réelle.
- Mobile : cibles de commande 44 px, filtres et état en colonne, aucune largeur forcée ni libellé anglais.

### À commiter par le juge (fichiers partagés, non committés par l’agent Archivage)

- `src/Kernel.php` : ajout de `'Archivage'` dans la liste des modules.
- `src/Securite/DroitsRoutes.php` : `GET /archives` et `POST /archives/verifier` →
  `archivage:consulter`; `POST /documents/{id:[0-9]+}/sceller` → `archivage:sceller`, avec sa résolution
  paramétrée.
- **Demande à Droits/Sécurité** : ajouter ces deux permissions au catalogue et donner
  `archivage:consulter` / `archivage:sceller` au rôle `archiveur` (l’administrateur `*` les porte déjà).

ETAT: en cours — rejoues unitaires et Playwright attendus
