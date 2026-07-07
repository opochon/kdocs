# SPEC — K-ERP Connect : ventilation fractionnée, statuts, cycle de blocage

> **Statut** : approuvée (arbitrages Olivier 2026-07-07) — extension du flux K-ERP Connect
> livré 2026-07-06 (`apps/erpconnect/`, simulation E2E verte).
> **Dépôts** : `F:\DATA\DEVELOPPEMENT\GEDv1` (plugin GED) + `F:\DATA\DEVELOPPEMENT\K-TIME\k-time-web` (K-Time).
> **Complète** : `docs/WINBIZ-PLUGIN-REPOSITIONNE.md` (décision 2026-07-03) et
> `K-TIME/docs/SPEC-GED-INTEGRATION.md` (lots GED-1..4, tous livrés).
> **Contrat d'échange canonique** : ce document (section 4) fait foi pour les nouveaux endpoints.

---

## 0. Principe et frontière (rappel)

- **GED (K-ERP Connect)** = *comprendre et proposer*. Détecte la facture (CMD v4 si dispo),
  extrait les champs, identifie le fournisseur via K-Time, présente une **ventilation
  fractionnée par ligne**, capture les choix de l'utilisateur, **introduit** l'intention
  structurée dans K-Time, puis **affiche en lecture seule** le retour de statut et de cause.
- **K-Time** = *décider et opérer*. Possède la validation, la **validation partielle**, le
  rejet, le **cycle de blocage** (note de crédit / correction / blocage paiement avec cause),
  et **seul** parle à WinBiz. La GED n'écrit **jamais** dans WinBiz.

Décisions produit (2026-07-07) :

| Sujet | Décision |
|-------|----------|
| Statut partiel | **Enum explicite** `validation_status` étendu : `partially_validated`, `blocked` |
| Cycle de blocage | **K-Time exécute**, GED déclenche la demande initiale + affiche le statut/cause en miroir (pull) |
| Périmètre livré maintenant | **Slice vertical minimal** : ventilation fractionnée + actions a/b/c + introduction + statuts validé/partiel/invalidé + miroir blocage. Opérations WinBiz par allocation = **stub tracé** (lot suivant) |
| Proposition de split | **K-Time pré-remplit** la ventilation habituelle → GED propose → user ajuste |

---

## 1. Modèle de statuts (source de vérité = K-Time, miroir = GED)

`received_invoices.validation_status` ENUM étendu :

