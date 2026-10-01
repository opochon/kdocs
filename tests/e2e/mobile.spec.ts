import { test, expect, devices, type Browser, type Page } from '@playwright/test';
import { execFileSync, spawn, type ChildProcess } from 'node:child_process';
import { createServer } from 'node:net';
import fs from 'node:fs';
import path from 'node:path';

/**
 * Oracle V2-10a — le mobile, étape 1.
 *
 * Une seule base de gabarits et de CSS, adaptée par points de rupture (≤ 768 px, ≤ 480 px) : mêmes routes,
 * mêmes droits, même CSP. Le parcours tourne sur des appareils ÉMULÉS (iPhone 13 et Pixel 7), avec un
 * compte RÉEL (créé par `tests/e2e/preparer-mobile.php` comme à l'installation) :
 *
 *   1. sur À traiter, une pièce ingérée par le Pilote est validée au pouce (gros bouton), l'aperçu est
 *      au-dessus et la file est un compteur + une liste repliable ;
 *   2. recherche : barre en haut, résultats en cartes ; la fiche s'ouvre, lisible et téléchargeable ;
 *   3. dépôt par photo : l'écran Déposer envoie une image dans la MÊME chaîne d'ingestion, le retour dit
 *      « reçu, en cours de lecture », et la pièce arrive dans À traiter après le passage de la tâche ;
 *   4. navigation : barre latérale → onglets en bas ; les écrans d'ordinateur sont signalés.
 *
 * Contrôle automatique sur chaque écran mobile : aucune largeur de page > largeur de l'écran, et toute
 * cible tactile de commande mesurable fait au moins 44 px.
 *
 * Aucune donnée réelle : corpus synthétique, compte fictif, base gedv2_test.
 */

const RACINE = process.cwd();
const FONDS = 'var/oracles/mobile/documents';
const ENV = {
  ...process.env,
  DB_NAME: 'gedv2_test',
  APP_ENV: 'test',
  STORAGE_ROOT: FONDS,
  INGESTION_DOSSIER: 'var/oracles/mobile/entree',
  INGESTION_TRAVAIL: 'var/oracles/mobile/ingestion',
  INGESTION_IMAP_HOTE: 'mail.test',
  INGESTION_IMAP_PORT: '993',
  INGESTION_IMAP_UTILISATEUR: 'ingestion-oracle',
  INFOMANIAK_AI_MODEL_CLASSIFY: 'oracle/model-classify-1',
};

/** PNG synthétique 1×1 : le fichier de test du dépôt par photo. */
const PNG = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
  'base64',
);

/**
 * Le même PNG, marqué du jeton du passage : l'empreinte change donc à chaque exécution, sinon la chaîne
 * d'ingestion (idempotente, à raison) reconnaîtrait le fichier du passage précédent comme un doublon.
 */
function photoUnique(jeton: string): Buffer {
  return Buffer.concat([PNG, Buffer.from(`\nGEDv2-oracle-mobile:${jeton}\n`, 'ascii')]);
}

type Preparation = {
  compte: string;
  mot_de_passe: string;
  fonds: string;
  jeton: string;
  id_pilote: number;
  id_recherche: number;
  titre_recherche: string;
};

let serveur: ChildProcess | undefined;
let base = '';
let prep: Preparation;

async function portLibre(): Promise<number> {
  return new Promise((resolve, reject) => {
    const sonde = createServer();
    sonde.once('error', reject);
    sonde.listen(0, '127.0.0.1', () => {
      const adresse = sonde.address();
      const port = typeof adresse === 'object' && adresse !== null ? adresse.port : 0;
      sonde.close(() => resolve(port));
    });
  });
}

async function attendreSante(url: string): Promise<void> {
  for (let essai = 0; essai < 100; essai++) {
    try {
      const reponse = await fetch(`${url}/sante`);
      if (reponse.ok) return;
    } catch {
      // le serveur PHP est encore en amorçage
    }
    await new Promise((resoudre) => setTimeout(resoudre, 100));
  }
  throw new Error('le serveur de l’oracle « mobile » n’a pas démarré');
}

function preparer(): Preparation {
  const sortie = execFileSync('php', ['tests/e2e/preparer-mobile.php'], { cwd: RACINE, env: ENV }).toString();
  return JSON.parse(sortie) as Preparation;
}

