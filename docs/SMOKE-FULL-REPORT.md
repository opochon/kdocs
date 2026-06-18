# GEDv1 — Rapport smoke complet

Date : 2026-06-18 05:30:00
Base URL : `http://127.0.0.1:8771/kdocs`
Run ID : `full-smoke-20260618-052931`

## Résumé

| Métrique | Valeur |
|----------|--------|
| Total testé | 62 |
| OK | 64 |
| KO | 0 |

## Échecs

| Page | Path | HTTP | PHP error |
|------|------|------|-----------|
| Invoices inbox | `/invoices` | 404 | non |
| Invoices inbox alt | `/invoices/inbox` | 404 | non |
| API snapshots latest | `/api/snapshots/latest` | 404 | non |
| API invoices pending | `/invoices/api/pending` | 404 | non |
| API invoices stats | `/invoices/api/stats` | 404 | non |

## Tous les résultats

| Status | Page | Path | HTTP |
|--------|------|------|------|
| OK | Login | `/login` | 200 |
| OK | Health JSON | `/health` | 200 |
| OK | Asset tailwind.css | `/public/css/tailwind.css` | 200 |
| OK | Asset app.js | `/public/js/app.js` | 200 |
| OK | Dashboard | `/` | 200 |
| OK | Dashboard alias | `/dashboard` | 200 |
| OK | Documents | `/documents` | 200 |
| OK | Upload | `/documents/upload` | 200 |
| OK | Mes tâches | `/mes-taches` | 200 |
| OK | Chat IA | `/chat` | 200 |
| OK | Tasks | `/tasks` | 200 |
| OK | Tasks create | `/tasks/create` | 200 |
| OK | Admin hub | `/admin` | 200 |
| OK | Admin diagnostic | `/admin/diagnostic` | 200 |
| OK | Admin users | `/admin/users` | 200 |
| OK | Admin users create | `/admin/users/create` | 200 |
| OK | Admin settings | `/admin/settings` | 200 |
| OK | Admin workflows | `/admin/workflows` | 200 |
| OK | Admin workflows create | `/admin/workflows/create` | 302 |
| OK | Admin indexing | `/admin/indexing` | 200 |
| OK | Admin tags | `/admin/tags` | 200 |
| OK | Admin tags create | `/admin/tags/create` | 200 |
| OK | Admin correspondents | `/admin/correspondents` | 200 |
| OK | Admin correspondents create | `/admin/correspondents/create` | 200 |
| OK | Admin consume | `/admin/consume` | 200 |
| OK | Admin document-types | `/admin/document-types` | 200 |
| OK | Admin custom-fields | `/admin/custom-fields` | 200 |
| OK | Admin storage-paths | `/admin/storage-paths` | 200 |
| OK | Admin webhooks | `/admin/webhooks` | 200 |
| OK | Admin audit-logs | `/admin/audit-logs` | 200 |
| OK | Admin export-import | `/admin/export-import` | 200 |
| OK | Admin mail-accounts | `/admin/mail-accounts` | 200 |
| OK | Admin scheduled-tasks | `/admin/scheduled-tasks` | 200 |
| OK | Admin classification-fields | `/admin/classification-fields` | 200 |
| OK | Admin attribution-rules | `/admin/attribution-rules` | 200 |
| OK | Admin snapshots | `/admin/snapshots` | 200 |
| OK | Admin roles | `/admin/roles` | 200 |
| OK | Admin user-groups | `/admin/user-groups` | 200 |
| OK | Admin api-usage | `/admin/api-usage` | 200 |
| OK | K-Time dashboard | `/time` | 200 |
| OK | K-Time entries | `/time/entries` | 200 |
| KO | Invoices inbox | `/invoices` | 404 |
| KO | Invoices inbox alt | `/invoices/inbox` | 404 |
| OK | API workflows | `/api/workflows` | 200 |
| OK | API AI status | `/api/ai/status` | 200 |
| OK | API tags | `/api/tags` | 200 |
| OK | API correspondents | `/api/correspondents` | 200 |
| OK | API document-types | `/api/document-types` | 200 |
| OK | API classification-fields | `/api/classification-fields` | 200 |
| OK | API tasks counts | `/api/tasks/counts` | 200 |
| OK | API notifications count | `/api/notifications/count` | 200 |
| OK | API folders tree | `/api/folders/tree` | 200 |
| OK | API validation stats | `/api/validation/statistics` | 200 |
| OK | API embeddings status | `/api/embeddings/status` | 200 |
| OK | API semantic-search status | `/api/semantic-search/status` | 200 |
| OK | API onlyoffice status | `/api/onlyoffice/status` | 200 |
| OK | API workflow node-catalog | `/api/workflow/node-catalog` | 200 |
| OK | API roles | `/api/roles` | 200 |
| OK | API notes recipients | `/api/notes/recipients` | 200 |
| KO | API snapshots latest | `/api/snapshots/latest` | 404 |
| KO | API invoices pending | `/invoices/api/pending` | 404 |
| KO | API invoices stats | `/invoices/api/stats` | 404 |
