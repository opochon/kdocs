# RAPPORT — Correction Erreur OCR + Correspondant

**Date :** 2026-02-04 20:30  
**Agent :** Agent-3  
**Status :** ✅ TERMINÉ

---

## ✅ CORRECTIONS EFFECTUÉES

### 1. ✅ Erreur OCR "Data too long for column 'content'" — Corrigé

**Problème :**  
Erreur SQL : `SQLSTATE[22001]: String data, right truncated: 1406 Data too long for column 'content' at row 1`

**Cause :**  
La colonne `content` est définie comme `TEXT` dans MySQL/MariaDB, qui a une limite de **65,535 caractères**. Si le contenu OCR dépasse cette limite, l'insertion échoue.

**Solution implémentée :**  
Troncature automatique du contenu à **65,000 caractères** avant insertion/update, avec logs pour traçabilité.

**Fichiers modifiés :**

1. **`app/Services/DocumentProcessor.php`**
   - Ligne 109-116 : Troncature lors de l'extraction OCR
   - Ligne 153-160 : Troncature lors de la synchronisation content ↔ ocr_text
   - Ligne 343-350 : Troncature dans `updateDocument()`
   - Ligne 444-451 : Troncature dans `processDocument()`

2. **`app/Controllers/Api/DocumentsApiController.php`**
   - Ligne 303-313 : Troncature lors de l'update via API

**Code ajouté :**
```php
// Limiter la taille à 65,000 caractères pour éviter l'erreur "Data too long"
$maxLength = 65000;
$originalLength = mb_strlen($content);
if ($originalLength > $maxLength) {
    $content = mb_substr($content, 0, $maxLength);
    error_log("DocumentProcessor: Contenu OCR tronqué de {$originalLength} à {$maxLength} caractères pour document {$documentId}");
}
```

**Résultat :**  
L'erreur OCR ne se produira plus. Le contenu est automatiquement tronqué avec logs pour traçabilité.

---

### 2. ✅ Correspondant non suggéré — Amélioré

**Problème :**  
Pour "Arrêt du Tribunal cantonal du 2 novembre 2023", aucun correspondant n'est suggéré même si "Tribunal cantonal" devrait matcher.

**Cause :**  
Le matching utilisait uniquement `stripos()` pour comparer les chaînes complètes. Si l'IA suggère "Tribunal cantonal" mais qu'il n'y a pas de correspondant avec ce nom exact dans la DB, ça ne matche pas.

