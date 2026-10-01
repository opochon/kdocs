import { test, expect, type Browser, type Page, type Dialog } from '@playwright/test';
import { execFileSync, spawn, type ChildProcess } from 'node:child_process';
import { createServer } from 'node:net';
import fs from 'node:fs';
import path from 'node:path';

/**
 * Oracle V2-06b — le designer de workflows repris du designer GEDv1, adapté au modèle du moteur GEDv2.
 *
 * Parcours avec un compte RÉEL (créé comme à l'installation) :
 *   1. installer le gabarit « Facture à seuil » et l'ouvrir dans l'éditeur ;
 *   2. changer le seuil, ajouter un état, relier une transition, enregistrer ;
 *   3. rouvrir le circuit : les modifications sont présentes ;
 *   4. lancer un essai à blanc sur un document ingéré par le Pilote : le chemin est affiché, aucune
 *      écriture n'a eu lieu (empreinte du document et de la base inchangées) ;
 *   5. échouer si une boîte de dialogue du navigateur apparaît.
 *
 * Aucune donnée réelle : corpus synthétique, compte fictif, base gedv2_test.
 */

const RACINE = process.cwd();
const FONDS = 'var/oracles/workflows/documents';
const ENV = {
  ...process.env,
  DB_NAME: 'gedv2_test',
  APP_ENV: 'test',
  STORAGE_ROOT: FONDS,
  INGESTION_DOSSIER: 'var/oracles/workflows/entree',
  INGESTION_TRAVAIL: 'var/oracles/workflows/ingestion',
  INGESTION_IMAP_HOTE: 'mail.test',
  INGESTION_IMAP_PORT: '993',
  INGESTION_IMAP_UTILISATEUR: 'ingestion-oracle',
  INFOMANIAK_AI_MODEL_CLASSIFY: 'oracle/model-classify-1',
  INFOMANIAK_AI_TIMEOUT_CLASSIFY: '5',
};

type Preparation = {
  preparation: string;
  compte: string;
  mot_de_passe: string;
  fonds: string;
  id_pilote: number;
};

type Empreinte = { document_id: number; journal: number; instances: number; fichier: string; meta: string };

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
  throw new Error('le serveur de l’oracle « workflows » n’a pas démarré');
}

function preparer(): Preparation {
  const sortie = execFileSync('php', ['tests/e2e/preparer-workflows.php'], { cwd: RACINE, env: ENV }).toString();
  return JSON.parse(sortie) as Preparation;
}

function empreinte(idDocument: number): Empreinte {
  const sortie = execFileSync('php', ['tests/e2e/verifier-workflows.php'],
    { cwd: RACINE, env: { ...ENV, DOCUMENT_ID: String(idDocument) } }).toString();
  return JSON.parse(sortie) as Empreinte;
}

async function pageConnectee(browser: Browser): Promise<{ page: Page; fermer: () => Promise<void>; dialogues: string[] }> {
  const contexte = await browser.newContext({ baseURL: base, viewport: { width: 1440, height: 900 }, locale: 'fr-CH' });
  const page = await contexte.newPage();
  const dialogues: string[] = [];
  // Aucune boîte de dialogue du navigateur ne doit apparaître : l'application a les siennes.
  page.on('dialog', async (dialogue: Dialog) => {
    dialogues.push(dialogue.message());
    await dialogue.dismiss();
  });
  await page.goto('/connexion');
  await page.getByTestId('identifiant-connexion').fill(prep.compte);
  await page.getByTestId('mot-de-passe-connexion').fill(prep.mot_de_passe);
  await page.getByTestId('action-connexion').click();
  await expect(page).toHaveURL(/\/a-traiter$/);

  return { page, fermer: () => contexte.close(), dialogues };
}

/** Ouvre l'éditeur du gabarit « Facture à seuil » (installé d'un geste) et rend l'identifiant du circuit. */
async function ouvrirGabarit(page: Page): Promise<number> {
  await page.goto('/workflows');
  await expect(page.getByTestId('ecran-workflows')).toBeVisible();
  await page.getByTestId('action-installer-facture_a_seuil').click();

  const carte = page.locator('[data-testid^="circuit-"]').filter({ hasText: 'Facture à seuil' }).first();
  await expect(carte).toBeVisible();
  const circuitId = Number(await carte.getAttribute('data-circuit-id'));
  expect(circuitId).toBeGreaterThan(0);

  await page.getByTestId(`editer-circuit-${circuitId}`).click();
  await expect(page).toHaveURL(new RegExp(`/workflows/circuits/${circuitId}/editeur$`));
  await expect(page.getByTestId('ecran-workflows-editeur')).toBeVisible();
  return circuitId;
}

