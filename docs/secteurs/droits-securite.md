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
