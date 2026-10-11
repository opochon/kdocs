# LOT ged-socle-reconstruction — compteur cohérent de la reconstruction d'index

Agent : DeepCode · Juge : Claude (poste principal) · Base : `gedv2_test` (127.0.0.1:3308)
Branche : `ged/ged-socle-reconstruction` · Constat d'origine : lot `ged-personas` (ergonomie n°3)

## Mission

Le persona administrateur voyait un pourcentage faux et devait relancer : `ReconstructionIndex::lancer()`
comptait **tous** les documents vivants de l'index (`documents`) comme total, alors que
`IndexPleinTexte::synchroniser()` ne relit que ceux **présents dans le fonds courant**. Une première
tranche laissait un reste fantôme (observé 6/7) qu'une seconde tranche concluait. Attendu : le total
annoncé est exactement le nombre de documents qui seront relus, l'avancement atteint 100 % sans reste
fantôme, et le document absent est compté à part (nommé), jamais en silence.

Le lot est **transversal de correction** : le périmètre est défini une seule fois côté Socle/Recherche,
l'écran Application l'expose. Fichiers hors secteur strict touchés (documentés dans `socle.md`) :
`src/Recherche/IndexPleinTexte.php` (périmètre partagé), `src/Ui/Application.php` et
`templates/ecrans/application.twig` (barre + bilan), `tests/e2e/` (porte écran).

## Fait

- **Oracle rouge d'abord** — `tests/php/Socle/ReconstructionCompteurTest.php` (nouveau) : un fonds de
  7 documents dont UN absent du disque. Avant code : `total = 7` au lieu de 6, et **4 tranches au lieu
  de 3** pour un lot de 2 (reste fantôme reproduit exactement).
- **Correction à la source** (`372ed7c`) :
  - `IndexPleinTexte::aRelire()` devient l'**unique** définition du périmètre (documents réellement
    présents dans le fonds) ; `synchroniser()` la parcourt ;
  - `ReconstructionIndex::lancer()` en tire `total = count(perimetre)` ;
  - les documents vivants absents du fonds (`supprime_le IS NULL`, chemin hors périmètre) sont comptés
    (`absents`) et nommés (`absents_noms`, 5 chemins max) à part ;
  - la fin forcée `faits === 0 ? total : 0` est supprimée : l'avancement se cumule, sans tranche fantôme.
- **Écran** (`5f525b0`) : `Application` transporte `absents`/`absents_noms` ; le gabarit
  `application.twig` affiche une barre (`data-testid="reconstruction-barre"`, value/max), un pourcentage
  (`reconstruction-pourcentage`), une progression (`reconstruction-progression`) et un bilan
  (`reconstruction-absents`) qui nomme les absents, y compris après « terminée ».
- **E2E par l'écran** (`79a9c8b`) — `tests/e2e/reconstruction-index.spec.ts` +
  `tests/e2e/preparer-reconstruction-index.php` : fonds isolé de 27 documents dont UN absent du disque.
  Le parcours passe par le menu visible Application, lance, traite les tranches, et assène sur ce que
  l'utilisateur voit : progression `26 / 26`, `100 %`, barre `value = max = 26`, bouton tranche absent,
  bilan nommant l'absent. **Vérification par mutation** : ancien compteur réintroduit ⇒ E2E rouge
  (progression observée `0 / 27` au lieu de `0 / 26`) ; source restaurée ⇒ vert.

## Mesures exactes

- PHPUnit ciblé avant/après : `ReconstructionCompteurTest` **2 échecs** (rouge attendu) → **2 tests /
  22 assertions** verts ; `V212dTest` 7 tests verts inchangés.
- PHPUnit complet : **565 tests, 3131 assertions, 1 skip** — vert (couche 1).
- PHPStan niveau 6 : **0 erreur**, `phpstan-baseline.neon` **vide** (inchangée).
- E2E ciblé : `reconstruction-index.spec.ts` **1 passed (11 s)** en vert ; **1 failed** sous mutation.
- Harness `tools\harness.ps1 -SansBanc` : voir « Rejeu du harness ».

## Porte qualité (ECC)

- **Analyse statique** : PHPStan niveau 6, 0 erreur hors baseline ; baseline vide, jamais grossie ;
  aucun `@phpstan-ignore` ajouté.
- **Revue du diff** (`php-reviewer`, `security-reviewer`, `silent-failure-hunter`) :
  - `declare(strict_types=1)` partout ; aucune requête construite par concaténation d'entrée
    (`SELECT chemin_relatif FROM documents WHERE supprime_le IS NULL ORDER BY chemin_relatif` est
    constante) ; aucun SQL/DELETE destructeur ajouté (zéro suppression) ;
  - pas de `catch` qui avale : les exceptions `\Throwable` de `avancer()` sont déjà tracées dans l'état
    (`echec` + message), comportement conservé ;
  - `absents()` n'est pas silencieux : le compte et les chemins remontent jusqu'à l'écran ;
  - pas de contrôleur-fleuve ajouté.
- **TDD** : l'oracle `ReconstructionCompteurTest` était rouge avant le code, vert après ; aucun test
  existant affaibli, aucune sonde retirée, aucune porte relevée.

## Rejeu du harness

`powershell -ExecutionPolicy Bypass -File tools\harness.ps1 -SansBanc`, sur ce worktree (registre :
`recette/registre.json`, commit `79a9c8b`) :

- **VERT** `0-garde-fous` (vendor et node_modules présents ; `DB_NAME=gedv2_test` imposé) ;
- **VERT** `1-unitaire` : **565 tests, 3131 assertions, 1 skip** ;
- **VERT** `2-contrat` : toutes les routes déclarées couvertes ;
- **ROUGE attendu** `3-banc` : sautée par `-SansBanc` ;
- **VERT** `4-ecrans` : **77 passed (3.9 min)** — dont le nouveau
  `tests/e2e/reconstruction-index.spec.ts` ;
- **VERT** `5-personas` : administrateur 4/4 (médiane 668 ms) | auditeur 4/4 (417 ms) |
  comptable 4/4 (534 ms) | debutant 4/4 (326 ms) | fiduciaire 4/4 (428 ms) | secretaire 3/3 (605 ms) ;
- **VERT** `6-charge` : p95 lecture 667 ms, p95 écriture 241 ms, 0 erreur, 0 collision ;
- **VERT** `7-securite` : aucun contournement accepté ;
- **VERT** `8-statique` : PHPStan niveau 6, **0 erreur** hors baseline (baseline vide) ; `composer audit` :
  aucune vulnérabilité.

Note d'environnement : `tools/composer.phar` est ignoré par git et absent d'un worktree neuf ; copié
depuis le dépôt principal (comme au lot `ged-personas`) pour que la couche 8 (audit) puisse tourner.

## Reste

- (aucun) — voir « Mesures », « Rejeu du harness » et « Porte qualité ».

ETAT: fini
