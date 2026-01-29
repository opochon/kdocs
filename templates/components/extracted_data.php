<?php
/**
 * Composant d'affichage des données extraites
 * Variables attendues : $documentId, $extractedData (optionnel)
 */

$documentId = $documentId ?? 0;
$extractedData = $extractedData ?? [];

// Charger les données si non fournies
if (empty($extractedData) && $documentId > 0) {
    try {
        $extractionService = new \KDocs\Services\ExtractionService();
        $extractedData = $extractionService->getExtractedData($documentId);
    } catch (\Exception $e) {
        $extractedData = [];
    }
}
?>

<div class="space-y-4" id="extracted-data-container">
    <?php if (empty($extractedData)): ?>
    <div class="text-center py-8 text-gray-500">
        <svg class="w-12 h-12 mx-auto mb-3 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
        </svg>
        <p class="text-sm">Aucune donnée extraite</p>
        <button onclick="extractDocumentData(<?= $documentId ?>)"
                class="mt-3 px-4 py-2 text-sm bg-blue-600 text-white rounded-lg hover:bg-blue-700">
            Extraire les données
        </button>
    </div>
    <?php else: ?>

    <!-- Bouton re-extraction -->
    <div class="flex justify-end mb-2">
        <button onclick="extractDocumentData(<?= $documentId ?>)"
                class="px-3 py-1.5 text-xs border border-blue-300 text-blue-700 rounded hover:bg-blue-50">
            Re-extraire
        </button>
    </div>

    <?php foreach ($extractedData as $field): ?>
    <div class="bg-gray-50 rounded-lg p-3 border border-gray-200" data-field-code="<?= htmlspecialchars($field['field_code']) ?>">
        <div class="flex items-start justify-between">
            <div class="flex-1">
                <label class="text-sm font-medium text-gray-700"><?= htmlspecialchars($field['field_name']) ?></label>

                <?php if ($field['field_type'] === 'select' || $field['field_type'] === 'multi_select'): ?>
                    <?php
                    $options = json_decode($field['options'] ?? '[]', true) ?: [];
                    ?>
                    <select class="mt-1 w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
                            data-original-value="<?= htmlspecialchars($field['value'] ?? '') ?>"
                            onchange="markFieldChanged(this, '<?= htmlspecialchars($field['field_code']) ?>')">
                        <option value="">-- Sélectionner --</option>
                        <?php foreach ($options as $opt): ?>
                            <?php
                            $optValue = is_array($opt) ? ($opt['value'] ?? '') : $opt;
                            $optLabel = is_array($opt) ? ($opt['label'] ?? $optValue) : $opt;
                            $selected = ($field['value'] ?? '') === $optValue ? 'selected' : '';
                            ?>
                            <option value="<?= htmlspecialchars($optValue) ?>" <?= $selected ?>><?= htmlspecialchars($optLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php elseif ($field['field_type'] === 'date'): ?>
                    <input type="date"
                           value="<?= htmlspecialchars($field['value'] ?? '') ?>"
                           data-original-value="<?= htmlspecialchars($field['value'] ?? '') ?>"
                           onchange="markFieldChanged(this, '<?= htmlspecialchars($field['field_code']) ?>')"
                           class="mt-1 w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                <?php elseif ($field['field_type'] === 'money' || $field['field_type'] === 'number'): ?>
                    <input type="number" step="0.01"
                           value="<?= htmlspecialchars($field['value'] ?? '') ?>"
                           data-original-value="<?= htmlspecialchars($field['value'] ?? '') ?>"
                           onchange="markFieldChanged(this, '<?= htmlspecialchars($field['field_code']) ?>')"
                           class="mt-1 w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                <?php else: ?>
                    <input type="text"
                           value="<?= htmlspecialchars($field['value'] ?? '') ?>"
                           data-original-value="<?= htmlspecialchars($field['value'] ?? '') ?>"
                           onchange="markFieldChanged(this, '<?= htmlspecialchars($field['field_code']) ?>')"
                           class="mt-1 w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                <?php endif; ?>
            </div>

            <!-- Actions -->
            <div class="ml-2 flex flex-col gap-1">
                <?php if (!($field['is_confirmed'] ?? false)): ?>
                <button onclick="confirmExtractedValue(<?= $documentId ?>, '<?= htmlspecialchars($field['field_code']) ?>')"
                        class="p-1.5 text-green-600 hover:bg-green-50 rounded" title="Confirmer">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    </svg>
                </button>
                <?php else: ?>
                <span class="p-1.5 text-green-600" title="Confirmé">
                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"></path>
                    </svg>
                </span>
                <?php endif; ?>

                <button onclick="correctExtractedValue(<?= $documentId ?>, '<?= htmlspecialchars($field['field_code']) ?>')"
                        class="p-1.5 text-blue-600 hover:bg-blue-50 rounded hidden correction-btn" title="Enregistrer correction">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"></path>
                    </svg>
                </button>
            </div>
        </div>

        <!-- Info confiance et source -->
        <div class="mt-2 flex items-center gap-3 text-xs text-gray-500">
            <?php if ($field['show_confidence'] && $field['confidence'] !== null): ?>
            <span class="flex items-center gap-1" title="Score de confiance">
                <?php
                $confidence = (float)$field['confidence'];
                $confidenceColor = $confidence >= 0.8 ? 'text-green-600' : ($confidence >= 0.5 ? 'text-yellow-600' : 'text-red-600');
                ?>
                <span class="<?= $confidenceColor ?>"><?= round($confidence * 100) ?>%</span>
            </span>
            <?php endif; ?>

            <span class="flex items-center gap-1" title="Source">
                <?php
                $sourceLabels = [
                    'history' => 'Historique',
                    'rules' => 'Règles',
                    'ai' => 'IA',
                    'regex' => 'Pattern',
                    'manual' => 'Manuel'
                ];
                $sourceLabel = $sourceLabels[$field['source'] ?? ''] ?? $field['source'];
                ?>
                <span><?= htmlspecialchars($sourceLabel) ?></span>
            </span>

            <?php if ($field['is_corrected'] ?? false): ?>
            <span class="text-orange-600">Corrigé</span>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>

    <?php endif; ?>
</div>

<script>
// Marquer un champ comme modifié
function markFieldChanged(input, fieldCode) {
    const container = input.closest('[data-field-code]');
    const correctionBtn = container.querySelector('.correction-btn');
    const originalValue = input.dataset.originalValue;

    if (input.value !== originalValue) {
        input.classList.add('border-blue-500', 'bg-blue-50');
        if (correctionBtn) correctionBtn.classList.remove('hidden');
    } else {
        input.classList.remove('border-blue-500', 'bg-blue-50');
        if (correctionBtn) correctionBtn.classList.add('hidden');
    }
}

// Extraire les données du document
async function extractDocumentData(documentId) {
    const btn = event?.target;
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="animate-pulse">Extraction...</span>';
    }

    try {
        const response = await fetch(`<?= url('/api/documents/') ?>${documentId}/extract`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' }
        });

        const data = await response.json();

        if (data.success) {
            // Recharger la page pour voir les nouvelles données
            window.location.reload();
        } else {
            alert('Erreur: ' + (data.error || 'Extraction échouée'));
        }
    } catch (error) {
        console.error('Erreur extraction:', error);
        alert('Erreur de connexion');
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = 'Extraire les données';
        }
    }
}

