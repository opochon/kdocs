# Rapports d'agents (archive)

Ce répertoire regroupe des **rapports rédigés par des agents lors de sessions passées**
(diagnostics, correctifs ponctuels, campagnes de test). Ce sont des comptes rendus
historiques, pas de la documentation vivante — pour l'état courant du produit, voir
`docs/ARCHITECTURE.md`, `docs/WORKLOG.md` et le reste de `docs/`. Un rapport ici peut
décrire un correctif intégré depuis longtemps ou, à l'inverse, un diagnostic jamais traité :
le statut indiqué pour chacun ci-dessous fait la différence.

Ces 8 fichiers vivaient à la racine du dépôt sous le nom `RAPPORT_*.md` (pattern
ignoré par `.gitignore`, donc jamais suivis par git avant ce rangement — voir note
en bas de page).

## Index

| Rapport | Date | Ce qu'il établit | Statut |
|---|---|---|---|
| [`agent3-fallback-ollama.md`](agent3-fallback-ollama.md) | 2026-02-04 19:00 | Bascule automatique Claude → Ollama sur erreur HTTP 402/429 (crédits épuisés, rate limit), avec messages et métadonnées `_provider`/`_fallback_used` dans la réponse API. | **Intégré.** Le code (`should_fallback` dans `ClaudeService.php`/`AIProviderService.php`) est toujours en place ; commit `0173582` présent dans l'historique. |
| [`analyse-document-52.md`](analyse-document-52.md) | 2026-02-04 19:30 | Diagnostic de 5 problèmes sur la fiche document (boutons ✓/✗ mal branchés, toggle de validation à 3 états incompatible avec l'ENUM DB, suggestions IA vides, métadonnées non extraites). | **Partiellement périmé.** Les points 1 à 4 (boutons, toggle, debug IA) ont été corrigés par les rapports suivants (`execution-complete.md`, `final-corrections-document-52.md`). Le point 5 (métadonnées manquantes sur ce document précis) reste **indéterminé** — aucun rapport de suivi ne le referme. |
| [`correction-date-bouton.md`](correction-date-bouton.md) | 2026-02-04 20:15 | Correctif du format de date non appliqué après suggestion IA (conversion vers `YYYY-MM-DD`), et renommage du bouton « Suggestions IA » → « Suggestion : analyser ». Commit `0761815`. | **Intégré pour `templates/documents/index.php`** (texte du bouton et logique de date toujours présents). La seconde occurrence citée, `templates/documents/show.php`, **n'existe plus** dans le dépôt — fichier vraisemblablement refondu depuis ; mention obsolète. |
| [`execution-complete.md`](execution-complete.md) | 2026-02-04 20:00 | Récapitulatif des 4 correctifs du document 52 (boutons ✓/✗, toggle validation avec mapping `na` ↔ `NULL`, debug suggestions IA). Commits `3ee32a9` et `b6c0d60`. | **Intégré.** Contenu très proche de `final-corrections-document-52.md` (probable doublon/brouillon successif du même travail) ; le code correspondant (`toggleValidationStatus`, mapping `na`/`NULL` dans `ValidationService.php` et `ValidationApiController.php`) est présent aujourd'hui. |
| [`final-corrections-document-52.md`](final-corrections-document-52.md) | 2026-02-04 20:00 | Version détaillée du même récapitulatif que `execution-complete.md`, avec extraits de code avant/après pour chaque correctif. | **Intégré**, mêmes vérifications que ci-dessus. À lire comme la version « longue » de `execution-complete.md` plutôt que comme un rapport distinct. |
| [`ocr-complet.md`](ocr-complet.md) | 2026-02-04 21:00 | Correction de l'erreur SQL `Data too long for column 'content'` (limite `TEXT` MySQL à 65 535 caractères) : troncature à 65 000 caractères sur les 10 points d'insertion/mise à jour identifiés, dans 4 fichiers. | **Intégré.** La troncature à 65000 est toujours présente dans `DocumentProcessor.php` et `DocumentsApiController.php`. Recommandation finale (migrer `content`/`ocr_text` vers `LONGTEXT`) **non appliquée** — les migrations existantes n'ont pas ce changement ; reste une piste ouverte. |
| [`ocr-correspondant.md`](ocr-correspondant.md) | 2026-02-04 20:30 | Version antérieure et partielle du correctif de troncature OCR (5 endroits sur 2 fichiers), plus l'amélioration du matching de correspondant IA (match exact, partiel, par mots-clés) dans `AIClassifierService.php`. | **Intégré mais superseded.** Le volet troncature est complété par `ocr-complet.md` (10 endroits). Le volet matching de correspondant reste d'actualité : le code à 3 stratégies est toujours présent dans `AIClassifierService.php`. |
| [`test-ocr-avant-ia.md`](test-ocr-avant-ia.md) | 2026-02-04 21:30 | Correctif pour que l'OCR soit déclenché avant l'analyse IA quand le contenu est vide ou insuffisant (`classifyWithAI()`), avec rechargement du document avant classification. | **Intégré.** La vérification `hasContent`/`hasOcrText` et le déclenchement OCR conditionnel sont toujours présents dans `DocumentsApiController.php`. |

## Note sur l'historique git

Ces 8 fichiers correspondaient au motif `RAPPORT_*.md` du `.gitignore` racine (ligne
`RAPPORT_*.md`) : ils n'ont donc jamais été suivis par git avant leur déplacement ici.
Le rangement dans ce répertoire a été fait par déplacement simple (le nouveau nom ne
correspond à aucun motif d'exclusion) suivi d'un ajout git — il n'existe pas d'historique
de renommage à faire remonter, puisqu'il n'y avait pas de suivi préalable.
