# OnlyOffice - Guide de configuration et dépannage

## Vue d'ensemble

OnlyOffice Document Server permet la prévisualisation et l'édition de documents Office (Word, Excel, PowerPoint) directement dans le navigateur.

## Architecture

```
┌─────────────────┐     ┌──────────────────────┐     ┌────────────────┐
│   Navigateur    │────▶│  K-Docs (PHP)        │     │ OnlyOffice     │
│   (Client)      │     │  localhost/kdocs     │     │ Document Server│
└────────┬────────┘     └──────────┬───────────┘     │ localhost:8080 │
         │                         │                  └────────┬───────┘
         │  ◀──────── iframe ──────│◀─────────────────────────┘
         │                         │
         │                         │  callback_url
         │                         │  ┌──────────────┐
         │                         │  │  Docker      │
         │                         │◀─│  Container   │
         │                         │  │  (OnlyOffice)│
         │                         │  └──────────────┘
```

## Flux de communication

1. **Ouverture d'un document**: Le navigateur charge l'iframe OnlyOffice
2. **Téléchargement**: OnlyOffice (Docker) appelle `callback_url/api/onlyoffice/public/download/{id}/{token}`
3. **Édition**: L'utilisateur modifie le document dans l'iframe
4. **Sauvegarde**: OnlyOffice (Docker) appelle `callback_url/api/onlyoffice/public/callback/{id}/{token}`

## Configuration

### Fichier `config/config.php`

```php
'onlyoffice' => [
    'enabled' => true,
    'server_url' => 'http://localhost:8080',      // URL navigateur → OnlyOffice
    'app_url' => 'http://localhost/kdocs',        // URL navigateur → K-Docs
    'callback_url' => 'http://192.168.1.x/kdocs', // URL Docker → K-Docs (IMPORTANT!)
    'jwt_secret' => '',                           // Secret JWT (si configuré dans Docker)
    'ssl_verify' => false,                        // false en dev, true en prod
    'debug_log' => true,                          // Active les logs détaillés
    'timeout' => 10,                              // Timeout HTTP en secondes
],
```

### Configuration via l'interface

Accédez à `/admin/settings` et cherchez la section "OnlyOffice Document Server".

## Erreurs courantes

### "Échec du téléchargement" / "Download failed"

**Cause**: Le container Docker OnlyOffice ne peut pas atteindre l'URL de callback K-Docs.

**Solutions**:

1. **Vérifiez le callback_url**:
   - Ne jamais utiliser `localhost` ou `127.0.0.1`
   - Utilisez votre IP locale: `ipconfig` (Windows) ou `ip addr` (Linux)
   - Ou utilisez `host.docker.internal` (Docker Desktop sur Windows/Mac)

2. **Vérifiez le firewall**:
   - Le port 80 doit être accessible depuis le container Docker
   - Désactivez temporairement le firewall pour tester

3. **Testez la connectivité**:
   - Utilisez le bouton "Tester la connectivité" dans les paramètres
   - Vérifiez les logs dans `/admin/settings`

### "Impossible d'enregistrer" / "Save error"

**Cause**: Erreur lors de la sauvegarde via le callback.

**Solutions**:

1. Vérifiez les logs OnlyOffice: `/admin/settings` → "Voir les logs"
2. Vérifiez que le fichier existe sur le disque
3. Vérifiez les permissions d'écriture sur le dossier storage

### Erreurs SSL

**Symptôme**: Erreurs de certificat, connexion refusée en HTTPS.

**Solution**:
1. En développement: Désactivez "Vérification SSL" dans les paramètres
2. En production: Configurez des certificats valides

## Diagnostic

### Via l'interface

1. Accédez à `/admin/settings`
2. Section "OnlyOffice Document Server"
3. Cliquez sur "Tester la connectivité"
4. Consultez les logs avec "Voir les logs"

### Via API

```bash
# Test de connectivité
curl http://localhost/kdocs/api/onlyoffice/test-connectivity

# Configuration actuelle
curl http://localhost/kdocs/api/onlyoffice/debug-config

# Logs récents
curl http://localhost/kdocs/api/onlyoffice/logs?lines=50

# Statut du serveur
curl http://localhost/kdocs/api/onlyoffice/status
```

### Fichier de logs

Les logs détaillés sont stockés dans:
```
storage/logs/onlyoffice.log
```

Format des logs:
```
[2026-02-05 10:30:00] [INFO] Request: publicDownload {"document_id":50,"remote_ip":"172.17.0.2"}
[2026-02-05 10:30:00] [INFO] File download {"document_id":50,"file_path":"C:\\...\\doc.docx","file_size":12345}
```

## Configuration Docker OnlyOffice

### docker-compose.yml

