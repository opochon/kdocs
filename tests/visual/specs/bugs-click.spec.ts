import { test, expect } from '@playwright/test';

// Verification des bugs signales (clics / navigation / submit) via evenements natifs Playwright.
// But : distinguer bug reel d'un artefact de l'environnement d'automatisation du testeur.
// Concerne : creation de tache (POST), lien "Creer une tache", liens sidebar admin (Diagnostic).

const BASE_PATH = process.env.KDOCS_BASE_PATH ?? '/kdocs';
const HOST = process.env.KDOCS_HOST ?? '127.0.0.1';
const PORT = process.env.KDOCS_PORT ?? '8765';
const BASE = `http://${HOST}:${PORT}${BASE_PATH}`;

test.describe('Bug B — clics / navigation / submit (evenements natifs)', () => {

  test('lien sidebar admin Diagnostic reagit au clic (navigation native)', async ({ page }) => {
    await page.goto(`${BASE}/admin`, { waitUntil: 'domcontentloaded' });
    // Le lien Diagnostic pointe vers /admin/diagnostic.
    const diagLink = page.locator('a.ds-nav-item', { hasText: 'Diagnostic' }).first();
    await expect(diagLink).toBeVisible();
    await diagLink.click();
    await page.waitForLoadState('domcontentloaded');
    expect(page.url(), 'doit naviguer vers /admin/diagnostic').toContain('/admin/diagnostic');
    // La page diagnostic doit se charger sans erreur PHP fatale.
    const html = await page.content();
    for (const marker of ['Fatal error', 'Parse error', 'Uncaught', 'Call to undefined']) {
      expect(html, `marqueur "${marker}"`).not.toContain(marker);
    }
  });

  test('lien "Creer une tache" (liste) navigue vers le formulaire', async ({ page }) => {
    await page.goto(`${BASE}/tasks`, { waitUntil: 'domcontentloaded' });
    const createLink = page.locator('a', { hasText: 'Créer une tâche' }).first();
    await expect(createLink).toBeVisible();
    await createLink.click();
    await page.waitForLoadState('domcontentloaded');
    expect(page.url()).toContain('/tasks/create');
    // Le formulaire de creation est present avec son bouton submit.
    await expect(page.locator('form[action]')).toBeVisible();
    await expect(page.locator('button[type="submit"]', { hasText: 'Créer la tâche' })).toBeVisible();
  });

  test('bouton "Creer la tache" envoie un POST et cree la tache', async ({ page }) => {
    // Surveiller les requetes pour confirmer qu'un POST /tasks/create part.
    const postRequests: string[] = [];
    page.on('request', (req) => {
      if (req.method() === 'POST' && req.url().includes('/tasks/create')) {
        postRequests.push(req.url());
      }
    });

    await page.goto(`${BASE}/tasks/create`, { waitUntil: 'domcontentloaded' });

    // Saisie native (trusted events) — confirme que le clavier fonctionne.
    const title = `Test auto ${Date.now()}`;
    await page.fill('#title', title);
    await page.fill('#description', 'Créé par le test Playwright de vérification des clics.');

    // Soumettre par clic natif sur le bouton.
    await page.click('button[type="submit"]', { hasText: 'Créer la tâche' });
    await page.waitForLoadState('networkidle');

    // 1. Un POST a bien ete emis vers /tasks/create.
    expect(postRequests, 'aucun POST /tasks/create emis').not.toEqual([]);
    expect(postRequests.length).toBeGreaterThanOrEqual(1);

    // 2. Apres submit, on ne reste pas sur le formulaire (la tache a ete creee).
    expect(page.url(), 'reste sur /tasks/create (creation echouee)').not.toMatch(/\/tasks\/create$/);

    // 3. La tache apparait dans la liste.
    await page.goto(`${BASE}/tasks`, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('text=' + title).first()).toBeVisible({ timeout: 10_000 });
  });
});
