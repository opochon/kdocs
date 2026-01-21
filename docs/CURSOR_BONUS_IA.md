# K-Docs v1 - BONUS : Classification IA avec Claude

## 🎯 OBJECTIF

Ajouter la classification intelligente par IA, inspirée de FILESORDNER.
**C'est un BONUS au-delà de Paperless-ngx** qui n'a pas cette fonctionnalité.

---

## 📋 ÉTAPE 6 : Classification IA (Bonus)

### 6.1 Créer le Service Claude

**Créer** `app/Services/ClaudeService.php` :
```php
<?php
namespace KDocs\Services;

use KDocs\Core\Config;

class ClaudeService
{
    private string $apiKey;
    private string $model = 'claude-sonnet-4-20250514';
    private string $apiUrl = 'https://api.anthropic.com/v1/messages';
    
    public function __construct()
    {
        $config = Config::load();
        $this->apiKey = $config['claude']['api_key'] ?? '';
        $this->model = $config['claude']['model'] ?? $this->model;
    }
    
    public function isConfigured(): bool
    {
        return !empty($this->apiKey);
    }
    
    /**
     * Envoyer une requête à Claude
     */
    public function sendMessage(string $prompt, ?string $systemPrompt = null): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }
        
        $messages = [
            ['role' => 'user', 'content' => $prompt]
        ];
        
        $body = [
            'model' => $this->model,
            'max_tokens' => 2048,
            'messages' => $messages
        ];
        
        if ($systemPrompt) {
            $body['system'] = $systemPrompt;
        }
        
        $ch = curl_init($this->apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01'
            ],
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_TIMEOUT => 60
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            error_log("Claude API error: HTTP $httpCode - $response");
            return null;
        }
        
        return json_decode($response, true);
    }
    
    /**
     * Extraire le texte de la réponse
     */
    public function extractText(array $response): string
    {
        if (isset($response['content'][0]['text'])) {
            return $response['content'][0]['text'];
        }
        return '';
    }
}
```

### 6.2 Créer le Service de Classification IA

