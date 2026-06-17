# PROMPT - Améliorations Page Settings K-Docs

## Contexte
La page `templates/admin/settings.php` a déjà une bonne section cascade IA.
On veut 3 améliorations.

## 1. AJOUTER LIBREOFFICE DANS LA SECTION OUTILS

Dans la section "🔧 Outils système", LibreOffice est présent mais il faut :
- Afficher la **version** de LibreOffice (pas juste "Disponible")
- Ajouter un indicateur si LibreOffice est **utilisé** (pour DOCX, XLSX, PPTX)

Code à ajouter pour récupérer la version :
```php
$libreofficeVersion = '';
if ($libreofficePath && file_exists($libreofficePath)) {
    $output = shell_exec('"' . $libreofficePath . '" --version 2>&1');
    if (preg_match('/LibreOffice\s+([\d.]+)/', $output, $m)) {
        $libreofficeVersion = $m[1];
    }
}
```

Afficher :
```
LibreOffice          ✅ v24.2.1
                     Conversion DOCX, XLSX, PPTX
```

## 2. AJOUTER ONLYOFFICE DANS LA CASCADE (si pertinent)

OnlyOffice a sa propre section, mais il faudrait clarifier son rôle :
- OnlyOffice = **édition en ligne** (pas classification)
- Ajouter une note explicative dans sa section

Texte à ajouter :
```html
<div class="mt-2 p-2 bg-gray-50 rounded text-xs text-gray-600">
    <strong>Note :</strong> OnlyOffice est utilisé pour l'édition collaborative des documents, 
    pas pour la classification IA. Il fonctionne indépendamment de la cascade Claude → Ollama → Règles.
</div>
```

## 3. BOUTON "TESTER LA CASCADE" 

Ajouter un bouton qui envoie un texte de test et affiche quel provider répond.

### 3.1 Ajouter le bouton dans la section IA

Après la div "Provider actuel", ajouter :
```html
<div class="mt-4 border-t pt-4">
    <button type="button" 
            id="btn-test-cascade"
            onclick="testCascade()"
            class="px-4 py-2 bg-purple-600 text-white rounded hover:bg-purple-700 text-sm">
        🧪 Tester la cascade
    </button>
    <span id="cascade-test-result" class="ml-4 text-sm"></span>
</div>

<div id="cascade-test-details" class="mt-3 hidden p-3 bg-gray-50 rounded border text-xs">
    <div><strong>Texte envoyé :</strong> <span id="test-input"></span></div>
    <div class="mt-1"><strong>Provider utilisé :</strong> <span id="test-provider"></span></div>
    <div class="mt-1"><strong>Résultat :</strong> <pre id="test-output" class="mt-1 bg-white p-2 rounded overflow-auto max-h-40"></pre></div>
    <div class="mt-1"><strong>Temps :</strong> <span id="test-time"></span></div>
</div>
```

### 3.2 JavaScript pour le test

```javascript
async function testCascade() {
    const btn = document.getElementById('btn-test-cascade');
    const result = document.getElementById('cascade-test-result');
    const details = document.getElementById('cascade-test-details');
    
    btn.disabled = true;
    btn.textContent = '⏳ Test en cours...';
    result.textContent = '';
    details.classList.add('hidden');
    
    try {
        const response = await fetch('<?= url("/api/ai/test") ?>', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                text: "Facture N° 2025-001 du 15 janvier 2025. Montant: 150.00 CHF. Client: Swisscom SA."
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            result.innerHTML = '<span class="text-green-600">✅ ' + data.provider + ' a répondu</span>';
            document.getElementById('test-input').textContent = data.input || 'Texte de test facture';
            document.getElementById('test-provider').textContent = data.provider;
            document.getElementById('test-output').textContent = JSON.stringify(data.result, null, 2);
            document.getElementById('test-time').textContent = data.time_ms + ' ms';
            details.classList.remove('hidden');
        } else {
            result.innerHTML = '<span class="text-red-600">❌ Erreur: ' + (data.error || 'Inconnu') + '</span>';
        }
    } catch (e) {
        result.innerHTML = '<span class="text-red-600">❌ Erreur réseau</span>';
    }
    
    btn.disabled = false;
    btn.textContent = '🧪 Tester la cascade';
}
```

### 3.3 API endpoint `/api/ai/test`

Créer ou vérifier que l'endpoint existe dans `routes/api.php` :

```php
$router->post('/api/ai/test', function() {
    header('Content-Type: application/json');
    
    $input = json_decode(file_get_contents('php://input'), true);
    $text = $input['text'] ?? 'Document de test pour classification';
    
    $aiProvider = new \KDocs\Services\AIProviderService();
    
    $start = microtime(true);
    $result = $aiProvider->classify($text);
    $time = round((microtime(true) - $start) * 1000);
    
    echo json_encode([
        'success' => true,
        'input' => substr($text, 0, 100) . '...',
        'provider' => $result['method'] ?? 'unknown',
        'result' => $result,
        'time_ms' => $time
    ]);
});
```

## Fichiers à modifier

1. `templates/admin/settings.php` - Ajouter version LibreOffice, note OnlyOffice, bouton test
2. `routes/api.php` - Vérifier/ajouter endpoint `/api/ai/test`

## Tests

Après modification :
1. Aller sur `/admin/settings`
2. Vérifier que LibreOffice affiche sa version
3. Cliquer sur "Tester la cascade"
4. Vérifier que le provider et le résultat s'affichent
