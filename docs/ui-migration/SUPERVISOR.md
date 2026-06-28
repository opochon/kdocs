# Agent SUPERVISEUR — pilotage migration UI

**Rôle.** Ne migre pas lui-même. Il **séquence**, **valide** (gates + revue clair/sombre),
**intègre** (1 commit par portion), **tient le tableau de statut**, et **relance** l'agent
suivant. Source des règles : `CONVENTIONS.md`.

## Goal global

Terminer l'uniformisation UI : tous les **templates de contenu** passent en tokens Karbonic /
classes (`.ds-*`, `.btn`, `.form-*`, `.badge`), natifs **clair + sombre**, puis **retrait du shim**
sombre transitoire. Découpage par **priorité produit** :

- **P1 — cœur produit** : ce que l'utilisateur voit le plus → Agents **A** (transverse), **B** (documents), **C** (dashboard/recherche/tâches/chat).
- **P2 — admin (gros volume)** : Agents **D** (paramètres + formulaires), **E** (listes & outillage).
- **P3 — divers** : reliquats répartis dans **C/E** (chat, designer, attribution-rules…).
- **Reste / phase finale (superviseur)** : Agent **F** (auth/erreurs) ; puis **retrait du shim §5**,
  **thème Chart.js** (couleurs canvas non pilotées par tokens), **densité picto/hint** (`DESIGN-SYSTEM-KARBONIC.md` §6),
  **audit `documents/index_clean.php`** (doublon legacy probable → supprimer plutôt que migrer).

## Tableau de statut

> **Baseline : `e999daa`** (fondation + chrome). **État actuel : tout `À FAIRE` (clear).**

| Agent | Portée | Prio | Dépend de | Statut | Commit |
|-------|--------|------|-----------|--------|--------|
| **A** — Composants & partials | `components/ui/*`, `components/*`, `partials/*` (hors chrome) | P1 ⭐transverse | — | `À FAIRE` | — |
| **B** — Documents (cœur) | `documents/*` | P1 | A | `À FAIRE` | — |
| **C** — Dashboard / Recherche / Tâches / Chat | `dashboard/*`, `search/*`, `tasks/*`, `chat/*` | P1 / P3 | A | `À FAIRE` | — |
| **D** — Admin : paramètres & formulaires | `admin/settings`, `admin/*_form`, `…/editor`, `…/form` | P2 | A | `À FAIRE` | — |
| **E** — Admin : listes & outillage | `admin/{listes,outillage}`, `workflow/designer` | P2 / P3 | A | `À FAIRE` | — |
| **F** — Auth & erreurs | `auth/login`, `errors/*` | reste | — | `À FAIRE` | — |
| **★ Finale** — retrait shim + Chart.js + densité + audit index_clean | transverse | reste | A–F | `À FAIRE` | — |

## Ordre conseillé & dépendances

```
A  (transverse — débloque tout)
└─► B ∥ C        (cœur produit, en parallèle possible)
        └─► D ∥ E   (admin)
                └─► F
                    └─► ★ Finale (retrait shim, Chart.js, densité, audit)
```

**Pourquoi A en premier** : `components/ui/{button,badge,card}` et les partials sont inclus par
de nombreuses pages ; les tokeniser corrige B/C/D/E d'un coup et évite des conflits de fusion.

## Boucle de pilotage (par agent)

1. **Pré-check** : `git status` propre, baseline verte (`migration_smoke` + `npm test`).
2. **Lancer** l'agent (contexte propre) avec le prompt type du `README.md`.
3. Agent → portion + gates → statut **EN REVUE**.
4. **Valider** : relire le diff, relancer les gates, **revue clair + sombre** des pages touchées.
   - KO → renvoyer à l'agent (statut **EN COURS**, blocage noté dans son journal).
5. **Commit** la portion : `feat(ged): migration UI agent X — <portée>` (gates ok, clair+sombre revus).
6. **Mettre à jour** ce tableau (statut **FAIT** + hash), puis relancer l'agent suivant.
7. Quand A–F **FAIT** → exécuter la **phase finale**, puis mettre à jour `SESSION-STATUS.md`.

## Définition de « terminé » (global)

- 0 utilitaire gris/blanc Tailwind neutre résiduel dans les templates de contenu.
- Shim §5 de `design-system.css` retiré (ou réduit à un reliquat documenté).
- `migration_smoke` 141/141, `phpunit` inchangé, `phpstan [OK]`, Playwright 7/7, live-smokes 64 OK.
- Revue clair + sombre OK sur l'ensemble des écrans.
