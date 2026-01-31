# K-Docs - Corrections Prioritaires

## Contexte

L'application K-Docs présente des problèmes fondamentaux qui empêchent son utilisation normale. Ces problèmes doivent être corrigés avant tout autre développement.

## Problèmes identifiés (30/01/2026) - MISE À JOUR APRÈS TEST

### 🔴 P0 - CRITIQUES (bloquants)

#### 0a. Aperçu DOCX = fond bleu au lieu de miniature
- **Problème** : Dans la modale, les DOCX affichent un rectangle bleu avec "DOCX" au lieu de la vraie miniature
- **Fichier** : `templates/documents/index.php` - fonction qui génère l'aperçu
- **Attendu** : Charger `<img src="/documents/{id}/thumbnail">` 
- **Fix** :
```javascript
// REMPLACER le placeholder bleu par :
if (isOfficeDocument(doc.mime_type)) {
    viewerHtml = `
        <div class="flex flex-col items-center justify-center h-full gap-4">
            <img src="${BASE_PATH}/documents/${doc.id}/thumbnail" 
                 class="max-h-64 shadow-lg rounded"
                 onerror="this.parentElement.innerHTML='<div class=\\'text-6xl\\'>📄</div><p>Miniature non disponible</p>'">
            <button onclick="openOnlyOffice(${doc.id})" 
                    class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">
                📝 Ouvrir dans l'éditeur
            </button>
        </div>
    `;
}
```

#### 0b. Badge "En attente" pas cliquable
- **Problème** : Le badge validation en haut à droite de la modale n'est pas interactif
- **Fichier** : `templates/documents/index.php` - section header modale
- **Attendu** : Cliquer cycle : ⏳ En attente → ✅ Validé → ❌ Rejeté
- **Fix** :
```javascript
// Remplacer le span statique par un bouton
function renderValidationBadge(doc) {
    const states = {
        'pending': { label: '⏳ En attente', class: 'bg-yellow-100 text-yellow-800', next: 'validated' },
        'validated': { label: '✅ Validé', class: 'bg-green-100 text-green-800', next: 'rejected' },
        'rejected': { label: '❌ Rejeté', class: 'bg-red-100 text-red-800', next: 'pending' }
    };
    const current = states[doc.validation_status] || states['pending'];
    
    return `<button onclick="cycleValidation(${doc.id}, '${current.next}')"
                    class="px-3 py-1 rounded-full text-sm font-medium ${current.class} 
                           hover:opacity-80 cursor-pointer transition">
                ${current.label}
            </button>`;
}

async function cycleValidation(docId, newStatus) {
    const res = await fetch(`${BASE_PATH}/api/documents/${docId}`, {
        method: 'PUT',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({validation_status: newStatus})
    });
    if (res.ok) {
        // Recharger le document
        loadDocument(docId);
        showNotification('Statut mis à jour', 'success');
    }
}
```

#### 0c. Pas de bouton "Éditer avec OnlyOffice" pour DOCX
- **Problème** : Impossible d'ouvrir un document Office pour édition depuis la modale
- **Attendu** : Bouton qui ouvre OnlyOffice dans un nouvel onglet
- **Fix** : Ajouter le bouton (voir 0a) + implémenter `openOnlyOffice()`
```javascript
function openOnlyOffice(docId) {
    window.open(`${BASE_PATH}/documents/${docId}/edit`, '_blank');
}
```

### 🟡 P1 - IMPORTANTS

#### 1. Page `/documents/{id}` ne devrait pas exister
- **Problème** : URL directe ouvre une page séparée au lieu de la modale
- **Attendu** : Rediriger vers `/documents?open={id}` qui ouvre la modale
- **Fichier** : `app/Controllers/DocumentsController.php`
```php
public function show(int $id): void {
    header("Location: /kdocs/documents?open={$id}");
    exit;
}
```
Et dans `index.php`, au chargement :
```javascript
const urlParams = new URLSearchParams(window.location.search);
const openId = urlParams.get('open');
if (openId) {
    openDocumentModal(parseInt(openId));
}
```

#### 2. OnlyOffice cassé
- **Problème** : "Échec du téléchargement" quand on essaie d'éditer
- **Diagnostic** :
```bash
docker logs onlyoffice-docs --tail 50
curl http://localhost:8080/healthcheck
```
- **Causes probables** :
  - JWT secret désynchronisé
  - URL callback inaccessible depuis Docker (`host.docker.internal`)
  - Certificat SSL

#### 3. OCR Tesseract non disponible
- **Problème** : "OCR échoué: Tesseract non disponible ou image illisible"
- **Diagnostic** :
```bash
where tesseract
tesseract --version
tesseract --list-langs
```
- **Fix** : Installer Tesseract + langue française
```bash
# Windows - Télécharger depuis GitHub
# https://github.com/UB-Mannheim/tesseract/wiki
# Ajouter au PATH
```

### 🟢 P2 - MINEURS

#### 4. Date parsing "18.01.0026" au lieu de "18.01.2026"
- Bug dans l'extraction de date depuis le nom de fichier

#### 5. Miniatures PDF manquantes
- Vérifier Ghostscript : `gswin64c -version`

---

## Fichiers à modifier

| Fichier | Modifications |
|---------|---------------|
| `templates/documents/index.php` | Aperçu DOCX, badge validation, bouton OnlyOffice |
| `app/Controllers/DocumentsController.php` | Redirection show() |
| `public/assets/js/documents.js` | Functions JS (si séparé) |
| `config/onlyoffice.php` | Vérifier JWT |

---

## Tests de validation

Après corrections, vérifier :

1. [ ] Clic sur DOCX → modale avec miniature visible (pas fond bleu)
2. [ ] Clic sur image → modale avec image affichée ✅ (déjà OK)
3. [ ] Clic sur badge "En attente" → cycle vers "Validé"
4. [ ] Clic sur "Ouvrir dans l'éditeur" → nouvel onglet OnlyOffice
5. [ ] URL `/documents/49` → redirige vers `/documents?open=49`
6. [ ] OnlyOffice charge le document sans erreur

---

## Ordre de correction recommandé

1. **Badge validation cliquable** (30 min) - Impact UX immédiat
2. **Aperçu DOCX = miniature** (30 min) - Impact UX immédiat  
3. **Bouton OnlyOffice** (15 min) - Dépend du fix OnlyOffice
4. **Diagnostic OnlyOffice** (1-2h) - Peut être complexe
5. **Redirection page détail** (15 min)
6. **Tesseract** (30 min)

---

*Document mis à jour le 30/01/2026 après test navigateur*
