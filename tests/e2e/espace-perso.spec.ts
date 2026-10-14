import { test, expect, type Page } from '@playwright/test';
import { execFileSync, spawn } from 'node:child_process';
import { createServer } from 'node:net';
import fs from 'node:fs';
import path from 'node:path';

/**
 * Lot ged-mfiles-espace-1 : espace personnel (favoris, récemment consultés) et liens de partage.
 *
 * Parcours isolé : son propre fonds et son propre compte, serveur dédié comme les parcours V2-12d.
 * Le favori et la consultation sont des données d'index (registre `.ged/`) : la fiche ne déplace rien.
 * Un lien de partage n'ouvre QUE le document partagé, et se refuse nommément après révocation.
 */

const RACINE = process.cwd();
type Prep = { compte: string; mot_de_passe: string; document: number; cible: number };

async function portLibre(): Promise<number> {
  return new Promise((ok, ko) => {
    const s = createServer();
    s.once('error', ko);
    s.listen(0, '127.0.0.1', () => {
      const a = s.address();
      const p = typeof a === 'object' && a ? a.port : 0;
      s.close(() => ok(p));
    });
  });
}

async function attente(base: string): Promise<void> {
  for (let i = 0; i < 100; i++) {
    try { if ((await fetch(base + '/sante')).ok) return; } catch { /* serveur pas encore prêt */ }
    await new Promise((r) => setTimeout(r, 100));
  }
  throw new Error('serveur espace-perso indisponible');
}

async function avecEspace<T>(fn: (base: string, prep: Prep) => Promise<T>): Promise<T> {
  const fonds = path.join(RACINE, 'var', 'oracles', 'espace-perso', 'documents');
  const audit = path.join(RACINE, 'var', 'oracles', 'espace-perso', 'securite');
  const env = { ...process.env, DB_NAME: 'gedv2_test', APP_ENV: 'test', STORAGE_ROOT: fonds, SECURITE_DOSSIER: audit };
  const prep = JSON.parse(execFileSync('php', ['tests/e2e/preparer-espace-perso.php'], { cwd: RACINE, env }).toString()) as Prep;
  const port = await portLibre();
  const base = `http://127.0.0.1:${port}`;
  const serveur = spawn('php', ['-d', 'opcache.enable=0', '-S', `127.0.0.1:${port}`, '-t', 'public', 'public/index.php'], { cwd: RACINE, env, stdio: 'ignore' });
  try {
    await attente(base);
    return await fn(base, prep);
  } finally {
    serveur.kill();
  }
}

async function connecter(page: Page, prep: Prep): Promise<void> {
  await page.goto('/connexion');
  await page.getByTestId('identifiant-connexion').fill(prep.compte);
  await page.getByTestId('mot-de-passe-connexion').fill(prep.mot_de_passe);
  await page.getByTestId('action-connexion').click({ timeout: 15_000 });
  await expect(page).toHaveURL(/\/a-traiter$/, { timeout: 15_000 });
}

function surveillerConsole(page: Page): string[] {
  const erreurs: string[] = [];
  page.on('console', (m) => { if (m.type() === 'error') erreurs.push(m.text()); });
  page.on('pageerror', (e) => erreurs.push(e.message));
  return erreurs;
}

test.describe.configure({ mode: 'serial' });

