<div class="max-w-7xl mx-auto px-4 py-6">
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-2xl font-bold">Validation des Documents</h1>
            <p class="text-sm text-gray-500 mt-1">
                Mode: <strong><?= htmlspecialchars($classifier->getMethod()) ?></strong>
                <?php if ($classifier->isAIAvailable()): ?>
                    <span class="text-green-600 ml-2">✓ IA disponible</span>
                <?php else: ?>
                    <span class="text-gray-400 ml-2">○ IA non configurée</span>
                <?php endif; ?>
            </p>
            <?php if ($classifier->isAIAvailable()): ?>
            <div class="mt-2 space-y-2">
                <div class="flex items-center gap-2">
                    <label class="text-xs text-gray-600">Mode:</label>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" id="ocr-mode-toggle" class="sr-only peer" <?= (isset($_COOKIE['ocr_mode']) && $_COOKIE['ocr_mode'] === 'ai') ? 'checked' : '' ?>>
                        <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                        <span class="ml-3 text-xs text-gray-700">
                            <span id="ocr-mode-label-left"><?= (isset($_COOKIE['ocr_mode']) && $_COOKIE['ocr_mode'] === 'ai') ? 'IA' : 'OCR' ?></span>
                            <span class="mx-1">/</span>
                            <span id="ocr-mode-label-right"><?= (isset($_COOKIE['ocr_mode']) && $_COOKIE['ocr_mode'] === 'ai') ? 'OCR' : 'IA' ?></span>
                        </span>
                    </label>
                    <span class="text-xs text-gray-500">
                        (<span id="ocr-mode-status"><?= (isset($_COOKIE['ocr_mode']) && $_COOKIE['ocr_mode'] === 'ai') ? 'IA activée' : 'OCR activé' ?></span>)
                    </span>
                </div>
                <div class="flex items-center gap-2 ai-complex-toggle-container" style="display: <?= (isset($_COOKIE['ocr_mode']) && $_COOKIE['ocr_mode'] === 'ai') ? 'none' : 'flex' ?>;">
                    <label class="text-xs text-gray-600">Utiliser l'IA pour les documents complexes:</label>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" id="ai-complex-auto-toggle" class="sr-only peer" <?= (isset($_COOKIE['ai_complex_auto']) && $_COOKIE['ai_complex_auto'] === '1') ? 'checked' : '' ?>>
                        <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-purple-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-purple-600"></div>
                        <span class="ml-3 text-xs text-gray-700">
                            <span id="ai-complex-label"><?= (isset($_COOKIE['ai_complex_auto']) && $_COOKIE['ai_complex_auto'] === '1') ? 'Activé' : 'Désactivé' ?></span>
                        </span>
                    </label>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <div class="flex gap-2">
            <span class="px-3 py-1 bg-blue-100 text-blue-800 rounded-full text-sm">
                <?= $filesCount ?> fichier(s) à importer
            </span>
            <form method="POST" action="<?= url('/admin/consume/scan') ?>" class="inline">
                <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700">
                    🔄 Scanner
                </button>
            </form>
            <form method="POST" action="<?= url('/admin/consume/rescan') ?>" class="inline" 
                  onsubmit="return confirm('Cette action va réinitialiser les checksums MD5 et re-traiter tous les documents existants. Cela peut prendre du temps. Continuer ?');">
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">
                    🔁 Re-scanner les documents
                </button>
            </form>
        </div>
    </div>
    
    <?php if (!empty($_SESSION['flash'])): ?>
    <div class="mb-4 p-4 rounded <?= $_SESSION['flash']['type'] === 'success' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800' ?>">
        <?= htmlspecialchars($_SESSION['flash']['message']) ?>
    </div>
    <?php unset($_SESSION['flash']); endif; ?>
    
    <?php if (empty($pending)): ?>
    <div class="bg-white rounded-lg shadow p-8 text-center text-gray-500">
        Aucun document en attente de validation
    </div>
    <?php else: ?>
    <div class="space-y-6">
        <?php foreach ($pending as $doc): 
            $suggestions = json_decode($doc['classification_suggestions'] ?? '{}', true);
            $final = $suggestions['final'] ?? [];
            $hasThumbnail = !empty($doc['thumbnail_url']);
        ?>
        <div class="bg-white rounded-lg shadow-lg border border-gray-200" id="document-card-<?= $doc['id'] ?>">
            <?php include __DIR__ . '/consume_card.php'; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<script>
// Gestionnaire global pour les menus contextuels sur les badges de tags
// Utilise la délégation d'événements pour capturer tous les badges, même ceux créés dynamiquement
// IMPORTANT: Utiliser la phase de capture (true) pour intercepter AVANT que le navigateur n'affiche son menu
document.addEventListener('contextmenu', function(e) {
    // Vérifier si le clic est sur un badge de tag suggéré ou un de ses enfants
    const badge = e.target.closest('.suggested-tag-badge');
    if (badge) {
        // Ne pas intercepter si on clique sur le bouton ×
        if (e.target.tagName === 'BUTTON' || e.target.closest('button')) {
            return; // Laisser le bouton gérer son propre événement
        }
        
        e.preventDefault();
        e.stopPropagation();
        e.stopImmediatePropagation();
        
        const tagName = badge.getAttribute('data-tag');
        const documentId = badge.getAttribute('data-document-id');
        
        if (tagName && documentId) {
            showSuggestedTagMenu(e, tagName, parseInt(documentId));
        }
        return false;
    }
    
    // Vérifier si le clic est sur un badge de catégorie ou un de ses enfants
    const categoryBadge = e.target.closest('.category-badge');
    if (categoryBadge) {
        e.preventDefault();
        e.stopPropagation();
        e.stopImmediatePropagation();
        
        const categoryName = categoryBadge.getAttribute('data-category');
        const documentId = categoryBadge.getAttribute('data-document-id');
        
        if (categoryName && documentId) {
            showCategoryMenu(e, categoryName, parseInt(documentId));
        }
        return false;
    }
}, true); // Utiliser la phase de capture pour intercepter avant le navigateur

/**
 * Met à jour tous les selects dans tous les formulaires de documents
 * après création d'un correspondant, champ de classification, etc.
 */
