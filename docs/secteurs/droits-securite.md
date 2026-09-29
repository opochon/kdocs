# Secteur DROITS & SÉCURITÉ — connexion, sessions, garde centrale, audit

Agent : Codex · Juge : Claude Code · Lot en cours : V2-07a

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

- Interface : lorsque les écrans ne seront plus alimentés par `Ui\\Maquette`, appeler le résolveur des droits de dossier
  avant de rendre chaque ligne de liste et chaque résultat de recherche. La garde refuse déjà fiche, aperçu, fichier et
  miniature ; l'écran de liste doit à son tour omettre les documents hors ACL plutôt que de les rendre non actionnables.

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
