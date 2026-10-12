# Lot ged-parite-ecrans-1 — rattraper les écarts `ecran` de la parité Paperless-ngx

Base : `ged-parite-modele-3` fusionné (`d06dd61`) : 780 éléments, **529 couverts**, parité stricte **67,82 %**.
Cible : la catégorie `ecran` (30 éléments, 12 couverts, **17 absents**, 1 exclu, strict 40,00 %).
Secteur : **Interface** (`docs/secteurs/interface.md` → chemins `templates/`, `public/assets/`, `src/Ui/`,
`tests/e2e/`). Les quelques lignes hors secteur (routes de données, permissions, harness, préférences)
sont signalées dans la rubrique « Demandes » de la fiche de secteur et dans ce journal.

Méthode oracle d'abord : (1) scénarios utilisateur dans `tests/parite/vecteurs/ecrans.json` (lecture seule
du `src-ui` de `C:\ged\ref\paperless-ngx`, aucun code GPL copié) ; (2) un parcours E2E Playwright par écran
qui AGIT par l'écran réel ; (3) constat des rouges ; (4) implémentation ; (5) `correspondance.json` et
rapport de parité remis à jour.

## Fait (commits)

- `07c637f` — **oracle** : `tests/parite/vecteurs/ecrans.json` (17 scénarios utilisateur) et
  `tests/php/Parite/EcransPariteTest.php` (cohérence scénario ↔ gabarit ↔ correspondance), vus ROUGES.
- `413e3e8` — **écrans** : tableau de bord, journaux (admin), corbeille (admin, restauration seule),
  réglages de l'utilisateur (général, notifications, permissions, vues), accès par numéro d'archive ;
  réutilisation de `/application` (configuration, chemins de stockage), `/taches`, `/vues`,
  `/administration/comptes|groupes` ; menu (rail, section Administration, bas du rail) et icône corbeille.
- `349e800` — **preuves E2E** : `tableau-de-bord.spec.ts`, `journaux.spec.ts`, `corbeille.spec.ts`,
  `reglages.spec.ts` (4 parcours), `asn.spec.ts`, `configuration.spec.ts`, `utilisateurs.spec.ts` +
  `preparer-ecrans.php` / `nettoyer-ecrans.php` (base `gedv2_test`, corpus synthétique, nettoyage complet).
- `6d3aae4` — **parité** : `correspondance.json` (17 entrées `ecran` = gabarit GEDv2 + preuve Playwright),
  `PARITE-PAPERLESS.md` régénéré, correcteur `tools/parite/corriger-correspondance-ecrans.php`, rapport qui
  nomme la couche de la preuve.

## Résultat de parité

| Mesure | Avant | Après |
| --- | ---: | ---: |
| Éléments couverts | 529 / 780 | **546 / 780** |
| Parité stricte | 67,82 % | **70,00 %** |
| Parité hors exclusions | 72,17 % | **74,49 %** |
| `ecran` couverts | 12 / 30 | **29 / 30** (1 exclu : routage Django/Angular) |
| `ecran` strict | 40,00 % | **96,67 %** |
| `ecran` hors exclusions | 41,38 % | **100,00 %** |

Non prouvés : 0. Couche 9 VERTE. Les 3 `modele` et 3 `chaine` restants du lot précédent sont inchangés.

## Écrans livrés et flux F à retester

| Écran | Route | Preuve (harness) | Flux F |
| --- | --- | --- | --- |
| Tableau de bord | `GET /tableau-de-bord` | `tableau-de-bord.spec.ts` (couche 4) | F3, F4, F5 |
| Journaux (admin) | `GET /journaux` | `journaux.spec.ts` (couche 4) | F10, F11 |
| Corbeille (admin) | `GET /corbeille` | `corbeille.spec.ts` (couche 4) | F6 |
| Réglages | `GET/POST /reglages[/{section}]` | `reglages.spec.ts` (4 parcours, couche 4) | — |
| Numéro d'archive | `GET /asn`, `GET /asn/{id}` | `asn.spec.ts` (couche 4) | F5 |
| Configuration + chemins de stockage | `GET /application` (réutilisé) | `configuration.spec.ts` (couche 4) | F1, F7 |
| Utilisateurs et groupes | `/administration/comptes\|groupes` (réutilisé) | `utilisateurs.spec.ts` (couche 4) | F10 |
| Vues enregistrées | `GET /vues` (réutilisé) | `vues.spec.ts` (couche 4) | F5 |
| Suivi des traitements | `GET /taches` (réutilisé) | `taches.spec.ts` (couche 4) | F1 |

