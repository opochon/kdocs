# Classement — lot `courrier-100p` — avec-ia — 2026-10-01 17:08

Porte V2-03 : **NON FRANCHIE** (via `Ged\Classement\ClasseurGed`, seuil 0.8)

| Mesure | Attendu | Obtenu |
|---|---|---|
| Documents | 53 | 53 |
| Validés seuls | — | 44 (83 %) |
| Validés seuls attendus (avec-ia) | 46 | 44 |
| Validés seuls, type faux | 0 | 0 |
| Types faux (validés ou non) | 0 | 2 |
| Émetteurs lus | — | 53 |
| Émetteurs faux | 0 | 0 |
| « Autre » validé seul | 0 | 0 |
| Dates fausses (en-tête) | 0 | 0 |
| Titres invalides (vide ou nom du lot) | 0 | 0 |
| Appels IA | — | 53 |
| Temps IA / pièce | — | 7.375 s |
| Durée | — | 390.95 s |

## Anomalies
- pages 21 : type `Attestation` (attendu `Document RH`), émetteur `Commune de Modeleville` (attendu `Commune de Modeleville`), date `2026-01-22` (attendu `2026-01-22`), titre `Attestation de travail - Jean Exemple`
- pages 37,38,39,40,41,42 : type `Police d’assurance` (attendu `Contrat`), émetteur `Assurances Demonstration SA` (attendu `Assurances Demonstration SA`), date `2026-03-01` (attendu `2026-03-01`), titre `Police responsabilité civile entreprise n° RC-000123`

## Laissés à contrôler
- pages 15 : `Reçu` proposé — émetteur à contrôler : IA et texte ne concordent pas
- pages 21 : `Attestation` proposé — sous-type IA à contrôler : Attestation sans indice propre dans le texte (texte=Document RH)
- pages 22 : `Facture` proposé — IA indisponible
- pages 37,38,39,40,41,42 : `Police d’assurance` proposé — sous-type IA à contrôler : Police d’assurance sans indice propre dans le texte (texte=Contrat)
- pages 47 : `Reçu` proposé — IA indisponible
- pages 63,64 : `Courrier` proposé — IA indisponible
- pages 73,74 : `Courrier` proposé — IA indisponible
- pages 76,77 : `Facture` proposé — IA indisponible
- pages 95 : `Reçu` proposé — IA indisponible

