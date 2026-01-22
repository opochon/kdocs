# K-Docs - Bugs IA à Corriger

## 🐛 PROBLÈMES IDENTIFIÉS

### Problème 1 : Bouton "Suggestions IA" - Alert Placeholder

**Capture** : Image 1 - Alert "Suggestions IA à implémenter"

**Fichier** : `templates/documents/show.php` (ligne ~838)

**Code actuel** :
```javascript
function getAISuggestions(docId) {
    // TODO: Implémenter suggestions IA
    alert('Suggestions IA à implémenter');
}
```

**Solution** : Implémenter la vraie fonction qui appelle l'API Claude pour suggérer des métadonnées.

---

### Problème 2 : Recherche IA ne trouve rien

**Capture** : Image 2 - "Je n'ai trouvé aucun document correspondant à votre recherche" pour "document divorce"

**Cause probable** :
1. **Clé API Claude non configurée** → La recherche utilise le fallback `text_search` simple
2. **La recherche simple ne marche pas** → Bug dans la requête SQL ou données manquantes

**Fichier config** : `config/config.php`
```php
'claude' => [
    'api_key' => $_ENV['ANTHROPIC_API_KEY'] ?? null,  // NULL !
],
```

**Diagnostic à faire** :
1. Vérifier si le document "Courrier au Tribunal civil" existe en base
2. Vérifier son contenu OCR (colonne `content`)
3. Vérifier si le mot "divorce" apparaît dans le contenu ou le titre

---

## 🔧 CORRECTIONS À IMPLÉMENTER

### Correction 1 : getAISuggestions()

**Remplacer dans** `templates/documents/show.php` :

```javascript
async function getAISuggestions(docId) {
    const btn = event.target;
    const originalText = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Analyse...';
    
    try {
        const response = await fetch(`<?= url('/api/documents/') ?>${docId}/ai-classify`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' }
        });
        
        const data = await response.json();
        
        if (data.error) {
            alert('Erreur: ' + data.error);
            return;
        }
        
        // Afficher les suggestions dans un modal ou appliquer directement
        if (data.suggestions) {
            const msg = [];
            if (data.suggestions.title) msg.push(`Titre suggéré: ${data.suggestions.title}`);
            if (data.suggestions.correspondent) msg.push(`Correspondant: ${data.suggestions.correspondent}`);
            if (data.suggestions.document_type) msg.push(`Type: ${data.suggestions.document_type}`);
            if (data.suggestions.tags?.length) msg.push(`Tags: ${data.suggestions.tags.join(', ')}`);
            if (data.suggestions.date) msg.push(`Date: ${data.suggestions.date}`);
            
            if (msg.length === 0) {
                alert('Aucune suggestion disponible.');
                return;
            }
            
            const apply = confirm('Suggestions IA:\n\n' + msg.join('\n') + '\n\nAppliquer ces suggestions ?');
            
            if (apply) {
                // Appliquer les suggestions aux champs du formulaire
                if (data.suggestions.title) {
                    document.querySelector('input[name="title"]').value = data.suggestions.title;
                }
                if (data.suggestions.correspondent_id) {
                    document.querySelector('select[name="correspondent_id"]').value = data.suggestions.correspondent_id;
                }
                if (data.suggestions.document_type_id) {
                    document.querySelector('select[name="document_type_id"]').value = data.suggestions.document_type_id;
                }
                if (data.suggestions.date) {
                    document.querySelector('input[name="document_date"]').value = data.suggestions.date;
                }
            }
        } else {
            alert('Aucune suggestion disponible. Vérifiez que la clé API Claude est configurée.');
        }
    } catch (error) {
        console.error('Erreur suggestions IA:', error);
        alert('Erreur lors de la récupération des suggestions: ' + error.message);
    } finally {
        btn.disabled = false;
        btn.textContent = originalText;
    }
}
```

### Correction 2 : Endpoint API /api/documents/{id}/ai-classify

**Créer dans** `app/Controllers/Api/DocumentsApiController.php` :

```php
/**
 * POST /api/documents/{id}/ai-classify
 * Classification IA d'un document
 */
public function aiClassify(Request $request, Response $response, array $args): Response
{
    $id = (int)($args['id'] ?? 0);
    
    if ($id <= 0) {
        return $this->jsonResponse($response, ['error' => 'ID invalide'], 400);
    }
    
    try {
        $classificationService = new \KDocs\Services\ClassificationService();
        $suggestions = $classificationService->classifyDocument($id);
        
        return $this->jsonResponse($response, [
            'success' => true,
            'suggestions' => $suggestions
        ]);
    } catch (\Exception $e) {
        error_log("AI Classify error: " . $e->getMessage());
        return $this->jsonResponse($response, ['error' => $e->getMessage()], 500);
    }
}
```

