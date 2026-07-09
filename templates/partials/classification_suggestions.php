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

<div id="classification-suggestions" class="rounded-lg p-4 mb-6" style="background: color-mix(in srgb, var(--amber) 12%, transparent); border: 1px solid color-mix(in srgb, var(--amber) 32%, transparent);">
    <div class="flex items-start justify-between">
        <div class="flex items-center gap-3">
            <div class="rounded-full p-2" style="background: color-mix(in srgb, var(--amber) 18%, transparent);">
                <?= icon('lightbulb', ['style' => 'color:var(--amber)']) ?>
            </div>
            <div>
                <h4 class="font-medium" style="color:var(--amber)">Suggestions de classification</h4>
                <p class="text-sm" style="color:var(--amber)">Basées sur des documents similaires</p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <button onclick="applyAllSuggestions()" class="btn-primary px-3 py-1 text-sm rounded">
                <?= icon('check-double', ['class' => 'mr-1']) ?>Appliquer tout
            </button>
            <button onclick="ignoreAllSuggestions()" class="btn-secondary border px-3 py-1 text-sm rounded">
                <?= icon('times', ['class' => 'mr-1']) ?>Ignorer tout
            </button>
        </div>
    </div>

    <div class="mt-4 space-y-2">
        <?php foreach ($suggestions as $suggestion): ?>
            <div class="suggestion-item ds-card flex items-center justify-between p-3"
                 data-id="<?= $suggestion['id'] ?>">
                <div class="flex items-center gap-4">
                    <span class="text-sm font-medium w-32" style="color:var(--ink-soft)">
                        <?= htmlspecialchars($fieldLabels[$suggestion['field_code']] ?? $suggestion['field_code']) ?>
                    </span>
                    <span class="font-medium" style="color:var(--ink)">
                        <?= htmlspecialchars($suggestion['value_label'] ?? $suggestion['suggested_value']) ?>
                    </span>
                    <span class="ds-chip--accent inline-flex items-center px-2 py-0.5 rounded text-xs font-medium">
                        <?= round($suggestion['confidence'] * 100) ?>% confiance
                    </span>
                    <?php if (!empty($suggestion['similar_documents'])): ?>
                        <span class="text-xs" style="color:var(--dim)">
                            Basé sur <?= count($suggestion['similar_documents']) ?> doc(s) similaire(s)
                        </span>
                    <?php endif; ?>
                </div>
                <div class="flex items-center gap-2">
                    <button onclick="applySuggestion(<?= $suggestion['id'] ?>)"
                            class="p-1" style="color:var(--green)" title="Appliquer">
                        <?= icon('check') ?>
                    </button>
                    <button onclick="ignoreSuggestion(<?= $suggestion['id'] ?>)"
                            class="p-1" style="color:var(--dim)" title="Ignorer">
                        <?= icon('times') ?>
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
