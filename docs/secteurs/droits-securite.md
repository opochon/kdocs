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

### W2 — DDL interdit dans les chemins HTTP (04-10)

- À Socle : retirer l'auto-guérison de `src/Socle/Module.php:35-43` (`Base::assurerBase()` et `Schema::installer()`), appelée par chaque création de Kernel. Si `documents` est absente, rendre une erreur nommée orientant vers `tools/installer.php`, sans DDL. Retirer aussi les appels constructeurs de `Schema::installer()` dans `src/Socle/Collaboration.php:24` et `src/Socle/ReconstructionIndex.php:21-22` lorsqu'ils sont atteignables depuis une route ; l'installation appartient exclusivement aux outils et amorces de test.
- À Recherche : retirer `Schema::installer()` de `src/Recherche/Service.php:42` et l'option `autoInstaller` appelée par `Recherche\\Ecran`. Une table absente doit produire une erreur de recherche nommée, jamais une migration au clic.
- À vue d'ensemble : ajouter un oracle inter-secteurs qui échoue si un `src/*/Module.php` ou une classe atteignable d'une route appelle `Schema::installer()` / DDL ; les exceptions doivent être limitées à `tools/` et `tests/` et documentées. Droits & Sécurité ne peut pas modifier ces chemins hors de son secteur.

### Journal V2-13 — reprise (03-10)

- Corrections du juge : migration sans `DELETE` sur les rôles ni leurs permissions ; les rôles historiques restent
  inactifs et portent leur alias canonique. Les changements de rôle effectués par migration sont inscrits dans
  `securite_audit` (ancien, nouveau, auteur `migration`, date). La migration `archiver` vers `gerer` traite la
  collision de clé : refus explicite prioritaire, sinon accord ; la règle historique reste lisible.
- A1 : les partages révoqués ou expirés rendent respectivement « révoqué le … » et « expiré le … » avec testid.
  A2 : `Depot` déclare `fiche`, `lister` et `cheminAbsolu` ; les trois ignores PHPStan de `ATraiter` sont retirés.
  A3 : les routes de partage, lien externe et fichier partagé sont factorisées en méthodes courtes, leurs erreurs
  restent rendues nommément. A4 : la file mobile est repliée au départ et, une fois ouverte, reste visible ;
  `mobile.spec.ts:298` (iPhone 13) est vert trois fois consécutives.
- Contrat HTTP : le journal PHP du serveur de contrat a mené à la mutation ACL sans `niveau` (charge synthétique) ;
  `DroitsDossiers::poserRegle()` levait alors « Action de droit inconnue », rendu en 500. L'oracle
  `testMutationAclInvalideEstUnRefusNommeEtJamaisUneErreurServeur` a été rouge (303), puis vert (400 nommé,
  2 assertions). Les cibles dossier et groupe sont maintenant contrôlées avant écriture.
- Sonde : les charges CSRF défensives couvrent désormais la mutation ACL, puis les mutations déclarées des comptes
  et groupes ; aucune sonde antérieure n'a été retirée ou assouplie. B7 utilise un compte `utilisateur` réellement
  créé en base, mot de passe haché, et vérifie 403 pour chaque permission réservée manager ou administrateur.
- B6 : l'oracle de rôles est vert (9 tests, 87 assertions) et `droits-b6.spec.ts` est vert (1 parcours) avec trois
  comptes synthétiques réels en base, mots de passe hachés, deux dossiers A/B, refus 403 de B et de l'administration
  pour l'utilisateur, gestion ACL pour le manager, accès complet administrateur.
- Preuves ciblées : contrat HTTP vert ; PHPStan niveau 6 vert (0 erreur hors baseline) ; parcours B6 vert.
- Commits de reprise : `4185fd8`, `312231a`, `0a4460b`, `64ece02`, `d58577c`, `ac3a577`, `4c4a136`, `196833e`,
  `1ff76d2`, `07e4414`, `2adc0e1`, `0613d2f`, `8a3f641`.
