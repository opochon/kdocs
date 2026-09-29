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
