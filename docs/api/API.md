# K-Docs API REST Documentation

## Base URL
```
http://localhost/kdocs/api
```

## Authentification
Toutes les routes API nécessitent une authentification via session (même middleware que l'interface web).

## Endpoints

### Documents

#### Liste des documents
```
GET /api/documents
```

**Query Parameters:**
- `page` (int, default: 1) - Numéro de page
- `per_page` (int, default: 20, max: 100) - Nombre d'éléments par page
- `search` (string) - Recherche textuelle
- `document_type_id` (int) - Filtrer par type de document
- `correspondent_id` (int) - Filtrer par correspondant
- `tag_id` (int) - Filtrer par tag
- `order_by` (string) - Champ de tri (id, title, created_at, updated_at, document_date, amount)
- `order` (string) - Ordre (ASC, DESC)

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "title": "Document Title",
      "filename": "document.pdf",
      "original_filename": "Document Original.pdf",
      "file_path": "/path/to/file",
      "file_size": 12345,
      "mime_type": "application/pdf",
      "document_type_id": 1,
      "document_type_label": "Facture",
      "correspondent_id": 1,
      "correspondent_name": "Client ABC",
      "document_date": "2026-01-21",
      "amount": 100.50,
      "currency": "CHF",
      "created_at": "2026-01-21 10:00:00",
      "updated_at": "2026-01-21 10:00:00",
      "asn": 123
    }
  ],
  "pagination": {
    "page": 1,
    "per_page": 20,
    "total": 100,
    "total_pages": 5
  }
}
```

#### Détails d'un document
```
GET /api/documents/{id}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "title": "Document Title",
    "tags": [
      {
        "id": 1,
        "name": "Important",
        "color": "#ff0000"
      }
    ],
    ...
  }
}
```

#### Créer un document
```
POST /api/documents
```

**Body:**
```json
{
  "filename": "document.pdf",
  "file_path": "/path/to/file",
  "title": "Document Title",
  "document_type_id": 1,
  "correspondent_id": 1,
  "document_date": "2026-01-21",
  "amount": 100.50,
  "currency": "CHF"
}
```

#### Mettre à jour un document
```
PUT /api/documents/{id}
```

**Body:** (tous les champs sont optionnels)
```json
{
  "title": "New Title",
  "document_type_id": 2,
  "correspondent_id": 3,
  "tags": [1, 2, 3]
}
```

#### Supprimer un document
```
DELETE /api/documents/{id}
```

### Tags

#### Liste des tags
```
GET /api/tags
```

#### Détails d'un tag
```
GET /api/tags/{id}
```

#### Créer un tag
```
POST /api/tags
```

**Body:**
```json
{
  "name": "Tag Name",
  "color": "#ff0000",
  "match": "pattern",
  "matching_algorithm": "auto",
  "parent_id": null
}
```

#### Mettre à jour un tag
```
PUT /api/tags/{id}
```

#### Supprimer un tag
```
DELETE /api/tags/{id}
```

### Correspondents

#### Liste des correspondants
```
GET /api/correspondents
```

#### Détails d'un correspondant
```
GET /api/correspondents/{id}
```

#### Créer un correspondant
```
POST /api/correspondents
```

**Body:**
```json
{
  "name": "Correspondent Name",
  "match": "pattern",
  "matching_algorithm": "auto"
}
```

#### Mettre à jour un correspondant
```
PUT /api/correspondents/{id}
```

#### Supprimer un correspondant
```
DELETE /api/correspondents/{id}
```

## Codes de réponse HTTP

- `200` - Succès
- `201` - Créé avec succès
- `400` - Erreur de validation
- `404` - Ressource non trouvée
- `500` - Erreur serveur

## Format des erreurs

```json
{
  "error": true,
  "message": "Message d'erreur",
  "errors": {
    "field": ["Erreur de validation"]
  }
}
```

---

### Validation

#### Documents en attente de validation
```
GET /api/validation/pending
```

**Query Parameters:**
- `limit` (int, default: 50) - Nombre maximum

**Response:**
```json
{
  "success": true,
  "count": 5,
  "documents": [
    {
      "id": 123,
      "title": "Facture > 1000 CHF",
      "amount": 1500.00,
      "approval_deadline": "2026-01-30T00:00:00",
      "days_until_deadline": 3
    }
  ]
}
```

#### Définir le statut de validation
```
POST /api/validation/{documentId}/status
```

**Body:**
```json
{
  "status": "approved",
  "comment": "Document conforme"
}
```

**Valeurs status:** `approved`, `rejected`, `na`

**Response:**
```json
{
  "success": true,
  "status": "approved",
  "validated_by": 1,
  "role": "VALIDATOR_L1"
}
```

#### Approuver un document
```
POST /api/validation/{documentId}/approve
```

**Body:**
```json
{
  "comment": "Approuvé"
}
```

#### Rejeter un document
```
POST /api/validation/{documentId}/reject
```

**Body:**
```json
{
  "comment": "Information manquante"
}
```

#### Récupérer le statut de validation
```
GET /api/validation/{documentId}/status
```

**Response:**
```json
{
  "success": true,
  "document_id": 123,
  "status": "approved",
  "validated_by": {"id": 1, "username": "admin"},
  "validated_at": "2026-01-27T11:00:00",
  "comment": "OK",
  "requires_approval": false
}
```

#### Historique de validation
```
GET /api/validation/{documentId}/history
```

**Response:**
```json
{
  "success": true,
  "document_id": 123,
  "history": [
    {
      "action": "submitted",
      "from_status": null,
      "to_status": "pending",
      "performed_by": 2,
      "username": "user1",
      "created_at": "2026-01-27T10:00:00"
    }
  ]
}
```

#### Statistiques de validation
```
GET /api/validation/statistics
```

**Query Parameters:**
- `period` (string) - `day`, `week`, `month`, `year`
- `user_id` (int) - Filtrer par validateur

**Response:**
```json
{
  "success": true,
  "period": "month",
  "statistics": {
    "approved": {"count": 45, "total_amount": 25000, "avg_amount": 555},
    "rejected": {"count": 5, "total_amount": 3000, "avg_amount": 600},
    "pending": {"count": 10, "total_amount": 8000, "avg_amount": 800}
  }
}
```

#### Vérifier si l'utilisateur peut valider
```
GET /api/validation/can-validate/{documentId}
```

**Response:**
```json
{
  "success": true,
  "document_id": 123,
  "can_validate": true,
  "role": "VALIDATOR_L1",
  "max_amount": 10000
}
```

---

### Notifications

#### Liste des notifications
```
GET /api/notifications
```

**Query Parameters:**
- `limit` (int, default: 50)
- `offset` (int, default: 0)

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "type": "validation_pending",
      "title": "Document à valider",
      "message": "Facture EDF soumise par user1",
      "link": "/documents/123",
      "is_read": false,
      "priority": "high",
      "created_at": "2026-01-27T10:00:00"
    }
  ]
}
```

