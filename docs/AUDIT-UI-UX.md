# GEDv1 (K-Docs) — Audit UI/UX

> Date : 2026-06-18 · URL auditée : `http://127.0.0.1:8765/kdocs/`  
> Méthode : parcours templates/CSS + inspection live (browser MCP) — login auto root, pages clés

---

## Synthèse ressenti

| Critère | Score pro (1–10) | Ressenti |
|---------|-------------------|----------|
| **Global « produit pro »** | **3,5 / 10** | Interface **brouillonne**, utilisable par un power-user dev mais pas présentable client/fiduciaire |
| Cohérence visuelle | 4 | Tailwind + `app.css` tentent Paperless ; résultat hétérogène |
| Densité / lisibilité | 4 | Sidebar surchargée, triple colonne documents confuse |
| Navigation | 3 | Mélange navigation utilisateur + admin + référentiels sans séparation |
| Feedback utilisateur | 4 | Toasts partiels, états vides peu soignés, compteurs incohérents |
| Confiance / polish | 3 | Emojis, bannière sécurité permanente, miniatures vides |

**Verdict utilisateur confirmé** : l'interface donne l'impression d'un **prototype fonctionnel**, pas d'un SaaS GED professionnel (Paperless-ngx, M-Files, DocuWare, Notion/Dropbox Business).

---

## Stack UI actuelle

| Couche | Fichiers | Observation |
|--------|----------|---------------|
| Layout | `templates/layouts/main.php`, `auth.php` | Flex sidebar + header + main |
| Partials | `templates/partials/sidebar.php`, `header.php`, `footer.php` | Sidebar exécute des requêtes SQL à chaque page |
| CSS | `public/css/tailwind.css`, `theme.css`, `app.css` | Tailwind compilé + tokens CSS custom « Paperless-like » |
| JS | `public/js/app.js`, `ai-search.js` | Vanilla, pas de bundler |
| Page critique | `templates/documents/index.php` (~2 800 lignes) | Monolithe front : modale preview, grille, arborescence |

Inspirations déclarées dans `app.css` : *« Inspiré de Paperless-ngx »* — parité visuelle non atteinte.

---

## Comparaison benchmarks pro

| Référence | Attendu pro | K-Docs actuel |
|-----------|-------------|---------------|
| **Paperless-ngx** | Grille compacte, preview fiable, filtres latéraux clairs, dark mode | Grille OK en structure ; miniatures souvent vides ; badge « À classer » omniprésent |
| **M-Files** | Métadonnées structurées, panneau propriétés, workflow visible | Métadonnées en modale lourde ; workflows admin-only |
| **SaaS moderne** | Design system, pas d'emoji UI, hiérarchie claire, empty states | Emojis titres (⚙️ 📁 🤖), mélange FR/technique |
| **HTMLEDITOR v3** | Chrome framework tokens, ruban, densité modes | Aucun partage de design system — opportunité manquée |

---

## Problèmes concrets observés

### 1. Tailwind brut sans design system

- Classes utilitaires inline partout (`bg-gray-50`, `text-sm`, `rounded`) sans composants réutilisables.
- `app.css` redéfinit partiellement Tailwind (`.btn-primary`, sidebar) → **double source de vérité**.
- Pas de tokens sémantiques unifiés (`--surface-elevated`, `--chrome-tab-indicator` comme HTMLEDITOR).

### 2. Chrome incohérent

- **Sidebar unique** mélange : navigation métier (Documents, Tâches), référentiels (Tags, Types…), admin (Utilisateurs, Paramètres).
- Section « GESTION » et « ADMINISTRATION » peu différenciées visuellement.
- Header : recherche globale absente (commentée dans `main.php` : `search_chat.php`).
- Footer minimal « © 2026 K-Docs » — pas de version, pas de statut système.

### 3. Typographie et densité

- Police system stack 14 px — correcte mais sans échelle typographique cohérente.
- Page Documents : **3 colonnes** (dossiers logiques, arborescence FS, types) + grille → surcharge cognitive.
- Compteurs incohérents : sidebar « Documents 13 » vs header « 43 » vs dossier « Racine 21 » — **perte de confiance**.

### 4. Navigation

- Ordre sidebar illogique : Documents avant Dashboard (OK) mais admin intercalé sans accordéon persistant.
- Routes admin dupliquées dans sidebar utilisateur (Tags, Workflows…) au lieu d'un hub `/admin` exclusif.
- Lien « Fichiers à valider 58 » vs compteur dashboard « En attente 0 » — incohérence métier visible.

### 5. Feedback utilisateur

- Modale preview : titres « Chargement... » persistants dans l'accessibility tree au chargement.
- Grille documents : nombreux fichiers `test_*` — environnement dev non filtré, aspect « bac à sable ».
- Bannière orange permanente : *« root n'a pas de mot de passe »* — correcte en dev, **tue la crédibilité pro**.
- Boutons avec emojis : « 🔍 Tester », « 💾 Enregistrer », « 📤 Uploader » — ton amateur vs enterprise.

### 6. Page Documents (`/documents`)

