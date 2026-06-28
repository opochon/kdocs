<?php
// Formulaire de création/édition de webhook
?>

<div class="max-w-4xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold" style="color:var(--ink)">
            <?= $webhook ? 'Modifier le webhook' : 'Créer un webhook' ?>
        </h1>
        <a href="<?= url('/admin/webhooks') ?>" class="btn-secondary border px-4 py-2 rounded-lg">
            ← Retour
        </a>
    </div>

    <form method="POST" action="<?= url($webhook ? '/admin/webhooks/' . $webhook['id'] . '/save' : '/admin/webhooks/save') ?>" class="p-6 rounded-lg shadow space-y-4" style="background:var(--surface)">
        <div>
            <label for="name" class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">Nom du webhook</label>
            <input 
                type="text" 
                id="name" 
                name="name" 
                value="<?= htmlspecialchars($webhook['name'] ?? '') ?>"
                class="form-input"
                required
                placeholder="Ex: Notification Slack"
            >
        </div>

        <div>
            <label for="url" class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">URL de destination</label>
            <input 
                type="url" 
                id="url" 
                name="url" 
                value="<?= htmlspecialchars($webhook['url'] ?? '') ?>"
                class="form-input"
                required
                placeholder="https://example.com/webhook"
            >
            <p class="mt-1 text-sm" style="color:var(--dim)">⚠️ L'URL doit utiliser HTTPS pour la sécurité.</p>
        </div>

        <div>
            <label class="block text-sm font-medium mb-2" style="color:var(--ink-soft)">Événements à écouter</label>
            <div class="grid grid-cols-2 gap-2 max-h-64 overflow-y-auto border rounded-lg p-4" style="border-color:var(--border)">
                <?php foreach ($availableEvents as $event => $label): ?>
                <label class="flex items-center space-x-2 cursor-pointer ds-row-hover p-2 rounded">
                    <input 
                        type="checkbox" 
                        name="events[]" 
                        value="<?= htmlspecialchars($event) ?>"
                        <?= ($webhook && in_array($event, $webhook['events'] ?? [])) ? 'checked' : '' ?>
                        class="h-4 w-4 rounded" style="accent-color:var(--accent)"
                    >
                    <span class="text-sm" style="color:var(--ink-soft)">
                        <?= htmlspecialchars($label) ?>
                    </span>
                    <code class="text-xs" style="color:var(--dim)"><?= htmlspecialchars($event) ?></code>
                </label>
                <?php endforeach; ?>
            </div>
            <p class="mt-1 text-sm" style="color:var(--dim)">Sélectionnez les événements qui déclencheront ce webhook.</p>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label for="timeout" class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">Timeout (secondes)</label>
                <input 
                    type="number" 
                    id="timeout" 
                    name="timeout" 
                    value="<?= htmlspecialchars($webhook['timeout'] ?? 30) ?>"
                    min="1"
                    max="300"
                    class="form-input"
                >
            </div>

            <div>
                <label for="retry_count" class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">Nombre de tentatives</label>
                <input 
                    type="number" 
                    id="retry_count" 
                    name="retry_count" 
                    value="<?= htmlspecialchars($webhook['retry_count'] ?? 3) ?>"
                    min="0"
                    max="10"
                    class="form-input"
                >
            </div>
        </div>

        <div>
            <label class="flex items-center space-x-2">
                <input 
                    type="checkbox" 
                    name="is_active" 
                    value="1"
                    <?= ($webhook['is_active'] ?? true) ? 'checked' : '' ?>
                    class="h-4 w-4 rounded" style="accent-color:var(--accent)"
                >
                <span class="text-sm font-medium" style="color:var(--ink-soft)">Webhook actif</span>
            </label>
        </div>

        <?php if ($webhook): ?>
        <div class="p-4 rounded-lg" style="background:var(--app-bg)">
            <p class="text-sm font-medium mb-2" style="color:var(--ink-soft)">Secret (pour signature HMAC)</p>
            <code class="text-xs break-all" style="color:var(--ink-soft)"><?= htmlspecialchars($webhook['secret']) ?></code>
            <p class="mt-2 text-xs" style="color:var(--dim)">Ce secret est utilisé pour signer les requêtes webhook. Ne le partagez pas.</p>
        </div>
        <?php endif; ?>

        <div class="flex justify-end space-x-2">
            <a href="<?= url('/admin/webhooks') ?>" class="btn-secondary border px-4 py-2 rounded-lg">Annuler</a>
            <button type="submit" class="btn-primary px-4 py-2 rounded-lg">
                <?= $webhook ? 'Modifier' : 'Créer' ?>
            </button>
        </div>
    </form>
</div>