async function updateAllFormSelects() {
    try {
        // Récupérer toutes les listes via les API
        const [correspondentsRes, documentTypesRes, tagsRes, fieldsRes] = await Promise.all([
            fetch('<?= url('/api/correspondents') ?>').then(r => r.json()),
            fetch('<?= url('/api/document-types') ?>').then(r => r.json()),
            fetch('<?= url('/api/tags') ?>').then(r => r.json()),
            fetch('<?= url('/api/classification-fields') ?>').then(r => r.json())
        ]);
        
        const correspondents = correspondentsRes.data || correspondentsRes || [];
        const documentTypes = documentTypesRes.data || documentTypesRes || [];
        const tags = tagsRes.data || tagsRes || [];
        const fields = fieldsRes.data || fieldsRes || [];
        
        // Mettre à jour tous les formulaires de documents
        document.querySelectorAll('form[action*="/admin/consume/validate/"]').forEach(form => {
            // Mettre à jour le select des correspondants
            const correspondentSelect = form.querySelector('select[name="correspondent_id"]');
            if (correspondentSelect) {
                const currentValue = correspondentSelect.value;
                correspondentSelect.innerHTML = '<option value="">-- Sélectionner --</option>';
                correspondents.forEach(c => {
                    const option = document.createElement('option');
                    option.value = c.id;
                    option.textContent = c.name;
                    if (currentValue == c.id) {
                        option.selected = true;
                    }
                    correspondentSelect.appendChild(option);
                });
            }
            
            // Mettre à jour les selects des champs de classification de type "supplier" ou "correspondent"
            form.querySelectorAll('select[name="correspondent_id"], select[name*="custom_field_"][name*="supplier"], select[name*="custom_field_"][name*="correspondent"]').forEach(select => {
                if (select.name === 'correspondent_id') return; // Déjà traité
                const currentValue = select.value;
                select.innerHTML = '<option value="">-- Sélectionner --</option>';
                correspondents.forEach(c => {
                    const option = document.createElement('option');
                    option.value = c.id;
                    option.textContent = c.name;
                    if (currentValue == c.id) {
                        option.selected = true;
                    }
                    select.appendChild(option);
                });
            });
            
            // Mettre à jour les selects des types de documents
            const documentTypeSelect = form.querySelector('select[name="document_type_id"]');
            if (documentTypeSelect) {
                const currentValue = documentTypeSelect.value;
                documentTypeSelect.innerHTML = '<option value="">-- Sélectionner --</option>';
                documentTypes.forEach(t => {
                    const option = document.createElement('option');
                    option.value = t.id;
                    option.textContent = t.label;
                    if (currentValue == t.id) {
                        option.selected = true;
                    }
                    documentTypeSelect.appendChild(option);
                });
            }
            
            // Mettre à jour les selects des champs de classification de type "type" ou "document_type"
            form.querySelectorAll('select[name*="custom_field_"][name*="type"], select[name*="custom_field_"][name*="document_type"]').forEach(select => {
                const currentValue = select.value;
                select.innerHTML = '<option value="">-- Sélectionner --</option>';
                documentTypes.forEach(t => {
                    const option = document.createElement('option');
                    option.value = t.id;
                    option.textContent = t.label;
                    if (currentValue == t.id) {
                        option.selected = true;
                    }
                    select.appendChild(option);
                });
            });
            
            // Mettre à jour le select des tags
            const tagsSelect = form.querySelector('select[name="tags[]"]');
            if (tagsSelect) {
                const currentValues = Array.from(tagsSelect.selectedOptions).map(opt => opt.value);
                tagsSelect.innerHTML = '';
                tags.forEach(t => {
                    const option = document.createElement('option');
                    option.value = t.id;
                    option.textContent = t.name;
                    if (currentValues.includes(String(t.id))) {
                        option.selected = true;
                    }
                    tagsSelect.appendChild(option);
                });
            }
        });
        
        console.log('✅ Tous les selects ont été mis à jour');
    } catch (error) {
        console.error('❌ Erreur lors de la mise à jour des selects:', error);
    }
}

// Activer/désactiver les champs selon l'option sélectionnée
document.querySelectorAll('.storage-path-radio').forEach(radio => {
    radio.addEventListener('change', function() {
        const form = this.closest('form');
        const docId = form.querySelector('input[name="storage_path_custom"]')?.id.replace('custom_path_', '') || 
                     form.querySelector('select[name="storage_path_id"]')?.id.replace('existing_path_', '');
        
        const customInput = form.querySelector('#custom_path_' + docId);
        const existingSelect = form.querySelector('#existing_path_' + docId);
        
        if (this.value === 'custom') {
            if (customInput) customInput.disabled = false;
            if (existingSelect) existingSelect.disabled = true;
        } else if (this.value === 'existing') {
            if (customInput) customInput.disabled = true;
            if (existingSelect) existingSelect.disabled = false;
        } else {
            if (customInput) customInput.disabled = true;
            if (existingSelect) existingSelect.disabled = true;
        }
    });
});

// Menu contextuel pour les catégories IA
let categoryMenu = null;

function showCategoryMenu(event, categoryName, documentId) {
    // Empêcher le menu contextuel du navigateur
    event.preventDefault();
    event.stopPropagation();
    
    // Supprimer le menu existant
    if (categoryMenu) {
        categoryMenu.remove();
        categoryMenu = null;
    }
    
    // Créer le menu
    categoryMenu = document.createElement('div');
    categoryMenu.className = 'fixed bg-white border border-gray-300 rounded-lg shadow-lg py-2 z-50 min-w-[200px]';
    categoryMenu.style.left = event.pageX + 'px';
    categoryMenu.style.top = event.pageY + 'px';
    categoryMenu.innerHTML = `
        <div class="px-4 py-2 text-xs font-semibold text-gray-700 border-b">${categoryName}</div>
        <button onclick="createAsTag('${categoryName}', ${documentId})" class="w-full text-left px-4 py-2 text-sm hover:bg-gray-100">➕ Créer en tant que tag</button>
        <button onclick="createAsField('${categoryName}', ${documentId})" class="w-full text-left px-4 py-2 text-sm hover:bg-gray-100">➕ Créer en tant que champ</button>
        <div class="border-t my-1"></div>
        <button onclick="mapToTag('${categoryName}', ${documentId})" class="w-full text-left px-4 py-2 text-sm hover:bg-gray-100">🔗 Mapper sur un tag</button>
        <button onclick="mapToField('${categoryName}', ${documentId})" class="w-full text-left px-4 py-2 text-sm hover:bg-gray-100">🔗 Mapper sur un champ</button>
        <button onclick="mapToCorrespondent('${categoryName}', ${documentId})" class="w-full text-left px-4 py-2 text-sm hover:bg-gray-100">🔗 Mapper sur un correspondant</button>
        <button onclick="mapToDocumentType('${categoryName}', ${documentId})" class="w-full text-left px-4 py-2 text-sm hover:bg-gray-100">🔗 Mapper sur un type</button>
    `;
    
    document.body.appendChild(categoryMenu);
    
    // Empêcher la propagation des événements sur le menu lui-même
    categoryMenu.addEventListener('contextmenu', (e) => {
        e.preventDefault();
        e.stopPropagation();
    });
    
    // Fermer au clic ailleurs
    setTimeout(() => {
        const closeMenu = function(e) {
            if (categoryMenu && !categoryMenu.contains(e.target)) {
                categoryMenu.remove();
                categoryMenu = null;
                document.removeEventListener('click', closeMenu);
                document.removeEventListener('contextmenu', closeMenu);
            }
        };
        document.addEventListener('click', closeMenu);
        document.addEventListener('contextmenu', closeMenu);
    }, 100);
}

// Fonction générique pour créer une modale
function showModalDialog(title, content, onConfirm, onCancel = null) {
    // Supprimer les modales existantes
    const existing = document.getElementById('modal-overlay');
    if (existing) existing.remove();
    
    const overlay = document.createElement('div');
    overlay.id = 'modal-overlay';
    overlay.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50';
    
    const modal = document.createElement('div');
    modal.className = 'bg-white rounded-lg shadow-xl max-w-md w-full mx-4';
    modal.innerHTML = `
        <div class="p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">${title}</h3>
            <div class="mb-4">${content}</div>
            <div class="flex justify-end gap-2">
                ${onCancel ? `<button id="modal-cancel" class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-md hover:bg-gray-200">Annuler</button>` : ''}
                <button id="modal-confirm" class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-md hover:bg-blue-700">Confirmer</button>
            </div>
        </div>
    `;
    
    overlay.appendChild(modal);
    document.body.appendChild(overlay);
    
    const confirmBtn = modal.querySelector('#modal-confirm');
    const cancelBtn = modal.querySelector('#modal-cancel');
    
    confirmBtn.addEventListener('click', () => {
        overlay.remove();
        if (onConfirm) onConfirm();
    });
    
    if (cancelBtn && onCancel) {
        cancelBtn.addEventListener('click', () => {
            overlay.remove();
            onCancel();
        });
    }
    
    overlay.addEventListener('click', (e) => {
        if (e.target === overlay) {
            overlay.remove();
            if (onCancel) onCancel();
        }
    });
    
    return overlay;
}

function showNameDialog(title, defaultValue, callback) {
    const inputId = 'modal-name-input-' + Date.now();
    const content = `
        <label class="block text-sm font-medium text-gray-700 mb-2">Nom:</label>
        <input type="text" id="${inputId}" value="${defaultValue || ''}" 
               class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
               placeholder="Entrez un nom..."
               autofocus>
    `;
    
    showModalDialog(title, content, () => {
        const input = document.getElementById(inputId);
        const value = input ? input.value.trim() : '';
        if (value) {
            callback(value);
        } else {
            // Si la valeur est vide, ne rien faire mais ne pas empêcher la fermeture de la modale
            console.warn('Nom vide, création annulée');
        }
    });
}

function showSelectionDialog(title, items, itemLabel, callback) {
    // Vérifier que items est un tableau
    if (!Array.isArray(items)) {
        console.error('showSelectionDialog: items is not an array', items);
        alert('Erreur: Les données reçues ne sont pas au bon format.');
        return;
    }
    
    if (!items || items.length === 0) {
        alert('Aucun élément disponible');
        return;
    }
    
    const listId = 'modal-selection-list-' + Date.now();
    const content = `
        <div class="max-h-64 overflow-y-auto border border-gray-200 rounded-md">
            <div id="${listId}" class="divide-y">
                ${items.map((item, index) => `
                    <label class="block px-4 py-2 hover:bg-gray-50 cursor-pointer">
                        <input type="radio" name="modal-selection" value="${item.id}" 
                               class="mr-2" ${index === 0 ? 'checked' : ''}>
                        <span>${itemLabel(item)}</span>
                    </label>
                `).join('')}
            </div>
        </div>
    `;
    
    showModalDialog(title, content, () => {
        const selected = document.querySelector(`#${listId} input[name="modal-selection"]:checked`);
        if (selected) {
            const item = items.find(i => i.id == selected.value);
            if (item) callback(item);
        }
    });
}

