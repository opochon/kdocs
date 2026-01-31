# K-Invoices

Gestion des factures fournisseurs avec extraction IA.

## Contraintes

- **PAS DE DOCKER**
- Depend de K-Docs Core (documents)
- PHP natif uniquement

## Stack technique

| Composant | Solution |
|-----------|----------|
| Extraction IA | Claude API / Ollama local |
| Base de donnees | MySQL (partagee avec K-Docs) |
| Rapprochement | WinBiz (ODBC) |
| UI | PHP + Tailwind (SSR) |

## Structure

```
invoices/
├── Controllers/
│   ├── InvoiceController.php
│   ├── LineController.php
│   ├── MatchingController.php
│   └── ExportController.php
├── Models/
│   ├── SupplierInvoice.php
│   ├── InvoiceLine.php
│   └── Matching.php
├── Services/
│   ├── InvoiceExtractor.php
│   ├── LineMatchingService.php
│   ├── WinBizExporter.php
│   └── LearningService.php
├── templates/
│   ├── inbox.php
│   ├── validate.php
│   └── export.php
├── migrations/
├── routes.php
├── config.php
└── README.md
```

## Fonctionnalites

### Extraction automatique (IA)
- Detection fournisseur
- Extraction numero facture, date, montants
- Extraction lignes detaillees (article, qte, prix)
- Apprentissage par fournisseur

### Rapprochement WinBiz
- Lecture BL (bons de livraison)
- Lecture fiches de travail
- Lecture stock articles
- Suggestion de rapprochement

### Validation
- Interface ligne par ligne
- Correction manuelle
- Validation en masse
- Historique modifications

### Export comptable
- Format WinBiz
- Format CSV generique
- Ecritures comptables

## Workflow

```
1. Document arrive dans K-Docs
   ↓
2. Detection "facture fournisseur"
   ↓
3. Extraction IA des lignes
   ↓
4. Proposition rapprochement WinBiz
   ↓
5. Validation utilisateur
   ↓
6. Export comptable
```

## Statut

**A faire** - Phase de conception

---
*K-Invoices - Application K-Docs*