function indexerCible(): void {
  execFileSync('php', ['tests/e2e/indexer-mobile.php'], {
    cwd: RACINE,
    env: { ...ENV, DOCUMENT_ID: String(prep.id_recherche), TITRE: prep.titre_recherche },
  });
}

function passerTachePhoto(nom: string): { document: number; titre: string } {
  const sortie = execFileSync('php', ['tests/e2e/passer-ingestion-mobile.php'], {
    cwd: RACINE, env: { ...ENV, PHOTO_NOM: nom },
  }).toString();
  return JSON.parse(sortie) as { document: number; titre: string };
}

/** Le contrôle automatique du lot : pas de débordement horizontal, cibles de commande ≥ 44 px. */
async function verifierMobile(page: Page): Promise<void> {
  const mesure = await page.evaluate(() => {
    const ecran = window.innerWidth;
    const largeur = Math.max(document.documentElement.scrollWidth, document.body.scrollWidth);
    // Cibles de COMMANDE (boutons, onglets, champs, lignes actionnables) — les liens en ligne du texte
    // courant ne sont pas des cibles tactiles.
    const selection = 'button, [role="tab"], summary, select, input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]), a.btn, .onglets-bas a, .rail a, .liste a, .resultat a:not(.btn)';
    const petits: string[] = [];
    document.querySelectorAll(selection).forEach((element) => {
      const boite = element.getBoundingClientRect();
      const style = getComputedStyle(element);
      if (boite.width === 0 || boite.height === 0 || style.visibility === 'hidden' || style.display === 'none') return;
      const nom = element.getAttribute('data-testid') || element.className || element.tagName;
      if (boite.height < 43.5) petits.push(`${nom} h=${Math.round(boite.height)}`);
      if (element.matches('button, [role="tab"], a.btn, .onglets-bas a') && boite.width < 43.5) {
        petits.push(`${nom} w=${Math.round(boite.width)}`);
      }
    });
    const refus = document.querySelector('[data-testid="refus-cause"]');
    return { ecran, largeur, petits, url: location.pathname + location.search, refus: refus ? refus.textContent : '' };
  });
  expect(mesure.largeur, `largeur de page ${mesure.largeur} ≤ écran ${mesure.ecran}`).toBeLessThanOrEqual(mesure.ecran + 1);
  expect(mesure.petits, `cibles < 44 px sur ${mesure.url} (refus: ${mesure.refus}) : ${mesure.petits.join(', ')}`).toEqual([]);
}

/** Attend que les comportements (ged.js) soient posés : les boutons d'action en dépendent. */
async function attendreJs(page: Page): Promise<void> {
  await page.locator('html[data-ged-js="1"]').waitFor({ state: 'attached' });
}

async function connecter(page: Page): Promise<void> {
  await page.goto('/connexion');
  await page.getByTestId('identifiant-connexion').fill(prep.compte);
  await page.getByTestId('mot-de-passe-connexion').fill(prep.mot_de_passe);
  await page.getByTestId('action-connexion').click();
  await expect(page).toHaveURL(/\/a-traiter$/);
  await attendreJs(page);
}

test.describe.configure({ mode: 'serial', timeout: 90_000 });

test.beforeAll(async () => {
  fs.mkdirSync(path.join(RACINE, 'var', 'oracles', 'mobile'), { recursive: true });
  const port = await portLibre();
  base = `http://127.0.0.1:${port}`;
  serveur = spawn('php', ['-d', 'opcache.enable=0', '-S', `127.0.0.1:${port}`, '-t', 'public', 'public/index.php'],
    { cwd: RACINE, env: ENV, stdio: 'ignore' });
  await attendreSante(base);
});

test.afterAll(() => {
  serveur?.kill();
});

test.beforeEach(() => {
  prep = preparer();
});