function createAsTag(categoryName, documentId) {
    showNameDialog('Créer un tag à partir de cette catégorie', (tagName) => {
        fetch('<?= url('/api/category-mapping/create-tag') ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ category_name: categoryName, tag_name: tagName, document_id: documentId })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                // Mettre à jour tous les selects sans recharger la page
                updateAllFormSelects().then(() => {
                    alert('✅ Tag créé avec succès ! Les formulaires ont été mis à jour.');
                });
            } else {
                alert('❌ Erreur: ' + (data.error || 'Erreur inconnue'));
            }
        });
    });
}

function createAsField(categoryName, documentId) {
    showNameDialog('Créer un champ de classification à partir de cette catégorie\n\nNom du champ:', (fieldName) => {
        const fieldCode = prompt('Code du champ (ex: tribunal, decision_juridique):', categoryName.toLowerCase().replace(/[^a-z0-9]/g, '_'));
        if (fieldCode && fieldCode.trim()) {
            fetch('<?= url('/api/category-mapping/create-field') ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ 
                    category_name: categoryName, 
                    field_name: fieldName, 
                    field_code: fieldCode.trim(),
                    document_id: documentId 
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    // Pour les champs de classification, il faut recharger car la structure des formulaires peut changer
                    alert('✅ Champ créé avec succès ! La page va être rechargée pour afficher le nouveau champ.');
                    location.reload();
                } else {
                    alert('❌ Erreur: ' + (data.error || 'Erreur inconnue'));
                }
            });
        }
    });
}

function mapToTag(categoryName, documentId) {
    // Récupérer la liste des tags
    fetch('<?= url('/api/tags') ?>')
        .then(r => r.json())
        .then(tags => {
            const options = tags.map(t => `${t.id}:${t.name}`).join('\n');
            const choice = prompt(`Mapper "${categoryName}" sur un tag:\n\n${options}\n\nEntrez l'ID du tag:`, '');
            if (choice && !isNaN(choice)) {
                const tag = tags.find(t => t.id == choice);
                if (tag) {
                    fetch('<?= url('/api/category-mapping/map-to-tag') ?>', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ 
                            category_name: categoryName, 
                            tag_id: parseInt(choice),
                            tag_name: tag.name,
                            document_id: documentId 
                        })
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            // Mettre à jour tous les selects sans recharger la page
                            updateAllFormSelects().then(() => {
                                alert('✅ Mapping créé avec succès ! Les formulaires ont été mis à jour.');
                            });
                        } else {
                            alert('❌ Erreur: ' + (data.error || 'Erreur inconnue'));
                        }
                    });
                }
            }
        });
}

function mapToField(categoryName, documentId) {
    fetch('<?= url('/api/classification-fields') ?>')
        .then(r => r.json())
        .then(fields => {
            const options = fields.map(f => `${f.id}:${f.field_name}`).join('\n');
            const choice = prompt(`Mapper "${categoryName}" sur un champ:\n\n${options}\n\nEntrez l'ID du champ:`, '');
            if (choice && !isNaN(choice)) {
                const field = fields.find(f => f.id == choice);
                if (field) {
                    fetch('<?= url('/api/category-mapping/map-to-field') ?>', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ 
                            category_name: categoryName, 
                            field_id: parseInt(choice),
                            field_name: field.field_name,
                            document_id: documentId 
                        })
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            // Mettre à jour tous les selects sans recharger la page
                            updateAllFormSelects().then(() => {
                                alert('✅ Mapping créé avec succès ! Les formulaires ont été mis à jour.');
                            });
                        } else {
                            alert('❌ Erreur: ' + (data.error || 'Erreur inconnue'));
                        }
                    });
                }
            }
        });
}

function mapToCorrespondent(categoryName, documentId) {
    fetch('<?= url('/api/correspondents') ?>')
        .then(r => r.json())
        .then(correspondents => {
            const options = correspondents.map(c => `${c.id}:${c.name}`).join('\n');
            const choice = prompt(`Mapper "${categoryName}" sur un correspondant:\n\n${options}\n\nEntrez l'ID:`, '');
            if (choice && !isNaN(choice)) {
                const corr = correspondents.find(c => c.id == choice);
                if (corr) {
                    fetch('<?= url('/api/category-mapping/map-to-correspondent') ?>', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ 
                            category_name: categoryName, 
                            correspondent_id: parseInt(choice),
                            correspondent_name: corr.name,
                            document_id: documentId 
                        })
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            alert('✅ Mapping créé avec succès !');
                            location.reload();
                        } else {
                            alert('❌ Erreur: ' + (data.error || 'Erreur inconnue'));
                        }
                    });
                }
            }
        });
}

function mapToDocumentType(categoryName, documentId) {
    fetch('<?= url('/api/document-types') ?>')
        .then(r => r.json())
        .then(types => {
            const options = types.map(t => `${t.id}:${t.label}`).join('\n');
            const choice = prompt(`Mapper "${categoryName}" sur un type:\n\n${options}\n\nEntrez l'ID:`, '');
            if (choice && !isNaN(choice)) {
                const type = types.find(t => t.id == choice);
                if (type) {
                    fetch('<?= url('/api/category-mapping/map-to-type') ?>', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ 
                            category_name: categoryName, 
                            type_id: parseInt(choice),
                            type_name: type.label,
                            document_id: documentId 
                        })
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            // Mettre à jour tous les selects sans recharger la page
                            updateAllFormSelects().then(() => {
                                alert('✅ Mapping créé avec succès ! Les formulaires ont été mis à jour.');
                            });
                        } else {
                            alert('❌ Erreur: ' + (data.error || 'Erreur inconnue'));
                        }
                    });
                }
            }
        });
}

// Menu contextuel pour les tags suggérés
let suggestedTagMenu = null;

