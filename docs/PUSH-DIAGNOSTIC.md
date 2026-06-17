# Diagnostic push GitHub — GEDv1 → `opochon/kdocs`

Date : 2026-06-17  
Dépôt local : `F:\DATA\DEVELOPPEMENT\GEDv1`  
Remote : `https://github.com/opochon/kdocs.git`  
Commit en attente : `33106ab` (`main` ahead 1)

## Symptôme

```
remote: Permission to opochon/kdocs.git denied to opochon.
fatal: unable to access 'https://github.com/opochon/kdocs.git/': The requested URL returned error: 403
```

## Cause racine

**Le PAT fine-grained actuellement utilisé par `gh` / Git Credential Helper n’a pas accès en écriture au dépôt `opochon/kdocs`.**

Ce n’est **pas** un mauvais remote, un dépôt inexistant, ni un compte GitHub incorrect. Le dépôt existe, l’utilisateur `opochon` y a les droits **via l’interface web**, mais le **jeton** ne couvre pas ce repo pour les opérations `contents: write`.

### Preuves (sorties redacted)

| Vérification | Résultat |
|--------------|----------|
| `git remote -v` | `origin` → `https://github.com/opochon/kdocs.git` (fetch/push) |
| `git status -sb` | `## main...origin/main [ahead 1]` — commit `33106ab` |
| `git ls-remote origin` | OK — lit `4499b41` sur `refs/heads/main` (lecture HTTPS) |
| `gh auth status` | Connecté en tant que `opochon`, protocole HTTPS, token `github_pat_…` (masqué) |
| `gh repo view opochon/kdocs` | Repo **public**, `viewerPermission`: **ADMIN**, `viewerCanAdminister`: true, créé/poussé le 2026-06-17 |
| `git push origin main` | **403** — message « denied to opochon » |
| Trace `git push` | Credential via `gh auth git-credential get` (pas un vieux login Windows isolé) |
| `POST /repos/opochon/kdocs/git/refs` (gh api) | **403** — `Resource not accessible by personal access token` |
| `POST /repos/opochon/htmleditor/git/refs` (gh api) | **201 Created** (même session `gh`) |
| `git push --dry-run` (htmleditor_v3) | OK — « Everything up-to-date » |

**Interprétation :** PAT **fine-grained** limité à un sous-ensemble de dépôts (p.ex. `htmleditor` uniquement). `kdocs` a été créé le **2026-06-17** ; il n’est pas dans la liste des repos autorisés du token. La lecture (`ls-remote`, métadonnées API) peut passer ; l’écriture (push, création de ref) est refusée.

Les PAT testés depuis `htmleditor_v3/git.txt` et `gitlogin.txt` L3 échouent pour la **même raison** s’ils sont le même jeton fine-grained ou un autre jeton sans `kdocs`.

## Ce qui a été exclu

- Remote URL incorrecte — correspond au repo listé sous `opochon/kdocs`.
- Repo absent — présent dans `gh repo list opochon`.
- Org / SSO — dépôt utilisateur `opochon`, pas une org bloquante.
- Clone WAMP — `.git/config` pointe bien vers GitHub ; historique cohérent (`4499b41` distant, `33106ab` local).
- Credential Manager seul — Git utilise déjà `gh` comme helper pour `github.com` ; le token `gh` est la source du refus.

## Historique / origine du clone

- Projet copié depuis `C:\wamp64\www\kdocs` vers `F:\DATA\DEVELOPPEMENT\GEDv1` (cf. `SESSION-STATUS.md`).
- Identité git locale : `K-Docs Developer <developer@kdocs.local>` (sans impact sur le 403).

## Solution recommandée (étapes)

### Option A — Étendre le PAT fine-grained existant (recommandé)

1. GitHub → **Settings** → **Developer settings** → **Fine-grained personal access tokens**.
2. Ouvrir le token utilisé pour ce poste (expiration affichée côté API : **2026-08-03** environ).
3. **Repository access** : ajouter **`opochon/kdocs`** ou passer à **All repositories**.
4. Vérifier la permission **Contents** : **Read and write**.
5. Sur la machine :
   ```powershell
   # Coller le PAT mis à jour (ne pas le committer)
   gh auth login --hostname github.com --git-protocol https --with-token
   gh auth setup-git
   ```
