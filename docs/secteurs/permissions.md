# Secteur PERMISSIONS DYNAMIQUES — droits par classe/métadonnée/état, visibilité conditionnelle

Agent : DeepCode (lot `ged-mfiles-permissions-1`) · Juge : Claude sur le poste principal.

## Mission

Porter la **famille M-Files « permissions dynamiques »** (`docs/PLAN-GEDV2.md`, `docs/parite/PARITE-MFILES.md`) :

- **droits selon la classe et les métadonnées** : l'accès (voir / modifier) dépend de la classe du document et
  de valeurs de champs (ex. seules les personnes marquées « comptables » voient les factures ; un dossier
  confidentiel n'est visible que par son responsable). S'appuie sur la nomenclature, les listes et les champs
  typés déjà portés par la famille 1 ;
- **droits selon l'état** (le moteur `Ged\Workflows` est déjà présent) : une règle peut viser un état de circuit ;
  l'état effectif d'un document se lit par le service `Ged\Permissions\Moteur` (même lecture que
  `Ged\Workflows\Circuits`, aucune seconde machine à états) ;
- **visibilité conditionnelle** : la règle est évaluée dynamiquement à l'affichage ET à la recherche ; un document
  non visible pour l'utilisateur n'apparaît ni dans la liste Bibliothèque ni dans les résultats de Recherche ;
- **définition des règles** : règles **nommées**, **portables et versionnées** dans le fonds
  (`.ged/permissions.json`, via `Ged\Socle\Registre`) — pas de coffre propriétaire — et **écran de gestion**
  (`/permissions`) branché sur les vraies règles.

Les règles dynamiques ne font que **restreindre** : elles n'accordent jamais un droit que l'ACL de dossier
(`Ged\Securite\DroitsDossiers`) ou la garde centrale n'auraient pas déjà donné. La sécurité en place n'est
jamais affaiblie.

Sont hors de ce lot et restent `absent` (proposés au juge) : le coffre/vault M-Files comme substitut du système
de fichiers, les vues personnelles par utilisateur, et les éléments REST M-Files de **structure de workflows**
(`/structure/workflows*`) qui appartiennent à la famille workflows (nº 4) : ce lot n'en couvre que la lecture de
l'état porté par l'objet (`workflowstate`), axe « droits selon l'état ».

## Chemins (seuls modifiables par ce secteur)

- `src/Permissions/` : `Regles.php` (règles nommées portables dans le fonds), `Moteur.php` (évaluation
  classe / métadonnée / état + clause de visibilité pour les listes et la recherche), `Ecran.php` (écran de
  gestion), `Module.php` (routes `/permissions*`).
- `templates/ecrans/permissions.twig`, styles `ged.css` (bloc `perm-*`).
- Preuves : `tests/php/Permissions/` (unités), `tests/php/Parite/MfilesPermissionsVecteursTest.php`,
  `tests/parite/vecteurs/mfiles-permissions-*.json`, `tests/e2e/permissions.spec.ts`, `tests/oracles/`.
- Oracle M-Files : `tools/parite/corriger-correspondance-mfiles.php` (section famille 4),
  `docs/parite/correspondance.json`.
- Journal du lot : `docs/lots/ged-mfiles-permissions-1.md`.

## Modifications minimales signalées (hors chemins du secteur)

- `src/Kernel.php` : une ligne, `'Permissions'` ajouté à la liste des modules (`Ged\Permissions\Module`). Même
  mécanisme que `DepotFileserver` (famille 6), signalé dans ce fichier.
- `src/Securite/DroitsRoutes.php` : déclaration des routes `/permissions*` (lecture + mutations). Aucune autre
  permission touchée.
- `tools/banc/sonde-securite.php` : charges CSRF défensives des mutations `/permissions*` (une entrée par route
  déclarée, sinon la couche 7 refuse une mutation non couverte).
- `src/Socle/EcranBibliotheque.php` et `src/Recherche/Service.php` : application de la clause de visibilité
  conditionnelle (lecture seule des règles ; aucune écriture, aucun second moteur). Aucun contrat modifié.

## Demandes

- **Aux Droits** : la garde centrale continue de refuser par défaut et la visibilité conditionnelle s'ajoute
  APRÈS l'ACL de dossier ; aucune règle dynamique ne doit accorder un accès que l'ACL refuse. Si un jour la
  garde traite la fiche/aperçu d'un document masqué par une règle dynamique, la décision devra être la même
  que celle de la liste (omission) ; le présent lot n'agit que sur la liste et la recherche.
- **À l'Interface** : le lien « Permissions » de la barre d'actions de l'écran d'administration pourra être
  aligné sur la maquette définitive ; le bloc `perm-*` de `ged.css` sert de base.

## Journal

- 14.10.2026 — ouverture du lot `ged-mfiles-permissions-1` (DeepCode) : règles nommées portables, évaluation
  classe / métadonnée / état, visibilité conditionnelle liste + recherche, écran de gestion, parité M-Files.
