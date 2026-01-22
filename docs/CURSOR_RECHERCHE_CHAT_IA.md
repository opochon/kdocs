# K-Docs v1 - Recherche Contextuelle & Chat IA

## 🎯 OBJECTIF

Ajouter une interface de recherche intelligente avec :
1. **Barre de recherche** en haut de page (recherche rapide)
2. **Zone Chat IA** pour poser des questions en langage naturel
3. **Auto-classification améliorée** style FILESORDNER

---

## 📋 FONCTIONNALITÉS À IMPLÉMENTER

### 1. Barre de Recherche Globale (Header)

Une barre de recherche toujours visible en haut, qui :
- Recherche instantanée (debounce 300ms)
- Supporte les opérateurs existants (tag:, from:, type:, date:, etc.)
- Affiche des résultats en dropdown
- Raccourci clavier Ctrl+K ou /

### 2. Zone Chat IA

Un panneau chat-like où l'utilisateur peut :
- Poser des questions en langage naturel
- Exemples :
  - "Dans quel document j'ai la référence Gabcx ?"
  - "Résume le document facture Swisscom janvier"
  - "Combien j'ai payé à Viteos en 2024 ?"
  - "Trouve tous les rappels non traités"
  - "Quel est le total de mes factures ce mois ?"

### 3. Auto-Classification Améliorée

Améliorer AIClassifierService pour :
- Détecter automatiquement : catégorie, expéditeur, destinataire, date, montant
- Créer automatiquement les correspondents/tags manquants
- Supporter les règles métier (Visana = assurance, pas santé)

---

## 📁 FICHIERS À CRÉER/MODIFIER

### Service de Recherche IA

