# Secteur INTERFACE — écrans, système visuel, mobile

Agent : GLM (sur maquettes validées) · Juge : Claude Code (personas) · Lot en cours : MAQUETTES → validation d'Olivier

## Mission
Six écrans maîtres (À traiter, Bibliothèque, Recherche, Fiche document, Workflows, Application/Administration), servis par
des gabarits Twig et un seul système visuel (`public/assets/css/ged.css`, jetons en tête). Tout autre écran est une
variante de l'un d'eux. Pratique, rapide, beau : compact, clavier d'abord, clair et sombre.

## Chemins
`templates/`, `public/assets/`, `src/Ui/`, `tests/e2e/`

## Règles (chacune a son test dans `tests/e2e/`)
Barre d'actions à gauche sous le titre ; une seule source de menu ; aucun défilement horizontal ; chaque élément actionnable
a un `data-testid` ; aucun écran > 1,5 s ; 0 erreur console ; erreurs nommées ; dossier d'ingestion jamais montré ;
couleurs uniquement par jetons ; le mobile a ses propres écrans.

## État au 29-09
Lot MAQUETTES fait : six écrans sur données fixes (`src/Ui/Maquette.php`), 12 tests Playwright verts, captures claires et
sombres dans `var/captures/`. En attente : la validation d'Olivier, écran par écran. Ensuite chaque secteur branche ses
vraies données sans changer le gabarit.

## Demande reçue de ARCHIVAGE (V2-09, DeepCode, 29-09)
Deux branchements à prévoir quand le Kernel appellera `Archivage\Module` (voir demandes du secteur) :
- sur la **fiche document**, un bouton « Sceller » (`data-testid="document-sceller"`), barre d'actions à
  gauche sous le titre, qui appelle `POST /documents/{id}/sceller` (permission `archivage:sceller`) ;
- une **liste de rétention** (« à échéance ») servie par `Ged\Archivage\Retention::aEcheance()`, avec le
  geste humain « Lever le scellement » (`Retention::lever()`) et son échéance affichée — jamais
  d'effacement automatique, le refus d'écriture se nomme (« scellé jusqu'au … »).

## Journal V2-03-écran — 29-09-2026

**Fait**
- `Ged\Ui\ATraiter` : l'écran « À traiter » lit le FONDS réel (via `Ged\Socle\DepotFichiers`, le dossier fait
  foi : une ligne d'index dont le fichier a disparu est ignorée). Bilan = classés automatiquement / à
  contrôler (statut des documents vivants) + en cours de lecture (travaux `recu|decoupe|pret`) ; file des
  `a_controler` du plus ancien au plus récent ; proposition du Classeur telle qu'écrite à l'ingestion
  (`source` IA/habitude/règle, `raison`, `preuve`) ; aperçu réel par la macro `visionneuse.moteur` du
  secteur Aperçu ; types lus dans la nomenclature (`Nomenclature`), repli maquette.
- `Ui\Ecrans::aTraiter` branché sur l'adaptateur ; **le gabarit garde ses classes, ses data-testid et sa
  disposition** : seuls sont ajoutés les `name` des champs, le champ caché `csrf` et l'action POST.
- `Ui\Module` : `POST /a-traiter/{id:[0-9]+}/valider` (permission `documents:traiter`). Valider (Entrée)
  écrit par `Ged\Contrats\Depot::mettreAJour` (statut `valide`, rangement recalculé, trace) puis enseigne
  l'habitude par `Ged\Classement\Habitudes::retenirChoix` — **choix humain seulement** ; Passer (→) va au
  suivant. Tout au clavier (`public/assets/js/ged.js`).
- `templates/partiels/visionneuse.twig` : l'`import` d'icônes est porté DANS la macro `moteur` (une macro
  importée n'exécute pas le corps du gabarit, `ic` manquait à l'import). Correctif d'une ligne, chemin du
  secteur Interface (`templates/`) ; signalé à Recherche & aperçu.

**Preuves**
- Oracle d'abord : `tests/e2e/a-traiter.spec.ts` écrit rouge (l'écran rendait encore les données fixes),
  puis vert. Fonds déposé par `tests/oracles/interface/preparer-a-traiter.php` via `DepotFichiers` dans
  `var/oracles/interface/` (**jamais dans `tests/corpus`**) ; parcours connecté (`tests/e2e/connexion.ts`)
  sur un serveur isolé, **trois validations à Entrée sans souris**, puis
  `tests/oracles/interface/classer-piece-4.php` rejoue le vrai classeur (IA inerte) : la 4ᵉ pièce du même
  émetteur arrive en source **« habitude »**, `valide`. 3 parcours verts (dont « Passer » (→) qui va au
  suivant sans rien écrire).
- `vendor/bin/phpunit` : **241 tests, 1073 assertions** verts (dont `SecuriteTest` qui vérifie que la
  nouvelle route déclare sa permission).
