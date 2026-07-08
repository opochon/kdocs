<?php
// Liste des webhooks
?>

<div class="max-w-7xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold" style="color:var(--ink)">Webhooks</h1>
        <a href="<?= url('/admin/webhooks/create') ?>" class="btn btn-primary">
            + Créer un webhook
        </a>
    </div>

    <div class="ds-card rounded-lg shadow overflow-hidden">
        <table class="ds-table">
            <thead>
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Nom</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">URL</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Événements</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Statut</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Statistiques (7j)</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($webhooks)): ?>
                <tr>
                    <td colspan="6" class="px-6 py-8 text-center" style="color:var(--dim)">
                        Aucun webhook configuré. <a href="<?= url('/admin/webhooks/create') ?>" style="color:var(--accent)">Créer le premier webhook</a>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($webhooks as $webhook): ?>
                <tr>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="font-medium" style="color:var(--ink)"><?= htmlspecialchars($webhook['name']) ?></div>
                    </td>
                    <td class="px-6 py-4">
                        <div class="text-sm max-w-md truncate" style="color:var(--ink-soft)" title="<?= htmlspecialchars($webhook['url']) ?>">
                            <?= htmlspecialchars($webhook['url']) ?>
                        </div>
                    </td>
                    <td class="px-6 py-4">
                        <div class="flex flex-wrap gap-1">
                            <?php foreach ($webhook['events'] as $event): ?>
                            <span class="ds-chip ds-chip--neutral px-2 py-1 text-xs">
                                <?= htmlspecialchars($event) ?>
                            </span>
                            <?php endforeach; ?>
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <?php if ($webhook['is_active']): ?>
                        <span class="ds-chip ds-chip--green px-2 py-1 text-xs">Actif</span>
                        <?php else: ?>
                        <span class="ds-chip ds-chip--neutral px-2 py-1 text-xs">Inactif</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm" style="color:var(--ink-soft)">
                        <?php if (!empty($webhook['stats'])): ?>
                        <div>
                            <span class="font-medium"><?= number_format($webhook['stats']['total_executions'] ?? 0) ?></span> exécutions
                        </div>
                        <div class="text-xs">
                            <?= number_format($webhook['stats']['success_count'] ?? 0) ?> réussies,
                            <?= number_format($webhook['stats']['error_count'] ?? 0) ?> erreurs
                        </div>
                        <?php else: ?>
                        <span style="color:var(--dim)">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                        <div class="flex items-center space-x-2">
                            <a href="<?= url('/admin/webhooks/' . $webhook['id'] . '/logs') ?>" style="color:var(--accent)">
                                Logs
                            </a>
                            <button onclick="testWebhook(<?= $webhook['id'] ?>)" style="color:var(--accent)">
                                Test
                            </button>
                            <a href="<?= url('/admin/webhooks/' . $webhook['id'] . '/edit') ?>" style="color:var(--ink-soft)">
                                Modifier
                            </a>
                            <form method="POST" action="<?= url('/admin/webhooks/' . $webhook['id'] . '/delete') ?>" class="inline" onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer ce webhook ?');">
                                <button type="submit" style="color:var(--red)">
                                    Supprimer
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function testWebhook(webhookId) {
    fetch('<?= url('/admin/webhooks') ?>/' + webhookId + '/test', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Webhook de test envoyé avec succès !');
        } else {
            alert('Erreur : ' + (data.error || 'Erreur inconnue'));
        }
    })
    .catch(error => {
        alert('Erreur lors du test du webhook : ' + error.message);
    });
}
</script>
