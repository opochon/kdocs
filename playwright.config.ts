import { defineConfig } from '@playwright/test';

const port = Number(process.env.GED_PORT ?? 8870);

export default defineConfig({
  testDir: './tests/e2e',
  outputDir: './var/test-results',
  timeout: 20_000,
  expect: { timeout: 5_000 },
  reporter: [['list'], ['json', { outputFile: 'var/e2e-latest.json' }]],
  use: {
    baseURL: `http://127.0.0.1:${port}`,
    viewport: { width: 1440, height: 900 },
    locale: 'fr-CH',
    actionTimeout: 5_000,
  },
  webServer: {
    command: `php -d opcache.enable=0 -S 127.0.0.1:${port} -t public public/index.php`,
    url: `http://127.0.0.1:${port}/sante`,
    reuseExistingServer: true,
    timeout: 15_000,
  },
});
