# K-Time

Saisie horaire ultra-rapide avec facturation.

## Contraintes

- **PAS DE DOCKER**
- **PAS DE DEPENDANCES EXTERNES**
- 100% PHP + MySQL (meme base que GED)
- Demarrage instantane

## Stack technique

| Composant | Solution |
|-----------|----------|
| Base de donnees | MySQL (partagee avec K-Docs) |
| PDF | TCPDF ou Dompdf (PHP pur) |
| UI | PHP + Tailwind (SSR) |
| Export | CSV, JSON, WinBiz |

## Structure

```
timetrack/
├── Controllers/
│   ├── DashboardController.php
│   ├── EntryController.php
│   ├── TimerController.php
│   ├── ClientController.php
│   ├── ProjectController.php
│   ├── InvoiceController.php
│   └── KDocsController.php
├── Models/
│   ├── Client.php
│   ├── Project.php
│   ├── Entry.php
│   ├── Timer.php
│   ├── Supply.php
│   └── Invoice.php
├── Services/
│   ├── QuickCodeParser.php
│   ├── TimerService.php
│   ├── InvoiceGenerator.php
│   └── KDocsIntegration.php
├── templates/
│   ├── dashboard.php
│   ├── entries/index.php
│   └── invoices/index.php
├── migrations/
│   └── 001_create_timetrack_tables.sql
├── routes.php
├── config.php
└── README.md
```

## Quick Codes (saisie rapide)

Syntaxe: `[duree][code_projet] [pREF][quantite] [description libre]`

| Saisie | Interpretation |
|--------|----------------|
| `2.5h` | 2h30 (duree seule) |
| `2.5hA1` | 2h30 sur projet A1 |
| `1h30` | 1h30 (format hh:mm supporte) |
| `pAA2` | 2 unites du produit AA |
| `2.5hA1 pAA2` | 2h30 projet A1 + 2 produits AA |

## Fonctionnalites

- Saisie rapide via Quick Codes
- Timer start/stop avec persistance
- Mode freelance + mode equipes planifie
- Generation factures PDF avec QR suisse
- Integration K-Docs (stockage factures, sync clients)

## Integration K-Docs

Si K-Docs est installe:
- Factures generees -> stockees dans K-Docs
- Recherche documents depuis K-Time
- Clients/Correspondants partages

Si K-Docs n'est pas installe:
- K-Time fonctionne en standalone
- Export PDF local
- Base clients propre

## Statut

**A faire** - Phase de conception

Voir `docs/KTIME_SPECIFICATION.md` pour les details complets.

---
*K-Time - Application K-Docs*
