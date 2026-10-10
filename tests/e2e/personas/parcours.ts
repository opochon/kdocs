import { expect, test, type Browser, type Page } from '@playwright/test';
import { execFileSync, spawn, type ChildProcess } from 'node:child_process';
import { createServer } from 'node:net';
import fs from 'node:fs';
import path from 'node:path';

/**
 * Couche 5 du harness (Personas) — mesure « trouvé / pas trouvé » et temps, comme un humain.
 *
 * Chaque geste part de l'écran d'accueil (redirection de connexion vers `/a-traiter`) et cherche la
 * fonction par les menus et les libellés visibles : jamais une URL directe, sauf la connexion.
 * Un geste non trouvé dans le budget de 30 s est un ÉCHEC NOMMÉ (persona, fonction, écran où le
 * persona s'est perdu) ; il n'est jamais sauté.
 *
 * Les captures vivent dans `recette/preuves/personas/<persona>-<geste>.png`, le verdict et les temps
 * dans `recette/preuves/personas/<persona>.json` (lu par le harness pour le registre).
 */

export const RACINE = process.cwd();
export const FONDS = path.join(RACINE, 'var', 'oracles', 'personas', 'documents');
export const PREUVES = path.join(RACINE, 'recette', 'preuves', 'personas');

/** Budget d'un geste : au-delà, le persona est perdu et le geste échoue nommément. */
export const BUDGET_GESTE_MS = 30_000;

export type Preparation = {
  mot_de_passe: string;
  comptes: Record<string, { id: number; identifiant: string; role: string }>;
  documents: Record<string, { id: number; chemin: string; titre: string }>;
  dossiers: Record<string, number>;
  champ_echeance: string;
  fonds: string;
};

export type Geste = {
  /** Slug du geste : nom de la capture (`<persona>-<nom>.png`). */
  nom: string;
  /** Fonction du plan F1 à F12 que le geste met à l'épreuve. */
  fonction: string;
  /** Ce que le persona cherche, en français, pour le rapport. */
  libelle: string;
  /** Navigation humaine (menus, libellés, clics) puis constat que la fonction est là. */
  jouer: (page: Page, prep: Preparation) => Promise<void>;
};

export type ResultatGeste = {
  nom: string;
  fonction: string;
  libelle: string;
  trouve: boolean;
  temps_ms: number;
  ecran: string;
  erreur: string;
};

export type ManifestPersona = {
  persona: string;
  role: string;
  compte: string;
  /** Tous les gestes attendus du persona, dans l'ordre : le harness calcule trouvés / total. */
  gestes: string[];
};

/** Petit PDF synthétique et UNIQUE : le dépôt d'un persona n'est jamais confondu avec un doublon. */
export function pdfUnique(): Buffer {
  const marqueur = `${Date.now()}-${Math.floor(Math.random() * 1_000_000)}`;

  return Buffer.from(`%PDF-1.4\n% persona ${marqueur}\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n`, 'latin1');
}

/** Prépare le fonds et les comptes RÉELS (base gedv2_test, verrou partagé). */
export function preparer(): Preparation {
  const sortie = execFileSync('php', [path.join('tests', 'e2e', 'personas', 'preparer-personas.php')], {
    cwd: RACINE,
    env: { ...process.env, DB_NAME: 'gedv2_test', APP_ENV: 'test', STORAGE_ROOT: FONDS },
  }).toString();

  return JSON.parse(sortie) as Preparation;
}