**Créer** `app/Services/AIClassifierService.php` :
```php
<?php
namespace KDocs\Services;

use KDocs\Core\Database;

class AIClassifierService
{
    private ClaudeService $claude;
    
    public function __construct()
    {
        $this->claude = new ClaudeService();
    }
    
    public function isAvailable(): bool
    {
        return $this->claude->isConfigured();
    }
    
    /**
     * Classifier un document avec l'IA
     */
    public function classify(int $documentId): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }
        
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM documents WHERE id = ?");
        $stmt->execute([$documentId]);
        $document = $stmt->fetch();
        
        if (!$document || empty($document['content'])) {
            return null;
        }
        
        // Récupérer les entités existantes pour le contexte
        $tags = $db->query("SELECT id, name FROM tags")->fetchAll();
        $correspondents = $db->query("SELECT id, name FROM correspondents")->fetchAll();
        $types = $db->query("SELECT id, name FROM document_types")->fetchAll();
        
        $tagList = implode(', ', array_column($tags, 'name'));
        $corrList = implode(', ', array_column($correspondents, 'name'));
        $typeList = implode(', ', array_column($types, 'name'));
        
        // Construire le prompt
        $systemPrompt = <<<PROMPT
Tu es un assistant spécialisé dans la classification de documents.
Tu dois analyser le contenu d'un document et suggérer :
- Un correspondant (expéditeur/émetteur du document)
- Un type de document
- Des tags pertinents
- La date du document (si visible)
- Un montant (si c'est une facture)

Réponds UNIQUEMENT en JSON valide avec cette structure :
{
    "correspondent": "nom suggéré ou null",
    "document_type": "type suggéré ou null", 
    "tags": ["tag1", "tag2"],
    "document_date": "YYYY-MM-DD ou null",
    "amount": 123.45 ou null,
    "title_suggestion": "titre suggéré",
    "confidence": 0.0 à 1.0
}
PROMPT;

        $prompt = <<<PROMPT
Analyse ce document et classifie-le.

CORRESPONDANTS EXISTANTS : $corrList
TYPES EXISTANTS : $typeList  
TAGS EXISTANTS : $tagList

CONTENU DU DOCUMENT :
{$document['content']}

Réponds uniquement en JSON.
PROMPT;

        $response = $this->claude->sendMessage($prompt, $systemPrompt);
        if (!$response) {
            return null;
        }
        
        $text = $this->claude->extractText($response);
        
        // Parser le JSON de la réponse
        // Nettoyer le texte (enlever ```json si présent)
        $text = preg_replace('/^```json\s*/', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);
        
        $result = json_decode($text, true);
        if (!$result) {
            return null;
        }
        
        // Matcher avec les entités existantes
        $result['matched'] = $this->matchWithExisting($result, $tags, $correspondents, $types);
        
        return $result;
    }
    
    /**
     * Matcher les suggestions avec les entités existantes
     */
    private function matchWithExisting(array $result, array $tags, array $correspondents, array $types): array
    {
        $matched = [
            'correspondent_id' => null,
            'document_type_id' => null,
            'tag_ids' => []
        ];
        
        // Matcher correspondent
        if (!empty($result['correspondent'])) {
            foreach ($correspondents as $corr) {
                if (stripos($corr['name'], $result['correspondent']) !== false ||
                    stripos($result['correspondent'], $corr['name']) !== false) {
                    $matched['correspondent_id'] = $corr['id'];
                    break;
                }
            }
        }
        
        // Matcher type
        if (!empty($result['document_type'])) {
            foreach ($types as $type) {
                if (stripos($type['name'], $result['document_type']) !== false ||
                    stripos($result['document_type'], $type['name']) !== false) {
                    $matched['document_type_id'] = $type['id'];
                    break;
                }
            }
        }
        
        // Matcher tags
        if (!empty($result['tags'])) {
            foreach ($result['tags'] as $suggestedTag) {
                foreach ($tags as $tag) {
                    if (stripos($tag['name'], $suggestedTag) !== false ||
                        stripos($suggestedTag, $tag['name']) !== false) {
                        $matched['tag_ids'][] = $tag['id'];
                        break;
                    }
                }
            }
        }
        
        return $matched;
    }
    
    /**
     * Appliquer les suggestions de l'IA à un document
     */
    public function applySuggestions(int $documentId, array $suggestions): bool
    {
        $db = Database::getInstance();
        
        $updates = [];
        $params = [];
        
        if (!empty($suggestions['matched']['correspondent_id'])) {
            $updates[] = "correspondent_id = ?";
            $params[] = $suggestions['matched']['correspondent_id'];
        }
        
        if (!empty($suggestions['matched']['document_type_id'])) {
            $updates[] = "document_type_id = ?";
            $params[] = $suggestions['matched']['document_type_id'];
        }
        
        if (!empty($suggestions['document_date'])) {
            $updates[] = "document_date = ?";
            $params[] = $suggestions['document_date'];
        }
        
        if (!empty($suggestions['amount'])) {
            $updates[] = "amount = ?";
            $params[] = $suggestions['amount'];
        }
        
        if (!empty($suggestions['title_suggestion'])) {
            $updates[] = "title = ?";
            $params[] = $suggestions['title_suggestion'];
        }
        
        if (!empty($updates)) {
            $params[] = $documentId;
            $sql = "UPDATE documents SET " . implode(', ', $updates) . " WHERE id = ?";
            $db->prepare($sql)->execute($params);
        }
        
        // Ajouter les tags
        if (!empty($suggestions['matched']['tag_ids'])) {
            foreach ($suggestions['matched']['tag_ids'] as $tagId) {
                $db->prepare("INSERT IGNORE INTO document_tags (document_id, tag_id) VALUES (?, ?)")
                   ->execute([$documentId, $tagId]);
            }
        }
        
        return true;
    }
}
```

### 6.3 Ajouter la Configuration Claude

**Modifier** `config/config.php` :
```php
// Ajouter dans le tableau de config
'claude' => [
    'api_key' => $_ENV['ANTHROPIC_API_KEY'] ?? '',
    'model' => 'claude-sonnet-4-20250514'
],
```

### 6.4 Ajouter l'Interface de Classification

**Modifier** `templates/documents/show.php` - Ajouter un bouton :
```php
<?php if ($aiClassifier->isAvailable()): ?>
<button onclick="classifyWithAI(<?= $document['id'] ?>)" class="btn btn-purple">
    <i class="fas fa-magic mr-1"></i> Classifier avec IA
</button>
<?php endif; ?>

<script>
async function classifyWithAI(docId) {
    const btn = event.target;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Classification...';
    
    try {
        const response = await fetch(`/api/documents/${docId}/classify-ai`, {
            method: 'POST'
        });
        const data = await response.json();
        
        if (data.success) {
            // Afficher les suggestions dans une modal
            showSuggestionsModal(data.suggestions, docId);
        } else {
            alert('Erreur: ' + data.error);
        }
    } catch (e) {
        alert('Erreur de classification');
    }
    
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-magic mr-1"></i> Classifier avec IA';
}

