<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Rapprochement facture #<?= (int)($invoice_id ?? 0) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 p-8">
    <h1 class="text-2xl font-semibold mb-2">Rapprochement facture #<?= (int)($invoice_id ?? 0) ?></h1>
    <p class="text-gray-600 mb-6">Suggestions facture ↔ bon de livraison WinBiz</p>

    <table class="w-full bg-white border rounded shadow-sm text-sm">
        <thead class="bg-gray-100">
            <tr>
                <th class="p-2 text-left">Ligne facture</th>
                <th class="p-2 text-left">Suggestion BL</th>
                <th class="p-2 text-right">Confiance</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($matches ?? [] as $row): ?>
            <?php
            $line = $row['invoice_line'] ?? [];
            $suggestion = $row['suggestion']['bl_line'] ?? null;
            ?>
            <tr class="border-t">
                <td class="p-2">
                    <?= htmlspecialchars(($line['code'] ?? '') . ' — ' . ($line['designation'] ?? '')) ?>
                    <span class="text-gray-400">(<?= (float)($line['quantity'] ?? 0) ?>)</span>
                </td>
                <td class="p-2">
                    <?php if ($suggestion): ?>
                        <?= htmlspecialchars(($suggestion['code'] ?? '') . ' — ' . ($suggestion['designation'] ?? '')) ?>
                    <?php else: ?>
                        <span class="text-gray-400">Aucune suggestion</span>
                    <?php endif; ?>
                </td>
                <td class="p-2 text-right"><?= (float)($row['confidence'] ?? 0) ?>%</td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>
