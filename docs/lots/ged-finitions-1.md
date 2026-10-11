# LOT ged-finitions-1 — deux finitions relevées par le juge le 11-10

Agent : DeepCode · Juge : Claude (poste principal) · Base de test : `gedv2_test` (127.0.0.1:3308)
Branche : `ged/ged-finitions-1` · Point de départ : `origin/v2` (commit `52a1448`)

## Mission

Deux constats du juge restés ouverts après les lots précédents :

1. **Écran Application / reconstruction de l'index** — `ReconstructionIndex::avancer` pose désormais un
   champ `ecart` (documents du périmètre disparus du disque PENDANT la reconstruction). Le bilan de
   l'écran doit l'afficher quand il est non nul, **en plus** des absents nommés. Oracle : test PHPUnit du
   rendu + E2E par l'écran (faire disparaître un fichier pendant la reconstruction, le bilan le dit).
2. **Agent du poste (`agent-poste/`, C# .NET 10)** — après `dotnet test`,
   `HKCU\Software\Classes\kdocs` reste enregistré et pointe vers l'exécutable de test. Les tests qui
   enregistrent le protocole doivent sauvegarder l'état HKCU **avant** et le restaurer **après**, même en
   cas d'échec (comme `agent-poste/tools/tester-installateur.ps1`). Oracle : après la suite complète, la
   clé est identique à l'état d'avant ; `dotnet test` complet vert en session interactive.

## Reconnaissance (faits, pas de code)

- L'état de reconstruction porte déjà `ecart` (`src/Socle/ReconstructionIndex.php` : posé par `avancer`,
  exposé par `etat()`) et un oracle le couvre (`tests/php/Socle/ReconstructionCompteurTest.php`,
  `testUnFichierQuiDisparaitPendantLaReconstructionNeLaBloquePas`). Il manquait l'**affichage**.
- `templates/ecrans/application.twig` affichait déjà `reconstruction-absents` (absents du lancement) mais
  rien pour `ecart` ; le docblock de `src/Ui/Application.php::reconstruction()` omettait `ecart`.
- Côté agent, **seul** `ParcoursF13Tests.Point2_le_protocole_kdocs_est_enregistre_sous_HKCU` touche la
  vraie ruche (`RegistreHkcu`). `ProtocoleKdocsTests` écrit dans un `RegistreMemoire` : il ne touche
  jamais HKCU (aucune fuite possible, aucune sauvegarde réelle à prévoir) — c'est écrit ici pour ne pas
  laisser croire qu'il a été oublié.
- Défaut reproduit sur ce poste : le juge avait laissé `HKCU\Software\Classes\kdocs` pointant vers
  `C:\ged\juge\...\Kdocs.AgentPoste.exe`. Un `dotnet test` de référence (220 tests, 39 s) l'a réécrit
  vers l'exécutable de test de ce worktree — exactement la fuite dénoncée.

## Fait

### Item 1 — affichage de l'écart (interface)

- **Oracle rouge d'abord** — `tests/php/Ui/ApplicationReconstructionEcartTest.php` (nouveau) : un fonds de
  5 documents, une reconstruction lancée, un fichier du périmètre retiré du disque, les tranches jouées
  jusqu'à la fin, puis l'écran rendu par la route réelle `/application`. Avant code, l'assertion
  `data-testid="reconstruction-ecart"` échoue (le HTML ne contient pas le bloc). Un second test prouve
  qu'un écart nul n'affiche rien.
- **Code** (`7872788`) — `templates/ecrans/application.twig` : bloc `reconstruction-ecart` affiché quand
  `reconstruction.ecart > 0`, en plus du bloc `reconstruction-absents` ; `src/Ui/Application.php` :
  docblock `reconstruction()` complété du champ `ecart`.
- **E2E par l'écran** (`ee5ab1b`) — `tests/e2e/reconstruction-index.spec.ts` gagne un second parcours :
  le fonds annonce 26 documents, l'utilisateur lance, le test fait disparaître du disque un document
  PRÉSENT du périmètre, les tranches sont cliquées, et le bilan affiche l'écart (compte 1) en plus des
  absents nommés — l'écran atteint « terminée » avec `25 / 26`. `tests/e2e/preparer-reconstruction-index.php`
  rend le chemin du document qui doit disparaître ; `tests/e2e/README.md` décrit les deux gestes.
  Le premier parcours (26 / 26, aucun écart) est conservé et assène en plus l'absence de bloc écart.

### Item 2 — hygiène HKCU des tests de l'agent (édition depuis le poste)

