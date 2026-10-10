# Lot ged-fuite-bibliotheque — F10 : 0 fuite sur liste, recherche, aperçu, API

Correctif de sécurité prioritaire. Juge : Claude (poste principal). Agent : DeepCode.

## Contexte (constat du juge, harness du 10.10, commit 16482d5)

`tests/e2e/droits-b6.spec.ts:34` échoue : un compte UTILISATEUR sans droit sur le dossier B voit la
ligne du document de B dans la Bibliothèque (`ligne-<id>` : attendu 0, reçu 1). La recherche, l'aperçu
et le fichier refusent bien ; la Bibliothèque non. Le filtrage des droits de dossier (V2-13, point B3 :
dans la requête SQL, jamais après coup) n'a pas été appliqué à la Bibliothèque.

## Méthode

1. Oracle rouge PHPUnit (compte réel, deux dossiers A/B, une règle de lecture sur A) : la liste de la
   Bibliothèque ne contient que les documents de A.
2. Correction à la source : prédicat de droits réutilisable (`DroitsDossiers::predicatLecture`) introduit
   DANS les requêtes (héritage du parent, refus explicite gagnant, refus par défaut, administrateur voit
   tout, compte synthétique des oracles inchangé). Trois marqueurs positionnels liés au compte.
3. Même preuve par liste : vues, À traiter, partages avec moi, compteurs du rail et des vues, navigation
   par dossier, suggestions (types de recherche), API.

## Fait (commits)

- `80884e3` — oracle rouge de la fuite Bibliothèque (`tests/php/Securite/FuiteListesTest.php`) + note de lot.
- `92f3332` — filtre droits de dossier dans la requête de la Bibliothèque : liste paginée, `total`,
  `vuesVolet`, arborescence, panneau `?doc=`. Prédicat dans `src/Securite/DroitsDossiers.php`.
- `419fb08` — file « À traiter » : liste, compteur, bilan des valides, pièces sœurs d'un lot lus avec le
  prédicat ; `Ui\Ecrans::aTraiter` transmet le compte.
- `5c4634a` — compteur du rail (« À traiter » du menu) borné par le compte ; la garde pose le compte
  courant sur la vue (`Vue::definirContexteRail`).
- `e4f36f3` — suggestions de type de la Recherche : jointure `recherche_textes` × `documents` + prédicat.
- `159da61` — « Partagés avec moi » : le filtre de lecture passe DANS la requête (B3).
- `b8b768d` — oracle de la liste des dossiers de l'API (`/api/v1/dossiers`) : déjà bornée par
  `DroitsDossiers`, un dossier sans droit n'apparaît pas.

## Preuves

- **Oracle rouge d'abord** : `FuiteListesTest::testLaListeDeLaBibliotheque...` rouge sur le code
  `16482d5` avant correction (le document B, id 12, apparaissait dans `docs`).
- **PHPUnit ciblé final** : `vendor/bin/phpunit tests/php/Securite/FuiteListesTest.php` → **8 tests,
  18 assertions, vert**. Couvre : liste Bibliothèque, `total` et compteurs de vues, arborescence,
  aperçu `?doc=` hors droits, file À traiter + bilan, badge du rail, partages avec moi, types de
  recherche, dossiers de l'API.
- **E2E** : `npx.cmd playwright test tests/e2e/droits-b6.spec.ts --project=parcours` → **1 passed**
  trois fois de suite (7,9 s / 7,8 s / 7,7 s), seul, sur serveur `php -S` de test.
- **Suite entière** : `vendor/bin/phpunit` → **469 tests, 2636 assertions, 1 skipped, 0 échec**.
- **PHPStan niveau 6** : `php vendor/bin/phpstan analyse --memory-limit=2G` → **0 erreur** hors baseline
  (baseline non modifiée, jamais grossie).
