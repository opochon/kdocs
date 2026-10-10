import { expect } from '@playwright/test';
import { enregistrerParcours, type Geste } from './parcours';

/**
 * Persona « comptable » — rôle Manager, droit de dossier Gestion sur son dossier.
 * F8 : trouver les circuits et règles de workflow. F9 : poser l'échéance d'un contrat sous horloge
 * figée. F11 : trouver le scellement sur la fiche et la liste des archives.
 */

const PERSONA = 'comptable';

/** Horloge figée du geste F9 : le persona raisonne « dans 30 jours » depuis cette date. */
const AUJOURDHUI_FIGE = new Date('2026-10-10T09:00:00');
const ECHEANCE = '2026-11-09';

const gestes: Geste[] = [
  {
    nom: 'workflows-liste',
    fonction: 'F8',
    libelle: 'Trouver les circuits et règles de workflow par le menu',
    jouer: async (page) => {
      await page.getByTestId('menu-workflows').click();
      await expect(page.getByTestId('ecran-workflows')).toBeVisible();
      await expect(page.getByTestId('action-nouveau-circuit')).toBeVisible();
      await expect(page.getByTestId('regles')).toBeVisible();
    },
  },
  {
    nom: 'contrat-echeance',
    fonction: 'F9',
    libelle: 'Ouvrir un contrat dans la bibliothèque et poser son échéance (horloge figée)',
    jouer: async (page, preparation) => {
      await page.clock.install({ time: AUJOURDHUI_FIGE });
      await page.getByTestId('menu-bibliotheque').click();
      await expect(page.getByTestId('ecran-bibliotheque')).toBeVisible();
      await page.getByTestId(`ligne-${preparation.documents.comptable.id}`).getByRole('link').click();
      const champ = page.getByTestId(`saisie-champ-${preparation.champ_echeance}`);
      await expect(champ).toBeVisible();
      await champ.fill(ECHEANCE);
      await page.getByTestId('action-enregistrer-champs').click();
      await expect(champ).toHaveValue(ECHEANCE);
    },
  },
  {
    nom: 'sceller-document',
    fonction: 'F11',
    libelle: 'Ouvrir la fiche d’un document validé et trouver le scellement',
    jouer: async (page, preparation) => {
      await page.getByTestId('menu-bibliotheque').click();
      await page.getByTestId(`ligne-${preparation.documents.comptable.id}`).getByRole('link').click();
      await page.getByTestId('ouvrir-fiche').click();
      await expect(page.getByTestId('ecran-document')).toBeVisible();
      await expect(page.getByTestId('document-sceller').or(page.getByTestId('archivage-tsa-absente'))).toBeVisible();
    },
  },
  {
    nom: 'archives-liste',
    fonction: 'F11',
    libelle: 'Trouver la liste des documents scellés par le menu Archives',
    jouer: async (page) => {
      await page.getByTestId('menu-archives').click();
      await expect(page.getByTestId('ecran-archives')).toBeVisible();
      await expect(page.getByTestId('archives-liste')).toBeVisible();
    },
  },
];

enregistrerParcours(PERSONA, gestes);
