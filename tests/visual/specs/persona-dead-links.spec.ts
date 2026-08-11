import { test, expect, type Page } from '@playwright/test';

// Persona qui parcourt l'application comme un utilisateur réel et signale les
// CHEMINS MORTS : liens/boutons vers 404/500, hrefs de route interne sans le
// préfixe de base (/kdocs), liens vides (#, javascript:void(0)) sans
// gestionnaire, et miniatures qui ne chargent jamais (naturalWidth === 0).
//
// Défaut fondateur reproduit ici (parmi d'autres du même type) :
// app/Services/TaskUnifiedService.php construit 'link' en chemin brut
// ('/admin/consume', '/documents/{id}', '/workflow/approve/{token}') au lieu
// de passer par url(), donc sans le préfixe /kdocs. Le href est lisible et
// classifiable AVANT tout clic — c'est ce que ce test fait : lecture statique
// du DOM, puis une requête GET (pas un clic UI) pour obtenir le code réel,
// afin de ne jamais déclencher une action via un clic sur un bouton mutateur.
//
// Contrainte de sécurité : ZERO clic sur une action destructrice. On ne fait
// que lire des hrefs et, pour ceux qui semblent viser une route interne, une
// requête GET en dehors de toute interaction UI. Un lien/bouton dont le href
// ou le libellé matche la liste noire ci-dessous n'est ni cliqué ni requêté.

const BASE_PATH = process.env.KDOCS_BASE_PATH ?? '/kdocs';
const HOST = process.env.KDOCS_HOST ?? '127.0.0.1';
const PORT = process.env.KDOCS_PORT ?? '8765';
const BASE = `http://${HOST}:${PORT}${BASE_PATH}`;
const ORIGIN = `http://${HOST}:${PORT}`;

// Liste noire — jamais suivie, jamais requêtée. Preuve d'application : voir
// isBlacklisted() et son usage unique juste avant toute classification.
const DESTRUCTIVE_HREF_PATTERNS = [/\/delete(\b|\/|$)/i, /\/purge(\b|\/|$)/i, /\/trash(\b|\/|$)/i, /\/validate(\b|\/|$)/i];
const DESTRUCTIVE_LABEL_WORDS = ['supprimer', 'purger', 'vider la corbeille', 'vider', 'valider en masse'];

function isBlacklisted(href: string, label: string): boolean {
  const h = href.toLowerCase();
  const l = label.toLowerCase();
  return DESTRUCTIVE_HREF_PATTERNS.some((re) => re.test(h)) || DESTRUCTIVE_LABEL_WORDS.some((w) => l.includes(w));
}

// Écrans réels à parcourir (au minimum ceux demandés par Olivier).
// heavy: true => pages connues pour figer le navigateur (bibliothèque 50
// miniatures cassées, file consume 385 formulaires / 1540 selects / 36824
// noeuds) : pas d'attente networkidle, extraction sous timeout serré.
const SCREENS: { name: string; path: string; heavy?: boolean }[] = [
  { name: 'tableau-de-bord', path: '/' },
  { name: 'bibliotheque', path: '/documents', heavy: true },
  { name: 'recherche', path: '/search' },
  { name: 'mes-taches', path: '/mes-taches' },
  { name: 'admin-consume', path: '/admin/consume', heavy: true },
  { name: 'hub-admin', path: '/admin' },
  { name: 'import', path: '/documents/upload' },
];

type DeadPath = {
  screen: string;
  label: string;
  href: string | null;
  resolved: string | null;
  code: number | string;
  reason: string;
};

type Playability = { screen: string; url: string; reason: string };

type AnchorInfo = { href: string | null; label: string; hasHandler: boolean };

// Extraction DOM : tous les <a> de la page (couvre sidebar + cartes/lignes de
// contenu — la sidebar est incluse dans le DOM de chaque écran).
async function extractAnchors(page: Page): Promise<AnchorInfo[]> {
  return page.$$eval('a', (as) =>
    as.map((a) => ({
      href: a.getAttribute('href'),
      label: (a.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 90),
      hasHandler: !!(a.getAttribute('onclick') || a.id || Object.keys((a as HTMLElement).dataset || {}).length > 0),
    })),
  );
}