function showSuggestedTagMenu(event, tagName, documentId) {
    // Empêcher le menu contextuel du navigateur
    event.preventDefault();
    event.stopPropagation();
    
    // Supprimer le menu existant
    if (suggestedTagMenu) {
        suggestedTagMenu.remove();
        suggestedTagMenu = null;
    }
    
    // Créer le menu
    suggestedTagMenu = document.createElement('div');
    suggestedTagMenu.className = 'fixed bg-white border border-gray-300 rounded-lg shadow-lg py-2 z-50 min-w-[250px]';
    suggestedTagMenu.style.left = event.pageX + 'px';
    suggestedTagMenu.style.top = event.pageY + 'px';
    suggestedTagMenu.innerHTML = `
        <div class="px-4 py-2 text-xs font-semibold text-gray-700 border-b">${tagName}</div>
        <button data-action="create-tag" class="w-full text-left px-4 py-2 text-sm hover:bg-gray-100">➕ Ajouter comme tag</button>
        <button data-action="create-field" class="w-full text-left px-4 py-2 text-sm hover:bg-gray-100">➕ Ajouter comme champ de classification</button>
        <button data-action="create-correspondent" class="w-full text-left px-4 py-2 text-sm hover:bg-gray-100">➕ Ajouter comme correspondant</button>
        <div class="border-t my-1"></div>
        <button data-action="map-tag" class="w-full text-left px-4 py-2 text-sm hover:bg-gray-100">🔗 Affecter à un tag existant</button>
        <button data-action="map-field" class="w-full text-left px-4 py-2 text-sm hover:bg-gray-100">🔗 Affecter à un champ de classification existant (inclut types de document)</button>
        <button data-action="map-correspondent" class="w-full text-left px-4 py-2 text-sm hover:bg-gray-100">🔗 Affecter à un correspondant</button>
    `;
    
    // Ajouter les gestionnaires d'événements avec stopPropagation pour éviter la fermeture immédiate
    suggestedTagMenu.querySelector('[data-action="create-tag"]').addEventListener('click', (e) => {
        e.stopPropagation();
        e.preventDefault();
        if (suggestedTagMenu) {
            suggestedTagMenu.remove();
            suggestedTagMenu = null;
        }
        createSuggestedAsTag(tagName, documentId);
    });
    
    suggestedTagMenu.querySelector('[data-action="create-field"]').addEventListener('click', (e) => {
        e.stopPropagation();
        e.preventDefault();
        if (suggestedTagMenu) {
            suggestedTagMenu.remove();
            suggestedTagMenu = null;
        }
        createSuggestedAsField(tagName, documentId);
    });
    
    suggestedTagMenu.querySelector('[data-action="create-correspondent"]').addEventListener('click', (e) => {
        e.stopPropagation();
        e.preventDefault();
        if (suggestedTagMenu) {
            suggestedTagMenu.remove();
            suggestedTagMenu = null;
        }
        createSuggestedAsCorrespondent(tagName, documentId);
    });
    
    suggestedTagMenu.querySelector('[data-action="map-tag"]').addEventListener('click', (e) => {
        e.stopPropagation();
        e.preventDefault();
        if (suggestedTagMenu) {
            suggestedTagMenu.remove();
            suggestedTagMenu = null;
        }
        mapSuggestedToTag(tagName, documentId);
    });
    
    suggestedTagMenu.querySelector('[data-action="map-field"]').addEventListener('click', (e) => {
        e.stopPropagation();
        e.preventDefault();
        if (suggestedTagMenu) {
            suggestedTagMenu.remove();
            suggestedTagMenu = null;
        }
        mapSuggestedToField(tagName, documentId);
    });
    
    suggestedTagMenu.querySelector('[data-action="map-correspondent"]').addEventListener('click', (e) => {
        e.stopPropagation();
        e.preventDefault();
        if (suggestedTagMenu) {
            suggestedTagMenu.remove();
            suggestedTagMenu = null;
        }
        mapSuggestedToCorrespondent(tagName, documentId);
    });
    
    document.body.appendChild(suggestedTagMenu);
    
    // Fermer au clic ailleurs (mais pas immédiatement pour permettre le clic sur les boutons)
    setTimeout(() => {
        const closeMenu = function(e) {
            // Ne pas fermer si on clique dans le menu
            if (suggestedTagMenu && !suggestedTagMenu.contains(e.target)) {
                suggestedTagMenu.remove();
                suggestedTagMenu = null;
                document.removeEventListener('click', closeMenu);
            }
        };
        document.addEventListener('click', closeMenu);
    }, 200);
}

function createSuggestedAsTag(tagName, documentId) {
    if (suggestedTagMenu) {
        suggestedTagMenu.remove();
        suggestedTagMenu = null;
    }
    
    // Récupérer d'abord les tags existants du document
    const form = document.querySelector(`form[action*="/validate/${documentId}"]`);
    let existingTagIds = [];
    if (form) {
        const tagsSelect = form.querySelector('select[name="tags[]"]');
        if (tagsSelect) {
            existingTagIds = Array.from(tagsSelect.selectedOptions).map(opt => parseInt(opt.value));
        }
    }
    
    // Créer directement le tag avec le nom de la suggestion et l'ajouter au document
    fetch('<?= url('/api/category-mapping/create-tag') ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ category_name: tagName, tag_name: tagName, document_id: documentId })
    })
    .then(async r => {
        const contentType = r.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            const text = await r.text();
            throw new Error(`Réponse non-JSON reçue (${r.status}): ${text.substring(0, 200)}`);
        }
        return r.json();
    })
    .then(data => {
        if (data.success && data.tag_id) {
            // Ajouter le nouveau tag aux tags existants (sans doublon)
            const tagId = parseInt(data.tag_id);
            if (!existingTagIds.includes(tagId)) {
                existingTagIds.push(tagId);
            }
            
            // Mettre à jour le document avec tous les tags
            return fetch('<?= url('/api/documents/') ?>' + documentId, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ tags: existingTagIds })
            })
            .then(r => r.json())
            .then(updateData => {
                if (updateData.success) {
                    // Transformer le badge en badge "ajouté" avec possibilité de retirer
                    const badge = document.querySelector(`[data-tag="${tagName}"][data-document-id="${documentId}"]`);
                    if (badge) {
                        badge.className = badge.className.replace('bg-blue-100 text-blue-800', 'bg-green-100 text-green-800');
                        badge.setAttribute('data-tag-added', '1');
                        badge.setAttribute('data-tag-id', tagId);
                        
                        // S'assurer que le badge a toujours la classe suggested-tag-badge pour le gestionnaire global
                        if (!badge.classList.contains('suggested-tag-badge')) {
                            badge.classList.add('suggested-tag-badge');
                        }
                        
                        // Changer le bouton × en bouton de retrait
                        const removeBtn = badge.querySelector('button');
                        if (removeBtn) {
                            removeBtn.className = removeBtn.className.replace('text-blue-600', 'text-green-600');
                            removeBtn.onclick = (e) => {
                                e.stopPropagation();
                                removeTagFromDocument(tagName, documentId, tagId);
                            };
                            removeBtn.title = 'Retirer le tag';
                            removeBtn.innerHTML = '×';
                        }
                    }
                    
                    // Ajouter le badge dans la section Tags assignés
                    const assignedContainer = document.getElementById(`assigned-tags-container-${documentId}`);
                    if (assignedContainer) {
                        const existingBadge = assignedContainer.querySelector(`[data-tag-id="${tagId}"][data-document-id="${documentId}"]`);
                        if (!existingBadge) {
                            const newBadge = document.createElement('div');
                            newBadge.className = 'relative inline-flex items-center gap-1 px-3 py-1 bg-red-100 text-red-800 rounded-full text-xs hover:bg-opacity-80 transition-colors assigned-tag-badge';
                            newBadge.setAttribute('data-tag-id', tagId);
                            newBadge.setAttribute('data-tag-name', tagName);
                            newBadge.setAttribute('data-document-id', documentId);
                            
                            const span = document.createElement('span');
                            span.className = 'cursor-pointer flex-1';
                            span.textContent = tagName;
                            newBadge.appendChild(span);
                            
                            const button = document.createElement('button');
                            button.type = 'button';
                            button.className = 'ml-1 text-red-600 hover:text-red-800 hover:font-bold transition-colors';
                            button.onclick = () => moveTagToSuggestions(tagName, tagId, documentId);
                            button.title = 'Déplacer vers les suggestions';
                            button.innerHTML = '×';
                            newBadge.appendChild(button);
                            
                            assignedContainer.appendChild(newBadge);
                        }
                    }
                    
                    // Mettre à jour tous les selects pour afficher le nouveau tag
                    updateAllFormSelects().then(() => {
                        // Sélectionner le tag dans le select du formulaire
                        if (form) {
                            const tagsSelect = form.querySelector('select[name="tags[]"]');
                            if (tagsSelect) {
                                const option = Array.from(tagsSelect.options).find(opt => opt.value == tagId);
                                if (option) {
                                    option.selected = true;
                                }
                            }
                        }
                        
                        // Retirer l'option du select d'ajout si elle existe
                        const addTagSelect = document.getElementById(`add-tag-select-${documentId}`);
                        if (addTagSelect) {
                            const optionToRemove = Array.from(addTagSelect.options).find(opt => opt.value == tagId);
                            if (optionToRemove) {
                                optionToRemove.remove();
                            }
                        }
                    });
                } else {
                    alert('❌ Erreur lors de l\'ajout du tag au document: ' + (updateData.error || 'Erreur inconnue'));
                }
            });
        } else {
            alert('❌ Erreur lors de la création du tag: ' + (data.error || 'Erreur inconnue'));
        }
    })
    .catch(err => {
        console.error('Erreur lors de la création/ajout du tag:', err);
        let errorMessage = err.message;
        if (errorMessage.includes('<!doctype') || errorMessage.includes('Réponse non-JSON')) {
            errorMessage = 'Erreur serveur : La réponse n\'est pas au format JSON. Vérifiez que la table category_mappings existe dans la base de données.';
        }
        alert('❌ Erreur: ' + errorMessage);
    });
}

