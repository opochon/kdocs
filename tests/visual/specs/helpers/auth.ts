import { type Page } from '@playwright/test';
import { BASE } from './personas';

export async function loginAs(page: Page, username: string, password = ''): Promise<void> {
  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
  await page.fill('#username', username);
  await page.fill('#password', password);
  await page.click('button[type="submit"]');
  await page.waitForLoadState('networkidle');
  if (/\/login\b/.test(page.url())) {
    throw new Error(`Login echoue pour "${username}" — lancer: php tools/eval-full.php --clean`);
  }
}
