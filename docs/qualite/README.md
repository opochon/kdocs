# Porte qualité — référentiel ECC

Source : [ECC — Everything Claude Code](https://github.com/affaan-m/ECC), version 2.2.3, commit `ef648e01`, licence MIT (copie dans `ecc/LICENSE`). Choix d'Olivier du 2026-10-03 : un logiciel de qualité est attendu ; ECC complète le harness GEDv2, il ne le remplace pas.

## Ce qu'ECC apporte et ce qu'il ne remplace pas

- Le harness GEDv2 (couches 0 à 7) prouve le produit : unitaire, contrats, banc sur papier réel, écrans, charge, sécurité. ECC ne sait rien de tout cela et ne le remplace pas.
- ECC apporte ce qui manquait : des règles d'écriture PHP (`ecc/rules/php`, `ecc/rules/common`), des grilles de revue (`ecc/agents/php-reviewer.md`, `security-reviewer.md`, `silent-failure-hunter.md`, `database-reviewer.md`, `code-reviewer.md`), la discipline TDD (`ecc/skills/tdd-workflow.md`) et la boucle de vérification (`ecc/skills/verification-loop.md`).
- Le plugin ECC complet n'est pas chargé dans les agents : ses 200 skills dépassent le budget de contexte de Codex (descriptions retirées). Seules les pièces utiles à GEDv2 sont recopiées ici.

## Porte qualité de chaque lot (obligatoire avant ETAT: fini)

1. **Analyse statique** (couche 8 du harness, à poser) : PHPStan au niveau fixé dans `phpstan.neon`, aucune erreur nouvelle hors baseline ; une erreur ignorée l'est par `@phpstan-ignore` motivé, jamais en grossissant la baseline.
2. **Revue du diff du lot** contre les grilles `php-reviewer`, `security-reviewer` et `silent-failure-hunter` : chaque constat CRITIQUE ou ÉLEVÉ est corrigé, ou listé avec sa raison dans le journal du lot. En particulier : pas de `catch (Throwable)` qui avale une erreur sans la tracer, pas de SQL construit par concaténation d'une entrée, `declare(strict_types=1)`, fonctions courtes et lisibles (pas de contrôleur de 400 caractères sur une ligne).
3. **TDD** : l'oracle est rouge avant le code, et le reste du lot ne l'affaiblit pas.
4. **Journal** : rubrique « Porte qualité » avec les chiffres (PHPStan, constats de revue corrigés ou motivés).

Le juge applique les mêmes grilles à sa relecture.