// Retirer un tag du document et le remettre dans les suggestions
function removeTagFromDocument(tagName, documentId, tagId) {
    if (!confirm(`Retirer le tag "${tagName}" du document ?\n\nIl reviendra dans la liste des suggestions.`)) {
        return;
    }
    
    // Récupérer les tags actuels du document
    const form = document.querySelector(`form[action*="/validate/${documentId}"]`);
    if (!form) {
        alert('❌ Formulaire non trouvé');
        return;
    }
    
    const tagsSelect = form.querySelector('select[name="tags[]"]');
    if (!tagsSelect) {
        alert('❌ Select de tags non trouvé');
        return;
    }
    
    const tagIdInt = parseInt(tagId);
    const currentTagIds = Array.from(tagsSelect.selectedOptions)
        .map(opt => parseInt(opt.value))
        .filter(id => id !== tagIdInt); // Retirer le tag
    
    // Mettre à jour le document
    fetch('<?= url('/api/documents/') ?>' + documentId, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ tags: currentTagIds })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            // Retirer le badge de la section Tags assignés
            const assignedBadge = document.querySelector(`[data-tag-id="${tagIdInt}"][data-document-id="${documentId}"].assigned-tag-badge`);
            if (assignedBadge) {
                assignedBadge.remove();
            }
            
            // Retransformer le badge en badge "suggéré" dans les suggestions
            const badge = document.querySelector(`[data-tag="${tagName}"][data-document-id="${documentId}"].suggested-tag-badge`);
            if (badge) {
                badge.className = badge.className.replace('bg-green-100 text-green-800', 'bg-blue-100 text-blue-800');
                badge.removeAttribute('data-tag-added');
                badge.removeAttribute('data-tag-id');
                
                // S'assurer que le badge a toujours la classe suggested-tag-badge pour le gestionnaire global
                if (!badge.classList.contains('suggested-tag-badge')) {
                    badge.classList.add('suggested-tag-badge');
                }
                
                // Remettre le bouton × pour marquer comme non pertinent
                const removeBtn = badge.querySelector('button');
                if (removeBtn) {
                    removeBtn.className = removeBtn.className.replace('text-green-600', 'text-blue-600');
                    removeBtn.onclick = () => markTagIrrelevant(tagName, documentId);
                    removeBtn.title = 'Marquer comme non pertinent';
                    removeBtn.innerHTML = '×';
                }
            } else {
                // Si le badge n'existe pas dans les suggestions, l'ajouter
                const suggestedContainer = document.getElementById(`suggested-tags-container-${documentId}`);
                if (suggestedContainer) {
                    const existingBadge = suggestedContainer.querySelector(`[data-tag="${tagName}"][data-document-id="${documentId}"]`);
                    if (!existingBadge) {
                        const newBadge = document.createElement('div');
                        newBadge.className = 'relative inline-flex items-center gap-1 px-3 py-1 bg-blue-100 text-blue-800 rounded-full text-xs hover:bg-opacity-80 transition-colors suggested-tag-badge cursor-pointer';
                        newBadge.setAttribute('data-tag', tagName);
                        newBadge.setAttribute('data-document-id', documentId);
                        newBadge.setAttribute('data-has-mapping', '0');
                        
                        const span = document.createElement('span');
                        span.className = 'cursor-pointer flex-1';
                        span.textContent = tagName;
                        newBadge.appendChild(span);
                        
                        const button = document.createElement('button');
                        button.type = 'button';
                        button.className = 'ml-1 text-blue-600 hover:text-red-600 hover:font-bold transition-colors';
                        button.onclick = () => markTagIrrelevant(tagName, documentId);
                        button.title = 'Marquer comme non pertinent';
                        button.innerHTML = '×';
                        newBadge.appendChild(button);
                        
                        suggestedContainer.appendChild(newBadge);
                    }
                }
            }
            
            // Mettre à jour le select caché
            const option = Array.from(tagsSelect.options).find(opt => parseInt(opt.value) === tagIdInt);
            if (option) {
                option.selected = false;
            }
            
            // Ajouter l'option au select d'ajout
            const addTagSelect = document.getElementById(`add-tag-select-${documentId}`);
            if (addTagSelect) {
                let optionExists = false;
                for (let opt of addTagSelect.options) {
                    if (opt.value == tagIdInt) {
                        optionExists = true;
                        break;
                    }
                }
                if (!optionExists) {
                    const newOption = document.createElement('option');
                    newOption.value = tagIdInt;
                    newOption.textContent = tagName;
                    addTagSelect.appendChild(newOption);
                }
            }
        } else {
            alert('❌ Erreur lors du retrait du tag: ' + (data.error || 'Erreur inconnue'));
        }
    })
    .catch(err => {
        console.error('Erreur lors du retrait du tag:', err);
        alert('❌ Erreur: ' + err.message);
    });
}

// Déplacer un tag assigné vers les suggestions
function moveTagToSuggestions(tagName, tagId, documentId) {
    // Récupérer le formulaire
    const form = document.querySelector(`form[action*="/validate/${documentId}"]`);
    if (!form) {
        alert('❌ Formulaire non trouvé');
        return;
    }
    
    const tagsSelect = form.querySelector(`select[name="tags[]"]`);
    if (!tagsSelect) {
        alert('❌ Select de tags non trouvé');
        return;
    }
    
    const tagIdInt = parseInt(tagId);
    const currentTagIds = Array.from(tagsSelect.selectedOptions)
        .map(opt => parseInt(opt.value))
        .filter(id => id !== tagIdInt); // Retirer le tag
    
    // Mettre à jour le document
    fetch('<?= url('/api/documents/') ?>' + documentId, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ tags: currentTagIds })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            // Retirer le badge de la section Tags
            const assignedBadge = document.querySelector(`[data-tag-id="${tagId}"][data-document-id="${documentId}"].assigned-tag-badge`);
            if (assignedBadge) {
                assignedBadge.remove();
            }
            
            // Mettre à jour le select caché
            const option = Array.from(tagsSelect.options).find(opt => parseInt(opt.value) === tagIdInt);
            if (option) {
                option.selected = false;
            }
            
            // Ajouter le tag aux suggestions s'il n'y est pas déjà
            const suggestedContainer = document.getElementById(`suggested-tags-container-${documentId}`);
            if (suggestedContainer) {
                // Vérifier si le tag existe déjà dans les suggestions
                const existingBadge = suggestedContainer.querySelector(`[data-tag="${tagName}"][data-document-id="${documentId}"]`);
                if (!existingBadge) {
                    // Créer un nouveau badge dans les suggestions
                    const newBadge = document.createElement('div');
                    newBadge.className = 'relative inline-flex items-center gap-1 px-3 py-1 bg-blue-100 text-blue-800 rounded-full text-xs hover:bg-opacity-80 transition-colors suggested-tag-badge cursor-pointer';
                    newBadge.setAttribute('data-tag', tagName);
                    newBadge.setAttribute('data-document-id', documentId);
                    newBadge.setAttribute('data-has-mapping', '0');
                    
                    const span = document.createElement('span');
                    span.className = 'cursor-pointer flex-1';
                    span.textContent = tagName;
                    newBadge.appendChild(span);
                    
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'ml-1 text-blue-600 hover:text-red-600 hover:font-bold transition-colors';
                    button.onclick = () => markTagIrrelevant(tagName, documentId);
                    button.title = 'Marquer comme non pertinent';
                    button.innerHTML = '×';
                    newBadge.appendChild(button);
                    
                    suggestedContainer.appendChild(newBadge);
                }
            }
            
            // Mettre à jour le select d'ajout de tags
            const addTagSelect = document.getElementById(`add-tag-select-${documentId}`);
            if (addTagSelect) {
                // Ajouter l'option si elle n'existe pas déjà
                let optionExists = false;
                for (let opt of addTagSelect.options) {
                    if (opt.value == tagId) {
                        optionExists = true;
                        break;
                    }
                }
                if (!optionExists) {
                    const newOption = document.createElement('option');
                    newOption.value = tagId;
                    newOption.textContent = tagName;
                    addTagSelect.appendChild(newOption);
                }
            }
        } else {
            alert('❌ Erreur lors du déplacement du tag: ' + (data.error || 'Erreur inconnue'));
        }
    })
    .catch(err => {
        console.error('Erreur lors du déplacement du tag:', err);
        alert('❌ Erreur: ' + err.message);
    });
}

