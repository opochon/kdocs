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

- `480eba7` — **oracle api-2** : `tools/parite/extraire-api-2.py` (lecture seule de
  `test_api_documents.py::test_document_filters`, `test_api_search.py::test_search_filtering`,
  `test_api_custom_fields.py::test_custom_field_filters`) + vecteurs commités
  `tests/parite/vecteurs/api-2-filtres-documents.json` (15 rejouables, 24 `non_rejouables` Django/DRF)
  et `api-2-filtres-ressources.json` (3 rejouables). Liste exacte des 77 éléments copiée.
- `adc18d5` — **famille 1 « filtres-documents »** : `Catalogue` câble les filtres de champs personnalisés
  (`champs_contient`, `champs_presents`, `champs_tous`, `champs_any`, `sans_champs`), valeurs lues dans
  `meta.champs` (le dossier fait foi), clés passées par `JSON_CONTAINS(JSON_KEYS(JSON_EXTRACT(…)), ?)`.
  Rejeu `ApiDeuxFiltresDocumentsTest` : 15 vecteurs + bornes des champs, vu ROUGE avant le code.
- `82925a8` + `8f21aed` (extracteur) — **famille 2 « filtres des autres ressources »** :
  `GET /api/v1/champs` (définitions, filtres `id`/`ids`/`nom`/`nom_contient`) et
  `GET /api/v1/partages` (liens de partage, filtres `cree_min`/`cree_max`/`expire_min`/`expire_max`,
  bornés aux documents lisibles). `ApiJeton` extrait de `DocumentsApi` (porte unique 401/403/429).
- `563b947` — **famille 3 « vues d'information et de recherche »** : `GET /api/v1/statistiques`,
  `GET /api/v1/next_asn`, `GET /api/v1/autocomplete`, `POST /api/v1/documents/selection_data`,
  `GET /api/v1/documents/{id}/metadonnees`, tous bornés par le prédicat de lecture des droits.
- `6c00cf0` — **famille 4 « actions »** : `POST /api/v1/documents/{id}/email`,
  `POST /api/v1/documents/email`, `POST /api/v1/documents/bulk_edit`, `POST /api/v1/documents/bulk_download`,
  `POST /api/v1/dossiers/tester`. Les envois passent par `Mailer` (transport « fichier » en test, aucun
  envoi réel) ; la masse réutilise `Socle\ModificationMasse`.
- *(en cours de commit)* — **correspondance api-2** : `tools/parite/corriger-correspondance-api-2.php`
  (additif, ciblé sur les 77 ids) : 20 `couvert`, 33 `couvert-autrement`, 18 `exclu`
  (infrastructure-django / infrastructure-celery-redis), 6 `absent` restants avec raison et référence.

## Correspondance (docs/parite/correspondance.json)

| Statut | Avant | Après |
| --- | ---: | ---: |
| absent | 187 | 116 |
| couvert | 21 | 41 |
| couvert-autrement | 525 | 558 |
| exclu | 47 | 65 |
| total | 780 | 780 |

Les 6 restants : `DocumentFilterSet.custom_field_query`, `.original_filename`, `.owner`, `.owner__id`,
`.owner__id__none`, `TagFilterSet.is_root` (modèle absent, raison + référence dans la note du lot).

## Reste

- Rejeu du harness `-SansBanc` (couches 0,1,2,4,5,6,7,8,9) et régénération du rapport par le juge.
- Note finale, mesures exactes, ETAT.

ETAT: en cours
