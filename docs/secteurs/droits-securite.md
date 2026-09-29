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
