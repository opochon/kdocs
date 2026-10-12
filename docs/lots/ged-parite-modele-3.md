# Lot ged-parite-modele-3 — reste de `modele` et petites catégories voisines

Base : `ged-parite-modele-2` fusionné (`4800682`) : 780 éléments, 435 couverts, parité stricte 55,77 %.
Cible : le RESTE de la catégorie `modele` (79 absents : champs du document, vues enregistrées
`SavedView`/`DisplayFields`/`DisplayMode`, champs personnalisés `FieldDataType`/`CustomFieldInstance`,
`MatchingModel`, `Tag`, `ModelWithOwner`, `UiSettings`, `ShareLink`, `PaperlessTask`/`TaskName`/`TaskType`)
et les catégories voisines `permissions` (4), `masse` (3), `correspondance` (4), `commande` (6),
`chaine` (5).

Secteur : Socle (documents, vues, champs, nomenclature, collaboration) ; les éléments qui relèvent d'un
autre secteur sont couverts par un équivalent EXISTANT (Securite, Ingestion, Classement, Apercu, Archivage)
sans modifier ces secteurs.

Méthode oracle d'abord, par sous-famille : vecteurs en LECTURE SEULE des tests Paperless
(`C:\ged\ref\paperless-ngx`, tag v2.20.5) → rejeu PHPUnit contre le vrai service et `gedv2_test` → rouge →
implémentation → vert → commit par sous-famille.

## Fait (commits)

_(à compléter au fil du lot)_

## En cours

Cadrage : inventaire des 101 absents, cartographie Paperless → GEDv2, note de lot.

## Reste

- Sous-familles : document/champs, vues enregistrées, matching/tags/owner/UiSettings/ShareLink, tâches,
  permissions/masse/correspondance/commande/chaine.
- Parité : correspondance.json, rapport PARITE-PAPERLESS.md, couche 9.
- Porte qualité et harness.

## Mesures exactes

_(à compléter)_
