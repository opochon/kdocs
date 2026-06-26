# Tests visuels Playwright — shell K-Docs

Smoke **DOM + captures** des routes canoniques du shell (Bibliothèque, Recherche,
À traiter, Importer, Admin). Complète les smokes PHP : ici on vérifie le rendu
authentifié dans un vrai navigateur. **Non bloquant** (hors pre-commit).

## Prérequis (une fois)

```cmd
cd F:\DATA\DEVELOPPEMENT\GEDv1\tests\visual
npm install
npm run install-browser   REM telecharge Chromium pour Playwright
```

Node ≥ 18. Le serveur PHP est démarré automatiquement par Playwright (réutilisé
s'il tourne déjà sur le port 8765).

## Lancer

```cmd
cd F:\DATA\DEVELOPPEMENT\GEDv1\tests\visual
npm test
```

Depuis la racine : `make test-visual` (ou `npm --prefix tests/visual test`).

## Ce qui est vérifié, par route

1. Réponse HTTP < 400.
2. Auth tenue (pas de redirection vers `/login`).
3. Aucun marqueur d'erreur PHP dans le HTML (`Fatal error`, `Uncaught`, …).
4. Capture pleine page dans `shots/<route>.png`.

Le rapport HTML : `npm run report`. Trace des échecs dans `test-results/`.

## Réglages (variables d'environnement)

| Variable | Défaut | Rôle |
|----------|--------|------|
| `KDOCS_HOST` / `KDOCS_PORT` | `127.0.0.1` / `8765` | bind serveur + baseURL |
| `KDOCS_USER` / `KDOCS_PASS` | `root` / *(vide)* | identifiants de login |

## Évolution (différé)

Baseline pixel activable sans refonte : remplacer la capture par
`await expect(page).toHaveScreenshot('<route>.png')` une fois les baselines gelées
sur une machine de référence.

Remplace l'ancien `tests/screenshot_runner.ps1` (capture sans gestion d'auth).
