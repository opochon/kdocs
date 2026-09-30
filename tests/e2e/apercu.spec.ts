import { test, expect, type Page } from '@playwright/test';
import { connecter } from './connexion';

/**
 * Oracle du lot V2-05a — aperçu réel : 3 pages rendues et lisibles, page 2 au clavier, zoom et rotation,
 * 0 erreur console ; images (png/jpg/tiff) et bureautique (message nommé quand LibreOffice manque) ;
 * la miniature est servie en PNG. Les budgets de temps (première page PDF) vivent dans `budget-apercu.spec.ts`.
 */
test.beforeEach(async ({ page }) => { await connecter(page); });

function surveillerConsole(page: Page): string[] {
  const erreurs: string[] = [];
  page.on('console', (m) => { if (m.type() === 'error') erreurs.push(m.text()); });
  page.on('pageerror', (e) => erreurs.push(e.message));
  return erreurs;
}

// Le budget « première page PDF < 1 s » vit dans `budget-apercu.spec.ts` (projet « budgets », serveur dédié) :
// mesuré dans la file du serveur mono-processus partagé par les workers, il tombait au hasard (PERF-2).

test('aperçu PDF : la page 2 est atteignable au clavier, zoom et rotation répondent', async ({ page }) => {
  const erreurs = surveillerConsole(page);
  await page.goto('/documents/204/apercu');
  await page.waitForSelector('[data-testid="visionneuse-canvas-3"][data-rendu="1"]', { state: 'visible' });

  await page.getByTestId('visionneuse-pages').click();
  await page.keyboard.press('PageDown');
  await expect(page.getByTestId('visionneuse-page')).toContainText('page 2');

  await page.keyboard.press('PageDown');
  await expect(page.getByTestId('visionneuse-page')).toContainText('page 3');

  await page.getByTestId('visionneuse-zoom-avant').click();
  await expect(page.getByTestId('visionneuse-zoom')).not.toHaveText('100 %');
  await page.getByTestId('visionneuse-rotation').click();
  await expect(page.getByTestId('visionneuse')).toHaveAttribute('data-rotation', '90');

  expect(erreurs, 'aucune erreur console').toEqual([]);
});

test('aperçu PDF : se tient en clair comme en sombre', async ({ page }) => {
  await page.emulateMedia({ colorScheme: 'dark' });
  await page.goto('/documents/204/apercu');
  await page.waitForSelector('[data-testid="visionneuse-canvas-1"][data-rendu="1"]', { state: 'visible' });
  const fond = await page.evaluate(() => getComputedStyle(document.body).backgroundColor);
  expect(fond).not.toBe('rgba(0, 0, 0, 0)');
  await page.screenshot({ path: 'var/captures/apercu-pdf-sombre.png' });
});

test('miniature : servie hors requête en PNG', async ({ page }) => {
  const rep = await page.request.get('/documents/204/miniature');
  expect(rep.status()).toBe(200);
  expect(rep.headers()['content-type'] ?? '').toContain('image/png');
  const octets = await rep.body();
  expect(octets.subarray(0, 4).toString('hex')).toBe('89504e47');
});

test('images : png, jpg et tiff s’affichent sans téléchargement', async ({ page }) => {
  for (const id of ['206', '207', '208']) {
    const erreurs = surveillerConsole(page);
    await page.goto(`/documents/${id}/apercu`);
    await expect(page.getByTestId('visionneuse-image')).toBeVisible();
    expect(erreurs, `aucune erreur console pour l’image ${id}`).toEqual([]);
  }
});

test('bureautique : document nommé, jamais un écran muet', async ({ page }) => {
  await page.goto('/documents/209/apercu');
  const categorie = await page.getByTestId('visionneuse').getAttribute('data-categorie');
  if (categorie === 'bureautique') {
    await expect(page.getByTestId('visionneuse-message')).toContainText('aperçu indisponible : LibreOffice absent');
  } else {
    await expect(page.getByTestId('visionneuse-canvas-1')).toBeVisible();
  }
});

test('aperçu : un document inconnu est nommé, jamais un 500 muet', async ({ page }) => {
  const rep = await page.goto('/documents/999999/apercu');
  expect(rep?.status()).toBe(404);
  await expect(page.getByTestId('erreur-reference')).not.toBeEmpty();
});
