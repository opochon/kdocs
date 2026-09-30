import { test, expect } from '@playwright/test';
import { connecter } from './connexion';
import fs from 'node:fs';
import path from 'node:path';

/**
 * Oracle V2-08c — parcours Playwright connecté : la liaison part de la GED.
 *
 * Mon profil → adresse vérifiée par le lien du `.eml` écrit en `var/` (transport fichier) → Relier
 * ClearMyMails → le `.eml` d'auto-configuration (X-GED-Appairage) est relu, l'échange {code, verification,
 * poste} est joué comme le ferait CMM → Mon profil › Appareils reliés → Révoquer → le jeton est refusé 401.
 */
const dossierMails = path.join(process.cwd(), 'var', 'mails');

/** Les courriels écrits par le transport « fichier », du plus ancien au plus récent. */
function mails(): { fichier: string; contenu: string }[] {
  if (!fs.existsSync(dossierMails)) return [];
  return fs
    .readdirSync(dossierMails)
    .filter((f) => f.endsWith('.eml'))
    .map((f) => {
      const fichier = path.join(dossierMails, f);
      return { fichier, contenu: fs.readFileSync(fichier, 'utf8'), mtime: fs.statSync(fichier).mtimeMs };
    })
    .sort((a, b) => a.mtime - b.mtime)
    .map(({ fichier, contenu }) => ({ fichier, contenu }));
}

async function attendreMail(contient: string): Promise<{ fichier: string; contenu: string }> {
  for (let essai = 0; essai < 50; ++essai) {
    const trouve = [...mails()].reverse().find((m) => m.contenu.includes(contient));
    if (trouve) return trouve;
    await new Promise((r) => setTimeout(r, 100));
  }
  throw new Error(`courriel jamais écrit en var/ pour « ${contient} »`);
}

/** Découpe un `.eml` en parties MIME (en-têtes / corps) sur la première frontière. */
function partiesMime(eml: string): { entetes: string; corps: string }[] {
  const texte = eml.replace(/\r\n/g, '\n');
  const frontiere = /boundary="?([^"\s;]+)"?/i.exec(texte)?.[1];
  if (!frontiere) {
    const i = texte.indexOf('\n\n');
    return i < 0 ? [] : [{ entetes: texte.slice(0, i), corps: texte.slice(i + 2) }];
  }
  const parties: { entetes: string; corps: string }[] = [];
  for (const morceau of texte.split('--' + frontiere)) {
    const i = morceau.indexOf('\n\n');
    if (i < 0) continue;
    parties.push({ entetes: morceau.slice(0, i), corps: morceau.slice(i + 2).replace(/\n+$/, '') });
  }
  return parties;
}

function decoder(corps: string, entetes: string): string {
  const encodage = /content-transfer-encoding:\s*(\S+)/i.exec(entetes)?.[1]?.toLowerCase() ?? '';
  if (encodage === 'base64') return Buffer.from(corps.replace(/\s/g, ''), 'base64').toString('utf8');
  if (encodage === 'quoted-printable') return Buffer.from(corps.replace(/=\r?\n/g, ''), 'binary').toString('utf8');
  return corps;
}

function texteMessage(eml: string): string {
  for (const partie of partiesMime(eml)) {
    if (/content-type:\s*text\/plain/i.test(partie.entetes)) return decoder(partie.corps, partie.entetes);
  }
  return '';
}

function pieceJson(eml: string, nom: string): Record<string, unknown> {
  for (const partie of partiesMime(eml)) {
    const fichier = /filename="?([^"\s;]+)"?/i.exec(partie.entetes)?.[1];
    if (fichier === nom) return JSON.parse(decoder(partie.corps, partie.entetes));
  }
  throw new Error(`pièce ${nom} absente du courriel`);
}

test('appairage : de Mon profil au courriel, échange CMM, appareils reliés et révocation', async ({ page }) => {
  await connecter(page);
  await page.goto('/profil');
  await expect(page.getByTestId('ecran-profil')).toBeVisible();

  // 1. Adresse du profil, vérifiée par le lien envoyé à cette adresse.
  const adresse = `profil-${Date.now()}@exemple.test`;
  await page.getByTestId('profil-mail-champ').fill(adresse);
  await page.getByTestId('action-profil-mail').click();
  await expect(page.getByTestId('profil-message')).toContainText('vérification');

  const verifMail = await attendreMail(adresse);
  const lien = /(\/profil\/mail\/verifier\?jeton=[A-Za-z0-9_-]+)/.exec(texteMessage(verifMail.contenu))?.[1];
  expect(lien).toBeTruthy();
  await page.goto(lien as string);
  await expect(page).toHaveURL(/\/profil/);
  await expect(page.getByTestId('profil-adresse-etat')).toContainText('vérifiée');

  // 2. Relier ClearMyMails : appairage en attente, code de vérification affiché, courriel écrit en var/.
  const poste = `cmm-e2e-${Date.now()}`;
  await page.getByTestId('action-profil-relier').click();
  await expect(page.getByTestId('appairage-en-attente')).toBeVisible();
  const verification = (await page.getByTestId('appairage-verification').innerText()).trim();

  const autoMail = await attendreMail('X-GED-Appairage');
  const piece = pieceJson(autoMail.contenu, 'ged-appairage.json');
  expect(piece.version).toBe(1);
  expect(piece.compte).toBeTruthy();
  expect(piece.verification).toBe(verification);
  expect(String(piece.code)).toHaveLength(64);

  // 3. Un code de vérification faux est refusé 400, nommé, sans consommer le code d'échange.
  const faux = await page.request.post('/api/v1/appairage/jeton', {
    data: { code: piece.code, verification: 'AAA-BBB', poste },
  });
  expect(faux.status()).toBe(400);
  expect((await faux.json()).erreur.cause).toContain('vérification');

  // 4. Échange légitime, comme le ferait CMM.
  const echange = await page.request.post('/api/v1/appairage/jeton', {
    data: { code: piece.code, verification, poste },
  });
  expect(echange.status()).toBe(200);
  const jeton = (await echange.json()).jeton;
  expect(jeton).toBeTruthy();

  // Rejeu du même code : refusé.
  const rejeu = await page.request.post('/api/v1/appairage/jeton', {
    data: { code: piece.code, verification, poste },
  });
  expect(rejeu.status()).toBe(400);

  // 5. Mon profil › Appareils reliés, puis révocation.
  await page.goto('/profil');
  const ligne = page.locator('[data-testid="appareil"]').filter({ hasText: poste });
  await expect(ligne).toBeVisible();
  await expect(ligne).toContainText('ClearMyMails');
  await ligne.getByTestId('action-revoquer-appareil').click();
  await expect(page).toHaveURL(/\/profil/);
  await expect(page.locator('[data-testid="appareil"]').filter({ hasText: poste })).toContainText('révoqué');

  // 6. Le jeton révoqué est refusé 401, nommé.
  const apres = await page.request.get('/api/v1/appairage/moi', { headers: { Authorization: `Bearer ${jeton}` } });
  expect(apres.status()).toBe(401);
});

test('profil : sans session, la GED refuse et nomme', async ({ page }) => {
  const refus = await page.goto('/profil');
  expect(refus?.status()).toBe(403);
  await expect(page.getByTestId('refus-acces')).toBeVisible();
});
