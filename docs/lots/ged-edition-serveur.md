# Lot ged-edition-serveur — côté serveur de l'édition depuis le poste (F13)

Branche `ged/ged-edition-serveur` (worktree `C:\ged\wt\ged-edition-serveur`), juge : Claude sur le poste
principal. Cahier des charges : `docs/secteurs/edition-poste.md`.

Mission : ouvrir un document de la GED depuis le poste (`kdocs://ouvrir/<id>`), téléverser une nouvelle
version avec la version de base (conflit signalé, jamais d'écrasement), signaler ouverture et fermeture,
déposer et récupérer une copie de secours hors du fonds, afficher « ouvert par X sur POSTE depuis HH:MM ».
Aucune nouvelle table (plafond 21), aucune DDL hors `tools/installer.php`.

## Fait

### Étape 0 — périmètre et environnement (`c600821`)

- Lecture du cahier des charges, du plan, de la porte qualité ECC, du code existant (Jonctions, Socle,
  Ui, Droits, harness, patrons PHPUnit/Playwright).
- Note d'environnement : dans ce worktree, `vendor` était une **jonction** vers `C:\ged\GEDv2\vendor` ;
  l'autoloader Composer résolvait donc `C:\ged\GEDv2\src` et les tests chargeaient le dépôt principal.
  Correction locale (hors dépôt, `vendor` est gitignoré) : la jonction a été remplacée par une copie,
  l'autoloader pointe désormais sur `C:\ged\wt\ged-edition-serveur\src`.
- `docs/secteurs/edition-poste.md` : rubriques « Chemins » (périmètre du lot) et « Demandes »
  (contrat HTTP de l'agent local) ajoutées.

### Étape 1 — API du poste et oracle (`29d7953`)

Nouveau secteur `src/Edition/` : `Editeur` (état, empreinte, version courante, dépôt avec version de
base), `Ouvertures` (ouverture informative sur `document_collaboration`, événement
`action = 'ouverture'`, bail `expire_le` = dernier signal + 10 min), `Secours` (espace de secours hors
fonds, `copie.json` à côté, une copie par document et par appareil), `Droits`, `Pieces`, `Api`, `Module`.
Routes déclarées dans `DroitsRoutes` (API `publique:api-edition` + route de session de récupération),
`Edition` ajouté d'une ligne à la liste des modules de `Kernel`. Aucune table, aucune DDL.

Oracle `tests/php/Edition/ApiEditionTest.php` (10 tests, 100 assertions) : téléchargement avec
`X-Ged-Empreinte`/`X-Ged-Version`, jeton absent/faux/révoqué/jeton d'application refusés et nommés,
identifiant hors droits (403) et inconnu (404), dépôt à jour, rejeu idempotent, base périmée → conflit
signalé sans rien perdre (3 empreintes archivées), ouverture/signal/fermeture/abandon, copie de secours
(une par appareil, jamais une version) puis récupération en version, routes déclarées.

### Étape 2 — interface (`9600300`)

Fiche : bouton « Ouvrir dans Word/Excel/PowerPoint/le poste » (`kdocs://ouvrir/<id>`, seulement si le
compte peut modifier), « ouvert par X sur POSTE depuis HH:MM » (« peut-être abandonné » sans signal),
onglet Versions : liste des copies de secours + « Récupérer » après confirmation (case à cocher
obligatoire). Bibliothèque : « ouvert par … » sur la ligne et dans l'aperçu, bouton d'ouverture dans
l'aperçu. `.env.example` : `SECOURS_ROOT`. Oracle `tests/php/Edition/EcranEditionTest.php` (3 tests,
17 assertions) rend le VRAI gabarit et vérifie ce qui s'affiche.

### Étape 3 — E2E par l'écran (`9819eff`)

`tests/e2e/edition-serveur.spec.ts` (2 tests) + `preparer-edition-serveur.php` : fonds isolé
`var/oracles/edition-serveur/`, comptes réels manager et lecteur. L'écran prouve le bouton Word/Excel,
« ouvert par X sur POSTE depuis HH:MM » (fiche ET Bibliothèque) et la récupération d'une copie ; les
gestes de l'agent (ouverture, dépôt de la copie) sont joués par le VRAI HTTP sous jeton d'appareil.
`tests/e2e/README.md` : comptes du lot documentés.

### Étape 4 — bancs (`b16b4e0`)

`tools/banc/sonde-securite.php` : charge CSRF défensive de la route de récupération.
`tools/banc/contrat.php` : statut attendu (401 sans jeton) des huit routes d'API. Ces deux bancs
EXIGENT la couverture de toute route déclarée : ces ajouts sont la contrepartie obligatoire de
`DroitsRoutes`.

### Étape 5 — durcissement (`4b746c4`)

Revue ECC : `basename()` sur le nom de fichier de copie (pas d'évasion de dossier), `version_base`
n'accepte qu'une chaîne numérique, `exigerDroit` réutilise le jeton déjà résolu (sans relecture
d'en-tête) et l'état ne renvoie plus le chemin interne du fonds. Aucun changement de comportement.

## Mesures

- Base : `gedv2_test` (127.0.0.1:3308).
- PHPUnit, suite complète (commit de l'API) : **497 tests, 2 906 assertions, 1 ignoré**, vert. Dont
  oracle du lot : 13 tests, 117 assertions.
- PHPStan niveau 6 (`phpstan-baseline.neon` vide) : **0 erreur**.
- Sonde sécurité : `OK: sonde sécurité — aucun contournement accepté`.
- Contrat HTTP : `OK: contrats de service et HTTP — toutes les routes déclarées sont couvertes`.
- E2E du lot (seul) : **2 tests verts** (9,7 s).
- Harness `-SansBanc` : voir ci-dessous (chiffres exacts rejoués avant `ETAT: fini`).

## Porte qualité

1. **Analyse statique** : `php vendor/bin/phpstan analyse --memory-limit=2G` vert, niveau 6,
   `phpstan-baseline.neon` vide (jamais grossie). Aucun `@phpstan-ignore` ajouté.
2. **Revue du diff** (grilles ECC php-reviewer / security-reviewer / silent-failure-hunter) :
   - security : API authentifiée par jeton d'appareil (`Jetons`), jamais par session ; refus nommés
     401/403 ; autorisation par dossier du document (`DroitsDossiers`, niveaux `lire`/`modifier`) ;
     SQL entièrement paramétré (`IN` construit sur un compte d'entiers) ; chemins bornés
     (`DepotFichiers::cheminAbsolu`, `basename()` sur le nom de copie, extension temporaire validée
     `[a-z0-9]{1,10}`) ; jeton jamais journalisé ; sorties Twig échappées (aucun `|raw`).
   - silent-failure : les seuls `catch (Throwable)` ajoutés sont ceux qui TRACENT et choisissent un
     repli sûr (`Droits::peutModifier` → refus, `Ui\Document`/`EcranBibliotheque` → sans ouverture, API
     → 500 nommé, route de récupération → message nommé). Aucun `catch` muet, aucune erreur avalée.
   - php : `declare(strict_types=1)` partout, types et `readonly` posés, méthodes courtes, aucun
     `dd()/var_dump()`, imports utiles seulement.
3. **TDD** : l'oracle `tests/php/Edition/` a été écrit d'abord et vu ROUGE (routes absentes : 404 et
   `null` au lieu de `publique:api-edition`), puis vert. Aucun test existant affaibli.

## Harness (avant `ETAT: fini`)

`powershell -ExecutionPolicy Bypass -File tools\harness.ps1 -SansBanc` rejoué le **2026-10-11** sur le
commit **4b746c4** (`recette/registre.json`, durée 840 s) :

| Couche | Verdict | Chiffres |
| --- | --- | --- |
| 0-garde-fous | VERT | vendor + node_modules présents, DB_NAME=gedv2_test imposé |
| 1-unitaire | VERT | **500 tests, 2 923 assertions, 1 ignoré** (dont les 13 tests du lot) |
| 3-banc | ROUGE | sautée (`-SansBanc`, attendu) |
| 4-ecrans | VERT | **74 passed (4,0 m)**, dont `edition-serveur.spec.ts` |
| 7-securite | VERT | sonde sécurité : aucun contournement accepté |
| 2-contrat | VERT | contrats de service et HTTP : toutes les routes déclarées couvertes |
| 6-charge | VERT | p95 lecture **752 ms**, p95 écriture **264 ms**, 0 erreur, 0 collision |
| 8-statique | VERT | **phpstan : 0 erreur hors baseline** ; composer audit : aucune vulnérabilité |
| 5-personas | VERT | 6 personas, tous les gestes trouvés (médianes 390–778 ms) |

Note de rigueur : un premier rejeu avait tourné en même temps qu'une seconde instance lancée par erreur
(même base, mêmes ports 8871/8872) ; sa couche 4-ecrans avait alors signalé 4 échecs de **contention**
(un `Test timeout` à l'ouverture du navigateur, et l'aperçu PDF à 1030–1072 ms pour un budget de 1 000 ms
sur `/documents/204/apercu`, une route que le lot ne touche pas : `git diff HEAD~6..HEAD` ne contient
aucun fichier d'Aperçu, de visionneuse ni d'asset). Rejoués seuls, `a-traiter.spec.ts` passe et
`budget-apercu` repasse sous le seuil par intermittence (machine chargée par d'autres agents). Le rejeu
**propre et unique** ci-dessus est vert, 74/74.

ETAT: fini