**Créer** `app/Services/AISearchService.php` :
```php
<?php
namespace KDocs\Services;

use KDocs\Core\Database;

class AISearchService
{
    private ClaudeService $claude;
    private $db;
    
    public function __construct()
    {
        $this->claude = new ClaudeService();
        $this->db = Database::getInstance();
    }
    
    /**
     * Traite une question en langage naturel
     * Retourne une réponse + les documents trouvés
     */
    public function askQuestion(string $question): array
    {
        // 1. Convertir la question en filtres de recherche
        $filters = $this->questionToFilters($question);
        
        // 2. Exécuter la recherche
        $documents = $this->executeSearch($filters);
        
        // 3. Générer une réponse en langage naturel
        $answer = $this->generateAnswer($question, $documents, $filters);
        
        return [
            'answer' => $answer,
            'documents' => $documents,
            'filters_used' => $filters,
            'count' => count($documents)
        ];
    }
    
    /**
     * Convertit une question en filtres SQL via Claude
     */
    private function questionToFilters(string $question): array
    {
        $systemPrompt = <<<PROMPT
Tu es un assistant qui convertit des questions en langage naturel en filtres de recherche JSON.

Base de données disponible avec ces champs :
- title : titre du document
- content : contenu OCR du document
- correspondent_id : ID du correspondant (expéditeur)
- document_type_id : ID du type de document
- document_date : date du document (YYYY-MM-DD)
- amount : montant (décimal)
- tags : liste de tags associés
- archive_serial_number : numéro ASN

Correspondants existants dans la base (utilise l'ID correspondant) :
{CORRESPONDENTS_LIST}

Types de documents existants :
{TYPES_LIST}

Tags existants :
{TAGS_LIST}

Filtres disponibles (retourne un JSON avec ces clés) :
- text_search : recherche full-text dans titre et contenu
- correspondent_name : nom partiel du correspondant (LIKE)
- type_name : nom partiel du type (LIKE)
- tag_names : liste de noms de tags
- date_from : date minimale (YYYY-MM-DD)
- date_to : date maximale (YYYY-MM-DD)
- amount_min : montant minimum
- amount_max : montant maximum
- sort_by : "date", "amount", "title"
- sort_dir : "asc" ou "desc"
- limit : nombre max de résultats
- aggregation : "count", "sum_amount", "avg_amount" (pour stats)

Question : {QUESTION}

Réponds UNIQUEMENT avec un JSON valide.
Exemple : {"text_search": "facture", "correspondent_name": "swisscom", "sort_by": "date", "sort_dir": "desc", "limit": 10}
PROMPT;

        // Récupérer les entités pour le contexte
        $correspondents = $this->db->query("SELECT id, name FROM correspondents")->fetchAll();
        $types = $this->db->query("SELECT id, name FROM document_types")->fetchAll();
        $tags = $this->db->query("SELECT id, name FROM tags")->fetchAll();
        
        $corrList = implode(", ", array_map(fn($c) => "{$c['name']} (ID:{$c['id']})", $correspondents));
        $typeList = implode(", ", array_map(fn($t) => "{$t['name']} (ID:{$t['id']})", $types));
        $tagList = implode(", ", array_column($tags, 'name'));
        
        $prompt = str_replace(
            ['{CORRESPONDENTS_LIST}', '{TYPES_LIST}', '{TAGS_LIST}', '{QUESTION}'],
            [$corrList, $typeList, $tagList, $question],
            $systemPrompt
        );
        
        $response = $this->claude->sendMessage($prompt);
        if (!$response) {
            return ['text_search' => $question]; // Fallback
        }
        
        $text = $this->claude->extractText($response);
        
        // Parser le JSON
        $text = preg_replace('/^```json\s*/', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);
        
        $filters = json_decode($text, true);
        return $filters ?: ['text_search' => $question];
    }
    
    /**
     * Exécute la recherche avec les filtres
     */
    private function executeSearch(array $filters): array
    {
        $conditions = ["d.deleted_at IS NULL"];
        $params = [];
        
        // Recherche full-text
        if (!empty($filters['text_search'])) {
            $search = '%' . $filters['text_search'] . '%';
            $conditions[] = "(d.title LIKE ? OR d.content LIKE ? OR d.original_filename LIKE ?)";
            $params = array_merge($params, [$search, $search, $search]);
        }
        
        // Correspondent
        if (!empty($filters['correspondent_name'])) {
            $conditions[] = "EXISTS (SELECT 1 FROM correspondents c WHERE c.id = d.correspondent_id AND c.name LIKE ?)";
            $params[] = '%' . $filters['correspondent_name'] . '%';
        }
        
        // Type
        if (!empty($filters['type_name'])) {
            $conditions[] = "EXISTS (SELECT 1 FROM document_types dt WHERE dt.id = d.document_type_id AND dt.name LIKE ?)";
            $params[] = '%' . $filters['type_name'] . '%';
        }
        
        // Tags
        if (!empty($filters['tag_names']) && is_array($filters['tag_names'])) {
            foreach ($filters['tag_names'] as $tagName) {
                $conditions[] = "EXISTS (SELECT 1 FROM document_tags dtg JOIN tags t ON dtg.tag_id = t.id WHERE dtg.document_id = d.id AND t.name LIKE ?)";
                $params[] = '%' . $tagName . '%';
            }
        }
        
        // Dates
        if (!empty($filters['date_from'])) {
            $conditions[] = "d.document_date >= ?";
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $conditions[] = "d.document_date <= ?";
            $params[] = $filters['date_to'];
        }
        
        // Montants
        if (!empty($filters['amount_min'])) {
            $conditions[] = "d.amount >= ?";
            $params[] = (float)$filters['amount_min'];
        }
        if (!empty($filters['amount_max'])) {
            $conditions[] = "d.amount <= ?";
            $params[] = (float)$filters['amount_max'];
        }
        
        // Construire la requête
        $where = implode(' AND ', $conditions);
        
        // Tri
        $sortBy = $filters['sort_by'] ?? 'document_date';
        $sortDir = strtoupper($filters['sort_dir'] ?? 'DESC');
        if (!in_array($sortDir, ['ASC', 'DESC'])) $sortDir = 'DESC';
        $sortColumn = match($sortBy) {
            'amount' => 'd.amount',
            'title' => 'd.title',
            default => 'd.document_date'
        };
        
        // Limite
        $limit = min((int)($filters['limit'] ?? 20), 100);
        
        $sql = "
            SELECT d.*, 
                   c.name as correspondent_name,
                   dt.name as document_type_name
            FROM documents d
            LEFT JOIN correspondents c ON d.correspondent_id = c.id
            LEFT JOIN document_types dt ON d.document_type_id = dt.id
            WHERE $where
            ORDER BY $sortColumn $sortDir
            LIMIT $limit
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $documents = $stmt->fetchAll();
        
        // Ajouter les tags à chaque document
        foreach ($documents as &$doc) {
            $tagStmt = $this->db->prepare("
                SELECT t.name FROM tags t
                JOIN document_tags dt ON t.id = dt.tag_id
                WHERE dt.document_id = ?
            ");
            $tagStmt->execute([$doc['id']]);
            $doc['tags'] = array_column($tagStmt->fetchAll(), 'name');
        }
        
        return $documents;
    }
    
    /**
     * Génère une réponse en langage naturel
     */
    private function generateAnswer(string $question, array $documents, array $filters): string
    {
        if (empty($documents)) {
            return "Je n'ai trouvé aucun document correspondant à votre recherche.";
        }
        
        // Calculer des statistiques si demandé
        $stats = [];
        if (!empty($filters['aggregation'])) {
            switch ($filters['aggregation']) {
                case 'sum_amount':
                    $total = array_sum(array_column($documents, 'amount'));
                    $stats['total'] = number_format($total, 2, '.', ' ') . ' CHF';
                    break;
                case 'avg_amount':
                    $amounts = array_filter(array_column($documents, 'amount'));
                    $avg = count($amounts) > 0 ? array_sum($amounts) / count($amounts) : 0;
                    $stats['average'] = number_format($avg, 2, '.', ' ') . ' CHF';
                    break;
            }
        }
        
        // Construire le contexte pour Claude
        $context = "Documents trouvés (" . count($documents) . ") :\n\n";
        foreach (array_slice($documents, 0, 10) as $i => $doc) {
            $context .= ($i + 1) . ". " . ($doc['title'] ?? $doc['original_filename']) . "\n";
            $context .= "   - Correspondant: " . ($doc['correspondent_name'] ?? 'N/A') . "\n";
            $context .= "   - Date: " . ($doc['document_date'] ?? 'N/A') . "\n";
            $context .= "   - Montant: " . ($doc['amount'] ? number_format($doc['amount'], 2) . ' CHF' : 'N/A') . "\n";
            if (!empty($doc['tags'])) {
                $context .= "   - Tags: " . implode(', ', $doc['tags']) . "\n";
            }
            $context .= "\n";
        }
        
        if (!empty($stats)) {
            $context .= "Statistiques:\n";
            foreach ($stats as $key => $value) {
                $context .= "- $key: $value\n";
            }
        }
        
        $prompt = <<<PROMPT
Question de l'utilisateur: $question

$context

Génère une réponse concise et utile en français qui:
1. Répond directement à la question
2. Cite les documents pertinents
3. Donne des statistiques si demandé (totaux, moyennes)
4. Reste factuel et précis

Réponds directement, sans formatage markdown excessif.
PROMPT;

        $response = $this->claude->sendMessage($prompt);
        if (!$response) {
            // Fallback simple
            return "J'ai trouvé " . count($documents) . " document(s) correspondant à votre recherche.";
        }
        
        return $this->claude->extractText($response);
    }
    
    /**
     * Recherche rapide (pour la barre de recherche)
     */
    public function quickSearch(string $query, int $limit = 10): array
    {
        $search = '%' . $query . '%';
        
        $sql = "
            SELECT d.id, d.title, d.original_filename, d.document_date, d.amount,
                   c.name as correspondent_name
            FROM documents d
            LEFT JOIN correspondents c ON d.correspondent_id = c.id
            WHERE d.deleted_at IS NULL
              AND (d.title LIKE ? OR d.content LIKE ? OR d.original_filename LIKE ?
                   OR c.name LIKE ?)
            ORDER BY d.document_date DESC
            LIMIT ?
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$search, $search, $search, $search, $limit]);
        
        return $stmt->fetchAll();
    }
    
    /**
     * Trouve un document contenant une référence spécifique
     */
    public function findReference(string $reference): array
    {
        $search = '%' . $reference . '%';
        
        $sql = "
            SELECT d.*, c.name as correspondent_name, dt.name as document_type_name
            FROM documents d
            LEFT JOIN correspondents c ON d.correspondent_id = c.id
            LEFT JOIN document_types dt ON d.document_type_id = dt.id
            WHERE d.deleted_at IS NULL
              AND d.content LIKE ?
            ORDER BY d.document_date DESC
            LIMIT 20
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$search]);
        
        return $stmt->fetchAll();
    }
    
    /**
     * Résume un document
     */
    public function summarizeDocument(int $documentId): ?string
    {
        $stmt = $this->db->prepare("SELECT * FROM documents WHERE id = ?");
        $stmt->execute([$documentId]);
        $doc = $stmt->fetch();
        
        if (!$doc || empty($doc['content'])) {
            return null;
        }
        
        $content = substr($doc['content'], 0, 10000); // Limiter
        
        $prompt = <<<PROMPT
Résume ce document de manière concise (3-5 phrases) :

Titre: {$doc['title']}
Type: Document administratif
Contenu:
$content

Résumé:
PROMPT;

        $response = $this->claude->sendMessage($prompt);
        if (!$response) {
            return null;
        }
        
        return $this->claude->extractText($response);
    }
}
```

### Controller API Recherche

**Créer** `app/Controllers/Api/SearchApiController.php` :
```php
<?php
namespace KDocs\Controllers\Api;