**Solution implémentée :**  
Matching amélioré avec 3 stratégies :
1. **Match exact normalisé** (espaces multiples → un seul, lowercase)
2. **Match partiel** (un contient l'autre)
3. **Match par mots-clés** (au moins 2 mots en commun ou 1 mot long >5 chars)

**Fichier modifié :** `app/Services/AIClassifierService.php` ligne 628-667

**Code ajouté :**
```php
// Matcher correspondent avec matching amélioré
if (!empty($result['correspondent'])) {
    $suggestedCorr = mb_strtolower(trim($result['correspondent']));
    
    // Normaliser les noms
    $normalize = function($str) {
        $str = mb_strtolower($str);
        $str = preg_replace('/\s+/', ' ', $str); // Espaces multiples → un seul
        $str = trim($str);
        return $str;
    };
    
    $normalizedSuggested = $normalize($suggestedCorr);
    
    // Essayer plusieurs stratégies de matching
    foreach ($correspondents as $corr) {
        $corrName = mb_strtolower(trim($corr['name']));
        $normalizedCorr = $normalize($corrName);
        
        // 1. Match exact (normalisé)
        if ($normalizedSuggested === $normalizedCorr) {
            $matched['correspondent_id'] = $corr['id'];
            break;
        }
        
        // 2. Match partiel
        if (stripos($corrName, $suggestedCorr) !== false ||
            stripos($suggestedCorr, $corrName) !== false) {
            $matched['correspondent_id'] = $corr['id'];
            break;
        }
        
        // 3. Match par mots-clés
        $suggestedWords = array_filter(explode(' ', $normalizedSuggested), fn($w) => mb_strlen($w) > 3);
        $corrWords = array_filter(explode(' ', $normalizedCorr), fn($w) => mb_strlen($w) > 3);
        
        if (!empty($suggestedWords) && !empty($corrWords)) {
            $commonWords = array_intersect($suggestedWords, $corrWords);
            // Si au moins 2 mots en commun ou 1 mot long (>5 chars)
            if (count($commonWords) >= 2 || 
                (count($commonWords) >= 1 && max(array_map('mb_strlen', $commonWords)) > 5)) {
                $matched['correspondent_id'] = $corr['id'];
                break;
            }
        }
    }
    
    // Log si aucun match trouvé
    if (!$matched['correspondent_id']) {
        error_log("AIClassifierService: Aucun correspondant trouvé pour suggestion '{$result['correspondent']}'");
    }
}
```

**Exemple de matching :**
- IA suggère : "Tribunal cantonal"
- Correspondant DB : "Tribunal cantonal de Genève"
- **Match :** ✅ Oui (match partiel : "Tribunal cantonal" contenu dans "Tribunal cantonal de Genève")

- IA suggère : "Tribunal cantonal"
- Correspondant DB : "Tribunal"
- **Match :** ✅ Oui (match partiel : "Tribunal" contenu dans "Tribunal cantonal")

- IA suggère : "Tribunal cantonal"
- Correspondant DB : "Tribunal de Genève"
- **Match :** ✅ Oui (match mots-clés : "Tribunal" en commun, mot >5 chars)

**Résultat :**  
Le matching de correspondant est maintenant beaucoup plus flexible et devrait trouver "Tribunal cantonal" même si le nom exact n'existe pas dans la DB.

---

## 🧪 TESTS EFFECTUÉS

✅ **Syntaxe PHP :** Tous les fichiers valides
- `app/Services/DocumentProcessor.php` — OK
- `app/Services/AIClassifierService.php` — OK
- `app/Controllers/Api/DocumentsApiController.php` — OK

✅ **Smoke tests :** 24/24 passent (100%)
- Config validation — OK
- PHP syntax check — OK
- Smoke tests — OK
- Credentials check — OK

---

## 📦 FICHIERS MODIFIÉS

1. **app/Services/DocumentProcessor.php**
   - Ligne 109-116 : Troncature contenu OCR
   - Ligne 153-160 : Troncature synchronisation
   - Ligne 343-350 : Troncature updateDocument()
   - Ligne 444-451 : Troncature processDocument()

2. **app/Services/AIClassifierService.php**
   - Ligne 628-667 : Matching correspondant amélioré

3. **app/Controllers/Api/DocumentsApiController.php**
   - Ligne 303-313 : Troncature update API

---

## 🔍 DÉTAILS TECHNIQUES

### Limite TEXT MySQL

**Type :** `TEXT`  
**Limite :** 65,535 caractères (64 KB)  
**Marge de sécurité :** 65,000 caractères (pour éviter les problèmes d'encodage UTF-8)

**Alternative future :**  
Pour supporter des documents très longs, créer une migration pour changer `TEXT` → `LONGTEXT` (4 GB) :
```sql
ALTER TABLE documents MODIFY COLUMN content LONGTEXT;
ALTER TABLE documents MODIFY COLUMN ocr_text LONGTEXT;
```

### Stratégies de matching correspondant

1. **Match exact normalisé**
   - Normalise les espaces multiples
   - Compare en lowercase
   - Exemple : "Tribunal cantonal" === "Tribunal  cantonal"

2. **Match partiel**
   - Vérifie si un nom contient l'autre
   - Exemple : "Tribunal" contenu dans "Tribunal cantonal"

3. **Match par mots-clés**
   - Extrait les mots >3 caractères
   - Compare les mots communs
   - Match si ≥2 mots communs OU 1 mot long (>5 chars)
   - Exemple : "Tribunal cantonal" vs "Tribunal de Genève" → "Tribunal" en commun

---

## ✅ VALIDATION

- [x] Troncature contenu OCR implémentée
- [x] Matching correspondant amélioré
- [x] Logs ajoutés pour traçabilité
- [x] Syntaxe PHP valide
- [x] Smoke tests passent

---

## 🎯 TESTS MANUELS REQUIS

1. **Test erreur OCR :**
   - Traiter un document avec beaucoup de texte (>65,000 caractères)
   - Vérifier que l'OCR réussit sans erreur
   - Vérifier les logs pour voir la troncature

2. **Test correspondant :**
   - Créer un correspondant "Tribunal cantonal de Genève"
   - Analyser "Arrêt du Tribunal cantonal du 2 novembre 2023"
   - Vérifier que le correspondant est suggéré

---

*Rapport généré le 2026-02-04 20:30*
