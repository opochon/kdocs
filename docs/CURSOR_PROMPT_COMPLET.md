# K-Docs - Prompt Cursor COMPLET pour Corrections

## 🚨 DIAGNOSTIC EFFECTUÉ

### Problèmes Identifiés :
1. **OCR vide** : 8 documents, 0 avec contenu OCR → La recherche ne peut rien trouver
2. **Suggestions IA** : Bouton déclenche `alert()` au lieu de vraie fonction
3. **Bugs HTML** : Code visible dans barre de recherche
4. **Workflows incomplets** : Loin de la parité Paperless-ngx

### Configuration OK :
- Clé API Claude : ✅ Configurée (`sk-ant-api...hpqi`)
- Base de données : ✅ Connectée
- 8 documents présents

---

## 📋 INSTRUCTIONS CURSOR - EXÉCUTER DANS L'ORDRE

### ÉTAPE 1 : Corriger le bug HTML dans la barre de recherche

**Fichier** : `templates/documents/index.php`

Chercher vers ligne 90 ce code malformé :
```php
<input type="text" ... placeholder="Rechercher... (Ctrl+K ou /)" class="...">
                            title="Raccourci: Ctrl+K ou /"
                            onkeydown="if(event.key === 'Enter') this.form.submit()">
```

Les attributs `title` et `onkeydown` sont APRÈS le `>`. Corriger en :
```php
<input type="text" 
       id="search-input"
       name="search"
       value="<?= htmlspecialchars($search ?? '') ?>"
       placeholder="Rechercher... (Ctrl+K ou /)" 
       class="pl-10 pr-4 py-2 border rounded-lg w-64 focus:outline-none focus:ring-2 focus:ring-blue-500"
       title="Raccourci: Ctrl+K ou /"
       onkeydown="if(event.key === 'Enter') this.form.submit()">
```

---

### ÉTAPE 2 : Relancer l'OCR sur tous les documents

**Créer le fichier** `reprocess_all_ocr.php` :

```php
<?php
/**
 * Script pour relancer l'OCR sur tous les documents
 */
require_once __DIR__ . '/vendor/autoload.php';

use KDocs\Core\Database;
use KDocs\Services\OCRService;

set_time_limit(0);
header('Content-Type: text/html; charset=utf-8');

echo "<h1>Retraitement OCR de tous les documents</h1>";
echo "<pre>";

try {
    $db = Database::getInstance();
    $ocr = new OCRService();
    
    // Récupérer tous les documents sans OCR
    $docs = $db->query("
        SELECT id, title, original_filename, file_path, mime_type
        FROM documents 
        WHERE deleted_at IS NULL
        ORDER BY id
    ")->fetchAll();
    
    echo "Documents à traiter : " . count($docs) . "\n\n";
    
    foreach ($docs as $doc) {
        echo "---\n";
        echo "Document #{$doc['id']}: {$doc['original_filename']}\n";
        
        // Construire le chemin complet du fichier
        $basePath = __DIR__ . '/storage/documents';
        $filePath = $basePath . '/' . ($doc['file_path'] ?: $doc['original_filename']);
        
        // Essayer plusieurs chemins possibles
        $possiblePaths = [
            $filePath,
            $basePath . '/' . $doc['original_filename'],
            __DIR__ . '/storage/documents/' . basename($doc['original_filename']),
        ];
        
        $foundPath = null;
        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                $foundPath = $path;
                break;
            }
        }
        
        if (!$foundPath) {
            echo "  ⚠️ Fichier non trouvé. Chemins essayés:\n";
            foreach ($possiblePaths as $path) {
                echo "    - $path\n";
            }
            continue;
        }
        
        echo "  Fichier trouvé: $foundPath\n";
        
        // Déterminer le type MIME si absent
        $mimeType = $doc['mime_type'] ?: mime_content_type($foundPath);
        echo "  Type MIME: $mimeType\n";
        
        // Extraire le texte
        try {
            $text = $ocr->extractText($foundPath, $mimeType);
            
            if ($text && strlen($text) > 0) {
                // Mettre à jour la base
                $stmt = $db->prepare("UPDATE documents SET content = ?, ocr_text = ? WHERE id = ?");
                $stmt->execute([$text, $text, $doc['id']]);
                
                echo "  ✅ OCR réussi: " . strlen($text) . " caractères extraits\n";
                echo "  Aperçu: " . substr($text, 0, 100) . "...\n";
            } else {
                echo "  ⚠️ Aucun texte extrait\n";
            }
        } catch (Exception $e) {
            echo "  ❌ Erreur OCR: " . $e->getMessage() . "\n";
        }
        
        flush();
    }
    
    echo "\n\n=== TERMINÉ ===\n";
    
    // Vérifier le résultat
    $stats = $db->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN content IS NOT NULL AND LENGTH(content) > 0 THEN 1 ELSE 0 END) as with_ocr
        FROM documents WHERE deleted_at IS NULL
    ")->fetch();
    
    echo "Documents avec OCR : {$stats['with_ocr']} / {$stats['total']}\n";
    
} catch (Exception $e) {
    echo "ERREUR FATALE: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}

echo "</pre>";
```

