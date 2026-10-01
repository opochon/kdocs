// GEDv2 — visionneuse du secteur Recherche & aperçu.
// JS natif, aucun framework. pdf.js est servi LOCALEMENT (`/assets/vendor/pdfjs/`), jamais depuis un CDN.
// Aucune couleur en dur : uniquement les jetons du système visuel (`ged.css`).

const CSS = `
.visionneuse.doc { display: flex; flex-direction: column; align-items: stretch; gap: 0; padding: 0; min-height: 0; overflow: hidden; background: var(--surface-2); }
.visionneuse-barre { display: flex; align-items: center; gap: 8px; padding: 6px 8px; background: var(--surface); border-bottom: 1px solid var(--border); }
.visionneuse-zoom, .visionneuse-page { min-width: 56px; text-align: center; color: var(--text-2); }
.visionneuse-clavier { margin-left: auto; font-size: var(--fs-sm); }
.visionneuse-pages { position: relative; flex: 1; min-height: 0; overflow: auto; padding: 14px; display: flex; flex-direction: column; align-items: center; gap: 14px; background: var(--surface-2); outline: none; }
.visionneuse-pages:focus-visible { outline: 2px solid var(--accent); outline-offset: -2px; }
.visionneuse-feuille { flex: none; background: var(--paper); box-shadow: var(--shadow); border-radius: 2px; line-height: 0; }
.visionneuse-feuille canvas { display: block; max-width: 100%; height: auto; }
.visionneuse-image { display: block; max-width: 100%; box-shadow: var(--shadow); background: var(--paper); transform-origin: center top; }
.visionneuse-message { margin: auto; padding: 18px 20px; border-radius: var(--r); background: var(--warn-soft); color: var(--warn); font-weight: 500; }
.visionneuse-erreur { margin: auto; color: var(--danger); }
.visionneuse-courriel, .courriel { max-width: 860px; width: 100%; background: var(--paper); color: var(--text); border-radius: var(--r); box-shadow: var(--shadow); padding: 20px; line-height: 1.5; }
.courriel-entetes { margin: 0 0 14px; display: grid; grid-template-columns: max-content 1fr; gap: 2px 12px; }
.courriel-entetes dt { color: var(--text-2); font-weight: 600; }
.courriel-entetes dd { margin: 0; }
.courriel-corps { overflow-wrap: anywhere; }
.courriel-pieces { margin-top: 16px; border-top: 1px solid var(--border); padding-top: 10px; }
.courriel-pieces h3 { font-size: var(--fs-sm); color: var(--text-2); margin-bottom: 6px; }
.visionneuse-chargement { margin: auto; display: flex; align-items: center; gap: 8px; }
.visionneuse-ecran { height: 100vh; display: flex; flex-direction: column; background: var(--bg); }
.visionneuse-tete { display: flex; align-items: center; gap: 12px; padding: 10px 16px; background: var(--surface); border-bottom: 1px solid var(--border); }
.visionneuse-tete h1 { font-size: var(--fs-lg); }
.visionneuse-tete [data-action="theme"] { margin-left: auto; }
.visionneuse-ecran .visionneuse.doc { flex: 1; min-height: 0; }
`;

function injecterStyles() {
  if (document.getElementById('visionneuse-styles')) return;
  const style = document.createElement('style');
  style.id = 'visionneuse-styles';
  style.textContent = CSS;
  document.head.appendChild(style);
}
injecterStyles();

const PDF_JS = '/assets/vendor/pdfjs/pdf.min.js';
const PDF_WORKER = '/assets/vendor/pdfjs/pdf.worker.min.js';
let pdfJsPromesse = null;

/** Charge pdf.js à la première visionneuse PDF seulement, puis met en place le worker local. */
function chargerPdfJs() {
  if (window.pdfjsLib) {
    window.pdfjsLib.GlobalWorkerOptions.workerSrc = PDF_WORKER;
    return Promise.resolve(window.pdfjsLib);
  }
  if (!pdfJsPromesse) {
    pdfJsPromesse = new Promise((resoudre, rejeter) => {
      const script = document.createElement('script');
      script.src = PDF_JS;
      script.addEventListener('load', () => {
        window.pdfjsLib.GlobalWorkerOptions.workerSrc = PDF_WORKER;
        resoudre(window.pdfjsLib);
      });
      script.addEventListener('error', () => rejeter(new Error('pdf.js introuvable')));
      document.head.appendChild(script);
    });
  }
  return pdfJsPromesse;
}