| Statut | Sens | `status` paiement K-Time | Miroir GED (`erp_links.validation_status`) |
|--------|------|--------------------------|--------------------------------------------|
| `pending` | Introduite, en attente de décision humaine | `draft` (hors paiement) | « En attente de validation » |
| `validated` | Bon pour accord **total** — toutes allocations confirmées | `a_payer` (entre au paiement) | « Validée — bon pour accord » |
| `partially_validated` | Une partie confirmée, le reste en suspens/contesté | `draft` (hors paiement tant que non résolu) | « Partiellement validée » |
| `rejected` | Refusée (note obligatoire) | `draft` (hors paiement) | « Invalidée » + note |
| `blocked` | Bloquée avec cause — cycle ouvert | `draft` (hors paiement jusqu'à résolution) | « Bloquée » + kind + cause |

**Invariant paiement** : seule `validated` fait passer `status` en `a_payer` (circuit paiement).
Le paiement du **montant partiel** d'une facture `partially_validated` est **hors périmètre de ce
slice** (lot suivant) — documenté, non implémenté.

**Transitions** (garde `source='ged'`) :

```
pending ─validate────────────→ validated       (status → a_payer)
pending ─partial-validate────→ partially_validated
pending ─reject (note)───────→ rejected
pending ─block (kind,cause)──→ blocked
partially_validated ─validate→ validated
partially_validated ─block───→ blocked
blocked ─resolve─────────────→ pending          (le cycle repart ; réévaluation humaine)
```

---

## 2. Ventilation fractionnée (allocations par ligne)

Une ligne de facture de quantité `X` peut être **éclatée** en plusieurs allocations dont la
somme des quantités = `X` (tolérance configurable). Le reliquat non couvert est implicitement
`non_attribue`.

`allocation_type` (vocabulaire aligné K-Time) :

| Type | Métier | Réf ERP typique (renseignée par K-Time, lot suivant) |
|------|--------|------------------------------------------------------|
| `stock` | Entrée / réservation stock | article WinBiz `AR_NUMERO` |
| `facture` | Refacturation / rattachement facture client | `DO_NODOC` facture vente |
| `fiche_travail` | Rattachement fiche de travail / bon de livraison | delivery note K-Time |
| `vente_comptant` | Vente au comptant | `invoices.kind='cash_sale'` |
| `recu_conteste` | Reçu / **contestation** d'une partie de la facture | alimente une demande `note_credit` |
| `non_attribue` | Suspens — décision ultérieure | — |

La **proposition** initiale est pré-remplie par la GED à partir de
`GET /api/ged/suppliers/{id}/ventilation` (ventilation habituelle du fournisseur par article) :
si un article de la facture correspond (par `product_id`, `supplier_ref` ou description) à un
article connu, sa ventilation dominante devient l'allocation proposée par défaut. L'utilisateur
ajuste (fractionne, change de type, conteste) avant introduction.

---

## 3. Cycle de blocage (K-Time)

`block_kind` ENUM :

| Kind | Sens | Action attendue côté facturation |
|------|------|----------------------------------|
| `note_credit` | Demande de note de crédit fournisseur (partie contestée) | Émettre / réclamer une note de crédit |
| `correction_facture` | Demande de correction de facture au fournisseur | Réclamer une facture corrigée |
| `blocage_paiement` | Blocage pur du paiement avec cause | Retenir le paiement jusqu'à levée |

- La **cause** (texte) est **obligatoire** à la création d'un blocage.
- Cycle : `open → resolved` (`resolution_note`). Tant qu'un blocage est `open`, la facture est
  `validation_status='blocked'`.
- **GED déclenche** la demande initiale (bouton « Demander un blocage avec cause »). **K-Time
  exécute** le cycle et sa résolution (UI K-Time). La GED affiche kind + cause + statut en pull.
- Le détail opérationnel du cycle (relances, échéances, lien note de crédit ↔ facture) est
  **spécifié ici mais implémenté dans un lot ultérieur** ; ce slice pose la demande, le statut
  `blocked`, la cause et l'affichage miroir.

---

## 4. Contrat d'interface GED ↔ K-Time (endpoints)

Auth inchangée : header `X-Api-Key: <ged_api_key>`. Base `KTIME_URL`.
Endpoints **existants** (lots GED-1..4) inchangés sauf mention.

### 4.1 `GET /api/ged/suppliers/{id}/ventilation` — inchangé (consommé pour pré-remplir)

Réponse (rappel) : `{ ok, supplier_id, articles:[{product_id, code, name, supplier_ref, frequency, avg_price, ventilation, ventilations[]}], ventilation_types[] }`.

### 4.2 `POST /api/ged/received-invoices` — **étendu** (allocations par ligne)

Payload : identique à GED-2, chaque `lines[]` accepte désormais un tableau `allocations` :

```jsonc
{
  "external_ref": "ged:doc:900223",
  "supplier": { "id": 5079 },
  "supplier_ref": "F-2026-889",
  "invoice_date": "2026-07-01",
  "total_ht": 1000.0, "total_tva": 81.0, "total_ttc": 1081.0,
  "currency": "CHF",
  "lines": [
    {
      "description": "Vis 40mm", "qty": 15, "unit_price": 4.5, "tva_rate": 8.1,
      "product_id": 7, "supplier_article_code": "F-889",
      "allocations": [
        { "type": "stock",         "qty": 5 },
        { "type": "facture",       "qty": 5, "erp_ref_type": "client_invoice", "erp_ref_id": "1042", "erp_ref_label": "Facture 1042" },
        { "type": "fiche_travail", "qty": 3 },
        { "type": "recu_conteste", "qty": 2 }
      ]
    }
  ]
}
```

- Allocations **optionnelles** (rétro-compatible : sans `allocations`, comportement GED-2).
- K-Time persiste dans `received_invoice_allocations` (une ligne par allocation).
- Idempotence inchangée (UNIQUE `source, external_ref`) ; sur ré-introduction d'un doublon,
  les allocations existantes sont **remplacées** (DELETE + INSERT) dans la même transaction.
- Réponse : `{ ok, id, status:'draft', validation_status:'pending', duplicate:bool, allocations_count:int }`.

### 4.3 `POST /api/ged/received-invoices/{id}/block` — **nouveau** (GED-triggerable)

Body : `{ "kind": "note_credit|correction_facture|blocage_paiement", "cause": "texte obligatoire" }`.
Effet : crée un `received_invoice_blocks (status='open')`, passe `validation_status='blocked'`,
audit `block`. Erreur `422 ged_block_cause_required` si cause vide ; `404 ged_invoice_unknown`.
Réponse : `{ ok, id, validation_status:'blocked', block:{id, kind, cause, status:'open', created_at} }`.

### 4.4 `POST /api/ged/received-invoices/{id}/partial-validate` — **nouveau**

Body : `{ "confirmed_allocation_ids": [12,13], "note": "optionnel" }`.
Effet : marque les allocations confirmées (`status='confirmed'`), passe
`validation_status='partially_validated'`, **ne change pas** `status` paiement, audit
`partial_validate`. Réponse : `{ ok, id, validation_status:'partially_validated', confirmed:int, pending:int }`.

### 4.5 `POST …/validate` et `POST …/reject` — existants, inchangés

`validate` : toutes allocations → `confirmed`, `validation_status='validated'`, `status='a_payer'`.
`reject` : `validation_status='rejected'`, note obligatoire.

### 4.6 `GET /api/ged/received-invoices/{id}` — **enrichi**

Réponse étendue (rétro-compatible, champs ajoutés) :

```jsonc
{
  "ok": true, "id": 512, "external_ref": "ged:doc:900223",
  "status": "draft", "validation_status": "blocked",
  "validated_by": { "id": 3, "name": "Olivier P." } | null,
  "validated_at": "2026-07-07 10:12:00" | null,
  "source": "ged",
  "block": { "id": 9, "kind": "note_credit", "cause": "Prix ligne 1 erroné", "status": "open", "created_at": "..." } | null,
  "allocations": [
    { "id": 12, "line_id": 88, "type": "stock", "qty": 5, "status": "confirmed", "erp_ref_label": null },
    { "id": 13, "line_id": 88, "type": "recu_conteste", "qty": 2, "status": "pending", "erp_ref_label": null }
  ],
  "allocations_summary": { "total": 4, "confirmed": 2, "pending": 2 }
}
```

---

## 5. Schémas de données

### 5.1 K-Time — migration `088_ged_ventilation.sql`

```sql
USE k_time;

-- Statut de validation étendu
ALTER TABLE received_invoices
  MODIFY COLUMN validation_status
    ENUM('pending','validated','partially_validated','rejected','blocked') NULL;

-- Allocations fractionnées par ligne de facture reçue
CREATE TABLE IF NOT EXISTS received_invoice_allocations (
  id                      INT AUTO_INCREMENT PRIMARY KEY,
  received_invoice_line_id INT NOT NULL,
  received_invoice_id      INT NOT NULL,
  qty                     DECIMAL(12,3) NOT NULL,
  allocation_type         ENUM('stock','facture','fiche_travail','vente_comptant','recu_conteste','non_attribue') NOT NULL,
  erp_ref_type            VARCHAR(50)  NULL,
  erp_ref_id              VARCHAR(100) NULL,
  erp_ref_label           VARCHAR(255) NULL,
  status                  ENUM('pending','confirmed','rejected') NOT NULL DEFAULT 'pending',
  wb_operation_status     ENUM('none','pending','done','error') NOT NULL DEFAULT 'none', -- lot WinBiz
  metadata_json           JSON NULL,
  created_at              TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at              TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_ria_invoice (received_invoice_id),
  KEY idx_ria_line (received_invoice_line_id),
  KEY idx_ria_status (status),
  CONSTRAINT fk_ria_line FOREIGN KEY (received_invoice_line_id)
    REFERENCES received_invoice_lines(id) ON DELETE CASCADE,
  CONSTRAINT fk_ria_inv FOREIGN KEY (received_invoice_id)
    REFERENCES received_invoices(id) ON DELETE CASCADE
);

-- Cycle de blocage
CREATE TABLE IF NOT EXISTS received_invoice_blocks (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  received_invoice_id INT NOT NULL,
  kind                ENUM('note_credit','correction_facture','blocage_paiement') NOT NULL,
  cause               VARCHAR(1000) NOT NULL,
  status              ENUM('open','resolved') NOT NULL DEFAULT 'open',
  created_by          INT NULL,
  created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  resolved_by         INT NULL,
  resolved_at         DATETIME NULL,
  resolution_note     VARCHAR(1000) NULL,
  KEY idx_rib_invoice (received_invoice_id),
  KEY idx_rib_status (status),
  CONSTRAINT fk_rib_inv FOREIGN KEY (received_invoice_id)
    REFERENCES received_invoices(id) ON DELETE CASCADE
);

-- audit_log.action : + 'partial_validate', 'block', 'unblock'
-- (rejoue l'ENUM complet des migrations 080/082 + ces 3 valeurs)
```

### 5.2 GED — migration `add_invoice_line_allocations.php`

```sql
CREATE TABLE IF NOT EXISTS invoice_line_allocations (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  line_item_id    INT NOT NULL,
  document_id     INT NOT NULL,
  quantity        DECIMAL(12,3) NOT NULL,
  allocation_type ENUM('stock','facture','fiche_travail','vente_comptant','recu_conteste','non_attribue') NOT NULL,
  erp_ref_type    VARCHAR(50)  NULL,
  erp_ref_id      VARCHAR(100) NULL,
  erp_ref_label   VARCHAR(255) NULL,
  status          ENUM('proposed','confirmed','rejected') NOT NULL DEFAULT 'proposed',
  confidence      DECIMAL(5,2) NULL,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_ila_line (line_item_id),
  KEY idx_ila_doc (document_id),
  CONSTRAINT fk_ila_line FOREIGN KEY (line_item_id)
    REFERENCES invoice_line_items(id) ON DELETE CASCADE
);
```

Miroir statut/blocage : colonnes `validation_status`, `block_kind`, `block_cause` ajoutées à la
table `erp_links` (migration GED `add_erp_links_table` étendue ou migration additive).

---

## 6. Comportement du plugin GED

`ErpConnectService` :
- `buildProposal(documentId)` : après lookup fournisseur + ventilation, construit pour chaque
  ligne une **proposition d'allocation** (type dominant K-Time, qty = qty ligne). Persiste
  `invoice_line_allocations` en `status='proposed'`.
- `submitToKTime(documentId, userChoices)` : lit les allocations ajustées, valide la somme par
  ligne (= qty, tolérance), construit le payload §4.2 (`lines[].allocations[]`), appelle
  `KTimeClient::createReceivedInvoice`, upsert `erp_links`.
- `requestBlock(documentId, kind, cause)` : appelle `KTimeClient::blockReceivedInvoice`, met à
  jour `erp_links.validation_status='blocked'`, `block_kind`, `block_cause`.
- `refreshStatus(documentId)` : `GET {id}` enrichi → met à jour `erp_links.validation_status`
  (dont `partially_validated`/`blocked`), `block_kind`, `block_cause`. `bon_pour_accord` = true
  ssi `validation_status='validated'`.

`KTimeClient` : + `blockReceivedInvoice(id, kind, cause)`, + `partialValidate(id, ids, note)`
(pour tests/complétude), `getReceivedInvoice` parse les champs enrichis.

## 7. UI panneau (`apps/erpconnect/templates/panel.php`)

Par ligne : grille de fractionnement (une sous-ligne par allocation : type + quantité), un
indicateur « somme = qté » (vert/rouge), boutons + / − pour ajouter/retirer une allocation,
sélecteur de type (stock / facture / fiche de travail / vente comptant / reçu-contestation).
Reliquat non couvert affiché comme `non_attribue`. Actions globales : « Introduire dans K-Time »,
« Demander un blocage » (choix kind + cause). Bandeau statut lecture seule : validé /
partiellement validé / invalidé / **bloqué (kind + cause)**, bouton « Actualiser le statut ».
Onglet lié dans la modale document GED.

## 8. WinBiz (hors slice — tracé)

À la validation (`validated`) ou à la confirmation d'une allocation, K-Time générera les
opérations WinBiz correspondantes (mouvement stock, rattachement facture/fiche) via
`WbLink` + le bridge. **Non implémenté dans ce slice** : `received_invoice_allocations.wb_operation_status`
reste `'none'`. Spécifié pour le lot suivant.

---

## 9. Lots d'implémentation et gates

| Lot | Dépôt | Contenu | Gate |
|-----|-------|---------|------|
| **V1** K-Time schéma | K-Time | migration 084 (enum + 2 tables) + modèles | migrate + smoke |
| **V2** K-Time endpoints | K-Time | create+allocations, block, partial-validate, show enrichi | tests PHP endpoints |
| **V3** GED schéma+service | GED | migration allocations + erp_links étendu + service/client | `ErpConnectTest` étendu |
| **V4** GED UI | GED | panneau fractionné + actions + miroir statut/blocage | rendu + spec structurel |
| **V5** E2E | 2 | `erp-connect.spec.ts` (fractionnement + partiel + blocage) | `run-erp-simulation.bat` VERT |

**Non-objectifs de ce slice** : paiement du montant partiel ; opérations WinBiz par allocation ;
UI complète du cycle de blocage côté K-Time (relances/échéances) — tous en lot ultérieur.

---

*Créé 2026-07-07 — spec ventilation fractionnée + statuts + cycle de blocage K-ERP Connect.*