// Ajouter un tag au document depuis le select
function addTagToDocument(tagId, documentId) {
    if (!tagId) return;
    
    const form = document.querySelector(`form[action*="/validate/${documentId}"]`);
    if (!form) {
        alert('❌ Formulaire non trouvé');
        return;
    }
    
    const tagsSelect = form.querySelector(`select[name="tags[]"]`);
    if (!tagsSelect) {
        alert('❌ Select de tags non trouvé');
        return;
    }
    
    // Trouver l'option correspondante
    const option = Array.from(tagsSelect.options).find(opt => opt.value == tagId);
    if (!option) {
        alert('❌ Tag non trouvé');
        return;
    }
    
    const tagName = option.textContent.trim();
    const tagIdInt = parseInt(tagId);
    
    // Récupérer les tags actuels
    const currentTagIds = Array.from(tagsSelect.selectedOptions)
        .map(opt => parseInt(opt.value));
    
    // Ajouter le nouveau tag s'il n'est pas déjà présent
    if (!currentTagIds.includes(tagIdInt)) {
        currentTagIds.push(tagIdInt);
    }
    
    // Mettre à jour le document
    fetch('<?= url('/api/documents/') ?>' + documentId, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ tags: currentTagIds })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            // Sélectionner l'option dans le select caché
            option.selected = true;
            
            // Ajouter le badge dans la section Tags
            const assignedContainer = document.getElementById(`assigned-tags-container-${documentId}`);
            if (assignedContainer) {
                // Vérifier si le badge existe déjà
                const existingBadge = assignedContainer.querySelector(`[data-tag-id="${tagId}"][data-document-id="${documentId}"]`);
                if (!existingBadge) {
                    const newBadge = document.createElement('div');
                    newBadge.className = 'relative inline-flex items-center gap-1 px-3 py-1 bg-red-100 text-red-800 rounded-full text-xs hover:bg-opacity-80 transition-colors assigned-tag-badge';
                    newBadge.setAttribute('data-tag-id', tagId);
                    newBadge.setAttribute('data-tag-name', tagName);
                    newBadge.setAttribute('data-document-id', documentId);
                    
                    const span = document.createElement('span');
                    span.className = 'cursor-pointer flex-1';
                    span.textContent = tagName;
                    newBadge.appendChild(span);
                    
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'ml-1 text-red-600 hover:text-red-800 hover:font-bold transition-colors';
                    button.onclick = () => moveTagToSuggestions(tagName, tagId, documentId);
                    button.title = 'Déplacer vers les suggestions';
                    button.innerHTML = '×';
                    newBadge.appendChild(button);
                    
                    assignedContainer.appendChild(newBadge);
                }
            }
            
            // Retirer l'option du select d'ajout
            const addTagSelect = document.getElementById(`add-tag-select-${documentId}`);
            if (addTagSelect) {
                const optionToRemove = Array.from(addTagSelect.options).find(opt => opt.value == tagId);
                if (optionToRemove) {
                    optionToRemove.remove();
                }
                addTagSelect.value = ''; // Réinitialiser le select
            }
            
            // Mettre à jour le badge dans les suggestions s'il existe
            const suggestedBadge = document.querySelector(`[data-tag="${tagName}"][data-document-id="${documentId}"].suggested-tag-badge`);
            if (suggestedBadge) {
                suggestedBadge.className = suggestedBadge.className.replace('bg-blue-100 text-blue-800', 'bg-green-100 text-green-800');
                suggestedBadge.setAttribute('data-tag-added', '1');
                suggestedBadge.setAttribute('data-tag-id', tagId);
                
                const removeBtn = suggestedBadge.querySelector('button');
                if (removeBtn) {
                    removeBtn.className = removeBtn.className.replace('text-blue-600', 'text-green-600');
                    removeBtn.onclick = (e) => {
                        e.stopPropagation();
                        removeTagFromDocument(tagName, documentId, tagId);
                    };
                    removeBtn.title = 'Retirer le tag';
                }
            }
        } else {
            alert('❌ Erreur lors de l\'ajout du tag: ' + (data.error || 'Erreur inconnue'));
        }
    })
    .catch(err => {
        console.error('Erreur lors de l\'ajout du tag:', err);
        alert('❌ Erreur: ' + err.message);
    });
}

function createSuggestedAsCorrespondent(tagName, documentId) {
    if (suggestedTagMenu) {
        suggestedTagMenu.remove();
        suggestedTagMenu = null;
    }
    const defaultCorrespondentName = tagName.charAt(0).toUpperCase() + tagName.slice(1);
    showNameDialog('Créer un correspondant à partir de cette suggestion', defaultCorrespondentName, (correspondentName) => {
        fetch('<?= url('/api/category-mapping/create-correspondent') ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                category_name: tagName, 
                correspondent_name: correspondentName,
                document_id: documentId 
            })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                // Chercher le badge et le formulaire
                const badge = document.querySelector(`[data-tag="${tagName}"][data-document-id="${documentId}"]`);
                const form = badge ? badge.closest('form') : document.querySelector(`form[action*="/validate/${documentId}"]`);
                
                // Appliquer automatiquement la valeur au document dans le formulaire
                if (form) {
                    const correspondentSelect = form.querySelector('select[name="correspondent_id"]');
                    if (correspondentSelect) {
                        // Ajouter l'option si elle n'existe pas déjà
                        let optionExists = false;
                        for (let option of correspondentSelect.options) {
                            if (option.value == data.correspondent_id) {
                                optionExists = true;
                                break;
                            }
                        }
                        if (!optionExists) {
                            const newOption = document.createElement('option');
                            newOption.value = data.correspondent_id;
                            newOption.textContent = correspondentName;
                            correspondentSelect.appendChild(newOption);
                        }
                        correspondentSelect.value = data.correspondent_id;
                        correspondentSelect.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                }
                
                // Masquer le badge du tag suggéré
                if (badge) {
                    badge.style.display = 'none';
                }
                
                // Mettre à jour tous les selects dans tous les formulaires
                updateAllFormSelects().then(() => {
                    alert('✅ Correspondant créé et valeur appliquée au document ! Les autres formulaires ont été mis à jour.');
                });
            } else {
                alert('❌ Erreur: ' + (data.error || 'Erreur inconnue'));
            }
        })
        .catch(err => {
            alert('❌ Erreur: ' + err.message);
        });
    });
}