- `php tools/banc/sonde-securite.php` : **VERT** (CSRF absent/rejoué refusé sur la nouvelle mutation).
- `tests/e2e/maquettes.spec.ts` : l'ancien parcours clavier (faux documents 101→103, sémantique changée par
  la validation réelle) devient un contrôle du contrat visuel ; le comportement vit dans l'oracle.

**Modifications minimales signalées (hors secteur, assumées)**
- `src/Kernel.php` : « Ui » ajouté à la liste des modules de secteur — l'écran porte désormais une route.
  Signalé ici et dans les demandes ci-dessous.
- `src/Securite/DroitsRoutes.php` : permission `POST /a-traiter/{id:[0-9]+}/valider` + reconnaissance de la
  route paramétrée (signalé dans `docs/secteurs/droits-securite.md`).
- `tools/banc/sonde-securite.php` : charge CSRF défensive de la nouvelle mutation (signalé dans
  `docs/secteurs/harness.md` et `droits-securite.md`).

**Demandes aux autres secteurs**
- **Socle** : `DepotFichiers` n'écrit toujours pas l'`id` dans le `.meta.json` ; l'aperçu réel de
  `/a-traiter` résout la pièce par `id`, l'oracle le pose donc après dépôt. Poser l'`id` au dépôt rendrait le
  dossier auto-descriptible (déjà demandé par Workflows/Recherche).
- **Vue d'ensemble** : la ligne « Ui » ajoutée à `src/Kernel.php` est une modification minimale signalée ;
  à confirmer/reporter dans le contrat si le Kernel doit rester figé.
- **Recherche & aperçu** : la macro `visionneuse.moteur` était inutilisable à l'import (import d'icônes hors
  macro) ; corrigé (voir ci-dessus), à réutiliser dans `bibliotheque.twig` / `document.twig`.
- **Droits & sécurité** : le refus de validation (permission manquante, CSRF) est porté par la garde
  centrale ; l'écran n'a pas de garde propre.

**Reste hors de ce lot**
- Brancher les autres écrans (bibliothèque, fiche) sur le fonds : suivis par leurs secteurs (Socle/Lot V2-04).
- `harness.ps1 -SansBanc` (dernier rejeu, 29-09) : 0-garde-fous VERT, 7-securite VERT ; 4-écrans ROUGE avec
  6 échecs **hors lot** — `bibliotheque.spec` (routes Socle V2-04 absentes : `/metadonnees`, vues réelles),
  `recherche.spec` (l'index de la base partagée porte les ids de PHPUnit, attend les ids 201/204 du corpus),
  `apercu.spec:18` (flaky sous charge parallèle, vert en isolé) ; les **3 parcours `a-traiter`** et les 12
  parcours maquettes sont verts.
- 1-unitaire : **241/241 tests, 1073 assertions passent** (rejeu direct du lot : OK, vert). Le dernier
  harness l'a compté ROUGE sur 2 « PHPUnit warnings » introduits par un travail concurrent hors lot
  (`phpunit.xml` + `tests/contrats/` non commités d'un autre agent : classes de contrat abstraites =
  avertissements, `failOnWarning=true`). Aucun test en échec ; hors périmètre Interface.

ETAT: fini



## Jugement V2-03-écran (Claude Code, 29-09) — porte franchie
Rejoué sur le code commité : unitaire 212/212, 3 bancs verts, écrans 29/29 dont les 3 parcours « À traiter » (trois validations à Entrée, la 4e pièce en habitude, « Passer » sans écriture), sonde de sécurité verte. Le dessin validable par Olivier est inchangé (mêmes classes et data-testid).
ETAT: fini (jugé)

## Demandes du HARNESS (lot H-6 « 6-charge », 29-09-2026)

Mesure : fonds synthétique de 10 000 documents (`var/charge/`, `tools/banc/charge.php`), 3 puis 10 utilisateurs
simultanés, tâche d'ingestion `courrier-100p` en fond. Preuves : `recette/preuves/charge/charge.{json,md}`.

- **`/a-traiter` ne tient pas le seuil (défaut nommé)** : p50 2,9 s / p95 3,4 s à 3 utilisateurs ;
  p50 5,2 s / p95 5,5 s à 10. Cause : `Ged\Ui\ATraiter::documents()` appelle `DepotFichiers::lister()`
  (toutes les lignes de l'index) puis fait un `is_file` **par document** à chaque rendu, et `donnees()`
  reparcourt ces documents pour le bilan — le coût croît avec la taille du fonds, pas avec la file.
  Attendu : borner le travail au statut `a_controler` (requête indexée) et ne plus `stat`/décoder tout le
  fonds à chaque GET. La couche 6 (< 1,5 s en lecture) reste donc ROUGE tant que ce n'est pas corrigé.

Aucune autre attente : les validations (écriture) tiennent le seuil (p95 2,6–2,9 s < 3 s), 0 collision,
0 erreur, la tâche d'ingestion finit.
