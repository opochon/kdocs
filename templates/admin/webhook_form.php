<?php
// Formulaire de création/édition de webhook
?>

<div class="max-w-4xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">
            <?= $webhook ? 'Modifier le webhook' : 'Créer un webhook' ?>
        </h1>
        <a href="<?= url('/admin/webhooks') ?>" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700">
            ← Retour
        </a>
    </div>

    <form method="POST" action="<?= url($webhook ? '/admin/webhooks/' . $webhook['id'] . '/save' : '/admin/webhooks/save') ?>" class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow space-y-4">
        <div>
            <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Nom du webhook</label>
            <input 
                type="text" 
                id="name" 
                name="name" 
                value="<?= htmlspecialchars($webhook['name'] ?? '') ?>"
                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100"
                required
                placeholder="Ex: Notification Slack"
            >
        </div>

        <div>
            <label for="url" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">URL de destination</label>
            <input 
                type="url" 
                id="url" 
                name="url" 
                value="<?= htmlspecialchars($webhook['url'] ?? '') ?>"
                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100"
                required
                placeholder="https://example.com/webhook"
            >
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">⚠️ L'URL doit utiliser HTTPS pour la sécurité.</p>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Événements à écouter</label>
            <div class="grid grid-cols-2 gap-2 max-h-64 overflow-y-auto border border-gray-300 dark:border-gray-600 rounded-lg p-4">
                <?php foreach ($availableEvents as $event => $label): ?>
                <label class="flex items-center space-x-2 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 p-2 rounded">
                    <input 
                        type="checkbox" 
                        name="events[]" 
                        value="<?= htmlspecialchars($event) ?>"
                        <?= ($webhook && in_array($event, $webhook['events'] ?? [])) ? 'checked' : '' ?>
                        class="h-4 w-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500"
                    >
                    <span class="text-sm text-gray-700 dark:text-gray-300">
                        <?= htmlspecialchars($label) ?>
                    </span>
                    <code class="text-xs text-gray-500 dark:text-gray-400"><?= htmlspecialchars($event) ?></code>
                </label>
                <?php endforeach; ?>
            </div>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Sélectionnez les événements qui déclencheront ce webhook.</p>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label for="timeout" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Timeout (secondes)</label>
                <input 
                    type="number" 
                    id="timeout" 
                    name="timeout" 
                    value="<?= htmlspecialchars($webhook['timeout'] ?? 30) ?>"
                    min="1"
                    max="300"
                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100"
                >
            </div>

            <div>
                <label for="retry_count" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Nombre de tentatives</label>
                <input 
                    type="number" 
                    id="retry_count" 
                    name="retry_count" 
                    value="<?= htmlspecialchars($webhook['retry_count'] ?? 3) ?>"
                    min="0"
                    max="10"
                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100"
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
                    class="h-4 w-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500"
                >
                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Webhook actif</span>
            </label>
        </div>

        <?php if ($webhook): ?>
        <div class="bg-gray-50 dark:bg-gray-700 p-4 rounded-lg">
            <p class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Secret (pour signature HMAC)</p>
            <code class="text-xs text-gray-600 dark:text-gray-400 break-all"><?= htmlspecialchars($webhook['secret']) ?></code>
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Ce secret est utilisé pour signer les requêtes webhook. Ne le partagez pas.</p>
        </div>
        <?php endif; ?>

        <div class="flex justify-end space-x-2">
            <a href="<?= url('/admin/webhooks') ?>" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">Annuler</a>
            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                <?= $webhook ? 'Modifier' : 'Créer' ?>
            </button>
        </div>
    </form>
</div>
