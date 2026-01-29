<?php
/**
 * Bandeau de suggestions de classification ML
 * À inclure dans la vue détail document
 *
 * Variables attendues:
 * - $documentId: ID du document
 * - $suggestions: array des suggestions (optionnel, sera chargé si absent)
 */

if (!isset($suggestions)) {
    $suggestions = [];
    try {
        $learningService = new \KDocs\Services\Learning\ClassificationLearningService();
        $suggestions = $learningService->getDocumentSuggestions($documentId);
    } catch (\Exception $e) {
        // Ignorer si le service n'est pas disponible
    }
}

if (empty($suggestions)) {
    return;
}

$fieldLabels = [
    'compte_comptable' => 'Compte comptable',
    'centre_cout' => 'Centre de coût',
    'projet' => 'Projet'
];
?>

<div id="classification-suggestions" class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-6">
    <div class="flex items-start justify-between">
        <div class="flex items-center gap-3">
            <div class="bg-yellow-100 rounded-full p-2">
                <i class="fas fa-lightbulb text-yellow-600"></i>
            </div>
            <div>
                <h4 class="font-medium text-yellow-800">Suggestions de classification</h4>
                <p class="text-sm text-yellow-600">Basées sur des documents similaires</p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <button onclick="applyAllSuggestions()" class="px-3 py-1 bg-yellow-600 text-white text-sm rounded hover:bg-yellow-700">
                <i class="fas fa-check-double mr-1"></i>Appliquer tout
            </button>
            <button onclick="ignoreAllSuggestions()" class="px-3 py-1 bg-white text-yellow-700 text-sm rounded border border-yellow-300 hover:bg-yellow-100">
                <i class="fas fa-times mr-1"></i>Ignorer tout
            </button>
        </div>
    </div>

    <div class="mt-4 space-y-2">
        <?php foreach ($suggestions as $suggestion): ?>
            <div class="suggestion-item flex items-center justify-between bg-white rounded-lg p-3 border border-yellow-100"
                 data-id="<?= $suggestion['id'] ?>">
                <div class="flex items-center gap-4">
                    <span class="text-sm font-medium text-gray-600 w-32">
                        <?= htmlspecialchars($fieldLabels[$suggestion['field_code']] ?? $suggestion['field_code']) ?>
                    </span>
                    <span class="font-medium text-gray-800">
                        <?= htmlspecialchars($suggestion['value_label'] ?? $suggestion['suggested_value']) ?>
                    </span>
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800">
                        <?= round($suggestion['confidence'] * 100) ?>% confiance
                    </span>
                    <?php if (!empty($suggestion['similar_documents'])): ?>
                        <span class="text-xs text-gray-500">
                            Basé sur <?= count($suggestion['similar_documents']) ?> doc(s) similaire(s)
                        </span>
                    <?php endif; ?>
                </div>
                <div class="flex items-center gap-2">
                    <button onclick="applySuggestion(<?= $suggestion['id'] ?>)"
                            class="p-1 text-green-600 hover:text-green-800" title="Appliquer">
                        <i class="fas fa-check"></i>
                    </button>
                    <button onclick="ignoreSuggestion(<?= $suggestion['id'] ?>)"
                            class="p-1 text-gray-400 hover:text-gray-600" title="Ignorer">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
const documentId = <?= $documentId ?>;

async function applySuggestion(suggestionId) {
    try {
        const response = await fetch(`<?= url('/api/documents') ?>/${documentId}/suggestions/${suggestionId}/apply`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || ''
            }
        });

        const result = await response.json();
        if (result.success) {
            // Remove the suggestion row
            document.querySelector(`.suggestion-item[data-id="${suggestionId}"]`)?.remove();
            checkEmptySuggestions();
            // Optionally reload the page to show updated values
            location.reload();
        } else {
            alert(result.message || 'Erreur');
        }
    } catch (e) {
        alert('Erreur: ' + e.message);
    }
}

async function ignoreSuggestion(suggestionId) {
    try {
        const response = await fetch(`<?= url('/api/documents') ?>/${documentId}/suggestions/${suggestionId}/ignore`, {
            method: 'POST',
            headers: {
                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || ''
            }
        });

        const result = await response.json();
        if (result.success) {
            document.querySelector(`.suggestion-item[data-id="${suggestionId}"]`)?.remove();
            checkEmptySuggestions();
        }
    } catch (e) {
        console.error(e);
    }
}

async function applyAllSuggestions() {
    try {
        const response = await fetch(`<?= url('/api/documents') ?>/${documentId}/suggestions/apply-all`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || ''
            }
        });

        const result = await response.json();
        if (result.success) {
            location.reload();
        }
    } catch (e) {
        alert('Erreur: ' + e.message);
    }
}

async function ignoreAllSuggestions() {
    try {
        const response = await fetch(`<?= url('/api/documents') ?>/${documentId}/suggestions/ignore-all`, {
            method: 'POST',
            headers: {
                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || ''
            }
        });

        const result = await response.json();
        if (result.success) {
            document.getElementById('classification-suggestions')?.remove();
        }
    } catch (e) {
        console.error(e);
    }
}

function checkEmptySuggestions() {
    const items = document.querySelectorAll('.suggestion-item');
    if (items.length === 0) {
        document.getElementById('classification-suggestions')?.remove();
    }
}
</script>