class Visionneuse {
  constructor(racine) {
    this.racine = racine;
    this.zone = racine.querySelector('[data-testid="visionneuse-pages"]');
    this.indicateurPage = racine.querySelector('[data-testid="visionneuse-page"]');
    this.indicateurZoom = racine.querySelector('[data-testid="visionneuse-zoom"]');
    this.src = racine.dataset.src || '';
    this.categorie = racine.dataset.categorie || 'inconnu';
    this.pages = parseInt(racine.dataset.pages, 10) || 1;
    this.titre = racine.dataset.titre || '';
    this.message = racine.dataset.message || '';
    this.echelle = 1;
    this.rotation = 0;
    this.pageCourante = 1;
    this.documentPdf = null;
    this.image = null;
    this.chaineRendu = Promise.resolve();
  }

  demarrer() {
    this.ecouterOutils();
    this.ecouterClavier();
    this.ecouterTactile();
    if (this.categorie === 'message') {
      // Le message .eml est déjà rendu en HTML nettoyé côté serveur : rien à charger.
      this.zone.querySelector('[data-testid="visionneuse-chargement"]')?.remove();
      return;
    }
    if (this.message) {
      this.zone.querySelector('[data-testid="visionneuse-chargement"]')?.remove();
      return;
    }
    if (!this.src) {
      this.afficherErreur('aperçu indisponible : aucun contenu');
      return;
    }
    if (this.categorie === 'pdf') {
      this.chargerPdf().catch(() => this.afficherErreur('aperçu indisponible : lecture du PDF impossible'));
    } else if (this.categorie === 'image') {
      this.chargerImage();
    } else {
      this.afficherErreur('aperçu indisponible : format non pris en charge');
    }
  }

  ecouterOutils() {
    this.racine.querySelector('[data-action="zoom-avant"]')?.addEventListener('click', () => this.zoomer(1.2));
    this.racine.querySelector('[data-action="zoom-arriere"]')?.addEventListener('click', () => this.zoomer(1 / 1.2));
    this.racine.querySelector('[data-action="rotation"]')?.addEventListener('click', () => this.tourner());
  }

  /** Téléphone : pincer avec deux doigts zoome l'aperçu (le défilement à un doigt reste possible). */
  ecouterTactile() {
    let distance = 0;
    this.zone.addEventListener('touchstart', (evenement) => {
      distance = evenement.touches.length === 2 ? this.distanceTouches(evenement.touches) : 0;
    }, { passive: true });
    this.zone.addEventListener('touchmove', (evenement) => {
      if (evenement.touches.length !== 2) return;
      evenement.preventDefault();
      const nouvelle = this.distanceTouches(evenement.touches);
      if (distance > 0 && nouvelle > 0) this.zoomer(nouvelle / distance);
      distance = nouvelle;
    }, { passive: false });
    this.zone.addEventListener('touchend', () => { distance = 0; }, { passive: true });
  }

  distanceTouches(touches) {
    const dx = touches[0].clientX - touches[1].clientX;
    const dy = touches[0].clientY - touches[1].clientY;
    return Math.hypot(dx, dy);
  }

  ecouterClavier() {
    this.zone.addEventListener('keydown', (evenement) => {
      const suivant = ['PageDown', 'ArrowDown', 'ArrowRight', ' '];
      const precedent = ['PageUp', 'ArrowUp', 'ArrowLeft'];
      if (suivant.includes(evenement.key)) {
        evenement.preventDefault();
        this.allerPage(this.pageCourante + 1);
      } else if (precedent.includes(evenement.key)) {
        evenement.preventDefault();
        this.allerPage(this.pageCourante - 1);
      } else if (evenement.key === '+' || evenement.key === '=') {
        evenement.preventDefault();
        this.zoomer(1.2);
      } else if (evenement.key === '-') {
        evenement.preventDefault();
        this.zoomer(1 / 1.2);
      } else if (evenement.key.toLowerCase() === 'r') {
        evenement.preventDefault();
        this.tourner();
      }
    });
    // Le défilement à la souris tient l'indicateur de page à jour.
    this.zone.addEventListener('scroll', () => {
      const feuilles = [...this.zone.querySelectorAll('[data-page]')];
      let courante = 1;
      for (const feuille of feuilles) {
        if (feuille.offsetTop - this.zone.scrollTop <= this.zone.clientHeight / 2) {
          courante = parseInt(feuille.dataset.page, 10);
        }
      }
      if (courante !== this.pageCourante) {
        this.pageCourante = courante;
        this.majIndicateurPage();
      }
    }, { passive: true });
  }