use KDocs\Services\AISearchService;

class SearchApiController extends ApiController
{
    private AISearchService $searchService;
    
    public function __construct()
    {
        parent::__construct();
        $this->searchService = new AISearchService();
    }
    
    /**
     * POST /api/search/ask
     * Question en langage naturel
     */
    public function ask(): void
    {
        $data = json_decode(file_get_contents('php://input'), true);
        $question = $data['question'] ?? '';
        
        if (empty($question)) {
            $this->json(['error' => 'Question requise'], 400);
            return;
        }
        
        try {
            $result = $this->searchService->askQuestion($question);
            $this->json($result);
        } catch (\Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }
    
    /**
     * GET /api/search/quick?q=xxx
     * Recherche rapide (dropdown)
     */
    public function quick(): void
    {
        $query = $_GET['q'] ?? '';
        
        if (strlen($query) < 2) {
            $this->json(['results' => []]);
            return;
        }
        
        $results = $this->searchService->quickSearch($query);
        $this->json(['results' => $results]);
    }
    
    /**
     * GET /api/search/reference?ref=xxx
     * Trouver un document par référence
     */
    public function reference(): void
    {
        $ref = $_GET['ref'] ?? '';
        
        if (empty($ref)) {
            $this->json(['error' => 'Référence requise'], 400);
            return;
        }
        
        $documents = $this->searchService->findReference($ref);
        $this->json([
            'reference' => $ref,
            'count' => count($documents),
            'documents' => $documents
        ]);
    }
    
    /**
     * GET /api/documents/{id}/summary
     * Résumé d'un document
     */
    public function summary(int $id): void
    {
        $summary = $this->searchService->summarizeDocument($id);
        
        if (!$summary) {
            $this->json(['error' => 'Impossible de résumer ce document'], 400);
            return;
        }
        
        $this->json(['summary' => $summary]);
    }
}
```

### Template Interface Chat

**Créer** `templates/partials/search_chat.php` :
```php
<!-- Barre de recherche globale (header) -->
<div id="global-search" class="relative">
    <div class="flex items-center bg-gray-100 rounded-lg px-3 py-2">
        <i class="fas fa-search text-gray-400 mr-2"></i>
        <input 
            type="text" 
            id="search-input"
            class="bg-transparent border-none focus:outline-none w-64"
            placeholder="Rechercher... (Ctrl+K)"
            autocomplete="off"
        >
        <kbd class="hidden sm:inline-block ml-2 px-2 py-1 text-xs bg-gray-200 rounded">Ctrl+K</kbd>
    </div>
    
