<?php
// $documentTypes, $correspondents, $error, $success sont passés depuis le contrôleur
?>

<div class="max-w-2xl mx-auto space-y-6">
    <h1 class="text-2xl font-bold" style="color:var(--ink)">Uploader un document</h1>

    <?php if ($error): ?>
        <div class="border px-4 py-3 rounded" style="background:color-mix(in srgb, var(--red) 12%, transparent); border-color:color-mix(in srgb, var(--red) 35%, transparent); color:var(--red)">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="border px-4 py-3 rounded" style="background:color-mix(in srgb, var(--green) 12%, transparent); border-color:color-mix(in srgb, var(--green) 35%, transparent); color:var(--green)">
            <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="<?= url('/documents/upload') ?>" enctype="multipart/form-data" class="ds-card p-6 space-y-6">
        <div>
            <label for="file" class="form-label">
                Fichier <span style="color:var(--red)">*</span>
            </label>
            <input
                type="file"
                id="file"
                name="file"
                required
                accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.txt"
                class="block w-full text-sm file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold"
                style="color:var(--dim)"
            >
            <p class="mt-1 text-sm" style="color:var(--dim)">Formats acceptés : PDF, DOC, DOCX, JPG, PNG, TXT</p>
        </div>

        <div>
            <label for="title" class="form-label">
                Titre
            </label>
            <input
                type="text"
                id="title"
                name="title"
                class="form-input"
                placeholder="Titre du document (optionnel)"
            >
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <label for="document_type_id" class="form-label">
                    Type de document
                </label>
                <select
                    id="document_type_id"
                    name="document_type_id"
                    class="form-select"
                >
                    <option value="">-- Sélectionner --</option>
                    <?php foreach ($documentTypes as $type): ?>
                        <option value="<?= $type['id'] ?>"><?= htmlspecialchars($type['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label for="correspondent_id" class="form-label">
                    Correspondant
                </label>
                <select
                    id="correspondent_id"
                    name="correspondent_id"
                    class="form-select"
                >
                    <option value="">-- Sélectionner --</option>
                    <?php foreach ($correspondents as $corr): ?>
                        <option value="<?= $corr['id'] ?>"><?= htmlspecialchars($corr['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div>
                <label for="doc_date" class="form-label">
                    Date du document
                </label>
                <input
                    type="date"
                    id="doc_date"
                    name="doc_date"
                    class="form-input"
                >
            </div>

            <div>
                <label for="amount" class="form-label">
                    Montant
                </label>
                <input
                    type="number"
                    step="0.01"
                    id="amount"
                    name="amount"
                    class="form-input"
                    placeholder="0.00"
                >
            </div>

            <div>
                <label for="currency" class="form-label">
                    Devise
                </label>
                <select
                    id="currency"
                    name="currency"
                    class="form-select"
                >
                    <option value="CHF" selected>CHF</option>
                    <option value="EUR">EUR</option>
                    <option value="USD">USD</option>
                </select>
            </div>
        </div>

        <div class="flex items-center justify-end space-x-4">
            <a href="<?= url('/documents') ?>" class="btn btn-secondary">
                Annuler
            </a>
            <button
                type="submit"
                class="btn btn-primary"
            >
                Uploader
            </button>
        </div>
    </form>
</div>