Corbeille : la « suppression définitive » de Paperless est volontairement absente (règle non négociable :
zéro suppression de donnée utilisateur). L'écran l'annonce indisponible et nomme la règle ; ce n'est pas
une omission.

## Porte qualité

- **Analyse statique** : `php vendor\bin\phpstan analyse --memory-limit=2G` → **0 erreur, niveau 6**,
  `phpstan-baseline.neon` vide inchangée. `composer audit` (outil gitignoré restauré de la copie de
  référence) → **aucune vulnérabilité connue**.
- **TDD** : vecteurs d'écran et test de cohérence commis AVANT le code, vus rouges (gabarit introuvable,
  correspondance absente) puis verts ; parcours E2E écrits puis rejoués rouges/verts par écran.
- **Revue du diff** (php-reviewer / security-reviewer / silent-failure-hunter) :
  - contrôleurs courts, `declare(strict_types=1)`, aucun `catch (Throwable)` qui avale une erreur
    (chaque refus des nouveaux écrans nomme l'étape et la cause) ;
  - SQL : aucune concaténation d'entrée ; les rares requêtes des nouveaux écrans sont préparées ou
    portent une liste interne figée (aucune entrée dans la requête) ;
  - Données : les nouveaux écrans sont en LECTURE seule ; la seule mutation est la restauration
    (service du Socle, déjà testé) ; la corbeille ne supprime rien ; le journal d'audit reste en ajout seul ;
  - CSRF : toutes les mutations sont couvertes par la garde centrale ; la sonde offensive rejoue les
    charges défensives des deux mutations neuves ;
  - Harness : la couche 4 écrit maintenant `var/e2e-latest.json` : la preuve E2E n'est pas un faux vert.

## Harness (`powershell -File tools\harness.ps1 -SansBanc`)

Rejeu du 2026-10-12 13:06 sur l'arbre commité (`6d3aae4`), durée 1431 s. Détail : `var/harness/`,
verdict versionné : `recette/registre.json`.

| Couche | État | Détail |
| --- | --- | --- |
| 0-garde-fous | VERT | vendor + node_modules, `DB_NAME=gedv2_test` imposé |
| 1-unitaire | VERT | 828 tests, 11 099 assertions, 1 sauté |
| 3-banc | ROUGE | sautée par `-SansBanc` (attendu) |
| 4-écrans | ROUGE | 2 échecs / 89 verts (12,5 min) — **hors lot, environnemental**, voir ci-dessous |
| 7-sécurité | VERT | sonde offensive : aucun contournement (charges CSRF du lot incluses) |
| 2-contrat | VERT | toutes les routes déclarées couvertes, dont `/tableau-de-bord`, `/journaux`, `/corbeille`, `/reglages`, `/asn` |
| 6-charge | VERT | p95 lecture 784 ms, p95 écriture 238 ms, 0 erreur, 0 collision, ingestion finie |
| 8-statique | VERT | PHPStan niveau 6 : 0 erreur ; `composer audit` : aucune vulnérabilité (outil restauré) |
| 5-personas | VERT | 6 personas, tous les gestes trouvés (médianes 382–891 ms) |
| 9-parité | VERT | 780 éléments, 546 couverts, strict 70,00 %, hors exclusions 74,49 %, 0 non prouvé |

**Les 2 échecs de la couche 4 ne viennent pas du lot** :
- `edition-pages.spec.ts` (délai de 90 s dépassé) — reproduit **avec les fichiers du lot retirés**
  (`templates/layout.twig`, `src/Ui/Rail.php`, `templates/partiels/icones.twig` remis au commit de base) :
  l'échec est indépendant du lot ; le registre du lot précédent (`8a032c5`) portait déjà 4-écrans à
  1 échec / 79 verts et la note de `ged-parite-modele-3` nommait déjà ce spec.
- `a-traiter.spec.ts` (1er parcours) : `browserContext.newPage: Protocol error (Target.createTarget):
  Not supported` — panne transitoire du navigateur Playwright ; **rejoué seul : 1 passé (4,6 s)**, et les
  deux autres parcours `a-traiter` sont verts dans le même rejeu.

Aucun test affaibli, aucune sonde retirée, aucune porte relevée ; les 12 preuves E2E citées par la parité
sont **vertes** au dernier rejeu (vérifié dans `var/e2e-latest.json`).

ETAT: fini