    <!-- Dropdown résultats -->
    <div id="search-dropdown" class="hidden absolute top-full left-0 right-0 mt-2 bg-white rounded-lg shadow-xl border max-h-96 overflow-y-auto z-50">
        <div id="search-results"></div>
    </div>
</div>

<!-- Panneau Chat IA (sidebar ou modal) -->
<div id="ai-chat-panel" class="hidden fixed right-0 top-0 h-full w-96 bg-white shadow-2xl z-40 flex flex-col">
    <div class="p-4 bg-purple-600 text-white flex justify-between items-center">
        <h3 class="font-semibold"><i class="fas fa-robot mr-2"></i>Assistant K-Docs</h3>
        <button onclick="toggleChatPanel()" class="text-white hover:text-gray-200">
            <i class="fas fa-times"></i>
        </button>
    </div>
    
    <div id="chat-messages" class="flex-1 overflow-y-auto p-4 space-y-4">
        <div class="text-center text-gray-500 py-8">
            <i class="fas fa-comments text-4xl mb-2"></i>
            <p>Posez une question sur vos documents</p>
            <p class="text-sm mt-2">Exemples :</p>
            <div class="mt-2 space-y-1 text-sm">
                <button class="example-question text-purple-600 hover:underline block">"Où est la référence ABC123 ?"</button>
                <button class="example-question text-purple-600 hover:underline block">"Total factures Swisscom 2024"</button>
                <button class="example-question text-purple-600 hover:underline block">"Résume le dernier document"</button>
            </div>
        </div>
    </div>
    
