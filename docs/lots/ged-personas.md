# LOT ged-personas — couche 5 du harness (Personas)

Agent : DeepCode · Juge : Claude (poste principal) · Base : `gedv2_test` (127.0.0.1:3308) · Fonds : `var/oracles/personas/documents`

## Mission

Écrire la couche 5 du harness : six personas (fiduciaire, secrétaire, comptable, administrateur,
auditeur, débutant) jouent F1 à F12 depuis l'écran d'accueil, en cherchant la fonction comme un humain
(menus, libellés visibles), jamais par URL directe sauf la connexion. Pour chaque geste : temps, verdict
trouvé / pas trouvé, capture `recette/preuves/personas/<persona>-<geste>.png`. Un geste non trouvé en 30 s
est un ÉCHEC NOMMÉ (persona, fonction, écran), jamais un test sauté. La couche est branchée dans
`tools/harness.ps1` (verte seulement si tous les personas trouvent tout) et son détail (persona,
trouvés / total, temps médian) alimente le registre.

## Fait

- `tests/e2e/personas/preparer-personas.php` : fonds synthétique (un dossier par persona, déposé par le
  VRAI `DepotFichiers`), six comptes RÉELS (mot de passe haché) et leurs droits de dossier conformes au
  modèle du 2026-10-03 (`docs/secteurs/droits-securite.md`), champ personnalisé « Échéance » sur le type
  Contrat, index plein texte reconstruit hors requête, remise à zéro des tables dérivées sous le verrou
  partagé (protocole des autres parcours). Aucune donnée réelle, aucun nom de client.
- `tests/e2e/personas/parcours.ts` : harnais de mesure partagé — connexion vers l'accueil, budget de 30 s
  par geste, capture d'écran, verdict et temps écrits IMMÉDIATEMENT (un worker redémarré après un échec
  ne perd aucun verdict), manifeste des gestes attendus par persona.
- Un fichier par persona (`fiduciaire`, `secretaire`, `comptable`, `administrateur`, `auditeur`,
  `debutant`), trois à quatre gestes chacun, couvrant F1 à F12.
- `playwright.config.ts` : projet `personas` (workers 1), exclu du projet `parcours` ; `4-ecrans` ne
  rejoue plus les personas (invocation `--project=parcours --project=budgets`).
- `tools/harness.ps1` : la couche `5-personas` remplace « pas encore écrite ». Elle rejoue
  `--project=personas`, agrège les manifestes et les verdicts, et n'est verte que si tout est trouvé.
- `tests/e2e/README.md` : comptes et rôles des personas documentés.

## Reste

- (aucun) — voir « Mesures », « Rejeu du harness » et « Porte qualité ».

## Mesures (couche 5 rejouée seule, `npx playwright test --project=personas`)

6 personas, 23 gestes, **23/23 trouvés** (couverture F1 à F12), 0 geste sauté.

| Persona | Rôle | Gestes trouvés / total | Temps médian |
| --- | --- | --- | --- |
| fiduciaire | manager (Dépôt) | 4 / 4 | 515 ms |
| secretaire | manager (Modification) | 3 / 3 | 584 ms |
| comptable | manager (Gestion) | 4 / 4 | 564 ms |
| administrateur | administrateur | 4 / 4 | 724 ms |
| auditeur | manager (Lecture) | 4 / 4 | 428 ms |
| debutant | manager (Dépôt) | 4 / 4 | 399 ms |

