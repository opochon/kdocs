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

- `src/Ui/Module.php` : routes `GET /favoris`, `POST /favoris`, `POST /favoris/{id}/retirer`,
  `GET /liens-partages`, `POST /liens-partages/{id}/revoquer`, `POST /liens-partages/cle/{jeton}/revoquer`.
  Aucune route n'est déclarable ailleurs : `Kernel` (non modifiable) n'appelle que les modules de secteur
  déjà enregistrés.
- `src/Ui/Ecrans.php` : la fiche document expose `est_favori` et enregistre la consultation (recents) ;
  aucune autre donnée d'écran ne change.
- `src/Ui/Rail.php` : une entrée principale « Favoris » (découvrabilité de l'espace perso).
- `templates/ecrans/document.twig` : bouton « Favori » (bascule) et lien vers les liens partagés ;
  `templates/ecrans/espace-perso.twig`, `templates/ecrans/liens-partages.twig` (nouveaux).

## Demandes au Socle / à Interface

- _(aucune pour l'instant)_ : `Registre` (configuration portable), `Base::pdo`, `DroitsDossiers` et
  `Collaboration` sont consommés sans modifier leurs contrats. Un besoin de contrat partagé sera écrit ici.

## Journal

- 14.10.2026 — ouverture du lot `ged-mfiles-espace-1` (DeepCode) : favoris, récemment consultés et liens de
  partage, parité M-Files (famille espace perso & partage). Chemins posés ci-dessus.
