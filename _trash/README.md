# _trash — code retiré du tronc actif

Dossier « corbeille » du dépôt. On y **déplace** (jamais on ne supprime à la dure) le code mort
sorti de l'arborescence active. Le fichier reste donc présent dans le dépôt et l'historique git —
**zéro risque de perte**, et il peut être restauré ou purgé plus tard sur décision explicite.

Convention : conserver le chemin d'origine sous `_trash/` (ex. `_trash/templates/documents/show.php`).

## Contenu

| Déplacé le | Origine | Raison |
|------------|---------|--------|
| 2026-06-27 | `templates/documents/show.php` | Fiche document legacy : `DocumentsController::show` redirige toujours vers `/documents?open={id}` (la fiche est la modale). Template jamais rendu. |
