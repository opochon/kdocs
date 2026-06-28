<form method="POST" action="<?= url('/admin/consume/validate/' . $doc['id']) ?>" class="p-6" data-document-id="<?= $doc['id'] ?>">
                <div class="grid grid-cols-12 gap-6">
                    <!-- Colonne gauche : Aperçu et contenu -->
                    <div class="col-span-4">
                        <!-- Miniature -->
                        <div class="mb-4">
                            <label class="text-xs font-semibold mb-2 block" style="color:var(--ink-soft)">Aperçu</label>
                            <?php if ($hasThumbnail): ?>
                            <img src="<?= htmlspecialchars($doc['thumbnail_url']) ?>"
                                 alt="Aperçu"
                                 class="w-full border rounded-lg shadow-sm cursor-pointer hover:shadow-md transition"
                                 onclick="window.open('<?= url('/documents/' . $doc['id'] . '/view') ?>', '_blank')">
                            <?php else: ?>
                            <div class="w-full h-64 rounded-lg flex items-center justify-center border-2 border-dashed" style="background:var(--app-bg);border-color:var(--border)">
                                <div class="text-center" style="color:var(--dim)">
                                    <i class="fas fa-file-pdf text-4xl mb-2"></i>
                                    <p class="text-sm">Miniature non disponible</p>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Informations fichier -->
                        <div class="text-xs space-y-1 mb-4" style="color:var(--dim)">
                            <div><strong>Fichier:</strong> <?= htmlspecialchars($doc['original_filename']) ?></div>
                            <div><strong>Taille:</strong> <?= number_format($doc['file_size'] / 1024, 2) ?> KB</div>
                            <div><strong>Date d'upload:</strong> <?= !empty($doc['created_at']) ? date('d/m/Y H:i', strtotime($doc['created_at'])) : '-' ?></div>
                            <?php if (!empty($doc['doc_date'])): ?>
                            <div><strong>Date du document:</strong> <?= date('d/m/Y', strtotime($doc['doc_date'])) ?></div>
                            <?php endif; ?>
                            <div><strong>Méthode:</strong>
                                <?php
                                $methodUsed = $suggestions['method_used'] ?? 'non classifié';
                                if ($methodUsed === 'ai_direct' || $methodUsed === 'ai' || strpos($methodUsed, 'ai') !== false) {
                                    echo '<span class="font-semibold" style="color:var(--ink-soft)">IA</span>';
                                } elseif ($methodUsed === 'rules' || strpos($methodUsed, 'rules') !== false || strpos($methodUsed, 'local') !== false) {
                                    echo '<span class="font-semibold" style="color:var(--ink-soft)">Local</span>';
                                } else {
                                    echo htmlspecialchars($methodUsed);
                                }
                                ?>
                            </div>
                            <div><strong>Confiance:</strong>
                                <span class="px-2 py-0.5 ds-chip <?= ($suggestions['confidence'] ?? 0) >= 0.7 ? 'ds-chip--green' : (($suggestions['confidence'] ?? 0) >= 0.4 ? 'ds-chip--amber' : 'ds-chip--red') ?>">
                                    <?= round(($suggestions['confidence'] ?? 0) * 100) ?>%
                                </span>
                            </div>
                        </div>

                        <!-- Contenu OCR -->
                        <?php if (!empty($doc['content_preview'])): ?>
                        <div class="mb-4">
                            <label class="text-xs font-semibold mb-2 block" style="color:var(--ink-soft)">Contenu extrait (OCR)</label>
                            <div class="border rounded p-3 max-h-48 overflow-y-auto text-xs" style="background:var(--app-bg);border-color:var(--border);color:var(--ink-soft)">
                                <?= nl2br(htmlspecialchars($doc['content_preview'])) ?>
                                <?php if (strlen($doc['content'] ?? $doc['ocr_text'] ?? '') > 500): ?>
                                <span class="italic" style="color:var(--dim)">...</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Synthèse du document -->
                        <?php
                        $summary = $final['summary'] ?? null;
                        if (empty($summary) && !empty($suggestions['ai_result']['summary'])) {
                            $summary = $suggestions['ai_result']['summary'];
                        }
                        // Debug: afficher la réponse brute de Claude
                        $debugInfo = [];
                        if (!empty($suggestions['ai_result'])) {
                            $debugInfo['ai_result'] = $suggestions['ai_result'];
                        }
                        ?>
                        <div class="mb-4">
                            <label class="text-xs font-semibold mb-2 block" style="color:var(--ink-soft)">
                                Synthèse du document
                                <?php if (!empty($summary)): ?>
                                <span class="text-xs" style="color:var(--green)">(générée par IA)</span>
                                <?php endif; ?>
                            </label>
                            <textarea name="summary"
                                      rows="4"
                                      class="w-full px-3 py-2 rounded-md text-xs"
                                      placeholder="Synthèse du document (générée automatiquement par l'IA ou saisie manuellement)"><?= htmlspecialchars($summary ?? '') ?></textarea>
                            <?php if (!empty($debugInfo)): ?>
                            <details class="mt-2">
                                <summary class="text-xs cursor-pointer" style="color:var(--dim)">🔍 Debug: Voir la réponse complète de Claude</summary>
                                <div class="mt-2 border rounded p-2 text-xs overflow-auto max-h-64" style="background:var(--app-bg);border-color:var(--border)">
                                    <pre><?= htmlspecialchars(json_encode($debugInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                                </div>
                            </details>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Colonne droite : Formulaire de validation -->
                    <div class="col-span-8">
                        <div class="grid grid-cols-2 gap-4">
                            <!-- Titre (toujours présent) -->
                            <div class="col-span-2">
                                <label class="text-xs font-semibold mb-1 block" style="color:var(--ink-soft)">Titre du document</label>
                                <input type="text" name="title"
                                       value="<?= htmlspecialchars($final['title'] ?? $doc['title'] ?? '') ?>"
                                       class="w-full px-3 py-2 rounded-md text-sm">
                            </div>

                            <!-- Correspondant (toujours présent, juste après le titre) -->
                            <div class="col-span-2">
                                <?php
                                $correspondentId = $final['correspondent_id'] ?? $doc['correspondent_id'] ?? '';
                                $correspondentSuggestion = $final['correspondent_name'] ?? null;
                                ?>
                                <label class="text-xs font-semibold mb-1 block" style="color:var(--ink-soft)">
                                    Correspondant<?= $correspondentSuggestion ? ' (suggéré: ' . htmlspecialchars($correspondentSuggestion) . ')' : '' ?>
                                </label>
                                <select name="correspondent_id"
                                        class="w-full px-3 py-2 rounded-md text-sm">
                                    <option value="">-- Sélectionner --</option>
                                    <?php foreach ($correspondents as $c): ?>
                                    <option value="<?= $c['id'] ?>"
                                            <?= $correspondentId == $c['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($c['name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <?php
                            // Fonction helper pour générer un champ selon son type
                            $renderField = function($field, $final, $doc, $correspondents, $documentTypes, $tags) {
                                $fieldCode = $field['field_code'];
                                $fieldName = $field['field_name'];
                                $fieldType = $field['field_type'];
                                $isRequired = !empty($field['is_required']);

                                // Déterminer le nom du champ dans le formulaire et la valeur
                                $formName = '';
                                $currentValue = null;
                                $suggestion = null;

                                switch ($fieldCode) {
                                    case 'date':
                                        $formName = 'doc_date';
                                        $currentValue = $final['doc_date'] ?? $doc['doc_date'] ?? '';
                                        break;
                                    case 'amount':
                                        $formName = 'amount';
                                        $currentValue = $final['amount'] ?? $doc['amount'] ?? '';
                                        break;
                                    case 'supplier':
                                    case 'correspondent':
                                        $formName = 'correspondent_id';
                                        $currentValue = $final['correspondent_id'] ?? $doc['correspondent_id'] ?? '';
                                        $suggestion = $final['correspondent_name'] ?? null;
                                        break;
                                    case 'type':
                                    case 'document_type':
                                        $formName = 'document_type_id';
                                        $currentValue = $final['document_type_id'] ?? $doc['document_type_id'] ?? '';
                                        $suggestion = $final['document_type_name'] ?? null;
                                        break;
                                    case 'year':
                                        // L'année est généralement dérivée de la date, pas un champ direct
                                        return '';
                                    default:
                                        // Champs personnalisés
                                        $formName = 'custom_field_' . $fieldCode;
                                        $currentValue = null; // À implémenter si nécessaire
                                        break;
                                }

                                if (empty($formName)) return '';

                                $colSpan = ($fieldType === 'supplier' || $fieldType === 'type' || $fieldType === 'date' || $fieldType === 'amount') ? '' : 'col-span-2';

                                ob_start();
                                ?>
                                <div class="<?= $colSpan ?>">
                                    <label class="text-xs font-semibold mb-1 block" style="color:var(--ink-soft)">
                                        <?= htmlspecialchars($fieldName) ?>
                                        <?php if ($isRequired): ?>
                                        <span style="color:var(--red)">*</span>
                                        <?php endif; ?>
                                        <?php if ($suggestion): ?>
                                        <span class="text-xs" style="color:var(--green)">(suggéré: <?= htmlspecialchars($suggestion) ?>)</span>
                                        <?php endif; ?>
                                    </label>

                                    <?php if ($fieldType === 'supplier' || $fieldType === 'correspondent'): ?>
                                        <select name="<?= $formName ?>"
                                                class="w-full px-3 py-2 rounded-md text-sm"
                                                <?= $isRequired ? 'required' : '' ?>>
                                            <option value="">-- Sélectionner --</option>
                                            <?php foreach ($correspondents as $c): ?>
                                            <option value="<?= $c['id'] ?>"
                                                    <?= $currentValue == $c['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($c['name']) ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>

                                    <?php elseif ($fieldType === 'type' || $fieldType === 'document_type'): ?>
                                        <select name="<?= $formName ?>"
                                                class="w-full px-3 py-2 rounded-md text-sm"
                                                <?= $isRequired ? 'required' : '' ?>>
                                            <option value="">-- Sélectionner --</option>
                                            <?php foreach ($documentTypes as $t): ?>
                                            <option value="<?= $t['id'] ?>"
                                                    <?= $currentValue == $t['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($t['label']) ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>

                                    <?php elseif ($fieldType === 'date'): ?>
                                        <input type="date" name="<?= $formName ?>"
                                               value="<?= htmlspecialchars($currentValue) ?>"
                                               class="w-full px-3 py-2 rounded-md text-sm"
                                               <?= $isRequired ? 'required' : '' ?>>

                                    <?php elseif ($fieldType === 'amount'): ?>
                                        <input type="number" step="0.01" name="<?= $formName ?>"
                                               value="<?= htmlspecialchars($currentValue) ?>"
                                               class="w-full px-3 py-2 rounded-md text-sm">

                                    <?php else: ?>
                                        <input type="text" name="<?= $formName ?>"
                                               value="<?= htmlspecialchars($currentValue) ?>"
                                               class="w-full px-3 py-2 rounded-md text-sm"
                                               <?= $isRequired ? 'required' : '' ?>>
                                    <?php endif; ?>
                                </div>
                                <?php
                                return ob_get_clean();
                            };

                            // Générer les champs dynamiquement selon l'ordre de storage_path_position
                            $sortedFields = $classificationFields;
                            usort($sortedFields, function($a, $b) {
                                $posA = $a['storage_path_position'] ?? 999;
                                $posB = $b['storage_path_position'] ?? 999;
                                if ($posA == $posB) {
                                    // Si même position, priorité aux champs obligatoires
                                    $reqA = !empty($a['is_required']) ? 0 : 1;
                                    $reqB = !empty($b['is_required']) ? 0 : 1;
                                    return $reqA <=> $reqB;
                                }
                                return $posA <=> $posB;
                            });

                            // Afficher les champs (sauf year qui est dérivé de date, et correspondent qui est déjà affiché après le titre)
                            foreach ($sortedFields as $field) {
                                if ($field['field_code'] === 'year') continue;
                                if ($field['field_code'] === 'supplier' || $field['field_code'] === 'correspondent') continue; // Déjà affiché après le titre
                                echo $renderField($field, $final, $doc, $correspondents, $documentTypes, $tags);
                            }
                            ?>

                            <!-- Tags (toujours présent) -->
                            <div class="col-span-2">
                                <label class="text-xs font-semibold mb-1 block" style="color:var(--ink-soft)">
                                    Tags
                                </label>
                                <?php if (!empty($tags)): ?>
                                <!-- Conteneur pour les tags assignés (affichage en badges) -->
                                <div class="flex flex-wrap gap-2 mb-2" id="assigned-tags-container-<?= $doc['id'] ?>">
                                    <?php
                                    $assignedTagIds = $final['tag_ids'] ?? [];
                                    foreach ($tags as $tag):
                                        if (in_array($tag['id'], $assignedTagIds)):
                                    ?>
                                    <div class="relative inline-flex items-center gap-1 px-3 py-1 ds-chip ds-chip--neutral text-xs hover:bg-opacity-80 transition-colors assigned-tag-badge"
                                          data-tag-id="<?= $tag['id'] ?>"
                                          data-tag-name="<?= htmlspecialchars($tag['name']) ?>"
                                          data-document-id="<?= $doc['id'] ?>">
                                        <span class="cursor-pointer flex-1">
                                            <?= htmlspecialchars($tag['name']) ?>
                                        </span>
                                        <button type="button"
                                                class="ml-1 hover:font-bold transition-colors" style="color:var(--red)"
                                                onclick="moveTagToSuggestions('<?= htmlspecialchars($tag['name']) ?>', <?= $tag['id'] ?>, <?= $doc['id'] ?>)"
                                                title="Déplacer vers les suggestions">
                                            ×
                                        </button>
                                    </div>
                                    <?php
                                        endif;
                                    endforeach;
                                    ?>
                                </div>
                                <!-- Select caché pour maintenir les valeurs du formulaire -->
                                <select name="tags[]" multiple
                                        class="hidden"
                                        id="tags-select-<?= $doc['id'] ?>">
                                    <?php foreach ($tags as $tag): ?>
                                    <option value="<?= $tag['id'] ?>"
                                            <?= in_array($tag['id'], $final['tag_ids'] ?? []) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($tag['name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <!-- Select pour ajouter de nouveaux tags -->
                                <select class="w-full px-3 py-2 rounded-md text-sm"
                                        id="add-tag-select-<?= $doc['id'] ?>"
                                        onchange="addTagToDocument(this.value, <?= $doc['id'] ?>)">
                                    <option value="">Ajouter un tag...</option>
                                    <?php foreach ($tags as $tag): ?>
                                        <?php if (!in_array($tag['id'], $assignedTagIds)): ?>
                                    <option value="<?= $tag['id'] ?>">
                                        <?= htmlspecialchars($tag['name']) ?>
                                    </option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                                <?php else: ?>
                                <div class="border rounded-md p-3 text-xs mb-2" style="border-color:color-mix(in srgb,var(--amber) 35%,var(--border));background:color-mix(in srgb,var(--amber) 10%,transparent);color:var(--amber)">
                                    ⚠️ Aucun tag disponible. Créez des tags dans la section "Étiquettes" pour pouvoir les assigner aux documents.
                                </div>
                                <?php endif; ?>
                            </div>

                            <!-- Tags ou classification suggérés -->
                            <?php
                            // Récupérer les tags suggérés depuis différentes sources
                            $suggestedTags = $final['tag_names'] ?? [];
                            if (empty($suggestedTags) && !empty($suggestions['ai_result']['tag_names'])) {
                                $suggestedTags = $suggestions['ai_result']['tag_names'];
                            }
                            // Si toujours vide, essayer depuis ai_result directement (format brut)
                            if (empty($suggestedTags) && !empty($suggestions['ai_result']['tags'])) {
                                $suggestedTags = $suggestions['ai_result']['tags'];
                            }
                            // Si toujours vide, utiliser les tags extraits du contenu OCR
                            if (empty($suggestedTags) && !empty($doc['suggested_tags'])) {
                                $suggestedTags = $doc['suggested_tags'];
                            }
                            if (!empty($suggestedTags)):
                                // Récupérer les tags ignorés pour ce document
                                $ignoredTags = json_decode($doc['ai_ignored_tags'] ?? '[]', true);
                                if (!is_array($ignoredTags)) {
                                    $ignoredTags = [];
                                }
                                // Initialiser le service de mapping (peut échouer si table n'existe pas)
                                $mappingService = null;
                                try {
                                    $mappingService = new \KDocs\Services\CategoryMappingService();
                                } catch (\Exception $e) {
                                    // Service non disponible, continuer sans mapping
                                    error_log("CategoryMappingService non disponible: " . $e->getMessage());
                                }
                            ?>
                            <div class="col-span-2 border-t pt-4 mt-2">
                                <label class="text-xs font-semibold mb-2 block" style="color:var(--ink-soft)">
                                    🏷️ Tags ou classification suggérés
                                </label>
                                <div class="flex flex-wrap gap-2" id="suggested-tags-container-<?= $doc['id'] ?>">
                                    <?php
                                    // Récupérer les noms des tags déjà ajoutés au document
                                    $addedTagNames = [];
                                    $addedTagIds = [];
                                    if (!empty($final['tag_ids']) && is_array($final['tag_ids'])) {
                                        foreach ($tags as $tag) {
                                            if (in_array($tag['id'], $final['tag_ids'])) {
                                                $addedTagNames[] = strtolower($tag['name']);
                                                $addedTagIds[strtolower($tag['name'])] = $tag['id'];
                                            }
                                        }
                                    }

                                    foreach ($suggestedTags as $suggestedTag):
                                        if (in_array($suggestedTag, $ignoredTags)) continue; // Ne pas afficher les tags ignorés

                                        // Vérifier si le tag est déjà ajouté au document
                                        $isAdded = in_array(strtolower($suggestedTag), $addedTagNames);
                                        $tagId = $isAdded ? ($addedTagIds[strtolower($suggestedTag)] ?? null) : null;

                                        $hasMapping = false;
                                        $hasDocumentTypeMapping = false;
                                        if ($mappingService) {
                                            try {
                                                $mappings = $mappingService->getMappingsForCategory($suggestedTag);
                                                $hasMapping = !empty($mappings);
                                                // Vérifier si un mapping vers document_type existe
                                                foreach ($mappings as $mapping) {
                                                    if ($mapping['mapped_type'] === 'document_type') {
                                                        $hasDocumentTypeMapping = true;
                                                        break;
                                                    }
                                                }
                                            } catch (\Exception $e) {
                                                // Ignorer les erreurs de mapping
                                            }
                                        }
                                        // Ne pas afficher si déjà mappé vers un type de document ET que le document a déjà ce type
                                        if ($hasDocumentTypeMapping && !empty($final['document_type_id'])) {
                                            continue;
                                        }

                                        // Classe CSS différente selon si le tag est ajouté ou non
                                        $badgeClass = $isAdded ? 'ds-chip--green' : 'ds-chip--accent';
                                    ?>
                                    <div class="relative inline-flex items-center gap-1 px-3 py-1 ds-chip <?= $badgeClass ?> text-xs hover:bg-opacity-80 transition-colors suggested-tag-badge cursor-pointer"
                                          data-tag="<?= htmlspecialchars($suggestedTag) ?>"
                                          data-document-id="<?= $doc['id'] ?>"
                                          data-has-mapping="<?= $hasMapping ? '1' : '0' ?>"
                                          <?= $isAdded ? 'data-tag-added="1" data-tag-id="' . ($tagId ?? '') . '"' : '' ?>>
                                        <span class="cursor-pointer flex-1">
                                            <?= htmlspecialchars($suggestedTag) ?>
                                        </span>
                                        <?php if ($hasMapping): ?>
                                        <span style="color:var(--green)" title="Mappé">✓</span>
                                        <?php endif; ?>
                                        <button type="button"
                                                class="ml-1 hover:font-bold transition-colors" style="<?= $isAdded ? 'color:var(--green)' : 'color:var(--accent)' ?>"
                                                onclick="event.stopPropagation(); <?= $isAdded ? "removeTagFromDocument('" . htmlspecialchars($suggestedTag) . "', " . $doc['id'] . ", " . ($tagId ?? 'null') . ")" : "markTagIrrelevant('" . htmlspecialchars($suggestedTag) . "', " . $doc['id'] . ")" ?>"
                                                title="<?= $isAdded ? 'Retirer le tag' : 'Marquer comme non pertinent' ?>">
                                            ×
                                        </button>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <p class="mt-2 text-xs" style="color:var(--dim)">
                                    💡 Clic droit sur un tag pour créer/mapper • Clic sur × pour ignorer
                                </p>
                            </div>
                            <?php endif; ?>

                            <!-- Synthèse du document -->
                            <?php
                            $summary = $final['summary'] ?? null;
                            if (empty($summary) && !empty($suggestions['ai_result']['summary'])) {
                                $summary = $suggestions['ai_result']['summary'];
                            }
                            if (!empty($summary)):
                            ?>
                            <div class="col-span-2 border-t pt-4 mt-2">
                                <label class="text-xs font-semibold mb-2 block" style="color:var(--ink-soft)">
                                    📄 Synthèse du document
                                </label>
                                <div class="border rounded-md p-3" style="border-color:color-mix(in srgb,var(--accent) 35%,var(--border));background:var(--accent-soft)">
                                    <p class="text-sm leading-relaxed" style="color:var(--ink-soft)">
                                        <?= nl2br(htmlspecialchars($summary)) ?>
                                    </p>
                                </div>
                                <p class="mt-2 text-xs" style="color:var(--dim)">
                                    Cette synthèse permet d'identifier rapidement les grandes lignes du document sans avoir à le lire en entier.
                                </p>
                            </div>
                            <?php endif; ?>

                            <!-- Catégories supplémentaires extraites par IA -->
                            <?php
                            $additionalCategories = json_decode($doc['ai_additional_categories'] ?? '[]', true);
                            if (!empty($additionalCategories)):
                                $mappingService = new \KDocs\Services\CategoryMappingService();
                                $categoriesWithMappings = $mappingService->applyMappings($additionalCategories);
                            ?>
                            <div class="col-span-2 border-t pt-4 mt-2">
                                <label class="text-xs font-semibold mb-2 block" style="color:var(--ink-soft)">
                                    🤖 Catégories identifiées par IA
                                </label>
                                <div class="flex flex-wrap gap-2" id="categories-container-<?= $doc['id'] ?>">
                                    <?php foreach ($categoriesWithMappings as $cat): ?>
                                    <div class="relative inline-block">
                                        <span class="category-badge px-3 py-1 ds-chip ds-chip--accent text-xs cursor-pointer transition-colors"
                                              data-category="<?= htmlspecialchars($cat['name']) ?>"
                                              data-document-id="<?= $doc['id'] ?>"
                                              data-has-mapping="<?= $cat['has_mapping'] ? '1' : '0' ?>">
                                            <?= htmlspecialchars($cat['name']) ?>
                                            <?php if ($cat['has_mapping']): ?>
                                            <span class="ml-1" style="color:var(--green)" title="Mappé">✓</span>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- Données extraites (apprentissage) -->
                            <?php
                            $extractedData = [];
                            try {
                                $extractionService = new \KDocs\Services\ExtractionService();
                                $extractedData = $extractionService->getExtractedData($doc['id']);
                            } catch (\Exception $e) {}
                            if (!empty($extractedData)):
                            ?>
                            <div class="col-span-2 border-t pt-4 mt-2">
                                <label class="text-xs font-semibold mb-2 block" style="color:var(--ink-soft)">
                                    📊 Données extraites (apprentissage)
                                </label>
                                <div class="grid grid-cols-2 gap-3">
                                    <?php foreach ($extractedData as $field): ?>
                                    <div class="rounded p-2 border" style="background:var(--app-bg);border-color:var(--border)">
                                        <div class="text-xs mb-1" style="color:var(--dim)"><?= htmlspecialchars($field['field_name']) ?></div>
                                        <div class="flex items-center gap-2">
                                            <span class="text-sm font-medium"><?= htmlspecialchars($field['value'] ?? '-') ?></span>
                                            <?php if ($field['show_confidence'] && $field['confidence']): ?>
                                            <span class="text-xs px-1.5 py-0.5 ds-chip <?= $field['confidence'] >= 0.8 ? 'ds-chip--green' : ($field['confidence'] >= 0.5 ? 'ds-chip--amber' : 'ds-chip--red') ?>">
                                                <?= round($field['confidence'] * 100) ?>%
                                            </span>
                                            <?php endif; ?>
                                            <?php if ($field['is_confirmed']): ?>
                                            <span style="color:var(--green)" title="Confirmé">✓</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <p class="mt-2 text-xs" style="color:var(--dim)">
                                    💡 Ces données sont extraites automatiquement et s'améliorent avec vos corrections.
                                </p>
                            </div>
                            <?php endif; ?>

                            <!-- Emplacement de stockage -->
                            <div class="col-span-2 border-t pt-4 mt-2">
                                <label class="text-xs font-semibold mb-2 block" style="color:var(--ink-soft)">
                                    📁 Emplacement de stockage
                                    <?php if (!empty($doc['suggested_path'])): ?>
                                    <span class="text-xs" style="color:var(--accent)">(suggéré: <?= htmlspecialchars($doc['suggested_path']) ?>)</span>
                                    <?php endif; ?>
                                </label>

                                <!-- Chemin suggéré automatique -->
                                <div class="mb-3 p-3 border rounded-md" style="border-color:color-mix(in srgb,var(--accent) 35%,var(--border));background:var(--accent-soft)">
                                    <div class="flex items-center gap-2 mb-2">
                                        <input type="radio" name="storage_path_option" value="suggested" id="path_suggested_<?= $doc['id'] ?>" checked class="storage-path-radio">
                                        <label for="path_suggested_<?= $doc['id'] ?>" class="text-sm font-medium cursor-pointer" style="color:var(--ink-soft)">
                                            Chemin suggéré automatique
                                        </label>
                                    </div>
                                    <div class="ml-6 text-xs font-mono" style="color:var(--ink-soft)">
                                        <?= htmlspecialchars($doc['suggested_path'] ?? 'Aucune suggestion') ?>
                                    </div>
                                </div>

                                <!-- Chemin personnalisé -->
                                <div class="mb-3">
                                    <div class="flex items-center gap-2 mb-2">
                                        <input type="radio" name="storage_path_option" value="custom" id="path_custom_<?= $doc['id'] ?>" class="storage-path-radio">
                                        <label for="path_custom_<?= $doc['id'] ?>" class="text-sm font-medium cursor-pointer" style="color:var(--ink-soft)">
                                            Chemin personnalisé
                                        </label>
                                    </div>
                                    <input type="text"
                                           name="storage_path_custom"
                                           id="custom_path_<?= $doc['id'] ?>"
                                           placeholder="Ex: 2025/Factures/Fournisseur XYZ"
                                           class="ml-6 w-full px-3 py-2 rounded-md text-sm"
                                           disabled>
                                    <p class="ml-6 mt-1 text-xs" style="color:var(--dim)">Format: Année/Type/Fournisseur (le dossier sera créé automatiquement)</p>
                                </div>

                                <!-- Storage paths existants -->
                                <?php if (!empty($storagePaths)): ?>
                                <div>
                                    <div class="flex items-center gap-2 mb-2">
                                        <input type="radio" name="storage_path_option" value="existing" id="path_existing_<?= $doc['id'] ?>" class="storage-path-radio">
                                        <label for="path_existing_<?= $doc['id'] ?>" class="text-sm font-medium cursor-pointer" style="color:var(--ink-soft)">
                                            Utiliser un chemin existant
                                        </label>
                                    </div>
                                    <select name="storage_path_id"
                                            id="existing_path_<?= $doc['id'] ?>"
                                            class="ml-6 w-full px-3 py-2 rounded-md text-sm"
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
                            <button type="submit" class="btn ds-btn-green font-medium">
                                ✓ Valider et classer
                            </button>
                            <?php if ($classifier->isAIAvailable()): ?>
                            <button type="button"
                                    onclick="analyzeWithAI(<?= $doc['id'] ?>)"
                                    class="btn btn-secondary"
                                    id="analyze-ai-btn-<?= $doc['id'] ?>">
                                🤖 Analyser avec l'IA
                            </button>
                            <button type="button"
                                    onclick="analyzeComplexWithAI(<?= $doc['id'] ?>)"
                                    class="btn btn-secondary ocr-mode-dependent"
                                    id="analyze-complex-ai-btn-<?= $doc['id'] ?>"
                                    style="display: none;">
                                🔬 Analyser les documents complexes avec IA
                            </button>
                            <?php endif; ?>
                            <a href="<?= url('/documents/' . $doc['id'] . '/view') ?>"
                               target="_blank"
                               class="btn btn-secondary">
                                👁️ Voir le document
                            </a>
                        </div>
                    </div>
                </div>
            </form>