    <div class="p-4 border-t">
        <form id="chat-form" class="flex gap-2">
            <input 
                type="text" 
                id="chat-input"
                class="flex-1 border rounded-lg px-3 py-2 focus:ring-2 focus:ring-purple-500"
                placeholder="Posez votre question..."
            >
            <button type="submit" class="bg-purple-600 text-white px-4 py-2 rounded-lg hover:bg-purple-700">
                <i class="fas fa-paper-plane"></i>
            </button>
        </form>
    </div>
</div>

<!-- Bouton flottant pour ouvrir le chat -->
<button 
    id="chat-toggle-btn"
    onclick="toggleChatPanel()"
    class="fixed bottom-6 right-6 bg-purple-600 text-white w-14 h-14 rounded-full shadow-lg hover:bg-purple-700 z-30 flex items-center justify-center"
>
    <i class="fas fa-robot text-xl"></i>
</button>
```

### JavaScript Interface

**Créer** `public/js/ai-search.js` :
```javascript
// === RECHERCHE RAPIDE ===

let searchTimeout = null;
const searchInput = document.getElementById('search-input');
const searchDropdown = document.getElementById('search-dropdown');
const searchResults = document.getElementById('search-results');

if (searchInput) {
    // Recherche avec debounce
    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        const query = this.value.trim();
        
        if (query.length < 2) {
            searchDropdown.classList.add('hidden');
            return;
        }
        
        searchTimeout = setTimeout(() => {
            quickSearch(query);
        }, 300);
    });
    
    // Raccourci Ctrl+K
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
            e.preventDefault();
            searchInput.focus();
        }
        // Echap pour fermer
        if (e.key === 'Escape') {
            searchDropdown.classList.add('hidden');
            searchInput.blur();
        }
    });
    
    // Fermer dropdown si clic ailleurs
    document.addEventListener('click', function(e) {
        if (!e.target.closest('#global-search')) {
            searchDropdown.classList.add('hidden');
        }
    });
}

