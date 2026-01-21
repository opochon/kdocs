<?php
/**
 * Template de login
 */
?>

<div class="bg-white py-8 px-6 shadow rounded-lg">
    <div class="text-center mb-8">
        <h1 class="text-3xl font-bold text-gray-900">K-Docs</h1>
        <p class="mt-2 text-sm text-gray-600">Gestion Électronique de Documents</p>
    </div>

    <?php if (isset($error) && $error): ?>
        <div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <?php
    // La fonction url() est chargée depuis app/helpers.php
    ?>
    <form method="POST" action="<?= htmlspecialchars(url('/login')) ?>" class="space-y-6">
        <div>
            <label for="username" class="block text-sm font-medium text-gray-700">
                Nom d'utilisateur
            </label>
            <input 
                type="text" 
                id="username" 
                name="username" 
                required
                autofocus
                class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                value="<?= htmlspecialchars($username ?? '') ?>"
            >
        </div>

        <div>
            <label for="password" class="block text-sm font-medium text-gray-700">
                Mot de passe
            </label>
            <input 
                type="password" 
                id="password" 
                name="password" 
                class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
            >
        </div>

        <div>
            <button 
                type="submit"
                class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
            >
                Se connecter
            </button>
        </div>
    </form>

    <div class="mt-6 text-center text-sm text-gray-600">
        <p>Compte par défaut : <code class="bg-gray-100 px-2 py-1 rounded">root</code> / mot de passe vide</p>
    </div>
</div>