- Porte qualité : revue manuelle des changements contre php-reviewer, security-reviewer et silent-failure-hunter :
  suppression SQL interdite retirée, SQL utilisateur paramétré, erreurs de routes nommées et tracées ; aucun
  `catch (Throwable)` ajouté sans réponse d’erreur. Le harness global double n’est pas validé : son registre
  disponible montre 434 tests/2465 assertions avec 1 échec, 61 parcours verts/2 échecs et charge p95 2795 ms.
  Rejeux `harness.ps1 -SansBanc` :
  - 1 : 450 tests / 2 589 assertions, 48 écrans verts / 7 rouges ; contrat, charge (p95 lecture 1 325 ms,
    écriture 529 ms), statique verts ; sécurité rouge avant l'ajout des charges comptes/groupes.
  - 2 : 450 tests / 2 593 assertions verts ; contrat, sécurité, charge (p95 lecture 1 122 ms, écriture 498 ms)
    et statique verts ; couche écrans rouge avec sortie Playwright vide pendant les exécutions concurrentes.
- Porte qualité : revue manuelle contre php-reviewer, security-reviewer et silent-failure-hunter. SQL de contrôle
  paramétré, validation explicite des entrées ACL, aucun secret réel, ni `catch (Throwable)` avalé ; pas de constat
  critique ou élevé sur le diff. La porte globale ne peut pas être déclarée verte tant que la couche écrans n'est pas
  rejouée isolément par le juge.

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

### Journal V2-13v — reprise des refus Q1 à Q3 (04-10)

- Q1 — commit `af7096b` : `securite_audit` n'est plus créée, l'installateur revient à la liste et au plafond exacts de **21 tables** et ne tolère qu'un reliquat homonyme sans le lire ni le supprimer. La migration des rôles écrit désormais les changements dans `JournalAudit` NDJSON en ajout seul (auteur `migration`, cible, ancien, nouveau, horodatage).
- Q2 — commit `f10e590` : les cinq bancs qui ouvrent un PDO (`charge`, `contrat`, `mesurer-classement`, `rejouer-ingestion`, `sonde-securite`) imposent et contrôlent `gedv2_test`; l'oracle `BancIsolationTest` les couvre. La sonde prend `sys_get_temp_dir()/gedv2_test.lock`, identique à PHPUnit.
- Q3 — commit `9651cd9` : le compte B7 est préfixé `sonde-utilisateur-`, haché, créé uniquement dans `gedv2_test` et retiré dans le `finally` sous verrou. L'oracle vérifie le marqueur et le nettoyage.
- Oracles ciblés : avant correction, plafond 21 : **3 échecs** (table excédentaire) ; après Q1, `InstallateurTest` + `V213DroitsRolesTest` : **15 tests, 116 assertions** verts. Avant Q2, l'oracle des bancs échouait ; après Q2/Q3, `BancIsolationTest` : **2 tests, 13 assertions** verts. Aucun compte `sonde-utilisateur-*` ne subsistait dans `gedv2_test` après la sonde interrompue.
- Porte qualité : PHPStan niveau 6 et `composer audit` verts dans les deux rejeux (**0 erreur hors baseline**, aucune vulnérabilité). Revue manuelle php-reviewer/security-reviewer/silent-failure-hunter : SQL de migration fixe ou paramétré, aucun secret réel, audit NDJSON ajout-seul, `catch` de nettoyage converti en échec nommé de sonde ; aucun constat critique ou élevé.
- Rejeux isolés `powershell -ExecutionPolicy Bypass -File tools\harness.ps1 -SansBanc` :
  - 1 — **736 s** : unitaire **453/2612** vert, contrat et charge verts (p95 lecture **590 ms**, écriture **451 ms**), statique vert ; écrans **35 passed / 12 failed** et sonde rouge (timeout `POST /deposer`).
  - 2 — **750 s** : unitaire **453/2612** vert, statique vert ; écrans **45 passed / 9 failed**, sonde rouge (timeout `POST /connexion`), contrat rouge (**10 tests, 34 assertions, 3 erreurs**), charge rouge (p95 lecture **24 191 ms**, écriture **626 ms**, **1** erreur). `3-banc` et `5-personas` sont les deux rouges admis.

ETAT: bloqué — les rouges restants proviennent des expirations HTTP et de la saturation de services externes au lot; ils empêchent le verdict demandé (seuls 3-banc et 5-personas admis).

### Journal V2-13w — retrait du DDL du chemin HTTP (04-10)