  async chargerPdf() {
    const pdfjs = await chargerPdfJs();
    this.documentPdf = await pdfjs.getDocument({ url: this.src }).promise;
    this.pages = this.documentPdf.numPages;
    this.majIndicateurPage();
    this.chaineRendu = this.chaineRendu.then(() => this.dessinerPdf());
  }

  async dessinerPdf() {
    this.zone.replaceChildren();
    for (let numero = 1; numero <= this.pages; numero++) {
      const feuille = document.createElement('section');
      feuille.className = 'visionneuse-feuille';
      feuille.dataset.page = String(numero);
      const canvas = document.createElement('canvas');
      canvas.setAttribute('data-testid', `visionneuse-canvas-${numero}`);
      canvas.setAttribute('aria-label', `Page ${numero} sur ${this.pages}`);
      feuille.appendChild(canvas);
      this.zone.appendChild(feuille);
      await this.dessinerPage(numero, canvas);
      canvas.dataset.rendu = '1';
      if (numero === 1) this.majIndicateurPage();
    }
  }

  async dessinerPage(numero, canvas) {
    const page = await this.documentPdf.getPage(numero);
    const base = page.getViewport({ scale: 1, rotation: this.rotation });
    // Confort de lecture : la page occupe une largeur raisonnable, le zoom permet d'aller au-delà.
    const disponible = Math.max(320, Math.min(860, this.zone.clientWidth - 30));
    const echelle = (disponible / base.width) * this.echelle;
    const viewport = page.getViewport({ scale: echelle, rotation: this.rotation });
    const densite = Math.min(2, window.devicePixelRatio || 1);
    canvas.width = Math.floor(viewport.width * densite);
    canvas.height = Math.floor(viewport.height * densite);
    canvas.style.width = `${Math.floor(viewport.width)}px`;
    canvas.style.height = `${Math.floor(viewport.height)}px`;
    const contexte = canvas.getContext('2d');
    contexte.setTransform(densite, 0, 0, densite, 0, 0);
    await page.render({ canvasContext: contexte, viewport }).promise;
  }

  chargerImage() {
    const image = document.createElement('img');
    image.className = 'visionneuse-image';
    image.setAttribute('data-testid', 'visionneuse-image');
    image.alt = this.titre || 'Aperçu du document';
    image.src = this.src;
    image.addEventListener('error', () => this.afficherErreur('aperçu indisponible : image illisible'));
    this.image = image;
    this.zone.replaceChildren(image);
    this.majIndicateurPage();
  }

  allerPage(numero) {
    const cible = Math.min(this.pages, Math.max(1, numero));
    this.pageCourante = cible;
    this.majIndicateurPage();
    const feuille = this.zone.querySelector(`[data-page="${cible}"]`);
    if (feuille) this.zone.scrollTop = feuille.offsetTop - this.zone.clientTop;
    else if (this.image) this.image.scrollIntoView({ block: 'start' });
  }

  zoomer(facteur) {
    const avant = this.echelle;
    this.echelle = Math.min(3, Math.max(0.5, Math.round(this.echelle * facteur * 100) / 100));
    if (this.echelle === avant) return;
    if (this.indicateurZoom) this.indicateurZoom.textContent = `${Math.round(this.echelle * 100)} %`;
    if (this.documentPdf) this.chaineRendu = this.chaineRendu.then(() => this.dessinerPdf());
    else this.appliquerImage();
  }

  tourner() {
    this.rotation = (this.rotation + 90) % 360;
    this.racine.dataset.rotation = String(this.rotation);
    if (this.documentPdf) this.chaineRendu = this.chaineRendu.then(() => this.dessinerPdf());
    else this.appliquerImage();
  }

  appliquerImage() {
    if (this.image) this.image.style.transform = `rotate(${this.rotation}deg) scale(${this.echelle})`;
  }

  majIndicateurPage() {
    if (this.indicateurPage) this.indicateurPage.textContent = `page ${this.pageCourante} / ${this.pages}`;
  }

  afficherErreur(message) {
    const paragraphe = document.createElement('p');
    paragraphe.className = 'visionneuse-erreur';
    paragraphe.setAttribute('data-testid', 'visionneuse-erreur');
    paragraphe.textContent = message;
    this.zone.replaceChildren(paragraphe);
  }
}

document.querySelectorAll('[data-visionneuse]').forEach((racine) => new Visionneuse(racine).demarrer());
