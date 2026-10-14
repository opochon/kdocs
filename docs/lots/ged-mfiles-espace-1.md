# Lot `ged-mfiles-espace-1` — espace perso & partage (favoris, récemment consultés, liens de partage)

Agent : DeepCode · Secteur : `docs/secteurs/espace-partage.md` · Juge : à désigner (porte rejouée).

## Objectif

Porter les 10 éléments M-Files `absent` du registre de parité relevant de l'espace personnel et du partage :
favoris (lister, savoir, ajouter, retirer), récemment consultés (lister, enregistrer), liens de partage
(lister, créer, télécharger par le lien, révoquer).

## Éléments couverts

| Id M-Files | Équivalent GEDv2 | Preuve |
| --- | --- | --- |
| `mfiles:rest:get:favorites` | `Ged\EspacePartage\EspacePerso::favoris` | `MfilesEspaceVecteursTest::testRejoueLesVecteursDesFavorisEtDesRecents` |
| `mfiles:rest:get:favorites-type-objectid` | `EspacePerso::estFavori` | idem |
| `mfiles:rest:post:favorites` | `EspacePerso::ajouterFavori` | idem |
| `mfiles:rest:delete:favorites-type-objectid` | `EspacePerso::retirerFavori` | idem |
| `mfiles:rest:get:recentlyaccessedbyme` | `EspacePerso::recents` | idem |
| `mfiles:rest:post:recentlyaccessedbyme` | `EspacePerso::enregistrerConsultation` | idem |
| `mfiles:rest:get:sharedlinks` | `Ged\EspacePartage\Partages::liens` | `MfilesEspaceVecteursTest::testRejoueLesVecteursDesLiensDePartage` |
| `mfiles:rest:post:sharedlinks` | `Partages::creer` (délègue `Collaboration::partagerExterne`) | idem |
| `mfiles:rest:get:sharedlinks-vaultguid-sharepublicaccesskey-content` | `Partages::ouvrir` | idem |
| `mfiles:rest:delete:sharedlinks-sharepublicaccesskey` | `Partages::revoquerParCle` | idem |

Interfaces réelles : `GET /favoris` (Mon espace : favoris + récents), favori sur la fiche document
(`POST /documents/{id}/favori[/retirer]`), `GET /liens-partages`, révocation par identifiant ou par clé.
Chaque élément actionnable porte un `data-testid`.

## Journal d'avancement

- 14.10.2026 — Cadrage : chemins du secteur posés (`docs/secteurs/espace-partage.md`), ouverture du journal.
- 14.10.2026 — Oracle rouge `tests/php/EspacePartage/EspacePersoTest.php` (8 tests, classe absente → 8 erreurs),
  puis vert : `Ged\EspacePartage\EspacePerso` (favoris lister / savoir / ajouter / retirer + récents
  enregistrer / lister), registre portable `.ged/favoris.json` et `.ged/recents.json`, retrait par marqueur
  daté (zéro suppression), filtrage ACL fail-closed. Commit `favoris:`.
- 14.10.2026 — Oracle rouge `tests/php/EspacePartage/PartagesTest.php` (4 tests, classe absente), puis vert :
  `Ged\EspacePartage\Partages` (liens externes : lister / créer / ouvrir / révoquer par clé), composition de
  `Ged\Socle\Collaboration`. Puis révocation par identifiant sous contrôle des droits (ACL, test dédié).
  Commits `partage:`.
- 14.10.2026 — Interface : écrans `/favoris` (Mon espace) et `/liens-partages`, favori sur la fiche document,
  entrée de rail, permissions des routes déclarées (`DroitsRoutes`), parcours Playwright
  `tests/e2e/espace-perso.spec.ts` **2/2 vert**. Commit `espace-partage:`.
- 14.10.2026 — Preuves de parité : vecteurs `tests/parite/vecteurs/mfiles-espace-favoris.json` (6) et
  `mfiles-espace-partages.json` (4), rejoués par `MfilesEspaceVecteursTest` (2 tests). Registre
  régénéré : **112 éléments, 66 couverts, 0 non-prouvé, 29 absents, 17 exclus — parité stricte 58,93 %,
  hors exclusions 69,47 %**. Commit `parite:`.
- 14.10.2026 — Porte qualité ECC, puis harness `-SansBanc`.

## Preuves

- PHPUnit : `tests/php/EspacePartage` 13 tests / 61 assertions,
  `tests/php/Parite/MfilesEspaceVecteursTest` 2 tests / 16 assertions — verts.
