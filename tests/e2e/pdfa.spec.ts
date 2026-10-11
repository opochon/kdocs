import { test, expect, type Browser, type Page } from '@playwright/test';
import { execFileSync, spawn, type ChildProcess } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { createServer } from 'node:net';
import path from 'node:path';

/**
 * Lot ged-pdfa-1 : la copie d'archivage PDF/A-2b vue par l'ÉCRAN.
 *
 * L'utilisateur se connecte, ouvre la fiche d'un document (PDF/A en attente), lance le rattrapage depuis
 * l'écran Application, clique les tranches jusqu'à « terminée », revient sur la fiche (PDF/A 2b ok) et
 * TÉLÉCHARGE la copie par le bouton. On assène sur ce que l'utilisateur VOIT (data-testid) et sur les
 * octets réellement téléchargés (en-tête PDF + métadonnées PDF/A), jamais sur une API interne.
 */

const RACINE = process.cwd();
type Prep = {
  compte: string;
  mot_de_passe: string;
  total: number;
  note_id: number;
  documents: { id: number; chemin: string; titre: string }[];
};

async function portLibre(): Promise<number> {
  return new Promise((ok, ko) => {
    const sonde = createServer();
    sonde.once('error', ko);
    sonde.listen(0, '127.0.0.1', () => {
      const adresse = sonde.address();
      sonde.close(() => ok(typeof adresse === 'object' && adresse !== null ? adresse.port : 0));
    });
  });
}

async function attente(base: string): Promise<void> {
  for (let i = 0; i < 100; i++) {
    try {
      if ((await fetch(`${base}/sante`)).ok) return;
    } catch {
      // le serveur PHP est encore en amorçage
    }
    await new Promise((r) => setTimeout(r, 100));
  }
  throw new Error('serveur pdfa indisponible');
}

async function connecter(page: Page, prep: Prep): Promise<void> {
  await page.goto('/connexion');
  await page.getByTestId('identifiant-connexion').fill(prep.compte);
  await page.getByTestId('mot-de-passe-connexion').fill(prep.mot_de_passe);
  await page.getByTestId('action-connexion').click({ timeout: 15_000 });
  await expect(page).toHaveURL(/\/a-traiter$/, { timeout: 15_000 });
}

/** Ouvre la fiche d'un document par le menu Bibliothèque, en cliquant la ligne puis « Ouvrir la fiche ». */
async function ouvrirFiche(page: Page, id: number): Promise<void> {
  await page.getByTestId('menu-bibliotheque').click({ timeout: 20_000 });
  await expect(page.getByTestId('ecran-bibliotheque')).toBeVisible();
  await page.getByTestId(`ligne-${id}`).getByRole('link').click({ timeout: 20_000 });
  await expect(page.getByTestId('ouvrir-fiche')).toBeVisible();
  await page.getByTestId('ouvrir-fiche').click({ timeout: 20_000 });
  await expect(page.getByTestId('ecran-document')).toBeVisible();
}

/** Clique les tranches jusqu'à « terminée », avec une borne qui dénonce un rattrapage bloqué. */
async function traiterJusquaLaFin(page: Page): Promise<void> {
  for (let tranche = 0; tranche < 8; tranche++) {
    if (await page.getByTestId('pdfa-terminee').isVisible().catch(() => false)) return;
    // Le POST lance la conversion Ghostscript d'une tranche : délai explicite (serveur php -S mono-processus).
    await page.getByTestId('pdfa-tranche').click({ timeout: 30_000 });
    await page.waitForLoadState('load', { timeout: 30_000 });
  }
  await expect(page.getByTestId('pdfa-terminee')).toBeVisible();
}

/**
 * Prépare un fonds isolé, démarre un serveur PHP de test sur un port libre, connecte l'utilisateur et lui
 * fait jouer `corps`. Serveur et contexte sont toujours fermés, même en cas d'échec.
 */
