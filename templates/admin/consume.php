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
        </div>
        <div class="flex gap-2">
            <span class="px-3 py-1 bg-blue-100 text-blue-800 rounded-full text-sm">
                <?= $filesCount ?> fichier(s) à importer
            </span>
            <form method="POST" action="<?= url('/admin/consume/scan') ?>">
                <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700">
                    🔄 Scanner
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
        <div class="bg-white rounded-lg shadow-lg border border-gray-200">
            <form method="POST" action="<?= url('/admin/consume/validate/' . $doc['id']) ?>" class="p-6">
                <div class="grid grid-cols-12 gap-6">
                    <!-- Colonne gauche : Aperçu et contenu -->
                    <div class="col-span-4">
                        <!-- Miniature -->
                        <div class="mb-4">
                            <label class="text-xs font-semibold text-gray-700 mb-2 block">Aperçu</label>
                            <?php if ($hasThumbnail): ?>
                            <img src="<?= htmlspecialchars($doc['thumbnail_url']) ?>" 
                                 alt="Aperçu" 
                                 class="w-full border rounded-lg shadow-sm cursor-pointer hover:shadow-md transition"
                                 onclick="window.open('<?= url('/documents/' . $doc['id'] . '/view') ?>', '_blank')">
                            <?php else: ?>
                            <div class="w-full h-64 bg-gray-100 rounded-lg flex items-center justify-center border-2 border-dashed border-gray-300">
                                <div class="text-center text-gray-400">
                                    <i class="fas fa-file-pdf text-4xl mb-2"></i>
                                    <p class="text-sm">Miniature non disponible</p>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Informations fichier -->
                        <div class="text-xs text-gray-500 space-y-1 mb-4">
                            <div><strong>Fichier:</strong> <?= htmlspecialchars($doc['original_filename']) ?></div>
                            <div><strong>Taille:</strong> <?= number_format($doc['file_size'] / 1024, 2) ?> KB</div>
                            <div><strong>Méthode:</strong> <?= htmlspecialchars($suggestions['method_used'] ?? 'non classifié') ?></div>
                            <div><strong>Confiance:</strong> 
                                <span class="px-2 py-0.5 rounded <?= ($suggestions['confidence'] ?? 0) >= 0.7 ? 'bg-green-100 text-green-800' : (($suggestions['confidence'] ?? 0) >= 0.4 ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800') ?>">
                                    <?= round(($suggestions['confidence'] ?? 0) * 100) ?>%
                                </span>
                            </div>
                        </div>
                        
                        <!-- Contenu OCR -->
                        <?php if (!empty($doc['content_preview'])): ?>
                        <div class="mb-4">
                            <label class="text-xs font-semibold text-gray-700 mb-2 block">Contenu extrait (OCR)</label>
                            <div class="bg-gray-50 border rounded p-3 max-h-48 overflow-y-auto text-xs text-gray-700">
                                <?= nl2br(htmlspecialchars($doc['content_preview'])) ?>
                                <?php if (strlen($doc['content'] ?? $doc['ocr_text'] ?? '') > 500): ?>
                                <span class="text-gray-400 italic">...</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Colonne droite : Formulaire de validation -->
                    <div class="col-span-8">
                        <div class="grid grid-cols-2 gap-4">
                            <!-- Titre -->
                            <div class="col-span-2">
                                <label class="text-xs font-semibold text-gray-700 mb-1 block">Titre du document</label>
                                <input type="text" name="title" 
                                       value="<?= htmlspecialchars($final['title'] ?? $doc['title'] ?? '') ?>" 
                                       class="w-full px-3 py-2 border rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            </div>
                            
                            <!-- Date -->
                            <div>
                                <label class="text-xs font-semibold text-gray-700 mb-1 block">Date du document</label>
                                <input type="date" name="doc_date" 
                                       value="<?= htmlspecialchars($final['doc_date'] ?? $doc['doc_date'] ?? '') ?>" 
                                       class="w-full px-3 py-2 border rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            </div>
                            
                            <!-- Montant -->
                            <div>
                                <label class="text-xs font-semibold text-gray-700 mb-1 block">Montant</label>
                                <input type="number" step="0.01" name="amount" 
                                       value="<?= htmlspecialchars($final['amount'] ?? $doc['amount'] ?? '') ?>" 
                                       class="w-full px-3 py-2 border rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            </div>
                            
                            <!-- Correspondant -->
                            <div>
                                <label class="text-xs font-semibold text-gray-700 mb-1 block">
                                    Correspondant / Fournisseur
                                    <?php if (!empty($final['correspondent_name'])): ?>
                                    <span class="text-green-600 text-xs">(suggéré: <?= htmlspecialchars($final['correspondent_name']) ?>)</span>
                                    <?php endif; ?>
                                </label>
                                <select name="correspondent_id" 
                                        class="w-full px-3 py-2 border rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                    <option value="">-- Sélectionner --</option>
                                    <?php foreach ($correspondents as $c): ?>
                                    <option value="<?= $c['id'] ?>" 
                                            <?= ($final['correspondent_id'] ?? $doc['correspondent_id']) == $c['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($c['name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- Type de document -->
                            <div>
                                <label class="text-xs font-semibold text-gray-700 mb-1 block">
                                    Type de document
                                    <?php if (!empty($final['document_type_name'])): ?>
                                    <span class="text-green-600 text-xs">(suggéré: <?= htmlspecialchars($final['document_type_name']) ?>)</span>
                                    <?php endif; ?>
                                </label>
                                <select name="document_type_id" 
                                        class="w-full px-3 py-2 border rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                    <option value="">-- Sélectionner --</option>
                                    <?php foreach ($documentTypes as $t): ?>
                                    <option value="<?= $t['id'] ?>" 
                                            <?= ($final['document_type_id'] ?? $doc['document_type_id']) == $t['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($t['label']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- Tags -->
                            <div class="col-span-2">
                                <label class="text-xs font-semibold text-gray-700 mb-1 block">
                                    Tags
                                    <?php if (!empty($final['tag_names'])): ?>
                                    <span class="text-green-600 text-xs">(suggérés: <?= htmlspecialchars(implode(', ', $final['tag_names'])) ?>)</span>
                                    <?php endif; ?>
                                </label>
                                <select name="tags[]" multiple 
                                        class="w-full px-3 py-2 border rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" 
                                        size="3">
                                    <?php foreach ($tags as $tag): ?>
                                    <option value="<?= $tag['id'] ?>" 
                                            <?= in_array($tag['id'], $final['tag_ids'] ?? []) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($tag['name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- Emplacement de stockage -->
                            <div class="col-span-2 border-t pt-4 mt-2">
                                <label class="text-xs font-semibold text-gray-700 mb-2 block">
                                    📁 Emplacement de stockage
                                    <?php if (!empty($doc['suggested_path'])): ?>
                                    <span class="text-blue-600 text-xs">(suggéré: <?= htmlspecialchars($doc['suggested_path']) ?>)</span>
                                    <?php endif; ?>
                                </label>
                                
                                <!-- Chemin suggéré automatique -->
                                <div class="mb-3 p-3 bg-blue-50 border border-blue-200 rounded-md">
                                    <div class="flex items-center gap-2 mb-2">
                                        <input type="radio" name="storage_path_option" value="suggested" id="path_suggested_<?= $doc['id'] ?>" checked class="storage-path-radio">
                                        <label for="path_suggested_<?= $doc['id'] ?>" class="text-sm font-medium text-gray-700 cursor-pointer">
                                            Chemin suggéré automatique
                                        </label>
                                    </div>
                                    <div class="ml-6 text-xs text-gray-600 font-mono">
                                        <?= htmlspecialchars($doc['suggested_path'] ?? 'Aucune suggestion') ?>
                                    </div>
                                </div>
                                
                                <!-- Chemin personnalisé -->
                                <div class="mb-3">
                                    <div class="flex items-center gap-2 mb-2">
                                        <input type="radio" name="storage_path_option" value="custom" id="path_custom_<?= $doc['id'] ?>" class="storage-path-radio">
                                        <label for="path_custom_<?= $doc['id'] ?>" class="text-sm font-medium text-gray-700 cursor-pointer">
                                            Chemin personnalisé
                                        </label>
                                    </div>
                                    <input type="text" 
                                           name="storage_path_custom" 
                                           id="custom_path_<?= $doc['id'] ?>"
                                           placeholder="Ex: 2025/Factures/Fournisseur XYZ" 
                                           class="ml-6 w-full px-3 py-2 border rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                           disabled>
                                    <p class="ml-6 mt-1 text-xs text-gray-500">Format: Année/Type/Fournisseur (le dossier sera créé automatiquement)</p>
                                </div>
                                
                                <!-- Storage paths existants -->
                                <?php if (!empty($storagePaths)): ?>
                                <div>
                                    <div class="flex items-center gap-2 mb-2">
                                        <input type="radio" name="storage_path_option" value="existing" id="path_existing_<?= $doc['id'] ?>" class="storage-path-radio">
                                        <label for="path_existing_<?= $doc['id'] ?>" class="text-sm font-medium text-gray-700 cursor-pointer">
                                            Utiliser un chemin existant
                                        </label>
                                    </div>
                                    <select name="storage_path_id" 
                                            id="existing_path_<?= $doc['id'] ?>"
                                            class="ml-6 w-full px-3 py-2 border rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                            disabled>
                                        <option value="">-- Sélectionner --</option>
                                        <?php foreach ($storagePaths as $sp): ?>
                                        <option value="<?= $sp['id'] ?>"><?= htmlspecialchars($sp['name']) ?> (<?= htmlspecialchars($sp['path']) ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Boutons d'action -->
                        <div class="flex gap-3 mt-6 pt-4 border-t">
                            <button type="submit" class="px-6 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 focus:ring-2 focus:ring-green-500 font-medium">
                                ✓ Valider et classer
                            </button>
                            <a href="<?= url('/documents/' . $doc['id'] . '/view') ?>" 
                               target="_blank"
                               class="px-4 py-2 bg-gray-100 text-gray-700 rounded-md hover:bg-gray-200 focus:ring-2 focus:ring-gray-500">
                                👁️ Voir le document
                            </a>
                        </div>
                    </div>
                </div>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<script>
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
</script>
