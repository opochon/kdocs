import { test, expect, type Browser, type Page } from '@playwright/test';
import { execFileSync, spawn, type ChildProcess } from 'node:child_process';
import { createServer } from 'node:net';
import fs from 'node:fs';
import path from 'node:path';

const RACINE = process.cwd(); const FONDS = path.join(RACINE, 'var', 'oracles', 'v2-11', 'documents');
type Preparation = { compte:string; mot_de_passe:string; document:number; image:number; scelle:number; pdf_octets:string; image_octets:string };
let serveur: ChildProcess | undefined; let base = ''; let prep: Preparation;
async function portLibre(): Promise<number> { return new Promise((ok, ko) => { const s=createServer(); s.once('error',ko); s.listen(0,'127.0.0.1',()=>{const a=s.address(); const p=typeof a==='object'&&a?a.port:0; s.close(()=>ok(p));});}); }
async function attente(url:string): Promise<void> { for(let i=0;i<100;i++){try{if((await fetch(`${url}/sante`)).ok)return;}catch{} await new Promise(r=>setTimeout(r,100));} throw new Error('serveur V2-11 indisponible'); }
function preparer():Preparation { return JSON.parse(execFileSync('php',['tests/e2e/preparer-v2-11.php'],{cwd:RACINE,env:{...process.env,DB_NAME:'gedv2_test',APP_ENV:'test',STORAGE_ROOT:FONDS}}).toString()) as Preparation; }
async function connecter(page:Page):Promise<void>{ await page.goto('/connexion'); await page.getByTestId('identifiant-connexion').fill(prep.compte); await page.getByTestId('mot-de-passe-connexion').fill(prep.mot_de_passe); await page.getByTestId('action-connexion').click(); await expect(page).toHaveURL(/\/a-traiter$/); }
test.describe.configure({mode:'serial'});
test.beforeAll(async()=>{const port=await portLibre();base=`http://127.0.0.1:${port}`;serveur=spawn('php',['-d','opcache.enable=0','-S',`127.0.0.1:${port}`,'-t','public','public/index.php'],{cwd:RACINE,env:{...process.env,DB_NAME:'gedv2_test',APP_ENV:'test',STORAGE_ROOT:FONDS},stdio:'ignore'});await attente(base);prep=preparer();});
test.afterAll(()=>serveur?.kill());

test('oracle : aucun lien mort ni bouton visible sans mécanisme sur les écrans documentaires', async({browser})=>{
  const c=await browser.newContext({baseURL:base}); const page=await c.newPage(); try { await connecter(page);
    for (const url of [`/documents/${prep.document}`,'/bibliotheque','/a-traiter','/application','/recherche']) {
      await page.goto(url);
      const morts=await page.locator('a[href="#"]').count(); expect(morts,`${url} contient un href=#`).toBe(0);
      const inertes=await page.locator('button:visible').evaluateAll((boutons) => boutons.filter((b) => {
        const action=b.getAttribute('data-action');
        return !b.closest('form') && !action && b.getAttribute('role') !== 'tab';
      }).map((b) => b.getAttribute('data-testid') || b.textContent?.trim()));
      expect(inertes,`${url} contient un bouton sans formulaire ni gestionnaire`).toEqual([]);
    }
    await page.goto(`/documents/${prep.document}`);
    await page.getByTestId('onglet-versions').click(); await expect(page.getByTestId('panneau-versions')).toBeVisible();
    await page.getByTestId('onglet-historique').click(); await expect(page.getByTestId('panneau-historique')).toBeVisible();
    // V2-12d rend ces gestes réels : ils ne sont plus retirés de la fiche.
    await expect(page.getByTestId('onglet-notes')).toHaveCount(1); await expect(page.getByTestId('onglet-liens')).toHaveCount(1);
  } finally { await c.close(); }
});

test('télécharger sert exactement le PDF et l’image originaux avec leur type', async({browser})=>{
  const c=await browser.newContext({baseURL:base}); const page=await c.newPage(); try { await connecter(page);
    for (const [id, attendu, mime] of [[prep.document,prep.pdf_octets,'application/pdf'],[prep.image,prep.image_octets,'image/png']] as const) {
      const reponse=await page.request.get(`${base}/documents/${id}/fichier`); expect(reponse.status()).toBe(200); expect(reponse.headers()['content-type']).toContain(mime); expect(Buffer.compare(Buffer.from(await reponse.body()),Buffer.from(attendu,'base64'))).toBe(0); expect(reponse.headers()['content-disposition']).toContain('attachment'); expect(reponse.headers()['content-disposition']).toContain('filename=');
    }
  } finally { await c.close(); }
});

test('nouvelle version archive v1, affiche v2 et refuse un document scellé', async({browser})=>{
  const c=await browser.newContext({baseURL:base}); const page=await c.newPage(); const nouveau=path.join(RACINE,'var','oracles','v2-11','nouvelle-version.pdf'); fs.mkdirSync(path.dirname(nouveau),{recursive:true}); fs.writeFileSync(nouveau,'%PDF-1.4\n% version deux synthétique\n%%EOF\n'); try { await connecter(page);
    await page.goto(`/documents/${prep.document}`); await page.getByTestId('document-fichier-version').setInputFiles(nouveau); await page.getByTestId('document-envoyer-version').click(); await expect(page.getByTestId('version-succes')).toBeVisible(); await page.getByTestId('onglet-versions').click(); await expect(page.getByTestId('panneau-versions')).toContainText('v2'); await expect(page.getByTestId('panneau-versions')).toContainText('v1'); await page.getByTestId('onglet-historique').click(); await expect(page.getByTestId('panneau-historique')).toContainText('Version v1 archivée');
    await page.goto(`/documents/${prep.scelle}`); await expect(page.getByTestId('document-nouvelle-version')).toHaveCount(0);
    const csrf=(await page.context().cookies()).find((c)=>c.name==='gedv2_csrf')?.value; const r=await page.request.post(`${base}/documents/${prep.scelle}/versions`, {headers:{'X-CSRF-Token':csrf!},multipart:{fichier:{name:'version-scellée.pdf',mimeType:'application/pdf',buffer:Buffer.from('%PDF-1.4\n% refus scellement\n%%EOF\n')}},maxRedirects:0}); expect(r.status()).toBe(403); expect(await r.text()).toContain('nouvelle version');
  } finally { await c.close(); }
});
