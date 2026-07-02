# Passe fonctions UI — saisie écran + Playwright + personas

> Complète `tests/visual/FUNCTIONS-SPEC.md` (contrat fonctionnel) avec les **procédures
> de saisie écran**, le **mapping Playwright** et la **discipline de correction par lot**.
>
> Registre machine : `tests/visual/specs/helpers/functions-registry.ts`
> Couverture : **29/35** dans le registry (2026-07-01) ; ~38 entrées dans FUNCTIONS-SPEC.

## Discipline d'exécution

1. **Prérequis** : MariaDB 3307, serveur `127.0.0.1:8765`, `php tools/eval-full.php --no-ocr`.
2. **Un lot à la fois** — ordre A → G → B → C → D → E → F (voir ci-dessous).
3. **Gate lot** : Playwright du lot **100 % vert** avant le lot suivant.
4. **Correction** : rouge → corriger le code (pas le test sauf oracle faux) → re-lancer le lot → capture `tests/visual/shots/`.
5. **Validation humaine** : revue screenshots + une saisie manuelle par fonction critique du lot.
6. **Harness complet** : `run-harness.bat` uniquement quand tous les lots actifs sont verts.

### Commandes par lot

```cmd
cd F:\DATA\DEVELOPPEMENT\GEDv1
run-passe-lot-a.bat
run-passe-lot-b.bat
run-passe-lot-c.bat
```

| Lot | Fichier spec | Fonctions ciblées |
|-----|--------------|-------------------|
| **A — ECM** | `persona-parcours-ecm.spec.ts` + `pipeline-ui.spec.ts` | F-IMP-01, F-LIB-02/03, F-DOC-01/02, F-SEARCH-01 |
| **G — Personas** | `persona*.spec.ts`, `workflow-doc-identification.spec.ts` | F-AUTH, F-VAL, F-DOC-04, F-REDX-TYPES |
| **B — Bibliothèque** | `lib-operations.spec.ts` | F-LIB-04..08 |
| **C — Fiche doc** | `fiche-document.spec.ts` | F-DOC-03..09 |
| **D — Recherche / tâches** | *(à créer)* | F-SEARCH-02/03, F-TASK-*, F-IMP-02 |
| **E — Admin** | *(à créer `admin-hub.spec.ts`)* | F-ADM-* |
| **F — Chrome / a11y** | `chrome-coherence.spec.ts`, `a11y.spec.ts` | F-CHROME-*, F-A11Y-* |

---

## Lot A — Parcours ECM (priorité immédiate)

Persona : **`eval_redx_expert`** (identification documentaire, sans WinBiz).

| Étape | ID | Saisie écran (procédure manuelle) | Oracle | Playwright |
|-------|-----|-----------------------------------|--------|------------|
| 1 Ingérer | F-IMP-01 | Menu **Importer** → `/documents/upload` → choisir PDF → titre → type Facture → **Uploader** | Redirect `/documents?success=1` ; doc visible en recherche | `persona-parcours-ecm.spec.ts` |
| 2 Ouvrir | F-LIB-02 | Bibliothèque → clic carte ou `?open={id}` | Modale preview sans erreur PHP | idem |
| 3 Classer | F-DOC-01 | Fiche → liste **Type** → Contrat → icône **Enregistrer** | `GET /api/documents/{id}` → `document_type_id` = Contrat | idem |
| 4 Analyser | F-DOC-02 | Fiche → **Suggestion : analyser** | `POST classify-ai` répond (200 ou 400 provider) | idem (skip IA si Infomaniak lent) |
| 5 Retrouver | F-SEARCH-01 | Barre recherche → titre du doc → Entrée | Carte visible | idem |

**Correction livrée (Lot A)** : `DocumentsController::upload` utilisait `document_type` au lieu de `document_type_id` — type ignoré à l'upload formulaire.

---

## Lot G — Personas & validation (existant)