Puis exécuter : `http://localhost/kdocs/reprocess_all_ocr.php`

---

### ÉTAPE 3 : Corriger la fonction getAISuggestions()

**Fichier** : `templates/documents/show.php`

Remplacer la fonction (vers ligne 838) :
```javascript
function getAISuggestions(docId) {
    // TODO: Implémenter suggestions IA
    alert('Suggestions IA à implémenter');
}
```

Par :
```javascript
async function getAISuggestions(docId) {
    const btn = event.target;
    const originalText = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Analyse...';
    
    try {
        const response = await fetch(`<?= url('/api/documents/') ?>${docId}/ai-classify`, {
            method: 'POST',
            headers: { 
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        
        const data = await response.json();
        
        if (data.error) {
            alert('Erreur: ' + data.error);
            return;
        }
        
        if (data.suggestions) {
            const suggestions = data.suggestions;
            const msg = [];
            
            if (suggestions.title) msg.push(`📝 Titre: ${suggestions.title}`);
            if (suggestions.correspondent) msg.push(`👤 Correspondant: ${suggestions.correspondent}`);
            if (suggestions.document_type) msg.push(`📁 Type: ${suggestions.document_type}`);
            if (suggestions.tags && suggestions.tags.length) msg.push(`🏷️ Tags: ${suggestions.tags.join(', ')}`);
            if (suggestions.date) msg.push(`📅 Date: ${suggestions.date}`);
            if (suggestions.amount) msg.push(`💰 Montant: ${suggestions.amount} CHF`);
            
            if (msg.length === 0) {
                alert('Aucune suggestion disponible pour ce document.');
                return;
            }
            
            const apply = confirm('🤖 Suggestions IA :\n\n' + msg.join('\n') + '\n\n─────────────────\nAppliquer ces suggestions ?');
            
            if (apply) {
                // Appliquer les suggestions
                if (suggestions.title) {
                    const titleInput = document.querySelector('input[name="title"]');
                    if (titleInput) titleInput.value = suggestions.title;
                }
                if (suggestions.correspondent_id) {
                    const corrSelect = document.querySelector('select[name="correspondent_id"]');
                    if (corrSelect) corrSelect.value = suggestions.correspondent_id;
                }
                if (suggestions.document_type_id) {
                    const typeSelect = document.querySelector('select[name="document_type_id"]');
                    if (typeSelect) typeSelect.value = suggestions.document_type_id;
                }
                if (suggestions.date) {
                    const dateInput = document.querySelector('input[name="document_date"]');
                    if (dateInput) dateInput.value = suggestions.date;
                }
                
                // Marquer le formulaire comme modifié
                const form = document.getElementById('document-form');
                if (form) form.classList.add('modified');
                
                alert('✅ Suggestions appliquées ! N\'oubliez pas d\'enregistrer.');
            }
        } else {
            alert('Aucune suggestion disponible.\nVérifiez que le document a du contenu OCR.');
        }
    } catch (error) {
        console.error('Erreur suggestions IA:', error);
        alert('Erreur: ' + error.message);
    } finally {
        btn.disabled = false;
        btn.textContent = originalText;
    }
}
```

