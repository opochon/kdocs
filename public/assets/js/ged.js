// GEDv2 — comportements communs. JS natif, sans dépendance. Chaque comportement est déclaré par un attribut.

// Marqueur « comportements prêts » : les boutons d'action (Valider, Corriger…) exigent ce module.
document.documentElement.dataset.gedJs = '1';

// Thème clair / sombre, mémorisé par poste (confort, jamais une donnée métier).
document.querySelector('[data-action="theme"]')?.addEventListener('click', () => {
  const racine = document.documentElement;
  const sombre = racine.dataset.theme ? racine.dataset.theme !== 'dark'
    : !matchMedia('(prefers-color-scheme: dark)').matches;
  racine.dataset.theme = sombre ? 'dark' : 'light';
  try { localStorage.setItem('ged-theme', racine.dataset.theme); } catch (e) { /* stockage indisponible */ }
});

// Ctrl+K : la recherche, depuis n'importe quel écran.
document.addEventListener('keydown', (e) => {
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
    e.preventDefault();
    document.querySelector('[data-testid="recherche-globale-champ"]')?.focus();
  }
});

// V2-10c : sur téléphone (≤ 480 px), l'invite longue est tronquée. On pose une invite COURTE, entièrement
// visible ; sur ordinateur, la phrase complète revient. Le texte long reste dans `placeholder`, la version
// courte dans `data-invite-courte`.
const champRechercheGlobale = document.querySelector('[data-testid="recherche-globale-champ"]');
if (champRechercheGlobale) {
  const inviteLongue = champRechercheGlobale.getAttribute('placeholder') ?? '';
  const inviteCourte = champRechercheGlobale.dataset.inviteCourte || inviteLongue;
  const appliquerInvite = () => {
    champRechercheGlobale.placeholder = matchMedia('(max-width: 480px)').matches ? inviteCourte : inviteLongue;
  };
  appliquerInvite();
  matchMedia('(max-width: 480px)').addEventListener('change', appliquerInvite);
}

// Onglets accessibles (fiche document).
document.querySelectorAll('[data-onglets]').forEach((bloc) => {
  const onglets = [...bloc.querySelectorAll('[role="tab"]')];
  onglets.forEach((o) => o.addEventListener('click', () => {
    onglets.forEach((x) => {
      const actif = x === o;
      x.setAttribute('aria-selected', String(actif));
      bloc.querySelector('#' + x.getAttribute('aria-controls')).hidden = !actif;
    });
  }));
});

