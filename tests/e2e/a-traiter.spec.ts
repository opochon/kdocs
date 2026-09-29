import { test, expect, type Browser, type Page } from '@playwright/test';
import { execFileSync, spawn, type ChildProcess } from 'node:child_process';
import { createServer } from 'node:net';
import fs from 'node:fs';
import path from 'node:path';
import { connecter } from './connexion';

/**
 * Oracle du lot V2-03-écran — l'écran « À traiter » branché sur les VRAIES données.
 *
 * Le fonds de test est déposé par `tests/oracles/interface/preparer-a-traiter.php` via `DepotFichiers`
 * dans un dossier d'essai (`var/oracles/interface/documents`), JAMAIS dans `tests/corpus`. Pour que
 * l'application lise ce fonds sans dépendre de la racine du serveur Playwright, le parcours démarre son
 * propre serveur PHP avec ce `STORAGE_ROOT` — et se connecte avec `tests/e2e/connexion.ts`.
 *
 * Preuve : trois pièces validées au clavier (Entrée), sans souris, puis la quatrième pièce du même
 * émetteur arrive avec la source « habitude » (l'écran n'a jamais classé seul : seuls les choix humains
 * enseignent).
 */

const RACINE = process.cwd();
const DOSSIER_ORACLE = path.join(RACINE, 'var', 'oracles', 'interface');
const FONDS = path.join(DOSSIER_ORACLE, 'documents');

type Preparation = { emetteur: string; ids: number[] };

let serveur: ChildProcess | undefined;
let base = '';
let prep: Preparation;

async function portLibre(): Promise<number> {
  return new Promise((resolve, reject) => {
    const sonde = createServer();
    sonde.once('error', reject);
    sonde.listen(0, '127.0.0.1', () => {
      const adresse = sonde.address();
      const port = typeof adresse === 'object' && adresse !== null ? adresse.port : 0;
      sonde.close(() => resolve(port));
    });
  });
}

async function attendreSante(url: string): Promise<void> {
  for (let essai = 0; essai < 100; essai++) {
    try {
      const reponse = await fetch(`${url}/sante`);
      if (reponse.ok) return;
    } catch {
      // le serveur PHP est encore en amorçage
    }
    await new Promise((resoudre) => setTimeout(resoudre, 100));
  }
  throw new Error('le serveur de l’oracle « À traiter » n’a pas démarré');
}

function preparer(): Preparation {
  const sortie = execFileSync('php', ['tests/oracles/interface/preparer-a-traiter.php'], {
    cwd: RACINE,
    env: { ...process.env, DB_NAME: 'gedv2_test', APP_ENV: 'test', STORAGE_ROOT: FONDS },
  }).toString();
  return JSON.parse(sortie) as Preparation;
}

function classerPiece4(): { source: string; type: string; valide: boolean; emetteur: string } {
  const sortie = execFileSync('php', ['tests/oracles/interface/classer-piece-4.php'], {
    cwd: RACINE,
    env: { ...process.env, DB_NAME: 'gedv2_test', APP_ENV: 'test', STORAGE_ROOT: FONDS },
  }).toString();
  return JSON.parse(sortie);
}

async function pageConnectee(browser: Browser): Promise<{ page: Page; fermer: () => Promise<void> }> {
  const contexte = await browser.newContext({ baseURL: base, viewport: { width: 1440, height: 900 }, locale: 'fr-CH' });
  const page = await contexte.newPage();
  await connecter(page);

  return { page, fermer: () => contexte.close() };
}

test.describe.configure({ mode: 'serial' });

test.beforeAll(async () => {
  fs.mkdirSync(DOSSIER_ORACLE, { recursive: true });
  const port = await portLibre();
  base = `http://127.0.0.1:${port}`;
  serveur = spawn('php', ['-d', 'opcache.enable=0', '-S', `127.0.0.1:${port}`, '-t', 'public', 'public/index.php'], {
    cwd: RACINE,
    env: { ...process.env, DB_NAME: 'gedv2_test', APP_ENV: 'test', STORAGE_ROOT: FONDS },
    stdio: 'ignore',
  });
  await attendreSante(base);
});

test.afterAll(() => {
  serveur?.kill();
});

test.beforeEach(() => {
  prep = preparer();
});

test('l’écran montre le fonds réel, la proposition du Classeur et l’aperçu', async ({ browser }) => {
  const { page, fermer } = await pageConnectee(browser);
  try {
    await page.goto('/a-traiter');

    await expect(page.getByTestId('ecran-a-traiter')).toBeVisible();
    await expect(page.getByTestId('bilan-a-controler').locator('.valeur')).toHaveText(String(prep.ids.length));
    await expect(page.getByTestId('file-a-traiter')).toContainText('Facture oracle 1');
    await expect(page.getByTestId(`file-item-${prep.ids[0]}`)).toHaveAttribute('aria-current', 'true');

    // « Classement proposé » pré-rempli par ce que le Classeur a proposé : source (règle) et raison.
    await expect(page.getByTestId('formulaire-validation').locator('.source').first()).toContainText('Règle');
    await expect(page.getByTestId('raison-controle')).toContainText('confiance IA insuffisante');
    await expect(page.getByTestId('champ-emetteur')).toHaveValue(prep.emetteur);
    await expect(page.getByTestId('champ-type')).toHaveValue('Facture');

    // Aperçu réel : la visionneuse du secteur Aperçu, pas la page de papier.
    await expect(page.getByTestId('apercu')).toHaveAttribute('data-src', /\/documents\/\d+\/apercu\/fichier/);
    await expect(page.getByTestId('apercu')).toHaveAttribute('data-categorie', 'pdf');
  } finally {
    await fermer();
  }
});

test('valider trois pièces au clavier enseigne l’habitude à la quatrième', async ({ browser }) => {
  const { page, fermer } = await pageConnectee(browser);
  try {
    // Les trois pièces sont dans la file, du plus ancien au plus récent.
    for (const id of prep.ids) {
      await expect(page.getByTestId(`file-item-${id}`)).toBeVisible();
    }

    // Trois validations au clavier, sans souris : Entrée sur chacune, dans l'ordre de la file.
    for (const id of prep.ids) {
      await page.goto(`/a-traiter?doc=${id}`);
      await expect(page.getByTestId(`file-item-${id}`)).toHaveAttribute('aria-current', 'true');
      await page.keyboard.press('Enter');
      // La pièce validée quitte la file (POST suivi du rendu de la suivante).
      await expect(page.getByTestId(`file-item-${id}`)).toHaveCount(0);
    }
    await expect(page.getByTestId('file-a-traiter').locator('a')).toHaveCount(0);

    // La quatrième pièce du même émetteur est classée par l'habitude formée par les choix humains.
    const quatrieme = classerPiece4();
    expect(quatrieme.emetteur).toBe(prep.emetteur);
    expect(quatrieme.source).toBe('habitude');
    expect(quatrieme.type).toBe('Facture');
    expect(quatrieme.valide).toBe(true);
  } finally {
    await fermer();
  }
});