---

### ÉTAPE 4 : Ajouter l'endpoint API ai-classify

**Fichier** : `app/Controllers/Api/DocumentsApiController.php`

Ajouter cette méthode :
```php
/**
 * POST /api/documents/{id}/ai-classify
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
        
        if (!$suggestions) {
            return $this->jsonResponse($response, [
                'error' => 'Impossible de classifier ce document. Vérifiez le contenu OCR.'
            ], 400);
        }
        
        return $this->jsonResponse($response, [
            'success' => true,
            'document_id' => $id,
            'suggestions' => $suggestions
        ]);
    } catch (\Exception $e) {
        error_log("AI Classify error for doc $id: " . $e->getMessage());
        return $this->jsonResponse($response, ['error' => $e->getMessage()], 500);
    }
}
```

---

### ÉTAPE 5 : Ajouter la route

**Fichier** : `routes.php` ou `index.php` (section routes)

Ajouter :
```php
$app->post('/api/documents/{id}/ai-classify', [DocumentsApiController::class, 'aiClassify']);
```

---

### ÉTAPE 6 : Améliorer AISearchService pour recherche sans OCR

**Fichier** : `app/Services/AISearchService.php`

Dans la méthode `executeSearch()`, améliorer la recherche pour inclure le titre et le nom de fichier même sans contenu :

```php
private function executeSearch(array $filters): array
{
    $conditions = ["d.deleted_at IS NULL"];
    $params = [];
    
    // Recherche full-text améliorée
    if (!empty($filters['text_search'])) {
        $searchTerms = preg_split('/\s+/', trim($filters['text_search']));
        $termConditions = [];
        
        foreach ($searchTerms as $term) {
            if (strlen($term) < 2) continue;
            $search = '%' . $term . '%';
            
            // Rechercher dans titre, fichier, contenu, correspondant, type
            $termConditions[] = "(
                d.title LIKE ? 
                OR d.original_filename LIKE ? 
                OR d.content LIKE ?
                OR EXISTS (SELECT 1 FROM correspondents c WHERE c.id = d.correspondent_id AND c.name LIKE ?)
                OR EXISTS (SELECT 1 FROM document_types dt WHERE dt.id = d.document_type_id AND dt.label LIKE ?)
            )";
            $params = array_merge($params, [$search, $search, $search, $search, $search]);
        }
        
        // Match ANY term (plus permissif)
        if (!empty($termConditions)) {
            $conditions[] = "(" . implode(" OR ", $termConditions) . ")";
        }
    }
    
    // ... reste du code inchangé
```

---

### ÉTAPE 7 : Vérifier/Créer ClassificationService

**Fichier** : `app/Services/ClassificationService.php`

Vérifier qu'il existe et fonctionne. S'il n'existe pas ou est incomplet, le créer :

