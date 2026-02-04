# FIX FALLBACK OLLAMA - Résumé des modifications

## ✅ MODIFICATIONS EFFECTUÉES

### 1. ClaudeService.php - Détection améliorée des erreurs HTTP

**Changements :**
- `sendMessage()` retourne maintenant un tableau structuré en cas d'erreur HTTP :
  ```php
  [
    'error' => true,
    'http_code' => 400/402/429,
    'message' => 'error message',
    'is_payment_error' => true/false,
    'is_rate_limit' => true/false,
    'should_fallback' => true/false
  ]
  ```

- Détection automatique des erreurs de paiement :
  - HTTP 402 (Payment Required)
  - HTTP 400 avec message contenant "credit balance", "insufficient", "too low", "payment"
  - HTTP 429 (Rate Limit)

- Logs améliorés :
  - `"Claude API error: HTTP 402 (Payment Required) - [message] - Switching to Ollama fallback"`
  - `"Claude API error: HTTP 429 (Rate Limit) - [message] - Switching to Ollama fallback"`

**Méthodes modifiées :**
- `sendMessage()` - Détection HTTP 402/429/400 avec crédits
- `sendMessageWithFile()` - Même logique pour les fichiers

### 2. AIProviderService.php - Cascade améliorée avec logs explicites

**Changements :**
- `completeWithClaude()` détecte maintenant les erreurs structurées retournées par ClaudeService
- Logs clairs lors du fallback :
  - `"AIProviderService: Claude API error HTTP 402 (Payment Required) - [message] - Switching to Ollama"`
  - `"AIProviderService: Successfully switched to Ollama"`

- `complete()` méthode principale :
  - Détecte automatiquement les erreurs Claude
  - Passe automatiquement à Ollama si `should_fallback === true`
  - Logs détaillés à chaque étape

### 3. AIClassifierService.php - Utilisation de AIProviderService

**Changements majeurs :**
- `classify()` utilise maintenant **uniquement** `AIProviderService` au lieu d'appeler ClaudeService directement
- Suppression de la logique de fallback manuelle (déléguée à AIProviderService)
- Logs améliorés :
  - `"AIClassifierService: Classification successful via Ollama (Claude unavailable)"`
  - `"AIClassifierService: Classification successful via claude"`

- `classifyWithFile()` et `classifyComplexWithFile()` :
  - Détectent les erreurs structurées de ClaudeService
  - Utilisent AIProviderService pour le fallback Ollama avec texte extrait

### 4. DocumentsApiController.php - Messages utilisateur améliorés

**Changements :**
- Vérification du statut AI avant classification
- Messages d'erreur explicites :
  - `"Claude indisponible et Ollama a échoué. Vérifiez que Ollama est démarré (ollama serve)."`
  - `"Les services IA (Claude et Ollama) ont échoué. Vérifiez les logs."`

- Réponse enrichie avec métadonnées :
  ```php
  $suggestions['_provider'] = 'ollama' ou 'claude';
  $suggestions['_fallback_used'] = true/false;
  $suggestions['_message'] = 'Classification effectuée via Ollama (Claude indisponible)';
  ```

## 🔄 CASCADE DE FALLBACK

```
1. Tentative Claude
   ↓ (HTTP 400/402/429)
2. Détection erreur → Log "Claude failed, switching to Ollama"
   ↓
3. Tentative Ollama
   ↓ (succès)
4. Log "Successfully switched to Ollama"
   ↓
5. Retour résultat avec _provider = 'ollama'
```

## 📋 TESTS EFFECTUÉS

✅ Syntaxe PHP : Tous les fichiers valides
✅ Ollama disponible : `curl http://localhost:11434/api/tags` → OK
✅ Modèles disponibles :
   - llama3.1:8b
   - mistral:7b
   - nomic-embed-text (embeddings)

## 🧪 TESTS À EFFECTUER

1. **Test Suggestions IA avec Claude indisponible :**
   - Ouvrir document dans l'interface
   - Cliquer "Suggestions IA"
   - Vérifier logs : doit voir "Claude failed, switching to Ollama"
   - Vérifier résultat : doit classifier via Ollama
   - Vérifier message : doit afficher "Classification effectuée via Ollama"

2. **Test avec Claude disponible :**
   - Recharger crédits Claude API
   - Cliquer "Suggestions IA"
   - Vérifier : doit utiliser Claude (pas de fallback)

3. **Test avec Ollama indisponible :**
   - Arrêter Ollama : `ollama stop` ou fermer le service
   - Cliquer "Suggestions IA"
   - Vérifier message d'erreur : doit indiquer "Ollama a échoué"

## 📝 LOGS ATTENDUS

### Cas 1 : Claude échoue → Ollama réussit
```
Claude API error: HTTP 400 (Payment Required) - Your credit balance is too low... - Switching to Ollama fallback
AIProviderService: Claude API error HTTP 400 (Payment Required) - Your credit balance is too low... - Switching to Ollama
AIProviderService: Claude failed, switching to Ollama fallback
AIProviderService: Successfully switched to Ollama
AIClassifierService: Classification successful via Ollama (Claude unavailable)
```

### Cas 2 : Claude et Ollama échouent
```
Claude API error: HTTP 400 (Payment Required) - ... - Switching to Ollama fallback
AIProviderService: Claude failed, switching to Ollama fallback
AIProviderService: Ollama fallback also failed
AIClassifierService: All AI providers failed (Claude and Ollama)
```

## 🎯 RÉSULTAT ATTENDU

**Avant le fix :**
- Erreur silencieuse → Message générique "Impossible de classifier"
- Pas de fallback vers Ollama
- Pas d'information sur le provider utilisé

**Après le fix :**
- Logs clairs à chaque étape
- Fallback automatique vers Ollama si Claude échoue
- Message utilisateur explicite : "Classification effectuée via Ollama (Claude indisponible)"
- Métadonnées dans la réponse API indiquant le provider utilisé

---

*Fix effectué le 2026-02-04 19:00*