// À traiter : contrôle au clavier. Entrée = valider la pièce courante (POST), → = passer au suivant.
const fileATraiter = document.querySelector('[data-testid="file-a-traiter"]');
const formulaireValidation = document.querySelector('[data-testid="formulaire-validation"]');
if (fileATraiter || formulaireValidation) {
  const suivant = () => {
    const liens = [...document.querySelectorAll('[data-testid="file-a-traiter"] a')];
    const i = liens.findIndex((a) => a.getAttribute('aria-current') === 'true');
    const cible = liens[i + 1] ?? liens[0];
    if (cible) window.location.href = cible.href;
  };
  const valider = () => {
    if (!formulaireValidation || formulaireValidation.dataset.doc === '0') return;
    // Sans proposition, le type part vide : le refus se NOMME et se voit (jamais un faux type rangé à Entrée).
    const type = formulaireValidation.querySelector('[name="type"]');
    if (type && String(type.value).trim() === '') {
      type.focus();
      if (typeof type.reportValidity === 'function') type.reportValidity();
      return;
    }
    if (typeof formulaireValidation.requestSubmit === 'function') formulaireValidation.requestSubmit();
    else formulaireValidation.submit();
  };
  document.querySelector('[data-action="suivant"]')?.addEventListener('click', suivant);
  document.querySelector('[data-action="valider"]')?.addEventListener('click', valider);

  // « Corriger » (mobile) : amène le pouce au champ Type, sans soumettre.
  document.querySelector('[data-action="corriger"]')?.addEventListener('click', () => {
    const type = document.querySelector('[data-testid="champ-type"]');
    type?.scrollIntoView({ block: 'center', behavior: 'smooth' });
    type?.focus();
  });

  // Téléphone : la file est un compteur repliable (le gabarit reste ouvert sans JS).
  const blocFile = document.querySelector('[data-testid="bloc-file"]');
  if (blocFile instanceof HTMLDetailsElement && matchMedia('(max-width: 768px)').matches) {
    blocFile.open = false;
  }

  document.addEventListener('keydown', (e) => {
    const saisie = e.target instanceof HTMLElement && e.target.matches('input, select, textarea');
    if (saisie) return;
    if (e.key === 'ArrowRight') { e.preventDefault(); suivant(); }
    if (e.key === 'Enter') { e.preventDefault(); valider(); }
  });

  // Balayage horizontal sur la pièce (hors aperçu, qui a son pincement et son défilement) = pièce suivante.
  const ecran = document.querySelector('[data-testid="ecran-a-traiter"]');
  if (ecran) {
    let depart = null;
    ecran.addEventListener('touchstart', (e) => {
      if (e.target instanceof Element && e.target.closest('[data-visionneuse], .visionneuse, input, select, textarea, details')) {
        depart = null;
        return;
      }
      const t = e.touches[0];
      depart = t ? { x: t.clientX, y: t.clientY } : null;
    }, { passive: true });
    ecran.addEventListener('touchend', (e) => {
      if (!depart) return;
      const t = e.changedTouches[0];
      if (t) {
        const dx = t.clientX - depart.x;
        const dy = t.clientY - depart.y;
        if (Math.abs(dx) > 70 && Math.abs(dx) > Math.abs(dy) * 1.5) suivant();
      }
      depart = null;
    }, { passive: true });
  }
}

// L'arbre du fonds est utile au pouce sans prendre toute la hauteur : replié seulement sur téléphone.
const blocDossiers = document.querySelector('[data-testid="bloc-dossiers"]');
if (blocDossiers instanceof HTMLDetailsElement && matchMedia('(max-width: 768px)').matches) blocDossiers.open = false;

// Recherche (V2-10b) : sur téléphone, les filtres sont repliés derrière le bouton « Filtres » ; ouverts, ils
// tiennent un champ par ligne. Le gabarit est ouvert par défaut (ordinateur complet même sans JS).
document.querySelectorAll('[data-testid="filtres-panneau"]').forEach((panneau) => {
  if (panneau instanceof HTMLDetailsElement && matchMedia('(max-width: 768px)').matches) {
    panneau.open = false;
  }
});

