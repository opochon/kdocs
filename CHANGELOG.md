# Changelog

Toutes les modifications notables de ce projet sont documentées ici.

Le format est basé sur [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/),
et ce projet adhère au [Semantic Versioning](https://semver.org/lang/fr/).

## [Unreleased]

### Added
- Suite de tests complète (smoke, API, integration, UI)
- Pre-commit hooks pour anti-régression
- PHPStan niveau 5 pour analyse statique
- GitHub Actions CI/CD pipeline
- Tests unitaires PHPUnit
- Bouton "Tester la cascade" dans admin/settings

### Changed
- Mise à jour composer.json avec scripts de test
- LibreOffice version affichée dans settings

### Fixed
- Performance page settings (version LibreOffice en cache)

## [1.0.0] - 2026-02-03

### Added
- POC validé 100% (59/59 tests)
- Cascade IA : Claude → Ollama → Règles
- Extraction multi-format (PDF, DOCX, MSG)
- OCR avec Tesseract
- Classification automatique
- Embeddings Ollama (768 dims)
- Recherche sémantique
- Training et apprentissage
- Split PDF intelligent
- Interface GED complète
- 3 flux : consume, filesystem, drop UI

### Infrastructure
- PHP 8.4 + MySQL
- Ollama (llama3.1:8b, nomic-embed-text)
- Tesseract OCR
- Ghostscript
- LibreOffice (conversion)

---

## Convention de commit

```
type(scope): description

Types:
- feat: nouvelle fonctionnalité
- fix: correction de bug
- docs: documentation
- style: formatage
- refactor: refactoring
- test: ajout/modification tests
- chore: maintenance
```

Exemples:
```
feat(ai): add cascade classification
fix(upload): handle large files
test(api): add document endpoint tests
```