#### Notifications non lues
```
GET /api/notifications/unread
```

**Response:**
```json
{
  "success": true,
  "count": 5,
  "by_priority": {
    "urgent": 1,
    "high": 2,
    "normal": 2,
    "low": 0
  },
  "notifications": [...]
}
```

#### Marquer une notification comme lue
```
POST /api/notifications/{id}/read
```

#### Marquer toutes les notifications comme lues
```
POST /api/notifications/read-all
```

---

### Rôles

#### Liste des types de rôles
```
GET /api/roles
```

**Response:**
```json
{
  "success": true,
  "roles": [
    {"code": "VALIDATOR_L1", "label": "Validateur Niveau 1"},
    {"code": "VALIDATOR_L2", "label": "Validateur Niveau 2"},
    {"code": "APPROVER", "label": "Approbateur"},
    {"code": "ADMIN", "label": "Administrateur"}
  ]
}
```

#### Rôles d'un utilisateur
```
GET /api/roles/user/{userId}
```

#### Assigner un rôle
```
POST /api/roles/user/{userId}/assign
```

**Body:**
```json
{
  "role_code": "VALIDATOR_L1",
  "scope": "*",
  "max_amount": 5000,
  "valid_from": "2026-01-01",
  "valid_to": "2026-12-31"
}
```

#### Retirer un rôle
```
DELETE /api/roles/user/{userId}/{roleCode}?scope=*
```

---

### Recherche

#### Recherche avancée
```
GET /api/search
```

**Query Parameters:**
- `q` (string) - Texte recherché
- `correspondent_id` (int)
- `document_type_id` (int)
- `tag_ids[]` (int[])
- `created_after` (date)
- `created_before` (date)
- `amount_min` (float)
- `amount_max` (float)
- `page` (int)
- `per_page` (int)

**Response:**
```json
{
  "success": true,
  "data": {
    "documents": [...],
    "total": 25,
    "search_time": 0.045,
    "facets": {
      "correspondents": [{"id": 1, "name": "EDF", "count": 10}],
      "document_types": [{"id": 5, "name": "Facture", "count": 15}],
      "tags": [{"id": 1, "name": "Important", "count": 5}]
    }
  }
}
```

#### Suggestions de recherche
```
GET /api/search/suggest?q=fac&limit=10
```

**Response:**
```json
{
  "success": true,
  "suggestions": [
    {"text": "Facture EDF", "type": "document"},
    {"text": "EDF", "type": "correspondent"}
  ]
}
```

---

## Rate Limiting

L'API est limitée à **100 requêtes par minute** par adresse IP.

**Headers:**
```
X-RateLimit-Limit: 100
X-RateLimit-Remaining: 95
X-RateLimit-Reset: 1706356800
```

**Erreur 429:**
```json
{
  "error": "Rate limit exceeded. Try again in 45 seconds."
}
```
