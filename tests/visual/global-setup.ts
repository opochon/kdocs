import { chromium, type FullConfig } from '@playwright/test';
import path from 'node:path';

// Login une seule fois, etat de session reutilise par tous les tests.
const HOST = process.env.KDOCS_HOST ?? '127.0.0.1';
const PORT = process.env.KDOCS_PORT ?? '8765';
const BASE = `http://${HOST}:${PORT}/kdocs`;
const USER = process.env.KDOCS_USER ?? 'root';
const PASS = process.env.KDOCS_PASS ?? '';

export default async function globalSetup(_config: FullConfig) {
  const browser = await chromium.launch();
  const page = await browser.newPage();

  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
  await page.fill('#username', USER);
  await page.fill('#password', PASS);
  await page.click('button[type="submit"]');
  await page.waitForLoadState('networkidle');

  // Echec d'auth => toujours sur /login.
  if (/\/login\b/.test(page.url())) {
    await browser.close();
    throw new Error(
      `Login echoue pour "${USER}" sur ${BASE}/login. ` +
        'Verifier APP_DEBUG / compte root, ou definir KDOCS_USER / KDOCS_PASS.',
    );
  }

  await page.context().storageState({ path: path.join(__dirname, 'storageState.json') });
  await browser.close();
}
