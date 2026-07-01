/**
 * Personas de test — source alignée sur tools/eval-full.php et FUNCTIONS-SPEC.md.
 * WinBiz / rapprochement ERP : hors périmètre persona (plugin, non prioritaire).
 */

export const BASE_PATH = process.env.KDOCS_BASE_PATH ?? '/kdocs';
export const HOST = process.env.KDOCS_HOST ?? '127.0.0.1';
export const PORT = process.env.KDOCS_PORT ?? '8765';
export const BASE = `http://${HOST}:${PORT}${BASE_PATH}`;

export const ERROR_MARKERS = [
  'Fatal error', 'Parse error', 'Uncaught', 'Whoops',
  'PDOException', 'Call to undefined', 'syntax error, unexpected',
];

export type PersonaDef = {
  username: string;
  label: string;
  /** Peut valider une facture 6000 CHF (lot eval) */
  canValidateFacture6000: boolean;
};

/** Personas métier internes (validation par rôle). */
export const INTERNAL_PERSONAS: PersonaDef[] = [
  { username: 'eval_secretaire', label: 'secretaire', canValidateFacture6000: false },
  { username: 'eval_comptable', label: 'comptable', canValidateFacture6000: false },
  { username: 'eval_rh', label: 'rh', canValidateFacture6000: false },
  { username: 'eval_employeur', label: 'employeur', canValidateFacture6000: true },
];

/**
 * Expert ECM REDX : valide le parcours document (types, métadonnées, classification).
 * Ne couvre PAS le module WinBiz (rapprochement = plugin, après identification doc).
 */
export const REDX_EXPERT: PersonaDef = {
  username: 'eval_redx_expert',
  label: 'redx-expert',
  canValidateFacture6000: true,
};

/** Types documentaires attendus pour l'identification (oracle F-REDX-TYPES). */
export const EXPECTED_DOC_TYPE_LABELS = [
  'Facture',
  'Note de crédit',
  'Contrat',
  'Courrier',
  'Reçu',
];