async function quickSearch(query) {
    try {
        const response = await fetch(`/api/search/quick?q=${encodeURIComponent(query)}`);
        const data = await response.json();
        
        if (data.results && data.results.length > 0) {
            renderSearchResults(data.results);
            searchDropdown.classList.remove('hidden');
        } else {
            searchResults.innerHTML = '<div class="p-4 text-gray-500">Aucun résultat</div>';
            searchDropdown.classList.remove('hidden');
        }
    } catch (error) {
        console.error('Erreur recherche:', error);
    }
}

function renderSearchResults(results) {
    searchResults.innerHTML = results.map(doc => `
        <a href="/documents/${doc.id}" class="block p-3 hover:bg-gray-50 border-b last:border-0">
            <div class="flex justify-between items-start">
                <div>
                    <div class="font-medium text-gray-900">${doc.title || doc.original_filename}</div>
                    <div class="text-sm text-gray-500">
                        ${doc.correspondent_name || ''} 
                        ${doc.document_date ? '• ' + doc.document_date : ''}
                    </div>
                </div>
                ${doc.amount ? `<span class="text-green-600 font-medium">${parseFloat(doc.amount).toFixed(2)} CHF</span>` : ''}
            </div>
        </a>
    `).join('');
}

// === CHAT IA ===

const chatPanel = document.getElementById('ai-chat-panel');
const chatMessages = document.getElementById('chat-messages');
const chatForm = document.getElementById('chat-form');
const chatInput = document.getElementById('chat-input');

function toggleChatPanel() {
    chatPanel.classList.toggle('hidden');
    if (!chatPanel.classList.contains('hidden')) {
        chatInput.focus();
    }
}

// Questions exemples
document.querySelectorAll('.example-question').forEach(btn => {
    btn.addEventListener('click', function() {
        chatInput.value = this.textContent.replace(/"/g, '');
        chatForm.dispatchEvent(new Event('submit'));
    });
});

if (chatForm) {
    chatForm.addEventListener('submit', async function(e) {
        e.preventDefault();
        const question = chatInput.value.trim();
        
        if (!question) return;
        
        // Afficher la question de l'utilisateur
        addChatMessage(question, 'user');
        chatInput.value = '';
        
        // Afficher indicateur de chargement
        const loadingId = addChatMessage('Recherche en cours...', 'assistant', true);
        
        try {
            const response = await fetch('/api/search/ask', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ question })
            });
            
            const data = await response.json();
            
            // Supprimer le message de chargement
            document.getElementById(loadingId)?.remove();
            
            if (data.error) {
                addChatMessage('Erreur: ' + data.error, 'error');
            } else {
                // Afficher la réponse
                addChatMessage(data.answer, 'assistant');
                
                // Afficher les documents trouvés
                if (data.documents && data.documents.length > 0) {
                    addDocumentsList(data.documents);
                }
            }
        } catch (error) {
            document.getElementById(loadingId)?.remove();
            addChatMessage('Erreur de connexion', 'error');
        }
    });
}

function addChatMessage(content, type, isLoading = false) {
    const id = 'msg-' + Date.now();
    const msgDiv = document.createElement('div');
    msgDiv.id = id;
    msgDiv.className = type === 'user' 
        ? 'flex justify-end' 
        : 'flex justify-start';
    
    const bubble = document.createElement('div');
    bubble.className = type === 'user'
        ? 'bg-purple-600 text-white rounded-lg px-4 py-2 max-w-[80%]'
        : type === 'error'
        ? 'bg-red-100 text-red-700 rounded-lg px-4 py-2 max-w-[80%]'
        : 'bg-gray-100 text-gray-800 rounded-lg px-4 py-2 max-w-[80%]';
    
    if (isLoading) {
        bubble.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>' + content;
    } else {
        bubble.textContent = content;
    }
    
    msgDiv.appendChild(bubble);
    chatMessages.appendChild(msgDiv);
    chatMessages.scrollTop = chatMessages.scrollHeight;
    
    return id;
}