async function extractBrokenImages(page: Page): Promise<{ total: number; brokenCount: number; examples: string[] }> {
  return page.$$eval('img', (imgs) => {
    const total = imgs.length;
    const broken = imgs
      .filter((img) => img.complete && img.naturalWidth === 0)
      .map((img) => img.getAttribute('src') || img.currentSrc || '(sans src)');
    return { total, brokenCount: broken.length, examples: broken.slice(0, 5) };
  });
}

function withTimeout<T>(p: Promise<T>, ms: number, label: string): Promise<T> {
  return Promise.race([
    p,
    new Promise<T>((_, reject) => setTimeout(() => reject(new Error(`${label} — hors delai (${ms}ms)`)), ms)),
  ]);
}

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

test.describe('persona: chemins morts', () => {
  test('parcours réel — détection des liens/boutons morts', async ({ page }) => {
    test.setTimeout(5 * 60_000);

    const deadPaths: DeadPath[] = [];
    const playability: Playability[] = [];
    const skipped: { screen: string; label: string; href: string }[] = [];
    // 429 = limite de débit de l'app (RATE_LIMIT_MAX) déclenchée par CE test
    // qui vérifie beaucoup de liens vite — pas une preuve de chemin mort.
    // Reporté séparément, jamais compté comme chemin mort.
    const rateLimited: { screen: string; label: string; href: string; resolved: string }[] = [];
    // Dédup des requêtes GET par URL résolue — évite de marteler le serveur
    // quand un même href fautif se répète sur des dizaines de cartes.
    const checkedUrls = new Map<string, DeadPath | null>();
    const MAX_LIVE_CHECKS_PER_SCREEN = 15;
    const DELAY_BETWEEN_LIVE_CHECKS_MS = 200;

    for (const screen of SCREENS) {
      const url = `${BASE}${screen.path}`;
      console.log(`\n--- Écran: ${screen.name} (${url}) ---`);

      let resp;
      try {
        resp = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 45_000 });
      } catch (e) {
        playability.push({ screen: screen.name, url, reason: `page injouable — goto en échec/hors délai: ${String(e).slice(0, 300)}` });
        console.log(`  [INJOUABLE] ${String(e).slice(0, 300)}`);
        continue;
      }
      if (!resp) {
        playability.push({ screen: screen.name, url, reason: 'page injouable — aucune réponse de navigation' });
        continue;
      }
      if (resp.status() === 429) {
        // Rate-limit déclenché par ce test lui-même (beaucoup de vérifications
        // rapides) — pas une preuve que l'écran est mort. On patiente et on
        // retente une fois avant de conclure quoi que ce soit.
        rateLimited.push({ screen: screen.name, label: '(écran de départ)', href: screen.path, resolved: url });
        await sleep(3_000);
        try {
          resp = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 45_000 });
        } catch (e) {
          playability.push({ screen: screen.name, url, reason: `page injouable après retentative post rate-limit: ${String(e).slice(0, 300)}` });
          continue;
        }
      }
      if (!resp) {
        playability.push({ screen: screen.name, url, reason: 'page injouable — aucune réponse de navigation (après retentative)' });
        continue;
      }
      if (resp.status() === 429) {
        // Toujours rate-limité après la pause : écran non concluant, pas mort.
        rateLimited.push({ screen: screen.name, label: '(écran de départ, encore 429 après retentative)', href: screen.path, resolved: url });
        continue;
      }
      if (resp.status() >= 500) {
        deadPaths.push({ screen: 'entrée', label: screen.name, href: screen.path, resolved: url, code: resp.status(), reason: "l'écran de départ lui-même répond en erreur serveur" });
        console.log(`  [MORT] écran de départ en ${resp.status()}`);
        continue;
      }
      if (resp.status() >= 400 && resp.status() !== 429) {
        deadPaths.push({ screen: 'entrée', label: screen.name, href: screen.path, resolved: url, code: resp.status(), reason: "l'écran de départ lui-même est introuvable" });
        console.log(`  [MORT] écran de départ en ${resp.status()}`);
        continue;
      }

      if (!screen.heavy) {
        try {
          await page.waitForLoadState('networkidle', { timeout: 15_000 });
        } catch {
          // best-effort : on continue avec ce qui est rendu.
        }
      } else {
        // Écran connu pour figer (miniatures cassées / DOM énorme) : pas de
        // networkidle, juste une pause courte pour laisser le rendu initial.
        await page.waitForTimeout(2_500).catch(() => {});
      }

      let anchors: AnchorInfo[];
      try {
        anchors = await withTimeout(extractAnchors(page), screen.heavy ? 20_000 : 15_000, `extraction des liens (${screen.name})`);
      } catch (e) {
        playability.push({ screen: screen.name, url, reason: `page injouable — ${String(e).slice(0, 300)}` });
        console.log(`  [INJOUABLE] ${String(e).slice(0, 300)}`);
        continue;
      }
      console.log(`  ${anchors.length} lien(s) <a> trouvé(s)`);

      // --- Miniatures cassées (naturalWidth === 0 après tentative de chargement) ---
      try {
        const imgs = await withTimeout(extractBrokenImages(page), 15_000, `scan images (${screen.name})`);
        if (imgs.brokenCount > 0) {
          deadPaths.push({
            screen: screen.name,
            label: 'miniatures',
            href: null,
            resolved: null,
            code: 'IMG',
            reason: `${imgs.brokenCount}/${imgs.total} balise(s) <img> jamais chargée(s) (naturalWidth=0) — ex: ${imgs.examples.join(', ') || '(sans src)'}`,
          });
          console.log(`  [MORT] ${imgs.brokenCount}/${imgs.total} images cassées`);
        }
      } catch (e) {
        playability.push({ screen: screen.name, url, reason: `scan images hors délai — ${String(e).slice(0, 200)}` });
      }

      let liveChecksThisScreen = 0;

      for (const a of anchors) {
        if (a.href === null) continue; // <a> sans href : rien à suivre
        const href = a.href.trim();
        const label = a.label || '(sans libellé)';

        if (isBlacklisted(href, label)) {
          skipped.push({ screen: screen.name, label, href });
          continue;
        }

        // Lien vide / ancre / no-op JS sans gestionnaire détecté.
        if (href === '' || href === '#' || /^javascript:\s*void\(\s*0\s*\)\s*;?$/i.test(href)) {
          if (!a.hasHandler) {
            deadPaths.push({ screen: screen.name, label, href, resolved: null, code: 'N/A', reason: 'lien vide/# sans gestionnaire (pas d\'onclick, id ni attribut data-*)' });
            console.log(`  [MORT] "${label}" -> href vide sans gestionnaire`);
          }
          continue;
        }

        if (/^(mailto:|tel:)/i.test(href)) continue;

        // Résolution contre l'URL courante (reproduit exactement ce que ferait
        // un navigateur en cliquant sur ce href).
        let resolved: URL;
        try {
          resolved = new URL(href, url);
        } catch {
          deadPaths.push({ screen: screen.name, label, href, resolved: null, code: 'ERR', reason: 'href malformé — impossible à résoudre en URL' });
          continue;
        }

        // Externe (autre origine) : hors périmètre "route interne".
        if (resolved.origin !== ORIGIN) continue;

        const missingPrefix =
          resolved.pathname.startsWith('/') &&
          !resolved.pathname.startsWith(BASE_PATH) &&
          !/^\/(public\/|favicon\.ico$|robots\.txt$|health$)/i.test(resolved.pathname);

        const resolvedUrl = resolved.toString();

        if (checkedUrls.has(resolvedUrl)) {
          const prior = checkedUrls.get(resolvedUrl);
          if (prior) deadPaths.push({ ...prior, screen: screen.name, label }); // même défaut revu depuis un autre écran/libellé
          continue;
        }

        if (liveChecksThisScreen >= MAX_LIVE_CHECKS_PER_SCREEN) {
          // Volume trop grand (ex. une carte "Voir" par document, des
          // dizaines de fois) : le href sans préfixe est déjà détectable
          // statiquement, donc reporté même sans requête live au-delà du cap.
          if (missingPrefix) {
            const rec: DeadPath = { screen: screen.name, label, href, resolved: resolvedUrl, code: 'N/A (echantillon)', reason: `href sans préfixe ${BASE_PATH} — route interne visée en chemin brut (au-delà du cap de vérification live de cet écran)` };
            deadPaths.push(rec);
          }
          continue;
        }

        liveChecksThisScreen += 1;
        if (liveChecksThisScreen > 1) await sleep(DELAY_BETWEEN_LIVE_CHECKS_MS); // étale les requêtes, évite de déclencher le rate-limit de l'appli
        let status: number | string = 'ERR';
        let errMsg = '';
        for (let attempt = 0; attempt < 2; attempt++) {
          try {
            const r = await page.context().request.get(resolvedUrl, { timeout: 15_000, maxRedirects: 5, failOnStatusCode: false });
            status = r.status();
            errMsg = '';
          } catch (e) {
            errMsg = String(e).slice(0, 200);
          }
          if (status !== 429) break;
          await sleep(3_000); // une seule retentative après un backoff, avant de renoncer à conclure
        }

        // 429 = notre propre vérification déclenche le rate-limit de l'appli
        // (RATE_LIMIT_MAX) — ce n'est pas une preuve de chemin mort, la route
        // existe. Reporté séparément, jamais compté comme échec.
        if (status === 429) {
          rateLimited.push({ screen: screen.name, label, href, resolved: resolvedUrl });
          checkedUrls.set(resolvedUrl, null);
          continue;
        }

        let rec: DeadPath | null = null;
        if (errMsg) {
          rec = { screen: screen.name, label, href, resolved: resolvedUrl, code: 'ERR', reason: `requête GET en échec: ${errMsg}` };
        } else if (missingPrefix) {
          rec = { screen: screen.name, label, href, resolved: resolvedUrl, code: status, reason: `href de route interne SANS le préfixe ${BASE_PATH} (chemin brut, pas de url()) — réponse réelle ${status}` };
        } else if (typeof status === 'number' && status >= 400) {
          rec = { screen: screen.name, label, href, resolved: resolvedUrl, code: status, reason: status >= 500 ? 'erreur serveur (500+)' : 'introuvable (404+)' };
        }

        checkedUrls.set(resolvedUrl, rec);
        if (rec) {
          deadPaths.push(rec);
          console.log(`  [MORT] "${label}" -> ${href} (résolu: ${resolvedUrl}) code=${rec.code} — ${rec.reason}`);
        }
      }
    }

    // --- Rapport lisible -----------------------------------------------------
    const lines: string[] = [];
    lines.push('');
    lines.push('='.repeat(78));
    lines.push(`RAPPORT — chemins morts (${deadPaths.length}) / écrans injouables (${playability.length})`);
    lines.push('='.repeat(78));
    if (deadPaths.length === 0) {
      lines.push('Aucun chemin mort détecté.');
    }
    for (const d of deadPaths) {
      lines.push(
        `- écran="${d.screen}" libellé="${d.label}" href="${d.href ?? '(n/a)'}" url="${d.resolved ?? '(n/a)'}" code=${d.code}\n  -> ${d.reason}`,
      );
    }
    if (playability.length) {
      lines.push('');
      lines.push('--- Écrans injouables (rendus explicites, pas sautés) ---');
      for (const p of playability) {
        lines.push(`- écran="${p.screen}" url="${p.url}"\n  -> ${p.reason}`);
      }
    }
    lines.push('');
    lines.push(`--- Liens ignorés (liste noire — jamais suivis) : ${skipped.length} ---`);
    for (const s of skipped.slice(0, 20)) {
      lines.push(`- écran="${s.screen}" libellé="${s.label}" href="${s.href}"`);
    }
    if (skipped.length > 20) lines.push(`  ... et ${skipped.length - 20} de plus.`);
    if (rateLimited.length) {
      lines.push('');
      lines.push(`--- Non concluant : rate-limit (429) déclenché par ce test lui-même — pas un chemin mort : ${rateLimited.length} ---`);
      for (const r of rateLimited.slice(0, 20)) {
        lines.push(`- écran="${r.screen}" libellé="${r.label}" href="${r.href}"`);
      }
      if (rateLimited.length > 20) lines.push(`  ... et ${rateLimited.length - 20} de plus.`);
    }
    lines.push('='.repeat(78));
    const report = lines.join('\n');
    console.log(report);

    expect(deadPaths.length, report).toBe(0);
    expect(playability.length, report).toBe(0);
  });
});
