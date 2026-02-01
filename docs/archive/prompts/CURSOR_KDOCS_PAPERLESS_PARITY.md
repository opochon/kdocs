# K-Docs - Audit Complet et Mise à Niveau Paperless-ngx

## 🎯 OBJECTIF GLOBAL

Atteindre une parité complète avec Paperless-ngx en corrigeant les bugs existants et en ajoutant les fonctionnalités manquantes.

**Référence officielle** : https://docs.paperless-ngx.com/usage/

---

## 🐛 BUGS À CORRIGER EN PRIORITÉ

### Bug 1 : Code HTML visible dans la barre de recherche

**Fichier** : `templates/documents/index.php` (ligne ~90)

**Problème** : Les attributs HTML sont mal placés :
```php
<input type="text" ... placeholder="Rechercher... (Ctrl+K ou /)" class="...">
                            title="Raccourci: Ctrl+K ou /"
                            onkeydown="if(event.key === 'Enter') this.form.submit()">
```

**Correction** : Déplacer les attributs AVANT le `>` de fermeture :
```php
<input type="text" 
       id="search-input"
       name="search"
       value="<?= htmlspecialchars($search ?? '') ?>"
       placeholder="Rechercher... (Ctrl+K ou /)" 
       class="pl-10 pr-4 py-2 border rounded-lg w-64 focus:outline-none focus:ring-2 focus:ring-blue-500"
       title="Raccourci: Ctrl+K ou /"
       onkeydown="if(event.key === 'Enter') this.form.submit()">
```

### Bug 2 : Style CSS cassé dans la section grille

**Fichier** : `templates/documents/index.php` (vers ligne 450)

**Problème** : Accolade orpheline dans le CSS :
```css
<style>
    /* Grille adaptative pour les miniatures */
    /* Styles déplacés dans la section <style> principale */
    }   <!-- CETTE ACCOLADE EST ORPHELINE -->
```

**Correction** : Supprimer l'accolade orpheline.

### Bug 3 : `Database` non importé dans WorkflowsController

**Fichier** : `app/Controllers/WorkflowsController.php`

**Problème** : `Database::getInstance()` utilisé sans import.

**Correction** : Ajouter en haut du fichier :
```php
use KDocs\Core\Database;
```

---

## 📋 FONCTIONNALITÉS MANQUANTES

### 1. TAGS - Matching Algorithms (PRIORITÉ HAUTE)

**Paperless-ngx** offre 6 algorithmes de matching pour tags, correspondents, et types :
1. **None** - Pas de matching automatique
2. **Any** - Match si UN des mots est trouvé
3. **All** - Match si TOUS les mots sont trouvés
4. **Exact** - Match exact de la chaîne
5. **Regex** - Expression régulière
6. **Fuzzy** - Matching approximatif
7. **Auto** - Machine learning (neural network)

**kdocs actuel** : Champ `match` simple sans algorithme.

**À implémenter** dans `templates/admin/tag_form.php` :
```php
<div class="grid grid-cols-2 gap-4">
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Match</label>
        <input type="text" name="match" value="<?= htmlspecialchars($tag['match'] ?? '') ?>"
               class="w-full px-3 py-2 border rounded-lg" 
               placeholder="Texte à rechercher">
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Algorithme</label>
        <select name="matching_algorithm" class="w-full px-3 py-2 border rounded-lg">
            <option value="0" <?= ($tag['matching_algorithm'] ?? 0) == 0 ? 'selected' : '' ?>>Aucun</option>
            <option value="1" <?= ($tag['matching_algorithm'] ?? 0) == 1 ? 'selected' : '' ?>>N'importe lequel</option>
            <option value="2" <?= ($tag['matching_algorithm'] ?? 0) == 2 ? 'selected' : '' ?>>Tous</option>
            <option value="3" <?= ($tag['matching_algorithm'] ?? 0) == 3 ? 'selected' : '' ?>>Exact</option>
            <option value="4" <?= ($tag['matching_algorithm'] ?? 0) == 4 ? 'selected' : '' ?>>Regex</option>
            <option value="5" <?= ($tag['matching_algorithm'] ?? 0) == 5 ? 'selected' : '' ?>>Fuzzy</option>
            <option value="6" <?= ($tag['matching_algorithm'] ?? 0) == 6 ? 'selected' : '' ?>>Auto (ML)</option>
        </select>
    </div>
</div>
<div class="mt-2">
    <label class="flex items-center">
        <input type="checkbox" name="is_insensitive" value="1" 
               <?= ($tag['is_insensitive'] ?? true) ? 'checked' : '' ?>>
        <span class="ml-2 text-sm">Insensible à la casse</span>
    </label>
</div>
```

