import { expect } from '@playwright/test';
import { enregistrerParcours, pdfUnique, type Geste } from './parcours';

/**
 * Persona « secrétaire » — rôle Manager, droit de dossier Modification sur son dossier.
 * F4 : valider à la main une pièce de la file. F5 : restreindre la recherche par le filtre Type.
 * F2 : déposer un document par l'écran. Le persona part de l'accueil et cherche par les menus visibles.
 */

const PERSONA = 'secretaire';

const gestes: Geste[] = [
  {
    nom: 'valider-piece',
    fonction: 'F4',
    libelle: 'Valider à la main la pièce à contrôler depuis la file de l’accueil',
    jouer: async (page, preparation) => {
      const document = preparation.documents.secretaire.id;
      await page.getByTestId(`file-item-${document}`).click();
      await expect(page).toHaveURL(new RegExp(`/a-traiter\\?doc=${document}$`));
      await expect(page.getByTestId('action-valider')).toBeVisible();
      await page.getByTestId('action-valider').click();
      await expect(page).toHaveURL(/\/a-traiter(\?|$)/, { timeout: 15_000 });
      await expect(page.getByTestId(`file-item-${document}`)).toHaveCount(0);
    },
  },
  {
    nom: 'recherche-filtre',
    fonction: 'F5',
    libelle: 'Retrouver un document et restreindre la recherche par le filtre Type',
    jouer: async (page, preparation) => {
      await page.getByTestId('recherche-globale-champ').fill('Secretaire');
      await page.getByTestId('recherche-globale-champ').press('Enter');
      await expect(page.getByTestId(`resultat-${preparation.documents.secretaire.id}`)).toBeVisible();
      await page.getByTestId('filtre-type').selectOption('Facture');
      await page.getByTestId('action-filtrer').click();
      await expect(page).toHaveURL(/type=Facture/);
      await expect(page.getByTestId(`resultat-${preparation.documents.secretaire.id}`)).toBeVisible();
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
        name: 'courrier-secretaire.pdf', mimeType: 'application/pdf', buffer: pdfUnique(),
      });
      await page.getByTestId('action-envoyer').click();
      await expect(page.getByTestId('depot-retour')).toBeVisible({ timeout: 15_000 });
    },
  },
];

enregistrerParcours(PERSONA, gestes);