Observations live (screenshot `gedv1-dashboard.png`, session 2026-06-18) :

- Miniatures : majorité **rectangles blancs vides** ; seuls PDF scannés bas de grille ont preview.
- Badge orange « À classer » sur presque tous les documents — file d'attente non traitée visible.
- Arborescence « toclassify » encore présente (legacy) alors que doc architecture dit dossier supprimé.
- Zone drag-and-drop « Déposez vos fichiers ici » — OK fonctionnellement, styling basique.
- Modale preview ~2 800 lignes JS inline — maintenance et perf problématiques.

### 7. Page Paramètres (`/admin/settings`)

- Formulaire long single-column (~1 100 lignes PHP) — pas de tabs, pas de progressive disclosure.
- Sections emoji : 📁 Stockage, 🔧 Outils, 🤖 IA, ⚙️ Indexation.
- Qdrant affiché « Connection timed out » — erreur technique exposée à l'admin sans action guidée.
- Chemin stockage encore `C:\wamp64\www\kdocs\...` — signal migration incomplète.

### 8. Dashboard (`/`)

- Cartes stats + graphiques basiques — acceptable.
- « Actions rapides » avec emojis 📤 📋 — style blog, pas enterprise.
- Liste « Documents récents » remplie de fichiers test.

---

## Captures d'écran

| Page | URL | Observation |
|------|-----|-------------|
| Documents | `/documents` | Grille 43 docs, miniatures vides, badges « À classer » |
| Dashboard | `/` | Stats + graphiques, fichiers test récents |
| Paramètres | `/admin/settings` | Formulaire monolithique, Qdrant timeout visible |

> Captures browser MCP session 2026-06-18 (non versionnées dans le dépôt — reproduire via `dev-start.bat` + navigation).

---

## Score détaillé ressenti pro vs brouillon

| Zone | Pro (1–10) | Justification |
|------|------------|---------------|
| Login | 5 | Simple, fonctionnel, pas de branding |
| Dashboard | 4 | Stats OK, polish faible |
| Documents | 3 | Cœur produit — miniatures, compteurs, bruit test |
| Upload | 4 | Non audité visuellement en détail ; drag-drop basique |
| Admin settings | 3 | Trop technique, emojis, erreurs brutes |
| Chat IA | 4 | Présent ; cohérence chrome à vérifier |
| Global | **3,5** | **Brouillon confirmé** — fondation fonctionnelle, zero finition produit |

---

## Recommandations refonte UI

### P0 — Bloquants crédibilité (2–4 semaines)

| # | Action | Impact |
|---|--------|--------|
| 1 | **Séparer chrome user / admin** : sidebar user (Documents, Recherche, Tâches, Upload) vs hub Admin distinct | Navigation claire |
| 2 | **Design system minimal** : composants `Button`, `Card`, `Badge`, `Sidebar` — supprimer emojis UI | Aspect pro immédiat |
| 3 | **Fix miniatures + compteurs** : une source de vérité pour totaux ; placeholder uniforme si thumb manquante | Confiance documents |
| 4 | **Refactor `documents/index.php`** : extraire JS modale en module, réduire inline | Maintenabilité |

### P1 — Parité Paperless / M-Files (1–2 mois)

| # | Action |
|---|--------|
| 5 | Panneau métadonnées latéral permanent (type, correspondant, tags, champs classification) — pas seulement modale |
| 6 | Filtres facettés + recherche unifiée header (réactiver `search_chat.php` ou équivalent) |
| 7 | Empty states illustrés + onboarding premier document |
| 8 | Aligner tokens CSS sur `htmleditor/docs/UI-CHROME-FRAMEWORK.md` pour cohérence écosystème Stoco |
| 9 | Mode densité Compact / Confort |
| 10 | Masquer données test / seed en prod ; filtre « documents validés » par défaut |

### P2 — Polish SaaS (backlog)

| # | Action |
|---|--------|
| 11 | Dark mode chrome (document preview reste papier clair — invariant HTMLEDITOR) |
| 12 | Raccourcis clavier documentés (preview ←/→, validation) |
| 13 | Toasts unifiés + skeleton loaders |
| 14 | Page settings en onglets (Stockage · OCR · IA · Indexation · Intégrations) |

---

## Piste de refonte cible

```
┌─────────────────────────────────────────────────────────┐
│  Header : logo · recherche globale · notifs · profil    │
├──────────┬──────────────────────────────────────────────┤
│  Nav     │  Toolbar contextuelle (filtres · tri · vue)  │
│  user    ├──────────────────┬───────────────────────────┤
│  (5项)   │  Liste / Grille  │  Panneau métadonnées      │
│          │  + preview inline│  (classification IA)      │
└──────────┴──────────────────┴───────────────────────────┘
Admin : route /admin/* avec layout séparé (pas dans sidebar user)
```

---

*Références : `templates/`, `public/css/`, `docs/COMPARAISON_PAPERLESS.md`, `docs/CORRECTIONS_PRIORITAIRES.md`, HTMLEDITOR `docs/UI-CHROME-FRAMEWORK.md`*
