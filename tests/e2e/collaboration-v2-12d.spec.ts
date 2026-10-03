import { test, expect, type Browser, type Page } from '@playwright/test';
import { execFileSync, spawn, type ChildProcess } from 'node:child_process';
import { createServer } from 'node:net';
import fs from 'node:fs';
import path from 'node:path';

const RACINE = process.cwd();
type Prep = { compte:string; mot_de_passe:string; document:number; cible:number };
async function portLibre(): Promise<number> { return new Promise((ok, ko) => { const s=createServer(); s.once('error',ko); s.listen(0,'127.0.0.1',()=>{const a=s.address();const p=typeof a==='object'&&a?a.port:0;s.close(()=>ok(p));}); }); }
async function attente(base:string): Promise<void> { for(let i=0;i<100;i++){ try { if((await fetch(base+'/sante')).ok)return; }catch{} await new Promise(r=>setTimeout(r,100)); } throw new Error('serveur V2-12d indisponible'); }
async function connecter(page:Page, prep:Prep):Promise<void> { await page.goto('/connexion'); await page.getByTestId('identifiant-connexion').fill(prep.compte); await page.getByTestId('mot-de-passe-connexion').fill(prep.mot_de_passe); await page.getByTestId('action-connexion').click(); await expect(page).toHaveURL(/\/a-traiter$/); }
async function avecGeste<T>(geste:'notes'|'liens'|'partager'|'reconstruction', fn:(base:string, prep:Prep)=>Promise<T>):Promise<T> {
  const fonds=path.join(RACINE,'var','oracles','v2-12d',geste,'documents'); const audit=path.join(RACINE,'var','oracles','v2-12d',geste,'audit');
  const env={...process.env,DB_NAME:'gedv2_test',APP_ENV:'test',STORAGE_ROOT:fonds,SECURITE_DOSSIER:audit};
  const prep=JSON.parse(execFileSync('php',['tests/e2e/preparer-v2-12d.php',geste],{cwd:RACINE,env}).toString()) as Prep;
  const port=await portLibre(); const base=`http://127.0.0.1:${port}`; const serveur=spawn('php',['-d','opcache.enable=0','-S',`127.0.0.1:${port}`,'-t','public','public/index.php'],{cwd:RACINE,env,stdio:'ignore'});
  try { await attente(base); return await fn(base,prep); } finally { serveur.kill(); }
}

test.describe.configure({mode:'serial'});
test('Notes, téléphone : ajouter, lire, modifier et retirer sa note laisse une trace et l’index réel', async({browser}) => {
  await avecGeste('notes', async(base, prep) => { const c=await browser.newContext({baseURL:base,viewport:{width:390,height:844}}); const page=await c.newPage(); try {
    await connecter(page,prep); await page.goto(`/documents/${prep.document}`); await page.getByTestId('onglet-notes').click(); await page.getByTestId('note-texte').fill('note téléphone cherchable'); await page.getByTestId('note-enregistrer').click(); await page.getByTestId('onglet-notes').click(); const note=page.locator('[data-testid^="note-modifier-"]'); await expect(note).toBeVisible();
    await page.locator('[data-testid^="note-texte-"]').fill('note téléphone modifiée'); await page.locator('[data-testid^="note-enregistrer-"]').click(); await page.getByTestId('onglet-historique').click(); await expect(page.getByTestId('panneau-historique')).toContainText('Note modifiée');
    await page.getByTestId('onglet-notes').click(); await page.locator('[data-testid^="note-retirer-"]').click(); await expect(page.getByTestId('panneau-notes')).toContainText('Aucune note'); await page.goto('/recherche?q=t%C3%A9l%C3%A9phone'); await expect(page.getByTestId(`resultat-${prep.document}`)).toHaveCount(0);
  } finally { await c.close(); } });
});

