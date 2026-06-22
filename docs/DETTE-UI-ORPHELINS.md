# K-Docs — Dette UI / menus orphelins

> Menus, routes et modules **présents en code mais masqués** jusqu'à activation plugin.  
> Spec : `docs/superpowers/specs/2026-06-18-kdocs-redx-simplification-design.md`

---

## Principe

Un plugin **désactivé** ne doit produire **aucune entrée de navigation** ni route accessible accidentellement. Le code stub reste dans `apps/` pour développement futur.

---

## Modules masqués (B0)

| Module | Dossier | Activation | État sidebar | Notes |
|--------|---------|------------|--------------|-------|
| **K-Invoices** | `apps/invoices/` | `INVOICES_APP_ENABLED=false` (défaut) | Aucun lien | Routes montées uniquement si flag `true` via `PluginRegistry` |
| **K-Mail** | `apps/mail/` | `app.enabled = false` dans `config.php` | Aucun lien | Non monté — 404 si accès direct `/mail` |

### Vérification

```php
// apps/invoices/config.php
'enabled' => filter_var(env('INVOICES_APP_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

// apps/mail/config.php
'enabled' => false,
```

`PluginRegistry::registerAppRoutes()` saute les apps dont `enabled` est false.

---

## Modules visibles mais hors scope shell REDX

| Module | Route | Décision B0 | Phase retrait/migration |
|--------|-------|-------------|-------------------------|
| **K-Time** | `/time` | Reste visible (app fonctionnelle partielle) | Réévaluer en B1 — possible déplacement plugin |
| **Dashboard** | `/` | Reste accessible — pas priorité sidebar cible | Fusion Bibliothèque en B1 |
| **Indexation admin** | `/admin/indexing` | Admin only — à regrouper hub `/admin` | B0.8 / B1.2 |

---

## Sidebar actuelle vs cible

| État actuel (legacy) | Cible B1 |
|----------------------|----------|
| ~25 entrées mélangées user/admin | 5 entrées user + lien Admin |
| Tags, workflows, webhooks dans sidebar user | Hub `/admin` uniquement |
| « Fichiers à valider » → `/admin/consume` | « À traiter » user ou admin selon rôle |

---

## Smoke tests

`tests/full_pages_smoke_test.php` marque les routes `/invoices/*` comme **optionnelles** (404 attendu si plugin off).

---

## Réactivation future

1. **Factures (phase A)** : `INVOICES_APP_ENABLED=true` dans `.env` + health WinBiz bridge.
2. **Mail** : `app.enabled = true` + config IMAP + smoke routes `/mail`.
3. Documenter toute **nouvelle entrée sidebar** dans ce fichier avant merge.

---

*Créé : 2026-06-22 — lot B0.2*
