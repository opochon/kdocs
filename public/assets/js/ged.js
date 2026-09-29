// GEDv2 — comportements communs. JS natif, sans dépendance. Chaque comportement est déclaré par un attribut.

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

// À traiter : validation au clavier. Entrée = valider, → = passer au suivant.
const file = document.querySelector('[data-testid="file-a-traiter"]');
if (file) {
  const suivant = () => {
    const liens = [...file.querySelectorAll('a')];
    const i = liens.findIndex((a) => a.getAttribute('aria-current') === 'true');
    const cible = liens[i + 1] ?? liens[0];
    if (cible) window.location.href = cible.href;
  };
  document.querySelector('[data-action="suivant"]')?.addEventListener('click', suivant);
  document.querySelector('[data-action="valider"]')?.addEventListener('click', suivant);
  document.addEventListener('keydown', (e) => {
    const saisie = e.target instanceof HTMLElement && e.target.matches('input, select, textarea');
    if (e.key === 'ArrowRight' && !saisie) { e.preventDefault(); suivant(); }
    if (e.key === 'Enter' && !saisie) { e.preventDefault(); suivant(); }
  });
}
