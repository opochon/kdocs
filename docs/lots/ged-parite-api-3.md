# Lot ged-parite-api-3 — rattraper les 108 écarts `api` restants de la parité Paperless-ngx 2.20.5

Base : `v2` = `0e3f618` (parité stricte 76,79 %, 599/780 ; catégorie `api` 149/318 couverts, 108 absents,
61 exclus). Liste exacte : `C:\ged\lots\api-3-elements.txt`, copiée dans
`docs/lots/ged-parite-api-3-elements.txt` (108 lignes).

Méthode : identique au lot `ged-parite-api-2` — vecteurs de comportement extraits en LECTURE SEULE des
tests de Paperless-ngx (`C:\ged\ref\paperless-ngx`, tag v2.20.5, commit `d1aa76e`) dans
`tests/parite/vecteurs/api-3-*.json`, rejoués par PHPUnit contre la vraie couche HTTP de GEDv2
(`gedv2_test`), rouge puis code puis vert. Aucune ligne de code Paperless (GPL-3) reprise.

## Fait (commits)

- (en cours) copie de la liste des 108 éléments.

## En cours

- Famille 1 « ressources de classement » (Tag, DocumentType, CustomField, SavedView, Correspondent,
  StoragePath).

## Reste

- Familles 2 à 6 (administration, sécurité/compte, masse/dépôt, recherche/statistiques/état, partage).
- `docs/parite/correspondance.json` (statuts api-3), regénération `PARITE-PAPERLESS.md` par le juge.
- Revue des exclusions api-2 discutables (TOTPView, SystemStatusView, RemoteVersionView,
  SocialAccountProvidersView, DisconnectSocialAccountView, routes system_status/remoteversion/totp_view/
  tasks classées infrastructure-django alors que leur verbe est resté absent).
- Porte qualité (PHPStan, revue ECC) et harness `-SansBanc`.

## Mesures exactes

| Mesure | Avant | Après |
| --- | ---: | ---: |
| `api` couverts | 149 / 318 | à mesurer |
| Parité stricte | 76,79 % | à mesurer |

ETAT: en cours
