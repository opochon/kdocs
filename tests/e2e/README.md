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

## Comptes réels du lot ged-parite-masse

`tests/e2e/parite-masse.spec.ts` crée lui aussi ses comptes RÉELS (mot de passe haché) sur son propre
fonds `var/oracles/parite-masse/documents` :

- `parite-manager@gedv2.test` / `MotDePasse-Masse-2026!` — rôle Manager, dossier « Modifiable » en
  lecture + dépôt + modification, dossier « Lecture » en lecture seule ;
- `parite-admin@gedv2.test` / `MotDePasse-Masse-Admin-2026!` — rôle Administrateur, pour les écrans
  d'administration atteints par le menu.

Aucune donnée réelle, aucun nom de client.

## Comptes réels du lot ged-edition-serveur

`tests/e2e/edition-serveur.spec.ts` démarre son propre serveur et son propre fonds
`var/oracles/edition-serveur/documents` (espace de secours `var/oracles/edition-serveur/secours`) et crée
deux comptes RÉELS par `tests/e2e/preparer-edition-serveur.php` (mot de passe haché, la voie de
l'installateur), sur le même dossier :

- `edition-e2e-manager@gedv2.test` / `MotDePasse-Edition-Manager-2026!` — rôle Manager, dossier
  « edition-serveur/Documents » en lecture + dépôt + modification ;
- `edition-e2e-lecteur@gedv2.test` / `MotDePasse-Edition-Lecteur-2026!` — rôle Utilisateur, même dossier
  en lecture seule.

Le manager prouve le bouton « Ouvrir dans Word/Excel » (`kdocs://ouvrir/<id>`), l'affichage « ouvert par …
sur POSTE-E2E-F13 depuis HH:MM » et la récupération d'une copie de secours après confirmation ; le
lecteur voit l'ouverture mais ni le bouton ni la récupération. Le jeton d'appareil de l'agent est rendu
par la préparation ; les gestes de l'agent (ouverture, dépôt de la copie) sont joués par le vrai HTTP
sous ce jeton, et tout le reste passe par l'écran. Aucune donnée réelle, aucun nom de client.


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

## Compte réel du lot ged-socle-reconstruction

`tests/e2e/reconstruction-index.spec.ts` (constat du lot ged-personas) démarre son propre serveur et son
propre fonds `var/oracles/reconstruction-index/documents`, préparés par
`tests/e2e/preparer-reconstruction-index.php` (mot de passe haché, la voie de l'installateur) :

- `reconstruction-admin@exemple.test` / `MotDePasse-Reconstruction-2026!` — rôle Administrateur.

Le fonds compte 27 documents synthétiques dont UN a son fichier retiré du disque (métadonnées et ligne
d'index conservées) : le total relisible attendu est 26, le document absent est compté et nommé à part.
Deux gestes sont joués par l'écran : la reconstruction va à 100 % sans tranche fantôme ; et, dans un
second parcours, un document PRÉSENT du périmètre disparaît du fonds PENDANT la reconstruction — elle se
termine et le bilan affiche l'écart (finition ged-finitions-1, 11-10) en plus des absents nommés.
Aucune donnée réelle, aucun nom de client.


