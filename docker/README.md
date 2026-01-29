# K-Docs Docker Services

## OnlyOffice Document Server

OnlyOffice permet la prévisualisation et l'édition des documents Office (Word, Excel, PowerPoint).

### Prérequis

- Docker Desktop installé et en cours d'exécution
- Port 8080 libre

### Démarrage

```powershell
# Dans le dossier docker/
cd C:\wamp64\www\kdocs\docker

# Démarrer OnlyOffice
docker-compose up -d

# Vérifier le statut
docker-compose ps

# Voir les logs
docker-compose logs -f onlyoffice
```

### Premier démarrage

Le premier démarrage peut prendre **2-3 minutes** car OnlyOffice initialise ses services internes.

Vérifiez que le service est prêt :
```powershell
# Test healthcheck
curl http://localhost:8080/healthcheck
# Doit retourner: true

# Ou dans le navigateur
# http://localhost:8080/healthcheck
```

### Configuration K-Docs

Dans `config/config.php`, vérifiez :

```php
'onlyoffice' => [
    'enabled' => true,
    'server_url' => 'http://localhost:8080',
    'jwt_secret' => '',  // Vide si JWT_ENABLED=false dans Docker
    'app_url' => 'http://localhost/kdocs',
    'callback_url' => 'http://host.docker.internal/kdocs',
],
```

### Commandes utiles

```powershell
# Arrêter
docker-compose down

# Redémarrer
docker-compose restart onlyoffice

# Voir les logs en temps réel
docker-compose logs -f onlyoffice

# Recréer le conteneur
docker-compose up -d --force-recreate
```

### Dépannage

#### "Aucune réponse" du healthcheck

1. Vérifiez que Docker Desktop est lancé
2. Le conteneur met 2-3 min à démarrer
3. Vérifiez les logs : `docker-compose logs onlyoffice`

#### Erreur de callback

Si l'édition ne sauvegarde pas :
- Vérifiez que `host.docker.internal` résout bien vers Windows
- Testez depuis le conteneur : `docker exec kdocs-onlyoffice curl http://host.docker.internal/kdocs/api/documents/1`

#### Port déjà utilisé

Si le port 8080 est occupé :
```yaml
# docker-compose.yml
ports:
  - "8081:80"  # Changer 8080 en 8081
```

Et mettez à jour `config.php` :
```php
'server_url' => 'http://localhost:8081',
```
