# K-Docs — Spec fonctionnelle d'interface (référentiel de test)

> Source de vérité pour les tests Playwright (persona + pipeline + a11y).
> Croisement de `docs/FUNCTIONS-INDEX.md` (méthodes PHP), `docs/ORACLES-KDOCS-PRODUCT.md`
> (shell/chrome) et `index.php` (routes déclarées).
>
> Périmètre : **fonctions exposées dans l'interface utilisateur** (pas l'inventaire PHP complet).
> Dernière mise à jour : 2026-06-29.

## Conventions

- **Rôle** : `[aucune]` rôle-agnostique | `[scope]` dépend du type de doc | `[plafond]` dépend du montant | `[admin]` admin requis | `[assigné]` tâche assignée à l'utilisateur.
- **Oracle** : état observable attendu (UI ou API) avant/après.
- **Invariants d'affichage** : règles de cohérence/lisibilité à vérifier (couche 3).
- Les sélecteurs UI sont stables (id/class) — base du mapping Playwright.

---

## 1. Authentification

| ID | Fonction | UI | API | Rôle | Oracle | Invariants affichage |
|----|----------|----|-----|------|--------|----------------------|
| F-AUTH-01 | Login | `#username`, `#password`, `button[type=submit]` | `POST /login` | [aucune] | Succès → redirect `/documents` ; échec → reste `/login` | Bannière « mot de passe faible » si hash vide |
| F-AUTH-02 | Logout | lien header | `GET /logout` | [aucune] | Session détruite → `/login` | — |

---

## 2. Bibliothèque (`/documents`)

| ID | Fonction | UI | API | Rôle | Oracle | Invariants affichage |
|----|----------|----|-----|------|--------|----------------------|
| F-LIB-01 | Parcourir arborescence | `.folder-link[data-path]` (sidebar) | `GET /documents?path=…` + AJAX `GET /api/folders/documents` | [aucune] | Cartes docs chargées (DB + physiques fusionnés) | Racine libellé **vide** + `aria-label="Racine du stockage"` ; dossiers internes (consume, toclassify…) masqués |
| F-LIB-02 | Ouvrir fiche | clic `.document-card` / `?open={id}` | `GET /api/documents/{id}` | [aucune] | Modale rendue avec champs métadonnées | Pas de marqueur d'erreur PHP dans la modale |
| F-LIB-03 | Upload (drag-drop) | dropzone (handleFileDrop) | `POST /api/documents/upload` (multipart `files[]`, `folder`) | [aucune] | Doc créé + OCR lancé + `id` retourné | **BUG connu** : `relative_path` non renseigné → doc absent du dossier cible (cf. F-CHROME-05) |
| F-LIB-04 | Indexer dossier | bouton « Indexer ce dossier » | `POST /api/folders/index` | [aucune] | `indexed` count retourné ; polling statut | Badge `.indexing` pendant le traitement |
| F-LIB-05 | Renommer dossier | modale « Renommer » | `POST /api/folders/rename` | [aucune] | Chemins docs mis à jour | — |
| F-LIB-06 | Déplacer dossier | modale « Déplacer » | `POST /api/folders/move` | [aucune] | Chemins docs mis à jour | — |
| F-LIB-07 | Supprimer dossier | modale « Supprimer » | `POST /api/folders/delete` | [aucune] | Dossier + contenu → corbeille (réversible) | Confirmation requise |
| F-LIB-08 | Tri / vue | `#sort-select`, boutons Grille/Liste | `GET /documents?sort=…` | [aucune] | Ordre changé | — |

---

## 3. Fiche document (modale)

