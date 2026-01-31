# KDOCS - Journal de travail collaboratif

> Coordination entre Claude Code CLI (modifications) et Claude.ai (tests visuels)

---

## 🎯 Écrans en cours

### 1. Documents (indexation UI) - En attente Claude Code
**URL**: http://localhost/kdocs/documents  
**Statut**: [x] En cours avec Claude Code

### 2. Fichiers à valider - NOUVEAU
**URL**: http://localhost/kdocs/admin/consume  
**Statut**: [x] Analyse terminée, prêt pour dev

---

## 📝 Tâches actuelles

### ÉCRAN: Fichiers à valider (prioritaire)

#### Bugs identifiés (25/01/2026)

| # | Problème | Fichier | Priorité |
|---|----------|---------|----------|
| **BUG-1** | OCR mal encodé (`f?d?ral` → `fédéral`) | `app/Services/OCRService.php` | 🔴 Haute |
| **BUG-2** | Tags non suggérés automatiquement | `app/Services/ClassificationService.php` | 🟡 Moyenne |
| **BUG-3** | Titre = nom dossier au lieu du vrai titre | `app/Services/ConsumeFolderService.php` | 🟡 Moyenne |
| **BUG-4** | Confiance toujours 0% | `app/Services/ClassificationService.php` | 🟢 Basse |

#### BUG-1: Fix OCR encodage UTF-8

**Problème**: Tesseract retourne du texte en ISO-8859-1 (Latin-1), pas UTF-8.
Les caractères accentués apparaissent comme `?`.

**Exemple visible**:
```
Bundesgericht Arr?t du 5 juin 2024
Tribunal f?d?ral lie Cour de droit civil
```

**Solution** - Modifier `app/Services/OCRService.php`:

```php
// Dans extractTextFromImage(), après file_get_contents:
$text = file_get_contents($outputFile . '.txt');

// FIX: Convertir en UTF-8 si nécessaire
if ($text && !mb_check_encoding($text, 'UTF-8')) {
    $detected = mb_detect_encoding($text, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
    if ($detected && $detected !== 'UTF-8') {
        $text = mb_convert_encoding($text, 'UTF-8', $detected);
    }
}

@unlink($outputFile . '.txt');
return trim($text);
```

**Test**: Re-scanner le document "Arrêt du 05_06_2024", vérifier que les accents s'affichent.

#### BUG-2: Suggestion automatique de tags

**Problème**: Le champ Tags reste vide, pas de suggestion.

**Solution**: Extraire les mots-clés significatifs du contenu OCR:
- Noms propres (majuscules)
- Termes juridiques/métier
- Dates
- Montants

**Fichiers à modifier**:
- `app/Services/ClassificationService.php` - Ajouter extraction keywords
- `app/Controllers/Api/SuggestedTagsApiController.php` - Endpoint existant ?

#### BUG-3: Extraction du titre depuis OCR

**Problème**: Titre = "toclassify" (nom du dossier source)

**Solution**: 
1. Extraire la première ligne significative du contenu OCR
2. Ou utiliser le nom de fichier original si informatif
3. Pattern matching pour documents juridiques ("Arrêt du...", "Jugement...")

---

### ÉCRAN: Documents (indexation UI) - En parallèle

#### Tâches Claude Code (script orchestrator)
- [ ] Endpoint API `/api/indexing-status`
- [ ] DocumentController lit `.indexing`
- [ ] Barre de progression UI
- [ ] Déclenchement auto indexation
- [ ] Scripts batch pour cron

---

## 🔧 Dernière modification

| Champ | Valeur |
|-------|--------|
| Fichier | - |
| Par | - |
| Date | 2026-01-25 |
| Description | Analyse bugs "Fichiers à valider" |

---

## ✅ Tests de régression

### Page Fichiers à valider (`/admin/consume`)
- [ ] OCR affiche les accents correctement
- [ ] Tags suggérés automatiquement
- [ ] Titre extrait du contenu (pas nom dossier)
- [ ] Confiance > 0% si règles matchent
- [ ] Bouton "Analyser avec l'IA" fonctionne
- [ ] Validation déplace le fichier correctement
- [ ] Pas d'erreur PHP/JS

### Page Documents
- [ ] Page charge < 1s
- [ ] Barre indexation s'affiche si `.indexing` présent
- [ ] Navigation/filtres fonctionnent

---

## 📋 Backlog écrans

1. **Fichiers à valider** (bugs OCR/tags) - PRIORITAIRE
2. **Documents** (indexation UI) - EN COURS
3. Upload
4. Types de documents
5. Correspondants
6. Dossiers logiques
7. Paramètres

---

## 🐛 Bugs connus

| # | Description | Écran | Priorité | Statut |
|---|-------------|-------|----------|--------|
| 1 | ~~Indexation lancée à chaque ouverture arbo~~ | Documents | Haute | ✅ Résolu |
| 2 | 10⚠ affiché mais pas de feedback utilisateur | Documents | Moyenne | 🔄 En cours |
| 3 | **OCR encodage cassé (accents = ?)** | Validation | Haute | 🆕 Nouveau |
| 4 | Tags non suggérés | Validation | Moyenne | 🆕 Nouveau |
| 5 | Titre = nom dossier | Validation | Moyenne | 🆕 Nouveau |

---

## 📅 Historique

### 2026-01-25
- Analyse page "Fichiers à valider"
- Identifié 4 bugs : OCR encodage, tags, titre, confiance
- Documenté fix OCR UTF-8
- Setup workflow collaboratif
- Bug performance 9s résolu
- Script orchestrator PowerShell créé

---

## 💡 Notes techniques

### OCR Stack
- **Tesseract** : `C:\Program Files\Tesseract-OCR\tesseract.exe`
- **pdftotext** : Pour extraction texte PDF natif (plus rapide)
- **pdftoppm** : Conversion PDF → images pour OCR
- **ImageMagick** : Fallback conversion

### Encodage
- DB : `utf8mb4` (MariaDB port 3307)
- Tesseract output : ISO-8859-1 par défaut → nécessite conversion
- PHP : `mb_convert_encoding()` pour fix

### Commandes utiles
```bash
# Tester OCR manuellement
tesseract "document.png" output -l fra+eng

# Vérifier encodage d'un fichier
file --mime-encoding output.txt
```
