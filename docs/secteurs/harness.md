# HARNESS — la maturité se mesure, elle ne se déclare pas

Agent : Claude Code · Juge : chaque secteur le rejoue · Commande : `powershell -File tools\harness.ps1`

## Règles
- Une couche absente vaut ROUGE. Une ligne de matrice sans test qui l'exécute vaut 0.
- Le registre `recette/registre.json` n'est écrit que par le harness, jamais à la main.
- Les tests n'écrivent que dans `gedv2_test` et un fonds de test (`phpunit.xml` et `playwright.config.ts` imposent
  `DB_NAME`, `STORAGE_ROOT`, `APP_ENV`, et le port 8871) : un `.env` local ne peut pas les détourner.
- Aucun appel réseau dans les couches 1 et 4 (IA, IMAP, K-Time simulés) ; le réseau réel n'est touché qu'en couche 3.

## Couches
| # | Couche | Ce qu'elle prouve | État 29-09 |
|---|---|---|---|
| 0 | garde-fous | dépendances, base de test imposée, aucune donnée réelle, aucun `DELETE` hors liste | VERT |
| 1 | unitaire | PHPUnit, chaque secteur (contrats, pilote, IMAP contre faux serveur, aperçu…) | VERT |
| 2 | contrat | chaque implémentation d'un `Ged\Contrats\*` passe la même suite de contrat | à écrire |
| 3 | banc | corpus + vérité : découpe (`mesurer-decoupe.php`, 53/53), classement, rejeu complet sur base vierge | à brancher (outil prêt) |
| 4 | écrans | Playwright : parcours clavier, clair/sombre, 0 erreur console, captures | VERT |
| 5 | personas | parcours métier bout à bout (comptable, secrétariat, direction) | à écrire |
| 6 | charge | 10 000 documents, 5 utilisateurs, temps de réponse bornés, tâches courtes | à écrire |
| 7 | sécurité | droits refusés par défaut, CSRF, en-têtes, jetons, pas de fuite de secret | à écrire |

## Lot suivant (Claude Code)
1. Couche 3 : `harness.ps1` lance `mesurer-decoupe.php` (si le moteur est présent, sinon ROUGE nommé « moteur absent »).
2. Couche 2 : suite de contrat `Depot` (dépôt → `.meta.json` → version 1 → index reconstructible) jouée contre
   `Ged\Socle\DepotFichiers` dès qu'il existe.
3. Couche 0 : refuser tout test qui touche une base autre que `gedv2_test`.

## Jugement sur le code commité (29-09)
Le vestiaire est partagé par plusieurs agents : un travail non commité peut casser le harness des autres. Le juge rejoue
donc depuis une copie de travail séparée, sur HEAD :
`git worktree add --detach ..\GEDv2-juge HEAD` (`vendor` COPIÉ puis `php tools/composer.phar dump-autoload` — une jonction ferait charger le `src/` du vestiaire, vu le 29-09 —, jonction vers `node_modules`, `.env` copié), puis
`$env:GED_PORT_TESTS='8872'; powershell -File ..\GEDv2-juge\tools\harness.ps1`. Le verrou de `gedv2_test` vit dans le
dossier temporaire du système : juge et agents s'attendent au lieu de s'effacer les tables.