| ID | Fonction | UI | API | Rôle | Oracle | Invariants affichage |
|----|----------|----|-----|------|--------|----------------------|
| F-DOC-01 | Édition métadonnées | `#preview-title-input`, `#preview-type-select`, `#preview-correspondent-select`, `#preview-date-input`, `#preview-amount-input`, `input[name=preview_tags]` | `PUT /api/documents/{id}` | [aucune] | Champs persistés ; `document_type_id` non null après save | Champs modifiés `.ds-field-changed` |
| F-DOC-02 | Suggestion IA | `#ai-suggest-btn` (« Suggestion : analyser ») | `POST /api/documents/{id}/classify-ai` | [aucune] | Si provider IA dispo → champs remplis ; sinon HTTP 400 (route vivante) | Bouton spinner « Analyse… » pendant l'appel |
| F-DOC-03 | Retraiter OCR | bouton « Retraiter (OCR) » | `POST /api/documents/{id}/ocr` | [aucune] | `content` rafraîchi | — |
| F-DOC-04 | Toggle validation | bouton `toggleValidationStatus` | `POST /api/validation/{id}/validate` | **[plaford]+[scope]** | Statut `approved`/`rejected`/`na` | Bouton désactivé si `canValidate=false` (couleur neutre) |
| F-DOC-05 | Soumettre validation | action « Soumettre » | `POST /api/validation/{id}/submit` | [aucune] | `validation_status=pending` + deadline | Badge « À traiter » |
| F-DOC-06 | Télécharger | lien « Télécharger » | `GET /documents/{id}/download` | [aucune] | Fichier envoyé | — |
| F-DOC-07 | Supprimer doc | bouton « Supprimer » | `POST /documents/{id}/delete` | [aucune] | Doc → corbeille | Confirmation requise |
| F-DOC-08 | Onglets fiche | `#preview-tabs button` (Détails/Notes/Contenu/Info/Historique) | — | [aucune] | Panneau correspondant visible | Onglet **Versions** présent **ssi** `PluginRegistry::isEnabled('smq')` |
| F-DOC-09 | Notes | onglet Notes | `POST/DELETE /api/documents/{id}/notes[/{noteId}]` | [aucune] | Note ajoutée/supprimée | — |
| F-DOC-10 | Versions (SMQ) | onglet Versions | `GET /api/documents/{id}/versions` | [aucune] | Liste versions ; restore/diff/download | Gated SMQ — absent si SMQ désactivé |
| F-DOC-11 | Lecture (C.3) | bloc onglet Versions | `POST …/versions/{n}/read`, `GET …/read-status` | [assigné] | Quittance 1/doc+version+user | Gated SMQ |

---

## 4. Recherche

| ID | Fonction | UI | API | Rôle | Oracle | Invariants affichage |
|----|----------|----|-----|------|--------|----------------------|
| F-SEARCH-01 | Recherche simple | `#search-input` + Enter | `GET /documents?search=…` (SSR `SearchService`) | [aucune] | Résultats fulltext ; fallback sémantique si < 3 hits + embeddings dispo | Badge « sémantique » si fallback |
| F-SEARCH-02 | Recherche avancée | page `/search`, filtres date/scope | `SearchController` | [aucune] | Facets correspondants/types | Aide syntaxe (`#syntax-help-popup`) |
| F-SEARCH-03 | Sémantique / hybride | (UI gated Qdrant) | `POST /api/semantic-search/search`, `/api/embeddings/hybrid-search` | [aucune] | Résultats vectoriels | UI masquée si `qdrant.enabled=false` |

---

## 5. À traiter (`/mes-taches`)

| ID | Fonction | UI | API | Rôle | Oracle | Invariants affichage |
|----|----------|----|-----|------|--------|----------------------|
| F-TASK-01 | Liste tâches | page `/mes-taches` | `GET /api/my-tasks` | [assigné] | Tâches assignées à l'utilisateur | Badge sidebar `pending`/`needs_review` |
| F-TASK-02 | Valider depuis tâche | action tâche | `POST /api/validation/{id}/approve\|reject` | **[plaford]+[scope]** | Statut changé | — |

---

## 6. Importer

| ID | Fonction | UI | API | Rôle | Oracle | Invariants affichage |
|----|----------|----|-----|------|--------|----------------------|
| F-IMP-01 | Upload page | `/documents/upload` | `POST /api/documents/upload` | [aucune] | Doc créé + OCR | — |
| F-IMP-02 | Consume (admin) | `/admin/consume` | `POST /api/consume`, `/api/consume/batch` | [admin] | Dossier consume scanné + ingest | Lien admin-only |