test.describe.configure({ mode: 'serial', timeout: 90_000 });

test.beforeAll(async () => {
  fs.mkdirSync(path.join(RACINE, 'var', 'oracles', 'workflows'), { recursive: true });
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

test('workflows : ouvrir le gabarit, changer le seuil, ajouter un état, relier, rouvrir, essayer à blanc', async ({ browser }) => {
  const { page, fermer, dialogues } = await pageConnectee(browser);
  try {
    const circuitId = await ouvrirGabarit(page);

    // Le dessin du gabarit est bien chargé : des nœuds et un canevas.
    await expect(page.getByTestId('workflows-canevas')).toBeVisible();
    await expect(page.locator('[data-testid^="noeud-"]').first()).toBeVisible();

    // 1. Changer le seuil : la condition « montant ≥ 10000 » de la règle direction.
    const conditionSeuil = page.locator('[data-genre="condition"][data-champ="montant"][data-operateur="superieur_ou_egal"]').first();
    await conditionSeuil.click();
    await expect(page.getByTestId('reglage-valeur')).toHaveValue('10000');
    await page.getByTestId('reglage-valeur').fill('5000');
    await page.getByTestId('action-reglage-enregistrer').click();
    await expect(conditionSeuil).toHaveAttribute('data-valeur', '5000');

    // 2. Ajouter un état et le nommer.
    await page.getByTestId('action-ajouter-etat').click();
    await expect(page.getByTestId('reglage-nom')).toHaveValue(/^etat_/);
    await page.getByTestId('reglage-nom').fill('verifie');
    await page.getByTestId('reglage-libelle').fill('Vérifié');
    await page.getByTestId('action-reglage-enregistrer').click();
    const etatVerifie = page.locator('[data-genre="etat"][data-etat="verifie"]').first();
    await expect(etatVerifie).toBeVisible();

    // 3. Relier une transition du nouvel état vers « Validé ».
    await page.getByTestId('reglage-transition-vers').selectOption({ label: 'Validé' });
    await page.getByTestId('action-transition-relier').click();
    await expect(page.getByTestId('reglage-transitions').locator('[data-transition="verifie→valide"]')).toBeVisible();

    // 4. Enregistrer.
    await page.getByTestId('action-enregistrer').click();
    await expect(page.getByTestId('statut-enregistrement')).toContainText('Enregistré');

    // 5. Rouvrir le circuit : les modifications sont présentes.
    await page.goto(`/workflows/circuits/${circuitId}/editeur`);
    await expect(page.getByTestId('ecran-workflows-editeur')).toBeVisible();
    const seuilRelu = page.locator('[data-genre="condition"][data-champ="montant"][data-operateur="superieur_ou_egal"]').first();
    await expect(seuilRelu).toHaveAttribute('data-valeur', '5000');
    const etatRelu = page.locator('[data-genre="etat"][data-etat="verifie"]').first();
    await expect(etatRelu).toBeVisible();
    await etatRelu.click();
    await expect(page.getByTestId('reglage-transitions').locator('[data-transition="verifie→valide"]')).toBeVisible();

    // 6. Essai à blanc sur un document ingéré par le Pilote : chemin affiché, aucune écriture.
    const avant = empreinte(prep.id_pilote);
    await page.getByTestId('essai-document').selectOption(String(prep.id_pilote));
    await page.getByTestId('essai-declencheur').selectOption('document_depose');
    await page.getByTestId('action-essai-editeur').click();

    await expect(page.getByTestId('essai-resultat')).toBeVisible();
    await expect(page.getByTestId('essai-zero-ecriture')).toContainText('zéro écriture');
    await expect(page.getByTestId('essai-chemin')).not.toContainText('vide');
    await expect(page.locator('[data-chemin="1"]').first()).toBeVisible();

    const apres = empreinte(prep.id_pilote);
    expect(apres, 'essai à blanc : journal, instances, fichier et métadonnées inchangés').toEqual(avant);
    expect(apres.journal).toBe(0);
    expect(apres.instances).toBe(0);

    // 7. Aucune boîte de dialogue du navigateur.
    expect(dialogues, `dialogues natifs observés : ${dialogues.join(' | ')}`).toEqual([]);
  } finally {
    await fermer();
  }
});

test('designer : graphe ajusté à la vue, zoomable (boutons, molette, clavier), titres accentués, plus de section technique', async ({ browser }) => {
  const { page, fermer } = await pageConnectee(browser);
  try {
    await ouvrirGabarit(page);

    // a) Les titres de groupes sont accentués et en casse de phrase (« Déclencheurs », pas « DECLENCHEURS »).
    const titres = await page.locator('[data-testid="workflows-outils"] .titre-section').allInnerTexts();
    expect(titres.map((t) => t.trim())).toEqual(['Déclencheurs', 'Conditions', 'Actions', 'États', 'Approbation']);

    // b) La section « Composants GEDv1 non portés » (information de développeur) a quitté l'écran.
    await expect(page.getByTestId('a-porter')).toHaveCount(0);
    expect(await page.locator('body').innerText()).not.toContain('Composants GEDv1 non portés');

    const canevas = page.getByTestId('workflows-canevas');
    await expect(canevas).toBeVisible();

    // c) À l'ouverture, le graphe est ajusté à la vue : chaque nœud tient dans le canevas.
    const cadre = await canevas.boundingBox();
    expect(cadre).not.toBeNull();
    const noeuds = page.locator('[data-testid^="noeud-"]');
    const nombre = await noeuds.count();
    expect(nombre).toBeGreaterThan(1);
    for (let i = 0; i < nombre; i++) {
      const boite = await noeuds.nth(i).boundingBox();
      expect(boite, `nœud ${i} mesurable`).not.toBeNull();
      if (!boite || !cadre) continue;
      expect(boite.x, `nœud ${i} déborde à gauche`).toBeGreaterThanOrEqual(cadre.x - 2);
      expect(boite.y, `nœud ${i} déborde en haut`).toBeGreaterThanOrEqual(cadre.y - 2);
      expect(boite.x + boite.width, `nœud ${i} déborde à droite`).toBeLessThanOrEqual(cadre.x + cadre.width + 2);
      expect(boite.y + boite.height, `nœud ${i} déborde en bas`).toBeLessThanOrEqual(cadre.y + cadre.height + 2);
    }

    const zoom = async (): Promise<number> => Number((await canevas.getAttribute('data-zoom')) ?? '0');

    // d) Boutons de zoom : « + » agrandit, « − » réduit.
    const z0 = await zoom();
    await page.getByTestId('workflows-zoom-avant').click();
    const z1 = await zoom();
    expect(z1, `zoom après + (${z0} → ${z1})`).toBeGreaterThan(z0);
    await page.getByTestId('workflows-zoom-arriere').click();
    expect(await zoom()).toBeLessThan(z1);

    // e) Molette au-dessus du canevas : agrandit vers le pointeur.
    await canevas.hover();
    await page.mouse.wheel(0, -400);
    expect(await zoom()).toBeGreaterThan(z0);

    // f) Clavier : « 0 » ajuste, « + » agrandit.
    await canevas.focus();
    await page.keyboard.press('0');
    const ajuste = await zoom();
    await page.keyboard.press('+');
    expect(await zoom()).toBeGreaterThan(ajuste);

    // g) Le bouton « Ajuster » ramène le graphe dans le canevas.
    await page.getByTestId('workflows-zoom-avant').click();
    await page.getByTestId('workflows-ajuster').click();
    const cadre2 = await canevas.boundingBox();
    for (let i = 0; i < nombre; i++) {
      const boite = await noeuds.nth(i).boundingBox();
      if (!boite || !cadre2) continue;
      expect(boite.x).toBeGreaterThanOrEqual(cadre2.x - 2);
      expect(boite.x + boite.width).toBeLessThanOrEqual(cadre2.x + cadre2.width + 2);
    }
  } finally {
    await fermer();
  }
});
