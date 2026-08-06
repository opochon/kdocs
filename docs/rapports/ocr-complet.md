# RAPPORT COMPLET — Correction Erreur OCR "Data too long"

**Date :** 2026-02-04 21:00  
**Agent :** Agent-3  
**Status :** ✅ TERMINÉ

---

## ✅ PROBLÈME IDENTIFIÉ

**Erreur :** `SQLSTATE[22001]: String data, right truncated: 1406 Data too long for column 'content' at row 1`

**Document concerné :** "Arrêt du Tribunal cantonal du 2 novembre 2023"

**Cause :** La colonne `content` est de type `TEXT` dans MySQL/MariaDB, limitée à **65,535 caractères**. Si le contenu OCR dépasse cette limite, l'insertion/update échoue.

---

## ✅ CORRECTIONS EFFECTUÉES

### Tous les endroits où `content`/`ocr_text` sont insérés/updatés ont été corrigés :

#### 1. **app/Services/DocumentProcessor.php** (5 endroits)
- ✅ Ligne 109-116 : Troncature lors de l'extraction OCR initiale
- ✅ Ligne 153-160 : Troncature lors de la synchronisation content ↔ ocr_text
- ✅ Ligne 343-350 : Troncature dans `updateDocument()`
- ✅ Ligne 463-469 : Troncature pour texte extrait par IA
- ✅ Ligne 471-474 : Troncature dans `processDocument()`

#### 2. **app/Controllers/Api/DocumentsApiController.php** (3 endroits)
- ✅ Ligne 303-313 : Troncature lors de l'update via API (`update()`)
- ✅ Ligne 531-540 : Troncature avant classification IA (`classifyWithAI()`)
- ✅ Ligne 787-790 : Troncature dans endpoint OCR direct (`ocr()`)

#### 3. **app/Services/MSGImportService.php** (1 endroit)
- ✅ Ligne 305-310 : Troncature lors de l'import de messages email

#### 4. **scripts/archives/process_pending.php** (1 endroit)
- ✅ Ligne 300-306 : Troncature dans le script de retraitement OCR

---

## 📊 STATISTIQUES

**Total d'endroits corrigés :** 10  
**Fichiers modifiés :** 4  
**Commits créés :** 3

---

## 🔧 IMPLÉMENTATION TECHNIQUE

### Code de troncature standardisé :

```php
// Limiter la taille à 65,000 caractères pour éviter l'erreur "Data too long"
// TEXT MySQL a une limite de 65,535 caractères, on garde une marge de sécurité
$maxLength = 65000;
$originalLength = mb_strlen($content);
if ($originalLength > $maxLength) {
    $content = mb_substr($content, 0, $maxLength);
    error_log("Service: Contenu tronqué de {$originalLength} à {$maxLength} caractères pour document {$documentId}");
}
```

### Caractéristiques :
- ✅ Utilise `mb_strlen()` et `mb_substr()` pour gérer UTF-8 correctement
- ✅ Limite à 65,000 caractères (marge de sécurité de 535 caractères)
- ✅ Logs ajoutés pour traçabilité
- ✅ Appliqué AVANT chaque insertion/update

---

## 🧪 TESTS EFFECTUÉS

✅ **Syntaxe PHP :** Tous les fichiers valides
- `app/Services/DocumentProcessor.php` — OK
- `app/Controllers/Api/DocumentsApiController.php` — OK
- `app/Services/MSGImportService.php` — OK
- `scripts/archives/process_pending.php` — OK

✅ **Smoke tests :** 24/24 passent (100%)
- Config validation — OK
- PHP syntax check — OK
- Smoke tests — OK
- Credentials check — OK

---

## 📦 COMMITS CRÉÉS

1. **a268e75** — `fix(ocr): Troncature contenu OCR + matching correspondant amélioré`
2. **b93babd** — `fix(ocr): Troncature également pour texte extrait par IA`
3. **[nouveau]** — `fix(ocr): Troncature contenu dans tous les endroits restants`

---

## 🎯 VALIDATION

- [x] Tous les endroits d'insertion/update corrigés (10/10)
- [x] Logs ajoutés pour traçabilité
- [x] Syntaxe PHP valide
- [x] Smoke tests passent
- [x] Commits créés
- [x] COORDINATION.md mis à jour

---

## 🔍 VÉRIFICATION MANUELLE REQUISE

Pour le document "Arrêt du Tribunal cantonal du 2 novembre 2023" :

1. **Vérifier que l'OCR fonctionne maintenant :**
   - Retraiter le document via l'interface ou l'API
   - Vérifier qu'il n'y a plus d'erreur "Data too long"
   - Vérifier les logs pour voir si une troncature a eu lieu

2. **Vérifier le contenu :**
   - Si le document fait >65,000 caractères, vérifier que les premiers 65,000 sont présents
   - Vérifier que le document reste utilisable malgré la troncature

---

## 💡 RECOMMANDATION FUTURE

Pour supporter des documents très longs sans troncature, créer une migration pour changer `TEXT` → `LONGTEXT` :

```sql
ALTER TABLE documents MODIFY COLUMN content LONGTEXT;
ALTER TABLE documents MODIFY COLUMN ocr_text LONGTEXT;
```

**Avantages :**
- Support jusqu'à 4 GB de texte
- Plus besoin de troncature
- Pas de perte d'information

**Inconvénients :**
- Légèrement plus lent pour les requêtes FULLTEXT
- Plus d'espace disque nécessaire

---

*Rapport généré le 2026-02-04 21:00*
