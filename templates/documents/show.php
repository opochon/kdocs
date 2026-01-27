<?php
// Template Paperless-ngx style - Page de détails du document
// $document, $tags, $notes, $documentId, $correspondents, $documentTypes, $storagePaths, $allTags, $previousId, $nextId sont passés
use KDocs\Core\Config;
$base = Config::basePath();
$isPDF = strpos($document['mime_type'] ?? '', 'pdf') !== false;
$isImage = strpos($document['mime_type'] ?? '', 'image') !== false;
$canPreview = $isPDF || $isImage;
?>

<!-- Header avec titre et actions -->
<div class="bg-white border-b border-gray-200 px-4 py-3">
    <div class="flex items-center justify-between">
        <!-- Titre et navigation -->
        <div class="flex items-center gap-3 flex-1 min-w-0">
            <h1 class="text-lg font-semibold text-gray-900 truncate">
                <?= htmlspecialchars($document['title'] ?: $document['original_filename']) ?>
            </h1>
            <!-- Badge de validation -->
            <?php
            $validation_status = $document['validation_status'] ?? null;
            $validated_by_username = $document['validated_by_username'] ?? null;
            $validated_at = $document['validated_at'] ?? null;
            $size = 'md';
            $show_details = true;
            ?>
            <?php include __DIR__ . '/../components/validation_badge.php'; ?>
            <?php if ($canPreview && $isPDF): ?>
            <div class="hidden md:flex items-center gap-2 text-sm text-gray-600">
                <button onclick="previousPDFPage()" class="px-2 py-1 border border-gray-300 rounded hover:bg-gray-50" title="Page précédente">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                    </svg>
                </button>
                <span id="page-info-header" class="px-2">Page 1 sur 1</span>
                <button onclick="nextPDFPage()" class="px-2 py-1 border border-gray-300 rounded hover:bg-gray-50" title="Page suivante">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                </button>
                <div class="flex items-center gap-1">
                    <button onclick="decreaseZoom()" class="px-2 py-1 border border-gray-300 rounded hover:bg-gray-50">-</button>
                    <select id="zoom-select-header" onchange="setZoomFromSelect(this.value)" class="text-sm border border-gray-300 rounded px-2 py-1">
                        <option value="0.5">50%</option>
                        <option value="0.75">75%</option>
                        <option value="1.0" selected>100%</option>
                        <option value="1.25">125%</option>
                        <option value="1.5">150%</option>
                        <option value="2.0">200%</option>
                        <option value="fit-width">Ajuster largeur</option>
                        <option value="fit-page">Ajuster page</option>
                    </select>
                    <button onclick="increaseZoom()" class="px-2 py-1 border border-gray-300 rounded hover:bg-gray-50">+</button>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Actions -->
        <div class="flex items-center gap-2">
            <form method="POST" action="<?= url('/documents/' . $document['id'] . '/delete') ?>" 
                  onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer ce document ?');" class="inline">
                <button type="submit" class="px-3 py-1.5 text-sm border border-red-300 text-red-700 rounded hover:bg-red-50" title="Supprimer">
                    <svg class="w-4 h-4 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                    </svg>
                    <span class="hidden lg:inline ml-1">Supprimer</span>
                </button>
            </form>
            
            <div class="relative" id="download-dropdown">
                <button onclick="toggleDropdown('download-dropdown')" class="px-3 py-1.5 text-sm border border-blue-300 text-blue-700 rounded hover:bg-blue-50 flex items-center gap-1">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                    </svg>
                    <span class="hidden lg:inline">Télécharger</span>
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                    </svg>
                </button>
                <div class="hidden absolute right-0 mt-1 bg-white border border-gray-200 rounded shadow-lg z-10 min-w-[200px]">
                    <a href="<?= url('/documents/' . $document['id'] . '/download') ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                        Télécharger original
                    </a>
                </div>
            </div>
            
            <div class="relative" id="actions-dropdown">
                <button onclick="toggleDropdown('actions-dropdown')" class="px-3 py-1.5 text-sm border border-blue-300 text-blue-700 rounded hover:bg-blue-50 flex items-center gap-1">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z"></path>
                    </svg>
                    <span class="hidden lg:inline">Actions</span>
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                    </svg>
                </button>
                <div class="hidden absolute right-0 mt-1 bg-white border border-gray-200 rounded shadow-lg z-10 min-w-[200px]">
                    <button onclick="reprocessDocument(<?= $document['id'] ?>)" class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                        Retraiter (OCR)
                    </button>
                    <?php if ($isPDF): ?>
                    <button onclick="printDocument()" class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                        Imprimer
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="relative" id="custom-fields-dropdown">
                <button onclick="toggleDropdown('custom-fields-dropdown')" class="px-3 py-1.5 text-sm border border-blue-300 text-blue-700 rounded hover:bg-blue-50">
                    <span class="hidden lg:inline">Champs personnalisés</span>
                    <span class="lg:hidden">Champs</span>
                </button>
                <div class="hidden absolute right-0 mt-1 bg-white border border-gray-200 rounded shadow-lg z-10 min-w-[250px] p-3">
                    <p class="text-sm text-gray-600 mb-2">Ajouter un champ personnalisé</p>
                    <select id="custom-field-select" class="w-full text-sm border border-gray-300 rounded px-2 py-1 mb-2">
                        <option value="">Sélectionner un champ...</option>
                        <?php
                        try {
                            $customFields = \KDocs\Models\CustomField::all();
                            foreach ($customFields as $cf) {
                                echo '<option value="' . $cf['id'] . '">' . htmlspecialchars($cf['name']) . '</option>';
                            }
                        } catch (\Exception $e) {}
                        ?>
                    </select>
                    <button onclick="addCustomField()" class="w-full px-3 py-1.5 text-sm bg-blue-600 text-white rounded hover:bg-blue-700">
                        Ajouter
                    </button>
                </div>
            </div>
            
            <div class="relative" id="send-dropdown">
                <button onclick="toggleDropdown('send-dropdown')" class="px-3 py-1.5 text-sm border border-blue-300 text-blue-700 rounded hover:bg-blue-50 flex items-center gap-1">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"></path>
                    </svg>
                    <span class="hidden lg:inline">Envoyer</span>
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                    </svg>
                </button>
                <div class="hidden absolute right-0 mt-1 bg-white border border-gray-200 rounded shadow-lg z-10 min-w-[200px]">
                    <button onclick="openNoteModal(<?= $document['id'] ?>)" class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                        Envoyer une note
                    </button>
                    <button onclick="openShareLinks(<?= $document['id'] ?>)" class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                        Liens de partage
                    </button>
                    <button onclick="openEmailDocument(<?= $document['id'] ?>)" class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                        Email
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Layout deux colonnes -->
<div class="flex flex-col md:flex-row h-[calc(100vh-120px)] overflow-hidden">
    <!-- Colonne gauche : Formulaire avec onglets -->
    <div class="w-full md:w-5/12 lg:w-2/5 border-r border-gray-200 bg-white overflow-y-auto">
        <form id="document-form" method="POST" action="<?= url('/documents/' . $document['id'] . '/edit') ?>">
            <!-- Barre d'outils -->
            <div class="flex items-center justify-between px-4 py-2 border-b border-gray-200">
                <div class="flex items-center gap-1">
                    <a href="<?= url('/documents') ?>" class="p-1.5 text-gray-600 hover:bg-gray-100 rounded" title="Fermer">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </a>
                    <a href="<?= $previousId ? url('/documents/' . $previousId) : '#' ?>" 
                       class="p-1.5 text-gray-600 hover:bg-gray-100 rounded <?= !$previousId ? 'opacity-50 cursor-not-allowed' : '' ?>" 
                       title="Précédent"
                       <?= !$previousId ? 'onclick="return false;"' : '' ?>>
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                        </svg>
                    </a>
                    <a href="<?= $nextId ? url('/documents/' . $nextId) : '#' ?>" 
                       class="p-1.5 text-gray-600 hover:bg-gray-100 rounded <?= !$nextId ? 'opacity-50 cursor-not-allowed' : '' ?>" 
                       title="Suivant"
                       <?= !$nextId ? 'onclick="return false;"' : '' ?>>
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                        </svg>
                    </a>
                </div>
                
                <div class="flex items-center gap-2">
                    <?php if ($aiAvailable): ?>
                    <button type="button" onclick="getAISuggestions(<?= $document['id'] ?>)"
                            class="px-2 py-1 text-xs border border-purple-300 text-purple-700 rounded hover:bg-purple-50"
                            title="Analyse le document avec l'IA pour suggérer titre, type, correspondant et tags">
                        Suggestions IA
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Onglets -->
            <div class="border-b border-gray-200">
                <nav class="flex overflow-x-auto" id="document-tabs">
                    <button type="button" onclick="switchTab('details')" class="tab-button active px-4 py-2 text-sm font-medium border-b-2 border-blue-600 text-blue-600">
                        Détails
                    </button>
                    <button type="button" onclick="switchTab('content')" class="tab-button px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-900">
                        Contenu
                    </button>
                    <button type="button" onclick="switchTab('metadata')" class="tab-button px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-900">
                        Métadonnées
                    </button>
                    <button type="button" onclick="switchTab('notes')" class="tab-button px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-900">
                        Notes <?php if (count($notes) > 0): ?><span class="ml-1 px-1.5 py-0.5 text-xs bg-gray-200 rounded"><?= count($notes) ?></span><?php endif; ?>
                    </button>
                    <button type="button" onclick="switchTab('history')" class="tab-button px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-900">
                        Historique
                    </button>
                </nav>
            </div>
            
            <!-- Contenu des onglets -->
            <div class="p-4">
                <!-- Onglet Détails -->
                <div id="tab-details" class="tab-content">
                    <!-- Statut de validation -->
                    <div class="mb-6 p-4 bg-gray-50 rounded-lg border border-gray-200">
                        <h4 class="text-sm font-medium text-gray-900 mb-3">Validation</h4>
                        <?php
                        $document_id = $document['id'];
                        $validation_status = $document['validation_status'] ?? null;
                        $can_validate = $canValidate ?? true;
                        $requires_approval = $document['requires_approval'] ?? false;
                        $show_submit = false;
                        $basePath = $base;
                        ?>
                        <?php include __DIR__ . '/../components/validation_actions.php'; ?>
                    </div>

                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Titre</label>
                            <input type="text" name="title" value="<?= htmlspecialchars($document['title'] ?? '') ?>" 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Numéro de série d'archivage (NSA)</label>
                            <input type="number" name="asn" value="<?= htmlspecialchars($document['asn'] ?? '') ?>" 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Date du document</label>
                            <input type="date" name="document_date" value="<?= ($document['document_date'] ?? $document['doc_date'] ?? null) ? date('Y-m-d', strtotime($document['document_date'] ?? $document['doc_date'])) : '' ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Correspondant</label>
                            <select name="correspondent_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                <option value="">Non défini</option>
                                <?php foreach ($correspondents as $corr): ?>
                                <option value="<?= $corr['id'] ?>" <?= ($document['correspondent_id'] ?? null) == $corr['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($corr['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Type de document</label>
                            <select name="document_type_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                <option value="">Non défini</option>
                                <?php foreach ($documentTypes as $dt): ?>
                                <option value="<?= $dt['id'] ?>" <?= ($document['document_type_id'] ?? null) == $dt['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($dt['label']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Chemin de stockage</label>
                            <select name="storage_path_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                <option value="">Par défaut</option>
                                <?php foreach ($storagePaths as $sp): ?>
                                <option value="<?= $sp['id'] ?>" <?= ($document['storage_path_id'] ?? null) == $sp['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($sp['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Étiquettes</label>
                            <div id="tags-container" class="flex flex-wrap gap-2 mb-2">
                                <?php foreach ($tags as $tag): ?>
                                <span class="inline-flex items-center px-2 py-1 text-xs rounded-full" 
                                      style="background-color: <?= htmlspecialchars($tag['color'] ?? '#6b7280') ?>20; color: <?= htmlspecialchars($tag['color'] ?? '#6b7280') ?>">
                                    <?= htmlspecialchars($tag['name']) ?>
                                    <button type="button" onclick="removeTag(<?= $tag['id'] ?>)" class="ml-1 hover:font-bold">×</button>
                                </span>
                                <?php endforeach; ?>
                            </div>
                            <select id="tag-select" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                <option value="">Ajouter une étiquette...</option>
                                <?php foreach ($allTags as $tag): ?>
                                <?php if (!in_array($tag['id'], array_column($tags, 'id'))): ?>
                                <option value="<?= $tag['id'] ?>"><?= htmlspecialchars($tag['name']) ?></option>
                                <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Custom Fields existants -->
                        <?php
                        try {
                            $customFieldValues = \KDocs\Models\CustomField::getValuesForDocument($document['id']);
                            foreach ($customFieldValues as $cfv):
                                $field = \KDocs\Models\CustomField::findById($cfv['custom_field_id']);
                                if (!$field) continue;
                        ?>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                <?= htmlspecialchars($field['name']) ?>
                                <button type="button" onclick="removeCustomField(<?= $cfv['id'] ?>)" class="ml-2 text-red-600 hover:text-red-800 text-xs">×</button>
                            </label>
                            <?php if ($field['data_type'] === 'boolean'): ?>
                            <input type="checkbox" name="custom_fields[<?= $field['id'] ?>]" value="1" <?= $cfv['value'] ? 'checked' : '' ?>>
                            <?php elseif ($field['data_type'] === 'date'): ?>
                            <input type="date" name="custom_fields[<?= $field['id'] ?>]" value="<?= htmlspecialchars($cfv['value'] ?? '') ?>" 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                            <?php elseif ($field['data_type'] === 'integer' || $field['data_type'] === 'float'): ?>
                            <input type="number" name="custom_fields[<?= $field['id'] ?>]" value="<?= htmlspecialchars($cfv['value'] ?? '') ?>" 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                            <?php else: ?>
                            <input type="text" name="custom_fields[<?= $field['id'] ?>]" value="<?= htmlspecialchars($cfv['value'] ?? '') ?>" 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                            <?php endif; ?>
                        </div>
                        <?php
                            endforeach;
                        } catch (\Exception $e) {}
                        ?>
                    </div>
                    
                    <div class="mt-6 pt-4 border-t border-gray-200">
                        <div class="flex gap-2">
                            <button type="button" onclick="discardChanges()" class="px-4 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50">
                                Abandonner
                            </button>
                            <?php if ($nextId): ?>
                            <button type="submit" name="save_and_next" value="1" class="px-4 py-2 text-sm bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                                Enregistrer & suivant
                            </button>
                            <?php else: ?>
                            <button type="submit" name="save_and_close" value="1" class="px-4 py-2 text-sm bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                                Enregistrer & fermer
                            </button>
                            <?php endif; ?>
                            <button type="submit" class="px-4 py-2 text-sm bg-green-600 text-white rounded-lg hover:bg-green-700">
                                Enregistrer
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Onglet Contenu -->
                <div id="tab-content" class="tab-content hidden">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Contenu extrait (OCR)</label>
                        <textarea name="ocr_text" rows="20" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 font-mono text-sm"><?= htmlspecialchars($document['ocr_text'] ?? '') ?></textarea>
                    </div>
                </div>
                
                <!-- Onglet Métadonnées -->
                <div id="tab-metadata" class="tab-content hidden">
                    <table class="w-full text-sm">
                        <tbody class="divide-y divide-gray-200">
                            <tr>
                                <td class="py-2 text-gray-600 font-medium">Date modifiée</td>
                                <td class="py-2 text-gray-900"><?= $document['updated_at'] ? date('d/m/Y H:i', strtotime($document['updated_at'])) : '-' ?></td>
                            </tr>
                            <tr>
                                <td class="py-2 text-gray-600 font-medium">Date ajoutée</td>
                                <td class="py-2 text-gray-900"><?= date('d/m/Y H:i', strtotime($document['created_at'])) ?></td>
                            </tr>
                            <tr>
                                <td class="py-2 text-gray-600 font-medium">Nom du fichier</td>
                                <td class="py-2 text-gray-900 font-mono text-xs"><?= htmlspecialchars($document['filename'] ?? '') ?></td>
                            </tr>
                            <tr>
                                <td class="py-2 text-gray-600 font-medium">Fichier original</td>
                                <td class="py-2 text-gray-900 font-mono text-xs"><?= htmlspecialchars($document['original_filename'] ?? '') ?></td>
                            </tr>
                            <tr>
                                <td class="py-2 text-gray-600 font-medium">Taille du fichier</td>
                                <td class="py-2 text-gray-900"><?= $document['file_size'] ? number_format($document['file_size'] / 1024, 2) . ' KB' : '-' ?></td>
                            </tr>
                            <tr>
                                <td class="py-2 text-gray-600 font-medium">Type MIME</td>
                                <td class="py-2 text-gray-900"><?= htmlspecialchars($document['mime_type'] ?? '') ?></td>
                            </tr>
                            <?php if (!empty($document['checksum'])): ?>
                            <tr>
                                <td class="py-2 text-gray-600 font-medium">Checksum MD5</td>
                                <td class="py-2 text-gray-900 font-mono text-xs"><?= htmlspecialchars($document['checksum']) ?></td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Onglet Notes -->
                <div id="tab-notes" class="tab-content hidden">
                    <div class="space-y-4">
                        <?php if (empty($notes)): ?>
                        <p class="text-gray-500 text-sm">Aucune note pour ce document.</p>
                        <?php else: ?>
                        <?php foreach ($notes as $note): ?>
                        <div class="bg-gray-50 rounded-lg p-4 border border-gray-200">
                            <div class="flex items-start justify-between mb-2">
                                <p class="text-sm text-gray-700 whitespace-pre-wrap flex-1"><?= nl2br(htmlspecialchars($note['note'] ?? $note['content'] ?? '')) ?></p>
                                <button onclick="deleteNote(<?= $note['id'] ?>)" class="ml-2 text-red-600 hover:text-red-800 text-sm">×</button>
                            </div>
                            <div class="text-xs text-gray-500 mt-2">
                                <?php if (!empty($note['user_name'])): ?>
                                Par <?= htmlspecialchars($note['user_name']) ?> • 
                                <?php endif; ?>
                                <?= date('d/m/Y à H:i', strtotime($note['created_at'])) ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                        
                        <div class="border-t pt-4">
                            <h4 class="text-sm font-medium text-gray-700 mb-2">Ajouter une note</h4>
                            <textarea id="new-note-text" rows="4" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"></textarea>
                            <button onclick="addNote()" class="mt-2 px-4 py-2 text-sm bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                                Ajouter
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Onglet Historique -->
                <div id="tab-history" class="tab-content hidden">
                    <div id="history-content">
                        <p class="text-gray-500 text-sm">Chargement de l'historique...</p>
                    </div>
                </div>
            </div>
        </form>
    </div>
    
    <!-- Colonne droite : Prévisualisation -->
    <div class="w-full md:w-7/12 lg:w-3/5 bg-gray-50 overflow-hidden relative">
        <?php if ($canPreview): ?>
        <div id="preview-container" class="h-full w-full overflow-auto bg-gray-100">
            <?php if ($isPDF): ?>
            <!-- Prévisualisation PDF -->
            <div id="pdf-preview" class="h-full flex items-center justify-center p-4">
                <div id="pdf-loading" class="text-center">
                    <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600 mb-3"></div>
                    <p class="text-gray-600">Chargement du PDF...</p>
                </div>
                <canvas id="pdf-canvas" class="hidden max-w-full"></canvas>
            </div>
            <?php else: ?>
            <!-- Prévisualisation Image -->
            <div class="h-full flex items-center justify-center p-4">
                <img src="<?= url('/documents/' . $document['id'] . '/view') ?>" 
                     alt="<?= htmlspecialchars($document['title'] ?: $document['original_filename']) ?>"
                     class="max-w-full max-h-full object-contain"
                     onerror="this.parentElement.innerHTML='<div class=\'text-center text-gray-500\'><p>Erreur de chargement de l\'image</p></div>'">
            </div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="h-full flex items-center justify-center text-gray-500">
            <div class="text-center">
                <svg class="w-16 h-16 mx-auto mb-3 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
                <p>Aucune prévisualisation disponible</p>
                <a href="<?= url('/documents/' . $document['id'] . '/download') ?>" class="mt-2 inline-block px-4 py-2 text-sm bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                    Télécharger
                </a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
.tab-button {
    white-space: nowrap;
    transition: all 0.2s;
}
.tab-button.active {
    border-bottom-color: #2563eb;
    color: #2563eb;
}
.tab-content {
    display: block;
}
.tab-content.hidden {
    display: none;
}
</style>

<script>
// Gestion des onglets
function switchTab(tabName) {
    // Masquer tous les contenus
    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.add('hidden');
    });
    
    // Désactiver tous les boutons
    document.querySelectorAll('.tab-button').forEach(btn => {
        btn.classList.remove('active', 'border-b-2', 'border-blue-600', 'text-blue-600');
        btn.classList.add('text-gray-600');
    });
    
    // Afficher le contenu sélectionné
    const content = document.getElementById('tab-' + tabName);
    if (content) {
        content.classList.remove('hidden');
    }
    
    // Activer le bouton
    const buttons = document.querySelectorAll('.tab-button');
    buttons.forEach(btn => {
        if (btn.textContent.trim().includes(tabName === 'details' ? 'Détails' : 
                                            tabName === 'content' ? 'Contenu' : 
                                            tabName === 'metadata' ? 'Métadonnées' : 
                                            tabName === 'notes' ? 'Notes' : 'Historique')) {
            btn.classList.add('active', 'border-b-2', 'border-blue-600', 'text-blue-600');
            btn.classList.remove('text-gray-600');
        }
    });
    
    // Charger l'historique si nécessaire
    if (tabName === 'history') {
        loadHistory();
    }
}

// Dropdowns
function toggleDropdown(id) {
    const dropdown = document.getElementById(id);
    const menu = dropdown.querySelector('.hidden');
    if (menu) {
        menu.classList.toggle('hidden');
    }
}

// Fermer les dropdowns au clic extérieur
document.addEventListener('click', function(e) {
    if (!e.target.closest('[id$="-dropdown"]')) {
        document.querySelectorAll('[id$="-dropdown"] .hidden').forEach(menu => {
            if (!menu.classList.contains('hidden')) {
                menu.classList.add('hidden');
            }
        });
    }
});

// Gestion des tags
document.getElementById('tag-select')?.addEventListener('change', function() {
    if (this.value) {
        addTag(this.value);
        this.value = '';
    }
});

function addTag(tagId) {
    // TODO: Implémenter l'ajout de tag via API
    console.log('Ajouter tag:', tagId);
}

function removeTag(tagId) {
    // TODO: Implémenter la suppression de tag via API
    console.log('Supprimer tag:', tagId);
}

// Gestion des notes
function addNote() {
    const text = document.getElementById('new-note-text').value;
    if (!text.trim()) return;
    
    fetch('<?= url('/api/documents/' . $document['id'] . '/notes') ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ note: text })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            window.location.reload();
        }
    });
}

