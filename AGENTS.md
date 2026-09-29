# GEDv2 — contrat commun des agents

À lire en entier avant toute action. Court exprès : les détails vivent dans `docs/secteurs/<secteur>.md`.

## Ce qu'est GEDv2
Une GED dont **le dossier fait foi** : chaque document est un fichier lisible sans l'application, ses
métadonnées sont dans un `.meta.json` à côté de lui, la base MariaDB n'est qu'un index reconstructible.
Cahier des charges fonctionnel : Paperless-ngx 2.20 (fonctions, pas code : GPLv3 — aucune ligne copiée).
Cible métier : M-Files/RedX. Plan : `docs/PLAN-GEDV2.md`. Matrices : `docs/MATRICE-FONCTIONS.md`, `docs/MATRICE-FLUX.md`.

## Pile
PHP 8.4, Slim 4, Twig 3 (échappement automatique), MariaDB 11 (127.0.0.1:3307), JS natif sans framework,
CSS maison (`public/assets/css/ged.css`, jetons en tête de fichier). Moteur d'ingestion Python externe
(`cmd4_ingest`, dépôt ClearMyDocs) appelé comme service. Tests : PHPUnit, Playwright.
Doit tourner en hébergement mutualisé Infomaniak : aucun démon obligatoire, aucun appel PHP long
(traitements lourds = jobs courts avec point de reprise).

## Bases
- `gedv2` : base de dev. `gedv2_test` : base des tests (phpunit.xml la force). Jamais d'autre base.
- Jamais les bases `kdocs*`, `ktime*` ni aucun dépôt voisin en écriture.

## Règles non négociables
1. **Un agent = un secteur.** Tu ne modifies que les chemins de ton secteur (`docs/secteurs/<secteur>.md`,
   rubrique « Chemins »). Besoin ailleurs → tu l'écris dans `docs/secteurs/<secteur>.md`, rubrique « Demandes ».
2. **Oracle d'abord.** Tout lot commence par un test ou un oracle qui échoue (rouge), puis le code, puis vert.
   Une absence d'erreur ne vaut pas validation ; une ligne sans test vaut 0 dans le registre.
3. **Zéro donnée réelle, zéro nom de client** dans le dépôt : corpus synthétiques, noms fictifs.
4. **Zéro suppression** de donnée utilisateur par le produit : corbeille, versions, journal.
5. **Interface** : chaque élément actionnable porte un `data-testid` ; barre d'actions à gauche sous le titre ;
   écrans compacts ; une erreur se nomme (étape, cause), jamais un 500 muet ; aucun écran > 1,5 s.
6. **Commits** : un commit par étape verte, message en français `secteur: ce qui change`, jamais `git add -A`,
   jamais de push. Fin de lot : dernière ligne du rapport `ETAT: <fini|bloqué: raison>`.
7. Le juge d'un lot n'est jamais son auteur : la porte est rejouée par l'agent désigné dans le secteur.

## Contrats entre secteurs
`src/Contrats/` : interfaces partagées (ex. `Depot`, implémenté par Socle, appelé par Ingestion et Classement).
Seul l'agent de vue d'ensemble les modifie ; un secteur qui a besoin d'un changement l'écrit dans ses Demandes.
Chaque secteur expose ses routes par `src/<Secteur>/Module.php` (`enregistrer(App, Config, Vue)`), appelé par
`src/Kernel.php` : personne ne modifie Kernel.
Connexion à la base : `Ged\Base::pdo($config)` (une seule, partagée) ; ne crée pas la tienne.
Tables d'un secteur : `src/<Secteur>/Schema.php` avec `public static function installer(\PDO $pdo): void` (CREATE TABLE
IF NOT EXISTS, idempotent) ; l'installateur du Socle appelle celui de chaque secteur présent.

## Commandes
- `powershell -File tools\serve.ps1` : serveur de dev sur http://127.0.0.1:8870
- `powershell -File tools\harness.ps1` : garde-fous + PHPUnit + Playwright + registre (`recette/registre.json`)
