# K-Docs - Architecture IA avec Fallback

## Vue d'ensemble

K-Docs utilise une stratégie de **fallback intelligent** pour les services IA :

```
┌─────────────────────────────────────────────────────────────────┐
│                    K-DOCS AI STRATEGY                           │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│   Priority 1: CLAUDE API                                         │
│   ├─ Configured? ──► YES ──► Use Claude (best quality)          │
│   └─ NO ↓                                                        │
│                                                                  │
│   Priority 2: OLLAMA (Local)                                     │
│   ├─ Running? ──► YES ──► Use Ollama (good quality, free)       │
│   └─ NO ↓                                                        │
│                                                                  │
│   Priority 3: Rules-only mode                                    │
│   └─ Pattern matching only (basic classification)                │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

## Composants

### 1. Claude API (Priorité 1)
- **Qualité** : Excellente
- **Coût** : Payant (~$3/million tokens)
- **Fonctionnalités** : Classification, extraction, résumé, chat
- **Configuration** : `claude_api_key.txt` ou `config.php`

### 2. Ollama (Priorité 2 - Fallback)
- **Qualité** : Bonne à acceptable
- **Coût** : Gratuit (local)
- **Fonctionnalités** : Classification, extraction, résumé, chat, embeddings
- **Modèles recommandés** :
  - LLM : `llama3.2` (classification, chat)
  - Embeddings : `nomic-embed-text` (recherche sémantique)

### 3. Rules-only (Priorité 3)
- **Qualité** : Basique
- **Fonctionnalités** : Matching patterns uniquement
- **Usage** : Quand aucune IA n'est disponible

## Installation Ollama (fallback)

```bash
# 1. Installer Ollama
# Windows: https://ollama.ai/download
# Linux: curl -fsSL https://ollama.ai/install.sh | sh

# 2. Télécharger les modèles
ollama pull llama3.2          # ~2GB - LLM pour classification
ollama pull nomic-embed-text  # ~275MB - Embeddings pour recherche

# 3. Vérifier
ollama list
curl http://localhost:11434/api/tags
```

## Configuration

### config.php
```php
return [
    // Claude (prioritaire si configuré)
    'claude' => [
        'api_key' => '', // Laisser vide pour utiliser Ollama
        'model' => 'claude-sonnet-4-20250514',
    ],
    
    // Ollama (fallback automatique)
    'api' => [
        'ollama_url' => 'http://localhost:11434',
    ],
    'ollama' => [
        'model' => 'llama3.2', // Modèle LLM par défaut
    ],
    
    // Embeddings (recherche sémantique)
    'embeddings' => [
        'enabled' => true,
        'provider' => 'ollama', // ou 'openai'
        'ollama_model' => 'nomic-embed-text',
    ],
];
```

## API Endpoints

### GET /api/ai/status
Retourne le statut des providers :
```json
{
  "active_provider": "ollama",
  "ai_available": true,
  "claude": {
    "available": false,
    "configured": false
  },
  "ollama": {
    "available": true,
    "url": "http://localhost:11434",
    "model": "llama3.2",
    "models": ["llama3.2:latest", "nomic-embed-text:latest"],
    "has_llm": true,
    "has_embedding": true
  },
  "fallback_active": true
}
```

### POST /api/ai/test
Test le provider actif :
```json
{
  "success": true,
  "provider": "ollama",
  "model": "llama3.2",
  "response": "OK",
  "duration_ms": 450
}
```

## Service AIProviderService

```php
use KDocs\Services\AIProviderService;

$ai = new AIProviderService();

// Vérifier la disponibilité
if ($ai->isAIAvailable()) {
    $provider = $ai->getBestProvider(); // 'claude', 'ollama', ou 'none'
}

// Classification automatique (utilise le meilleur provider)
$result = $ai->classifyDocument($content, $filename);

// Extraction de données
$data = $ai->extractData($content, ['date', 'amount', 'reference']);

// Résumé
$summary = $ai->summarize($content, 200);

// Complétion libre
$response = $ai->complete("Traduis en anglais: Bonjour");
```

## Comparaison des providers

| Fonctionnalité | Claude | Ollama | Rules |
|----------------|--------|--------|-------|
| Classification | ⭐⭐⭐⭐⭐ | ⭐⭐⭐⭐ | ⭐⭐ |
| Extraction | ⭐⭐⭐⭐⭐ | ⭐⭐⭐ | ⭐ |
| Résumé | ⭐⭐⭐⭐⭐ | ⭐⭐⭐⭐ | ❌ |
| Chat | ⭐⭐⭐⭐⭐ | ⭐⭐⭐⭐ | ❌ |
| Embeddings | ❌ | ⭐⭐⭐⭐⭐ | ❌ |
| Coût | 💰💰 | ✅ Gratuit | ✅ Gratuit |
| Confidentialité | ☁️ Cloud | ✅ 100% local | ✅ Local |
| Vitesse | ⚡ Rapide | 🐢 Variable | ⚡ Instant |

## Cas d'usage

### Scénario 1 : Production avec budget
- Configurer Claude API
- Ollama en backup si quota dépassé
- Meilleure qualité garantie

### Scénario 2 : Installation locale sans budget
- Ollama uniquement
- Qualité acceptable pour la plupart des usages
- 100% gratuit et privé

### Scénario 3 : Environnement air-gapped
- Ollama obligatoire (pas d'internet)
- Modèles pré-téléchargés

## Smoke Test

```bash
php tests/smoke_test.php
```

Output attendu :
```
--- INTELLIGENCE ARTIFICIELLE ---
[OK] 29. Claude API - disponible
[OK] 30. Ollama (fallback) - disponible  
[OK] 31. Provider IA actif - Claude (qualité max)
```

ou si Claude non configuré :
```
[!!] 29. Claude API - non configuré (warning)
[OK] 30. Ollama (fallback) - disponible
[OK] 31. Provider IA actif - Ollama (fallback)
```

---

*Architecture documentée le 30/01/2026*