// Confirmer une valeur extraite
async function confirmExtractedValue(documentId, fieldCode) {
    try {
        const response = await fetch(`<?= url('/api/documents/') ?>${documentId}/extracted/${fieldCode}/confirm`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' }
        });

        const data = await response.json();

        if (data.success) {
            // Mettre à jour l'UI
            const container = document.querySelector(`[data-field-code="${fieldCode}"]`);
            if (container) {
                const btn = container.querySelector('button[onclick*="confirmExtractedValue"]');
                if (btn) {
                    btn.outerHTML = `<span class="p-1.5 text-green-600" title="Confirmé">
                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"></path>
                        </svg>
                    </span>`;
                }
            }
        } else {
            alert('Erreur: ' + (data.message || 'Confirmation échouée'));
        }
    } catch (error) {
        console.error('Erreur confirmation:', error);
        alert('Erreur de connexion');
    }
}

// Corriger une valeur extraite (apprentissage)
async function correctExtractedValue(documentId, fieldCode) {
    const container = document.querySelector(`[data-field-code="${fieldCode}"]`);
    const input = container.querySelector('input, select');
    const newValue = input.value;

    try {
        const response = await fetch(`<?= url('/api/documents/') ?>${documentId}/extracted/${fieldCode}/correct`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ value: newValue })
        });

        const data = await response.json();

        if (data.success) {
            // Mettre à jour l'UI
            input.dataset.originalValue = newValue;
            input.classList.remove('border-blue-500', 'bg-blue-50');

            const correctionBtn = container.querySelector('.correction-btn');
            if (correctionBtn) correctionBtn.classList.add('hidden');

            // Afficher confirmation
            const infoDiv = container.querySelector('.text-xs');
            if (infoDiv) {
                const correctedSpan = infoDiv.querySelector('.text-orange-600');
                if (!correctedSpan) {
                    infoDiv.innerHTML += '<span class="text-orange-600 ml-2">Corrigé</span>';
                }
            }

            alert('Valeur corrigée et mémorisée pour les futurs documents similaires');
        } else {
            alert('Erreur: ' + (data.message || 'Correction échouée'));
        }
    } catch (error) {
        console.error('Erreur correction:', error);
        alert('Erreur de connexion');
    }
}
</script>
