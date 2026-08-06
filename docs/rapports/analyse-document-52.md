# RAPPORT D'ANALYSE — Document Rapport_Fragilisation_Hydrogene_SwissBioSolution

**Date :** 2026-02-04 19:30  
**Document ID :** 52 (à confirmer)  
**Agent :** Agent-3

---

## 📋 PROBLÈMES IDENTIFIÉS

### 1. ❌ IA > Aucune suggestion applicable

**Symptôme :**  
Le bouton "Suggestions IA" affiche le message "Aucune suggestion applicable" après analyse.

**Analyse du code :**
- Fonction : `getAISuggestionsPreview()` dans `templates/documents/index.php` ligne 1254
- Endpoint appelé : `POST /api/documents/{id}/classify-ai`
- Logique : Si `appliedCount === 0` → message "Aucune suggestion applicable"

**Causes possibles :**
1. Les suggestions sont retournées mais ne correspondent à aucun champ existant (`matched` vide)
2. Les IDs de champs dans `matched` ne correspondent pas aux IDs réels en DB
3. Les éléments DOM (`preview-title-input`, `preview-type-select`, etc.) ne sont pas trouvés
4. L'IA retourne des suggestions mais sans correspondance avec les entités existantes

**Code concerné :**
```javascript
// templates/documents/index.php ligne 1272-1333
if (result.success && result.data?.suggestions) {
    const s = result.data.suggestions;
    const matched = s.matched || {};
    let appliedCount = 0;
    
    // Applique seulement si matched contient les IDs
    if (matched.document_type_id) { ... }
    if (matched.correspondent_id) { ... }
    // Si matched est vide → appliedCount reste 0
}
```

**Action requise :**
- Vérifier que `matched` contient bien les IDs
- Logger les suggestions retournées pour debug
- Vérifier que les éléments DOM existent dans la modal

---

### 2. ⚠️ Bouton "Vu" vert (✓) — Fonction incorrecte

**Symptôme :**  
Le bouton vert (✓) en haut à droite valide le document au lieu d'enregistrer les modifications.

**Comportement actuel :**
- Bouton vert (✓) : `onclick="setValidationStatus(${doc.id}, 'approved')"`
- Bouton "Enregistrer" en bas : `onclick="saveDocumentPreview(${doc.id})"`

**Comportement attendu :**  
Le bouton vert (✓) devrait avoir la même fonction que "Enregistrer" → enregistrer les modifications du formulaire.

**Code concerné :**
```javascript
// templates/documents/index.php ligne 797-800
<button onclick="setValidationStatus(${doc.id}, 'approved')" title="Valider"
        class="...">
    <svg>...</svg> <!-- ✓ -->
</button>

// ligne 984
<button onclick="saveDocumentPreview(${doc.id})" class="...">
    Enregistrer
</button>
```

**Action requise :**
- Changer `onclick` du bouton vert pour appeler `saveDocumentPreview()` au lieu de `setValidationStatus()`
- OU créer un bouton séparé pour la validation si nécessaire

---

### 3. ⚠️ Bouton "Croix" (✗) — Fonction incorrecte

**Symptôme :**  
Le bouton croix (✗) rejette le document au lieu de fermer la modal.

**Comportement actuel :**
- Bouton croix (✗) : `onclick="setValidationStatus(${doc.id}, 'rejected')"`
- Bouton "Fermer" en bas : `onclick="closeDocumentPreview()"`

**Comportement attendu :**  
Le bouton croix (✗) devrait fermer la modal sans modifier le document.

**Code concerné :**
```javascript
// templates/documents/index.php ligne 801-804
<button onclick="setValidationStatus(${doc.id}, 'rejected')" title="Rejeter"
        class="...">
    <svg>...</svg> <!-- ✗ -->
</button>

// ligne 981
<button onclick="closeDocumentPreview()" class="...">
    Fermer
</button>
```

**Action requise :**
- Changer `onclick` du bouton croix pour appeler `closeDocumentPreview()` au lieu de `setValidationStatus()`
- OU créer un bouton séparé pour le rejet si nécessaire

---

### 4. ⚠️ Toggle validation 3 phases — Mapping DB incorrect

**Symptôme :**  
Le système de validation utilise des strings ('approved', 'rejected', 'na') au lieu d'un toggle avec valeurs DB (1, 0, null).

**État actuel :**
- DB : `validation_status ENUM('pending', 'approved', 'rejected') DEFAULT NULL`
- Code : Utilise 'approved', 'rejected', 'na' (mais 'na' n'est pas dans l'ENUM !)
- API : Accepte 'approved', 'rejected', 'na' mais DB ne supporte pas 'na'

**Comportement attendu :**  
Toggle 3 phases avec correspondance DB :
- Validé → `validation_status = 'approved'` (ou 1 si conversion)
- Non validé → `validation_status = 'rejected'` (ou 0 si conversion)
- N/A → `validation_status = NULL` (ou null)

**Problème identifié :**
- L'ENUM DB ne contient pas 'na'
- Le code utilise 'na' mais la DB ne l'accepte pas
- Besoin de modifier soit l'ENUM DB, soit le code

**Code concerné :**
```sql
-- database/migrations/016_document_validation.sql ligne 10
ALTER TABLE documents
ADD COLUMN IF NOT EXISTS validation_status ENUM('pending', 'approved', 'rejected') DEFAULT NULL
```

```php
// app/Controllers/Api/ValidationApiController.php ligne 137
if (!in_array($status, ['approved', 'rejected', 'na'])) {
    return $this->jsonResponse($response, ['error' => 'Statut invalide'], 400);
}
```

