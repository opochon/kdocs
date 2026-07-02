import { expect, type Page } from '@playwright/test';
import { ERROR_MARKERS } from './personas';

/** Oracle commun : pas de marqueur d'erreur PHP dans le HTML rendu. */
export async function expectNoPhpError(page: Page): Promise<void> {
  const html = await page.content();
  for (const marker of ERROR_MARKERS) {
    expect(html, `marqueur "${marker}"`).not.toContain(marker);
  }
}
