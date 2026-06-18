# Acces dev GEDv1 (K-Docs)

## URL

- Application : **http://127.0.0.1:8765/kdocs/**
- Login : **http://127.0.0.1:8765/kdocs/login**
- Health : **http://127.0.0.1:8765/kdocs/health** (200 = serveur OK)

Identifiants dev par defaut : utilisateur **root**, mot de passe **vide**.

Preferer **127.0.0.1** plutot que `localhost` (evite ambiguite IPv6 et correspond au bind du serveur PHP).

## Demarrer le serveur

Depuis la racine `GEDv1/` :

```cmd
dev-start.bat
```

Ou :

```cmd
tools\run-dev-server.bat
```

Commande manuelle equivalente :

```cmd
cd F:\DATA\DEVELOPPEMENT\GEDv1
set GEDV1_DEBUG_SESSION=4af063
php -S 127.0.0.1:8765 router.php
```

**Important** : toujours passer par `router.php`. Ne pas utiliser `php -S ... -t .` seul (assets /kdocs/public casses).

## Verifications rapides

```powershell
curl -s -o NUL -w "%{http_code}" http://127.0.0.1:8765/kdocs/login
curl -I http://127.0.0.1:8765/kdocs
```

Attendu : login **200**, `/kdocs` **302** vers `/kdocs/`.

## Port bloque ou « aucun acces »

Symptome typique : `curl` se connecte mais **timeout sans octets** (HTTP 0), ou plusieurs `php.exe` sur le meme port.

1. Lister :

```powershell
Get-NetTCPConnection -LocalPort 8765 -ErrorAction SilentlyContinue | Format-Table LocalAddress,State,OwningProcess
Get-CimInstance Win32_Process -Filter "Name='php.exe'" | Select-Object ProcessId,CommandLine
```

2. Nettoyer :

```cmd
tools\kill-dev-port.bat 8765
```

3. Relancer `dev-start.bat`.

### Port de secours 8770

Si 8765 reste incoherent (Listen sans processus, sockets fantomes) :

```cmd
tools\kill-dev-port.bat 8770
set GEDV1_DEBUG_SESSION=4af063
php -S 127.0.0.1:8770 router.php
```

Mettre a jour `.env` : `APP_URL=http://127.0.0.1:8770/kdocs`

## Smokes live (CI local)

```cmd
tools\run-live-smokes.bat
```

Demarre le serveur si health absent, attend health 200 (max 15 s), puis enchaine les tests HTTP.