- **Harness** : `powershell -ExecutionPolicy Bypass -File tools\harness.ps1 -SansBanc` (registre
  `recette/registre.json`, commit `b8b768d`, 543 s) :
  - `0-garde-fous` **VERT** ; `1-unitaire` **VERT** (469 tests / 2636 assertions) ;
  - `4-ecrans` **VERT** (65 passed, 3,0 min) ; `7-securite` **VERT** (aucun contournement accepté) ;
  - `2-contrat` **VERT** ; `6-charge` **VERT** (p95 lecture 644 ms, écriture 254 ms, 0 erreur) ;
  - `8-statique` **VERT** (PHPStan 0 erreur hors baseline ; `composer audit` : aucune vulnérabilité) ;
  - rouges admis : `3-banc` (sautée par `-SansBanc`) et `5-personas` (pas encore écrite).

## Points vérifiés sans fuite (aucune correction nécessaire)

- **API `/api/v1/...`** : `GET /api/v1/dossiers` et le compteur de `GET /api/v1/appairage/moi` passent
  déjà par `DroitsDossiers::autorise` (un dossier sans droit ne sort jamais). Oracle ajouté.
- **Export** : `src/Socle/Export.php` est l'export canonique F12 (mesure de reconstruction), sans route
  HTTP ni écran utilisateur ; il doit voir le fonds entier par construction. Aucun listage utilisateur
  à filtrer.
- **Recherche / aperçu / fiche / fichier** : déjà refusés par la garde (`GardeCentrale::documentAutorise`
  + prédicat du moteur de recherche). L'audit `rg 'FROM documents'` ne trouve aucune autre liste
  alimentée à l'écran hors de ces surfaces.

## Porte qualité (ECC)

- **Analyse statique** : `php vendor\bin\phpstan analyse --memory-limit=2G` → 0 erreur hors baseline ;
  `composer audit` → aucune vulnérabilité. Baseline inchangée.
- **Revue du diff** contre php-reviewer, security-reviewer et silent-failure-hunter :
  - SQL entièrement statique et paramétré (`?`), aucun fragment construit par interpolation d'une entrée
    utilisateur ; l'alias `d` du prédicat est une constante du code ; les valeurs (compte, chemin de
    filtre champs validé par slug) restent liées.
  - Autorisation : le prédicat est appliqué DANS la requête ; l'administrateur (`*`) voit tout ; le
    compte synthétique des oracles (id nul, `APP_ENV=test` uniquement) conserve le comportement
    historique ; aucun chemin d'écran ne relâche la garde.
  - Aucun `catch (Throwable)` ajouté qui avale une erreur sans trace : les `catch` ajoutés reprennent le
    style existant (repli honnête et borné : `typesFiltre` vide, compteur 0) sans masquer une fuite.
  - `declare(strict_types=1)`, types de paramètres et de retour présents, classe `final`.
  - Aucun secret, aucune donnée réelle, aucun nom de client.
  - Aucun constat critique ou élevé.

## Modifications hors secteur (signalées, règle « un agent = un secteur »)

Le correctif F10 ne pouvait pas rester dans `src/Securite/` : le prédicat est partagé, mais les requêtes
des listes vivent dans les secteurs appelants. Fichiers touchés hors `src/Securite/` :

- `src/Socle/EcranBibliotheque.php` (Bibliothèque, compteurs, vues, arbre, aperçu) ;
- `src/Socle/Collaboration.php` (`partagesAvecMoi`) ;
- `src/Recherche/Ecran.php` (suggestions de types) ;
- `src/Ui/ATraiter.php`, `src/Ui/Ecrans.php`, `src/Ui/Rail.php`, `src/Ui/Vue.php` (file, compteur, rail) ;
- `tests/php/Securite/FuiteListesTest.php`.

Ces demandes sont consignées dans `docs/secteurs/droits-securite.md` (rubrique « Demandes ») pour les
secteurs Socle, Interface et Recherche.

ETAT: fini
