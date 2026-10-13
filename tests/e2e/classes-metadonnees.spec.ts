import { test, expect, type Page, type Browser } from '@playwright/test';
import { execFileSync, spawn, type ChildProcess } from 'node:child_process';
import { createServer } from 'node:net';
import path from 'node:path';

/**
 * Parcours « Classes & métadonnées » (famille M-Files nº 1, lot ged-mfiles-classes-1).
 *
 * L'écran agit comme un vrai utilisateur : on clique les entrées de menu, les liens de classe et les
 * boutons réels, on lit ce qui est AFFICHÉ. Le fonds est synthétique (var/oracles/classes-metadonnees)
 * et le serveur est isolé ; aucune API interne n'est appelée à la place de l'écran.
 */
const RACINE = process.cwd();
const FONDS = path.join(RACINE, 'var', 'oracles', 'classes-metadonnees', 'documents');
const ENV = { DB_NAME: 'gedv2_test', APP_ENV: 'test', STORAGE_ROOT: FONDS };

type Preparation = { fonds: string; document: number; chemin: string; admin: string; mot_de_passe: string };
let serveur: ChildProcess | undefined;
let base = '';
let prep: Preparation;

async function portLibre(): Promise<number> {
  return new Promise((ok, ko) => { const s = createServer(); s.once('error', ko); s.listen(0, '127.0.0.1', () => { const a = s.address(); const p = typeof a === 'object' && a ? a.port : 0; s.close(() => ok(p)); }); });
}
async function attente(url: string): Promise<void> {
  for (let i = 0; i < 100; i++) { try { if ((await fetch(`${url}/sante`)).ok) return; } catch {} await new Promise((r) => setTimeout(r, 100)); }
  throw new Error('serveur classes-métadonnées indisponible');
}
function preparer(): Preparation {
  return JSON.parse(execFileSync('php', ['tests/e2e/preparer-classes-metadonnees.php'], { cwd: RACINE, env: { ...process.env, ...ENV } }).toString()) as Preparation;
}
async function connecter(page: Page, identifiant: string, motDePasse: string): Promise<void> {
  await page.goto('/connexion');
  await page.getByTestId('identifiant-connexion').fill(identifiant);
  await page.getByTestId('mot-de-passe-connexion').fill(motDePasse);
  await page.getByTestId('action-connexion').click({ timeout: 15_000 });
  await expect(page).toHaveURL(/\/a-traiter$/, { timeout: 15_000 });
}
async function ouvrirMetadonnees(page: Page): Promise<void> {
  await page.getByTestId('menu-metadonnees').click();
  await expect(page).toHaveURL(/\/metadonnees/, { timeout: 15_000 });
  await expect(page.getByTestId('ecran-metadonnees')).toBeVisible();
}
/** Le bloc de la liste nommée portant ce nom (data-testid `liste-<id>`). */
function liste(page: Page, nom: string) {
  return page.getByTestId(/^liste-\d+$/).filter({ hasText: nom });
}
/** Choisit une classe dans la nomenclature au CLAVIER (clavier d'abord : le lien peut passer sous un volet). */
async function choisirClasse(page: Page, nom: string): Promise<void> {
  const lien = page.getByTestId('table-types').getByRole('link', { name: nom, exact: true });
  await lien.focus();
  await page.keyboard.press('Enter');
}
/** Ouvre le contrat préparé depuis la bibliothèque, par sa ligne réelle (attente large : machine chargée). */
async function ouvrirDocumentContrat(page: Page): Promise<void> {
  const ligne = page.getByTestId(`ligne-${prep.document}`);
  await expect(ligne).toBeVisible({ timeout: 15_000 });
  await ligne.getByRole('link').click({ timeout: 15_000 });
}

test.describe.configure({ mode: 'serial' });
test.beforeAll(async () => {
  const port = await portLibre();
  base = `http://127.0.0.1:${port}`;
  serveur = spawn('php', ['-d', 'opcache.enable=0', '-S', `127.0.0.1:${port}`, '-t', 'public', 'public/index.php'], { cwd: RACINE, env: { ...process.env, ...ENV }, stdio: 'ignore' });
  await attente(base);
  prep = preparer();
});
test.afterAll(() => serveur?.kill());

async function contexte(browser: Browser) {
  const ctx = await browser.newContext({ baseURL: base, viewport: { width: 1440, height: 900 }, locale: 'fr-CH' });
  const page = await ctx.newPage();
  await connecter(page, prep.admin, prep.mot_de_passe);
  return { ctx, page };
}

