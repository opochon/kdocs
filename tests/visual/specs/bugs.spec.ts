import { test, expect, type Page } from '@playwright/test';

// Régressions rapportées (note de test 2026-06-30) — épinglées par Playwright.
// Objectif : distinguer les vrais bugs code des artefacts d'automatisation (le testeur
// ne pouvait pas taper au clavier → champs `required` vides → pas de submit ; liens <a>
// standards non cliqués). Playwright tape/clique réellement.

const BASE_PATH = process.env.KDOCS_BASE_PATH ?? '/kdocs';
const HOST = process.env.KDOCS_HOST ?? '127.0.0.1';
const PORT = process.env.KDOCS_PORT ?? '8765';
const BASE = `http://${HOST}:${PORT}${BASE_PATH}`;

const ERROR_MARKERS = [
  'Fatal error', 'Parse error', 'Uncaught', 'Whoops',
  'PDOException', 'Call to undefined', 'syntax error, unexpected',
  'Warning: Undefined array key', 'Warning: Undefined variable',
];

// B1 : /admin/diagnostic ne doit plus afficher de warning PHP "Undefined array key".
test('B1: /admin/diagnostic sans warning PHP (Undefined array key)', async ({ page }) => {
  await page.goto(`${BASE}/admin/diagnostic`, { waitUntil: 'domcontentloaded' });
  expect(page.url(), 'redirection login').not.toMatch(/\/login\b/);
  const html = await page.content();
  for (const marker of ERROR_MARKERS) {
    expect(html, `marqueur "${marker}" sur /admin/diagnostic`).not.toContain(marker);
  }
});

// A2 : les liens de la sidebar admin réagissent au clic (navigation réelle).
test('A2: lien sidebar admin Diagnostic réactif au clic', async ({ page }) => {
  await page.goto(`${BASE}/admin`, { waitUntil: 'domcontentloaded' });
  const link = page.locator('a.ds-nav-item').filter({ hasText: 'Diagnostic' }).first();
  await expect(link).toBeVisible();
  await link.click();
  await page.waitForLoadState('domcontentloaded');
  expect(page.url(), 'clic Diagnostic -> /admin/diagnostic').toContain('/admin/diagnostic');
});

// A1 : création de tâche — le bouton « Créer la tâche » déclenche bien un POST.
test('A1: création de tâche (bouton submit -> POST -> redirect /tasks)', async ({ page }) => {
  const stamp = Date.now();
  const title = `TestA1_${stamp}`;
  await page.goto(`${BASE}/tasks/create`, { waitUntil: 'domcontentloaded' });

  // Champs required remplis (frappe réelle) -> le submit n'est plus bloqué par validation HTML5.
  await page.fill('#title', title);
  await page.click('button[type="submit"]');

  // Le contrôleur ne redirige vers /tasks?success=1 QU'en cas de création réussie
  // (sinon il re-rend le formulaire à /tasks/create en 200). Le fait que le POST parte
  // et déclenche la redirection 302 prouve que le bouton fonctionne — l'« absence de POST »
  // rapportée par le testeur venait de son incapacité à remplir les champs required.
  await page.waitForURL(/\/tasks\?success=1$/);
  expect(page.url(), 'redirection succès').toContain('success=1');
  await expect(page.locator('text=Tâche créée/mise à jour avec succès')).toBeVisible();
});

// E1 : aucun tag en doublon (même nom, casse insensible) côté API référentiels.
test('E1: aucun tag en doublon de nom', async ({ page }) => {
  // Aller sur la fiche d'un doc pour charger la liste des tags via l'API show.
  const docsRes = await page.context().request.get(`${BASE}/api/documents?per_page=1`);
  const docsJson = await docsRes.json();
  const docs = docsJson.data ?? docsJson.documents ?? [];
  test.skip(docs.length === 0, 'Aucun document pour charger la liste des tags');

  const showRes = await page.context().request.get(`${BASE}/api/documents/${docs[0].id}`);
  const showJson = await showRes.json();
  const meta = showJson.data?._meta ?? showJson._meta ?? {};
  const tags = meta.all_tags ?? [];
  test.skip(tags.length === 0, 'Aucun tag exposé dans _meta.all_tags');

  const names = tags.map((t: any) => (t.name ?? '').toLowerCase());
  const dupes = names.filter((n: string, i: number) => names.indexOf(n) !== i);
  expect(dupes, `tags en doublon: ${JSON.stringify(dupes)}`).toHaveLength(0);
});

// D2 : l'API chat renvoie du JSON (jamais du HTML) — même sur erreur.
// Avant fix : une \Throwable (TypeError) échappait au catch (\Exception) -> page d'erreur
// Slim HTML -> "Unexpected token '<'... is not valid JSON" côté front.
test('D2: chat renvoie du JSON (pas du HTML) sur message', async ({ page }) => {
  const api = page.context().request;

  // Créer une conversation.
  const createRes = await api.post(`${BASE}/api/chat/conversations`);
  expect(createRes.ok(), `create conversation HTTP ${createRes.status()}`).toBeTruthy();
  const createJson = await createRes.json();
  const convId = createJson.conversation?.id ?? createJson.data?.conversation?.id;
  expect(convId, 'id conversation manquant').toBeTruthy();

  // Envoyer un message qui pouvait déclencher une erreur (-> JSON attendu, pas HTML).
  const msgRes = await api.post(`${BASE}/api/chat/conversations/${convId}/messages`, {
    data: { message: 'notaire' },
    headers: { 'Content-Type': 'application/json' },
  });
  const ct = msgRes.headers()['content-type'] ?? '';
  expect(ct, `content-type doit être JSON, reçu "${ct}"`).toContain('application/json');
  // Le corps doit être du JSON parsable (pas du HTML commençant par '<').
  const text = await msgRes.text();
  expect(text.startsWith('<'), 'corps HTML au lieu de JSON').toBeFalsy();

  // Nettoyage.
  try { await api.delete(`${BASE}/api/chat/conversations/${convId}`); } catch {}
});
