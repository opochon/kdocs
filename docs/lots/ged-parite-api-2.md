# Lot ged-parite-api-2 — rattraper les écarts `api` de la parité Paperless-ngx 2.20.5

Base : `v2` = `f1838f4` (parité stricte 70,00 %, 546/780 ; catégorie `api` 96/318, 179 absents).
Cible : les 77 éléments de `C:\ged\lots\api-2-elements.txt` (copiés dans
`docs/lots/ged-parite-api-2-elements.txt`) : 32 filtres, 18 routes, 21 vues, 6 actions. Les 102 verbes REST
des ressources relèvent du lot suivant (api-3) et ne sont pas traités ici.

Méthode oracle d'abord, par famille : vecteurs extraits en LECTURE SEULE des tests de Paperless
(`C:\ged\ref\paperless-ngx`, tag v2.20.5, commit `d1aa76e`) → rejeu PHPUnit contre la VRAIE couche de
requêtes de GEDv2 (`gedv2_test`) → rouge → implémentation → vert → commit. Aucune ligne de code Paperless
(GPL-3) reprise : uniquement des couples `{entree, sortie}`.

## Fait (commits)

- `docs/lots/ged-parite-api-2-elements.txt` : liste exacte des 77 éléments, copiée en tête de lot.

## En cours

- Famille 1 — filtres de documents.

## Reste

- Familles 2 (filtres des autres ressources), 3 (vues d'information et de recherche), 4 (actions et
  comptes/TOTP/liens sociaux).
- Correspondance, rapport, harness, porte qualité.

ETAT: en cours