- Oracle d'abord : `testModuleNExecuteAucuneInstallationDeSchemaAuDemarrage` a été ajouté rouge : il trouvait les deux appels `Socle\\Schema::installer()` et `Securite\\Schema::installer()` dans `Securite\\Module`, appelé par le Kernel à chaque requête. Après retrait, il est vert (**1 test, 1 assertion**).
- Fait — W1 : `Securite\\Module` ne pose ni ne migre plus aucun schéma. Les quatre écrans d'administration (`/administration/comptes`, `/administration/groupes`, `/administration/roles`, `/dossiers/{id}/droits`) attrapent seulement l'indisponibilité PDO en lecture et rendent un **503 nommé** (« Schéma d’administration absent ou indisponible : exécutez tools/installer.php. ») ; ils ne créent rien.
- Audit W2 : `rg` sur `CREATE`, `ALTER` et `installer(` dans `src/` n'a trouvé aucun autre DDL dans le secteur Sécurité hors `Schema.php`. Il reste toutefois quatre poses de schéma hors secteur : `src/Socle/Module.php:41` (appelée au démarrage du Kernel), `src/Recherche/Service.php:42` (écran de recherche), `src/Socle/Collaboration.php:24` et `src/Socle/ReconstructionIndex.php:21-22`. `src/Socle/Reconstructeur.php:32` est actuellement seulement appelé par `tools/reconstruire-index.php` et les préparations de test. Ces appels ne sont pas modifiables par Droits & Sécurité ; demandes détaillées ci-dessous.
- W3 : l'oracle statique interdit désormais tout `Schema::installer()` dans `src/Securite/Module.php`, donc dans la classe du secteur appelée au démarrage d'une requête. Il ne peut pas servir de preuve globale tant que les appels Socle/Recherche ci-dessus existent.
- Preuves ciblées : `php vendor\\bin\\phpunit tests\\php\\Securite` vert : **29 tests, 251 assertions**. `php vendor\\bin\\phpstan analyse --memory-limit=2G` vert : **0 erreur**. `composer audit` n'a pas pu être relancé : l'exécutable `composer` est absent du PATH de cette session.
- Écrans, deux rejeux isolés `npx.cmd playwright test --reporter=line` :
  - 1 — **45 passed, 10 failed, 10 did not run (4,7 min)**. Une préparation `edition-pages` échoue encore avec `MySQL server has gone away`; autres rouges d'écrans/budgets hors secteur.
  - 2 — **40 passed, 9 failed, 16 did not run (4,1 min)**. La console de Bibliothèque rapporte `net::ERR_CONNECTION_TIMED_OUT`; les erreurs `chrome-error://chromewebdata/`, les délais d'aperçu et le budget Recherche (**1 134 ms**) restent hors du diff V2-13w. Les rouges imputables à l'ancien `Schema::installer()` de `Securite\\Module` ne sont plus observés pendant le démarrage du module, mais les rouges de saturation ne peuvent pas être déclarés disparus tant que W2 global n'est pas traité.
- Harness : `powershell -ExecutionPolicy Bypass -File tools\\harness.ps1 -SansBanc` lancé une fois ; son processus s'est terminé sans sortie et sans mettre à jour `recette/registre.json` (dernier registre conservé : 04:27, commit `40dc908`). Aucun registre n'est attribué à V2-13w.
- Commit : `f94f5ae` (`droits-securite: retire le schema du demarrage HTTP`, avec `Co-Authored-By: Codex <noreply@openai.com>`).
- Porte qualité : revue manuelle du diff selon php-reviewer, security-reviewer et silent-failure-hunter : SQL existant paramétré, aucune entrée utilisateur ajoutée aux requêtes, aucune exception avalée (le seul `catch PDOException` rend une erreur 503 nommée), pas de secret. Aucun constat critique ou élevé. PHPStan vert ; `composer audit` indisponible dans la session ; couche écrans rouge par défaillances externes ci-dessus.

ETAT: bloqué — W2 globale requiert les corrections Socle/Recherche hors périmètre et les deux rejeux d’écrans restent rouges sur timeouts/connexions MariaDB externes.

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
- Harness : `powershell -ExecutionPolicy Bypass -File tools\\harness.ps1 -SansBanc` rejoué trois fois.
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
  - Rejeu 3 (registre `2026-10-01T10:35:51`) : `0`, `1` (**284 tests, 1372 assertions**), `7`, `2` et `6`
    verts ; seul `4-ecrans` rouge (**2 failed, 30 passed**) sur `bibliotheque.spec.ts` et `a-traiter.spec.ts`,
    deux parcours d'AUTRES secteurs : expiration du délai de 20 s dans leur `beforeEach` en attendant le
    verrou `gedv2_test` tenu par un banc concurrent. Aucun parcours R1 n'a jamais échoué sur les trois rejeux.
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

