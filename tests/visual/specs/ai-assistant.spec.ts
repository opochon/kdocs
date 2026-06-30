import { test, expect } from '@playwright/test';

// Assistant IA — spec Playwright (T-IA-INF, partie UI).
// Verifie le fix de gating : la page /search?mode=chat affiche #chat-input des
// lors qu'un fournisseur IA est disponible via la cascade (Infomaniak > Claude >
// Ollama), et PAS uniquement quand Claude est configure (regression : l'Assistant
// IA etait inutilisable avec Infomaniak actif + Claude off).
//
// La logique metier (count-all, cascade, fallback v2/v1) est couverte hermetiquement
// par tests/Unit/Services/{AiCascadeInfomaniakTest,NaturalLanguageQueryCountTest,
// InfomaniakAIServiceTest}.php — pas de duplication flaky (appels IA reseau) ici.

const BASE_PATH = process.env.KDOCS_BASE_PATH ?? '/kdocs';
const HOST = process.env.KDOCS_HOST ?? '127.0.0.1';
const PORT = process.env.KDOCS_PORT ?? '8765';
const BASE = `http://${HOST}:${PORT}${BASE_PATH}`;

test.describe('Assistant IA — UI /search?mode=chat', () => {
  test('le champ #chat-input est rendu (gating cascade, pas Claude-only)', async ({ page }) => {
    await page.goto(`${BASE}/search?mode=chat`, { waitUntil: 'domcontentloaded' });

    // Avec Infomaniak actif (cascade), la page ne doit PLUS afficher le bloc
    // « API Claude non configurée » (l'ancien gating) : le formulaire est rendu.
    await expect(page.locator('#chat-input')).toBeVisible();
    await expect(page.locator('#chat-form')).toBeVisible();
    await expect(page.locator('#send-btn')).toBeVisible();

    // Le titre de page « Assistant IA » est present (header).
    await expect(page.getByRole('heading', { name: 'Assistant IA' })).toBeVisible();

    // Et le bloc legacy « API Claude non configurée » est absent.
    await expect(page.getByText('API Claude non configurée')).toHaveCount(0);
  });
});
