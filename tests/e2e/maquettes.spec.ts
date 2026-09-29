import { test, expect, type Page } from '@playwright/test';

/**
 * Contrat visuel des six écrans maîtres (lot MAQUETTES). Chaque écran : répond, se désigne, ne produit
 * aucune erreur console, tient dans la fenêtre sans défilement horizontal, et laisse une capture de référence
 * (claire et sombre) dans var/captures pour la revue humaine.
 */
const ecrans = [
  { nom: 'a-traiter', url: '/a-traiter', testid: 'ecran-a-traiter' },
  { nom: 'bibliotheque', url: '/bibliotheque', testid: 'ecran-bibliotheque' },
  { nom: 'recherche', url: '/recherche?q=maintenance', testid: 'ecran-recherche' },
  { nom: 'fiche', url: '/documents/201', testid: 'ecran-document' },
  { nom: 'workflows', url: '/workflows', testid: 'ecran-workflows' },
  { nom: 'application', url: '/application', testid: 'ecran-application' },
];

function surveillerConsole(page: Page): string[] {
  const erreurs: string[] = [];
  page.on('console', (m) => { if (m.type() === 'error') erreurs.push(m.text()); });
  page.on('pageerror', (e) => erreurs.push(e.message));
  return erreurs;
}

for (const e of ecrans) {
  test(`écran ${e.nom} : répond, sans erreur, sans défilement horizontal`, async ({ page }) => {
    const erreurs = surveillerConsole(page);
    const debut = Date.now();
    const rep = await page.goto(e.url);
    expect(rep?.status()).toBe(200);
    await expect(page.getByTestId(e.testid)).toBeVisible();
    expect(Date.now() - debut, 'écran en moins de 1,5 s').toBeLessThan(1500);
    const deborde = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
    expect(deborde, 'aucun défilement horizontal de la page').toBe(false);
    expect(erreurs, 'aucune erreur console').toEqual([]);
    await page.screenshot({ path: `var/captures/${e.nom}-clair.png` });
    await page.emulateMedia({ colorScheme: 'dark' });
    await page.screenshot({ path: `var/captures/${e.nom}-sombre.png` });
  });
}

test('menu : chaque entrée mène à son écran', async ({ page }) => {
  await page.goto('/a-traiter');
  for (const [menu, ecran] of [['menu-bibliotheque', 'ecran-bibliotheque'], ['menu-recherche', 'ecran-recherche'],
    ['menu-workflows', 'ecran-workflows'], ['menu-application', 'ecran-application'], ['menu-a-traiter', 'ecran-a-traiter']]) {
    await page.getByTestId(menu).click();
    await expect(page.getByTestId(ecran)).toBeVisible();
  }
});

test('à traiter : le bilan est visible, le dossier d’ingestion jamais', async ({ page }) => {
  await page.goto('/a-traiter');
  await expect(page.getByTestId('bilan-auto')).toContainText('Classés automatiquement');
  await expect(page.getByTestId('bilan-a-controler')).toContainText('À contrôler');
  await expect(page.getByTestId('bilan-en-cours')).toContainText('En cours de lecture');
  await expect(page.locator('body')).not.toContainText(/consume|storage\//i);
});

test('à traiter : Entrée et → passent au document suivant', async ({ page }) => {
  await page.goto('/a-traiter?doc=101');
  await page.locator('body').click({ position: { x: 5, y: 5 } });
  await page.keyboard.press('ArrowRight');
  await expect(page).toHaveURL(/doc=102/);
  await page.keyboard.press('Enter');
  await expect(page).toHaveURL(/doc=103/);
  await expect(page.getByTestId('action-valider')).toBeInViewport();
});

test('recherche : Ctrl+K depuis n’importe quel écran, terme surligné', async ({ page }) => {
  await page.goto('/bibliotheque');
  await page.keyboard.press('Control+k');
  await expect(page.getByTestId('recherche-globale-champ')).toBeFocused();
  await page.keyboard.type('maintenance');
  await page.keyboard.press('Enter');
  await expect(page.getByTestId('ecran-recherche')).toBeVisible();
  await expect(page.locator('mark').first()).toHaveText(/maintenance/i);
});

test('fiche : les onglets changent le panneau', async ({ page }) => {
  await page.goto('/documents/201');
  await page.getByTestId('onglet-versions').click();
  await expect(page.getByTestId('panneau-versions')).toBeVisible();
  await expect(page.getByTestId('panneau-metadonnees')).toBeHidden();
});

test('erreur : une page inconnue est nommée, jamais muette', async ({ page }) => {
  const rep = await page.goto('/nulle-part');
  expect(rep?.status()).toBe(404);
  await expect(page.getByTestId('erreur-etape')).toContainText('GET /nulle-part');
  await expect(page.getByTestId('erreur-reference')).not.toBeEmpty();
});