- Playwright : `tests/e2e/espace-perso.spec.ts` 2 parcours verts (favoris + récents ; liens de partage).
- Juge de parité : `php tools/parite/juge-mfiles.php` → VERT, `recette/parite-mfiles.json`.
- Oracle d'abord : chaque service a été écrit après un test rouge constaté (voir journal).

## Porte qualité (ECC)

- **Analyse statique** : PHPStan niveau 6 (`php vendor\bin\phpstan analyse --memory-limit=2G`) → **0 erreur**,
  baseline vide inchangée ; `declare(strict_types=1)` sur tous les fichiers du lot.
- **Revue du diff contre les grilles ECC** :
  - `php-reviewer` : fonctions courtes, SQL isolé dans les services, aucun contrôleur obèse ; `basculerFavori`
    a beaucoup de paramètres (route + geste + redirection) mais reste lisible et testé. **Aucun constat
    CRITIQUE/ÉLEVÉ.**
  - `security-reviewer` : SQL de `EspacePerso`/`Partages` **entièrement paramétré** (le seul fragment concaténé,
    `DroitsDossiers::predicatLecture`, est un fragment statique du code, jamais une entrée) ; jeton de partage
    aléatoire (`random_bytes`), seul son SHA-256 est conservé (base et audit), jamais journalisé en clair ;
    toutes les routes du lot sont déclarées dans `Securite\DroitsRoutes` (une route absente y est interdite) ;
    un favori exige le droit **lire** (`niveauDossierPour`), une révocation par identifiant vérifie l'ACL
    (fail-closed) ; CSRF sur chaque POST ; Twig en échappement automatique, **aucun `|raw`**. **Aucun constat
    CRITIQUE/ÉLEVÉ.**
  - `silent-failure-hunter` : les `catch` ajoutés TRACENT (`error_log`) et retombent en sécurité
    (favori faux, consultation non enregistrée, refus ACL) ; les gestionnaires de routes rendent une erreur
    nommée (étape, cause), jamais un 500 muet. **Aucun `catch` muet ajouté.**
- **TDD** : les oracles ont été rejoués rouges avant le code, puis verts, sans affaiblir les assertions.

## Limites / propositions au juge

- Aucune exclusion posée par l'agent : les autres éléments M-Files restent `absent` (29) et sont proposés au
  juge. Le lot ne touche pas aux familles aperçu / vignette / titre / historique / commentaires / check-out.
- `recemmentaccessedbyme` est enregistré à l'ouverture d'une fiche RÉELLE ; un document hors droits n'apparaît
  jamais dans la liste (filtre ACL à la lecture).

## Harness `-SansBanc`

Rejoué deux fois à la fin. Premier rejeu : la **sonde de sécurité** a signalé une charge CSRF manquante pour
`POST /liens-partages/cle/{jeton}/revoquer` → corrigée dans `tools/banc/sonde-securite.php`, sonde rejouée
VERT. Second rejeu (commit `0b2f917`) :

| Couche | Verdict | Détail |
| --- | --- | --- |
| 0 garde-fous | VERT | vendor/node_modules ; `DB_NAME=gedv2_test` imposé |
| 1 unitaire | ROUGE | 970 tests ; 25 échecs **hors secteur** (moteur PDF/Python externe, réseau Infomaniak) |
| 2 contrat | VERT | toutes les routes déclarées sont couvertes |
| 3 banc | ROUGE | sautée (`-SansBanc`) |
| 4 écrans | ROUGE | 3 échecs **hors secteur** (ingestion Pilote, PDF/A) ; `espace-perso.spec.ts` **2/2 vert** |
| 5 personas | VERT | 6 personas, tous les gestes trouvés |
| 6 charge | ROUGE | `proc_open()` refusé par l'environnement ; mesures écrites, 0 erreur |
| 7 sécurité | VERT | sonde : aucun contournement accepté |
| 8 statique | VERT | PHPStan niveau 6 : 0 erreur hors baseline ; `composer audit` : aucune vulnérabilité |
| 9 parité Paperless | ROUGE | dépôt de référence `C:\ged\ref\paperless-ngx` absent de ce poste |
| 9b parité M-Files | VERT | 112 éléments, 66 couverts, 0 non-prouvé |

Les couches rouges visent des secteurs **non touchés** par ce lot et sont rouges **à l'identique sur la base
`dd241cc`** (vérifié en worktree : `EditeurPdfTest`, `InfomaniakClientTest`, PDF/A, ingestion Pilote échouent
déjà avant le lot, faute de moteur PDF/Python et de réseau sur ce poste). Le lot n'introduit donc **aucune
régression** ; toutes les preuves du lot sont vertes (unitaires, vecteurs de parité, e2e, phpstan, sonde).

ETAT: fini
