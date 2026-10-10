import { expect } from '@playwright/test';
import { enregistrerParcours, pdfUnique, type Geste } from './parcours';

/**
 * Persona « débutant » — rôle Manager, droit de dossier Dépôt sur son dossier : il découvre l'écran,
 * dépose et retrouve. F1 : se repérer dans les entrées de menu depuis l'accueil. F2 : déposer un
 * document. F5 : le retrouver. F6 : ouvrir sa fiche.
 */

const PERSONA = 'debutant';

const gestes: Geste[] = [
  {
    nom: 'se-reperer',
    fonction: 'F1',
    libelle: 'Se repérer dans les entrées de menu depuis l’écran d’accueil',
    jouer: async (page) => {
      await expect(page.getByTestId('ecran-a-traiter')).toBeVisible();
      await expect(page.getByTestId('menu-a-traiter')).toBeVisible();
      await expect(page.getByTestId('menu-bibliotheque')).toBeVisible();
      await expect(page.getByTestId('menu-recherche')).toBeVisible();
      await expect(page.getByTestId('menu-archives')).toBeVisible();
      await expect(page.getByTestId('menu-workflows')).toBeVisible();
      await expect(page.getByTestId('action-deposer')).toBeVisible();
    },
  },
  {
    nom: 'deposer',
    fonction: 'F2',
    libelle: 'Déposer un document par l’écran, depuis le bouton de l’accueil',
    jouer: async (page) => {
      await page.getByTestId('action-deposer').click();
      await expect(page).toHaveURL(/\/deposer$/);
      await page.getByTestId('champ-photo').setInputFiles({
        name: 'note-debutant.pdf', mimeType: 'application/pdf', buffer: pdfUnique(),
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
      await page.getByTestId('recherche-globale-champ').fill('Debutant');
      await page.getByTestId('recherche-globale-champ').press('Enter');
      await expect(page.getByTestId(`resultat-${preparation.documents.debutant.id}`)).toBeVisible();
    },
  },
  {
    nom: 'ouvrir-fiche',
    fonction: 'F6',
    libelle: 'Ouvrir la fiche du document trouvé par la recherche',
    jouer: async (page, preparation) => {
      await page.getByTestId('recherche-globale-champ').fill('Debutant');
      await page.getByTestId('recherche-globale-champ').press('Enter');
      await page.getByTestId(`resultat-${preparation.documents.debutant.id}`)
        .locator(`a[href="/documents/${preparation.documents.debutant.id}"]`).click();
      await expect(page.getByTestId('ecran-document')).toBeVisible();
    },
  },
];

enregistrerParcours(PERSONA, gestes);