// Déposer (V2-10b) : deux gestes français pilotent le SEUL champ de fichier (capture pour la photo), et la
// liste des fichiers choisis montre leurs vignettes. Le contrôle natif anglais n'est jamais visible.
const champFichier = document.querySelector('[data-testid="champ-photo"]');
if (champFichier instanceof HTMLInputElement) {
  const choisir = (photo) => {
    if (photo) champFichier.setAttribute('capture', 'environment');
    else champFichier.removeAttribute('capture');
    champFichier.click();
  };
  document.querySelector('[data-action="photo"]')?.addEventListener('click', () => choisir(true));
  document.querySelector('[data-action="fichiers"]')?.addEventListener('click', () => choisir(false));

  const listeFichiers = document.querySelector('[data-testid="liste-fichiers"]');
  if (listeFichiers) {
    champFichier.addEventListener('change', () => {
      listeFichiers.replaceChildren();
      for (const fichier of champFichier.files ?? []) {
        const item = document.createElement('li');
        item.className = 'depot-fichier';
        item.setAttribute('data-testid', `fichier-${fichier.name}`);
        const vignette = document.createElement('span');
        vignette.className = 'depot-vignette';
        if (fichier.type.startsWith('image/')) {
          // La CSP de la GED n'autorise que 'self' : ni blob: ni data: pour un <img>. On décode le fichier
          // en mémoire et on le dessine dans un canvas — la vignette ne charge donc aucune URL.
          const canvas = document.createElement('canvas');
          canvas.width = 44;
          canvas.height = 44;
          vignette.appendChild(canvas);
          if (typeof createImageBitmap === 'function') {
            createImageBitmap(fichier).then((bitmap) => {
              const contexte = canvas.getContext('2d');
              const rapport = Math.max(canvas.width / bitmap.width, canvas.height / bitmap.height);
              const largeur = bitmap.width * rapport;
              const hauteur = bitmap.height * rapport;
              contexte.drawImage(bitmap, (canvas.width - largeur) / 2, (canvas.height - hauteur) / 2, largeur, hauteur);
              bitmap.close();
            }).catch(() => { vignette.textContent = 'IMG'; });
          } else {
            vignette.textContent = 'IMG';
          }
        } else {
          vignette.textContent = 'PDF';
        }
        const nom = document.createElement('span');
        nom.className = 'depot-nom';
        nom.textContent = fichier.name;
        const taille = document.createElement('span');
        taille.className = 'depot-taille';
        taille.textContent = fichier.size < 1024 ? `${fichier.size} o` : `${Math.round(fichier.size / 1024)} Ko`;
        item.append(vignette, nom, taille);
        listeFichiers.appendChild(item);
      }
    });
  }
}

// Modification en masse (Bibliothèque, lot ged-parite-masse) : « toute la page » coche les lignes, et
// la confirmation ANNONCE le nombre de documents visés AVANT d'appliquer. Sans JS, le formulaire reste
// utilisable (le serveur compte, exécute par paquets courts et nomme les refus).
const formulaireMasse = document.querySelector('[data-testid="formulaire-masse"]');
if (formulaireMasse) {
  const toutPage = [...document.querySelectorAll('[data-testid="coche-tout-page"], [data-testid="coche-tout-page-entete"]')];
  const toutResultat = document.querySelector('[data-testid="coche-tout-resultat"]');
  const confirmation = formulaireMasse.querySelector('[data-testid="masse-confirmation"]');
  const action = formulaireMasse.querySelector('[data-testid="masse-action"]');
  const cases = () => [...document.querySelectorAll('[data-testid^="coche-doc-"]')];
  const totalFiltre = Number(toutResultat?.dataset.total ?? confirmation?.dataset.total ?? '0');

  const majConfirmation = () => {
    if (!confirmation) return;
    const n = toutResultat?.checked ? totalFiltre : cases().filter((c) => c.checked).length;
    const libelle = action?.selectedOptions?.[0]?.textContent?.trim() || 'cette action';
    confirmation.textContent = n > 0
      ? `Confirmer : appliquer « ${libelle} » à ${n} document${n > 1 ? 's' : ''}.`
      : 'Sélectionnez des documents, puis une action : le nombre sera annoncé avant d’appliquer.';
    confirmation.dataset.selection = String(n);
  };

  const basculerPage = (coche) => {
    if (!coche) return;
    for (const c of cases()) c.checked = coche.checked;
    for (const autre of toutPage) autre.checked = coche.checked;
    majConfirmation();
  };
  for (const coche of toutPage) coche.addEventListener('change', () => basculerPage(coche));
  toutResultat?.addEventListener('change', () => {
    if (toutResultat.checked) basculerPage({ checked: false });
    majConfirmation();
  });
  document.addEventListener('change', (e) => {
    if (e.target instanceof Element && e.target.matches('[data-testid^="coche-doc-"]')) {
      if (!e.target.checked) for (const coche of toutPage) coche.checked = false;
      majConfirmation();
    }
  });
  action?.addEventListener('change', majConfirmation);
  majConfirmation();
}

