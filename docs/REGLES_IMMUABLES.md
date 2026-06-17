# K-DOCS — RÈGLES IMMUABLES

> **Ce document contient les règles qui ne changent JAMAIS.**
> Toute modification de ce fichier nécessite une revue complète.

---

## 1. SÉCURITÉ DES DONNÉES UTILISATEUR

### 1.1 Fichiers
```
❌ INTERDIT : Déplacer/supprimer un fichier utilisateur sans demande EXPLICITE
❌ INTERDIT : Modifier le contenu d'un document original
❌ INTERDIT : Écraser un fichier existant sans confirmation
✅ OBLIGATOIRE : Toujours garder une copie dans trash/ avant suppression
✅ OBLIGATOIRE : Log de toute opération sur fichier
```

### 1.2 Base de données
```
❌ INTERDIT : DELETE sans WHERE
❌ INTERDIT : TRUNCATE sur tables de production
❌ INTERDIT : DROP TABLE sans backup
✅ OBLIGATOIRE : Requêtes préparées (pas de concaténation SQL)
✅ OBLIGATOIRE : Transactions pour opérations multiples
```

### 1.3 Credentials
```
❌ INTERDIT : Credentials en dur dans le code
❌ INTERDIT : Credentials dans les logs
❌ INTERDIT : Credentials dans Git
✅ OBLIGATOIRE : Variables d'environnement ou fichiers config ignorés
```

---

## 2. QUALITÉ CODE

### 2.1 Avant chaque commit
```
✅ OBLIGATOIRE : test.bat check PASSE
✅ OBLIGATOIRE : Pas d'erreur PHP (syntax check)
✅ OBLIGATOIRE : Smoke tests passent
```

### 2.2 Encodage
```
✅ OBLIGATOIRE : UTF-8 partout (fichiers, DB, HTTP)
✅ OBLIGATOIRE : mb_* functions pour strings
❌ INTERDIT : Encodages mixtes
```

### 2.3 Architecture
```
✅ OBLIGATOIRE : Controllers = HTTP uniquement
✅ OBLIGATOIRE : Services = logique métier
✅ OBLIGATOIRE : Repositories = accès données
❌ INTERDIT : Logique métier dans Controllers
❌ INTERDIT : SQL direct dans Controllers
```

---

## 3. ANTI-RÉGRESSION

### 3.1 Tests obligatoires
```
Avant release :
  ✅ smoke_test.php     PASS (100%)
  ✅ api_test.php       PASS (≥95%)
  ✅ integration_test   PASS (≥90%)
  ✅ poc test_all.php   PASS (≥95%)
```

### 3.2 Seuils de qualité
```
| Métrique | Minimum | Cible |
|----------|---------|-------|
| Smoke    | 100%    | 100%  |
| API      | 95%     | 100%  |
| Integration | 90%  | 95%   |
| POC      | 95%     | 100%  |
| Coverage | 60%     | 80%   |
```

### 3.3 Échecs critiques (bloquants)
```
❌ Base de données inaccessible
❌ Erreur PHP fatale sur route principale
❌ Upload impossible
❌ Login impossible
❌ Perte de données
```

---

## 4. COMMITS

### 4.1 Format obligatoire
```
type(scope): description courte

Types autorisés :
  feat     : nouvelle fonctionnalité
  fix      : correction bug
  docs     : documentation
  style    : formatage (pas de changement code)
  refactor : refactoring
  test     : ajout/modif tests
  chore    : maintenance
```

### 4.2 Exemples
```
✅ feat(documents): add bulk delete action
✅ fix(upload): handle special characters in filename
✅ test(api): add search endpoint tests
❌ "fixed stuff"
❌ "update"
❌ "wip"
```

---

## 5. MODE LOCAL TOUJOURS DISPONIBLE

### 5.1 Dépendances externes optionnelles
```
L'application DOIT fonctionner sans :
  - Internet
  - Claude API (fallback Ollama)
  - Ollama (fallback règles)
  - OnlyOffice
  - Services cloud
```

### 5.2 Outils locaux requis
```
OBLIGATOIRES (mode dégradé si absents) :
  - PHP 8.1+
  - MySQL/MariaDB
  - Tesseract (OCR dégradé si absent)

OPTIONNELS :
  - Ghostscript (miniatures PDF)
  - LibreOffice (conversion Office)
  - Ollama (IA locale)
```

---

## 6. PROCESS DE MODIFICATION DE CE FICHIER

```
1. Créer une issue GitHub/ticket
2. Discussion avec tous les contributeurs
3. Vote unanime requis
4. PR avec justification détaillée
5. Revue par 2 personnes minimum
6. Mise à jour CHANGELOG.md
```

---

## 7. CHECKLIST AVANT RELEASE

```
□ Tous les tests passent (test.bat all)
□ PHPStan sans erreur niveau 5
□ CHANGELOG.md mis à jour
□ Version bump dans composer.json
□ Tag Git créé
□ Backup base de données
□ Documentation à jour
```

---

## SIGNATURES

Ce document est approuvé par :
- Olivier Pochon (2026-02-04)

---

*Version : 1.0*
*Dernière mise à jour : 2026-02-04*
*Ce fichier ne doit PAS être modifié sans processus formel.*