```php
<?php
namespace KDocs\Services;

use KDocs\Core\Database;

class ClassificationService
{
    private ClaudeService $claude;
    private $db;
    
    public function __construct()
    {
        $this->claude = new ClaudeService();
        $this->db = Database::getInstance();
    }
    
    public function classifyDocument(int $documentId): ?array
    {
        // Récupérer le document
        $stmt = $this->db->prepare("
            SELECT d.*, c.name as correspondent_name, dt.label as type_name
            FROM documents d
            LEFT JOIN correspondents c ON d.correspondent_id = c.id
            LEFT JOIN document_types dt ON d.document_type_id = dt.id
            WHERE d.id = ?
        ");
        $stmt->execute([$documentId]);
        $doc = $stmt->fetch();
        
        if (!$doc) {
            return null;
        }
        
        // Texte à analyser
        $textToAnalyze = $doc['content'] ?? $doc['ocr_text'] ?? '';
        if (empty($textToAnalyze)) {
            // Utiliser au moins le titre et nom de fichier
            $textToAnalyze = ($doc['title'] ?? '') . ' ' . ($doc['original_filename'] ?? '');
        }
        
        if (strlen(trim($textToAnalyze)) < 10) {
            return null;
        }
        
        // Récupérer les entités existantes pour le contexte
        $correspondents = $this->db->query("SELECT id, name FROM correspondents ORDER BY name")->fetchAll();
        $types = $this->db->query("SELECT id, label FROM document_types ORDER BY label")->fetchAll();
        $tags = $this->db->query("SELECT id, name FROM tags ORDER BY name")->fetchAll();
        
        $correspondentsList = implode(", ", array_map(fn($c) => "{$c['name']} (ID:{$c['id']})", $correspondents));
        $typesList = implode(", ", array_map(fn($t) => "{$t['label']} (ID:{$t['id']})", $types));
        $tagsList = implode(", ", array_map(fn($t) => "{$t['name']} (ID:{$t['id']})", $tags));
        
        $prompt = <<<PROMPT
Analyse ce document et suggère des métadonnées appropriées.

CONTENU DU DOCUMENT:
$textToAnalyze

CORRESPONDANTS DISPONIBLES:
$correspondentsList

TYPES DE DOCUMENTS DISPONIBLES:
$typesList

TAGS DISPONIBLES:
$tagsList

Réponds UNIQUEMENT avec un JSON valide contenant les suggestions:
{
    "title": "titre suggéré ou null",
    "correspondent": "nom du correspondant ou null",
    "correspondent_id": ID ou null,
    "document_type": "nom du type ou null",
    "document_type_id": ID ou null,
    "tags": ["tag1", "tag2"] ou [],
    "tag_ids": [1, 2] ou [],
    "date": "YYYY-MM-DD ou null",
    "amount": nombre ou null,
    "confidence": 0.0 à 1.0
}

Si tu ne peux pas déterminer une valeur, mets null.
PROMPT;

        $response = $this->claude->sendMessage($prompt);
        if (!$response) {
            return null;
        }
        
        $text = $this->claude->extractText($response);
        
        // Nettoyer et parser le JSON
        $text = preg_replace('/^```json\s*/i', '', $text);
        $text = preg_replace('/\s*```$/i', '', $text);
        $text = trim($text);
        
        $suggestions = json_decode($text, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("ClassificationService: JSON parse error - " . json_last_error_msg());
            error_log("Response was: " . substr($text, 0, 500));
            return null;
        }
        
        return $suggestions;
    }
}
```

---

## 🧪 TESTS À EFFECTUER APRÈS CORRECTIONS

1. **Vérifier OCR** : `http://localhost/kdocs/diagnostic.php` - Tous les documents doivent avoir du contenu
2. **Tester recherche** : Aller dans "Recherche avancée" et chercher "tribunal" ou "divorce"
3. **Tester suggestions IA** : Ouvrir un document, cliquer "Suggestions IA"
4. **Vérifier barre de recherche** : Plus de code HTML visible

---

## 📝 RÉSUMÉ DES FICHIERS À MODIFIER

| Fichier | Action |
|---------|--------|
| `templates/documents/index.php` | Corriger HTML ligne ~90 |
| `templates/documents/show.php` | Remplacer `getAISuggestions()` |
| `app/Controllers/Api/DocumentsApiController.php` | Ajouter méthode `aiClassify()` |
| `routes.php` ou `index.php` | Ajouter route POST `/api/documents/{id}/ai-classify` |
| `app/Services/AISearchService.php` | Améliorer `executeSearch()` |
| `app/Services/ClassificationService.php` | Vérifier/Créer si manquant |
| `reprocess_all_ocr.php` | Créer et exécuter une fois |

---

## ⚡ COMMANDE CURSOR

```
Exécute ces tâches dans l'ordre :

1. Crée le fichier reprocess_all_ocr.php à la racine avec le code fourni
2. Corrige templates/documents/index.php - le bug HTML ligne ~90
3. Remplace getAISuggestions() dans templates/documents/show.php
4. Ajoute la méthode aiClassify() dans DocumentsApiController.php
5. Ajoute la route dans routes.php ou index.php
6. Améliore executeSearch() dans AISearchService.php
7. Vérifie/crée ClassificationService.php

Après : va sur http://localhost/kdocs/reprocess_all_ocr.php pour relancer l'OCR
```
