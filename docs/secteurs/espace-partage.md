# Secteur ESPACE PERSONNEL & PARTAGE — favoris, récemment consultés, liens de partage

Agent : DeepCode (lot `ged-mfiles-espace-1`) · Juge : l'agent désigné par l'orchestrateur (porte rejouée, jamais par l'auteur).

## Mission

Porter les fonctions M-Files aujourd'hui `absent` du registre de parité (`docs/parite/PARITE-MFILES.md`) qui
relèvent de l'**espace personnel** et du **partage** :

- **favoris** (`favorites`) : lister mes favoris, savoir si un document est en favori, l'ajouter, le retirer ;
- **récemment consultés** (`recentlyaccessedbyme`) : lister les documents récemment consultés, enregistrer une
  consultation ;
- **liens de partage de fichier** (`sharedlinks`) : lister les liens, en créer un, télécharger par le lien,
  révoquer un lien.

Sont hors de ce lot (autre agent) : aperçu / vignette / titre / historique / commentaires / check-out.

## Principes tenus

- **Le dossier fait foi.** Un favori et une consultation sont des données d'**index** : ils vivent dans le
  registre portable du fonds (`.ged/favoris.json`, `.ged/recents.json`, via `Ged\Socle\Registre`), jamais une
  copie du document. Le document reste le fichier + son `.meta.json` à côté.
- **Un lien de partage n'expose jamais un document hors des droits de qui le crée** : le service vérifie le
  droit de lecture à la création (`Collaboration::partagerExterne`), le lien n'ouvre QUE le document partagé, et
  un lien expiré ou révoqué se refuse **en se nommant** (403 nommé, jamais un 500 muet).
- **Zéro secret en clair** : seul le condensat SHA-256 du jeton est conservé (base et journal d'audit) ; le jeton
  n'apparaît qu'à la création, dans un flash de session (jamais dans une URL d'écran).
- **Zéro suppression** : un favori retiré porte une date de retrait (la ligne reste traçable) ; un lien révoqué
  porte `revoque_le` ; aucun `DELETE` SQL.

## Chemins (seuls modifiables par ce secteur)

- `src/EspacePartage/` : `EspacePerso.php` (favoris + récents, registre portable, filtrage ACL),
  `Partages.php` (lecture des liens externes, révocation par clé publique, composition de `Collaboration`).
- `docs/secteurs/espace-partage.md`, `docs/lots/ged-mfiles-espace-1.md`.
- Preuves : `tests/php/EspacePartage/` (unitaires + vecteurs), `tests/parite/vecteurs/mfiles-espace-*.json`,
  `tests/e2e/espace-perso.spec.ts`, `tests/e2e/preparer-espace-perso.php`.
- Registre de parité : `tools/parite/corriger-correspondance-mfiles.php`,
  `docs/parite/correspondance.json`, `docs/parite/PARITE-MFILES.md`, `recette/parite-mfiles.json`.

## Modifications minimales signalées (hors chemins du secteur)

- `src/Ui/Module.php` : routes `GET /favoris`, `POST /documents/{id}/favori`,
  `POST /documents/{id}/favori/retirer`, `POST /favoris/{id}/retirer`, `GET /liens-partages`,
  `POST /liens-partages/{id}/revoquer`, `POST /liens-partages/cle/{jeton}/revoquer`. Aucune route n'est
  déclarable ailleurs : `Kernel` (non modifiable) n'appelle que les modules de secteur déjà enregistrés.
- `src/Securite/DroitsRoutes.php` : déclaration des permissions des routes ci-dessus (une route absente y
  est interdite : le favori est un geste de LECTEUR, la révocation d'un lien garde `documents:partager-externe`).
- `src/Ui/Ecrans.php` : la fiche document expose `est_favori`/`document_reel` et enregistre la consultation
  (récents) ; aucune autre donnée d'écran ne change.
- `src/Ui/Rail.php` : une entrée principale « Mon espace » (`/favoris`) pour la découvrabilité.
- `templates/ecrans/document.twig` : bouton « Favori » (bascule) et lien vers les liens partagés ;
  `templates/ecrans/espace-perso.twig`, `templates/ecrans/liens-partages.twig`,
  `templates/partiels/icones.twig` (icône `favori`).
- `tools/parite/corriger-correspondance-mfiles.php` : une exclusion RATIFIÉE par le juge est désormais
  conservée telle quelle (elle n'est plus dégradée en `absent` par le générateur).

## Demandes au Socle / à Interface

- _(aucune pour l'instant)_ : `Registre` (configuration portable), `Base::pdo`, `DroitsDossiers` et
  `Collaboration` sont consommés sans modifier leurs contrats. Un besoin de contrat partagé sera écrit ici.

## Journal

- 14.10.2026 — ouverture du lot `ged-mfiles-espace-1` (DeepCode) : favoris, récemment consultés et liens de
  partage, parité M-Files (famille espace perso & partage). Chemins posés ci-dessus.
- 14.10.2026 — livré : services `Ged\EspacePartage\EspacePerso` (favoris/récents, registre portable,
  filtrage ACL) et `Ged\EspacePartage\Partages` (liens externes, révocation par clé/identifiant sous droits),
  écrans `/favoris` et `/liens-partages`, favori sur la fiche, parcours e2e vert. Parité M-Files **66/112,
  58,93 %, 0 non-prouvé** (hors exclusions 69,47 %).

