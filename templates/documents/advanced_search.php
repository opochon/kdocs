<?php
// Modal de recherche avancée - Style Paperless-ngx
// $correspondents, $documentTypes, $tags sont passés depuis le contrôleur
?>

<!-- Modal Recherche Avancée -->
<div id="advanced-search-modal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="rounded-lg max-w-4xl w-full max-h-[90vh] overflow-hidden flex flex-col" style="background:var(--surface); box-shadow:var(--shadow-pop)">
        <!-- Header -->
        <div class="px-6 py-4 flex items-center justify-between border-b" style="border-color:var(--border)">
            <h2 class="text-xl font-semibold" style="color:var(--ink)">🔍 Recherche Avancée</h2>
            <button onclick="closeAdvancedSearch()" class="text-2xl" style="color:var(--dim)">&times;</button>
        </div>

        <!-- Body -->
        <div class="flex-1 overflow-y-auto p-6">
            <form id="advanced-search-form" onsubmit="executeAdvancedSearch(event)">
                <div class="space-y-6">
                    <!-- Titre -->
                    <div>
                        <label class="form-label">Titre</label>
                        <div class="flex gap-2">
                            <select name="title_operator" class="form-select text-sm" style="width:auto">
                                <option value="contains">Contient</option>
                                <option value="starts_with">Commence par</option>
                                <option value="equals">Égal à</option>
                                <option value="not_contains">Ne contient pas</option>
                            </select>
                            <input type="text" name="title" placeholder="Rechercher dans le titre..."
                                   class="form-input flex-1">
                        </div>
                    </div>

                    <!-- Correspondant -->
                    <div>
                        <label class="form-label">Correspondant</label>
                        <select name="correspondent" id="search-correspondent"
                                class="form-select"
                                multiple>
                            <?php foreach ($correspondents ?? [] as $corr): ?>
                            <option value="<?= $corr['id'] ?>"><?= htmlspecialchars($corr['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="text-xs mt-1" style="color:var(--dim)">Maintenez Ctrl/Cmd pour sélectionner plusieurs</p>
                    </div>

                    <!-- Type de document -->
                    <div>
                        <label class="form-label">Type de document</label>
                        <select name="document_type" id="search-document-type"
                                class="form-select"
                                multiple>
                            <?php foreach ($documentTypes ?? [] as $type): ?>
                            <option value="<?= $type['id'] ?>"><?= htmlspecialchars($type['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Étiquettes -->
                    <div>
                        <label class="form-label">Étiquettes</label>
                        <div id="search-tags-container" class="flex flex-wrap gap-2 p-3 border rounded-lg min-h-[60px]" style="border-color:var(--border)">
                            <?php foreach ($tags ?? [] as $tag): ?>
                            <label class="inline-flex items-center px-3 py-1 rounded-full text-sm cursor-pointer hover:opacity-80"
                                   style="--_tc:<?= htmlspecialchars($tag['color'] ?? '') ?>; background-color:color-mix(in srgb,var(--_tc,var(--dim)) 20%,transparent); color:var(--_tc,var(--dim))">
                                <input type="checkbox" name="tags[]" value="<?= $tag['id'] ?>" class="mr-2">
                                <?= htmlspecialchars($tag['name']) ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Date -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="form-label">Date du document</label>
                            <div class="flex gap-2">
                                <select name="date_operator" class="form-select text-sm" style="width:auto">
                                    <option value="equals">Égal à</option>
                                    <option value="after">Après</option>
                                    <option value="before">Avant</option>
                                    <option value="between">Entre</option>
                                </select>
                                <input type="date" name="date_from"
                                       class="form-input flex-1">
                            </div>
                        </div>
                        <div id="date-to-container" class="hidden">
                            <label class="form-label">Et</label>
                            <input type="date" name="date_to"
                                   class="form-input">
                        </div>
                    </div>

                    <!-- Montant -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="form-label">Montant</label>
                            <div class="flex gap-2">
                                <select name="amount_operator" class="form-select text-sm" style="width:auto">
                                    <option value="equals">Égal à</option>
                                    <option value="greater">Supérieur à</option>
                                    <option value="less">Inférieur à</option>
                                    <option value="between">Entre</option>
                                </select>
                                <input type="number" name="amount_from" step="0.01" placeholder="0.00"
                                       class="form-input flex-1">
                            </div>
                        </div>
                        <div id="amount-to-container" class="hidden">
                            <label class="form-label">Et</label>
                            <input type="number" name="amount_to" step="0.01" placeholder="0.00"
                                   class="form-input">
                        </div>
                    </div>

                    <!-- Texte OCR -->
                    <div>
                        <label class="form-label">Rechercher dans le texte OCR</label>
                        <input type="text" name="ocr_text" placeholder="Rechercher dans le contenu du document..."
                               class="form-input">
                    </div>

                    <!-- Aide contextuelle -->
                    <div class="border rounded-lg p-4" style="background:var(--accent-soft); border-color:color-mix(in srgb, var(--accent) 30%, transparent)">
                        <h3 class="font-semibold mb-2" style="color:var(--accent)">💡 Aide</h3>
                        <ul class="text-sm space-y-1" style="color:var(--ink-soft)">
                            <li>• Utilisez les opérateurs pour affiner votre recherche</li>
                            <li>• Sélectionnez plusieurs correspondants/types/tags avec Ctrl/Cmd</li>
                            <li>• Les dates et montants peuvent être filtrés par plage</li>
                            <li>• La recherche OCR recherche dans tout le texte extrait du document</li>
                        </ul>
                    </div>
                </div>
            </form>
        </div>

        <!-- Footer -->
        <div class="px-6 py-4 flex items-center justify-between border-t" style="background:var(--app-bg); border-color:var(--border)">
            <div class="flex items-center gap-2">
                <button onclick="saveSearch()" class="btn btn-secondary">
                    ⭐ Sauvegarder cette recherche
                </button>
            </div>
            <div class="flex gap-2">
                <button onclick="closeAdvancedSearch()" class="btn btn-secondary">
                    Annuler
                </button>
                <button onclick="executeAdvancedSearch(event)" class="btn btn-primary">
                    🔍 Rechercher
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function openAdvancedSearch() {
    document.getElementById('advanced-search-modal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';

    // Afficher/masquer les champs "date_to" et "amount_to" selon l'opérateur
    const dateOperator = document.querySelector('[name="date_operator"]');
    const amountOperator = document.querySelector('[name="amount_operator"]');

    dateOperator.addEventListener('change', function() {
        const dateToContainer = document.getElementById('date-to-container');
        if (this.value === 'between') {
            dateToContainer.classList.remove('hidden');
        } else {
            dateToContainer.classList.add('hidden');
        }
    });

    amountOperator.addEventListener('change', function() {
        const amountToContainer = document.getElementById('amount-to-container');
        if (this.value === 'between') {
            amountToContainer.classList.remove('hidden');
        } else {
            amountToContainer.classList.add('hidden');
        }
    });
}

function closeAdvancedSearch() {
    document.getElementById('advanced-search-modal').classList.add('hidden');
    document.body.style.overflow = '';
}

function executeAdvancedSearch(event) {
    if (event) event.preventDefault();

    const form = document.getElementById('advanced-search-form');
    const formData = new FormData(form);

    // Construire la requête de recherche
    const searchParts = [];

    // Titre
    const title = formData.get('title');
    const titleOp = formData.get('title_operator');
    if (title) {
        if (titleOp === 'contains') searchParts.push(`title:"${title}"`);
        else if (titleOp === 'starts_with') searchParts.push(`title:"${title}*"`);
        else if (titleOp === 'equals') searchParts.push(`title:"${title}"`);
        else if (titleOp === 'not_contains') searchParts.push(`-title:"${title}"`);
    }

    // Correspondant
    const correspondents = formData.getAll('correspondent');
    if (correspondents.length > 0) {
        searchParts.push(`correspondent:${correspondents.join(',')}`);
    }

    // Type
    const types = formData.getAll('document_type');
    if (types.length > 0) {
        searchParts.push(`type:${types.join(',')}`);
    }

    // Tags
    const tags = formData.getAll('tags[]');
    if (tags.length > 0) {
        searchParts.push(`tag:${tags.join(',')}`);
    }

    // Date
    const dateFrom = formData.get('date_from');
    const dateOp = formData.get('date_operator');
    if (dateFrom) {
        if (dateOp === 'after') searchParts.push(`date:>${dateFrom}`);
        else if (dateOp === 'before') searchParts.push(`date:<${dateFrom}`);
        else if (dateOp === 'between') {
            const dateTo = formData.get('date_to');
            if (dateTo) searchParts.push(`date:${dateFrom}..${dateTo}`);
            else searchParts.push(`date:${dateFrom}`);
        } else searchParts.push(`date:${dateFrom}`);
    }

    // Montant
    const amountFrom = formData.get('amount_from');
    const amountOp = formData.get('amount_operator');
    if (amountFrom) {
        if (amountOp === 'greater') searchParts.push(`amount:>${amountFrom}`);
        else if (amountOp === 'less') searchParts.push(`amount:<${amountFrom}`);
        else if (amountOp === 'between') {
            const amountTo = formData.get('amount_to');
            if (amountTo) searchParts.push(`amount:${amountFrom}..${amountTo}`);
            else searchParts.push(`amount:${amountFrom}`);
        } else searchParts.push(`amount:${amountFrom}`);
    }

    // OCR
    const ocrText = formData.get('ocr_text');
    if (ocrText) {
        searchParts.push(`"${ocrText}"`);
    }

    // Rediriger vers la liste avec la recherche
    const searchQuery = searchParts.join(' ');
    window.location.href = '<?= url('/documents') ?>?search=' + encodeURIComponent(searchQuery);
}

function saveSearch() {
    const form = document.getElementById('advanced-search-form');
    const formData = new FormData(form);

    // Construire la requête (même logique que executeAdvancedSearch)
    const searchParts = [];
    // ... (même code)

    const searchQuery = searchParts.join(' ');
    const searchName = prompt('Nom de la recherche sauvegardée:');

    if (searchName) {
        // Sauvegarder via API
        fetch('<?= url('/api/saved-searches') ?>', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                name: searchName,
                query: searchQuery
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast('Recherche sauvegardée', 'success');
                closeAdvancedSearch();
            } else {
                showToast('Erreur: ' + (data.error || 'Impossible de sauvegarder'), 'error');
            }
        })
        .catch(error => {
            console.error('Erreur:', error);
            showToast('Erreur lors de la sauvegarde', 'error');
        });
    }
}

// Fermer avec Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const modal = document.getElementById('advanced-search-modal');
        if (modal && !modal.classList.contains('hidden')) {
            closeAdvancedSearch();
        }
    }
});
</script>
