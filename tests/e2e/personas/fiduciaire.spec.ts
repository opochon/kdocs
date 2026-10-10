import { expect, test } from '@playwright/test';
import { enregistrerParcours, pdfUnique, type Geste } from './parcours';

/**
 * Persona « fiduciaire » — rôle Manager, droit de dossier Dépôt sur son dossier (modèle 2026-10-03).
 * F1 : lire le bilan d'ingestion sur l'accueil. F2 : déposer un document par l'écran.
 * F5 : retrouver une pièce par la recherche globale. F6 : ouvrir la fiche et trouver « Nouvelle version ».
 * Le persona part de l'accueil et cherche par les menus et libellés visibles.
 */

const PERSONA = 'fiduciaire';

const gestes: Geste[] = [
  {
    nom: 'bilan-ingestion',
    fonction: 'F1',
    libelle: 'Lire le bilan d’ingestion sur l’écran d’accueil',
    jouer: async (page) => {
      await expect(page.getByTestId('ecran-a-traiter')).toBeVisible();
      await expect(page.getByTestId('bilan-ingestion')).toBeVisible();
      await expect(page.getByTestId('bilan-a-controler')).toBeVisible();
    },
  },
  {
    nom: 'deposer',
    fonction: 'F2',
    libelle: 'Déposer un document par l’écran, depuis le bouton de l’accueil',
    jouer: async (page) => {
      await page.getByTestId('action-deposer').click();
      await expect(page).toHaveURL(/\/deposer$/);
      await expect(page.getByTestId('ecran-deposer')).toBeVisible();
      await page.getByTestId('champ-photo').setInputFiles({
        name: 'courrier-persona.pdf', mimeType: 'application/pdf', buffer: pdfUnique(),
      });
      await page.getByTestId('action-envoyer').click();
      await expect(page.getByTestId('depot-retour')).toBeVisible({ timeout: 15_000 });
    },
  },
  {
    nom: 'retrouver',
    fonction: 'F5',
    libelle: 'Retrouver un document par la barre de recherche globale',
    jouer: async (page, preparation) => {
      await page.getByTestId('recherche-globale-champ').fill('Fiduciaire');
      await page.getByTestId('recherche-globale-champ').press('Enter');
      await expect(page).toHaveURL(/\/recherche\?q=/);
      await expect(page.getByTestId(`resultat-${preparation.documents.fiduciaire.id}`)).toBeVisible();
    },
  },
  {
    nom: 'fiche-nouvelle-version',
    fonction: 'F6',
    libelle: 'Ouvrir la fiche d’un document et trouver « Nouvelle version »',
    jouer: async (page, preparation) => {
      await page.getByTestId('menu-bibliotheque').click();
      await expect(page.getByTestId('ecran-bibliotheque')).toBeVisible();
      await page.getByTestId(`ligne-${preparation.documents.fiduciaire.id}`).getByRole('link').click();
      await expect(page.getByTestId('ouvrir-fiche')).toBeVisible();
      await page.getByTestId('ouvrir-fiche').click();
      await expect(page.getByTestId('ecran-document')).toBeVisible();
      await expect(page.getByTestId('document-nouvelle-version')).toBeVisible();
    },
  },
];

enregistrerParcours(PERSONA, gestes);
