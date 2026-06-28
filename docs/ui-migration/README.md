# Pilotage migration UI — design system Karbonic

Dossier de **coordination multi-agents** pour terminer l'uniformisation UI :
migrer les **templates de contenu** (utilitaires Tailwind en dur) vers les **tokens
Karbonic** / classes `.ds-*`, et **retirer le shim** sombre à terme.

> **Acquis (baseline `e999daa`)** : lot *fondation + chrome* livré — tokens clair/sombre,
> chrome (`.ds-*`), bascule de thème, shim sombre transitoire. Voir `SESSION-STATUS.md`
> et `docs/DESIGN-SYSTEM-KARBONIC.md`. **Ce dossier ne concerne que le RESTE.**

## Fichiers

| Fichier | Rôle |
|---------|------|
| `SUPERVISOR.md` | Agent de contrôle : goal, priorités, **tableau de statut**, boucle de pilotage |
| `CONVENTIONS.md` | Règles communes + cheat-sheet gris→token + **gates** de validation (à lire par chaque agent) |
| `agent-A-composants.md` | Composants & partials transverses (levier max, à faire en 1er) |
| `agent-B-documents.md` | Cœur produit : `documents/*` |
| `agent-C-dashboard-recherche-taches.md` | `dashboard/*`, `search/*`, `tasks/*`, `chat/*` |
| `agent-D-admin-formulaires.md` | `admin/settings` + tous les `*_form.php` |
| `agent-E-admin-outillage.md` | Listes & outillage admin + `workflow/designer` |
| `agent-F-auth-erreurs.md` | `auth/login`, `errors/*` (reste) |

## Comment ça marche (clear & relance)

1. Le **superviseur** (`SUPERVISOR.md`) tient le tableau de statut et fixe l'ordre.
2. On lance **un agent à la fois**, contexte propre. Prompt type :
   > « Lis `docs/ui-migration/CONVENTIONS.md` puis `docs/ui-migration/agent-X-….md`.
   >   Exécute ta portion en respectant les contraintes, lance les gates, passe ton
   >   statut à **EN REVUE** et liste les fichiers modifiés. »
3. L'agent fait sa portion → statut **EN REVUE**.
4. Le superviseur **valide** (gates + revue clair/sombre), **commit** (1 commit/portion),
   passe le statut à **FAIT**, met à jour le board, relance le suivant.

Statuts : `À FAIRE` → `EN COURS` → `EN REVUE` → `FAIT`.
