<?php
/**
 * Template de login
 */
?>

<div class="ds-card py-8 px-6 shadow">
    <div class="text-center mb-8">
        <h1 class="text-3xl font-bold" style="color:var(--ink)">K-Docs</h1>
        <p class="mt-2 text-sm" style="color:var(--ink-soft)">Gestion Électronique de Documents</p>
    </div>

    <?php if (isset($error) && $error): ?>
        <div class="mb-4 border px-4 py-3 rounded" style="background:color-mix(in srgb, var(--red) 12%, transparent); border-color:color-mix(in srgb, var(--red) 40%, var(--border)); color:var(--red);">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <?php
    // La fonction url() est chargée depuis app/helpers.php
    ?>
    <form method="POST" action="<?= htmlspecialchars(url('/login')) ?>" class="space-y-6">
        <div>
            <label for="username" class="block text-sm font-medium" style="color:var(--ink-soft)">
                Nom d'utilisateur
            </label>
            <input
                type="text"
                id="username"
                name="username"
                required
                autofocus
                class="mt-1 block w-full px-3 py-2 border rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                style="background:var(--surface); color:var(--ink); border-color:var(--border);"
                value="<?= htmlspecialchars($username ?? '') ?>"
            >
        </div>

        <div>
            <label for="password" class="block text-sm font-medium" style="color:var(--ink-soft)">
                Mot de passe
            </label>
            <input
                type="password"
                id="password"
                name="password"
                class="mt-1 block w-full px-3 py-2 border rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                style="background:var(--surface); color:var(--ink); border-color:var(--border);"
            >
        </div>

        <div>
            <button
                type="submit"
                class="btn-primary w-full flex justify-center py-2 px-4 border border-transparent rounded-md text-sm font-medium focus:outline-none focus:ring-2 focus:ring-blue-500"
            >
                Se connecter
            </button>
        </div>
    </form>

    <?php if (isAppDebug()): ?>
    <div class="mt-6 text-center text-sm" style="color:var(--ink-soft)">
        <p>Compte par défaut : <code class="px-2 py-1 rounded" style="background:var(--rail); color:var(--ink-soft);">root</code> / mot de passe vide</p>
    </div>
    <?php endif; ?>
</div>
