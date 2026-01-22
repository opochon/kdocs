# K-Docs - PROMPT CURSOR : Corrections IA COMPLÈTES

## 🎯 DIAGNOSTIC EFFECTUÉ

### État Base de Données :
- **8 documents** en base
- **Document ID 8** : "Courrier au Tribunal civil - envoyé" 
- **Contenu OCR** : Messages d'erreur ("pdftotext n'est pas reconnu...") au lieu du vrai texte
- **Clé API Claude** : NON CONFIGURÉE

### Bugs Identifiés :
1. **Bouton "Suggestions IA"** → Appelle `getAISuggestions()` qui fait juste `alert()`
2. **Recherche IA** → Recherche "document divorce" comme phrase exacte au lieu de mots séparés
3. **Recherche "tribunal"** → Devrait fonctionner mais pas testé via interface chat

---

## 🔧 CORRECTIONS À APPLIQUER

### CORRECTION 1 : show.php - Fonction getAISuggestions()

**Fichier** : `templates/documents/show.php`

**Chercher** (vers ligne 835-840) :

```javascript
function getAISuggestions(docId) {
    // TODO: Implémenter suggestions IA
    alert('Suggestions IA à implémenter');
}
```

**Remplacer par** :

```javascript
async function getAISuggestions(docId) {
    const btn = event.target;
    const originalHTML = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="animate-pulse">Analyse...</span>';
    
    try {
        // L'API existe déjà : /api/documents/{id}/classify-ai
        const response = await fetch(`<?= url('/api/documents/') ?>${docId}/classify-ai`, {
            method: 'POST',
            headers: { 
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            }
        });
        
        const data = await response.json();
        
        if (!response.ok || data.error) {
            alert('Erreur: ' + (data.error || data.message || 'Erreur inconnue'));
            return;
        }
        
        const suggestions = data.suggestions || data.data?.suggestions;
        if (!suggestions) {
            alert('Aucune suggestion disponible. Vérifiez que :\n1. La clé API Claude est configurée (Paramètres > IA)\n2. Le document contient du texte lisible');
            return;
        }
        
        // Construire le message
        const msg = [];
        if (suggestions.title_suggestion) msg.push(`📝 Titre: ${suggestions.title_suggestion}`);
        if (suggestions.correspondent) msg.push(`👤 Correspondant: ${suggestions.correspondent}`);
        if (suggestions.document_type) msg.push(`📁 Type: ${suggestions.document_type}`);
        if (suggestions.tags && suggestions.tags.length) msg.push(`🏷️ Tags: ${suggestions.tags.join(', ')}`);
        if (suggestions.document_date) msg.push(`📅 Date: ${suggestions.document_date}`);
        if (suggestions.amount) msg.push(`💰 Montant: ${suggestions.amount} CHF`);
        if (suggestions.confidence) msg.push(`\n📊 Confiance: ${Math.round(suggestions.confidence * 100)}%`);
        
        if (msg.length === 0) {
            alert('L\'IA n\'a pas pu extraire de suggestions pour ce document.');
            return;
        }
        
        const apply = confirm('🤖 Suggestions IA :\n\n' + msg.join('\n') + '\n\nAppliquer ces suggestions ?');
        
        if (apply) {
            // Appeler l'API pour appliquer les suggestions
            const applyResponse = await fetch(`<?= url('/api/documents/') ?>${docId}/apply-ai-suggestions`, {
                method: 'POST',
                headers: { 
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                }
            });
            
            const applyData = await applyResponse.json();
            
            if (applyResponse.ok && !applyData.error) {
                alert('✅ Suggestions appliquées ! La page va se recharger.');
                window.location.reload();
            } else {
                alert('Erreur lors de l\'application: ' + (applyData.error || 'Erreur inconnue'));
            }
        }
    } catch (error) {
        console.error('Erreur suggestions IA:', error);
        alert('Erreur de connexion: ' + error.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalHTML;
    }
}
```

---

### CORRECTION 2 : AISearchService.php - Recherche plus tolérante

**Fichier** : `app/Services/AISearchService.php`

**Chercher la méthode `executeSearch()`** et **remplacer la partie recherche full-text** :

**Avant** (vers ligne 80-90) :

```php
// Recherche full-text
if (!empty($filters['text_search'])) {
    $search = '%' . $filters['text_search'] . '%';
    $conditions[] = "(d.title LIKE ? OR d.content LIKE ? OR d.original_filename LIKE ?)";
    $params = array_merge($params, [$search, $search, $search]);
}
```

**Après** :

```php
// Recherche full-text AMÉLIORÉE - cherche chaque mot avec OR
if (!empty($filters['text_search'])) {
    $searchText = trim($filters['text_search']);
    $words = preg_split('/\s+/', $searchText);
    
    if (count($words) > 1) {
        // Multi-mots : chercher AU MOINS un mot (OR)
        $wordConditions = [];
        foreach ($words as $word) {
            if (strlen($word) >= 2) {
                $search = '%' . $word . '%';
                $wordConditions[] = "(d.title LIKE ? OR d.content LIKE ? OR d.ocr_text LIKE ? OR d.original_filename LIKE ?)";
                $params = array_merge($params, [$search, $search, $search, $search]);
            }
        }
        if (!empty($wordConditions)) {
            $conditions[] = "(" . implode(" OR ", $wordConditions) . ")";
        }
    } else {
        // Un seul mot
        $search = '%' . $searchText . '%';
        $conditions[] = "(d.title LIKE ? OR d.content LIKE ? OR d.ocr_text LIKE ? OR d.original_filename LIKE ?)";
        $params = array_merge($params, [$search, $search, $search, $search]);
    }
}
```

