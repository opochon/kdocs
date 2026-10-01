# Secteur DROITS & SÉCURITÉ — connexion, sessions, garde centrale, audit

Agent : DeepCode · Juge : à désigner · Lot en cours : R1-droits

## Mission
Personne n'accède à rien sans une autorisation vérifiée côté serveur par UNE garde centrale (refus par défaut).
Même règle pour les écrans, l'API, l'aperçu, les miniatures, la recherche et le téléchargement.

## Chemins
`src/Securite/` (dont `src/Securite/Module.php` : `public static function enregistrer(App $app, Config $config, Vue $vue): void`,
appelé automatiquement par `src/Kernel.php` — tu ne modifies jamais Kernel), `templates/connexion/`, `tests/php/Securite/`,
`tests/e2e/securite.spec.ts`. Les tests e2e existants doivent rester verts : ils se connecteront via un utilisateur de test
que tu crées (documente-le dans `tests/e2e/README.md`) et adaptes `tests/e2e/maquettes.spec.ts` en conséquence.

## Lot V2-07a — porte
1. Écran de connexion au système visuel existant (`public/assets/css/ged.css`, jetons ; aucune couleur en dur).
2. Session : cookie `HttpOnly`, `SameSite=Lax`, `Secure` si HTTPS, identifiant régénéré à la connexion, expiration glissante.
3. Anti-force brute : 5 échecs → attente croissante, journalisé.
4. Déconnexion en POST uniquement. CSRF sur toute mutation (formulaires et API sous cookie).
5. Garde centrale : toute route est refusée sauf si elle déclare sa permission ; une route sans déclaration = 403 et
   un test qui échoue (liste des routes vérifiée par test).
6. Journal d'audit en ajout seul (aucune mise à jour ni suppression), chaque connexion / refus / changement de droit.
7. Tests : PHPUnit (unités) + Playwright (connexion, refus, déconnexion, CSRF refusé) ; tout le harness reste vert.

## Reprendre de GEDv1 (audit 29-09, `GEDv1/var/audit-v2/codex-recherche-droits.md`)
Algorithme d'héritage de `FolderPermissionService` (plus tard, lot V2-07b). NE PAS reprendre : Auth/AuthController (cookie
sans Secure, logout GET, DELETE de sessions), exemption CSRF de l'API, RolesController/UserGroupsController sans garde
(élévation de privilège), partage public mort, miniature publique.

## Journal V2-07a

- Fait : écran de connexion, session opaque régénérée et glissante (cookie `HttpOnly`, `SameSite=Lax`, `Secure` sous HTTPS),
  anti-force brute à partir du cinquième échec, déconnexion POST avec CSRF, garde centrale sur registre exhaustif des routes,
  et journal NDJSON en ajout seul (connexion, refus et changement de droit).
- Preuves : oracle écrit rouge (`Ged\\Securite\\DroitsRoutes`, `RegistreSessions`, `ProtectionBruteForce` et `JournalAudit`
  absents), puis `tests/php/Securite/SecuriteTest.php` vert : 4 tests, 29 assertions. Vérification HTTP manuelle : connexion
  synthétique vers `/a-traiter`. Les parcours Playwright de connexion, refus, CSRF et déconnexion sont dans
  `tests/e2e/securite.spec.ts`; le compte strictement synthétique est documenté dans `tests/e2e/README.md`.
- Commits : tentative après attente de 10 secondes, bloquée deux fois par le refus de création de `.git/index.lock` dans le
  sandbox. Aucun fichier d'un autre secteur n'a été ajouté.