async function portLibre(): Promise<number> {
  return new Promise((resoudre, rejeter) => {
    const sonde = createServer();
    sonde.once('error', rejeter);
    sonde.listen(0, '127.0.0.1', () => {
      const adresse = sonde.address();
      const port = typeof adresse === 'object' && adresse !== null ? adresse.port : 0;
      sonde.close(() => resoudre(port));
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
  throw new Error('le serveur des personas n’a pas démarré');
}

/** Démarre un serveur PHP isolé sur le fonds personas (base gedv2_test). */
export async function demarrerServeur(): Promise<{ base: string; arreter: () => void }> {
  fs.mkdirSync(FONDS, { recursive: true });
  const port = await portLibre();
  const base = `http://127.0.0.1:${port}`;
  const serveur: ChildProcess = spawn('php', ['-d', 'opcache.enable=0', '-S', `127.0.0.1:${port}`, '-t', 'public', 'public/index.php'], {
    cwd: RACINE,
    env: { ...process.env, DB_NAME: 'gedv2_test', APP_ENV: 'test', STORAGE_ROOT: FONDS },
    stdio: 'ignore',
  });
  await attendreSante(base);

  return { base, arreter: () => serveur.kill() };
}

/** Connexion avec le compte RÉEL du persona (mot de passe haché), puis atterrissage sur l'accueil. */
export async function connecter(browser: Browser, base: string, prep: Preparation, persona: string) {
  const contexte = await browser.newContext({ baseURL: base, viewport: { width: 1440, height: 900 }, locale: 'fr-CH' });
  const page = await contexte.newPage();
  const compte = prep.comptes[persona];
  if (compte === undefined) {
    throw new Error(`persona inconnu : ${persona}`);
  }
  await page.goto('/connexion');
  await page.getByTestId('identifiant-connexion').fill(compte.identifiant);
  await page.getByTestId('mot-de-passe-connexion').fill(prep.mot_de_passe);
  await page.getByTestId('action-connexion').click({ timeout: 15_000 });
  await expect(page).toHaveURL(/\/a-traiter$/, { timeout: 15_000 });

  return { page, fermer: () => contexte.close() };
}

/** Décrit l'écran où le persona se trouve : URL et titre visible (pour nommer un échec). */
async function ecranCourant(page: Page): Promise<string> {
  const url = page.url();
  let titre = '';
  try {
    titre = ((await page.locator('h1').first().textContent({ timeout: 1_000 })) ?? '').trim().replace(/\s+/g, ' ');
  } catch {
    // pas de titre lisible : l'URL suffit à nommer l'écran
  }

  return titre === '' ? url : `${url} — ${titre}`;
}

/**
 * Joue un geste comme un humain : il part de l'accueil, navigue par les menus, et le budget de 30 s
 * court. Le résultat est toujours rendu — capture d'écran comprise — jamais sauté.
 */
export async function mesurerGeste(page: Page, persona: string, geste: Geste, prep: Preparation): Promise<ResultatGeste> {
  const debut = Date.now();
  let trouve = false;
  let erreur = '';
  let minuterie: NodeJS.Timeout | undefined;
  try {
    await Promise.race([
      geste.jouer(page, prep),
      new Promise<never>((_, rejeter) => {
        minuterie = setTimeout(
          () => rejeter(new Error(`fonction non trouvée en ${BUDGET_GESTE_MS / 1000} s`)),
          BUDGET_GESTE_MS,
        );
      }),
    ]);
    trouve = true;
  } catch (e) {
    erreur = e instanceof Error ? e.message.split('\n')[0] : String(e);
  } finally {
    if (minuterie !== undefined) clearTimeout(minuterie);
  }

  const temps_ms = Date.now() - debut;
  const ecran = await ecranCourant(page);
  const resultat: ResultatGeste = { nom: geste.nom, fonction: geste.fonction, libelle: geste.libelle, trouve, temps_ms, ecran, erreur };
  fs.mkdirSync(PREUVES, { recursive: true });
  await page.screenshot({ path: path.join(PREUVES, `${persona}-${geste.nom}.png`) }).catch(() => undefined);
  // Écrit IMMÉDIATEMENT : Playwright redémarre le worker après un échec, un état en mémoire serait perdu.
  fs.writeFileSync(path.join(PREUVES, `${persona}-${geste.nom}.json`), `${JSON.stringify(resultat, null, 2)}\n`, 'utf8');

  return resultat;
}

/** Écrit la liste des gestes attendus : le harness calcule trouvés / total même si un worker redémarre. */
export function ecrireManifest(persona: string, prep: Preparation, gestes: Geste[]): void {
  const manifeste: ManifestPersona = {
    persona,
    role: prep.comptes[persona].role,
    compte: prep.comptes[persona].identifiant,
    gestes: gestes.map((g) => g.nom),
  };
  fs.mkdirSync(PREUVES, { recursive: true });
  fs.writeFileSync(path.join(PREUVES, `${persona}.manifest.json`), `${JSON.stringify(manifeste, null, 2)}\n`, 'utf8');
}

/** Échec nommé d'un geste, prêt à lever dans le test (persona, fonction, écran où il s'est perdu). */
export function messageEchec(persona: string, resultat: ResultatGeste): string {
  return `PERSONA ${persona} — geste « ${resultat.nom} » (${resultat.fonction}) NON TROUVÉ : ${resultat.erreur} ; perdu sur ${resultat.ecran}`;
}

/**
 * Enregistre le parcours d'un persona : une préparation et un serveur pour le fichier, un test par
 * geste (donc un verdict, une capture et un temps chacun). Un geste non trouvé est un échec nommé,
 * jamais un test sauté.
 */
export function enregistrerParcours(persona: string, gestes: Geste[]): void {
  let prep: Preparation;
  let base = '';
  let arreter: (() => void) | undefined;

  test.beforeAll(async () => {
    test.setTimeout(120_000);
    prep = preparer();
    ecrireManifest(persona, prep, gestes);
    ({ base, arreter } = await demarrerServeur());
  });

  test.afterAll(() => {
    arreter?.();
  });

  for (const geste of gestes) {
    test(`${persona} — ${geste.nom} (${geste.fonction})`, async ({ browser }) => {
      test.setTimeout(45_000);
      const { page, fermer } = await connecter(browser, base, prep, persona);
      try {
        const resultat = await mesurerGeste(page, persona, geste, prep);
        if (!resultat.trouve) {
          throw new Error(messageEchec(persona, resultat));
        }
      } finally {
        await fermer();
      }
    });
  }
}