**Migration SQL** :
```sql
ALTER TABLE tags 
    ADD COLUMN matching_algorithm TINYINT DEFAULT 0,
    ADD COLUMN is_insensitive BOOLEAN DEFAULT TRUE;

ALTER TABLE correspondents 
    ADD COLUMN match VARCHAR(255) DEFAULT NULL,
    ADD COLUMN matching_algorithm TINYINT DEFAULT 0,
    ADD COLUMN is_insensitive BOOLEAN DEFAULT TRUE;

ALTER TABLE document_types 
    ADD COLUMN match VARCHAR(255) DEFAULT NULL,
    ADD COLUMN matching_algorithm TINYINT DEFAULT 0,
    ADD COLUMN is_insensitive BOOLEAN DEFAULT TRUE;

ALTER TABLE storage_paths 
    ADD COLUMN match VARCHAR(255) DEFAULT NULL,
    ADD COLUMN matching_algorithm TINYINT DEFAULT 0,
    ADD COLUMN is_insensitive BOOLEAN DEFAULT TRUE;
```

---

### 2. TAGS - Hiérarchie (Tags Imbriqués)

**Paperless-ngx** : Tags hiérarchiques avec parent_id.

**kdocs** : Déjà implémenté ✅ (vérifier si fonctionnel)

---

### 3. TAGS - Inbox Tag

**Paperless-ngx** : Tag spécial "Inbox" qui marque les documents non traités.

**À ajouter** dans la table tags :
```sql
ALTER TABLE tags ADD COLUMN is_inbox_tag BOOLEAN DEFAULT FALSE;
```

**Interface** : Checkbox "Est un tag Inbox" dans le formulaire tag.

---

### 4. DOCUMENTS - Split View (Preview + Métadonnées)

**Paperless-ngx** : Vue split-screen avec preview PDF à gauche et métadonnées éditables à droite.

**kdocs actuel** : Déjà implémenté ✅ (vérifier la preview PDF.js)

---

### 5. DOCUMENTS - Notes

**Paperless-ngx** : Notes attachées aux documents avec timestamp et auteur.

**kdocs** : Déjà implémenté ✅ (model DocumentNote.php existe)

---

### 6. DOCUMENTS - Liens de Partage Public

**Paperless-ngx** : Génération de liens publics avec expiration optionnelle.

**À créer** :

**Migration** :
```sql
CREATE TABLE IF NOT EXISTS document_share_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_id INT NOT NULL,
    slug VARCHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME DEFAULT NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id)
);
```

**Controller** : `ShareLinksController.php`
**Route** : `/share/{slug}` (publique, sans auth)

---

### 7. RECHERCHE - Syntaxe Avancée

**Paperless-ngx** supporte :
- `tag:facture` - Recherche par tag
- `correspondent:swisscom` - Par correspondant
- `type:invoice` - Par type
- `created:[2024-01-01 TO 2024-12-31]` - Par date
- `"exact phrase"` - Phrase exacte
- `title:rapport` - Dans le titre uniquement
- `content:TVA` - Dans le contenu uniquement