test('Liens, bureau : libellé bilatéral et retrait réel', async({browser}) => {
  await avecGeste('liens', async(base, prep) => { const c=await browser.newContext({baseURL:base});const page=await c.newPage(); try {
    await connecter(page,prep); await page.goto(`/documents/${prep.document}`); await page.getByTestId('onglet-liens').click(); await page.getByTestId('lien-cible').fill(String(prep.cible)); await page.getByTestId('lien-libelle').fill('facture vers contrat'); await page.getByTestId('lien-enregistrer').click(); await page.getByTestId('onglet-liens').click(); await expect(page.getByTestId('panneau-liens')).toContainText('facture vers contrat');
    await page.goto(`/documents/${prep.cible}`); await page.getByTestId('onglet-liens').click(); await expect(page.getByTestId('panneau-liens')).toContainText('Facture V2-12d'); await page.locator('[data-testid^="lien-retirer-"]').click(); await page.getByTestId('onglet-liens').click(); await expect(page.getByTestId('panneau-liens')).toContainText('Aucun lien');
  } finally { await c.close(); } });
});

test('Partager, téléphone : le jeton n’ouvre que le document, expire et se révoque', async({browser}) => {
  await avecGeste('partager', async(base, prep) => { const c=await browser.newContext({baseURL:base,viewport:{width:390,height:844}});const page=await c.newPage(); try {
    await connecter(page,prep); await page.goto(`/documents/${prep.document}`); await page.getByTestId('onglet-partager').click(); await page.getByTestId('partage-expiration').fill('2099-01-01'); await page.getByTestId('partage-externe-envoyer').click(); const lien=await page.getByTestId('partage-lien').locator('code').textContent(); expect(lien).toMatch(/^\/partages\/[A-Za-z0-9_-]{40,}$/);
    const externe=await browser.newContext({baseURL:base}); const lecteur=await externe.newPage(); await lecteur.goto(lien!); await expect(lecteur.getByTestId('partage-externe')).toBeVisible(); await expect(lecteur.getByTestId('partage-telecharger')).toBeVisible(); expect((await lecteur.locator('a,form,button').allTextContents()).join(' ')).not.toContain('Modifier');
    const faux=await lecteur.goto('/partages/'+ 'x'.repeat(43)); expect(faux?.status()).toBe(403); const reutilise=await lecteur.goto(`/documents/${prep.document}?jeton=${encodeURIComponent(lien!)}`); expect(reutilise?.status()).toBe(403); await externe.close();
    await page.goto(`/documents/${prep.document}`); await page.getByTestId('onglet-partager').click(); await page.locator('[data-testid^="partage-revoquer-"]').click(); const apres=await page.request.get(base+lien!); expect(apres.status()).toBe(403); const audit=fs.readFileSync(path.join(RACINE,'var','oracles','v2-12d','partager','audit','audit.ndjson'),'utf8'); expect(audit).toContain('partage_externe_acces'); expect(audit).not.toContain(lien!.slice('/partages/'.length));
  } finally { await c.close(); } });
});

test('Reconstruction, bureau : progression en tranches et second lancement refusé', async({browser}) => {
  await avecGeste('reconstruction', async(base, prep) => { const c=await browser.newContext({baseURL:base});const page=await c.newPage(); try {
    await connecter(page,prep); await page.goto('/application'); await page.getByTestId('reconstruction-lancer').click(); await expect(page.getByTestId('reconstruction-progression')).toContainText('0 / 26'); const csrf=(await page.context().cookies()).find(x=>x.name==='gedv2_csrf')?.value; const second=await page.request.post(base+'/application/reconstruction-index',{headers:{'X-CSRF-Token':csrf!},maxRedirects:0}); expect(second.status()).toBe(422); await page.goto('/application'); await page.getByTestId('reconstruction-tranche').click(); await expect(page.getByTestId('reconstruction-progression')).toContainText('25 / 26'); await page.getByTestId('reconstruction-tranche').click(); await expect(page.getByTestId('reconstruction-terminee')).toBeVisible();
  } finally { await c.close(); } });
});
