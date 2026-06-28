/**
 * Controleur de theme Karbonic — clair / sombre / systeme.
 *
 * Le no-FOUC (application de .dark avant rendu) est fait par un snippet inline
 * dans le <head> du layout. Ce module gere : le cycle au clic, la persistance
 * localStorage, l'etat des boutons [data-theme-toggle], et le suivi de l'OS en
 * mode "systeme".
 */
(function () {
  'use strict';

  var KEY = 'kdocs-theme';
  var ORDER = ['system', 'light', 'dark'];
  var LABELS = { system: 'Systeme', light: 'Clair', dark: 'Sombre' };
  var ICONS = { system: 'fa-circle-half-stroke', light: 'fa-sun', dark: 'fa-moon' };

  function current() {
    var t = localStorage.getItem(KEY);
    return ORDER.indexOf(t) === -1 ? 'system' : t;
  }

  function systemDark() {
    return !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
  }

  function apply(t) {
    var dark = t === 'dark' || (t === 'system' && systemDark());
    document.documentElement.classList.toggle('dark', dark);
    document.documentElement.setAttribute('data-theme', t);

    var toggles = document.querySelectorAll('[data-theme-toggle]');
    for (var i = 0; i < toggles.length; i++) {
      var btn = toggles[i];
      var label = LABELS[t] || LABELS.system;
      btn.setAttribute('title', 'Theme : ' + label + ' (cliquer pour changer)');
      btn.setAttribute('aria-label', 'Theme : ' + label);
      var icon = btn.querySelector('[data-theme-icon]');
      if (icon) {
        icon.className = 'fas ' + (ICONS[t] || ICONS.system);
        icon.setAttribute('data-theme-icon', '');
      }
      var text = btn.querySelector('[data-theme-label]');
      if (text) { text.textContent = label; }
    }
  }

  function set(t) {
    localStorage.setItem(KEY, t);
    apply(t);
  }

  // Expose pour les onclick inline du chrome.
  window.kdocsCycleTheme = function () {
    var i = ORDER.indexOf(current());
    set(ORDER[(i + 1) % ORDER.length]);
  };
  window.kdocsSetTheme = function (t) {
    if (ORDER.indexOf(t) !== -1) { set(t); }
  };

  // Suivre l'OS quand on est en mode "systeme".
  if (window.matchMedia) {
    try {
      window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
        if (current() === 'system') { apply('system'); }
      });
    } catch (e) { /* anciens navigateurs : addListener ignore */ }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { apply(current()); });
  } else {
    apply(current());
  }
})();
