# GEDv1 / K-Docs — instructions agents

Canonique. `CLAUDE.md` pointe ici. Vaut pour Claude Code, Codex, Cursor.

> **Transverse.** Ce depot herite des dix regles et de l'ordre de preseance de
> `EcosystemK/AGENTS.md` — importe : @../EcosystemK/AGENTS.md

## Commandes

```bash
run-harness.bat                      # gate complet (~10 min) — 0 ou 1
node tools/run-harness.mjs --check-specs   # registre des specs (instantane)
node tools/status-secteurs.mjs --write     # etat des 15 secteurs -> docs/STATUS-SECTEURS.md
node tools/checklist.mjs --write           # % fait / % teste -> docs/CHECKLIST.md
node tools/claim.mjs take|beat|release <id>  # reservation entre sessions paralleles
node tools/lint-contrat.mjs          # gate externe contrat K-Time (reseau reel)
php tools/preflight.php              # vendor, playwright, DB, K-Time
vendor\bin\phpunit --no-coverage     # ~50 s
```

Base : `kdocs` sur port **3307**. PHP emet un avertissement xdebug au demarrage : bruit connu, ignorer.

## Avant de coder

1. Lis `docs/STATUS-SECTEURS.md` — l'etat calcule, jamais une estimation.
2. Ouvre `governance/sectors.json`, choisis **exactement un** secteur, lis son `ownerAgent`, ses `fichiers`, ses `oracles`, ses `dependsOn`.
3. Ne sors pas de ce perimetre sans arbitrage trace.
4. Priorite : 👻 fantome, puis 🔴 rouge, puis ⚪ orphelin.

L'attendu produit est `governance/ATTENDUS-PRODUIT.md`. **Aucun agent ne modifie un attendu** — seul Olivier en adopte un nouveau.

## Les cinq regles qui coutent cher

**1. Zero suppression.** Aucune ligne n'est jamais supprimee d'une table par le produit. Marquage `deleted_at`, jamais `DELETE`. Reconstruire une base pour les tests est legitime mais n'appartient **pas** a l'application : outil externe, precede d'un dump. Gate : `tests/Feature/NoHardDeleteTest.php`, plafonds `governance/budgets.json`. Un cliquet ne remonte jamais.

**2. L'oracle prouve le cablage, pas la coherence du code.** Verifier `hasMethod()` ou l'existence d'une route ne suffit jamais : l'oracle **execute** le chemin reel. Cas fondateur — `folder-permissions` etait vert sur 10 tests unitaires alors que `FolderPermissionService` n'etait appele par aucune ligne applicative. C'est l'etat 👻 FANTOME du registre.

**3. Le contrat K-Time appartient aux deux depots.** `F:\DATA\DEVELOPPEMENT\K-TIME` est en **LECTURE SEULE** : consultable pour verifier `k-time-web/src/routes.php` et `docs/SPEC-GED-INTEGRATION.md`, jamais modifiable. Une modification cote K-Time se note BLOQUE avec la route et le changement attendu ; une session `claude-s` la traitera. Un agent qui modifie les deux cotes supprime la seule protection du contrat.

**4. Le mock n'est pas une preuve.** Tout lot erpconnect finit par un aller-retour **reel** contre `KTIME_URL` (`/api/ged/health` -> 200). `node tools/lint-contrat.mjs`.

**5. Jamais `git add -A`.** Le depot porte `vendor/` et `node_modules/` : l'operation sature. Chemins un par un.

## Pieges de ce depot

- **`.gitignore` masque des fichiers qui comptent.** Motifs `test_*.php`, `RAPPORT_*.md`, `tests/integration/`. Trois decouvertes en une session, dont une sonde executee par le harness et absente du depot. Verifier `git check-ignore -v <fichier>` avant de conclure qu'un fichier n'existe pas.
- **Les erreurs SQL de recherche sont avalees.** `SearchService::advancedSearch()` attrape et range dans `$result->error` : une recherche cassee rend zero resultat. Tester `$result->error`, pas l'absence d'exception.
- **Deux tables d'audit homonymes.** `audit_logs` (pluriel) est la vivante — 1261 lignes. `audit_log` (singulier) existe vide, colonnes differentes. `classification_audit_log` a 0 ligne.
- **Sept modules livres mais non deployes.** `CONTRACTS_APP_ENABLED`, `RH_APP_ENABLED`, `MAIL_APP_ENABLED`, `PORTAL_APP_ENABLED`, `MULTI_TENANT_ENABLED`, `CLAMAV_ENABLED`, `TSA_URL` sont absents du `.env`. Tests verts, tables a 0 ligne.
- **Aucun ordonnanceur ne tourne.** `scheduled_tasks` affiche `dernier_run = JAMAIS`.
- **Sous-agents tues a 600 s** sur ce poste. Rien de plus de ~3 min en avant-plan ; `run_in_background` pour le harness ; commiter par lot, jamais garder des heures de travail en memoire.

## Fin de lot

Test vert · `run-harness.bat` · **commit** (chemins un par un) · entree `governance/journal/AAAA-MM-JJ-<lot>.json` avec `{lot, resume, test, reste, decisions}` · `claim.mjs release`.

Un lot qui echoue deux fois : `reste` explicite, release, suivant. Un cas sensible — suppression, cle, migration destructive, changement de contrat K-Time — se note BLOQUE et se depasse.

## Preuve, pas affirmation

Un agent s'arrete quand le travail **a l'air** fini. Rendre la sortie de la commande, pas son resume. Un etat declare sans instrument est une faute de gouvernance.
