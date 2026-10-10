# Comptes synthétiques des tests E2E

Les scénarios Playwright s'authentifient avec `testeur@gedv2.test` et le mot de passe
`MotDePasse-Test-2026!`. Ce compte n'est créé que lorsque `APP_ENV=test` et ne donne accès
qu'à l'environnement de test `gedv2_test` ; il ne correspond à aucune personne réelle.

## Compte réel de recette (lot R1-droits)

`tests/e2e/droits-reels.spec.ts` ne se connecte PAS avec le compte synthétique ci-dessus : celui-ci a
un identifiant d'utilisateur nul en base et contourne volontairement les droits par dossier. Le parcours
crée un compte RÉEL comme en production via `tests/e2e/preparer-droits.php` (mot de passe haché, rôle
admin — la voie de l'installateur) : `recette-droits@gedv2.test` / `MotDePasse-Droits-2026!`. Il ouvre
l'aperçu d'un document réellement ingéré par le Pilote, ce qui exige que le document rangé ait son
dossier de droits. Aucune donnée réelle : les deux comptes sont fictifs et vivent dans `gedv2_test`.

## Comptes réels de la couche 5 (Personas)

`tests/e2e/personas/` joue six personas (fiduciaire, secrétaire, comptable, administrateur, auditeur,
débutant), chacun avec un compte RÉEL en base créé par `tests/e2e/personas/preparer-personas.php`
(mot de passe haché, comme l'installateur), sur le fonds synthétique `var/oracles/personas/documents`
(recréé à neuf par la préparation, jamais dans `tests/corpus`). Le rôle fonctionnel et le droit de
dossier suivent le modèle du 2026-10-03 (`docs/secteurs/droits-securite.md`, deux couches qui se
cumulent) :

| Persona | Compte | Rôle | Droit de dossier |
| --- | --- | --- | --- |
| fiduciaire | persona-fiduciaire@gedv2.test | manager | deposer |
| secretaire | persona-secretaire@gedv2.test | manager | modifier |
| comptable | persona-comptable@gedv2.test | manager | gerer |
| administrateur | persona-administrateur@gedv2.test | administrateur | tous |
| auditeur | persona-auditeur@gedv2.test | manager | lire |
| debutant | persona-debutant@gedv2.test | manager | deposer |

Mot de passe commun : `MotDePasse-Personas-2026!`. Chaque geste part de l'écran d'accueil, cherche la
fonction par les menus et libellés visibles (jamais une URL directe, sauf la connexion), et range sa
capture dans `recette/preuves/personas/<persona>-<geste>.png`. Les verdicts et les temps sont lus par
la couche 5 de `tools/harness.ps1`.

