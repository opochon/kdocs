# Conventions communes — migration UI (à lire par CHAQUE agent)

Source de vérité visuelle : **`docs/DESIGN-SYSTEM-KARBONIC.md`** + **`public/css/design-system.css`**.

## But d'un agent

Dans **sa portée uniquement** : remplacer les **utilitaires Tailwind en dur** (couleurs/gris)
par les **tokens Karbonic** ou des **classes** (`.ds-*`, `.btn`, `.form-*`, `.badge`), de sorte
que la page soit **native** en clair **et** sombre — sans dépendre du shim §5.

## Règles d'or

1. **Aucune couleur/gris en dur.** Tout passe par un token (`var(--ink)`, `var(--surface)`…)
   ou une classe existante. Pas de `#hex` ni de `bg-gray-*` / `text-gray-*` neutres résiduels.
2. **Action primaire = `--primary` (anthracite)**, une seule par contexte. Le bleu `--accent`
   ne sert qu'au **focus / liens / sélection** — jamais à un aplat décoratif.
3. **Clair ET sombre** traités d'emblée (vérifier les deux avant EN REVUE).
4. **Préserver le comportement** : ne pas toucher au PHP/logique, aux `id`, `name`,
   `data-*`, hooks JS, ni aux routes. Refactor **visuel** seulement.
5. **Réutiliser avant d'inventer** : préférer `.btn/.btn-primary`, `.form-input/select/textarea`,
   `.badge*` (theme.css, déjà tokenisés) et les `.ds-*` (design-system.css). Besoin récurrent
   non couvert → **ajouter une classe** dans `design-system.css` (pas du style en dur répété).
6. **Réduire le shim** : ce que tu migres n'a plus besoin du shim §5. Ne pas s'appuyer dessus.
7. **Îlots volontairement sombres** (`bg-gray-800/900` + `text-white` d'un bouton/encart déjà
   sombre) : convertir en tokens explicites, ne pas juste inverser.

## Cheat-sheet gris Tailwind → token

| Tailwind (clair) | Token | Usage |
|---|---|---|
| `bg-white` | `--surface` | cartes, champs, popovers |
| `bg-gray-50` | `--app-bg` | fond de page / zone en retrait |
| `bg-gray-100` | `--rail` | volets, colonnes |
| `bg-gray-200` | `--hover` | survol / zone active douce |
| `text-gray-900` / `800` | `--ink` | texte principal / titres |
| `text-gray-700` / `600` | `--ink-soft` | texte secondaire |
| `text-gray-500` / `400` | `--dim` | labels, méta |
| `text-gray-300` | `--muted` | très discret, placeholders |
| `border-gray-100` | `--border-soft` | séparateurs internes |
| `border-gray-200` / `300` | `--border` | bordures structurantes |
| `hover:bg-gray-50/100` | `--hover` | survol |
| `bg-blue-600` / `.btn-primary` (bouton) | `--primary` | action primaire (anthracite) |
| lien / focus / sélection | `--accent` | bleu doux, jamais décoratif |
| succès / alerte / erreur | `--green` / `--amber` / `--red` | états |

> En sombre, ces tokens basculent automatiquement (`.dark` dans `design-system.css`).
> Pour appliquer un token sans classe : `style="color:var(--ink)"` ou une petite classe dédiée.

## Gates de validation (avant de passer EN REVUE)

```cmd
REM 1. syntaxe PHP des fichiers touches
php -l <fichier>

REM 2. structure (doit rester 141/141)
php tests\migration_smoke_test.php

REM 3. tests (inchanges)
vendor\bin\phpunit --testsuite=unit
vendor\bin\phpunit --testsuite=feature

REM 4. rendu shell + SMQ (doit rester 7/7)
cd tests\visual && npm test

REM 5. revue manuelle CLAIR + SOMBRE des pages touchees
REM    (capturer via colorScheme dark, cf. methode session 2026-06-27)
```

Ne marquer **FAIT** que si : gates verts, aucune régression visuelle clair/sombre,
DOM/ids/JS préservés. Le **superviseur** intègre et commit (1 commit par portion).

## Cycle de statut

`À FAIRE` → `EN COURS` (agent démarre) → `EN REVUE` (portion finie, gates verts) →
`FAIT` (validé + commité par le superviseur). En cas de blocage : rester `EN COURS`
et noter le blocage dans le journal du job.