---

## 7. Validation (transverse, rôle-dépendant)

| ID | Fonction | UI | API | Rôle | Oracle | Invariants affichage |
|----|----------|----|-----|------|--------|----------------------|
| F-VAL-01 | Droits de validation | (lié F-DOC-04) | `GET /api/validation/{id}/can-validate` | **[plaford]+[scope]** | `can_validate: bool` + `reason` | — |
| F-VAL-02 | File d'attente | page validation | `GET /api/validation/pending` | [assigné] | Docs pending pour l'utilisateur | — |
| F-VAL-03 | Approuver / Rejeter | actions | `POST /api/validation/{id}/approve\|reject` | **[plaford]+[scope]** | Statut + historique | Commentaire optionnel |

---

## 8. Admin hub (`/admin`)

| ID | Fonction | UI | API | Rôle | Oracle | Invariants affichage |
|----|----------|----|-----|------|--------|----------------------|
| F-ADM-01 | Hub | `/admin` (tuiles) | — | [admin] | Tuiles Paramètres/Tags/Workflows/Users/Diagnostic… | Hors sidebar user principale |
| F-ADM-02 | Référentiels | `/admin/{tags,types,correspondents,custom-fields,storage-paths,workflows,users,roles}` | CRUD dédiés | [admin] | Listes éditables | — |
| F-ADM-03 | Règles attribution | `/admin/attribution-rules` | `/api/attribution-rules/*` | [admin] | Règles CRUD + test + logs | — |
| F-ADM-04 | Diagnostic | `/admin/diagnostic` | `GET /api/admin/connectors/health` | [admin] | Statuts connecteurs/plugins | — |
| F-ADM-05 | Indexation | `/admin/indexing` | `/api/folders/crawl`, `/api/indexing/*` | [admin] | Statut/workers | — |

---

## 9. Chrome & cohérence (invariants transverses)

| ID | Invariant | Vérification |
|----|-----------|--------------|
| F-CHROME-01 | Sidebar user ≤ 5 entrées + lien Admin | Comptage liens sidebar user |
| F-CHROME-02 | Compteurs sidebar = dashboard = BDD | Même requête SQL `pending` + total (oracle §4 ORACLES-KDOCS-PRODUCT) |
| F-CHROME-03 | Racine = libellé vide + `aria-label="Racine du stockage"` | `FolderTreeHelper` |
| F-CHROME-04 | Dossiers internes masqués (consume, toclassify, processed…) | `InternalFolderRegistry::hiddenNames()` |
| F-CHROME-05 | Indicateur « Synchronisation nécessaire » ⇔ `fileCount != dbCount` | `FolderTreeHelper:170` ; **échoue tant que F-LIB-03 (relative_path) n'est pas corrigé** |
| F-CHROME-06 | Pas d'emoji chrome (SVG uniquement) | Scan libellés/boutons |
| F-CHROME-07 | Bannière sécurité root visible ssi `APP_DEBUG=true` | — |
| F-CHROME-08 | Docs `test_*` masqués hors debug | `documentVisibilitySql` |

---

## 10. Lisibilité / accessibilité (couche 3, par persona)

Vérifiés via `@axe-core/playwright` sur chaque vue consultée par le persona.

| ID | Contrôle | Règle | Portée |
|----|----------|-------|--------|
| F-A11Y-01 | Contraste texte/fond | WCAG `color-contrast` (AA) | Chrome, badges, liens, champs |
| F-A11Y-02 | Nom accessible des interactifs | `button-name`, `link-name` | Tous boutons/liens |
| F-A11Y-03 | États désactivés sémantiques | `aria-disabled`/`disabled` visible | Boutons validation (F-DOC-04) selon rôle |
| F-A11Y-04 | Focus clavier visible | `focus-order` / focus-visible | Shell + modale |
| F-A11Y-05 | Rôle + nom des icônes-boutons | `name-role-value` | Boutons SVG (save, close, validation) |

---

## Mapping persona → fonctions (couche 3)

