# Secteur VUES DYNAMIQUES — listes filtrees par classe/metadonnees, vues enregistrees, vues recherchees

Agent : DeepCode (lot `ged-mfiles-vues-1`) · Juge : Claude sur le poste principal.

## Mission

Porter la **famille M-Files nº 3** (`docs/PLAN-GEDV2.md`, `docs/parite/PARITE-MFILES.md`) :

- **vues par filtres de metadonnees / classe** : lister les documents retenus par une classe et par des
  valeurs de metadonnees (toutes les factures d'un exercice, tous les contrats actifs). Les filtres
  s'appuient sur les classes (`Nomenclature`), les listes nommees (`Listes`) et les champs types
  (`Champs`) deja portes par les familles 1 et 2 ; une vue ne deplace jamais un fichier, elle ne
  retient que ce qui la satisfait ;
- **vues enregistrees / nommees** : l'utilisateur definit criteres + colonnes + tri, la nomme et la
  retrouve (par identifiant ou par slug du nom). Les definitions sont **portables et versionnees** dans
  le fonds (`.ged/vues.json`, via `Registre`) : pas de coffre proprietaire, une vue survit a la
  reconstruction de l'index (F12) et suit un demenagement du dossier ;
- **vues recherchees** : une vue peut garder une recherche (plein texte + filtres) ; rejouee depuis la
  Bibliotheque, elle affiche exactement le meme contenu que le moteur de Recherche, jamais un second
  moteur ;
- **comptage** : le contenu d'une vue se compte sans balayer deux fois (meme semantique que la liste).

Sont hors de ce lot et restent `absent` (proposes au juge) : le **regroupement/regroupement par
metadonnee** des resultats (aucun element M-Files REST mesure ne le porte), les **vues personnelles par
utilisateur** (les vues du fonds sont partagees, tracees par `auteur` ; le cloisonnement fin par
utilisateur n'est pas un element M-Files REST de cette famille).

## Chemins (seuls modifiables par ce secteur)

- `src/Socle/Vues.php` : service des vues enregistrees (filtres compiles, registre du fonds,
  `contenu()` / `compter()` pour les vues par classe et metadonnees).
- Preuves : `tests/php/Socle/VuesTest.php` (additions), `tests/php/Parite/MfilesVuesVecteursTest.php`,
  `tests/parite/vecteurs/mfiles-vues-*.json`, `tests/oracles/` (porte du lot si ecrite).
- Oracle M-Files : `tools/parite/corriger-correspondance-mfiles.php` (section famille 3),
  `docs/parite/correspondance.json`.
- Journal du lot : `docs/lots/ged-mfiles-vues-1.md`.

## Modifications minimales signalees (hors chemins du secteur)

- _(aucune pour l'instant)_ : les ecrans Bibliotheque (liste + compteur de chaque vue) et Vues
  enregistrees (creation/reglages) existent deja et sont rejoues par `tests/e2e/bibliotheque.spec.ts` et
  `tests/e2e/vues.spec.ts`. Aucune route, aucun gabarit, aucune permission ne change.

## Demandes au Socle / a Interface

- `Vues` s'appuie sur `Registre` (configuration portable), `Champs` (nature d'un champ, operateurs),
  `Fichiers` (slug) et `Fonds` (documents du fonds) sans modifier leurs contrats.
- Le compteur par vue de la Bibliotheque est deja servi par un `COUNT(*)` indexe
  (`EcranBibliotheque::vuesDuVolet`) : `Vues::compter()` est l'API de service equivalente pour un fonds
  deja lu, jamais un balayage supplementaire dans le chemin d'ecran.

## Journal

- 14.10.2026 — ouverture du lot `ged-mfiles-vues-1` (DeepCode) : contenu/comptage des vues par classe et
  metadonnees, vues recherchees rejouees, parite M-Files famille 3.
- 14.10.2026 — livre : `Ged\Socle\Vues::contenu()` / `compter()` (filtres compiles appliques au fonds,
  refus nomme d'une vue de recherche et d'une vue inconnue), vecteurs `mfiles-vues-filtres` 6/6 et
  `mfiles-vues-recherchees` 3/3, les trois elements REST `views` passes en `couvert` : parite M-Files
  **51/112, 45,54 %, 0 non-prouve**. Harness `-SansBanc` vert (942 tests), registre `recette/registre.json`,
  journal complet dans `docs/lots/ged-mfiles-vues-1.md`.