**À implémenter** dans `app/Services/SearchParser.php` (améliorer l'existant).

---

### 8. RECHERCHE - Autocomplétion

**Paperless-ngx** : Suggestions de mots pendant la frappe.

**À ajouter** :
- Endpoint API `/api/search/autocomplete?q=xxx`
- Dropdown sous la barre de recherche avec suggestions

---

### 9. RECHERCHE - "More Like This"

**Paperless-ngx** : Bouton pour trouver des documents similaires.

**kdocs** : Bouton existe dans show.php, vérifier l'implémentation backend.

---

### 10. DOCUMENTS - Permissions Objet

**Paperless-ngx** : Permissions par document (owner, view_users, change_users).

**À ajouter** dans la table documents :
```sql
ALTER TABLE documents 
    ADD COLUMN owner_id INT DEFAULT NULL,
    ADD COLUMN view_users TEXT DEFAULT NULL COMMENT 'JSON array of user IDs',
    ADD COLUMN view_groups TEXT DEFAULT NULL COMMENT 'JSON array of group IDs',
    ADD COLUMN change_users TEXT DEFAULT NULL,
    ADD COLUMN change_groups TEXT DEFAULT NULL;
```

---

### 11. MAIL - Règles Avancées

**Paperless-ngx** offre des filtres mail :
- Par expéditeur
- Par sujet (contient/regex)
- Par corps (contient/regex)
- Par pièce jointe (type MIME)
- Actions : mark read, delete, move, flag

**kdocs** : Vérifier `MailService.php` et `mail_rules` table.

**Interface** dans `mail_account_form.php` : Ajouter les filtres avancés.

---

### 12. CONSUME FOLDER - Subdirs as Tags

**Paperless-ngx** : Les sous-dossiers du consume folder deviennent automatiquement des tags.

**Exemple** : `/consume/factures/2024/` → Tags "factures" + "2024"

**À implémenter** dans `ConsumeFolderService.php`.

---

### 13. BARCODE - Séparation et ASN

**Paperless-ngx** : 
- Séparation de documents multi-pages via barcode
- Assignation automatique d'ASN via barcode

**À implémenter** (optionnel, priorité basse).

---

### 14. DASHBOARD - Widgets Personnalisables

**Paperless-ngx** : Dashboard avec saved views personnalisées.

**kdocs** : Dashboard basique avec stats. 

**À améliorer** : Permettre d'ajouter des "saved views" au dashboard.

---

### 15. SAVED VIEWS - Vues Sauvegardées

**Paperless-ngx** : Sauvegarder des filtres de recherche comme "vues".

**kdocs** : Table `saved_searches` existe. Vérifier l'interface.

**À ajouter** :
- Option "Afficher sur le Dashboard"
- Option "Afficher dans la Sidebar"

---

### 16. CUSTOM FIELDS - Types Complets

**Paperless-ngx** supporte :
- String (texte)
- URL
- Date
- Boolean
- Integer
- Float
- Monetary (avec devise)
- Document Link (lien vers autre document)
- Select (liste déroulante)

**kdocs** : Vérifier `custom_fields` table et `CustomField.php`.

---

### 17. BULK EDIT - Actions Groupées Complètes

**Paperless-ngx** permet en bulk :
- Ajouter/retirer tags
- Définir correspondent/type/storage_path
- Définir propriétaire
- Définir permissions
- Supprimer
- Fusionner (merge)
- Télécharger ZIP
- Reclasser IA

**kdocs** : Vérifier `DocumentsApiController.php` bulk-action endpoint.

---

### 18. EXPORT/IMPORT - Format Complet

**Paperless-ngx** : Export avec métadonnées JSON + fichiers originaux.

**kdocs** : Vérifier `ExportController.php`.

---

### 19. API REST - Parité Complète

**Paperless-ngx API** endpoints à vérifier :
- `/api/documents/` (CRUD)
- `/api/documents/{id}/download/`
- `/api/documents/{id}/preview/`
- `/api/documents/{id}/thumb/`
- `/api/documents/{id}/notes/`
- `/api/documents/post_document/` (upload)
- `/api/documents/bulk_edit/`
- `/api/tags/`, `/api/correspondents/`, `/api/document_types/`
- `/api/saved_views/`
- `/api/search/autocomplete/`
- `/api/tasks/`
- `/api/ui_settings/`

---

### 20. TOUR / ONBOARDING

**Paperless-ngx** : Tour guidé pour nouveaux utilisateurs.

**À implémenter** (priorité basse) : Bibliothèque JS comme Shepherd.js ou Intro.js.

---

## 📊 TABLEAU RÉCAPITULATIF

| # | Fonctionnalité | Statut kdocs | Priorité |
|---|----------------|--------------|----------|
| 1 | Bug barre recherche | 🐛 À corriger | CRITIQUE |
| 2 | Bug CSS grille | 🐛 À corriger | CRITIQUE |
| 3 | Bug Database import | 🐛 À corriger | HAUTE |
| 4 | Matching algorithms | ❌ Manquant | HAUTE |
| 5 | Tags inbox | ❌ Manquant | MOYENNE |
| 6 | Split view | ✅ OK | - |
| 7 | Notes documents | ✅ OK | - |
| 8 | Liens partage | ❌ Manquant | MOYENNE |
| 9 | Recherche avancée | ⚠️ Partiel | HAUTE |
| 10 | Autocomplétion | ❌ Manquant | MOYENNE |
| 11 | More Like This | ⚠️ Vérifier | BASSE |
| 12 | Permissions objet | ❌ Manquant | MOYENNE |
| 13 | Mail règles avancées | ⚠️ Partiel | MOYENNE |
| 14 | Subdirs as tags | ❌ Manquant | BASSE |
| 15 | Barcode | ❌ Manquant | BASSE |
| 16 | Dashboard widgets | ⚠️ Basique | BASSE |
| 17 | Saved views complètes | ⚠️ Partiel | MOYENNE |
| 18 | Custom fields types | ⚠️ Vérifier | MOYENNE |
| 19 | Bulk edit complet | ⚠️ Partiel | HAUTE |
| 20 | Export/Import | ⚠️ Vérifier | MOYENNE |
| 21 | API REST complète | ⚠️ Partiel | HAUTE |
| 22 | Workflows complets | ❌ Voir CURSOR_WORKFLOWS_PAPERLESS_PARITY.md | HAUTE |

---

## 🛠️ ORDRE D'EXÉCUTION RECOMMANDÉ

### Phase 1 : Bugs Critiques (30 min)
1. Corriger bug barre recherche
2. Corriger bug CSS grille
3. Corriger import Database

### Phase 2 : Matching & Recherche (2h)
4. Ajouter matching algorithms aux tags/correspondents/types
5. Implémenter MatchingService.php complet
6. Améliorer SearchParser.php

### Phase 3 : Workflows (voir autre doc) (3h)
7. Suivre CURSOR_WORKFLOWS_PAPERLESS_PARITY.md

### Phase 4 : Fonctionnalités UX (2h)
8. Liens de partage public
9. Autocomplétion recherche
10. Bulk edit complet

### Phase 5 : Polish (1h)
11. Vérifier et compléter API REST
12. Vérifier export/import
13. Améliorer dashboard

---

## 📁 FICHIERS DE MIGRATION SQL

**Créer** `database/migrations/paperless_parity.sql` :

```sql
-- =============================================
-- Migration K-Docs vers parité Paperless-ngx
-- =============================================

-- 1. Matching algorithms pour tags
ALTER TABLE tags 
    ADD COLUMN IF NOT EXISTS matching_algorithm TINYINT DEFAULT 0 COMMENT '0=none,1=any,2=all,3=exact,4=regex,5=fuzzy,6=auto',
    ADD COLUMN IF NOT EXISTS is_insensitive BOOLEAN DEFAULT TRUE,
    ADD COLUMN IF NOT EXISTS is_inbox_tag BOOLEAN DEFAULT FALSE;

-- 2. Matching algorithms pour correspondents
ALTER TABLE correspondents 
    ADD COLUMN IF NOT EXISTS match VARCHAR(255) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS matching_algorithm TINYINT DEFAULT 0,
    ADD COLUMN IF NOT EXISTS is_insensitive BOOLEAN DEFAULT TRUE;

-- 3. Matching algorithms pour document_types
ALTER TABLE document_types 
    ADD COLUMN IF NOT EXISTS match VARCHAR(255) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS matching_algorithm TINYINT DEFAULT 0,
    ADD COLUMN IF NOT EXISTS is_insensitive BOOLEAN DEFAULT TRUE;

-- 4. Matching algorithms pour storage_paths
ALTER TABLE storage_paths 
    ADD COLUMN IF NOT EXISTS match VARCHAR(255) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS matching_algorithm TINYINT DEFAULT 0,
    ADD COLUMN IF NOT EXISTS is_insensitive BOOLEAN DEFAULT TRUE;

-- 5. Permissions objet sur documents
ALTER TABLE documents 
    ADD COLUMN IF NOT EXISTS owner_id INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS view_users TEXT DEFAULT NULL COMMENT 'JSON array of user IDs',
    ADD COLUMN IF NOT EXISTS view_groups TEXT DEFAULT NULL COMMENT 'JSON array of group IDs',
    ADD COLUMN IF NOT EXISTS change_users TEXT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS change_groups TEXT DEFAULT NULL;

-- 6. Liens de partage public
CREATE TABLE IF NOT EXISTS document_share_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_id INT NOT NULL,
    slug VARCHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME DEFAULT NULL,
    download_count INT DEFAULT 0,
    max_downloads INT DEFAULT NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_slug (slug),
    INDEX idx_expires (expires_at)
);

-- 7. Saved views améliorées
ALTER TABLE saved_searches
    ADD COLUMN IF NOT EXISTS show_on_dashboard BOOLEAN DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS show_in_sidebar BOOLEAN DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS sort_field VARCHAR(50) DEFAULT 'created_at',
    ADD COLUMN IF NOT EXISTS sort_reverse BOOLEAN DEFAULT TRUE,
    ADD COLUMN IF NOT EXISTS page_size INT DEFAULT 25;

-- 8. Custom fields - types supplémentaires
ALTER TABLE custom_fields
    MODIFY COLUMN field_type ENUM('text', 'number', 'date', 'boolean', 'select', 'url', 'monetary', 'documentlink') DEFAULT 'text',
    ADD COLUMN IF NOT EXISTS select_options TEXT DEFAULT NULL COMMENT 'JSON array for select type',
    ADD COLUMN IF NOT EXISTS currency VARCHAR(3) DEFAULT 'CHF' COMMENT 'For monetary type';

-- 9. Mail rules avancées
ALTER TABLE mail_rules
    ADD COLUMN IF NOT EXISTS filter_from VARCHAR(255) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS filter_subject VARCHAR(255) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS filter_body VARCHAR(255) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS filter_attachment_type VARCHAR(100) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS action_type ENUM('mark_read', 'delete', 'move', 'flag', 'nothing') DEFAULT 'mark_read',
    ADD COLUMN IF NOT EXISTS action_parameter VARCHAR(255) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS maximum_age INT DEFAULT NULL COMMENT 'Days';

-- 10. Index pour performances
CREATE INDEX IF NOT EXISTS idx_documents_owner ON documents(owner_id);
CREATE INDEX IF NOT EXISTS idx_documents_created ON documents(created_at);
CREATE INDEX IF NOT EXISTS idx_documents_correspondent ON documents(correspondent_id);
CREATE INDEX IF NOT EXISTS idx_documents_type ON documents(document_type_id);
```

---

## 🎯 INSTRUCTIONS CURSOR

```
Lis docs/CURSOR_KDOCS_PAPERLESS_PARITY.md et exécute les corrections dans l'ordre :

PHASE 1 - BUGS CRITIQUES :
1. Corrige le bug HTML dans templates/documents/index.php (ligne ~90)
2. Supprime l'accolade orpheline dans le CSS (ligne ~450)  
3. Ajoute "use KDocs\Core\Database;" dans WorkflowsController.php

PHASE 2 - MATCHING :
4. Exécute database/migrations/paperless_parity.sql
5. Modifie templates/admin/tag_form.php pour ajouter matching_algorithm
6. Modifie templates/admin/correspondent_form.php pour ajouter match + matching_algorithm
7. Modifie templates/admin/document_type_form.php pour ajouter match + matching_algorithm
8. Crée app/Services/MatchingService.php avec tous les algorithmes

Continue ensuite avec CURSOR_WORKFLOWS_PAPERLESS_PARITY.md
```
