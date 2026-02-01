# K-Docs Smoke Test

## Installation

```bash
pip install selenium webdriver-manager
```

## Usage

### Windows
```batch
cd C:\wamp64\www\kdocs\tests
run_smoke_test.bat

REM Mode headless (sans fenêtre)
run_smoke_test.bat --headless
```

### Linux / macOS
```bash
cd /var/www/kdocs/tests
chmod +x run_smoke_test.sh
./run_smoke_test.sh

# Mode headless
./run_smoke_test.sh --headless
```

### Direct Python
```bash
python smoke_test.py
python smoke_test.py --base-url http://192.168.1.10/kdocs
python smoke_test.py --headless
python smoke_test.py --output /tmp/results
```

## Ce que fait le test

1. **Login** automatique (admin / vide)
2. **21 pages** testées avec screenshot
3. **Erreurs console** JS capturées
4. **Erreurs PHP/Slim** détectées (fatal error, exception, 500...)
5. **Actions** : clics, saisies sur certaines pages
6. **Upload** fichier test
7. **API** endpoints vérifiés

## Résultats

Après exécution, dans `tests/smoke_test_results/` :

```
smoke_test_results/
├── smoke_test_report.json    # Rapport complet JSON
├── smoke_test_report.md      # Rapport Markdown lisible
├── page_*.png                # Screenshots de chaque page
├── action_*.png              # Screenshots après actions
└── upload_test.png           # Screenshot test upload
```

## Codes de sortie

- `0` : Tous les tests passés
- `1` : Au moins un test échoué

## Personnalisation

Modifier `smoke_test.py` pour :
- Ajouter des pages dans `self.pages_to_test`
- Ajouter des actions dans `self.actions_to_test`
- Changer les credentials de login dans `self.login()`
