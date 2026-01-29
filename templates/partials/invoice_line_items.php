<?php
/**
 * Section lignes de facture
 * À inclure dans la vue détail document pour les factures
 *
 * Variables attendues:
 * - $documentId: ID du document
 * - $lineItems: array des lignes (optionnel, sera chargé si absent)
 * - $isInvoice: bool indiquant si c'est une facture
 */

if (!isset($isInvoice) || !$isInvoice) {
    return;
}

if (!isset($lineItems)) {
    $lineItems = \KDocs\Models\InvoiceLineItem::getForDocument($documentId);
}

$totals = \KDocs\Models\InvoiceLineItem::calculateTotals($documentId);

$fieldOptions = \KDocs\Models\ClassificationFieldOption::getAllGrouped();
?>

<div id="invoice-line-items" class="bg-white rounded-lg shadow mt-6">
    <div class="px-6 py-4 border-b flex items-center justify-between">
        <h3 class="font-medium text-gray-800">
            <i class="fas fa-list-ol text-blue-500 mr-2"></i>Lignes de facture
        </h3>
        <div class="flex items-center gap-2">
            <?php if (empty($lineItems)): ?>
                <button onclick="extractLineItems()" id="extract-btn"
                        class="px-3 py-1 bg-blue-600 text-white text-sm rounded hover:bg-blue-700">
                    <i class="fas fa-magic mr-1"></i>Extraire avec IA
                </button>
            <?php else: ?>
                <button onclick="extractLineItems(true)"
                        class="px-3 py-1 bg-gray-100 text-gray-700 text-sm rounded hover:bg-gray-200">
                    <i class="fas fa-sync mr-1"></i>Ré-extraire
                </button>
                <button onclick="addLineItem()"
                        class="px-3 py-1 bg-blue-600 text-white text-sm rounded hover:bg-blue-700">
                    <i class="fas fa-plus mr-1"></i>Ajouter
                </button>
            <?php endif; ?>
        </div>
    </div>

    <div id="line-items-container">
        <?php if (empty($lineItems)): ?>
            <div id="no-line-items" class="p-8 text-center text-gray-400">
                <i class="fas fa-file-invoice text-4xl mb-4"></i>
                <p>Aucune ligne extraite</p>
                <p class="text-sm mt-2">Cliquez sur "Extraire avec IA" pour parser automatiquement les lignes de cette facture</p>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">#</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Qté</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Code</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase w-1/3">Description</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">P.U.</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">TVA</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Compte</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200" id="line-items-tbody">
                        <?php foreach ($lineItems as $item): ?>
                            <tr class="line-item-row hover:bg-gray-50" data-id="<?= $item['id'] ?>">
                                <td class="px-4 py-3 text-sm text-gray-500"><?= $item['line_number'] ?></td>
                                <td class="px-4 py-3 text-sm">
                                    <input type="number" step="0.001" value="<?= $item['quantity'] ?>"
                                           class="line-qty w-16 text-sm border-gray-300 rounded"
                                           onchange="updateLineItem(<?= $item['id'] ?>)">
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    <input type="text" value="<?= htmlspecialchars($item['code'] ?? '') ?>"
                                           class="line-code w-20 text-sm border-gray-300 rounded"
                                           onchange="updateLineItem(<?= $item['id'] ?>)">
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    <input type="text" value="<?= htmlspecialchars($item['description']) ?>"
                                           class="line-description w-full text-sm border-gray-300 rounded"
                                           onchange="updateLineItem(<?= $item['id'] ?>)">
                                </td>
                                <td class="px-4 py-3 text-sm text-right">
                                    <input type="number" step="0.01" value="<?= $item['unit_price'] ?>"
                                           class="line-unit-price w-20 text-sm border-gray-300 rounded text-right"
                                           onchange="updateLineItem(<?= $item['id'] ?>)">
                                </td>
                                <td class="px-4 py-3 text-sm text-right">
                                    <span class="text-gray-500"><?= $item['tax_rate'] ?>%</span>
                                </td>
                                <td class="px-4 py-3 text-sm text-right font-medium">
                                    <?= number_format($item['line_total'] ?? 0, 2, '.', ' ') ?>
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    <select class="line-compte w-24 text-xs border-gray-300 rounded"
                                            onchange="updateLineItem(<?= $item['id'] ?>)">
                                        <option value="">--</option>
                                        <?php foreach ($fieldOptions['compte_comptable'] ?? [] as $opt): ?>
                                            <option value="<?= $opt['option_value'] ?>"
                                                    <?= $item['compte_comptable'] == $opt['option_value'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($opt['option_label']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <button onclick="deleteLineItem(<?= $item['id'] ?>)"
                                            class="text-red-400 hover:text-red-600">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="bg-gray-50">
                        <tr>
                            <td colspan="6" class="px-4 py-3 text-right font-medium text-gray-700">Sous-total HT:</td>
                            <td class="px-4 py-3 text-right font-medium" id="subtotal"><?= number_format($totals['subtotal'] ?? 0, 2, '.', ' ') ?></td>
                            <td colspan="2"></td>
                        </tr>
                        <tr>
                            <td colspan="6" class="px-4 py-3 text-right font-medium text-gray-700">TVA:</td>
                            <td class="px-4 py-3 text-right font-medium" id="total-tax"><?= number_format($totals['total_tax'] ?? 0, 2, '.', ' ') ?></td>
                            <td colspan="2"></td>
                        </tr>
                        <tr>
                            <td colspan="6" class="px-4 py-3 text-right font-bold text-gray-800">Total TTC:</td>
                            <td class="px-4 py-3 text-right font-bold text-lg" id="grand-total"><?= number_format($totals['grand_total'] ?? 0, 2, '.', ' ') ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
const lineItemsDocumentId = <?= $documentId ?>;

async function extractLineItems(force = false) {
    const btn = document.getElementById('extract-btn');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>Extraction...';
    }

    try {
        const response = await fetch(`<?= url('/api/documents') ?>/${lineItemsDocumentId}/line-items/extract`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || ''
            },
            body: JSON.stringify({ force: force })
        });

        const result = await response.json();

        if (result.success) {
            // Reload to show the extracted lines
            location.reload();
        } else {
            alert(result.message || 'Erreur lors de l\'extraction');
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-magic mr-1"></i>Extraire avec IA';
            }
        }
    } catch (e) {
        alert('Erreur: ' + e.message);
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-magic mr-1"></i>Extraire avec IA';
        }
    }
}

