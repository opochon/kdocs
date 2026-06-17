# K-DOCS — PROCESS DE DÉVELOPPEMENT

> Guide quotidien pour le développement sur K-Docs

---

## WORKFLOW STANDARD

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│   Branche   │────▶│  Développer │────▶│   Tester    │────▶│   Commit    │
│   feature/  │     │             │     │  test.bat   │     │ Conventional│
└─────────────┘     └─────────────┘     └─────────────┘     └─────────────┘
                                                                   │
                                                                   ▼
┌─────────────┐     ┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│   Release   │◀────│    Merge    │◀────│   Review    │◀────│    Push     │
│   Tag       │     │    main     │     │     PR      │     │   origin    │
└─────────────┘     └─────────────┘     └─────────────┘     └─────────────┘
```

---

## 1. DÉMARRER UNE TÂCHE

### Créer une branche
```bash
git checkout main
git pull origin main
git checkout -b feature/ma-nouvelle-feature
# ou
git checkout -b fix/correction-bug-xyz
```

### Nommage des branches
```
feature/   → Nouvelle fonctionnalité
fix/       → Correction de bug
refactor/  → Refactoring
test/      → Ajout de tests
docs/      → Documentation
```

---

## 2. DÉVELOPPER

### Règles de base
- Un commit = une modification logique
- Tester localement avant de commiter
- Respecter l'architecture existante

### Structure code
```
Controllers/  → Uniquement HTTP (request/response)
Services/     → Logique métier
Repositories/ → Accès base de données
Models/       → Entités et DTOs
```

### Ajouter un test
Si nouvelle fonctionnalité → ajouter un test :
```php
// tests/Unit/Services/MonServiceTest.php
public function testMaNouvelleMethode(): void
{
    $service = new MonService();
    $result = $service->maMethode();
    $this->assertIsArray($result);
}
```

---

## 3. TESTER (OBLIGATOIRE)

### Avant chaque commit
```bash
test.bat check
```
Ce script exécute :
1. Smoke tests
2. Syntax check PHP
3. PHPStan (warnings acceptés)

### Tests complets (avant PR)
```bash
test.bat all
```

### Tests spécifiques
```bash
test.bat smoke       # 30s - Santé système
test.bat api         # 1min - Endpoints REST
test.bat unit        # 1min - PHPUnit
test.bat poc         # 2min - POC complet
test.bat integration # 3min - Fonctionnel
```

### Seuils requis
| Suite | Minimum |
|-------|---------|
| Smoke | 100% |
| API | 95% |
| POC | 95% |

---

## 4. COMMITER

### Format obligatoire (Conventional Commits)
```
type(scope): description

[body optionnel]

[footer optionnel]
```

### Types
| Type | Usage |
|------|-------|
| feat | Nouvelle fonctionnalité |
| fix | Correction de bug |
| docs | Documentation |
| style | Formatage (pas de changement code) |
| refactor | Refactoring |
| test | Ajout/modif tests |
| chore | Maintenance, dépendances |

### Exemples
```bash
git commit -m "feat(documents): add bulk delete action"
git commit -m "fix(upload): handle files > 10MB"
git commit -m "test(api): add document search tests"
git commit -m "docs(readme): update installation steps"
```

### ❌ À éviter
```bash
git commit -m "update"
git commit -m "fix"
git commit -m "wip"
git commit -m "asdfgh"
```

---

## 5. PUSH & PR

### Push
```bash
git push origin feature/ma-feature
```

### Pull Request
1. Titre clair : `feat(scope): description`
2. Description : quoi, pourquoi, comment
3. Tests : confirmer que `test.bat all` passe
4. Screenshots si changement UI

### Template PR
```markdown
## Description
[Expliquer le changement]

## Type de changement
- [ ] Nouvelle fonctionnalité
- [ ] Correction de bug
- [ ] Refactoring
- [ ] Documentation

## Tests
- [ ] test.bat check passe
- [ ] test.bat all passe
- [ ] Nouveaux tests ajoutés si nécessaire

## Screenshots (si UI)
[Ajouter captures si applicable]
```

---

## 6. RELEASE

### Checklist release
```bash
# 1. Tous les tests passent
test.bat all

# 2. Mettre à jour CHANGELOG.md
# Ajouter section [x.y.z] - YYYY-MM-DD

# 3. Bump version
# Éditer composer.json → version

# 4. Commit release
git add .
git commit -m "chore(release): bump to vx.y.z"

# 5. Tag
git tag -a vx.y.z -m "Release x.y.z"

# 6. Push
git push origin main
git push origin vx.y.z
```

### Versioning (SemVer)
```
MAJOR.MINOR.PATCH

MAJOR : Changements incompatibles
MINOR : Nouvelles fonctionnalités (rétrocompatible)
PATCH : Corrections de bugs
```

---

## 7. HOTFIX (URGENT)

Pour corrections critiques en production :

```bash
# 1. Branche depuis main
git checkout main
git checkout -b hotfix/description-courte

# 2. Corriger + tester
test.bat check

# 3. Commit
git commit -m "fix(scope): description urgente"

# 4. Merge direct (si vraiment urgent)
git checkout main
git merge hotfix/description-courte

# 5. Tag patch
git tag -a vx.y.z -m "Hotfix x.y.z"
git push origin main --tags
```

---

## 8. COMMANDES UTILES

### Git
```bash
git status                    # État actuel
git log --oneline -10         # Derniers commits
git diff                      # Changements non commités
git stash                     # Mettre de côté
git stash pop                 # Récupérer
```

### Tests
```bash
test.bat check                # Validation rapide
test.bat all                  # Tests complets
test.bat report               # Rapport HTML
```

### Qualité
```bash
vendor\bin\phpstan analyse    # Analyse statique
vendor\bin\phpcs app/         # Code style check
vendor\bin\php-cs-fixer fix   # Auto-fix style
```

---

## RÉSUMÉ QUOTIDIEN

```
1. git pull origin main
2. git checkout -b feature/xxx
3. [coder]
4. test.bat check
5. git commit -m "type(scope): message"
6. git push origin feature/xxx
7. Créer PR
```

---

*Dernière mise à jour : 2026-02-04*
