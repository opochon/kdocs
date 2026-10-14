import { test, expect, type Page } from '@playwright/test';
import { existsSync, readFileSync, rmdirSync, rmSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { connecter } from './connexion';

/**
 * Oracle E2E du lot ged-mfiles-permissions-1 — famille M-Files « permissions dynamiques ».
 *
 * Le parcours AGIT comme un administrateur réel : il ouvre le menu Administration → « Permissions »,
 * crée une règle nommée par le formulaire, vérifie qu'elle apparaît ACTIVE, la désactive puis la
 * réactive. Tout passe par l'écran (menu, champs, boutons) : aucun appel d'API interne. Compte de test
 * synthétique, base gedv2_test, aucune donnée réelle.
 *
 * La règle vise un état unique (`e2e-<horodatage>`) qu'aucun document du corpus ne porte : elle ne peut
 * donc masquer un document d'un autre parcours. Le fichier de règles du fonds est restauré à l'identique
 * en fin de test (le corpus versionné reste propre).
 */

const FICHIER_REGLES = resolve(process.cwd(), 'tests/corpus/apercu/documents/.ged/permissions.json');

function surveillerConsole(page: Page): string[] {
  const erreurs: string[] = [];
  page.on('console', (m) => { if (m.type() === 'error') erreurs.push(m.text()); });
  page.on('pageerror', (e) => erreurs.push(e.message));
  return erreurs;
}

test('un administrateur crée une règle de permission, la désactive et la réactive', async ({ page }) => {
  test.setTimeout(60_000);
  const erreurs = surveillerConsole(page);
  const nom = `Règle E2E ${Date.now()}`;
  const etat = `e2e-${Date.now()}`;
  const reglesAvant = existsSync(FICHIER_REGLES) ? readFileSync(FICHIER_REGLES) : null;

  try {
    await connecter(page);

    // Le menu Administration mène à l'écran des règles (barre à gauche sous le titre).
    await page.getByTestId('menu-permissions').click({ timeout: 20_000 });
    await expect(page).toHaveURL(/\/permissions$/);
    await expect(page.getByTestId('ecran-permissions')).toBeVisible();

    // Création par le formulaire : classe du fonds (si le fonds en porte une), un état unique, seuls
    // les « utilisateur » voient.
    const classe = (await page.getByTestId('regle-classe').locator('option').allTextContents())
      .map((v) => v.trim()).find((v) => v !== '' && v !== 'Toutes') ?? '';
    await page.getByTestId('regle-nom').fill(nom);
    if (classe !== '') {
      await page.getByTestId('regle-classe').selectOption({ label: classe });
    }
    await page.getByTestId('regle-etat').fill(etat);
    await page.getByTestId('regle-role').selectOption('utilisateur');
    await page.getByTestId('action-creer-regle').click({ timeout: 20_000 });

    await expect(page).toHaveURL(/\/permissions$/);
    await expect(page.locator('body')).toContainText(nom);

    // La règle apparaît active, avec sa cible et ses conditions.
    const section = page.locator('[data-testid^="regle-"]').filter({ hasText: nom }).first();
    await expect(section).toBeVisible();
    await expect(section).toContainText('active');
    await expect(section).toContainText(classe !== '' ? `classe ${classe}` : 'toutes classes');
    await expect(section).toContainText(`état ${etat}`);
    await expect(section).toContainText('role utilisateur');

    // Désactivation par le bouton de la règle : jamais de suppression, l'état change.
    const identifiant = (await section.getAttribute('data-testid'))?.replace('regle-', '') ?? '';
    expect(Number(identifiant)).toBeGreaterThan(0);
    await page.getByTestId(`action-basculer-${identifiant}`).click({ timeout: 20_000 });
    await expect(page).toHaveURL(/\/permissions$/);
    await expect(page.getByTestId(`regle-etat-${identifiant}`)).toHaveText('désactivée');

    // Réactivation : la règle revient active.
    await page.getByTestId(`action-basculer-${identifiant}`).click({ timeout: 20_000 });
    await expect(page.getByTestId(`regle-etat-${identifiant}`)).toHaveText('active');

    expect(erreurs, `erreurs console : ${erreurs.join(' | ')}`).toEqual([]);
  } finally {
    if (reglesAvant === null) {
      rmSync(FICHIER_REGLES, { force: true });
      rmSync(resolve(process.cwd(), 'tests/corpus/apercu/documents/.ged/.verrou'), { force: true });
      try {
        rmdirSync(resolve(process.cwd(), 'tests/corpus/apercu/documents/.ged'));
      } catch {
        // le dossier n'était pas vide (autre essai en parallèle) : on ne touche à rien
      }
    } else {
      writeFileSync(FICHIER_REGLES, reglesAvant);
    }
  }
});