test('Favoris et récents, bureau : épingler une fiche, la retrouver dans Mon espace, la retirer', async ({ browser }) => {
  await avecEspace(async (base, prep) => {
    const c = await browser.newContext({ baseURL: base });
    const page = await c.newPage();
    const erreurs = surveillerConsole(page);
    try {
      await connecter(page, prep);
      await page.goto(`/documents/${prep.document}`);
      await expect(page.getByTestId('ecran-document')).toBeVisible();

      // Épingler : la fiche revient sur elle-même et propose désormais le retrait.
      await page.getByTestId('favori-ajouter').click();
      await expect(page).toHaveURL(new RegExp(`/documents/${prep.document}$`));
      await expect(page.getByTestId('favori-retirer')).toBeVisible();

      // « Mon espace » (menu) liste le favori ET la consultation enregistrée à l'ouverture de la fiche.
      await page.getByTestId('menu-favoris').click({ timeout: 15_000 });
      await expect(page.getByTestId('ecran-espace-perso')).toBeVisible();
      await expect(page.getByTestId(`favori-document-${prep.document}`)).toBeVisible();
      await expect(page.getByTestId(`recent-document-${prep.document}`)).toBeVisible();
      await expect(page.getByTestId('favoris-vide')).toHaveCount(0);

      // Le favori vit dans le registre portable du fonds, pas dans une copie.
      expect(fs.existsSync(path.join(RACINE, 'var', 'oracles', 'espace-perso', 'documents', '.ged', 'favoris.json'))).toBe(true);

      // Retirer : la liste se vide, la trace reste dans le registre (marqueur daté).
      await page.getByTestId(`favori-retirer-${prep.document}`).click();
      await expect(page.getByTestId('favoris-vide')).toBeVisible();
      const registre = fs.readFileSync(path.join(RACINE, 'var', 'oracles', 'espace-perso', 'documents', '.ged', 'favoris.json'), 'utf8');
      expect(registre).toContain('retire_le');

      expect(erreurs).toEqual([]);
    } finally {
      await c.close();
    }
  });
});

test('Liens de partage, téléphone : créer, lister, télécharger par le lien, puis révoquer', async ({ browser }) => {
  await avecEspace(async (base, prep) => {
    const c = await browser.newContext({ baseURL: base, viewport: { width: 390, height: 844 } });
    const page = await c.newPage();
    const erreurs = surveillerConsole(page);
    try {
      await connecter(page, prep);
      await page.goto(`/documents/${prep.document}`);
      await page.getByTestId('onglet-partager').click();
      await page.getByTestId('partage-expiration').fill('2099-01-01');
      await page.getByTestId('partage-externe-envoyer').click();
      await expect(page).toHaveURL(new RegExp(`/documents/${prep.document}$`));
      const lien = await page.getByTestId('partage-lien').locator('code').textContent();
      expect(lien).toMatch(/^\/partages\/[A-Za-z0-9_-]{40,}$/);

      // Le jeton n'est montré qu'une fois : un rechargement ne le remet pas dans l'URL.
      await page.reload();
      await expect(page.getByTestId('partage-lien')).toHaveCount(0);

      // Liste des liens de partage (le lien mène à l'écran dédié).
      await page.goto('/liens-partages');
      await expect(page.getByTestId('ecran-liens-partages')).toBeVisible();
      await expect(page.locator('[data-testid^="lien-partage-document-"]')).toHaveCount(1);

      // Un visiteur externe ouvre le lien : il ne voit QUE ce document, téléchargeable.
      const externe = await browser.newContext({ baseURL: base });
      const lecteur = await externe.newPage();
      await lecteur.goto(lien!);
      await expect(lecteur.getByTestId('partage-externe')).toBeVisible();
      await expect(lecteur.getByTestId('partage-telecharger')).toBeVisible();
      const texte = (await lecteur.locator('a,form,button').allTextContents()).join(' ');
      expect(texte).not.toContain('Modifier');
      await externe.close();

      // Révocation depuis la liste, puis le lien se refuse en se nommant.
      await page.locator('[data-testid^="lien-partage-revoquer-"]').click();
      await expect(page.getByTestId('liens-partages-vide')).toBeVisible();
      const apres = await page.request.get(base + lien!);
      expect(apres.status()).toBe(403);
      const corps = await apres.text();
      expect(corps).toContain('invalide, expiré ou révoqué');

      // Le secret n'est jamais écrit en clair dans le journal d'audit.
      const audit = fs.readFileSync(path.join(RACINE, 'var', 'oracles', 'espace-perso', 'securite', 'audit.ndjson'), 'utf8');
      expect(audit).toContain('partage_externe_cree');
      expect(audit).not.toContain(lien!.slice('/partages/'.length));

      expect(erreurs).toEqual([]);
    } finally {
      await c.close();
    }
  });
});