**Action requise :**
- Option 1 : Ajouter 'na' à l'ENUM DB
- Option 2 : Mapper 'na' → NULL dans le code avant insertion
- Option 3 : Utiliser un toggle qui change entre les 3 états (approved → rejected → NULL → approved)

---

### 5. ❌ Métadonnées manquantes

**Symptôme :**  
Le document n'a pas de date, pas de type, pas de règle appliquée.

**Métadonnées attendues :**
- `document_date` : Date du document
- `document_type_id` : Type de document
- Règle d'attribution appliquée (si applicable)

**Causes possibles :**
1. L'extraction de date a échoué (MetadataExtractor)
2. Aucun type de document n'a été suggéré/appliqué
3. Aucune règle d'attribution n'a été déclenchée
4. Le document n'a pas été traité par le flux de consommation

**Action requise :**
- Vérifier le contenu du document (texte extrait)
- Vérifier les logs d'extraction de métadonnées
- Vérifier si des règles d'attribution existent et sont applicables
- Tester l'extraction manuelle de date/type

---

## 🔍 ANALYSE TECHNIQUE DÉTAILLÉE

### Mapping validation_status

**État actuel :**
| Valeur UI | Valeur API | Valeur DB | Statut |
|-----------|------------|-----------|--------|
| Validé (✓) | 'approved' | 'approved' | ✅ OK |
| Rejeté (✗) | 'rejected' | 'rejected' | ✅ OK |
| N/A (-) | 'na' | ❌ Non supporté | ❌ ERREUR |

**État attendu :**
| Valeur UI | Valeur API | Valeur DB | Statut |
|-----------|------------|-----------|--------|
| Validé (✓) | 'approved' | 'approved' ou 1 | À définir |
| Non validé (✗) | 'rejected' | 'rejected' ou 0 | À définir |
| N/A (-) | 'na' | NULL ou null | À définir |

### Fonctions JavaScript concernées

1. **`setValidationStatus(docId, status)`** - ligne 1205
   - Appelle API `/api/validation/${docId}/status`
   - Met à jour le statut de validation uniquement
   - Ne sauvegarde pas les modifications du formulaire

2. **`saveDocumentPreview(docId, goNext)`** - ligne 1118
   - Sauvegarde toutes les modifications du formulaire
   - Appelle API `/api/documents/${docId}`
   - Peut naviguer au document suivant si `goNext === true`

3. **`closeDocumentPreview()`** - ligne 480
   - Ferme la modal sans sauvegarder
   - Ne modifie pas le document

4. **`getAISuggestionsPreview(docId)`** - ligne 1254
   - Appelle API `/api/documents/${docId}/classify-ai`
   - Applique les suggestions aux champs du formulaire
   - Affiche "Aucune suggestion applicable" si `appliedCount === 0`

---

## 📝 RECOMMANDATIONS

### Priorité 1 (Critique)
1. **Corriger le mapping validation_status 'na'**
   - Ajouter 'na' à l'ENUM DB OU mapper 'na' → NULL dans le code
   - Tester le toggle 3 phases

2. **Corriger les boutons vert/croix**
   - Bouton vert (✓) → `saveDocumentPreview()` au lieu de `setValidationStatus()`
   - Bouton croix (✗) → `closeDocumentPreview()` au lieu de `setValidationStatus()`
   - OU créer des boutons séparés pour validation/rejet

### Priorité 2 (Important)
3. **Debug suggestions IA**
   - Logger les suggestions retournées
   - Vérifier que `matched` contient les IDs corrects
   - Vérifier que les éléments DOM existent

4. **Extraction métadonnées**
   - Vérifier pourquoi date/type ne sont pas extraits
   - Tester MetadataExtractor sur ce document
   - Vérifier les règles d'attribution

### Priorité 3 (Amélioration)
5. **Améliorer UX**
   - Ajouter un bouton dédié pour validation/rejet si nécessaire
   - Clarifier la différence entre "Enregistrer" et "Valider"
   - Ajouter des tooltips explicatifs

---

## 🧪 TESTS À EFFECTUER

1. **Test bouton vert (✓)**
   - Cliquer sur ✓ → doit enregistrer les modifications
   - Vérifier que les champs sont sauvegardés en DB

2. **Test bouton croix (✗)**
   - Cliquer sur ✗ → doit fermer la modal
   - Vérifier que le document n'est pas modifié

3. **Test toggle validation**
   - Cliquer sur ✓ → `validation_status = 'approved'`
   - Cliquer sur ✗ → `validation_status = 'rejected'`
   - Cliquer sur - → `validation_status = NULL`
   - Vérifier en DB après chaque clic

4. **Test suggestions IA**
   - Ouvrir console browser (F12)
   - Cliquer "Suggestions IA"
   - Observer la réponse API dans Network
   - Vérifier que `matched` contient des IDs
   - Vérifier que les éléments DOM existent

5. **Test extraction métadonnées**
   - Vérifier le contenu texte du document
   - Tester MetadataExtractor manuellement
   - Vérifier les règles d'attribution

---

## 📦 FICHIERS À MODIFIER

1. **templates/documents/index.php**
   - Ligne 797-808 : Boutons validation (changer onclick)
   - Ligne 1254-1344 : Fonction `getAISuggestionsPreview()` (ajouter logs)

2. **database/migrations/016_document_validation.sql**
   - Ligne 10 : Ajouter 'na' à l'ENUM OU créer migration pour modifier

3. **app/Controllers/Api/ValidationApiController.php**
   - Ligne 137-149 : Gérer 'na' → NULL mapping

4. **app/Services/ValidationService.php**
   - Ligne 113-149 : Gérer 'na' → NULL dans validate()

---

*Rapport généré le 2026-02-04 19:30*
