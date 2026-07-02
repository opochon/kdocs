/**
 * Registre machine des fonctions UI (F-*) — aligné sur tests/visual/FUNCTIONS-SPEC.md
 * et docs/PASSE-FONCTIONS-UI.md.
 *
 * Chaque entrée lie : id fonction → lot de passe → spec Playwright → personas.
 */

export type FunctionLot = 'A-ecm' | 'B-lib' | 'C-fiche' | 'D-recherche-taches' | 'E-admin' | 'F-chrome-a11y' | 'G-personas';

export type UiFunctionDef = {
  id: string;
  label: string;
  lot: FunctionLot;
  /** Spec Playwright principale (fichier sous specs/) */
  spec: string | null;
  /** Nom du test Playwright (grep) si couvert */
  testName: string | null;
  /** Personas concernés : * = tous, [] = root/session technique */
  personas: string[];
  covered: boolean;
};

/** Ordre d'exécution recommandé des lots (correction avant suite). */
export const LOT_ORDER: FunctionLot[] = [
  'A-ecm',
  'G-personas',
  'B-lib',
  'C-fiche',
  'D-recherche-taches',
  'E-admin',
  'F-chrome-a11y',
];

export const UI_FUNCTIONS: UiFunctionDef[] = [
  // --- Lot A : parcours ECM (ingérer → classer → analyser) ---
  { id: 'F-IMP-01', label: 'Upload page formulaire', lot: 'A-ecm', spec: 'persona-parcours-ecm.spec.ts', testName: 'Lot A — ingérer', personas: ['eval_redx_expert'], covered: true },
  { id: 'F-LIB-02', label: 'Ouvrir fiche document', lot: 'A-ecm', spec: 'persona-parcours-ecm.spec.ts', testName: 'Lot A — ingérer', personas: ['eval_redx_expert'], covered: true },
  { id: 'F-DOC-01', label: 'Édition métadonnées + save', lot: 'A-ecm', spec: 'persona-parcours-ecm.spec.ts', testName: 'Lot A — classer', personas: ['eval_redx_expert'], covered: true },
  { id: 'F-DOC-02', label: 'Suggestion IA (classify-ai)', lot: 'A-ecm', spec: 'persona-parcours-ecm.spec.ts', testName: 'Lot A — analyser', personas: ['eval_redx_expert'], covered: true },
  { id: 'F-SEARCH-01', label: 'Recherche simple', lot: 'A-ecm', spec: 'persona-parcours-ecm.spec.ts', testName: 'Lot A — ingérer', personas: ['eval_redx_expert'], covered: true },
  { id: 'F-LIB-03', label: 'Upload drag-drop bibliothèque', lot: 'A-ecm', spec: 'pipeline-ui.spec.ts', testName: 'UI pipeline', personas: ['*'], covered: true },

  // --- Lot G : personas / validation ---
  { id: 'F-AUTH-01', label: 'Login', lot: 'G-personas', spec: 'persona.spec.ts', testName: 'login + documents', personas: ['eval_*'], covered: true },
  { id: 'F-VAL-01', label: 'Droits validation', lot: 'G-personas', spec: 'persona.spec.ts', testName: 'droits de validation', personas: ['eval_*'], covered: true },
  { id: 'F-DOC-04', label: 'Toggle validation UI', lot: 'G-personas', spec: 'persona-preview.spec.ts', testName: 'can_validate', personas: ['eval_*'], covered: true },
  { id: 'F-REDX-TYPES', label: 'Types ECM expert', lot: 'G-personas', spec: 'persona-redx-expert.spec.ts', testName: 'types documentaires ECM', personas: ['eval_redx_expert'], covered: true },

  // --- Lot B : bibliothèque (à instrumenter) ---
  { id: 'F-LIB-01', label: 'Parcourir arborescence', lot: 'B-lib', spec: 'shell.spec.ts', testName: 'shell: documents', personas: ['*'], covered: true },
  { id: 'F-LIB-04', label: 'Indexer dossier', lot: 'B-lib', spec: null, testName: null, personas: ['*'], covered: false },
  { id: 'F-LIB-05', label: 'Renommer dossier', lot: 'B-lib', spec: null, testName: null, personas: ['*'], covered: false },
  { id: 'F-LIB-06', label: 'Déplacer dossier', lot: 'B-lib', spec: null, testName: null, personas: ['*'], covered: false },
  { id: 'F-LIB-07', label: 'Supprimer dossier', lot: 'B-lib', spec: null, testName: null, personas: ['*'], covered: false },
  { id: 'F-LIB-08', label: 'Tri / vue', lot: 'B-lib', spec: 'shell.spec.ts', testName: null, personas: ['*'], covered: false },

  // --- Lot C : fiche document ---
  { id: 'F-DOC-03', label: 'Retraiter OCR', lot: 'C-fiche', spec: null, testName: null, personas: ['*'], covered: false },
  { id: 'F-DOC-05', label: 'Soumettre validation', lot: 'C-fiche', spec: null, testName: null, personas: ['eval_*'], covered: false },
  { id: 'F-DOC-06', label: 'Télécharger', lot: 'C-fiche', spec: null, testName: null, personas: ['*'], covered: false },
  { id: 'F-DOC-07', label: 'Supprimer doc', lot: 'C-fiche', spec: null, testName: null, personas: ['*'], covered: false },
  { id: 'F-DOC-08', label: 'Onglets fiche', lot: 'C-fiche', spec: null, testName: null, personas: ['*'], covered: false },
  { id: 'F-DOC-09', label: 'Notes', lot: 'C-fiche', spec: null, testName: null, personas: ['*'], covered: false },
  { id: 'F-DOC-10', label: 'Versions SMQ', lot: 'C-fiche', spec: 'smq-versions.spec.ts', testName: null, personas: ['*'], covered: true },

  // --- Lot D ---
  { id: 'F-SEARCH-02', label: 'Recherche avancée', lot: 'D-recherche-taches', spec: 'shell.spec.ts', testName: 'shell: search', personas: ['*'], covered: false },
  { id: 'F-SEARCH-03', label: 'Sémantique / hybride', lot: 'D-recherche-taches', spec: null, testName: null, personas: ['*'], covered: false },
  { id: 'F-TASK-01', label: 'Liste tâches', lot: 'D-recherche-taches', spec: 'shell.spec.ts', testName: 'shell: mes-taches', personas: ['eval_*'], covered: false },
  { id: 'F-TASK-02', label: 'Valider depuis tâche', lot: 'D-recherche-taches', spec: null, testName: null, personas: ['eval_*'], covered: false },
  { id: 'F-IMP-02', label: 'Consume admin', lot: 'D-recherche-taches', spec: null, testName: null, personas: ['admin'], covered: false },

  // --- Lot E : admin ---
  { id: 'F-ADM-01', label: 'Hub admin', lot: 'E-admin', spec: 'shell.spec.ts', testName: 'shell: admin', personas: ['admin'], covered: false },
  { id: 'F-ADM-04', label: 'Diagnostic', lot: 'E-admin', spec: 'bugs-click.spec.ts', testName: 'Diagnostic', personas: ['admin'], covered: true },

  // --- Lot F : chrome / a11y ---
  { id: 'F-CHROME-01', label: 'Sidebar user', lot: 'F-chrome-a11y', spec: 'chrome-coherence.spec.ts', testName: 'F-CHROME-01', personas: ['*'], covered: true },
  { id: 'F-CHROME-02', label: 'Compteurs sidebar', lot: 'F-chrome-a11y', spec: 'chrome-coherence.spec.ts', testName: 'F-CHROME-02', personas: ['*'], covered: true },
  { id: 'F-A11Y-01', label: 'Contraste a11y', lot: 'F-chrome-a11y', spec: 'a11y.spec.ts', testName: 'sans violations', personas: ['eval_*'], covered: true },
];

export function coverageSummary(): { total: number; covered: number; byLot: Record<string, { total: number; covered: number }> } {
  const byLot: Record<string, { total: number; covered: number }> = {};
  let covered = 0;
  for (const f of UI_FUNCTIONS) {
    if (!byLot[f.lot]) byLot[f.lot] = { total: 0, covered: 0 };
    byLot[f.lot].total++;
    if (f.covered) {
      covered++;
      byLot[f.lot].covered++;
    }
  }
  return { total: UI_FUNCTIONS.length, covered, byLot };
}