function showSuggestionsModal(suggestions, docId) {
    // Créer et afficher une modal avec les suggestions
    const modal = document.createElement('div');
    modal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50';
    modal.innerHTML = `
        <div class="bg-white rounded-lg p-6 max-w-lg w-full mx-4">
            <h3 class="text-lg font-bold mb-4">Suggestions IA</h3>
            <div class="space-y-2">
                <p><strong>Titre:</strong> ${suggestions.title_suggestion || '-'}</p>
                <p><strong>Correspondant:</strong> ${suggestions.correspondent || '-'}</p>
                <p><strong>Type:</strong> ${suggestions.document_type || '-'}</p>
                <p><strong>Tags:</strong> ${suggestions.tags?.join(', ') || '-'}</p>
                <p><strong>Date:</strong> ${suggestions.document_date || '-'}</p>
                <p><strong>Montant:</strong> ${suggestions.amount ? suggestions.amount + ' CHF' : '-'}</p>
                <p><strong>Confiance:</strong> ${Math.round((suggestions.confidence || 0) * 100)}%</p>
            </div>
            <div class="mt-4 flex justify-end gap-2">
                <button onclick="this.closest('.fixed').remove()" class="btn btn-secondary">Annuler</button>
                <button onclick="applySuggestions(${docId})" class="btn btn-primary">Appliquer</button>
            </div>
        </div>
    `;
    document.body.appendChild(modal);
}

async function applySuggestions(docId) {
    const response = await fetch(`/api/documents/${docId}/apply-ai-suggestions`, {
        method: 'POST'
    });
    if (response.ok) {
        location.reload();
    }
}
</script>
```

### 6.5 Ajouter les Routes API

**Ajouter dans** `app/Controllers/Api/DocumentsApiController.php` :
```php
public function classifyWithAI(int $id): void
{
    $classifier = new \KDocs\Services\AIClassifierService();
    
    if (!$classifier->isAvailable()) {
        $this->json(['success' => false, 'error' => 'Claude API non configurée']);
        return;
    }
    
    $suggestions = $classifier->classify($id);
    
    if (!$suggestions) {
        $this->json(['success' => false, 'error' => 'Impossible de classifier']);
        return;
    }
    
    // Stocker temporairement les suggestions en session
    $_SESSION['ai_suggestions_' . $id] = $suggestions;
    
    $this->json(['success' => true, 'suggestions' => $suggestions]);
}

public function applyAISuggestions(int $id): void
{
    $suggestions = $_SESSION['ai_suggestions_' . $id] ?? null;
    
    if (!$suggestions) {
        $this->json(['success' => false, 'error' => 'Pas de suggestions']);
        return;
    }
    
    $classifier = new \KDocs\Services\AIClassifierService();
    $classifier->applySuggestions($id, $suggestions);
    
    unset($_SESSION['ai_suggestions_' . $id]);
    
    $this->json(['success' => true]);
}
```

**Ajouter les routes** dans `index.php` :
```php
$app->post('/api/documents/{id}/classify-ai', [DocumentsApiController::class, 'classifyWithAI']);
$app->post('/api/documents/{id}/apply-ai-suggestions', [DocumentsApiController::class, 'applyAISuggestions']);
```

---

## ✅ CRITÈRES DE SUCCÈS

- [ ] ClaudeService se connecte à l'API
- [ ] Classification retourne des suggestions JSON
- [ ] Suggestions matchées avec entités existantes
- [ ] Bouton "Classifier avec IA" sur page document
- [ ] Application des suggestions fonctionne

---

## ⚙️ CONFIGURATION

Ajouter dans `.env` ou `config/config.php` :
```
ANTHROPIC_API_KEY=sk-ant-api...
```

---

## 🎯 INSTRUCTIONS CURSOR

```
ÉTAPE 6 (BONUS) : Ajouter la classification IA avec Claude.

1. Créer app/Services/ClaudeService.php
2. Créer app/Services/AIClassifierService.php  
3. Ajouter config claude dans config.php
4. Ajouter bouton + modal dans templates/documents/show.php
5. Ajouter routes API classify-ai et apply-ai-suggestions

Référence : F:\DATA\DEVELOPPEMENT\FILESORDNER\classify_pdfs.py
```