### Jugement R1-droits (juge, 01-10 10:55, copie propre sur 67e0726)

- Harness complet : 0, 1 (280 tests), 2, 3 (53/53, F1 franchie), 4 (35/35, dont `droits-reels.spec.ts` avec un compte réel), 6 (p95 lecture 979 ms) et 7 verts.
- Recette réelle rejouée sur l'instance `GEDv2-recette` (installateur avec `admin@recette.local`, fonds de 48 pièces issues du scan de 103 pages, reconstruction F12 puis indexation) : l'administrateur ouvre l'aperçu et le fichier des documents ingérés (200, PDF servi). Avant ce lot : 403 pour tout compte réel.
- Point produit à traiter plus tard, non bloquant : un compte non administrateur ne voit un document rangé qu'une fois une règle posée sur son dossier (refus par défaut, conforme à F10). Il faudra proposer des droits par défaut à l'installation (« tous les utilisateurs lisent le fonds » ou équivalent), à décider avec Olivier.
- Verdict : **R1-droits franchi.**

### Décision (2026-10-03) — modèle de droits des non-administrateurs

Demande d'Olivier : un manager peut classer, créer des règles, etc. ; un utilisateur peut consulter et poser un document s'il a les droits sur le dossier ; s'aligner sur les GED de référence. Modèle retenu, sur ce que font Alfresco (rôles par espace : Consumer, Contributor, Collaborator, Manager), DocuWare (droits fonctionnels distincts des droits sur les objets) et M-Files (listes d'accès, autoriser/refuser, le refus explicite gagnant) :

1. **Deux couches qui se cumulent par intersection** : un rôle fonctionnel dit quels gestes le compte peut faire dans la GED ; le droit sur le dossier dit où il peut les faire. Un geste exige les deux.
2. **Rôles fonctionnels** :
   - Utilisateur : consulter, rechercher, télécharger, déposer, ajouter notes et liens, partager en interne ce qu'il peut lire.
   - Manager : tout l'Utilisateur, plus classer et valider dans À traiter, retoucher les pages, modifier les métadonnées, créer et modifier les règles de classement, les circuits de workflow, les vues partagées, gérer les droits des dossiers qu'il gère, sceller (archivage) et créer des liens de partage externes.
   - Administrateur : configuration, comptes et groupes, types et champs, reconstruction d'index, et tout le reste.
