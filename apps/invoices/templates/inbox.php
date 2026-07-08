<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($title ?? 'K-Invoices') ?></title>
    <!-- no CDN styles — stub page uses inline Tailwind classes only -->
</head>
<body class="bg-gray-50 p-8">
    <h1 class="text-2xl font-semibold mb-4"><?= htmlspecialchars($title ?? 'K-Invoices') ?></h1>
    <p class="text-gray-600 mb-4">Module factures fournisseurs — stub opérationnel.</p>
    <?php if (!empty($invoice_id)): ?>
        <a class="text-blue-600 underline" href="/invoices/<?= (int)$invoice_id ?>/matching">Rapprochement WinBiz</a>
    <?php endif; ?>
</body>
</html>
