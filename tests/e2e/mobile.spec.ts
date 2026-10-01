import { test, expect, devices, type Browser, type Page } from '@playwright/test';
import { execFileSync, spawn, type ChildProcess } from 'node:child_process';
import { createServer } from 'node:net';
import fs from 'node:fs';
import path from 'node:path';

/**
 * Oracle V2-10a / V2-10b — le mobile.
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
 * V2-10b — l'ergonomie demandée par la recette réelle :
 *   - la PIÈCE d'abord : à l'ouverture d'À traiter, l'aperçu et « Valider » sont visibles SANS défilement,
 *     le bilan est une ligne compacte et les cartes d'ordinateur ne s'affichent plus ;
 *   - les filtres de Recherche sont repliés derrière « Filtres » et, ouverts, un champ par ligne sans
 *     aucun recouvrement (contrôle des boîtes englobantes) ;
 *   - aucun avis « écran conçu pour l'ordinateur » sur À traiter, Recherche et la fiche ;
 *   - Déposer offre « Prendre une photo » et « Choisir des fichiers » en français, avec la liste des
 *     fichiers choisis et leurs vignettes — jamais le libellé natif anglais ;
 *   - le raccourci « Ctrl K » est masqué sous 768 px.
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
  id_bruite: number;
  titre_bruite: string;
  jeton_bruite: string;
  emetteur_bruite: string;
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
  // V2-10c : on repose aussi la cible « texte bruité » — la table `recherche_textes` est partagée et un
  // indexeur d'un autre fonds marque « absent » les lignes qu'il ne voit pas.
  for (const [id, titre] of [[prep.id_recherche, prep.titre_recherche], [prep.id_bruite, prep.titre_bruite]] as const) {
    execFileSync('php', ['tests/e2e/indexer-mobile.php'], {
      cwd: RACINE,
      env: { ...ENV, DOCUMENT_ID: String(id), TITRE: titre },
    });
  }
}

function passerTachePhoto(nom: string): { document: number; titre: string } {
  const sortie = execFileSync('php', ['tests/e2e/passer-ingestion-mobile.php'], {
    cwd: RACINE, env: { ...ENV, PHOTO_NOM: nom },
  }).toString();
  return JSON.parse(sortie) as { document: number; titre: string };
}

/** Le contrôle automatique du lot : pas de débordement horizontal, cibles de commande ≥ 44 px. */
async function verifierMobile(page: Page): Promise<void> {
  // Après un geste qui déclenche un POST (requestSubmit), la navigation peut être en cours : on attend le
  // document, sinon `document.body` peut être nul le temps du remplacement (course observée en parallèle).
  await page.locator('body').waitFor({ state: 'attached' });
  const mesure = await page.evaluate(() => {
    const ecran = window.innerWidth;
    const largeur = Math.max(document.documentElement.scrollWidth, document.body ? document.body.scrollWidth : 0);
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

/**
 * V2-10c : l'invite de la barre de recherche doit être ENTIÈREMENT lisible sur 390 px — la version
 * longue est tronquée, le JS pose donc une invite courte sous 480 px. On mesure la largeur réellement
 * rendue (canvas, même police) et on la compare à la place disponible dans le champ.
 */
async function verifierInviteRecherche(page: Page): Promise<void> {
  const mesure = await page.evaluate(() => {
    const champ = document.querySelector('[data-testid="recherche-globale-champ"]') as HTMLInputElement | null;
    if (!champ) return null;
    const style = getComputedStyle(champ);
    const toile = document.createElement('canvas').getContext('2d');
    if (!toile) return null;
    toile.font = `${style.fontStyle} ${style.fontWeight} ${style.fontSize} ${style.fontFamily}`;
    return {
      invite: champ.placeholder,
      largeur: toile.measureText(champ.placeholder).width,
      disponible: champ.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight),
    };
  });

  expect(mesure, 'le champ de recherche global est présent').not.toBeNull();
  if (!mesure) return;
  expect(mesure.invite, 'invite courte sur téléphone').toBe('Rechercher');
  expect(mesure.largeur,
    `invite « ${mesure.invite} » : ${Math.round(mesure.largeur)} px ≤ ${Math.round(mesure.disponible)} px disponibles`)
    .toBeLessThanOrEqual(mesure.disponible + 1);
}

/**
 * L'élément est ENTIÈREMENT dans la fenêtre au chargement, sans défilement : c'est la promesse
 * « la pièce et Valider d'abord, au pouce ». On exige aussi que la page n'ait pas défilé.
 */
async function verifierVisibleSansDefilement(page: Page, testids: string[]): Promise<void> {
  const hauteur = await page.evaluate(() => window.innerHeight);
  for (const testid of testids) {
    const boite = await page.getByTestId(testid).boundingBox();
    expect(boite, `« ${testid} » existe et a une boîte`).not.toBeNull();
    if (!boite) continue;
    expect(boite.y, `« ${testid} » commence dans l’écran (y = ${Math.round(boite.y)})`).toBeGreaterThanOrEqual(0);
    expect(boite.y + boite.height, `« ${testid} » finit dans l’écran (bas = ${Math.round(boite.y + boite.height)} ≤ ${hauteur})`)
      .toBeLessThanOrEqual(hauteur + 1);
  }
  expect(await page.evaluate(() => window.scrollY), 'aucun défilement au chargement').toBe(0);
}

/**
 * Sur téléphone, aucun champ de filtre ne doit recouvrir un AUTRE élément (libellé, champ ou bouton
 * du panneau) : contrôle des boîtes englobantes, paires parent/enfant exclues (un libellé contient son
 * propre champ, c'est normal). Rend les collisions nommées.
 */
async function chevauchementsFiltres(page: Page): Promise<string[]> {
  return page.evaluate(() => {
    const cibles = [...document.querySelectorAll(
      '.filtres .chip, .filtres [data-testid^="filtre-"], .filtres .btn',
    )] as HTMLElement[];
    const visibles = cibles.filter((element) => {
      const r = element.getBoundingClientRect();
      return r.width > 0 && r.height > 0;
    });
    const collisions: string[] = [];
    for (let i = 0; i < visibles.length; i++) {
      for (let j = i + 1; j < visibles.length; j++) {
        const a = visibles[i];
        const b = visibles[j];
        if (a.contains(b) || b.contains(a)) continue;
        const ra = a.getBoundingClientRect();
        const rb = b.getBoundingClientRect();
        const recouvre = ra.left < rb.right - 0.5 && rb.left < ra.right - 0.5
          && ra.top < rb.bottom - 0.5 && rb.top < ra.bottom - 0.5;
        if (recouvre) collisions.push(`${a.dataset.testid || a.className} ∩ ${b.dataset.testid || b.className}`);
      }
    }
    return collisions;
  });
}

/**
 * Déplie la file « À traiter ». Le pied de validation est FIXE au-dessus des onglets (V2-10b) : on amène
 * d'abord la file au centre de l'écran, comme le ferait le pouce, pour que le clic ne tombe pas sur la barre.
 */
async function deplierFile(page: Page): Promise<void> {
  const repli = page.getByTestId('file-repli');
  await expect(repli).toBeVisible();
  await expect(repli).toContainText('File');
  await repli.evaluate((element) => element.scrollIntoView({ block: 'center' }));
  await repli.click();
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

      // 5) La barre du haut ne montre pas le raccourci « Ctrl K » sur téléphone (il n'a pas de sens
      // sans clavier) : le badge est masqué sous 768 px, la recherche reste au doigt.
      await expect(page.locator('.recherche-globale .kbd')).toBeHidden();
      // V2-10c : l'invite de recherche est entièrement visible sur 390 px (version courte).
      await verifierInviteRecherche(page);

      // 1) V2-10b — la PIÈCE d'abord : à l'ouverture, l'aperçu et « Valider » sont dans l'écran, sans
      // défilement ; le bilan est une ligne compacte et les trois cartes d'ordinateur ne s'affichent plus.
      // V2-10c : la proposition (type · émetteur · date) est visible AU-DESSUS des gestes — on ne valide
      // pas à l'aveugle.
      await verifierVisibleSansDefilement(page, ['apercu', 'action-valider', 'resume-proposition']);
      await expect(page.getByTestId('bilan-ligne')).toBeVisible();
      await expect(page.getByTestId('bilan-ligne')).toContainText('à contrôler');
      await expect(page.getByTestId('bilan-auto')).toBeHidden();
      await expect(page.getByTestId('ecran-ordinateur')).toHaveCount(0);

      const resume = page.getByTestId('resume-proposition');
      await expect(resume).toBeVisible();
      await expect(resume).toContainText('Facture');
      await expect(resume).toContainText(`Papeterie Fictive ${prep.jeton} SA`);
      await expect(resume).toContainText('à contrôler');
      const boiteResume = await resume.boundingBox();
      const boiteValider = await page.getByTestId('action-valider').boundingBox();
      expect(boiteResume && boiteValider && boiteResume.y + boiteResume.height <= boiteValider.y + 1,
        'le résumé est JUSTE AU-DESSUS de « Valider »').toBe(true);

      // 1) Une pièce à la fois : l'aperçu est au-dessus, la proposition dessous, gros boutons.
      await expect(page.getByTestId('apercu')).toBeVisible();
      await expect(page.getByTestId('formulaire-validation')).toBeVisible();
      const boiteApercu = await page.getByTestId('apercu').boundingBox();
      const boiteProposition = await page.getByTestId('formulaire-validation').boundingBox();
      expect(boiteApercu && boiteProposition && boiteApercu.y < boiteProposition.y,
        'l’aperçu est au-dessus de la proposition').toBe(true);

      // La file est un compteur + une liste REPLIABLE : le compteur est visible, la liste s'ouvre au geste.
      await deplierFile(page);
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

      // V2-10b : les filtres sont repliés derrière « Filtres » ; ouverts, un champ par ligne, sans aucun
      // recouvrement (boîtes englobantes) ; et l'avis « écran conçu pour l'ordinateur » ne s'affiche pas.
      await expect(page.getByTestId('ecran-ordinateur')).toHaveCount(0);
      const boutonFiltres = page.getByTestId('action-filtres');
      if (await boutonFiltres.isVisible().catch(() => false)) await boutonFiltres.click();
      await expect(page.locator('.filtres .chip').first()).toBeVisible();
      expect(await chevauchementsFiltres(page), 'aucun filtre ne recouvre un autre élément').toEqual([]);

      const carte = page.getByTestId(`resultat-${prep.id_recherche}`);
      await expect(carte).toBeVisible();
      await expect(carte).toContainText(prep.titre_recherche);
      await carte.locator('a').first().click();
      await expect(page).toHaveURL(new RegExp(`/documents/${prep.id_recherche}$`));
      await expect(page.getByTestId('ecran-document')).toBeVisible();
      await expect(page.getByTestId('ecran-document')).toContainText(prep.jeton);
      await expect(page.getByTestId('action-telecharger')).toBeVisible();
      await expect(page.getByTestId('panneau-metadonnees')).toBeVisible();
      // La fiche fait partie du périmètre mobile : aucun avis d'ordinateur ne s'y affiche.
      await expect(page.getByTestId('ecran-ordinateur')).toHaveCount(0);
      await verifierMobile(page);

      // 2bis) V2-10c : un texte OCR bruité ne produit AUCUN extrait illisible, mais le titre, le type,
      // l'émetteur et la date du document restent visibles.
      await page.goto(`/recherche?q=${prep.jeton_bruite}`);
      const carteBruitee = page.getByTestId(`resultat-${prep.id_bruite}`);
      await expect(carteBruitee).toBeVisible();
      await expect(carteBruitee).toContainText(prep.titre_bruite);
      await expect(carteBruitee).toContainText('Courrier');
      await expect(carteBruitee).toContainText(prep.emetteur_bruite);
      await expect(carteBruitee).toContainText('06.05.2026');
      await expect(page.getByTestId(`extraits-${prep.id_bruite}`)).toHaveCount(0);
      await expect(carteBruitee).not.toContainText('bcdfg');
      await verifierMobile(page);

      // 3) Dépôt par photo : appareil photo, plusieurs fichiers, retour nommé, puis la tâche.
      await page.getByTestId('onglet-deposer').click();
      await expect(page.getByTestId('ecran-deposer')).toBeVisible();
      // V2-10b : boutons français explicites, aucun contrôle natif anglais visible (le champ reste le
      // même, piloté par les boutons) ; la liste des fichiers choisis porte leurs vignettes.
      await expect(page.getByTestId('action-photo')).toBeVisible();
      await expect(page.getByTestId('action-photo')).toContainText('Prendre une photo');
      await expect(page.getByTestId('action-fichiers')).toBeVisible();
      await expect(page.getByTestId('action-fichiers')).toContainText('Choisir des fichiers');
      await expect(page.getByTestId('champ-photo')).toBeHidden();
      expect(await page.getByTestId('ecran-deposer').innerText(), 'aucun libellé anglais').not.toMatch(/choose files?|no file chosen/i);
      const champ = page.getByTestId('champ-photo');
      await expect(champ).toHaveAttribute('accept', /image\//);
      await expect(champ).toHaveAttribute('capture', 'environment');
      await expect(champ).toHaveAttribute('multiple', '');
      const nomPhoto = `photo-mobile-${prep.jeton}.png`;
      await champ.setInputFiles({ name: nomPhoto, mimeType: 'image/png', buffer: photoUnique(prep.jeton) });
      await expect(page.getByTestId('liste-fichiers')).toContainText(nomPhoto);
      await page.getByTestId('action-envoyer').click();
      await expect(page.getByTestId('depot-retour')).toContainText('reçu');
      await expect(page.getByTestId('depot-retour')).toContainText('en cours de lecture');
      await verifierMobile(page);

      const range = passerTachePhoto(nomPhoto);
      expect(range.document, 'la pièce déposée a été rangée').toBeGreaterThan(0);

      await page.goto('/a-traiter');
      await attendreJs(page);
      await expect(page.getByTestId('onglets-bas')).toBeVisible();
      await deplierFile(page);
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
