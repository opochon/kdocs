# Test login & pages K-Docs (GEDv1) — local

**Date :** 2026-06-17  
**Environnement :** `http://localhost:8765/kdocs/`  
**Serveur :** PHP built-in (`php -S localhost:8765 -t .`) — actif (HTTP 200 sur `/kdocs/login`)

---

## Résultat login

| Étape | Résultat |
|-------|----------|
| `root` + mot de passe vide (avant fix) | **KO** — message « Identifiants incorrects » |
| Cause | `users.password_hash` contenait un hash bcrypt (`admin`), alors que l’UI/README annoncent un mot de passe vide |
| `root` + `admin` (avant fix) | **OK** — 302 + cookie `kdocs_session` |
| `root` + mot de passe vide (après fix) | **OK** — session établie, redirection dashboard |
| Navigateur MCP (cursor-ide-browser) | **OK** — formulaire root/vide → Dashboard « Bienvenue, Admin ! » |

### Credentials fonctionnels (après correction)

| Utilisateur | Mot de passe | Notes |
|-------------|--------------|-------|
| `root` | *(vide)* | Compte dev par défaut (README + hint login) |
| `root` | `admin` | Fonctionnait avant reset DB (hash bcrypt présent) |

### Fix appliqué

```sql
UPDATE users SET password_hash = '' WHERE username = 'root';
```

Exécuté via script temporaire PHP le 2026-06-17. Alignement avec :
- `README.md` (« Compte: root (mot de passe vide) »)
- `templates/auth/login.php` (hint UI)
- `database/schema*.sql` (seed `password_hash = ''`)
- `app/Core/Auth.php` L82-93 (accepte hash vide + password vide en dev)

**Non commité** — correction données runtime uniquement. Pour reproduire : relancer la requête SQL ci-dessus si le hash est à nouveau défini (ex. via `bin/kdocs user:create`).

---

## Pages testées

Session authentifiée (`root` / mot de passe vide), vérification HTTP + contenu.

| URL | Statut | Remarques |
|-----|--------|-----------|
| `/kdocs/login` | 200 | Formulaire OK (public) |
| `/kdocs/` (dashboard) | 200 | 43 documents, stats, sidebar complète |
| `/kdocs/documents` | 200 | Liste documents (~260 Ko HTML) |
| `/kdocs/admin` | 200 | Hub administration |
| `/kdocs/admin/users` | 200 | 1 utilisateur (root) |
| `/kdocs/admin/settings` | 200 | Paramètres |
| `/kdocs/admin/workflows` | 200 | Workflows |
| `/kdocs/tasks` | 200 | Tâches |
| `/kdocs/mes-taches` | 200 | Mes tâches (30 en attente) |
| `/kdocs/chat` | 200 | Chat IA |
| `/kdocs/health` | 200 | JSON `status: healthy` |
| `/kdocs/api/workflows` | 200 | API workflows (JSON) |

### Health check (`/kdocs/health`)

- **database** : ok
- **storage / cache** : ok, writable
- **ocr** : ok (Tesseract)
- **php** : 8.4.0
- **onlyoffice** : ok, available
- **queue_worker** : warning (not running)
- **qdrant** : warning (server not responding)
- **winbiz** : warning (disabled)

Aucune page testée ne renvoie 500.

---

## Diagnostic initial (échec login)

1. **Serveur** : up sur port 8765.
2. **Code auth** : `Auth::attempt()` — mot de passe vide accepté seulement si `password_hash` est vide en BDD.
3. **BDD** (`kdocs.users`) :
   ```json
   {"id":1,"username":"root","password_hash":"$2y$12$...","is_active":1,"is_admin":1}
   ```
4. Hash vérifié : correspond au mot de passe `admin` (pas vide).
5. Client `mysql` CLI : indisponible (plugin auth Windows) — requêtes via PHP PDO OK.

---

## Commandes utiles

```powershell
# Vérifier serveur
curl -s -o NUL -w "%{http_code}" http://localhost:8765/kdocs/login

# Lancer serveur si down
cd F:\DATA\DEVELOPPEMENT\GEDv1
php -S localhost:8765 -t .

# Login curl (session cookie)
curl -c cookies.txt -b cookies.txt -X POST http://localhost:8765/kdocs/login -d "username=root&password=" -L

# Reset dev root si login vide échoue à nouveau
php -r "$c=require 'config/config.php'; $p=new PDO('mysql:host=127.0.0.1;port=3307;dbname=kdocs', 'root',''); $p->exec(\"UPDATE users SET password_hash='' WHERE username='root'\");"
```

---

## URL à utiliser

**Application :** http://localhost:8765/kdocs/  
**Login :** http://localhost:8765/kdocs/login  
**Compte dev :** `root` / *(mot de passe vide)*