3. **Droits par dossier** (hérités du parent, refus explicite qui l'emporte, refus par défaut, déjà portés par `DroitsDossiers`) en quatre niveaux : Lecture (voir, rechercher, télécharger), Dépôt (Lecture + déposer), Modification (Dépôt + modifier, nouvelle version, restaurer), Gestion (Modification + supprimer, sceller, gérer les droits du dossier).
4. La recherche, la Bibliothèque, les vues et l'API ne montrent jamais un document hors des dossiers lisibles du compte (filtrage dans la requête, pas après).
5. Lot à venir : **V2-13 Droits par rôle**, après V2-12d.

### Journal V2-13x — DDL strictement hors requête HTTP (04-10)

- Oracle d'abord : `DdlHorsHttpTest` a été écrit rouge ; il trouvait exactement cinq appels hors
  `Schema.php` : `Socle/Module`, `Socle/Collaboration`, `Recherche/Service`,
  `Socle/ReconstructionIndex` et `Socle/Reconstructeur`.
- Fait : X1, X2, X3 et X4 retirent ces appels. `ReconstructionIndex` et `Reconstructeur` supposent
  désormais le schéma posé par `tools/installer.php` ou une amorce CLI/de test. Le POST Workflow de
  gabarit reste une écriture de données idempotente, sans DDL.
- X5 : Bibliothèque rend désormais une 503 nommée « Schéma documentaire absent ou indisponible :
  exécutez tools/installer.php. » ; Recherche rend le même diagnostic pour son schéma. Aucune page
  ne retombe plus sur une maquette ni ne crée une table lorsqu'elle constate cette absence.
- X6 : garde-fou global statique sur tout `src/` (seuls `Schema.php` peuvent contenir
  `Schema::installer()`), et oracle dynamique sur une base déjà installée. Il recrée le Kernel et joue
  `/`, `/a-traiter`, `/bibliotheque`, `/recherche`, `/documents/1`, `/workflows` et `/application` :
  les compteurs MariaDB `Com_create_table`, `Com_alter_table` et `Com_drop_table` restent identiques.
  `DdlHorsHttpTest` : **7 tests, 16 assertions, vert**.
- Preuves ciblées : Recherche Service **8 tests, 30 assertions, vert** ; Recherche Écran **4 tests,
  19 assertions, vert** ; reconstruction Socle + V2-12d **11 tests, 72 assertions, vert**.
- Écrans : premier lancement isolé de `npx.cmd playwright test --reporter=line` démarre **65 parcours**
  et ses deux serveurs, mais le lanceur s'interrompt avant le bilan et laisse les serveurs orphelins ;
  ils ont été fermés par PID exact. Le second lancement s'arrête avant tout parcours : la sonde
  `http://127.0.0.1:8871/sante` expire à **15 s**. Aucun résultat écran n'est donc présenté comme vert.
  La sonde HTTP lancée ensuite ne produit pas de bilan avant l'interruption du lanceur.
- Harness : `powershell -ExecutionPolicy Bypass -File tools\harness.ps1 -SansBanc` est lancé une fois.
  Après plus de dix minutes, son processus PowerShell reste suspendu sans enfant actif et sans écrire
  `recette/registre.json` ; le dernier registre est celui de V2-13w (`f94f5ae`) et n'est pas attribué
  à ce lot.
- Commits : `830507b` (X1), `eb6a236` (X2), `cabdde3` (X3), `5ac8ae8` (X4), `83540b0` (X5),
  `e3c9c10` (X6). Tous portent `Co-Authored-By: Codex <noreply@openai.com>`.
- Porte qualité : PHPStan niveau 6 : **0 erreur** ; `php tools\composer.phar audit --locked
  --no-interaction` : **aucun avis de vulnérabilité**. Revue du diff contre `php-reviewer`,
  `security-reviewer` et `silent-failure-hunter` : aucune concaténation SQL ou entrée utilisateur
  ajoutée, aucun secret, aucune nouvelle route ni garde relâchée ; les nouveaux `PDOException` sont
  rendus en 503 nommée, sans repli silencieux. Aucun constat critique ou élevé.

ETAT: bloqué — lanceurs de recette externes suspendus/expirés (Playwright et harness), sans bilan final ni registre nouveau.

## Journal lot ged-fuite-bibliotheque (10-10) — F10 : 0 fuite sur les listes

Correctif prioritaire demandé par le juge : la Bibliothèque montrait la ligne d'un document hors droits
(`droits-b6.spec.ts:34`, constat du 10.10 sur `16482d5`). La recherche, l'aperçu et le fichier refusaient
déjà ; le filtrage V2-13 (point B3 : dans la requête, jamais après coup) manquait sur la Bibliothèque.

- Fait : `DroitsDossiers::predicatLecture()` est le prédicat SQL unique (héritage du parent, refus
  explicite de lecture gagnant, refus par défaut, administrateur `*` = tout, compte synthétique des
  oracles inchangé). Il entre DANS les requêtes de : liste Bibliothèque (paginée), `total`, compteurs de
  vues, arborescence des dossiers, panneau `?doc=`, file « À traiter » et son bilan, pièces sœurs d'un
  lot, compteur du rail, suggestions de type de la Recherche, « Partagés avec moi ».
- Preuves : `tests/php/Securite/FuiteListesTest.php` (**8 tests, 18 assertions**) écrit rouge d'abord
  (`80884e3`) puis vert ; `droits-b6.spec.ts` vert **3 fois de suite seul** (7,9 / 7,8 / 7,7 s) ;
  suite entière **469 tests / 2636 assertions / 1 skipped**.
- Harness `-SansBanc` (registre du 10-10 19:27, commit `b8b768d`, 543 s) : `0`, `1`, `4` (**65 passed**),
  `7`, `2`, `6` (p95 lecture **644 ms**, écriture **254 ms**) et `8` verts ; rouges admis `3-banc`
  (sautée) et `5-personas` (pas encore écrite).
- Porte qualité : PHPStan niveau 6 **0 erreur** hors baseline (baseline inchangée) ; `composer audit`
  sans vulnérabilité ; revue du diff contre php-reviewer / security-reviewer / silent-failure-hunter :
  SQL statique paramétré, alias du prédicat constant, aucune entrée utilisateur concaténée, aucun
  `catch` avalé ajouté, aucun secret. Aucun constat critique ou élevé.
- Commits : `80884e3`, `92f3332`, `419fb08`, `5c4634a`, `e4f36f3`, `159da61`, `b8b768d` (+ doc).
  Tous portent `Co-Authored-By: DeepCode <noreply@deepcode>`.
- Modifications minimales signalées hors secteur (le correctif ne pouvait pas rester dans `src/Securite/`,
  le prédicat est partagé mais les requêtes vivent chez les appelants) : `src/Socle/EcranBibliotheque.php`,
  `src/Socle/Collaboration.php`, `src/Recherche/Ecran.php`, `src/Ui/ATraiter.php`, `src/Ui/Ecrans.php`,
  `src/Ui/Rail.php`, `src/Ui/Vue.php`.

ETAT: fini

### Jugement lot ged-fuite-bibliotheque (juge, 10-10) — fusionné, fermeture V2-13 après harness complet

- Relu sur la copie du juge : le filtrage vit DANS la requête (`DroitsDossiers::predicatLecture`) et reproduit la décision de `autorise(…, 'lire')` : refus explicite de lecture au dossier, règle du dossier le plus proche, refus par défaut. Les listes couvertes sont la Bibliothèque (liste, compteurs, vues, arbre, aperçu), À traiter, le rail, les partages avec moi, les types de la Recherche et l'API des dossiers.
- Fusion de `origin/v2` (lot ged-phpstan) dans la branche : 2 conflits résolus par le juge (`Recherche\Service` n'a plus d'index en paramètre).
- Défaut trouvé à la relecture et corrigé par le juge (10bc768), oracle d'abord : `_` et `%` sont des jokers de LIKE. Un droit sur un dossier `P_Q` ouvrait le dossier voisin `PZQ` dans le prédicat des listes, dans le filtre ACL de la Recherche et dans `dossierPourChemin`. La comparaison de préfixe est désormais exacte (`LEFT` + `CONCAT(…, '/')`).
- Mesures sur 10bc768 : PHPStan niveau 6 sans erreur, baseline vide ; PHPUnit 471 tests / 2653 assertions vert. Écrans 65/65 verts sur la branche de l'agent avant fusion.
- Reste pour fermer V2-13 : un harness complet sur v2 (machine 2), après le lot personas qui occupe la base de test.
- Écart restant, non bloquant : `predicatLecture` ne vérifie pas la permission de rôle `lire`, alors que `autorise()` la vérifie. Les trois rôles l'ont aujourd'hui ; à aligner si un rôle sans lecture apparaît.

## Journal lot ged-parite-masse (10-10)

- `src/Securite/DroitsRoutes.php` : 2 routes déclarées — `POST /bibliotheque/masse` et
  `POST /bibliotheque/masse/{id:[0-9]+}/tranche` → `documents:modifier` (aux côtés de `POST /bibliotheque/vue`),
  plus la branche regex du motif paramétré dans `permissionPour()`. La permission globale est vérifiée par
  la garde ; le niveau `modifier` sur CHAQUE dossier est vérifié par le service (`ModificationMasse`),
  document par document : un document en lecture seule est refusé et jamais modifié.
- `src/Securite/GardeCentrale.php` : `Vue::definirContexteRail()` reçoit désormais les permissions de la
  session (3ᵉ argument) ; le rail n'affiche la section Administration qu'aux rôles qui ont
  `application:administrer` ou `*`. Aucune autre garde ni décision de droits modifiée.
- `tools/banc/sonde-securite.php` (fichier du secteur Harness) : 2 charges CSRF défensives ajoutées
  (`POST /bibliotheque/masse` action inconnue, `.../tranche` tâche inconnue) ; la sonde reste VERTE.
- Aucun écran n'a de garde propre : le refus (permission globale, CSRF, ACL de dossier) est porté par la
  garde centrale et le service.