async function jouer(browser: Browser, corps: (page: Page, prep: Prep) => Promise<void>): Promise<void> {
  const fonds = path.join(RACINE, 'var', 'oracles', 'pdfa', 'documents');
  const audit = path.join(RACINE, 'var', 'oracles', 'pdfa', 'audit');
  const env = { ...process.env, DB_NAME: 'gedv2_test', APP_ENV: 'test', STORAGE_ROOT: fonds, SECURITE_DOSSIER: audit };
  const prep = JSON.parse(execFileSync('php', ['tests/e2e/preparer-pdfa.php'], { cwd: RACINE, env }).toString()) as Prep;

  const port = await portLibre();
  const base = `http://127.0.0.1:${port}`;
  const serveur: ChildProcess = spawn('php', ['-d', 'opcache.enable=0', '-S', `127.0.0.1:${port}`, '-t', 'public', 'public/index.php'],
    { cwd: RACINE, env, stdio: 'ignore' });

  try {
    await attente(base);
    const contexte = await browser.newContext({ baseURL: base, viewport: { width: 1440, height: 900 }, locale: 'fr-CH' });
    const page = await contexte.newPage();
    try {
      await connecter(page, prep);
      await corps(page, prep);
    } finally {
      await contexte.close();
    }
  } finally {
    serveur.kill();
  }
}

// Le parcours prépare un fonds isolé et lance une vraie conversion Ghostscript : le délai par défaut (20 s)
// est trop court quand la machine est chargée. On borne plus large, sans changer ce qui est vérifié.
test.describe.configure({ mode: 'serial', timeout: 90_000 });

test('Fiche : PDF/A en attente, rattrapage par l’écran, puis PDF/A 2b ok et copie téléchargeable', async ({ browser }) => {
  await jouer(browser, async (page, prep) => {
    const document = prep.documents[0];

    // Avant : la fiche dit honnêtement que la copie n'est pas encore produite.
    await ouvrirFiche(page, document.id);
    await expect(page.getByTestId('pdfa-etat')).toBeVisible();
    await expect(page.getByTestId('pdfa-statut-attente')).toBeVisible();

    // L'utilisateur lance le rattrapage depuis l'écran Application et traite les tranches.
    await page.getByTestId('menu-application').click();
    await expect(page.getByTestId('ecran-application')).toBeVisible();
    await page.getByTestId('pdfa-lancer').click();
    await expect(page.getByTestId('pdfa-progression')).toHaveText(`0 / ${prep.total}`);
    await traiterJusquaLaFin(page);
    await expect(page.getByTestId('pdfa-progression')).toHaveText(`${prep.total} / ${prep.total}`);

    // Retour sur la fiche : la copie est là, et le bouton la télécharge pour de vrai.
    await ouvrirFiche(page, document.id);
    await expect(page.getByTestId('pdfa-statut-ok')).toBeVisible();
    await expect(page.getByTestId('pdfa-telecharger')).toBeVisible();

    const [telechargement] = await Promise.all([
      page.waitForEvent('download'),
      page.getByTestId('pdfa-telecharger').click(),
    ]);
    const chemin = await telechargement.path();
    expect(chemin).toBeTruthy();
    const octets = readFileSync(chemin as string);
    expect(octets.subarray(0, 5).toString('latin1')).toBe('%PDF-');
    const texte = octets.toString('latin1');
    expect(texte).toMatch(/pdfaid:part=['"]2['"]/);
    expect(texte).toMatch(/pdfaid:conformance=['"]B['"]/);
    expect(texte).toContain('GTS_PDFA1');
  });
});

test('Fiche : un format sans copie (texte) est dit « sans objet », pas en échec', async ({ browser }) => {
  await jouer(browser, async (page, prep) => {
    // Le .txt n'est ni PDF ni image : la fiche le dit « sans objet », jamais « échec ».
    await ouvrirFiche(page, prep.note_id);
    await expect(page.getByTestId('pdfa-statut-sans-objet')).toBeVisible();
    expect(prep.total).toBe(2);
  });
});
