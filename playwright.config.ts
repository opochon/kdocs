import { defineConfig } from '@playwright/test';

// Port propre aux tests (8870 = serveur de développement) : les tests ne visent jamais un serveur lancé avec le .env réel.
const port = Number(process.env.GED_PORT_TESTS ?? 8871);
// Les budgets de temps se mesurent sur LEUR serveur : `php -S` est mono-processus (et `PHP_CLI_SERVER_WORKERS`
// ne forke pas sous Windows), donc une requête d'un worker parallèle fait la queue devant la mesure et la fausse.
const portBudgets = Number(process.env.GED_PORT_BUDGETS ?? 8872);
const envTests = { DB_NAME: 'gedv2_test', APP_ENV: 'test', STORAGE_ROOT: 'tests/corpus/apercu/documents' };

export default defineConfig({
  testDir: './tests/e2e',
  // `gedv2_test` est l'unique base de test admise. Chaque préparation remet ses tables de fonds à
  // zéro sous verrou ; un worker unique garde cet état cohérent jusqu'à la fin de son parcours.
  workers: 1,
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
  webServer: [
    {
      command: `php -d opcache.enable=0 -S 127.0.0.1:${port} -t public public/index.php`,
      url: `http://127.0.0.1:${port}/sante`,
      reuseExistingServer: false,
      // Environnement de TEST imposé, quel que soit le .env local.
      env: envTests,
      timeout: 15_000,
    },
    {
      // Serveur dédié aux budgets de temps : les parcours parallèles ne font plus la queue devant la mesure.
      command: `php -d opcache.enable=0 -S 127.0.0.1:${portBudgets} -t public public/index.php`,
      url: `http://127.0.0.1:${portBudgets}/sante`,
      reuseExistingServer: false,
      env: envTests,
      timeout: 15_000,
    },
  ],
  projects: [
    // Les parcours d'écran, en parallèle : c'est le pool normal de la suite. La couche 5 (personas) a son
    // propre projet (couche 5 du harness) : elle démarre son serveur et son fonds, et se rejoue seule.
    { name: 'parcours', testIgnore: [/budget-.*\.spec\.ts$/, /personas[\\/][^\\/]*\.spec\.ts$/] },
    // Les budgets de temps (PERF-2), isolés : un serveur à eux et un seul worker, donc des mesures qui ne
    // tombent plus au hasard selon qui occupait le serveur mono-processus au même moment.
    {
      name: 'budgets',
      testMatch: /budget-.*\.spec\.ts$/,
      workers: 1,
      use: { baseURL: `http://127.0.0.1:${portBudgets}` },
    },
    // Couche 5 du harness (Personas) : chaque persona joue ses gestes depuis l'accueil, serveur et fonds
    // dédiés. Un seul worker : le fonds de test `gedv2_test` reste cohérent entre la préparation et l'écran.
    {
      name: 'personas',
      testMatch: /personas[\\/][^\\/]*\.spec\.ts$/,
      workers: 1,
    },
  ],
});