function createSuggestedAsField(tagName, documentId) {
    if (suggestedTagMenu) {
        suggestedTagMenu.remove();
        suggestedTagMenu = null;
    }
    const defaultFieldName = tagName.charAt(0).toUpperCase() + tagName.slice(1);
    showNameDialog('Créer un champ de classification', defaultFieldName, (fieldName) => {
        const defaultCode = tagName.toLowerCase().replace(/[^a-z0-9]/g, '_');
        const codeInputId = 'modal-field-code-input-' + Date.now();
        const codeContent = `
            <label class="block text-sm font-medium text-gray-700 mb-2">Nom du champ:</label>
            <div class="mb-3 text-sm text-gray-600">${fieldName}</div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Code du champ:</label>
            <input type="text" id="${codeInputId}" value="${defaultCode}" 
                   class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                   placeholder="ex: convention, logement"
                   autofocus>
            <p class="mt-2 text-xs text-gray-500">Le code est utilisé pour l'identification technique (minuscules, underscores)</p>
        `;
        
        showModalDialog('Code du champ de classification', codeContent, () => {
            const codeInput = document.getElementById(codeInputId);
            const fieldCode = codeInput ? codeInput.value.trim() : '';
            if (fieldCode) {
                fetch('<?= url('/api/category-mapping/create-field') ?>', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ 
                        category_name: tagName, 
                        field_name: fieldName, 
                        field_code: fieldCode,
                        document_id: documentId 
                    })
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        // Pour les champs de classification, il faut recharger car la structure des formulaires peut changer
                        alert('✅ Champ créé avec succès ! La page va être rechargée pour afficher le nouveau champ.');
                        location.reload();
                    } else {
                        alert('❌ Erreur: ' + (data.error || 'Erreur inconnue'));
                    }
                });
            }
        });
    });
}

function mapSuggestedToTag(tagName, documentId) {
    if (suggestedTagMenu) {
        suggestedTagMenu.remove();
        suggestedTagMenu = null;
    }
    fetch('<?= url('/api/tags') ?>')
        .then(r => r.json())
        .then(response => {
            // L'API peut retourner {success: true, data: [...]} ou directement [...]
            const tags = Array.isArray(response) ? response : (response.data || []);
            if (!tags || tags.length === 0) {
                alert('Aucun tag disponible. Créez d\'abord des tags dans la section "Étiquettes".');
                return;
            }
            showSelectionDialog(
                `Affecter "${tagName}" à un tag existant`,
                tags,
                (tag) => tag.name,
                (selectedTag) => {
                    fetch('<?= url('/api/category-mapping/map-to-tag') ?>', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ 
                            category_name: tagName, 
                            tag_id: selectedTag.id,
                            tag_name: selectedTag.name,
                            document_id: documentId 
                        })
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            // Mettre à jour tous les selects sans recharger la page
                            updateAllFormSelects().then(() => {
                                alert('✅ Mapping créé avec succès ! Les formulaires ont été mis à jour.');
                            });
                        } else {
                            alert('❌ Erreur: ' + (data.error || 'Erreur inconnue'));
                        }
                    });
                }
            );
        })
        .catch(err => {
            alert('❌ Erreur lors du chargement des tags: ' + err.message);
        });
}

function mapSuggestedToField(tagName, documentId) {
    if (suggestedTagMenu) {
        suggestedTagMenu.remove();
        suggestedTagMenu = null;
    }
    // Charger à la fois les champs de classification ET les types de document
    Promise.all([
        fetch('<?= url('/api/classification-fields') ?>').then(r => r.json()),
        fetch('<?= url('/api/document-types') ?>').then(r => r.json())
    ])
    .then(([fieldsResponse, typesResponse]) => {
        // Extraire les données
        const fields = Array.isArray(fieldsResponse) ? fieldsResponse : (fieldsResponse.data || []);
        const types = Array.isArray(typesResponse) ? typesResponse : (typesResponse.data || []);
        
        // Combiner les champs et les types de document
        const allFields = [
            ...fields.map(f => ({ ...f, isType: false, displayName: f.field_name })),
            ...types.map(t => ({ ...t, isType: true, displayName: t.label, field_name: t.label, id: t.id }))
        ];
        
        if (allFields.length === 0) {
            alert('Aucun champ de classification ou type de document disponible.');
            return;
        }
        
        showSelectionDialog(
            `Affecter "${tagName}" à un champ de classification existant (inclut types de document)`,
            allFields,
            (item) => item.isType ? `📄 ${item.displayName} (Type de document)` : item.displayName,
            (selectedItem) => {
                if (selectedItem.isType) {
                    // C'est un type de document, utiliser l'API map-to-type
                    fetch('<?= url('/api/category-mapping/map-to-type') ?>', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ 
                            category_name: tagName, 
                            type_id: selectedItem.id,
                            type_name: selectedItem.label,
                            document_id: documentId 
                        })
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            // Appliquer la valeur au formulaire
                            const badge = document.querySelector(`[data-tag="${tagName}"][data-document-id="${documentId}"]`);
                            const form = badge ? badge.closest('form') : document.querySelector(`form[action*="/validate/${documentId}"]`);
                            if (form) {
                                const typeSelect = form.querySelector('select[name="document_type_id"]');
                                if (typeSelect) {
                                    typeSelect.value = selectedItem.id;
                                    typeSelect.dispatchEvent(new Event('change', { bubbles: true }));
                                }
                            }
                            if (badge) badge.style.display = 'none';
                            alert('✅ Mapping créé et valeur appliquée au document !');
                        } else {
                            alert('❌ Erreur: ' + (data.error || 'Erreur inconnue'));
                        }
                    });
                } else {
                    // C'est un champ de classification normal
                    fetch('<?= url('/api/category-mapping/map-to-field') ?>', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ 
                            category_name: tagName, 
                            field_id: selectedItem.id,
                            field_name: selectedItem.field_name,
                            document_id: documentId 
                        })
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            // Mettre à jour tous les selects sans recharger la page
                            updateAllFormSelects().then(() => {
                                alert('✅ Mapping créé avec succès ! Les formulaires ont été mis à jour.');
                            });
                        } else {
                            alert('❌ Erreur: ' + (data.error || 'Erreur inconnue'));
                        }
                    });
                }
            }
        );
    })
    .catch(err => {
        alert('❌ Erreur lors du chargement: ' + err.message);
    });
}

function mapSuggestedToCorrespondent(tagName, documentId) {
    if (suggestedTagMenu) {
        suggestedTagMenu.remove();
        suggestedTagMenu = null;
    }
    fetch('<?= url('/api/correspondents') ?>')
        .then(r => r.json())
        .then(response => {
            // L'API retourne {success: true, data: [...]}
            const correspondents = Array.isArray(response) ? response : (response.data || []);
            if (!correspondents || correspondents.length === 0) {
                alert('Aucun correspondant disponible.');
                return;
            }
            showSelectionDialog(
                `Affecter "${tagName}" à un correspondant`,
                correspondents,
                (corr) => corr.name,
                (selectedCorr) => {
                    fetch('<?= url('/api/category-mapping/map-to-correspondent') ?>', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ 
                            category_name: tagName, 
                            correspondent_id: selectedCorr.id,
                            correspondent_name: selectedCorr.name,
                            document_id: documentId 
                        })
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            // Chercher le badge et le formulaire
                            const badge = document.querySelector(`[data-tag="${tagName}"][data-document-id="${documentId}"]`);
                            const form = badge ? badge.closest('form') : document.querySelector(`form[action*="/validate/${documentId}"]`);
                            
                            // Appliquer automatiquement la valeur au document dans le formulaire
                            if (form) {
                                const correspondentSelect = form.querySelector('select[name="correspondent_id"]');
                                if (correspondentSelect) {
                                    correspondentSelect.value = selectedCorr.id;
                                    correspondentSelect.dispatchEvent(new Event('change', { bubbles: true }));
                                }
                            }
                            
                            // Masquer le badge du tag suggéré
                            if (badge) {
                                badge.style.display = 'none';
                            }
                            
                            alert('✅ Mapping créé et valeur appliquée au document !');
                            // Ne pas recharger pour garder les modifications du formulaire
                        } else {
                            alert('❌ Erreur: ' + (data.error || 'Erreur inconnue'));
                        }
                    });
                }
            );
        })
        .catch(err => {
            alert('❌ Erreur lors du chargement des correspondants: ' + err.message);
        });
}

