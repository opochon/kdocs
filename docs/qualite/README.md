# Porte qualité — référentiel ECC

Source : [ECC — Everything Claude Code](https://github.com/affaan-m/ECC), version 2.2.3, commit `ef648e01`, licence MIT (copie dans `ecc/LICENSE`). Choix d'Olivier du 2026-10-03 : un logiciel de qualité est attendu ; ECC complète le harness GEDv2, il ne le remplace pas.

## Ce qu'ECC apporte et ce qu'il ne remplace pas

- Le harness GEDv2 (couches 0 à 7) prouve le produit : unitaire, contrats, banc sur papier réel, écrans, charge, sécurité. ECC ne sait rien de tout cela et ne le remplace pas.
- ECC apporte ce qui manquait : des règles d'écriture PHP (`ecc/rules/php`, `ecc/rules/common`), des grilles de revue (`ecc/agents/php-reviewer.md`, `security-reviewer.md`, `silent-failure-hunter.md`, `database-reviewer.md`, `code-reviewer.md`), la discipline TDD (`ecc/skills/tdd-workflow.md`) et la boucle de vérification (`ecc/skills/verification-loop.md`).
- Le plugin ECC complet n'est pas chargé dans les agents : ses 200 skills dépassent le budget de contexte de Codex (descriptions retirées). Seules les pièces utiles à GEDv2 sont recopiées ici.

## Porte qualité de chaque lot (obligatoire avant ETAT: fini)

1. **Analyse statique** (couche 8 du harness, posée le 2026-10-03) : PHPStan au niveau fixé dans `phpstan.neon` (niveau 6), commande `php vendor\bin\phpstan analyse --memory-limit=2G`, aucune erreur nouvelle hors baseline ; une erreur ignorée l'est par `@phpstan-ignore` motivé, jamais en grossissant la baseline.
2. **Revue du diff du lot** contre les grilles `php-reviewer`, `security-reviewer` et `silent-failure-hunter` : chaque constat CRITIQUE ou ÉLEVÉ est corrigé, ou listé avec sa raison dans le journal du lot. En particulier : pas de `catch (Throwable)` qui avale une erreur sans la tracer, pas de SQL construit par concaténation d'une entrée, `declare(strict_types=1)`, fonctions courtes et lisibles (pas de contrôleur de 400 caractères sur une ligne).
3. **TDD** : l'oracle est rouge avant le code, et le reste du lot ne l'affaiblit pas.
4. **Journal** : rubrique « Porte qualité » avec les chiffres (PHPStan, constats de revue corrigés ou motivés).

Le juge applique les mêmes grilles à sa relecture.

## Baseline PHPStan

Mesure du 2026-10-03 sur `src` (commit aebab81) : niveau 0 : 0 erreur, niveau 3 : 7, niveau 5 : 40, niveau 6 : 177, niveau 8 : 261. Niveau retenu : 6, les 177 erreurs existantes sont dans `phpstan-baseline.neon`.

- La baseline ne grossit jamais. Toute erreur corrigée en sort (`reportUnmatchedIgnoredErrors` rend la couche rouge si une entrée devient caduque : on régénère alors la baseline, à la baisse).
- Défauts réels repérés dans la baseline, à corriger en priorité : `Ui\ATraiter` appelle `fiche()`, `lister()` et `cheminAbsolu()` absents du contrat `Contrats\Depot` (fonctionne par accident avec l'implémentation actuelle, casse avec une autre) ; `EcranBibliotheque::arbreVivant()` déclare un type de retour faux ; `Recherche\Service::$index` écrit jamais lu ; conditions toujours vraies dans les horloges figées et `AdaptateurGraphe`.
- La couche 8 lance aussi `composer audit` : une vulnérabilité connue dans une dépendance, ou un audit impossible, rend la couche rouge.

## Baseline à zéro (lot ged-phpstan, 2026-10-10)

Jugé par Claude sur la copie du juge au commit c4f8158 : PHPStan niveau 6 vert avec `phpstan-baseline.neon` vide (167 → 0), aucun `phpstan-ignore` dans `src` ni `tests`, PHPUnit 462 tests / 2631 assertions vert, aucune assertion affaiblie. Défaut réel corrigé au passage, test d'abord : `Ui\ATraiter::types()` passait le PDO au lieu du dépôt à `Nomenclature`, l'écran À traiter affichait les types figés de la maquette (oracle `tests/php/Ui/ATraiterNomenclatureTest.php`). Désormais toute nouvelle erreur PHPStan rend la couche 8 rouge : la baseline reste vide.