function addDocumentsList(documents) {
    const container = document.createElement('div');
    container.className = 'bg-white border rounded-lg p-3 mt-2';
    container.innerHTML = `
        <div class="text-sm font-medium text-gray-700 mb-2">
            <i class="fas fa-file-alt mr-1"></i> ${documents.length} document(s) trouvé(s)
        </div>
        <div class="space-y-2 max-h-48 overflow-y-auto">
            ${documents.slice(0, 5).map(doc => `
                <a href="/documents/${doc.id}" class="block p-2 hover:bg-gray-50 rounded border text-sm">
                    <div class="font-medium">${doc.title || doc.original_filename}</div>
                    <div class="text-gray-500 text-xs">
                        ${doc.correspondent_name || ''} 
                        ${doc.document_date ? '• ' + doc.document_date : ''}
                        ${doc.amount ? '• ' + parseFloat(doc.amount).toFixed(2) + ' CHF' : ''}
                    </div>
                </a>
            `).join('')}
        </div>
        ${documents.length > 5 ? `<div class="text-center text-sm text-purple-600 mt-2">+ ${documents.length - 5} autres documents</div>` : ''}
    `;
    
    chatMessages.appendChild(container);
    chatMessages.scrollTop = chatMessages.scrollHeight;
}
```

### Routes à ajouter

**Modifier** `index.php` pour ajouter :
```php
// Routes API recherche IA
$app->post('/api/search/ask', [SearchApiController::class, 'ask']);
$app->get('/api/search/quick', [SearchApiController::class, 'quick']);
$app->get('/api/search/reference', [SearchApiController::class, 'reference']);
$app->get('/api/documents/{id}/summary', [SearchApiController::class, 'summary']);
```

### Intégration dans le layout

**Modifier** `templates/layouts/main.php` - Dans le header :
```php
<!-- Ajouter après le logo -->
<?php include __DIR__ . '/../partials/search_chat.php'; ?>

<!-- Avant </body> -->
<script src="/js/ai-search.js"></script>
```

---

## 🎯 EXEMPLES DE QUESTIONS SUPPORTÉES

| Question | Action |
|----------|--------|
| "Où est la référence Gabcx ?" | Recherche dans le contenu |
| "Total factures Swisscom 2024" | Filtre + somme montants |
| "Dernière facture énergie" | Filtre type + tri date |
| "Résume le document X" | Appel Claude pour résumé |
| "Combien j'ai payé en janvier ?" | Stats par période |
| "Documents de Viteos non traités" | Filtre correspondant + tags |
| "Factures > 500 CHF ce mois" | Filtre montant + date |

---

## ✅ CRITÈRES DE SUCCÈS

1. [ ] Barre de recherche fonctionne (Ctrl+K, dropdown)
2. [ ] Chat IA répond aux questions
3. [ ] Questions converties en filtres SQL
4. [ ] Réponses en langage naturel
5. [ ] Documents listés avec liens
6. [ ] Stats calculées (totaux, moyennes)
7. [ ] "Où est la référence X" trouve le document

---

## 🚀 INSTRUCTIONS CURSOR

```
Implémente la recherche contextuelle et le chat IA pour K-Docs v1.

1. Créer app/Services/AISearchService.php
2. Créer app/Controllers/Api/SearchApiController.php
3. Créer templates/partials/search_chat.php
4. Créer public/js/ai-search.js
5. Ajouter les routes dans index.php
6. Intégrer dans templates/layouts/main.php

Référence : F:\DATA\DEVELOPPEMENT\FILESORDNER\resources\search_docs.py
Le système doit convertir les questions en filtres SQL via Claude.
```
