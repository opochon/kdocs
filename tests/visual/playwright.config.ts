import { defineConfig, devices } from '@playwright/test';
import path from 'node:path';

// Serveur dev K-Docs : meme port que APP_URL pour que url() reste coherent.
const HOST = process.env.KDOCS_HOST ?? '127.0.0.1';
const PORT = process.env.KDOCS_PORT ?? '8765';
const ORIGIN = `http://${HOST}:${PORT}`;
const BASE_PATH = process.env.KDOCS_BASE_PATH ?? '/kdocs';
const BASE = `${ORIGIN}${BASE_PATH}`;
const repoRoot = path.resolve(__dirname, '..', '..');

export default defineConfig({
  testDir: './specs',
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: 0,
  workers: 1,
  reporter: [['list'], ['html', { open: 'never' }]],
  outputDir: './test-results',
  timeout: 30_000,
  expect: { timeout: 7_000 },
  globalSetup: require.resolve('./global-setup'),
  use: {
    // baseURL = origine seule : les routes prefixent le base path /kdocs
    // explicitement (un chemin commencant par / ignorerait un base path d'URL).
    baseURL: ORIGIN,
    storageState: path.join(__dirname, 'storageState.json'),
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
    viewport: { width: 1440, height: 900 },
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
  ],
  // Demarre le serveur PHP si /health n'est pas deja a 200 (sinon reutilise).
  webServer: {
    command: `php -S ${HOST}:${PORT} router.php`,
    cwd: repoRoot,
    url: `${BASE}/health`,
    reuseExistingServer: true,
    timeout: 30_000,
    stdout: 'ignore',
    stderr: 'pipe',
    // SMQ activé pour couvrir l'onglet Versions de la fiche (specs/smq-versions).
    // Rate limit relevé : la batterie complète dépasse 100 req/min (429 sinon).
    env: { SMQ_APP_ENABLED: 'true', RATE_LIMIT_MAX: '100000' },
  },
});
