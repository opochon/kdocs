import { expect } from '@playwright/test';
import { enregistrerParcours, type Geste } from './parcours';

/**
 * Persona « auditeur » — rôle Manager, droit de dossier Lecture seule sur son dossier : il consulte,
 * il ne classe ni ne dépose. F7 : lire l'arbre des dossiers du fonds. F10 : ne voir que son dossier.
 * F5 : retrouver un document. F6 : ouvrir sa fiche et trouver le téléchargement.
 */

const PERSONA = 'auditeur';

const gestes: Geste[] = [
  {
    nom: 'arbre-dossiers',
    fonction: 'F7',
    libelle: 'Lire l’arbre des dossiers du fonds dans la bibliothèque',
    jouer: async (page) => {
      await page.getByTestId('menu-bibliotheque').click();
      await expect(page.getByTestId('ecran-bibliotheque')).toBeVisible();
      await expect(page.getByTestId('arbre-dossiers')).toBeVisible();
      await expect(page.getByTestId(`dossier-personas-${PERSONA}`)).toBeVisible();
    },
  },
  {
    nom: 'mon-dossier-seulement',
    fonction: 'F10',
    libelle: 'Se restreindre à son dossier et n’y voir que ses pièces',
    jouer: async (page, preparation) => {
      await page.getByTestId('menu-bibliotheque').click();
      await page.getByTestId(`dossier-personas-${PERSONA}`).click();
      await expect(page).toHaveURL(/dossier=personas/);
      await expect(page.getByTestId(`ligne-${preparation.documents.auditeur.id}`)).toBeVisible();
      await expect(page.getByTestId(`ligne-${preparation.documents.fiduciaire.id}`)).toHaveCount(0);
    },
  },
  {
    nom: 'rechercher',
    fonction: 'F5',
    libelle: 'Retrouver un document par la barre de recherche globale',
    jouer: async (page, preparation) => {
      await page.getByTestId('recherche-globale-champ').fill('Audit');
      await page.getByTestId('recherche-globale-champ').press('Enter');
      await expect(page.getByTestId(`resultat-${preparation.documents.auditeur.id}`)).toBeVisible();
    },
  },
  {
    nom: 'ouvrir-fiche',
    fonction: 'F6',
    libelle: 'Ouvrir sa fiche document et trouver le téléchargement',
    jouer: async (page, preparation) => {
      await page.getByTestId('menu-bibliotheque').click();
      await page.getByTestId(`ligne-${preparation.documents.auditeur.id}`).getByRole('link').click();
      await page.getByTestId('ouvrir-fiche').click();
      await expect(page.getByTestId('ecran-document')).toBeVisible();
      await expect(page.getByTestId('action-telecharger')).toBeVisible();
    },
  },
];

enregistrerParcours(PERSONA, gestes);