function markTagIrrelevant(tagName, documentId) {
    if (!confirm(`Marquer "${tagName}" comme non pertinent ?\n\nCe tag ne sera plus suggéré pour ce document.`)) {
        return;
    }
    
    fetch('<?= url('/api/suggested-tags/mark-irrelevant') ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ 
            tag_name: tagName,
            document_id: documentId 
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            // Masquer le badge visuellement
            const badge = document.querySelector(`[data-tag="${tagName}"][data-document-id="${documentId}"]`);
            if (badge) {
                badge.style.display = 'none';
            }
        } else {
            alert('❌ Erreur: ' + (data.error || 'Erreur inconnue'));
        }
    });
}

function analyzeWithAI(documentId) {
    const btn = document.getElementById('analyze-ai-btn-' + documentId);
    if (!btn) return;
    
    const originalText = btn.textContent;
    btn.disabled = true;
    btn.textContent = '⏳ Analyse en cours...';
    
    // Récupérer le mode OCR sélectionné depuis le toggle
    const ocrToggle = document.getElementById('ocr-mode-toggle');
    const ocrMode = ocrToggle && ocrToggle.checked ? 'ai' : 'local';
    
    // Sauvegarder le choix dans un cookie
    document.cookie = `ocr_mode=${ocrMode}; path=/; max-age=31536000`; // 1 an
    
    fetch('<?= url('/api/documents/') ?>' + documentId + '/analyze-with-ai', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ 
            ocr_mode: ocrMode,
            use_file_directly: ocrMode === 'ai' // Si mode IA, envoyer le fichier directement
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            // Recharger uniquement la fiche du document avec AJAX
            fetch('<?= url('/admin/consume/document-card/') ?>' + documentId)
                .then(r => r.text())
                .then(html => {
                    const cardContainer = document.getElementById('document-card-' + documentId);
                    if (cardContainer) {
                        // Créer un conteneur temporaire pour parser le HTML
                        const temp = document.createElement('div');
                        temp.innerHTML = html;
                        const newCard = temp.querySelector('[id^="document-card-"]');
                        if (newCard) {
                            cardContainer.outerHTML = newCard.outerHTML;
                        }
                    }
                    btn.disabled = false;
                    btn.textContent = originalText;
                })
                .catch(err => {
                    console.error('Erreur lors du rechargement:', err);
                    // Fallback: recharger toute la page
                    location.reload();
                });
        } else {
            btn.disabled = false;
            btn.textContent = originalText;
            alert('❌ Erreur lors de l\'analyse: ' + (data.error || 'Erreur inconnue'));
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.textContent = originalText;
        alert('❌ Erreur: ' + err.message);
    });
}

function analyzeComplexWithAI(documentId) {
    const btn = document.getElementById('analyze-complex-ai-btn-' + documentId);
    if (!btn) return;
    
    const originalText = btn.textContent;
    btn.disabled = true;
    btn.textContent = '⏳ Analyse complexe en cours...';
    
    fetch('<?= url('/api/documents/') ?>' + documentId + '/analyze-complex-with-ai', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({})
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            // Recharger uniquement la fiche du document avec AJAX
            fetch('<?= url('/admin/consume/document-card/') ?>' + documentId)
                .then(r => r.text())
                .then(html => {
                    const cardContainer = document.getElementById('document-card-' + documentId);
                    if (cardContainer) {
                        // Créer un conteneur temporaire pour parser le HTML
                        const temp = document.createElement('div');
                        temp.innerHTML = html;
                        const newCard = temp.querySelector('[id^="document-card-"]');
                        if (newCard) {
                            cardContainer.outerHTML = newCard.outerHTML;
                        }
                    }
                    btn.disabled = false;
                    btn.textContent = originalText;
                })
                .catch(err => {
                    console.error('Erreur lors du rechargement:', err);
                    // Fallback: recharger toute la page
                    location.reload();
                });
        } else {
            btn.disabled = false;
            btn.textContent = originalText;
            alert('❌ Erreur lors de l\'analyse complexe: ' + (data.error || 'Erreur inconnue'));
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.textContent = originalText;
        alert('❌ Erreur: ' + err.message);
    });
}

// Gérer le toggle OCR
document.addEventListener('DOMContentLoaded', function() {
    const ocrToggle = document.getElementById('ocr-mode-toggle');
    const ocrLabelLeft = document.getElementById('ocr-mode-label-left');
    const ocrLabelRight = document.getElementById('ocr-mode-label-right');
    const ocrModeStatus = document.getElementById('ocr-mode-status');
    
    function updateComplexButtons() {
        const isAI = ocrToggle && ocrToggle.checked;
        // Afficher/masquer les boutons "Analyser les documents complexes avec IA" (visibles seulement si mode IA)
        document.querySelectorAll('.ocr-mode-dependent').forEach(btn => {
            btn.style.display = isAI ? 'inline-block' : 'none';
        });
        // Afficher/masquer le toggle IA complexe automatique (visible seulement si mode OCR/Local)
        const complexToggleContainer = document.querySelector('.ai-complex-toggle-container');
        if (complexToggleContainer) {
            complexToggleContainer.style.display = isAI ? 'none' : 'flex';
        }
    }
    
    if (ocrToggle) {
        // Restaurer l'état depuis le cookie
        const cookies = document.cookie.split(';');
        let ocrMode = 'local';
        for (let cookie of cookies) {
            const [name, value] = cookie.trim().split('=');
            if (name === 'ocr_mode') {
                ocrMode = value;
                break;
            }
        }
        
        const ocrLabelLeft = document.getElementById('ocr-mode-label-left');
        const ocrLabelRight = document.getElementById('ocr-mode-label-right');
        const ocrModeStatus = document.getElementById('ocr-mode-status');
        
        if (ocrMode === 'ai') {
            ocrToggle.checked = true;
            if (ocrLabelLeft) ocrLabelLeft.textContent = 'IA';
            if (ocrLabelRight) ocrLabelRight.textContent = 'OCR';
            if (ocrModeStatus) ocrModeStatus.textContent = 'IA activée';
        } else {
            ocrToggle.checked = false;
            if (ocrLabelLeft) ocrLabelLeft.textContent = 'OCR';
            if (ocrLabelRight) ocrLabelRight.textContent = 'IA';
            if (ocrModeStatus) ocrModeStatus.textContent = 'OCR activé';
        }
        
        // Mettre à jour les boutons complexes au chargement
        updateComplexButtons();
        
        ocrToggle.addEventListener('change', function() {
            const mode = this.checked ? 'ai' : 'local';
            if (ocrLabelLeft) ocrLabelLeft.textContent = mode === 'ai' ? 'IA' : 'OCR';
            if (ocrLabelRight) ocrLabelRight.textContent = mode === 'ai' ? 'OCR' : 'IA';
            if (ocrModeStatus) ocrModeStatus.textContent = mode === 'ai' ? 'IA activée' : 'OCR activé';
            document.cookie = `ocr_mode=${mode}; path=/; max-age=31536000`;
            // Mettre à jour la visibilité des boutons complexes
            updateComplexButtons();
        });
        
        // Gérer le toggle IA complexe automatique
        const aiComplexToggle = document.getElementById('ai-complex-auto-toggle');
        const aiComplexLabel = document.getElementById('ai-complex-label');
        if (aiComplexToggle && aiComplexLabel) {
            // Restaurer l'état depuis le cookie
            const cookies = document.cookie.split(';');
            let aiComplexAuto = '0';
            for (let cookie of cookies) {
                const [name, value] = cookie.trim().split('=');
                if (name === 'ai_complex_auto') {
                    aiComplexAuto = value;
                    break;
                }
            }
            if (aiComplexAuto === '1') {
                aiComplexToggle.checked = true;
                aiComplexLabel.textContent = 'Activé';
            }
            
            aiComplexToggle.addEventListener('change', function() {
                const enabled = this.checked ? '1' : '0';
                aiComplexLabel.textContent = enabled === '1' ? 'Activé' : 'Désactivé';
                document.cookie = `ai_complex_auto=${enabled}; path=/; max-age=31536000`;
                
                // Sauvegarder aussi dans les settings de la base de données
                fetch('<?= url('/admin/settings/save') ?>', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'ai[complex_auto_enabled]=' + (enabled === '1' ? '1' : '0')
                }).catch(err => {
                    console.error('Erreur sauvegarde setting:', err);
                });
            });
        }
    }
});
</script>