### Correction 3 : Route API

**Ajouter dans** `routes.php` :
```php
$app->post('/api/documents/{id}/ai-classify', [DocumentsApiController::class, 'aiClassify']);
```

### Correction 4 : Améliorer AISearchService pour fallback robuste

**Modifier** `app/Services/AISearchService.php` méthode `executeSearch()` :

Le problème : la recherche simple avec `LIKE` ne fonctionne probablement pas parce que :
1. Le contenu n'est peut-être pas indexé (colonne `content` vide)
2. Ou la recherche sur "divorce" ne matche pas le titre "Courrier au Tribunal civil"

**Solution** : Améliorer la recherche pour être plus tolérante :

```php
private function executeSearch(array $filters): array
{
    $conditions = ["d.deleted_at IS NULL"];
    $params = [];
    
    // Recherche full-text améliorée
    if (!empty($filters['text_search'])) {
        $searchTerms = preg_split('/\s+/', trim($filters['text_search']));
        $searchConditions = [];
        
        foreach ($searchTerms as $term) {
            $search = '%' . $term . '%';
            $searchConditions[] = "(d.title LIKE ? OR d.content LIKE ? OR d.original_filename LIKE ?)";
            $params = array_merge($params, [$search, $search, $search]);
        }
        
        // Match ANY term (OR) instead of ALL (AND)
        if (!empty($searchConditions)) {
            $conditions[] = "(" . implode(" OR ", $searchConditions) . ")";
        }
    }
    
    // ... reste du code
}
```

### Correction 5 : Configuration Clé API

**Option A** : Fichier
Créer `C:\wamp64\www\kdocs\claude_api_key.txt` avec la clé API.

**Option B** : Config directe
Modifier `config/config.php` :
```php
'claude' => [
    'api_key' => 'sk-ant-api03-...',  // Votre clé API
    'model' => 'claude-sonnet-4-20250514',
],
```

**Option C** : Variable d'environnement
Dans WAMP ou `.htaccess` :
```
SetEnv ANTHROPIC_API_KEY sk-ant-api03-...
```

---

## 🧪 TESTS À EFFECTUER

### Test 1 : Vérifier les données en base

```sql
-- Vérifier si le document existe
SELECT id, title, original_filename, LEFT(content, 200) as content_preview 
FROM documents 
WHERE title LIKE '%Tribunal%' OR title LIKE '%civil%';

-- Vérifier le contenu OCR
SELECT id, title, LENGTH(content) as content_length 
FROM documents 
WHERE content IS NOT NULL AND LENGTH(content) > 0;

-- Recherche manuelle sur "divorce"
SELECT id, title 
FROM documents 
WHERE title LIKE '%divorce%' OR content LIKE '%divorce%';
```

### Test 2 : Vérifier l'API de recherche

```bash
# Depuis le navigateur ou curl
curl -X POST http://localhost/kdocs/api/search/ask \
  -H "Content-Type: application/json" \
  -d '{"question": "document divorce"}'
```

### Test 3 : Vérifier que Claude fonctionne

```php
// Script de test : test_claude.php
<?php
require_once __DIR__ . '/vendor/autoload.php';

$claude = new \KDocs\Services\ClaudeService();

echo "Claude configuré: " . ($claude->isConfigured() ? "OUI" : "NON") . "\n";

if ($claude->isConfigured()) {
    $response = $claude->sendMessage("Dis bonjour");
    echo "Réponse: " . ($response ? $claude->extractText($response) : "ERREUR");
}
```

---

## 📋 ORDRE D'EXÉCUTION

1. **Vérifier/configurer la clé API Claude**
2. **Vérifier les données en base** (documents avec contenu OCR)
3. **Corriger getAISuggestions()** dans show.php
4. **Ajouter l'endpoint API ai-classify**
5. **Améliorer le fallback de recherche** si Claude non disponible
6. **Tester la recherche IA**
7. **Tester les suggestions IA sur document**

---

## 🎯 INSTRUCTIONS CURSOR

```
Lis docs/CURSOR_IA_BUGS_FIX.md et corrige les bugs IA :

1. D'abord, vérifie si la clé API Claude est configurée dans config/config.php
2. Remplace la fonction getAISuggestions() dans templates/documents/show.php
3. Ajoute l'endpoint /api/documents/{id}/ai-classify
4. Améliore AISearchService pour un meilleur fallback sans Claude
5. Teste que la recherche "document divorce" trouve des résultats
```
