# Lot ged-api-documents — API REST des documents (parité Paperless)

Agent : DeepCode · Secteur : Jonctions (`docs/secteurs/jonctions.md`) · Juge : Claude sur le poste principal.
Préalable aux liaisons ClearMyMails et K-Time. Branche : `ged/ged-api-documents`.

## Objectif
Exposer une API REST des documents (fonctions de Paperless-ngx 2.20, aucune ligne de son code) :
lister/filtrer/paginer, chercher en plein texte, lire une fiche, télécharger le fichier courant et une
version, modifier les métadonnées, déposer un document vers l'ingestion, lister types, étiquettes,
émetteurs et vues. Authentification par les jetons existants (`Jonctions\Jetons`) portant une portée
dédiée lecture/écriture, **jamais la session web** ; compte du jeton passé explicitement aux services,
droits posés **dans** les requêtes (`DroitsDossiers::predicatLecture`). Aucune nouvelle table.

## Fait
- [x] `docs/api/documents.yaml` — contrat OpenAPI 3.1 (chemins, schémas, erreurs nommées, portées).
- [x] `src/Jonctions/Jetons.php` — portées `documents_lecture` / `documents_ecriture` et
  `creerPourCompte()` (jeton lié à un compte, empreinte sha256 seule en base).
- [x] `src/Jonctions/Catalogue.php` — service : liste/recherche (index FULLTEXT + repli LIKE borné),
  fiche, fichier/version, modification (déléguée au contrat `Depot` pour le contrôle d'arrivée),
  référentiels (types/étiquettes/émetteurs/vues). ACL dans la requête pour liste, recherche,
  étiquettes, émetteurs ; fiche/fichier refusent 403 hors dossiers.
- [x] `src/Jonctions/DocumentsApi.php` — porte HTTP, jeton porteur hors session, bornes de page et de
  débit par jeton, erreurs `{erreur:{etape,cause,reference}}`.
- [x] `src/Jonctions/Debit.php` — limiteur de débit par jeton, état hors requête, sous verrou.
- [x] `src/Jonctions/Module.php` — routes enregistrées (Kernel non modifié).
- [x] Tests : `tests/php/Jonctions/DocumentsApiTest.php` (contrat HTTP + droits) et
  `CatalogueTest.php` (service), `SupportJonctions` étendu (`jetonDocuments`, dossier de débit éphémère).
- [x] Bancs : `tools/banc/contrat.php` (statuts des routes publiques, n° de version substitué) et
  `tools/banc/sonde-securite.php` (401 sans jeton, 403 pour un jeton de portée `depot`).

Commits : `e326828` (contrat + service + porte + tests), `c8f1e69` (bancs), `2121f9c` (revue ECC),
`fb4f554` (note de lot et fiche secteur).

## Reste
- Rien de fonctionnel : le lot est complet.

## Mesures exactes
- `vendor\bin\phpunit tests\php\Jonctions` : **33 tests, 304 assertions verts** (dont 13 nouveaux
  documents, 112 assertions).
- Suite complète : **576 tests, 3 230 assertions, 1 sauté, vert** (563 avant le lot, +13).
- `php vendor\bin\phpstan analyse --level 6` : **0 erreur hors baseline** (baseline vide).
- `php tools\banc\contrat.php` : vert. `php tools\banc\sonde-securite.php` : vert.

## Harness — `powershell -File tools\harness.ps1 -SansBanc` sur `fb4f554` (1 650 s)
Couches que le lot touche :
- **0-garde-fous : VERT**.
- **1-unitaire : VERT** — 576 tests, 3 230 assertions, 1 sauté.
- **2-contrat : VERT** — toutes les routes déclarées sont couvertes (dont les 10 nouvelles, 401 sans jeton).
- **7-securite : VERT** — aucune prise (401 sans jeton, 403 portée `depot` sur lecture et écriture).
- **8-statique : VERT** — PHPStan niveau 6 : 0 erreur hors baseline ; `composer audit` : aucune
  vulnérabilité. (`tools\composer.phar` est ignoré par git et manquait dans ce worktree ; restauré depuis
  l'arbre principal avant la mesure — la seule cause du premier échec de cette couche.)

Couches hors secteur (aucune route ni aucun écran du lot n'y participe ; défauts déjà consignés) :
- **4-écrans** : 1 échec sur 76 pendant la passe complète ; rejeu isolé de la couche : **74 passés,
  2 échoués**, tous deux des budgets de TEMPS sous machine chargée —
  `maquettes.spec.ts` « écran application » 2 369 ms pour un seuil de 1 500 ms (secteur Interface) et
  `budget-apercu.spec.ts` première page PDF 1 154 ms pour un seuil de 1 000 ms (secteur Aperçu). Le lot
  n'ajoute aucun écran et ne touche ni `maquettes.spec.ts` ni `budget-apercu.spec.ts`.
- **5-personas** : secrétaire 2/3 (geste F4 « valider-piece » sur le document 10085, qui appartenait au
  fonds du banc de charge laissé dans `gedv2_test` — défaut d'hygiène H-6 consigné) ; les 5 autres
  personas sont à 4/4. Aucun geste ne passe par l'API documents.
- **6-charge** : p95 lecture **1 528 ms** pour un seuil de 1 500 ms (0 erreur, 0 collision, ingestion
  finie) ; l'écart de 2 % est du bruit machine, la table par écran de `recette/preuves/charge/charge.md`
  ne désigne aucune route du lot.
- **3-banc** : sautée (`-SansBanc`), comme demandé.

## Décisions et points de contrat
- **Portée** : `documents_lecture` (lire) et `documents_ecriture` (lire + écrire) ; `*` couvre les deux.
  Un jeton **sans compte** est refusé (403 nommé) : les droits ne peuvent pas être appliqués.
- **Droits** : liste/recherche/étiquettes/émetteurs portent `DroitsDossiers::predicatLecture` dans le
  WHERE SQL ; fiche/fichier/version refusent 403 si le compte n'a pas `lire` ; modification exige
  `modifier` puis le Socle exige `deposer` sur le dossier d'ARRIVÉE pour tout déplacement.
- **Recherche** : index `recherche_textes` (FULLTEXT) si présent, sinon repli LIKE borné sur
  `documents.titre` + `documents.meta`, **au même périmètre de droits** ; aucune des deux voies ne rend
  un document hors dossiers.
- **Dépôt** : `POST /api/v1/documents` par défaut vers l'ingestion (même chaîne que le dossier
  surveillé) ; avec `dossier`, rangement direct et contrôle du niveau `deposer`.
- **Bornes** : page ≤ 100 (défaut 25), recherche ≤ 1000 candidats, débit par jeton
  (`API_DEBIT_MAX`, défaut 300 / `API_DEBIT_FENETRE`, défaut 60 s).

## Porte qualité
- Analyse statique : PHPStan niveau 6 **0 erreur** (baseline vide, inchangée).
- Revue ECC : `php-reviewer` (SQL tout en requêtes préparées, `declare(strict_types=1)`, classes
  `final`, types explicites, pas de fonction > 50 lignes), `security-reviewer` (jeton haché jamais en
  clair ni journalisé ; liste blanche d'extensions + taille max au dépôt ; traversée `..` refusée par
  `DepotFichiers::cheminAbsolu` et à la résolution des versions ; débit par jeton), `silent-failure-hunter`
  (repli de recherche, nomenclature et vues illisibles **tracés au journal** ; aucun `catch` vide ;
  panne du compteur de débit = exception, jamais un faux 429).
- TDD : oracles vus ROUGE (13 erreurs / 2 échecs, classes et routes absentes) puis verts.

ETAT: fini
