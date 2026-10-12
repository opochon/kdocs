# Lot ged-parite-api-1 — rattraper les écarts `api` de la parité Paperless-ngx 2.20.5

Base : `ged-parite-chaine-1` fusionné (`891d923`) : 780 éléments, 228 couverts, parité stricte 29,23 %.
Cible : la catégorie `api` encore `absent` — 226 éléments (hors 2 exclus Celery/Redis) : filtres de liste,
verbes REST des ressources, actions, routes et « vues » (ViewSets).

Méthode oracle d'abord, par famille (filtres de documents, filtres des autres ressources, verbes des
ressources de classement, verbes d'administration, actions) : vecteurs extraits en LECTURE SEULE des tests
Paperless (`C:\ged\ref\paperless-ngx`, tag v2.20.5, commit `d1aa76e`) → rejeu PHPUnit contre la vraie
couche de requêtes de GEDv2 (`gedv2_test`) → rouge → implémentation → vert → commit. Aucune ligne de code
Paperless (GPL-3) reprise : seuls des couples `{entree, sortie}` sont extraits.

Les ressources dont le MODÈLE n'existe pas dans GEDv2 (champs personnalisés, liens de partage, comptes et
règles de courrier, TOTP, tâches Celery, chemins de stockage comme entité) ne sont pas marquées couvertes :
elles sont listées « modèle absent » et relèvent du lot `modele` (à venir).

## Fait (commits)

- (famille « filtres-documents », commit à venir) — **oracle** : `tools/parite/extraire-api.py` (même
  discipline que `extraire-chaine.py`, lecture seule des tests Paperless) + vecteurs commités
  `tests/parite/vecteurs/api-filtres-documents.json` : 18 vecteurs rejouables (corpus synthétique, requête
  de liste, documents retenus) extraits de `test_api_documents.py`, 2 vecteurs `non_rejouables` (paramètres
  `id__range` inconnu, `tags__id__all` malformé) listés avec raison + référence.
  - **Rejeu** : `tests/php/Parite/ApiFiltresDocumentsTest.php` interroge la vraie couche de requêtes
    (`Jonctions\Catalogue::lister`) sur `gedv2_test` ; oracle lancé ROUGE avant le code (2 échecs,
    implémentation mise de côté), VERT après.
  - **Droits** : `tests/php/Parite/ApiFiltresNonAccesTest.php` prouve par l'interface HTTP réelle
    (jeton lié au compte) qu'aucun filtre (`a_traiter`, `ids`, `titre_contient`, `etiquettes_any`) ne fait
    remonter un document hors dossiers.
  - **Code** : `src/Jonctions/Catalogue.php` porte les filtres de liste (id/ids, titre/contenu et leurs
    opérateurs, empreinte, mime, dates de document et bornes cree_le/maj_le, étiquettes any/none/présence,
    statut À traiter, absence d'émetteur/type/dossier) — tout en paramètres liés, entrée invalide refusée 400.

## En cours

- Famille « filtres-référentiels » (types, étiquettes, émetteurs), puis documentation des restants.

## Reste

- Filtres des autres ressources, verbes des ressources, actions, administration.
- Documentation des 226 restants avec raison + référence.
- `correspondance.json`, régénération du rapport par le juge, harness `-SansBanc`.

## Mesures exactes

| Mesure | Avant | Après |
| --- | ---: | ---: |
| Vecteurs `filtres-documents` rejoués | — | **18 / 18** |
| Vecteurs `filtres-documents` non rejouables (Django/DRF) | — | 2 (listés) |
| `api` couverts | 0 / 226 (absents) | à compléter après correspondance |

ETAT: en cours