| Persona | Fonctions attendues accessibles | Fonctions devant être désactivées/refusées |
|---------|----------------------------------|---------------------------------------------|
| `eval_secretaire` (VALIDATOR_L1 ≤1000, scope *) | F-LIB-*, F-DOC-01/02/03/06/09, F-SEARCH-*, F-TASK-01 | F-DOC-04 (facture 6000 > 1000), F-VAL-03, F-ADM-* |
| `eval_comptable` (VALIDATEUR_FACTURE ≤5000, scope FACTURE) | F-LIB-*, F-DOC-* sur factures ≤5000, F-SEARCH-*, F-TASK-01/02 | F-DOC-04 (facture 6000 > 5000), F-DOC-04 sur RH (scope), F-ADM-* |
| `eval_rh` (VALIDATOR_L1, scope RH) | F-LIB-*, F-DOC-* sur docs RH, F-SEARCH-*, F-TASK-01/02 | F-DOC-04 sur facture (scope), F-ADM-* |
| `eval_employeur` (APPROVER, scope *) | F-LIB-*, F-DOC-* (validation sans plafond), F-SEARCH-*, F-TASK-*, F-VAL-03 | F-ADM-* (sauf si admin ajouté) |

> Les fonctions `[aucune]` (ingestion, classification, IA, recherche) sont **identiques pour tous les personas**
> → couvertes par `pipeline-ui.spec.ts` (UI) + `eval-full.php` (CLI), pas par le persona.
> Le persona n'apporte de la couverture que sur les fonctions **rôle-dépendantes** (F-DOC-04, F-VAL-*, F-TASK-*, F-ADM-*).

---

## Couverture actuelle (état 2026-06-29, sprint finalisation)

| Spec | Où couverte | Statut |
|------|-------------|--------|
| F-AUTH-01/02 | `global-setup.ts`, `persona.spec.ts` (login par persona) | ✓ |
| F-LIB-01/02/08 | `shell.spec.ts`, `persona.spec.ts`, `pipeline-ui.spec.ts` | ✓ |
| F-LIB-03 | `pipeline-ui.spec.ts` (upload + rangement dossier) | ✓ (fix `relative_path` `bc6c075`) |
| F-LIB-04 | `eval-full.php` (CLI) | ✓ |
| F-DOC-01 | `pipeline-ui.spec.ts` (save type) | ✓ |
| F-DOC-02 | `pipeline-ui.spec.ts` (classify-ai) | ✓ route vivante |
| F-DOC-04 | `persona-preview.spec.ts` (bouton validation + `can_validate`) | ✓ |
| F-VAL-01 | `persona.spec.ts` (can-validate par persona) | ✓ |
| F-DOC-10 | `smq-versions.spec.ts` | ✓ (serveur dev avec `SMQ_APP_ENABLED=true`) |
| F-SEARCH-01 | `persona.spec.ts`, `pipeline-ui.spec.ts`, `eval-full.php` | ✓ |
| F-CHROME-01/03/04/05/06/07 | `chrome-coherence.spec.ts` | ✓ (06/07 instrumentalisés 2026-06-30) |
| F-CHROME-02 | `chrome-coherence.spec.ts` (fixme) | ✗ incohérence compteurs connue (alignement SQL = décision produit) |
| F-CHROME-08 | `chrome-coherence.spec.ts` (skip) | `documentVisibilitySql` non appliqué à `/api/folders/documents` = décision produit |
| F-A11Y-01..05 | `a11y.spec.ts` (axe-core, root + par persona) | ✓ (remédiation contraste `3e56023`) |

## Suite à implémenter

1. **F-CHROME-02** : aligner les requêtes SQL compteurs sidebar/dashboard/header (décision produit).
2. **F-CHROME-08** : étendre `documentVisibilitySql` à `/api/folders/documents` ou tester via dashboard (décision produit).
3. **F-DOC-04 UI gating** : le bouton validation est rendu pour tous ; le gating est serveur-side. Pour un disabled visible par rôle, ajouter `:disabled` quand `can_validate=false`.