- **Oracle rouge d'abord** — `tests/.../Support/SauvegardeHkcuTests.cs` (nouveau) : photographie d'une clé
  jetable, mutation, restitution et comparaison à l'identique ; suppression d'une clé qui n'existait pas.
- **Helper** (`52569cc`) — `tests/.../Support/SauvegardeHkcu.cs` : photographie récursive (sous-clés,
  valeurs, genres), restitution exacte, `EstIdentique()`, `Dispose()` qui restitue même en cas d'échec.
- **Parcours F13** (`cf978b6`) — `Point2` sauvegarde la clé avant d'enregistrer, assène sur les valeurs
  écrites, restitue puis *assène* que la clé est redevenue celle trouvée ; la fixture de collection
  sauvegarde/restaure aussi l'état à sa fermeture (filet d'hygiène si un futur test oubliait).
- **Garde de la suite** (`cf978b6`) — `agent-poste/tools/verifier.ps1` exporte
  `HKCU\Software\Classes\kdocs` avant, le compare après la suite complète, restaure l'état d'avant et
  échoue si la clé a changé.

## Mesures exactes

- **PHPUnit ciblé, rouge avant code** : `ApplicationReconstructionEcartTest` **1 échec / 2** avant le bloc
  de gabarit (l'écran ne portait pas `reconstruction-ecart`) → **2 tests / 17 assertions** verts après.
- **PHPUnit voisin** : `ReconstructionCompteurTest`, `ReconstructionTest`, `V212dTest`, `EcransTest`
  → **28 tests / 141 assertions** verts (aucun affaibli).
- **E2E ciblé** : `reconstruction-index.spec.ts` → **2 passed (35,9 s puis 37,0 s)** ;
  **mutation** (bloc `reconstruction-ecart` neutralisé) → **1 failed** sur le parcours de disparition.
- **`dotnet test` de référence (avant code)** : **220 tests, 0 échec, 39 s** — la clé HKCU est réécrite
  vers l'exécutable de test (fuite reproduite).
- **`dotnet test` après code** : **222 tests, 0 échec, 0 ignoré, 32 s**, compilation
  **0 avertissement / 0 erreur** — clé `HKCU\Software\Classes\kdocs` **absente avant et après** (poste
  nettoyé de la fuite du juge).
- **Helper seul** : **2 tests, 0 échec, 38 ms**.
- **Garde `verifier.ps1`** : **VERT** — « clé kdocs identique avant/après la suite » (222 tests).
  **Mutation** (`SauvegardeHkcu.Restaurer` neutralisé) : Point2 rouge « le test doit rendre l'état HKCU
  trouvé » (**1 échec / 9**), garde **ROUGE** et état HKCU d'avant restauré.
- **Harness** `tools\harness.ps1 -SansBanc` : voir « Rejeu du harness ».

## Porte qualité (ECC)

- **Analyse statique** : PHPStan niveau 6 — chiffres du harness ci-dessous ; `phpstan-baseline.neon`
  vide, jamais grossie, aucun `@phpstan-ignore` ajouté. Côté C# : compilation stricte, **0 avertissement,
  0 erreur** (le test project ne masque aucun avertissement).
- **Revue du diff** (`php-reviewer`, `security-reviewer`, `silent-failure-hunter`) :
  - `declare(strict_types=1)` partout en PHP ; aucun SQL, aucune entrée utilisateur concaténée ; le
    gabarit échappe automatiquement (Twig) et n'introduit aucun `|raw` ;
  - le PHP ne fait que **lire** un état et l'afficher ; aucun `catch` muet ajouté ;
  - côté C#, le helper de test ne touche le registre que sous la clé visée, restitue par `Dispose`
    (exception comprise) et compare genres et données avant de conclure ; aucun secret, aucune donnée
    réelle ; les tests utilisent une clé jetable hors `Software\Classes\kdocs` ;
  - `silent-failure-hunter` : la garde `verifier.ps1` ne se contente pas de restaurer en silence — elle
    **nomme** la différence et échoue.
- **TDD** : oracle rouge avant code pour les deux items (rendu PHPUnit, helper HKCU) ; les parcours E2E et
  la garde de suite ont été vus rouges par **mutation**, jamais affaiblis ; aucune sonde retirée, aucune
  porte relevée.

## Rejeu du harness

(en cours — `powershell -ExecutionPolicy Bypass -File tools\harness.ps1 -SansBanc`)

## Reste

- (en cours) — voir « Rejeu du harness ».