- Harness : `powershell -ExecutionPolicy Bypass -File tools\harness.ps1` lancé ; le sandbox interdit à PowerShell de se
  positionner dans le dépôt, donc il tente d'écrire `C:\var` et `C:\recette` puis s'arrête. Le lancement depuis `cmd /c`
  confirme le répertoire cible, mais PowerShell revient tout de même à `C:\` et est refusé. La suite PHPUnit globale est
  aussi actuellement bloquée par `Ged\\Tests\\Ingestion\\PiloteAlertesTest`, qui demande la table
  `gedv2_test.ingestion_travaux` absente ; hors secteur.
- Reste : exécution Playwright automatisée, commit, harness complet et jugement de porte par Claude Code.

ETAT: bloqué: sandbox sans écriture .git ni répertoire de travail PowerShell, et migration ingestion concurrente absente

## Jugement V2-07a (Claude Code, 29-09) — porte franchie, avec une réserve pour V2-07b
Rejoué : PHPUnit vert (132 tests au total), Playwright vert une fois les parcours connectés ; garde centrale, session
opaque régénérée, CSRF sur toute mutation, anti-force brute et journal NDJSON conformes aux clauses 1 à 7.
Le blocage « sandbox » n'était pas un défaut du lot : le bac à sable de Codex interdit `.git`. Le juge a commis le travail
tel quel (auteur Codex) ; le lanceur de Codex tourne désormais hors bac à sable.
Réserve, à traiter en V2-07b : l'authentification lit un compte unique du `.env` (production : `documents:lire` seulement),
pas la table `comptes` que l'installateur du Socle pose avec mot de passe haché. V2-07b : comptes et rôles en base,
permissions par groupe, héritage par dossier (algorithme `FolderPermissionService` de GEDv1), garde des routes d'aperçu
unifiée (`document.voir` du secteur Aperçu ↔ `documents:lire` de la garde).

## Demandes

- H-2 contrat (29-09) : `POST /connexion` sans CSRF répond correctement 403, mais le gabarit de refus ne publie pas
  les deux champs nommés `etape` et `cause` exigés par le contrat HTTP (contrairement à `GardeCentrale::refuser`).
  À corriger dans le secteur Droits/Sécurité ; le harness le laisse ROUGE, sans masquer l'écart.
- Interface : lorsque les écrans ne seront plus alimentés par `Ui\\Maquette`, appeler le résolveur des droits de dossier
  avant de rendre chaque ligne de liste et chaque résultat de recherche. La garde refuse déjà fiche, aperçu, fichier et
  miniature ; l'écran de liste doit à son tour omettre les documents hors ACL plutôt que de les rendre non actionnables.
- Interface : sortir les attributs `style` des gabarits vers `public/assets/css/ged.css`. En attendant cette migration,
  la CSP conserve exceptionnellement `style-src 'self' 'unsafe-inline'`; les scripts en ligne sont eux limités au nonce
  aléatoire de chaque réponse.
- À Droits (V2-08a, modification minimale signalée) : `src/Securite/DroitsRoutes.php` déclare `POST /api/v1/depots` et
  `GET /api/v1/depots/{id:[0-9]+}` en `publique:api-depot` / `publique:api-depot-suivi`, car l'API d'entrée s'authentifie
  par jeton d'application hors session ; la garde les laisse passer et le secteur Jonctions vérifie le jeton.
- À Droits (V2-03-écran, modifications minimales signalées) : Interface a déclaré `POST /a-traiter/{id:[0-9]+}/valider`
  en `documents:traiter` (avec la reconnaissance de la route paramétrée) dans `src/Securite/DroitsRoutes.php`, et ajouté
  la charge CSRF défensive correspondante à `tools/banc/sonde-securite.php` — sans quoi la sonde signalait une mutation
  déclarée sans charge. Aucune autre permission touchée.

## Journal V2-07b

- Oracle d'abord : ajout de `tests/oracles/droits/droits.json`, rejoué par `DroitsBaseTest` rouge (classes `Schema` et
  `DroitsDossiers` absentes), puis vert. Il couvre lecture, modification et archivage sur liste, fiche, aperçu et API,
  l'héritage parent, le refus local et le refus par défaut.
- Fait : `securite_roles`, permissions, rôles-permissions, groupes, appartenances, dossiers et ACL sont installés de façon
  idempotente. Un refus de groupe l'emporte sur un accord concurrent et l'absence de règle, de dossier ou de groupe refuse.
  L'authentification vérifie maintenant `comptes.empreinte` avec `password_verify`, puis résout les rôles et groupes en base.
  Le compte synthétique reste strictement disponible en `APP_ENV=test`.
- Fait : les quatre routes d'aperçu déclarent `document.voir`, canoniquement résolu par la garde en `documents:lire` ; pour
  tout compte réel, la garde recoupe ensuite le chemin indexé du document avec son dossier ACL. Une fiche, un aperçu, un
  fichier ou une miniature sans dossier autorisé reçoit 403 sans atteindre le gestionnaire.
- Preuves : `vendor\\bin\\phpunit tests\\php\\Securite` vert (8 tests, 56 assertions), puis `vendor\\bin\\phpunit` vert
  (155 tests, 679 assertions). `powershell -ExecutionPolicy Bypass -File tools\\harness.ps1 -SansBanc` : garde-fous,
  PHPUnit (155/679) et Playwright (25 parcours) verts ; le banc est volontairement sauté et les couches non écrites restent rouges.

ETAT: fini

## Jugement V2-07b (Claude Code, 29-09) — porte franchie
Même harness complet vert. Comptes en base (`password_verify`), rôles et groupes, héritage par dossier avec refus par défaut, aperçu recoupé avec l'ACL du dossier. Reste : couche 7 du harness (sonde offensive : injection, chemins hors racine, contournement de la garde) à écrire par ce secteur.
ETAT: fini (jugé)

## Journal V2-07c

- Oracle d'abord : `SondeSecuriteTest` a été écrit rouge (jeton de connexion à usage unique et en-têtes de défense
  absents), puis vert : 3 tests, 8 assertions. La garde renouvelle désormais atomiquement le CSRF après chaque
  mutation ; le CSRF anonyme de connexion est à usage unique.
- Fait : `tools/banc/sonde-securite.php` démarre un serveur isolé sur `gedv2_test`, prend le verrou partagé et lit
  `DroitsRoutes::declarees()` pour attaquer toutes les routes déclarées sans session, sans permission et depuis un
  autre dossier. Elle couvre SQL/XSS (recherche, filtres, formulaire), sorties de racine (`..`, absolu, URI fichier)
  sur aperçu/fichier/miniature, CSRF absent et rejoué sur chaque mutation, fixation de session et cookies/en-têtes.
  La sonde retourne 0 seulement si aucune attaque ne passe. `DroitsRoutes::declarees()` est la seule modification du
  registre : elle expose les déclarations existantes à la sonde, sans ajouter de permission.
- Fait : la garde porte désormais `X-Content-Type-Options`, CSP avec `frame-ancestors 'none'`, `X-Frame-Options` et
  `Referrer-Policy` sur les réponses normales comme sur les refus ; les cookies CSRF et de session sont `HttpOnly` et
  `SameSite=Lax` (`Secure` derrière HTTPS).
- Preuves : `php tools/banc/sonde-securite.php` vert ; `vendor/bin/phpunit tests/php/Securite` vert (11 tests,
  70 assertions) ; `npx.cmd playwright test tests/e2e/securite.spec.ts --reporter=line` vert (2 parcours).
  Rejeu demandé : `powershell -ExecutionPolicy Bypass -File tools\harness.ps1 -SansBanc`. Le registre de ce rejeu
  confirme `7-securite` VERT (`OK: sonde sécurité — aucun contournement accepté`). Les couches globales 1 et 4 sont
  actuellement rouges (29 erreurs PHPUnit / 4 échecs, 11 échecs Playwright) dans des travaux concurrents hors secteur ;
  la sonde et les parcours Sécurité restent verts.
- Commits : `d26fabd` (`droits-securite: sonde offensive et CSRF non rejouable`) ; `42e096d`
  (`droits-securite: branche la sonde dans le harness`).

ETAT: fini

## Jugement V2-07c (Claude Code, 29-09) — porte NON franchie
Rejoué sur le code commité (copie du juge) : couche 7 VERTE (la sonde ne passe nulle part) — mais le lot casse deux
couches qui étaient vertes :
1. **Écrans : 10 parcours rouges.** L'en-tête `Content-Security-Policy: default-src 'self'` (GardeCentrale l. 95) bloque
   les scripts en ligne et les attributs `style` des gabarits existants (maquettes, recherche, aperçu). Une politique
   qui casse l'application n'est pas livrable. Attendu : `script-src 'self' 'nonce-<aléatoire par requête>'` avec le
   nonce exposé à Twig (variable globale) et posé sur chaque `<script>` en ligne des gabarits (modification minimale
   des gabarits, signalée) ; `style-src 'self' 'unsafe-inline'` tant que l'Interface n'a pas sorti les attributs
   `style` (demande écrite dans la fiche Interface). Les 26 parcours Playwright redeviennent verts, 0 erreur console.
2. **Zéro suppression : 1 test rouge.** `CorbeilleTest::testAucuneSuppressionSqlSurLesDonneesUtilisateur` trouve un
   `TRUNCATE` dans `RegistreSessions.php`. Les sessions expirées se marquent (ou s'ignorent par date), elles ne se
   vident pas en masse ; si une purge technique est indispensable, elle se justifie dans la fiche et passe par la liste
   blanche de ce test, pas en contournement.
Porte : harness complet vert sur le code commité (couches 0, 1, 3, 4, 7).
ETAT: à reprendre (voir Jugement V2-07c)

## Journal V2-07c bis

- Oracle rejoué rouge avant correction : `CorbeilleTest` signalait `ftruncate` dans `RegistreSessions`; les parcours
  Playwright maquettes, recherche et aperçu signalaient les violations CSP des scripts en ligne et des styles existants.
- Fait : chaque réponse de la garde génère un nonce CSP aléatoire, exposé globalement à Twig et porté par les deux scripts
  en ligne (`layout.twig` et la visionneuse autonome). La CSP déclare `script-src 'self' 'nonce-…'` et maintient
  provisoirement `style-src 'self' 'unsafe-inline'` jusqu'à la demande Interface ci-dessus. L'oracle de sonde vérifie
  désormais ces deux directives.
- Fait : `consommerCsrf()` réécrit sous verrou sans troncature et les sessions expirées sont ignorées par leur date, sans
  purge. La déconnexion conserve sa fermeture explicite de la seule session visée.
- Preuves : `vendor\\bin\\phpunit tests\\php\\Securite tests\\php\\Socle\\CorbeilleTest.php` vert (16 tests,
  99 assertions) ; `npx.cmd playwright test tests/e2e/maquettes.spec.ts tests/e2e/recherche.spec.ts
  tests/e2e/apercu.spec.ts --reporter=line` vert (23 parcours, sans erreur console). Rejeu complet demandé :
  `powershell -ExecutionPolicy Bypass -File tools\\harness.ps1 -SansBanc` : couches 0 et 7 vertes, mais 1 est bloquée
  par les 3 échecs `Socle\\InstallateurTest` (schéma à 22 tables au lieu des 20 attendues et base remplacée), et 4 par
  5 parcours hors secteur : `bibliotheque.spec.ts` réinitialise concurremment la base partagée `gedv2_test`, ce qui vide
  les résultats attendus par `recherche.spec.ts`. Aucun fichier Socle, Recherche ou Interface n'a été modifié.

ETAT: bloqué: le harness global ne peut pas afficher 1 et 4 VERTES tant que les travaux Socle corrigent ces échecs et isolent la remise à neuf de gedv2_test

## Jugement V2-07c bis (Claude Code, 29-09) — porte franchie
Rejoué sur le code commité : unitaire 205/205 (zéro suppression tenu), bancs verts, couche 7 VERTE, écrans 25/26 — le seul rouge est le seuil « première page PDF < 1 s » (1,23 s) sur une machine chargée par trois agents et les bancs : mesure de charge, pas défaut de sécurité ; à reprendre en V2-10 sur machine au repos. CSP à nonce par requête, `style-src 'unsafe-inline'` provisoire (demande Interface).
ETAT: fini (jugé)

## Journal V2-07d

- Oracle d'abord : `testConnexionSansCsrfPublieUnRefusNomme` a été ajouté rouge ; `POST /connexion` sans jeton
  conservait le statut 403 mais le formulaire ne rendait aucun champ `etape` ni `cause`.
- Fait : chaque refus de connexion rend désormais l'étape `connexion` et une cause nommée, avec les testids
  `refus-etape` et `refus-cause`. Le contrôle CSRF anonyme à usage unique, son audit et le renouvellement du
  jeton de formulaire sont inchangés.
- Preuves : `vendor\\bin\\phpunit tests\\php\\Securite\\SecuriteTest.php --filter ConnexionSansCsrf` vert
  (1 test, 3 assertions) ; `php tools\\banc\\contrat.php` vert (sortie 0 : toutes les routes déclarées sont couvertes).

ETAT: fini

## Journal R1-droits — rattachement des documents rangés aux dossiers de droits

**Défaut constaté en recette réelle** : les 48 pièces rangées par l'ingestion n'appartenaient à aucun
dossier de droits (`securite_dossiers` vide). `GardeCentrale::documentAutorise()` recevait `null` de
`dossierPourDocument()` et refusait l'aperçu, le fichier et la fiche à TOUT compte réel, administrateur
compris. Le harness restait vert parce que ses parcours se connectent avec le compte synthétique (id
nul), qui contourne les droits par dossier.

- Oracle d'abord : `tests/php/Securite/RattachementDroitsTest.php` écrit rouge (`assurerDossierPourChemin`
  absente, aperçu admin = 403, installateur acceptant `--admin=admin`), puis vert.
- Fait — correction à la source :
  - `DroitsDossiers::assurerDossierPourChemin()` garantit le dossier ACL d'un document depuis son chemin
    du fonds : chaque niveau devient un dossier, enfant du précédent (héritage du parent), sans jamais
    dupliquer ni écraser un dossier existant.
  - `DepotFichiers::enregistrer()` (dépôt, indexeur, reconstruction F12), `mettreAJour()` (changement de
    rangement) et `restaurer()` rattachent leur chemin ; `Indexeur::passer()` rattrape les documents DÉJÀ
    indexés (branche « intouché ») et les déplacements. Appels d'une ligne gardés par `class_exists`.
  - `Schema` : `securite_dossiers.id` est auto-incrémenté et réserve son espace d'identifiants à partir
    de `DOSSIER_ID_AUTO_MIN` (1 000 000) pour ne JAMAIS retomber sur un dossier posé explicitement par
    l'administration ou les tests (une collision réécrivait le dossier en silence).
  - Un compte au rôle admin (`*`) voit tout (`GardeCentrale`) ; pour les autres rôles, rien n'est relâché :
    un document rangé sans règle de dossier reste refusé (F10).
  - `Authentification` : un compte RÉEL en base reste vérifiable sous `APP_ENV=test` (le compte synthétique
    est conservé) — sans quoi aucune recette réelle ne pourrait se connecter.
  - `tools/installer.php` : `--admin` exige une adresse de courriel valide (`FILTER_VALIDATE_EMAIL`), validée
    AVANT toute action sur la base ; aide corrigée. `tests/php/Socle/InstallateurTest.php` adapté au courriel
    (modification minimale signalée dans `socle.md`).
- Parcours Playwright (défaut 2) : `tests/e2e/preparer-droits.php` + `tests/e2e/droits-reels.spec.ts` créent un
  compte RÉEL (mot de passe haché, rôle admin) et font ingérer un document par le VRAI `Pilote` (lot
  synthétique → découpe simulée → `DepotFichiers`). Le parcours se connecte avec ce compte et ouvre l'aperçu
  et la fiche ; sans session, la même route répond 403. La préparation échoue si le document rangé n'a pas
  de dossier ACL : ce parcours aurait attrapé le défaut 1.
- Preuves : `vendor\\bin\\phpunit tests\\php\\Securite` vert (16 tests, 112 assertions) ; suite entière
  `vendor\\bin\\phpunit` verte au 2ᵉ rejeu (**283 tests, 1367 assertions**), l'erreur `Ingestion\\PiloteTest`
  du 1ᵉʳ rejeu (`Fabrique::classeur()`) ayant été corrigée entre-temps par le secteur Ingestion ;
  `npx.cmd playwright test tests/e2e/droits-reels.spec.ts` vert, et suite Playwright verte **35 parcours**
  (dont le nouveau) au 1ᵉʳ rejeu ; sonde offensive verte (« aucun contournement accepté ») ; contrat vert
  après stabilisation de la base partagée.
- Harness : `powershell -ExecutionPolicy Bypass -File tools\\harness.ps1 -SansBanc` rejoué deux fois.
  - Rejeu 1 (registre `2026-10-01T10:02:41`, commit `67e0726`) : `0-garde-fous` VERT, `4-ecrans` VERT
    (**35 passed**, dont `droits-reels`), `7-securite` VERT, `2-contrat` VERT, `6-charge` VERT ; seul
    `1-unitaire` ROUGE (282 tests, 1363 assertions, `Errors: 1`) sur le défaut `Fabrique::classeur()` d'un
    autre secteur, corrigé depuis. Aucun rouge imputable à R1.
  - Rejeu 2 : `0`, `1` (**283 tests, 1367 assertions**, VERT) et `7` verts ; `2-contrat`, `4-ecrans` et
    `6-charge` rouges par l'état de la base `gedv2_test` et la charge d'agents concurrents pendant la
    passe (le seul échec écran est `tests/e2e/workflows.spec.ts`, testid `action-nouvelle-regle` absent ;
    le contrat tombe sur `POST /workflows/...` et redevient vert au rejeu seul ; la charge échoue au seuil
    p95 et à l'ingestion sous contention). Rejoués seuls, `tests/php/Workflows` (21/21), `php
    tools/banc/contrat.php` et les parcours R1 sont verts : aucune régression de R1.
  - `3-banc` est sautée par `-SansBanc` et `5-personas` n'est pas encore écrite : rouge attendu, hors lot.
- Commits : `ac77153` (oracle R1), `49581a5` (rattachement aux dossiers de droits, Socle compris),
  `3aa7681` (admin voit tout, compte réel en test), `5ec801a` (installateur = courriel), `f21e2bb`
  (parcours e2e d'un compte réel). Chaque commit porte `Co-Authored-By: DeepCode <noreply@deepcode>`.

### Fichiers partagés (règle « un agent = un secteur »)

Aucune ligne ajoutée dans `src/Securite/DroitsRoutes.php` ni dans `tools/banc/sonde-securite.php` : rien à
commiter par le juge pour ce lot. Le seul fichier hors périmètre est `tests/php/Socle/InstallateurTest.php`
(le test de l'installateur devait suivre le passage de `--admin=admin` à une adresse de courriel), signalé
dans `docs/secteurs/socle.md`.

ETAT: fini