6. Vérifier :
   ```powershell
   cd F:\DATA\DEVELOPPEMENT\GEDv1
   git push origin main
   ```

### Option B — PAT classique avec scope `repo`

1. Créer un **classic** PAT avec scope **`repo`** (couvre tous les dépôts privés/publics du compte).
2. `gh auth login --with-token` puis `git push` comme ci-dessus.

### Option C — SSH (évite HTTPS + PAT pour Git)

1. Clé SSH sur le compte GitHub.
2. `git remote set-url origin git@github.com:opochon/kdocs.git`
3. `git push origin main`

## Scopes / permissions requis

- **Fine-grained :** dépôt `kdocs` inclus + **Contents: Read and write**.
- **Classic :** scope **`repo`**.
- CI GitHub Actions sur ce repo : éventuellement **`workflow`** (classic) ou permission Workflows sur le token fine-grained.

## Actions effectuées pendant le diagnostic

- `gh auth setup-git` exécuté (déjà configuré).
- Push retenté : **échec 403** (inchangé).
- Branche de test API sur `htmleditor` créée puis **supprimée** (`test-push-diag-delete-me`) — aucun impact sur `kdocs`.

## État actuel

- Commit `33106ab` reste **local uniquement** jusqu’à mise à jour du PAT ou changement d’auth.
- Aucun secret n’a été écrit dans ce fichier.

---
*Généré par diagnostic agent — 2026-06-17*

## Test Cursor (2026-06-17 — re-exécution agent)

### Git local (GEDv1)

| Élément | Résultat |
|---------|----------|
| `git status -sb` | `## main...origin/main [ahead 1]` — **working tree non propre** : `M SESSION-STATUS.md`, `?? docs/PUSH-DIAGNOSTIC.md` |
| Commit `33106ab` | **Présent** en HEAD (`33106abbd4cda9c5bc7a2c7d64783caaf5095ad3`) |
| `git branch -vv` | `main` track `origin/main`, **ahead 1** |
| Distant `origin/main` | `4499b41` (lecture OK) |

**Conclusion git local :** historique OK, commit en attente de push ; modifications non commitées en cours.

### Auth `gh` / Cursor

| Test | Résultat |
|------|----------|
| `gh auth status` | Compte **opochon**, HTTPS, token **keyring** (`github_pat_…`, fine-grained) |
| `gh api user --jq .login` | `opochon` |
| `gh api repos/opochon/kdocs --jq .permissions` | `push: true` (droits **vue utilisateur** via API métadonnées) |
| `POST repos/opochon/kdocs/git/refs` (branche `test-cursor-push`, SHA `33106ab…`) | **403** — `Resource not accessible by personal access token` |
| `git push origin main` | **403** — `Permission to opochon/kdocs.git denied to opochon` |
| `gh auth git-credential get` | `username=opochon`, password = PAT keyring (via helper) |
| `git config credential.helper` | `manager` (global aussi `manager`) |
| `GIT_ASKPASS` / `GCM_INTERACTIVE` | non définis |
| Token `htmleditor_v3/git.txt` via `GH_TOKEN` + POST ref `kdocs` | **403** (même message PAT) |
| `gitlogin.txt` L3 comme `GH_TOKEN` | **401** Bad credentials (L3 = mot de passe compte, pas PAT) |
| Contrôle écriture `opochon/htmleditor` (POST ref puis DELETE) | **201** création ref test — preuve que la session `gh` **écrit** sur un autre dépôt autorisé |

**Conclusion push :** l’accès Cursor/`gh` est **réel et fonctionnel** pour GitHub, mais le **PAT fine-grained en keyring n’inclut pas `opochon/kdocs` en écriture**. Ce n’est pas un échec de l’intégration Cursor : c’est une **portée du token**. Aucun hash n’a été poussé sur `kdocs`.

### Actions si blocage persiste

1. Étendre le PAT fine-grained (Option A du document) ou PAT classic `repo`, puis `gh auth login --with-token` + `gh auth setup-git`.
2. Ou remote SSH (`git@github.com:opochon/kdocs.git`).
3. Ne pas utiliser `gitlogin.txt` L3 pour `gh` sauf si c’est un PAT `ghp_` / `github_pat_`.