async function updateLineItem(lineId) {
    const row = document.querySelector(`.line-item-row[data-id="${lineId}"]`);
    if (!row) return;

    const data = {
        quantity: row.querySelector('.line-qty')?.value || null,
        code: row.querySelector('.line-code')?.value || null,
        description: row.querySelector('.line-description')?.value || '',
        unit_price: row.querySelector('.line-unit-price')?.value || null,
        compte_comptable: row.querySelector('.line-compte')?.value || null
    };

    try {
        const response = await fetch(`<?= url('/api/documents') ?>/${lineItemsDocumentId}/line-items/${lineId}`, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || ''
            },
            body: JSON.stringify(data)
        });

        const result = await response.json();
        if (!result.success) {
            console.error('Update failed:', result.message);
        }
    } catch (e) {
        console.error('Error updating line:', e);
    }
}

async function deleteLineItem(lineId) {
    if (!confirm('Supprimer cette ligne ?')) return;

    try {
        const response = await fetch(`<?= url('/api/documents') ?>/${lineItemsDocumentId}/line-items/${lineId}`, {
            method: 'DELETE',
            headers: {
                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || ''
            }
        });

        const result = await response.json();
        if (result.success) {
            document.querySelector(`.line-item-row[data-id="${lineId}"]`)?.remove();
            // Update totals via AJAX or reload
            location.reload();
        }
    } catch (e) {
        alert('Erreur: ' + e.message);
    }
}

function addLineItem() {
    // Simple: reload to form or add row
    // For now, just show a prompt
    const description = prompt('Description de la ligne:');
    if (!description) return;

    fetch(`<?= url('/api/documents') ?>/${lineItemsDocumentId}/line-items`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || ''
        },
        body: JSON.stringify({
            description: description,
            quantity: 1
        })
    }).then(r => r.json()).then(result => {
        if (result.success) {
            location.reload();
        }
    });
}
</script>