---

### CORRECTION 3 : chat/index.php - Vérification Claude cohérente

**Fichier** : `templates/chat/index.php`

**Chercher** (lignes 5-8) :

```php
// Vérifier si Claude est configuré
$claudeApiKey = Setting::get('ai.claude_api_key', '');
$isConfigured = !empty($claudeApiKey);
```

**Remplacer par** :

```php
// Vérifier si Claude est configuré (utiliser le même service que partout)
$claudeService = new \KDocs\Services\ClaudeService();
$isConfigured = $claudeService->isConfigured();
```

---

### CORRECTION 4 : documents/index.php - Bug HTML visible

**Fichier** : `templates/documents/index.php`

**Chercher** (vers ligne 85-95) du code comme :

```html
       placeholder="Rechercher... (Ctrl+K ou /)" 
       class="..."
>
       title="Raccourci: Ctrl+K ou /"
       onkeydown="if(event.key === 'Enter') this.form.submit()">
```

**Le problème** : les attributs `title` et `onkeydown` sont APRÈS le `>` donc ils s'affichent comme texte.

**Corriger** en mettant les attributs AVANT le `>` :

```html
       placeholder="Rechercher... (Ctrl+K ou /)" 
       class="..."
       title="Raccourci: Ctrl+K ou /"
       onkeydown="if(event.key === 'Enter') this.form.submit()">
```

---

### CORRECTION 5 : ClaudeService.php - Recherche clé API améliorée

**Fichier** : `app/Services/ClaudeService.php`

**Vérifier/améliorer le constructeur** pour chercher la clé partout :

```php
public function __construct()
{
    $config = \KDocs\Core\Config::load();
    
    // Chercher la clé API (ordre de priorité)
    $this->apiKey = '';
    
    // 1. Config directe
    if (!empty($config['claude']['api_key'])) {
        $this->apiKey = $config['claude']['api_key'];
    }
    // 2. Config ai
    elseif (!empty($config['ai']['claude_api_key'])) {
        $this->apiKey = $config['ai']['claude_api_key'];
    }
    // 3. Variable d'environnement
    elseif (!empty($_ENV['ANTHROPIC_API_KEY'])) {
        $this->apiKey = $_ENV['ANTHROPIC_API_KEY'];
    }
    elseif (!empty(getenv('ANTHROPIC_API_KEY'))) {
        $this->apiKey = getenv('ANTHROPIC_API_KEY');
    }
    // 4. Setting en base
    else {
        try {
            $setting = \KDocs\Models\Setting::get('ai.claude_api_key');
            if (!empty($setting)) {
                $this->apiKey = $setting;
            }
        } catch (\Exception $e) {}
    }
    // 5. Fichier texte
    if (empty($this->apiKey)) {
        $keyFile = dirname(__DIR__, 2) . '/claude_api_key.txt';
        if (file_exists($keyFile)) {
            $this->apiKey = trim(file_get_contents($keyFile));
        }
    }
    
    if (isset($config['claude']['model'])) {
        $this->model = $config['claude']['model'];
    }
}
```

---

## ✅ TESTS À EFFECTUER APRÈS CORRECTIONS

### Test 1 : Recherche IA améliorée
1. Aller dans "Recherche avancée" (Chat)
2. Taper "document" → doit trouver des résultats
3. Taper "tribunal" → doit trouver le document ID 8
4. Taper "document tribunal" → doit trouver le document ID 8 (OR des deux mots)

### Test 2 : Suggestions IA
1. Ouvrir le document ID 8
2. Cliquer "Suggestions IA"
3. Si pas de clé API → message d'erreur clair
4. Si clé configurée → suggestions affichées

### Test 3 : Barre de recherche
1. Aller sur /documents
2. Vérifier que le code HTML n'est plus visible dans la barre

---

## 🔑 CONFIGURATION CLÉ API CLAUDE

Pour que l'IA fonctionne, Olivier doit configurer sa clé API Anthropic.

**Option la plus simple** : Créer le fichier `C:\wamp64\www\kdocs\claude_api_key.txt` avec la clé dedans.

**Ou** : Dans `config/config.php`, mettre la clé dans `'claude' => ['api_key' => 'sk-ant-...']`

---

## 📋 RÉSUMÉ DES FICHIERS À MODIFIER

| Fichier | Modification |
|---------|--------------|
| `templates/documents/show.php` | Remplacer `getAISuggestions()` |
| `app/Services/AISearchService.php` | Améliorer recherche full-text |
| `templates/chat/index.php` | Utiliser ClaudeService pour vérif |
| `templates/documents/index.php` | Corriger HTML mal formé |
| `app/Services/ClaudeService.php` | Améliorer recherche clé API |

---

## 🚀 COMMANDE CURSOR

```
Lis docs/CURSOR_FIX_ALL_IA.md et applique TOUTES les corrections dans l'ordre :

1. templates/documents/show.php - fonction getAISuggestions()
2. app/Services/AISearchService.php - recherche multi-mots  
3. templates/chat/index.php - vérification Claude
4. templates/documents/index.php - bug HTML
5. app/Services/ClaudeService.php - recherche clé API

Teste ensuite que la recherche "tribunal" trouve des résultats.
```