test('l’administrateur crée une liste de valeurs nommée et y ajoute une valeur', async ({ browser }: { browser: Browser }) => {
  const { ctx, page } = await contexte(browser);
  try {
    await ouvrirMetadonnees(page);
    await expect(page.getByTestId('listes-admin')).toBeVisible();
    await page.getByTestId('liste-nouveau-nom').fill('Service');
    await page.getByTestId('action-ajouter-liste').click();
    await expect(liste(page, 'Service')).toBeVisible();

    await liste(page, 'Service').getByTestId(/nouvelle-valeur$/).fill('Audit');
    await liste(page, 'Service').getByTestId(/ajouter-valeur$/).click();
    await expect(liste(page, 'Service')).toContainText('Audit');
  } finally { await ctx.close(); }
});

test('une propriété obligatoire référence la liste ; la carte s’adapte à la classe choisie', async ({ browser }: { browser: Browser }) => {
  const { ctx, page } = await contexte(browser);
  try {
    await ouvrirMetadonnees(page);
    await choisirClasse(page, 'Contrat');
    await expect(page).toHaveURL(/\/metadonnees\?type=\d+/);

    await page.getByTestId('champ-nom-champ').fill('Service responsable');
    await page.getByTestId('champ-nature').selectOption('liste');
    await page.getByTestId('champ-liste').selectOption('service');
    // Le clavier d'abord : la case d'obligation se coche à l'espace, comme un vrai utilisateur.
    await page.getByTestId('champ-obligatoire').focus();
    await page.keyboard.press('Space');
    await expect(page.getByTestId('champ-obligatoire')).toBeChecked();
    // Valider au clavier (Entrée sur le bouton) : l'écran est long, le clic au pointeur est instable.
    await page.getByTestId('action-ajouter-champ').press('Enter');

    const carte = page.getByTestId('carte-propriete-service-responsable');
    await expect(carte).toBeVisible();
    await expect(carte).toContainText('oui');
    await expect(carte).toContainText('Audit');

    // Choisir une AUTRE classe adapte la carte : la propriété de Contrat disparaît.
    await choisirClasse(page, 'Facture');
    await expect(page.getByTestId('carte-propriete-service-responsable')).toHaveCount(0);
  } finally { await ctx.close(); }
});

test('le document de la classe Contrat porte sa carte dynamique et garde la valeur choisie', async ({ browser }: { browser: Browser }) => {
  const { ctx, page } = await contexte(browser);
  try {
    await page.getByTestId('menu-bibliotheque').click();
    await expect(page).toHaveURL(/\/bibliotheque/);
    await ouvrirDocumentContrat(page);

    await expect(page.getByTestId('formulaire-champs')).toBeVisible();
    await expect(page.getByTestId('saisie-champ-service-responsable-obligatoire')).toBeVisible();
    await expect(page.getByTestId('saisie-champ-service-responsable')).toContainText('Audit');
    await page.getByTestId('saisie-champ-service-responsable').selectOption('Audit');
    await page.getByTestId('action-enregistrer-champs').click();

    await expect(page.getByTestId('saisie-champ-service-responsable')).toHaveValue('Audit');
  } finally { await ctx.close(); }
});

test('retirer une valeur la rend traçable et le document qui la porte la montre toujours', async ({ browser }: { browser: Browser }) => {
  const { ctx, page } = await contexte(browser);
  try {
    await ouvrirMetadonnees(page);
    const ligne = liste(page, 'Service').getByTestId(/^liste-\d+-valeur-\d+$/).filter({ hasText: 'Audit' });
    await expect(ligne).toBeVisible();
    await ligne.getByTestId(/basculer$/).click();

    await expect(liste(page, 'Service')).toContainText('retirée le');
    await expect(liste(page, 'Service')).toContainText('classes-admin@gedv2.test');

    // Le document n'a rien perdu : la valeur retirée reste affichée sur sa carte, marquée.
    await page.getByTestId('menu-bibliotheque').click();
    await ouvrirDocumentContrat(page);
    await expect(page.getByTestId('saisie-champ-service-responsable-retiree')).toBeAttached();
    await expect(page.getByTestId('saisie-champ-service-responsable')).toHaveValue('Audit');
  } finally { await ctx.close(); }
});
