// GEDv2 — designer de workflows, repris de GEDv1 (canevas SVG, nœuds glissés-déposés, liaisons, panneau
// de réglages) et adapté au système visuel GEDv2. JS natif, sans framework : la CSP n'autorise que les
// scripts externes et le nonce ; aucun gestionnaire en ligne, aucun style injecté.
//
// Le modèle reste celui du moteur : circuits = états + transitions, règles = déclencheur + conditions +
// actions. Ce fichier ne manipule que le DESSIN ; la projection vers le modèle est faite côté serveur
// (Ged\Workflows\AdaptateurGraphe), à l'enregistrement.

const racine = document.querySelector('[data-testid="ecran-workflows-editeur"]');
if (racine) {
  initialiser(racine);
}

function initialiser(racine) {
  const donnees = lireDonnees();
  if (!donnees) return;

  const svg = document.getElementById('workflows-canevas');
  const coucheLiens = document.getElementById('wf-liens');
  const coucheNoeuds = document.getElementById('wf-noeuds');
  const panneau = document.querySelector('[data-testid="panneau-reglages"]');
  const statut = document.querySelector('[data-testid="statut-enregistrement"]');
  const dialogue = document.getElementById('wf-dialogue');

  const catalogue = donnees.catalogue || {};
  const champsPosables = donnees.champs_posables || [];
  const meta = donnees.meta || {};
  const graphe = donnees.graphe || { nom: '', gabarit: null, actif: true, noeuds: [], liens: [] };
  const essai = donnees.essai || null;

  /** type de nœud → entrée du catalogue */
  const entrees = {};
  Object.values(catalogue).forEach((famille) => (famille || []).forEach((e) => (entrees[e.type] = e)));

  let selection = null;
  let suite = prochainNumero();
  let glissement = null;
  let liaison = null;

  rendre();

  // ------------------------------------------------------------------ rendu

  function rendre() {
    coucheLiens.replaceChildren();
    coucheNoeuds.replaceChildren();
    graphe.liens.forEach(rendreLien);
    graphe.noeuds.forEach(rendreNoeud);
    surlignerEssai();
  }

  function hauteur(entree) {
    const sorties = (entree && entree.sorties ? entree.sorties : ['default']).length;
    return sorties > 1 ? 52 + (sorties - 1) * 20 : 56;
  }

  function rendreNoeud(noeud) {
    const entree = entrees[noeud.type] || { couleur: 'neutre', sorties: ['default'] };
    const h = hauteur(entree);
    const groupe = creer('g', {
      class: 'wf-noeud' + (selection === noeud.id ? ' choisi' : ''),
      'data-testid': 'noeud-' + noeud.id, 'data-noeud': noeud.id, 'data-genre': noeud.genre,
      'data-type': noeud.type, tabindex: '0',
      transform: `translate(${noeud.x}, ${noeud.y})`,
    });
    if (noeud.genre === 'condition') {
      groupe.setAttribute('data-champ', String(noeud.config?.champ ?? ''));
      groupe.setAttribute('data-operateur', String(noeud.config?.operateur ?? ''));
      groupe.setAttribute('data-valeur', String(noeud.config?.valeur ?? ''));
    }
    if (noeud.genre === 'etat') groupe.setAttribute('data-etat', String(noeud.config?.nom ?? ''));
    if (noeud.genre === 'action') groupe.setAttribute('data-action-type', String(noeud.config?.type ?? ''));

    groupe.appendChild(creer('rect', { class: `wf-boite wf-c-${entree.couleur || 'neutre'}`, x: 0, y: 0, width: 160, height: h, rx: 8 }));
    groupe.appendChild(texte(12, 22, couper(noeud.nom || entree.libelle || noeud.type, 20), 'wf-nom'));
    groupe.appendChild(texte(12, 40, libelleEntree(noeud), 'wf-type'));

    if (noeud.genre !== 'declencheur') {
      groupe.appendChild(poignee(0, h / 2, 'entree', noeud.id, 'default'));
    }
    (entree.sorties || ['default']).forEach((sortie, i) => {
      const y = (entree.sorties || []).length > 1 ? 26 + i * 20 : h / 2;
      groupe.appendChild(poignee(160, y, 'sortie', noeud.id, sortie));
    });

    groupe.addEventListener('mousedown', (e) => demarrerGlissement(e, noeud));
    groupe.addEventListener('click', (e) => {
      e.stopPropagation();
      selectionner(noeud.id);
    });
    groupe.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        selectionner(noeud.id);
      }
    });

    coucheNoeuds.appendChild(groupe);
  }

  function rendreLien(lien) {
    const source = graphe.noeuds.find((n) => n.id === lien.de);
    const cible = graphe.noeuds.find((n) => n.id === lien.vers);
    if (!source || !cible) return;
    const h = hauteur(entrees[source.type]);
    const x1 = source.x + 160;
    const y1 = source.y + h / 2;
    const x2 = cible.x;
    const y2 = cible.y + hauteur(entrees[cible.type]) / 2;
    const milieu = (x1 + x2) / 2;
    const chemin = creer('path', {
      class: 'wf-lien', 'data-testid': 'trait-' + lien.id, 'data-lien': lien.id,
      'data-de': lien.de, 'data-vers': lien.vers, 'marker-end': 'url(#wf-fleche)',
      d: `M ${x1} ${y1} C ${milieu} ${y1}, ${milieu} ${y2}, ${x2} ${y2}`,
    });
    chemin.addEventListener('dblclick', async () => {
      if (await demander({ titre: 'Supprimer la liaison', message: 'Retirer cette liaison du circuit ?' })) {
        graphe.liens = graphe.liens.filter((l) => l.id !== lien.id);
        rendre();
      }
    });
    coucheLiens.appendChild(chemin);
  }

  // ------------------------------------------------------------------ interactions

  function selectionner(id) {
    selection = id;
    rendre();
    const noeud = graphe.noeuds.find((n) => n.id === id);
    if (noeud) regler(noeud);
  }

  function demarrerGlissement(e, noeud) {
    if (e.target.classList.contains('wf-poignee')) {
      e.stopPropagation();
      e.preventDefault();
      if (e.target.classList.contains('wf-sortie')) demarrerLiaison(noeud, e);
      return;
    }
    const cadre = svg.getBoundingClientRect();
    glissement = { noeud, dx: e.clientX - cadre.left - noeud.x, dy: e.clientY - cadre.top - noeud.y };
    selectionner(noeud.id);
    document.addEventListener('mousemove', glisserNoeud);
    document.addEventListener('mouseup', arreterGlissement);
  }

  function glisserNoeud(e) {
    if (!glissement) return;
    const cadre = svg.getBoundingClientRect();
    glissement.noeud.x = Math.max(0, Math.round(e.clientX - cadre.left - glissement.dx));
    glissement.noeud.y = Math.max(0, Math.round(e.clientY - cadre.top - glissement.dy));
    rendre();
  }

  function arreterGlissement() {
    glissement = null;
    document.removeEventListener('mousemove', glisserNoeud);
    document.removeEventListener('mouseup', arreterGlissement);
  }

  function demarrerLiaison(noeudSource, e) {
    const cadre = svg.getBoundingClientRect();
    liaison = { source: noeudSource.id, sortie: e.target.getAttribute('data-sortie') || 'default',
      trait: creer('line', { class: 'wf-temp', x1: noeudSource.x + 160, y1: noeudSource.y + hauteur(entrees[noeudSource.type]) / 2,
        x2: e.clientX - cadre.left, y2: e.clientY - cadre.top }) };
    coucheLiens.appendChild(liaison.trait);
    document.addEventListener('mousemove', suivreLiaison);
    document.addEventListener('mouseup', terminerLiaison);
  }

  function suivreLiaison(e) {
    if (!liaison) return;
    const cadre = svg.getBoundingClientRect();
    liaison.trait.setAttribute('x2', e.clientX - cadre.left);
    liaison.trait.setAttribute('y2', e.clientY - cadre.top);
  }

  function terminerLiaison(e) {
    document.removeEventListener('mousemove', suivreLiaison);
    document.removeEventListener('mouseup', terminerLiaison);
    const cible = document.elementFromPoint(e.clientX, e.clientY);
    if (liaison && cible && cible.classList.contains('wf-entree')) {
      relier(liaison.source, cible.getAttribute('data-noeud'), liaison.sortie);
    }
    if (liaison) liaison.trait.remove();
    liaison = null;
    rendre();
  }

  function relier(de, vers, sortie) {
    if (!de || !vers || de === vers) return;
    const existe = graphe.liens.some((l) => l.de === de && l.vers === vers && l.sortie === (sortie || 'default'));
    if (existe) return;
    const lien = { id: 'l' + Date.now().toString(36) + Math.round(Math.random() * 1000), de, vers, sortie: sortie || 'default' };
    const genreDe = graphe.noeuds.find((n) => n.id === de)?.genre;
    const genreVers = graphe.noeuds.find((n) => n.id === vers)?.genre;
    if (genreDe === 'etat' && genreVers === 'etat') {
      const cible = graphe.noeuds.find((n) => n.id === vers);
      lien.libelle = cible?.config?.libelle || 'Transition';
      lien.permission = 'workflow.transition.' + (cible?.config?.nom || 'etape');
    }
    graphe.liens.push(lien);
  }

  // ------------------------------------------------------------------ ajout de nœuds

  function ajouter(type, x, y) {
    const entree = entrees[type];
    if (!entree) return;
    const id = 'n' + suite++;
    const rang = graphe.noeuds.length;
    const noeud = { id, genre: entree.genre, type, nom: entree.libelle,
      x: Number.isFinite(x) && x >= 0 ? Math.round(x) : 40 + (rang % 4) * 210,
      y: Number.isFinite(y) && y >= 0 ? Math.round(y) : 40 + Math.floor(rang / 4) * 130,
      config: configParDefaut(entree) };
    if (noeud.genre === 'etat') noeud.nom = noeud.config.libelle;
    graphe.noeuds.push(noeud);
    rendre();
    selectionner(id);
  }

  function configParDefaut(entree) {
    const config = JSON.parse(JSON.stringify(entree.config?.defaut || {}));
    if (entree.genre === 'etat') {
      config.nom = 'etat_' + suite;
      config.libelle = 'Nouvel état';
    }
    if (entree.genre === 'action') {
      const action = entree.config?.action;
      config.type = action;
      if (action === 'demander_validation' || action === 'assigner') {
        config.circuit = meta.gabarit || meta.nom;
        config.vers = '';
        config.assigne = '';
      }
      if (action === 'poser') { config.champ = 'type'; config.valeur = ''; }
      if (action === 'etiqueter') config.etiquette = '';
      if (action === 'notifier') { config.destinataire = 'responsable'; config.message = ''; }
      if (action === 'appeler_jonction') config.cible = '';
    }
    return config;
  }

  // ------------------------------------------------------------------ panneau de réglages

  function regler(noeud) {
    const entree = entrees[noeud.type] || { config: { champs: [] } };
    panneau.replaceChildren();

    const blocNom = champ('reglage-noeud-nom', 'Nom du composant', 'text', noeud.nom);
    panneau.appendChild(blocNom);

    (entree.config?.champs || []).forEach((descripteur) => {
      panneau.appendChild(construireChamp(descripteur, noeud.config));
    });

    if (noeud.genre === 'etat') panneau.appendChild(blocTransitions(noeud));

    const actions = document.createElement('div');
    actions.className = 'actions';
    const enregistrer = bouton('action-reglage-enregistrer', 'Enregistrer le nœud', 'btn primaire');
    enregistrer.addEventListener('click', () => appliquerReglages(noeud));
    const supprimer = bouton('action-noeud-supprimer', 'Supprimer le nœud', 'btn');
    supprimer.addEventListener('click', () => supprimerNoeud(noeud));
    actions.append(enregistrer, supprimer);
    panneau.appendChild(actions);
  }

  function construireChamp(descripteur, config) {
    const valeur = config?.[descripteur.nom] ?? '';
    if (descripteur.type === 'select') {
      const options = optionsChamp(descripteur);
      const bloc = document.createElement('div');
      bloc.className = 'champ';
      const label = document.createElement('label');
      label.textContent = descripteur.libelle;
      label.setAttribute('for', 'reglage-' + descripteur.nom);
      const select = document.createElement('select');
      select.id = 'reglage-' + descripteur.nom;
      select.setAttribute('data-testid', 'reglage-' + descripteur.nom);
      const vide = document.createElement('option');
      vide.value = '';
      vide.textContent = '-- choisir --';
      select.appendChild(vide);
      Object.entries(options).forEach(([v, libelle]) => {
        const o = document.createElement('option');
        o.value = v;
        o.textContent = libelle;
        if (String(v) === String(valeur)) o.selected = true;
        select.appendChild(o);
      });
      bloc.append(label, select);
      return bloc;
    }
    if (descripteur.type === 'checkbox') {
      const bloc = document.createElement('div');
      bloc.className = 'champ';
      const label = document.createElement('label');
      const input = document.createElement('input');
      input.type = 'checkbox';
      input.id = 'reglage-' + descripteur.nom;
      input.setAttribute('data-testid', 'reglage-' + descripteur.nom);
      input.checked = Boolean(valeur);
      label.append(input, document.createTextNode(' ' + descripteur.libelle));
      bloc.append(label);
      return bloc;
    }
    if (descripteur.type === 'textarea') {
      const bloc = document.createElement('div');
      bloc.className = 'champ';
      const label = document.createElement('label');
      label.textContent = descripteur.libelle;
      label.setAttribute('for', 'reglage-' + descripteur.nom);
      const zone = document.createElement('textarea');
      zone.id = 'reglage-' + descripteur.nom;
      zone.rows = 3;
      zone.value = valeur ?? '';
      zone.setAttribute('data-testid', 'reglage-' + descripteur.nom);
      bloc.append(label, zone);
      return bloc;
    }
    const type = descripteur.type === 'number' ? 'number' : 'text';
    return champ('reglage-' + descripteur.nom, descripteur.libelle, type, valeur);
  }

  function optionsChamp(descripteur) {
    if (descripteur.options) return descripteur.options;
    if (descripteur.source === 'champs_posables') {
      const map = {};
      champsPosables.forEach((c) => (map[c] = c));
      return map;
    }
    if (descripteur.source === 'etats') {
      const map = {};
      graphe.noeuds.filter((n) => n.genre === 'etat').forEach((n) => (map[n.config?.nom || ''] = n.config?.libelle || n.config?.nom || ''));
      return map;
    }
    return {};
  }

  function blocTransitions(noeud) {
    const bloc = document.createElement('div');
    bloc.className = 'champ';
    const titre = document.createElement('label');
    titre.textContent = 'Transitions sortantes de « ' + (noeud.config?.libelle || noeud.config?.nom) + ' »';
    bloc.appendChild(titre);

    const liste = document.createElement('ul');
    liste.className = 'liste';
    liste.setAttribute('data-testid', 'reglage-transitions');
    graphe.liens
      .filter((l) => l.de === noeud.id)
      .forEach((l) => {
        const cible = graphe.noeuds.find((n) => n.id === l.vers);
        const li = document.createElement('li');
        li.className = 'l2';
        li.setAttribute('data-transition', (noeud.config?.nom || '') + '→' + (cible?.config?.nom || ''));
        li.textContent = 'vers ' + (cible?.config?.libelle || cible?.config?.nom || l.vers) + ' — droit ' + (l.permission || '—');
        liste.appendChild(li);
      });
    bloc.appendChild(liste);

    const select = document.createElement('select');
    select.id = 'reglage-transition-vers';
    select.setAttribute('data-testid', 'reglage-transition-vers');
    const vide = document.createElement('option');
    vide.value = '';
    vide.textContent = '-- état cible --';
    select.appendChild(vide);
    graphe.noeuds
      .filter((n) => n.genre === 'etat' && n.id !== noeud.id)
      .forEach((n) => {
        const o = document.createElement('option');
        o.value = n.id;
        o.textContent = n.config?.libelle || n.config?.nom;
        select.appendChild(o);
      });
    bloc.appendChild(select);
    bloc.appendChild(champ('reglage-transition-libelle', 'Libellé de la transition', 'text', ''));
    bloc.appendChild(champ('reglage-transition-permission', 'Droit requis', 'text', ''));

    const boutonRelier = bouton('action-transition-relier', 'Relier la transition', 'btn');
    boutonRelier.addEventListener('click', () => {
      const vers = select.value;
      if (!vers) {
        signaler('Choisissez un état cible pour la transition.', false);
        return;
      }
      relier(noeud.id, vers, 'default');
      const dernier = graphe.liens[graphe.liens.length - 1];
      const libelle = document.getElementById('reglage-transition-libelle')?.value?.trim();
      const permission = document.getElementById('reglage-transition-permission')?.value?.trim();
      if (dernier && dernier.de === noeud.id && dernier.vers === vers) {
        if (libelle) dernier.libelle = libelle;
        if (permission) dernier.permission = permission;
      }
      rendre();
      regler(noeud);
      signaler('Transition reliée. Enregistrez le circuit pour la garder.', true);
    });
    bloc.appendChild(boutonRelier);
    return bloc;
  }

  function appliquerReglages(noeud) {
    const nom = document.getElementById('reglage-noeud-nom');
    if (nom) noeud.nom = nom.value.trim() || noeud.nom;
    const champs = (entrees[noeud.type]?.config?.champs) || [];
    champs.forEach((d) => {
      const input = document.getElementById('reglage-' + d.nom);
      if (!input) return;
      if (d.type === 'checkbox') noeud.config[d.nom] = input.checked;
      else if (d.type === 'number') noeud.config[d.nom] = input.value === '' ? '' : Number(input.value);
      else noeud.config[d.nom] = input.value;
    });
    if (noeud.genre === 'etat') {
      noeud.nom = noeud.config.libelle || noeud.config.nom;
    }
    rendre();
    regler(noeud);
    signaler('Réglages appliqués.', true);
  }

  async function supprimerNoeud(noeud) {
    if (!(await demander({ titre: 'Supprimer le composant', message: `Retirer « ${noeud.nom} » du dessin ?` }))) return;
    graphe.noeuds = graphe.noeuds.filter((n) => n.id !== noeud.id);
    graphe.liens = graphe.liens.filter((l) => l.de !== noeud.id && l.vers !== noeud.id);
    selection = null;
    panneau.replaceChildren(texteBloc('Sélectionnez un nœud pour le régler.'));
    rendre();
  }

  // ------------------------------------------------------------------ outil, canevas, barre

  document.querySelectorAll('[data-testid="workflows-outils"] .wf-outil').forEach((outil) => {
    outil.addEventListener('click', () => ajouter(outil.dataset.type, undefined, undefined));
    outil.addEventListener('dragstart', (e) => {
      e.dataTransfer.setData('text/plain', outil.dataset.type);
      e.dataTransfer.effectAllowed = 'copy';
    });
  });

  svg.addEventListener('dragover', (e) => {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'copy';
  });
  svg.addEventListener('drop', (e) => {
    e.preventDefault();
    const type = e.dataTransfer.getData('text/plain');
    const cadre = svg.getBoundingClientRect();
    if (type) ajouter(type, e.clientX - cadre.left - 80, e.clientY - cadre.top - 28);
  });
  svg.addEventListener('click', (e) => {
    if (e.target === svg || e.target.id === 'wf-liens' || e.target.id === 'wf-noeuds') {
      selection = null;
      panneau.replaceChildren(texteBloc('Sélectionnez un nœud pour le régler.'));
      rendre();
    }
  });

  document.querySelector('[data-testid="action-ajouter-etat"]')?.addEventListener('click', () => ajouter('etat'));
  document.querySelector('[data-testid="action-enregistrer"]')?.addEventListener('click', enregistrer);
  document.querySelector('[data-testid="action-fermer-reglages"]')?.addEventListener('click', () => {
    selection = null;
    panneau.replaceChildren(texteBloc('Sélectionnez un nœud pour le régler.'));
    rendre();
  });
  document.querySelectorAll('[data-testid="graphe-texte"] [data-noeud]').forEach((bouton) => {
    bouton.addEventListener('click', () => selectionner(bouton.getAttribute('data-noeud')));
  });

  async function enregistrer() {
    if (graphe.noeuds.length === 0) {
      signaler('Ajoutez au moins un composant avant d’enregistrer.', false);
      return;
    }
    const jeton = document.getElementById('workflows-csrf')?.value || '';
    signaler('Enregistrement…', true);
    try {
      // Form-encodé : le corps est lu par PHP (`$_POST`) quel que soit le middleware de parsing.
      const corps = new URLSearchParams({ graphe: JSON.stringify(graphe) });
      const reponse = await fetch(donnees.urls.enregistrer, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': jeton },
        body: corps,
      });
      const resultat = await reponse.json().catch(() => ({ ok: false, cause: 'réponse illisible du serveur' }));
      if (reponse.ok && resultat.ok) {
        // La garde a consommé le jeton et en a posé un neuf : le serveur le rend pour le prochain envoi.
        if (resultat.csrf) document.getElementById('workflows-csrf').value = resultat.csrf;
        signaler(`Enregistré — ${resultat.regles} règle(s)${resultat.en_veille ? ', ' + resultat.en_veille + ' en veille' : ''}.`, true);
      } else {
        signaler('Refusé : ' + (resultat.cause || ('code ' + reponse.status)), false);
      }
    } catch (e) {
      signaler('Enregistrement impossible : ' + e.message, false);
    }
  }

  // ------------------------------------------------------------------ essai et dialogue

  function surlignerEssai() {
    if (!essai || !Array.isArray(essai.chemin)) return;
    const chemin = new Set(essai.chemin.map(String));
    coucheNoeuds.querySelectorAll('.wf-noeud').forEach((g) => {
      if (chemin.has(g.getAttribute('data-noeud'))) {
        g.classList.add('chemin');
        g.setAttribute('data-chemin', '1');
      }
    });
    coucheLiens.querySelectorAll('.wf-lien').forEach((p) => {
      if (chemin.has(p.getAttribute('data-de')) && chemin.has(p.getAttribute('data-vers'))) p.classList.add('chemin');
    });
  }

  function demander({ titre, message }) {
    return new Promise((resoudre) => {
      dialogue.querySelector('[data-testid="dialogue-titre"]').textContent = titre;
      dialogue.querySelector('[data-testid="dialogue-message"]').textContent = message || '';
      dialogue.querySelector('[data-testid="dialogue-champ-boite"]').hidden = true;
      const fermer = () => {
        dialogue.removeEventListener('close', fermer);
        resoudre(dialogue.returnValue === 'valider');
      };
      dialogue.addEventListener('close', fermer);
      dialogue.showModal();
    });
  }

  function signaler(message, correct) {
    if (!statut) return;
    statut.hidden = false;
    statut.textContent = message;
    statut.className = 'chip ' + (correct ? 'ok' : 'danger');
  }

  // ------------------------------------------------------------------ aides DOM

  function lireDonnees() {
    const script = document.getElementById('workflows-donnees');
    if (!script) return null;
    try {
      return JSON.parse(script.textContent);
    } catch (e) {
      return null;
    }
  }

  function prochainNumero() {
    let max = 0;
    graphe.noeuds.forEach((n) => {
      const m = /^n(\d+)$/.exec(String(n.id || ''));
      if (m) max = Math.max(max, Number(m[1]));
    });
    return max + 1;
  }

  function creer(balise, attributs) {
    const element = document.createElementNS('http://www.w3.org/2000/svg', balise);
    Object.entries(attributs).forEach(([cle, valeur]) => element.setAttribute(cle, String(valeur)));
    return element;
  }

  function texte(x, y, contenu, classe) {
    const element = creer('text', { x, y, class: classe });
    element.textContent = contenu;
    return element;
  }

  function poignee(cx, cy, sens, noeudId, sortie) {
    const cercle = creer('circle', { cx, cy, r: 7, class: 'wf-poignee wf-' + sens,
      'data-testid': sens + '-' + noeudId + '-' + sortie, 'data-noeud-id': noeudId });
    if (sens === 'sortie') cercle.setAttribute('data-sortie', sortie);
    return cercle;
  }

  function couper(texte, taille) {
    return texte.length > taille ? texte.slice(0, taille) + '…' : texte;
  }

  function libelleEntree(noeud) {
    const entree = entrees[noeud.type];
    if (noeud.genre === 'etat') return (noeud.config?.nom || '') + (noeud.config?.initial ? ' · initial' : '');
    if (noeud.genre === 'condition') return String(noeud.config?.champ || '') + ' ' + String(noeud.config?.operateur || '');
    if (noeud.genre === 'action') return String(noeud.config?.type || '');
    return entree?.libelle || noeud.type;
  }

  function champ(id, libelle, type, valeur) {
    const bloc = document.createElement('div');
    bloc.className = 'champ';
    const label = document.createElement('label');
    label.textContent = libelle;
    label.setAttribute('for', id);
    const input = document.createElement('input');
    input.type = type;
    input.id = id;
    input.value = valeur ?? '';
    input.setAttribute('data-testid', id);
    bloc.append(label, input);
    return bloc;
  }

  function bouton(testid, libelle, classe) {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = classe;
    b.setAttribute('data-testid', testid);
    b.textContent = libelle;
    return b;
  }

  function texteBloc(contenu) {
    const p = document.createElement('p');
    p.className = 'muted';
    p.textContent = contenu;
    return p;
  }
}
