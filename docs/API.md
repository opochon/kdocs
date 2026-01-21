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