function deleteNote(noteId) {
    if (!confirm('Supprimer cette note ?')) return;
    
    fetch('<?= url('/api/documents/' . $document['id'] . '/notes/') ?>' + noteId, {
        method: 'DELETE'
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            window.location.reload();
        }
    });
}

// Charger l'historique
function loadHistory() {
    fetch('<?= url('/documents/' . $document['id'] . '/history') ?>', {
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(r => r.text())
    .then(html => {
        document.getElementById('history-content').innerHTML = html;
    })
    .catch(err => {
        document.getElementById('history-content').innerHTML = '<p class="text-red-500 text-sm">Erreur lors du chargement de l\'historique</p>';
        console.error('Erreur chargement historique:', err);
    });
}

// Prévisualisation PDF
<?php if ($isPDF): ?>
let pdfDoc = null;
let currentPage = 1;
let totalPages = 1;
let currentScale = 1.5;

function initPDFPreview() {
    const pdfUrl = '<?= url('/documents/' . $document['id'] . '/view') ?>';
    
    // Utiliser PDF.js pour charger le PDF
    if (typeof pdfjsLib === 'undefined') {
        // Charger le worker d'abord
        const workerScript = document.createElement('script');
        workerScript.src = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
        
        const script = document.createElement('script');
        script.src = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js';
        script.onload = () => {
            if (typeof pdfjsLib !== 'undefined') {
                pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
            }
            loadPDF(pdfUrl);
        };
        script.onerror = () => {
            const loadingDiv = document.getElementById('pdf-loading');
            if (loadingDiv) {
                loadingDiv.innerHTML = '<p class="text-red-500">Erreur de chargement de PDF.js</p>';
            }
        };
        document.head.appendChild(workerScript);
        document.head.appendChild(script);
    } else {
        if (pdfjsLib.GlobalWorkerOptions) {
            pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
        }
        loadPDF(pdfUrl);
    }
}

function loadPDF(url) {
    const loadingDiv = document.getElementById('pdf-loading');
    if (loadingDiv) {
        loadingDiv.innerHTML = '<div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600 mb-3"></div><p class="text-gray-600">Chargement du PDF...</p>';
    }
    
    pdfjsLib.getDocument(url).promise.then(pdf => {
        pdfDoc = pdf;
        totalPages = pdf.numPages;
        updatePageInfo();
        renderPage(1);
        
        // Masquer le loader et afficher le canvas
        const loadingDiv = document.getElementById('pdf-loading');
        const canvas = document.getElementById('pdf-canvas');
        if (loadingDiv) loadingDiv.classList.add('hidden');
        if (canvas) canvas.classList.remove('hidden');
    }).catch(err => {
        console.error('Erreur chargement PDF:', err);
        const loadingDiv = document.getElementById('pdf-loading');
        if (loadingDiv) {
            loadingDiv.innerHTML = '<p class="text-red-500">Erreur lors du chargement du PDF</p>';
        }
    });
}

function renderPage(num) {
    if (!pdfDoc) return;
    const canvas = document.getElementById('pdf-canvas');
    if (!canvas) return;
    
    pdfDoc.getPage(num).then(page => {
        const viewport = page.getViewport({ scale: currentScale });
        canvas.height = viewport.height;
        canvas.width = viewport.width;
        
        const ctx = canvas.getContext('2d');
        const renderContext = {
            canvasContext: ctx,
            viewport: viewport
        };
        
        page.render(renderContext).promise.then(() => {
            currentPage = num;
            updatePageInfo();
        });
    }).catch(err => {
        console.error('Erreur rendu page:', err);
    });
}

function previousPDFPage() {
    if (currentPage > 1) {
        renderPage(currentPage - 1);
    }
}

function nextPDFPage() {
    if (currentPage < totalPages) {
        renderPage(currentPage + 1);
    }
}

function updatePageInfo() {
    const info = document.getElementById('page-info-header');
    if (info) {
        info.textContent = `Page ${currentPage} sur ${totalPages}`;
    }
}

function decreaseZoom() {
    currentScale = Math.max(0.5, currentScale - 0.25);
    if (pdfDoc && currentPage) {
        renderPage(currentPage);
    }
}

function increaseZoom() {
    currentScale = Math.min(3.0, currentScale + 0.25);
    if (pdfDoc && currentPage) {
        renderPage(currentPage);
    }
}

function setZoomFromSelect(value) {
    if (value === 'fit-width' || value === 'fit-page') {
        // TODO: Implémenter ajustement automatique
        return;
    }
    currentScale = parseFloat(value);
    if (pdfDoc && currentPage) {
        renderPage(currentPage);
    }
}

// Initialiser la prévisualisation au chargement
document.addEventListener('DOMContentLoaded', function() {
    initPDFPreview();
});
<?php endif; ?>

// Autres fonctions
function discardChanges() {
    if (confirm('Abandonner les modifications non enregistrées ?')) {
        window.location.reload();
    }
}

async function getAISuggestions(docId) {
    const btn = event.target;
    const originalHTML = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="animate-pulse">Analyse...</span>';
    
    try {
        // L'API existe déjà : /api/documents/{id}/classify-ai
        const response = await fetch(`<?= url('/api/documents/') ?>${docId}/classify-ai`, {
            method: 'POST',
            headers: { 
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            }
        });
        
        const data = await response.json();
        
        if (!response.ok || data.error) {
            alert('Erreur: ' + (data.error || data.message || 'Erreur inconnue'));
            return;
        }
        
        const suggestions = data.suggestions || data.data?.suggestions;
        if (!suggestions) {
            alert('Aucune suggestion disponible. Vérifiez que :\n1. La clé API Claude est configurée (Paramètres > IA)\n2. Le document contient du texte lisible');
            return;
        }
        
        // Construire le message
        const msg = [];
        if (suggestions.title_suggestion) msg.push(`📝 Titre: ${suggestions.title_suggestion}`);
        if (suggestions.correspondent) msg.push(`👤 Correspondant: ${suggestions.correspondent}`);
        if (suggestions.document_type) msg.push(`📁 Type: ${suggestions.document_type}`);
        if (suggestions.tags && suggestions.tags.length) msg.push(`🏷️ Tags: ${suggestions.tags.join(', ')}`);
        if (suggestions.document_date) msg.push(`📅 Date: ${suggestions.document_date}`);
        if (suggestions.amount) msg.push(`💰 Montant: ${suggestions.amount} CHF`);
        if (suggestions.confidence) msg.push(`\n📊 Confiance: ${Math.round(suggestions.confidence * 100)}%`);
        
        if (msg.length === 0) {
            alert('L\'IA n\'a pas pu extraire de suggestions pour ce document.');
            return;
        }
        
        const apply = confirm('🤖 Suggestions IA :\n\n' + msg.join('\n') + '\n\nAppliquer ces suggestions ?');
        
        if (apply) {
            // Appeler l'API pour appliquer les suggestions
            const applyResponse = await fetch(`<?= url('/api/documents/') ?>${docId}/apply-ai-suggestions`, {
                method: 'POST',
                headers: { 
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                }
            });
            
            const applyData = await applyResponse.json();
            
            if (applyResponse.ok && !applyData.error) {
                alert('✅ Suggestions appliquées ! La page va se recharger.');
                window.location.reload();
            } else {
                alert('Erreur lors de l\'application: ' + (applyData.error || 'Erreur inconnue'));
            }
        }
    } catch (error) {
        console.error('Erreur suggestions IA:', error);
        alert('Erreur de connexion: ' + error.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalHTML;
    }
}

function addCustomField() {
    // TODO: Implémenter ajout champ personnalisé
    alert('Ajout champ personnalisé à implémenter');
}

function removeCustomField(fieldValueId) {
    // TODO: Implémenter suppression champ personnalisé
    alert('Suppression champ personnalisé à implémenter');
}

function reprocessDocument(docId) {
    // TODO: Implémenter retraitement
    alert('Retraitement à implémenter');
}

function printDocument() {
    window.print();
}

function openShareLinks(docId) {
    // TODO: Implémenter liens de partage
    alert('Liens de partage à implémenter');
}

function openEmailDocument(docId) {
    // TODO: Implémenter email
    alert('Email à implémenter');
}

// Note modal functions
function openNoteModal(documentId = null) {
    const modal = document.getElementById('noteModal');
    const docIdInput = document.getElementById('noteDocumentId');
    if (docIdInput) docIdInput.value = documentId || '';
    if (modal) {
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }
}

function closeNoteModal() {
    const modal = document.getElementById('noteModal');
    const form = document.getElementById('noteForm');
    const actionTypeContainer = document.getElementById('actionTypeContainer');
    if (modal) {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }
    if (form) form.reset();
    if (actionTypeContainer) actionTypeContainer.classList.add('hidden');
}

async function sendNote(event) {
    event.preventDefault();
    const form = event.target;
    const formData = new FormData(form);

    const data = {
        to_user_id: parseInt(formData.get('to_user_id')),
        message: formData.get('message'),
        subject: formData.get('subject') || null,
        document_id: formData.get('document_id') ? parseInt(formData.get('document_id')) : null,
        action_required: formData.get('action_required') === '1',
        action_type: formData.get('action_type') || null
    };

    try {
        const response = await fetch('<?= url('/api/notes') ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        const result = await response.json();
        if (result.success) {
            closeNoteModal();
            alert('Note envoyée avec succès');
        } else {
            alert(result.error || 'Erreur lors de l\'envoi');
        }
    } catch (error) {
        console.error('Error sending note:', error);
        alert('Erreur de connexion');
    }
}

document.getElementById('noteActionRequired')?.addEventListener('change', function() {
    const container = document.getElementById('actionTypeContainer');
    if (container) container.classList.toggle('hidden', !this.checked);
});
</script>

<!-- Modal envoi de note -->
<?php
$document_id = $document['id'];
$recipients = [];
try {
    $noteService = new \KDocs\Services\UserNoteService();
    $recipients = $noteService->getAvailableRecipients($user['id'] ?? 0);
} catch (\Exception $e) {}
include __DIR__ . '/../components/note_form.php';
?>