| ID | Saisie écran | Spec |
|----|--------------|------|
| F-AUTH-01 | Login persona eval_* (mot de passe vide) | `persona.spec.ts` |
| F-VAL-01 | API `/api/validation/{id}/can-validate` facture 6000 CHF | `persona.spec.ts` |
| F-DOC-04 | Fiche → badge validation (disabled si rôle) | `persona-preview.spec.ts` |
| F-REDX-TYPES | Types ECM listés API + fiche expert | `persona-redx-expert.spec.ts` |
| F-DOC-01 UI | Type Contrat + save | `workflow-doc-identification.spec.ts` |

---

## Fonctions non couvertes (backlog par lot)

### Lot B — Bibliothèque

| ID | Saisie écran attendue | Oracle |
|----|----------------------|--------|
| F-LIB-04 | Dossier sélectionné → **Indexer ce dossier** | Toast/indexed count |
| F-LIB-05 | Clic droit / menu → Renommer → nouveau nom | Chemins docs mis à jour |
| F-LIB-06 | Menu → Déplacer → dossier cible | Chemins mis à jour |
| F-LIB-07 | Menu → Supprimer → confirmer | Corbeille |
| F-LIB-08 | `#sort-select`, boutons Grille/Liste | Ordre / vue changés |

### Lot C — Fiche document

| ID | Saisie écran | Oracle |
|----|--------------|--------|
| F-DOC-03 | **Retraiter (OCR)** | `content` rafraîchi |
| F-DOC-05 | **Soumettre** validation | `validation_status=pending` |
| F-DOC-06 | **Télécharger** | Fichier téléchargé |
| F-DOC-07 | **Supprimer** → confirmer | Doc en corbeille |
| F-DOC-08 | Onglets Détails/Notes/Contenu/Info/Historique/Versions | Panneau visible |
| F-DOC-09 | Onglet Notes → ajouter / supprimer | Note persistée |

### Lot D — Recherche & tâches

| ID | Saisie écran | Oracle |
|----|--------------|--------|
| F-SEARCH-02 | `/search` + filtres | Résultats filtrés |
| F-SEARCH-03 | UI sémantique (si Qdrant on) | Résultats vectoriels |
| F-TASK-01 | `/mes-taches` | Liste tâches assignées |
| F-TASK-02 | Action valider/rejeter depuis tâche | Statut changé |
| F-IMP-02 | `/admin/consume` | Scan consume |

### Lot E — Admin

| ID | Saisie écran | Oracle |
|----|--------------|--------|
| F-ADM-01 | `/admin` tuiles | Hub rendu |
| F-ADM-02 | CRUD tags/types/users… | Liste éditée |
| F-ADM-03 | Règles attribution | CRUD + test |
| F-ADM-04 | Diagnostic | Connecteurs health |
| F-ADM-05 | Indexation admin | Workers status |

---

## Mapping persona → fonctions à tester

| Persona | Doit exécuter (lots) | Doit être bloqué |
|---------|----------------------|------------------|
| `eval_redx_expert` | A (ECM complet), G (types + validation facture) | F-ADM-*, WinBiz |
| `eval_secretaire` | G, B, C (lecture), D (recherche) | F-DOC-04 facture 6000, F-ADM-* |
| `eval_comptable` | G, C sur factures ≤5000 | F-DOC-04 >5000, scope RH |
| `eval_rh` | G, C sur RH | F-DOC-04 facture |
| `eval_employeur` | G (validation sans plafond), C, D | F-ADM-* |

Les fonctions `[aucune]` (upload, classify, recherche) sont identiques pour tous → **Lot A une fois**, puis vérification rôle dans Lot G.

---

## Prochaine itération

1. ~~Valider **Lot A** (`run-passe-lot-a.bat`)~~ — **VERT**
2. ~~Écrire **`lib-operations.spec.ts`** (Lot B)~~ — **5/5 VERT**
3. ~~Écrire **`fiche-document.spec.ts`** (Lot C)~~ — **6/6 VERT** (`run-passe-lot-c.bat`)
4. **Lot D** — `search-tasks.spec.ts` (F-SEARCH-02/03, F-TASK-01/02, F-IMP-02)
5. **Lot E** — oracles admin (F-ADM-01 hub, F-ADM-02/03/05)
6. Étendre **`functions-registry.ts`** au fil des lots (F-AUTH-02, F-VAL-02/03, F-CHROME-03..07, F-A11Y-02..05)