Couverture des fonctions : F1 (bilan / repérage), F2 (dépôt écran), F3 (connecteurs), F4 (validation à la
main), F5 (recherche + filtres), F6 (fiche + version / téléchargement), F7 (arbre des dossiers), F8
(workflows), F9 (échéance, horloge figée au 10-10-2026), F10 (mon dossier seulement), F11 (scellement et
archives), F12 (réglages et reconstruction de l'index).

## Rejeu du harness

`powershell -ExecutionPolicy Bypass -File tools\harness.ps1 -SansBanc`, sur ce worktree (registre :
`recette/registre.json`, commit de base `6cc5b60`) :

- **VERT** : `0-garde-fous`, `1-unitaire` (462 tests, 2620 assertions, 1 skip), `2-contrat`, `6-charge`
  (p95 lecture 743 ms, p95 écriture 242 ms, 0 erreur, 0 collision, ingestion finie), `7-securite`,
  `8-statique` (PHPStan niveau 6 : 0 erreur hors baseline, baseline vide ; `composer audit` : aucune
  vulnérabilité).
- **VERT — `5-personas`** : administrateur 4/4 (médiane 663 ms) | auditeur 4/4 (médiane 627 ms) |
  comptable 4/4 (médiane 685 ms) | debutant 4/4 (médiane 474 ms) | fiduciaire 4/4 (médiane 560 ms) |
  secretaire 3/3 (médiane 597 ms).
- **ROUGE** `3-banc` : sautée par `-SansBanc` (rouge attendu).
- **ROUGE** `4-ecrans` : un échec **pré-existant sur la base de ce lot**, `tests/e2e/droits-b6.spec.ts`
  (« ligne-B » de la Bibliothèque visible pour un compte de rôle Utilisateur, ligne 34). Le filtrage des
  listes par ACL est apporté par la branche `ged-fuite-bibliotheque`/`v2` ; il est VERT sur `v2`. Ce lot
  ne touche aucun fichier de `src/`. Rejoué seul : 1 échec stable (`droits-b6`), 64 parcours verts.

### Note d'environnement (importante pour rejouer ce lot)

Le `vendor` du worktree est une **jonction** vers `/c/ged/GEDv2/vendor`. L'autoloader y résout `Ged\`
vers `/c/ged/GEDv2/src` : le harness charge donc le code de `v2`, **pas** celui du worktree. Procédure
correcte (celle du juge, `docs/secteurs/harness.md`) : **copier** `vendor` dans le worktree (le chemin
relatif de `autoload_static.php` repointe alors vers `src/` du worktree ; aucune régénération n'est
nécessaire). Sans cette copie, deux parcours d'écrans (`ecrans-reels`, `mobile`) échouent à tort : la
racine `Config` de l'écran est prise dans le dépôt principal et les chemins du fonds ne coïncident plus.
`tools/composer.phar` (ignoré par git) a aussi été copié depuis le dépôt principal pour permettre
`composer audit`.

## Constats d'ergonomie (à traiter en lot d'ergonomie, pas corrigés ici)

1. **Constat principal — un compte de rôle `Utilisateur` n'atteint AUCUN écran.** Après connexion, la
   redirection mène à `/a-traiter`, qui exige `documents:traiter` (rôle Manager ou Administrateur) ; un
   Utilisateur reçoit donc 403 « permission insuffisante » et la page de refus n'a qu'un lien « Se
   connecter ». Reproduction mesurée sur ce worktree (compte réel, mot de passe haché) :
   `verif-utilisateur@gedv2.test` → connexion 303 → `/a-traiter` = **403**. Attendu : un écran d'accueil
   par rôle (ex. Bibliothèque pour l'Utilisateur) ou un rail rendu sur la page de refus. Par suite, les
   cinq personas opérationnels portent le rôle Manager (avec des droits de dossier Lecture / Dépôt /
   Modification / Gestion, conformes à la couche ACL du modèle) et l'administrateur le rôle
   Administrateur ; le rôle Utilisateur n'est pas joué comme persona tant que ce constat n'est pas levé.
2. **Écrans d'administration sans entrée de menu visible** : `/metadonnees`, `/administration/comptes`,
   `/administration/groupes`, `/administration/roles` et `/dossiers/{id}/droits` ne sont atteignables par
   aucun lien visible depuis les six écrans maîtres (desktop) ; ils ne sont donc pas testés par les
   personas. Ce n'est pas dans le périmètre F1–F12, mais c'est le même défaut de découvrabilité.
3. **`total` de la reconstruction** : `ReconstructionIndex::lancer()` compte **tous** les documents vivants
   de l'index alors que `synchroniser()` ne relit que ceux présents dans le fonds courant ; une première
   tranche peut donc laisser un reste (observé 6/7) qu'une seconde tranche conclut. Le persona reprend
   les tranches jusqu'à « terminée » sans forcer ; à unifier côté Socle (constat, hors lot).

## Porte qualité

- Analyse statique : PHPStan niveau 6, 0 erreur hors baseline ; `phpstan-baseline.neon` reste vide. Le
  PHP ajouté vit sous `tests/`.
- Revue du diff contre `php-reviewer`, `security-reviewer`, `silent-failure-hunter` :
  - `preparer-personas.php` : `declare(strict_types=1)`, aucune requête construite par concaténation
    d'entrée (identifiants quotés / requêtes préparées), aucune exception avalée, aucun secret réel,
    aucune donnée client ; le fonds d'essai est recréé et les comptes sont synthétiques.
  - `parcours.ts` : aucune API interne appelée à la place de l'écran ; les gestes cliquent les libellés
    et `data-testid` visibles ; un échec est nommé et capture l'écran où le persona s'est perdu.
  - `tools/harness.ps1` : la couche 5 est rouge si Playwright échoue OU si un persona ne trouve pas tout ;
    aucun test n'est retiré, aucune porte n'est relevée.
- TDD : la couche 5 partait ROUGE (« pas encore écrite ») ; l'oracle a été écrit puis rejoué jusqu'au
  vert (23/23). Aucun test existant affaibli ; `playwright.config.ts` ne fait qu'isoler le nouveau projet.

ETAT: fini
