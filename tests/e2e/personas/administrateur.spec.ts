import { expect } from '@playwright/test';
import { enregistrerParcours, type Geste } from './parcours';

/**
 * Persona « administrateur » — rôle Administrateur (configuration, comptes et groupes, reconstruction).
 * F12 : trouver les réglages de l'application et reconstruire l'index depuis le dossier.
 * F3 : lire l'état des connecteurs. F11 : trouver la vérification des archives.
 */

const PERSONA = 'administrateur';

const gestes: Geste[] = [
  {
    nom: 'application-reglages',
    fonction: 'F12',
    libelle: 'Trouver les réglages de l’application par le menu',
    jouer: async (page) => {
      await page.getByTestId('menu-application').click();
      await expect(page.getByTestId('ecran-application')).toBeVisible();
      await expect(page.getByTestId('reglage-ingestion')).toBeVisible();
      await expect(page.getByTestId('reglage-stockage')).toBeVisible();
    },
  },
  {
    nom: 'connecteurs',
    fonction: 'F3',
    libelle: 'Lire l’état des connecteurs K-Time et ClearMyMails',
    jouer: async (page) => {
      await page.getByTestId('menu-application').click();
      await expect(page.getByTestId('etat-ktime')).toBeVisible();
      await expect(page.getByTestId('etat-cmm')).toBeVisible();
    },
  },
  {
    nom: 'reconstruire-index',
    fonction: 'F12',
    libelle: 'Lancer la reconstruction de l’index depuis le dossier et la voir finir',
    jouer: async (page) => {
      await page.getByTestId('menu-application').click();
      await page.getByTestId('reconstruction-lancer').click();
      await expect(page.getByTestId('reconstruction-progression')).toBeVisible({ timeout: 20_000 });
      // Le fonds partagé compte des pièces dont les fichiers ne sont pas dans ce fonds : une tranche
      // indexe ce qu'elle peut, la suivante conclut. Le persona reprend jusqu'à la fin, sans forcer.
      for (let tranche = 0; tranche < 5; tranche++) {
        const terminee = page.getByTestId('reconstruction-terminee');
        if (await terminee.isVisible().catch(() => false)) break;
        const suivante = page.getByTestId('reconstruction-tranche');
        if (!(await suivante.isVisible().catch(() => false))) break;
        await suivante.click();
        await page.waitForLoadState('load');
      }
      await expect(page.getByTestId('reconstruction-terminee')).toBeVisible({ timeout: 20_000 });
    },
  },
  {
    nom: 'archives-verifier',
    fonction: 'F11',
    libelle: 'Trouver la vérification d’intégrité des archives',
    jouer: async (page) => {
      await page.getByTestId('menu-archives').click();
      await expect(page.getByTestId('ecran-archives')).toBeVisible();
      await expect(page.getByTestId('archives-verifier')).toBeVisible();
    },
  },
];

enregistrerParcours(PERSONA, gestes);
