# GEDv1 — Notes de migration

> Copie effectuée le 2026-06-17 depuis `C:\wamp64\www\kdocs`.

## Source et destination

| | Chemin |
|---|--------|
| Source | `C:\wamp64\www\kdocs` |
| Destination | `F:\DATA\DEVELOPPEMENT\GEDv1` |

## Méthode

```powershell
robocopy "C:\wamp64\www\kdocs" "F:\DATA\DEVELOPPEMENT\GEDv1" /E /XD node_modules vendor .phpunit.cache /XF claude_api_key.txt cookies.txt
```

## Exclusions volontaires

| Élément | Raison |
|---------|--------|
| `node_modules/` | Non présent / réinstallable via npm si besoin |
| `vendor/` | Copie initiale exclue ; **recopié ensuite** depuis source car `composer install` échoue (lock désynchronisé) |
| `.phpunit.cache/` | Cache régénérable |
| `claude_api_key.txt` | Secret — ne pas migrer |
| `cookies.txt` | Session dev — ne pas migrer |

## Intégrité

| Métrique | Valeur |
|----------|--------|
| Fichiers source (hors vendor/node_modules/cache) | 1110 |
| Fichiers destination (hors vendor) | 1108 |
| Écart | 2 fichiers (secrets exclus + fichier `nul` vide) |

## Git

Le dépôt `.git` a été copié (historique kdocs préservé). Remote attendu : vérifier avec `git remote -v` depuis GEDv1.

## Composer — blocage

`composer.json` référence des packages absents du `composer.lock` :

- `php-di/php-di`, `monolog/monolog`
- dev : `phpstan`, `phpcs`, `php-cs-fixer`

**Action** : `composer update` depuis GEDv1 quand Composer est dans le PATH, ou resynchroniser lock depuis source WAMP.

**Contournement migration** : `vendor/` recopié depuis `C:\wamp64\www\kdocs\vendor`.

## Réinstallation complète

```cmd
cd F:\DATA\DEVELOPPEMENT\GEDv1
composer update
php database/install.php
```

## Apache / URL (WAMP)

Configurer WAMP pour servir GEDv1 :

1. Copier ou lier le dossier vers `C:\wamp64\www\gedv1`
2. Créer `config/config.php` depuis `config/config.example.php`
3. Copier `.env.example` vers `.env` et adapter `APP_URL`
4. Vérifier Apache `mod_rewrite` activé (Slim front controller)
5. URL recommandée : `http://localhost/gedv1`

```apache
# Exemple VirtualHost (httpd-vhosts.conf)
<VirtualHost *:80>
    DocumentRoot "F:/DATA/DEVELOPPEMENT/GEDv1"
    ServerName gedv1.local
    <Directory "F:/DATA/DEVELOPPEMENT/GEDv1">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Harness offline (sans serveur) :

```cmd
cd F:\DATA\DEVELOPPEMENT\GEDv1
php tests\migration_smoke_test.php
```

Harness HTTP (serveur requis) :

```cmd
run-tests.bat smoke
```

---
*Dernière mise à jour : 2026-06-17*
