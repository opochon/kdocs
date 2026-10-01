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