```yaml
version: '3'
services:
  onlyoffice:
    image: onlyoffice/documentserver
    ports:
      - "8080:80"
    environment:
      - JWT_ENABLED=false  # ou true avec JWT_SECRET
      # - JWT_SECRET=your-secret-here
    extra_hosts:
      - "host.docker.internal:host-gateway"  # Pour accéder à l'hôte
```

### Démarrage

```bash
docker-compose up -d
```

### Vérification

```bash
# Health check
curl http://localhost:8080/healthcheck
# Doit retourner: true
```

## Variables importantes

| Variable | Description | Exemple |
|----------|-------------|---------|
| `server_url` | URL du serveur OnlyOffice (vue navigateur) | `http://localhost:8080` |
| `app_url` | URL de K-Docs (vue navigateur) | `http://localhost/kdocs` |
| `callback_url` | URL de K-Docs (vue Docker) | `http://192.168.1.14/kdocs` |
| `jwt_secret` | Secret JWT partagé avec Docker | *(vide ou secret)* |
| `ssl_verify` | Vérifier les certificats SSL | `false` (dev) / `true` (prod) |
| `debug_log` | Activer les logs détaillés | `true` |

## Checklist de déploiement

- [ ] OnlyOffice Docker démarré et accessible
- [ ] Health check retourne "true"
- [ ] callback_url utilise une IP accessible depuis Docker (pas localhost)
- [ ] Firewall autorise les connexions entrantes sur le port 80
- [ ] Test de connectivité OK dans les paramètres
- [ ] JWT_SECRET configuré identiquement (ou désactivé des deux côtés)
- [ ] ssl_verify adapté à l'environnement (false en dev, true en prod avec certificats valides)

## Diagnostic admin « ERREUR » alors que le conteneur répond (curl loopback)

**Symptôme** : `/admin/diagnostic` affiche OnlyOffice en **ERREUR** (et Ollama
**DECONNECTE**) alors que `curl http://localhost:8080/healthcheck` depuis une
invite système retourne `true` et que `docker ps` montre le conteneur `Up`.

**Cause** : sur certains builds PHP Windows (cURL 8.10.1 / WAMP), `curl_init`
vers `127.0.0.1` ou `localhost` **time out** alors que le service répond
parfaitement (vérifié : `fsockopen('127.0.0.1', 8080)` ouvre le socket, Ollama
retourne ses modèles via fsockopen). Ce n'est ni un proxy ni IPv6 — `CURLOPT_PROXY => ''`
ne corrige rien. C'est un défaut du transport curl loopback de ce build.

**Fix appliqué (commit `c7db9ce`)** : `AdminController::httpProbe()` utilise
**fsockopen** pour les URL `http://` (fiable loopback + remote) et ne garde
curl que pour `https://` (TLS). Les healthchecks OnlyOffice et Ollama passent
donc par `httpProbe`. Ollama remonte CONNECTE (2 modèles) ; OnlyOffice
remontera CONNECTE dès que le conteneur répondra au healthcheck.

**Vérification manuelle** :
```powershell
# Confirme que le conteneur répond (PowerShell utilise un transport différent de PHP curl)
(Invoke-WebRequest http://127.0.0.1:8080/healthcheck -TimeoutSec 15).Content  # -> true
```

## Docker Desktop — pipe engine absent / conteneur « Up » mais ne répond plus

**Symptôme** : `docker ps` renvoie `failed to connect to the docker API at
npipe:////./pipe/docker_engine` (ou `dockerDesktopLinuxEngine`) bien que les
processus `Docker Desktop` tournent et que `wsl -l -v` montre `docker-desktop`
`Running`. OnlyOffice peut répondre à un healthcheck juste après le démarrage
puis cesser de répondre (timeout 30 s).

**Cause** : Docker Desktop est dans un état instable — le backend WSL tourne
mais n'expose plus le named pipe du moteur, et/ou le conteneur OnlyOffice
sature (RAM WSL2 trop faible, OnlyOffice nécessite ~2 Go). C'est un problème
d'**infrastructure hôte**, pas du code GEDv1.

**Actions (à exécuter manuellement, potentiellement destructives — ne pas
automatiser sans accord)** :
1. Quitter Docker Desktop (icône → Quit), attendre 10 s, le relancer.
2. Si persistant : `wsl --shutdown` (arrête **toutes** les distros WSL, dont
   Ubuntu) puis relancer Docker Desktop et `docker compose up -d onlyoffice`.
3. Allouer ≥ 4 Go à WSL2 (`.wslconfig` → `memory=4GB`) — OnlyOffice en a besoin.
4. Vérifier `docker ps` + `curl http://localhost:8080/healthcheck` → `true`
   avant de reprocher un « ERREUR » au diagnostic.

> Le diagnostic GEDv1 reflète fidèlement l'état du conteneur une fois Docker
> stabilisé. Tant que Docker Desktop est instable, OnlyOffice est un blocker
> environnemental hors périmètre code.

