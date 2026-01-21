<?php
// $stats est passé depuis le contrôleur
?>

<div class="space-y-6">
    <h1 class="text-2xl font-bold text-gray-800">Administration</h1>

    <!-- Statistiques -->
    <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500">Utilisateurs</p>
                    <p class="text-3xl font-bold text-gray-800"><?= $stats['users'] ?></p>
                </div>
                <div class="text-4xl">👥</div>
            </div>
            <a href="<?= url('/admin/users') ?>" class="mt-4 inline-block text-sm text-blue-600 hover:text-blue-800">
                Voir tous →
            </a>
        </div>

        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500">Documents</p>
                    <p class="text-3xl font-bold text-gray-800"><?= $stats['documents'] ?></p>
                </div>
                <div class="text-4xl">📄</div>
            </div>
            <a href="<?= url('/documents') ?>" class="mt-4 inline-block text-sm text-blue-600 hover:text-blue-800">
                Voir tous →
            </a>
        </div>

        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500">Tâches</p>
                    <p class="text-3xl font-bold text-gray-800"><?= $stats['tasks'] ?></p>
                </div>
                <div class="text-4xl">✅</div>
            </div>
            <a href="<?= url('/tasks') ?>" class="mt-4 inline-block text-sm text-blue-600 hover:text-blue-800">
                Voir toutes →
            </a>
        </div>

        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500">Types de documents</p>
                    <p class="text-3xl font-bold text-gray-800"><?= $stats['document_types'] ?></p>
                </div>
                <div class="text-4xl">🏷️</div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500">Correspondants</p>
                    <p class="text-3xl font-bold text-gray-800"><?= $stats['correspondents'] ?></p>
                </div>
                <div class="text-4xl">📧</div>
            </div>
        </div>
    </div>

    <!-- Actions rapides -->
    <div class="bg-white rounded-lg shadow p-6">
        <h2 class="text-xl font-bold text-gray-800 mb-4">Actions rapides</h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <a href="<?= url('/admin/users') ?>" class="p-4 border border-gray-200 rounded-lg hover:bg-gray-50">
                <div class="font-semibold text-gray-800">👥 Gérer les utilisateurs</div>
                <div class="text-sm text-gray-500 mt-1">Créer, modifier ou supprimer des utilisateurs</div>
            </a>
            <a href="<?= url('/admin/settings') ?>" class="p-4 border border-gray-200 rounded-lg hover:bg-gray-50">
                <div class="font-semibold text-gray-800">⚙️ Configuration</div>
                <div class="text-sm text-gray-500 mt-1">Paramètres du système</div>
            </a>
            <a href="<?= url('/documents/upload') ?>" class="p-4 border border-gray-200 rounded-lg hover:bg-gray-50">
                <div class="font-semibold text-gray-800">📤 Uploader un document</div>
                <div class="text-sm text-gray-500 mt-1">Ajouter un nouveau document</div>
            </a>
        </div>
    </div>
</div>