for (const appareil of ['iPhone 13', 'Pixel 7'] as const) {
  test(`mobile ${appareil} : valider une pièce, chercher et ouvrir une fiche, déposer une photo`, async ({ browser }: { browser: Browser }) => {
    const contexte = await browser.newContext({ ...devices[appareil], baseURL: base, locale: 'fr-CH' });
    const page = await contexte.newPage();
    const erreurs: string[] = [];
    const echecs: string[] = [];
    page.on('console', (m) => { if (m.type() === 'error') erreurs.push(m.text()); });
    page.on('pageerror', (e) => erreurs.push(e.message));
    page.on('response', (r) => { if (r.status() >= 400) echecs.push(`${r.status()} ${r.url()}`); });

    try {
      await connecter(page);

      // 4) Navigation : barre latérale → onglets en bas, « À traiter » courant.
      await expect(page.getByTestId('onglets-bas')).toBeVisible();
      await expect(page.getByTestId('onglet-a-traiter')).toHaveAttribute('aria-current', 'page');
      await verifierMobile(page);

      // 1) Une pièce à la fois : l'aperçu est au-dessus, la proposition dessous, gros boutons.
      await expect(page.getByTestId('apercu')).toBeVisible();
      await expect(page.getByTestId('formulaire-validation')).toBeVisible();
      const boiteApercu = await page.getByTestId('apercu').boundingBox();
      const boiteProposition = await page.getByTestId('formulaire-validation').boundingBox();
      expect(boiteApercu && boiteProposition && boiteApercu.y < boiteProposition.y,
        'l’aperçu est au-dessus de la proposition').toBe(true);

      // La file est un compteur + une liste REPLIABLE : le compteur est visible, la liste s'ouvre au geste.
      const repli = page.getByTestId('file-repli');
      await expect(repli).toBeVisible();
      await expect(repli).toContainText('File');
      await repli.click();
      await expect(page.getByTestId(`file-item-${prep.id_pilote}`)).toBeVisible();

      // Validation au pouce : le type proposé par l'ingestion est pré-rempli, le gros bouton valide.
      await expect(page.getByTestId('champ-type')).toHaveValue('Facture');
      await expect(page.getByTestId('action-corriger')).toBeVisible();
      await page.getByTestId('action-valider').click();
      await expect(page.getByTestId(`file-item-${prep.id_pilote}`)).toHaveCount(0);
      await verifierMobile(page);

      // 2) Recherche et fiche document, sans volets latéraux du bureau.
      indexerCible();
      await page.goto(`/recherche?q=${prep.jeton}`);
      await expect(page.getByTestId('recherche-globale-champ')).toBeVisible();
      const carte = page.getByTestId(`resultat-${prep.id_recherche}`);
      await expect(carte).toBeVisible();
      await expect(carte).toContainText(prep.titre_recherche);
      await carte.locator('a').first().click();
      await expect(page).toHaveURL(new RegExp(`/documents/${prep.id_recherche}$`));
      await expect(page.getByTestId('ecran-document')).toBeVisible();
      await expect(page.getByTestId('ecran-document')).toContainText(prep.jeton);
      await expect(page.getByTestId('action-telecharger')).toBeVisible();
      await expect(page.getByTestId('panneau-metadonnees')).toBeVisible();
      await verifierMobile(page);

      // 3) Dépôt par photo : appareil photo, plusieurs fichiers, retour nommé, puis la tâche.
      await page.getByTestId('onglet-deposer').click();
      await expect(page.getByTestId('ecran-deposer')).toBeVisible();
      const champ = page.getByTestId('champ-photo');
      await expect(champ).toHaveAttribute('accept', /image\//);
      await expect(champ).toHaveAttribute('capture', 'environment');
      await expect(champ).toHaveAttribute('multiple', '');
      const nomPhoto = `photo-mobile-${prep.jeton}.png`;
      await champ.setInputFiles({ name: nomPhoto, mimeType: 'image/png', buffer: photoUnique(prep.jeton) });
      await page.getByTestId('action-envoyer').click();
      await expect(page.getByTestId('depot-retour')).toContainText('reçu');
      await expect(page.getByTestId('depot-retour')).toContainText('en cours de lecture');
      await verifierMobile(page);

      const range = passerTachePhoto(nomPhoto);
      expect(range.document, 'la pièce déposée a été rangée').toBeGreaterThan(0);

      await page.goto('/a-traiter');
      await attendreJs(page);
      await expect(page.getByTestId('onglets-bas')).toBeVisible();
      await page.getByTestId('file-repli').click();
      await expect(page.getByTestId(`file-item-${range.document}`)).toBeVisible();
      await verifierMobile(page);

      // Écrans d'ordinateur : accessibles, mais signalés.
      await page.goto('/bibliotheque');
      await expect(page.getByTestId('ecran-ordinateur')).toBeVisible();
      await expect(page.getByTestId('ecran-ordinateur')).toContainText('ordinateur');

      expect(erreurs, `erreurs console : ${erreurs.join(' | ')} — requêtes refusées : ${echecs.join(' | ')}`).toEqual([]);
    } finally {
      await contexte.close();
    }
  });
}
