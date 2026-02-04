<?php
// $correspondent est passé depuis le contrôleur (null si création)
$isEdit = !empty($correspondent);
?>

<div class="max-w-3xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-gray-800"><?= $isEdit ? 'Modifier' : 'Créer' ?> un correspondant</h1>
        <a href="<?= url('/admin/correspondents') ?>" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
            &larr; Retour
        </a>
    </div>

    <div class="bg-white rounded-lg shadow">
        <form method="POST" action="<?= url('/admin/correspondents' . ($isEdit ? '/' . $correspondent['id'] : '') . '/save') ?>">

            <!-- Section: Informations de base -->
            <div class="p-6 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-900 mb-4">Informations de base</h2>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- Type de correspondant -->
                    <div>
                        <label for="type" class="block text-sm font-medium text-gray-700 mb-1">Type *</label>
                        <select id="type"
                                name="type"
                                required
                                onchange="toggleTypeFields()"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="personne" <?= ($correspondent['type'] ?? 'personne') === 'personne' ? 'selected' : '' ?>>Personne</option>
                            <option value="entreprise" <?= ($correspondent['type'] ?? '') === 'entreprise' ? 'selected' : '' ?>>Entreprise</option>
                            <option value="administration" <?= ($correspondent['type'] ?? '') === 'administration' ? 'selected' : '' ?>>Administration</option>
                        </select>
                    </div>

                    <!-- Type de contact -->
                    <div>
                        <label for="type_contact" class="block text-sm font-medium text-gray-700 mb-1">Relation</label>
                        <select id="type_contact"
                                name="type_contact"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">-- Non défini --</option>
                            <option value="client" <?= ($correspondent['type_contact'] ?? '') === 'client' ? 'selected' : '' ?>>Client</option>
                            <option value="fournisseur" <?= ($correspondent['type_contact'] ?? '') === 'fournisseur' ? 'selected' : '' ?>>Fournisseur</option>
                            <option value="administration" <?= ($correspondent['type_contact'] ?? '') === 'administration' ? 'selected' : '' ?>>Administration</option>
                            <option value="partenaire" <?= ($correspondent['type_contact'] ?? '') === 'partenaire' ? 'selected' : '' ?>>Partenaire</option>
                            <option value="autre" <?= ($correspondent['type_contact'] ?? '') === 'autre' ? 'selected' : '' ?>>Autre</option>
                        </select>
                    </div>
                </div>

                <!-- Champs selon le type -->
                <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- Nom entreprise (si entreprise/administration) -->
                    <div id="field-nom-entreprise" class="hidden md:col-span-2">
                        <label for="nom_entreprise" class="block text-sm font-medium text-gray-700 mb-1">Raison sociale *</label>
                        <input
                            type="text"
                            id="nom_entreprise"
                            name="nom_entreprise"
                            value="<?= htmlspecialchars($correspondent['nom_entreprise'] ?? '') ?>"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                            placeholder="Ex: ACME Corporation SA"
                        >
                    </div>

                    <!-- Nom (toujours visible) -->
                    <div>
                        <label for="name" class="block text-sm font-medium text-gray-700 mb-1">
                            <span id="label-name">Nom *</span>
                        </label>
                        <input
                            type="text"
                            id="name"
                            name="name"
                            value="<?= htmlspecialchars($correspondent['name'] ?? '') ?>"
                            required
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                            placeholder="Ex: Dupont"
                        >
                    </div>

                    <!-- Prénom (si personne) -->
                    <div id="field-prenom">
                        <label for="prenom" class="block text-sm font-medium text-gray-700 mb-1">Prénom</label>
                        <input
                            type="text"
                            id="prenom"
                            name="prenom"
                            value="<?= htmlspecialchars($correspondent['prenom'] ?? '') ?>"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                            placeholder="Ex: Jean"
                        >
                    </div>
                </div>
            </div>

            <!-- Section: Coordonnées -->
            <div class="p-6 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-900 mb-4">Coordonnées</h2>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            value="<?= htmlspecialchars($correspondent['email'] ?? '') ?>"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                            placeholder="contact@example.com"
                        >
                    </div>

                    <div>
                        <label for="telephone" class="block text-sm font-medium text-gray-700 mb-1">Téléphone</label>
                        <input
                            type="tel"
                            id="telephone"
                            name="telephone"
                            value="<?= htmlspecialchars($correspondent['telephone'] ?? '') ?>"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                            placeholder="+41 21 123 45 67"
                        >
                    </div>

                    <div class="md:col-span-2">
                        <label for="address" class="block text-sm font-medium text-gray-700 mb-1">Adresse</label>
                        <input
                            type="text"
                            id="address"
                            name="address"
                            value="<?= htmlspecialchars($correspondent['address'] ?? '') ?>"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                            placeholder="Rue et numéro"
                        >
                    </div>

                    <div>
                        <label for="npa" class="block text-sm font-medium text-gray-700 mb-1">NPA / Code postal</label>
                        <input
                            type="text"
                            id="npa"
                            name="npa"
                            value="<?= htmlspecialchars($correspondent['npa'] ?? '') ?>"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                            placeholder="1000"
                        >
                    </div>

                    <div>
                        <label for="ville" class="block text-sm font-medium text-gray-700 mb-1">Ville</label>
                        <input
                            type="text"
                            id="ville"
                            name="ville"
                            value="<?= htmlspecialchars($correspondent['ville'] ?? '') ?>"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                            placeholder="Lausanne"
                        >
                    </div>

                    <div>
                        <label for="pays" class="block text-sm font-medium text-gray-700 mb-1">Pays</label>
                        <input
                            type="text"
                            id="pays"
                            name="pays"
                            value="<?= htmlspecialchars($correspondent['pays'] ?? 'Suisse') ?>"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                        >
                    </div>
                </div>
            </div>

            <!-- Section: Détection automatique -->
            <div class="p-6 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-900 mb-4">Détection automatique</h2>
                <p class="text-sm text-gray-500 mb-4">Configure comment K-Docs détecte automatiquement ce correspondant dans les documents.</p>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="match" class="block text-sm font-medium text-gray-700 mb-1">Expression de match</label>
                        <input
                            type="text"
                            id="match"
                            name="match"
                            value="<?= htmlspecialchars($correspondent['match'] ?? '') ?>"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                            placeholder="Texte à rechercher dans les documents"
                        >
                    </div>

                    <div>
                        <label for="matching_algorithm" class="block text-sm font-medium text-gray-700 mb-1">Algorithme</label>
                        <select id="matching_algorithm"
                                name="matching_algorithm"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="none" <?= ($correspondent['matching_algorithm'] ?? 'none') === 'none' ? 'selected' : '' ?>>Aucun</option>
                            <option value="any" <?= ($correspondent['matching_algorithm'] ?? '') === 'any' ? 'selected' : '' ?>>N'importe lequel</option>
                            <option value="all" <?= ($correspondent['matching_algorithm'] ?? '') === 'all' ? 'selected' : '' ?>>Tous les mots</option>
                            <option value="exact" <?= ($correspondent['matching_algorithm'] ?? '') === 'exact' ? 'selected' : '' ?>>Exact</option>
                            <option value="regex" <?= ($correspondent['matching_algorithm'] ?? '') === 'regex' ? 'selected' : '' ?>>Regex</option>
                            <option value="fuzzy" <?= ($correspondent['matching_algorithm'] ?? '') === 'fuzzy' ? 'selected' : '' ?>>Fuzzy</option>
                            <option value="auto" <?= ($correspondent['matching_algorithm'] ?? '') === 'auto' ? 'selected' : '' ?>>Auto (ML)</option>
                        </select>
                    </div>
                </div>

                <div class="mt-3">
                    <label class="flex items-center">
                        <input type="checkbox" name="is_insensitive" value="1"
                               <?= ($correspondent['is_insensitive'] ?? true) ? 'checked' : '' ?>
                               class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        <span class="ml-2 text-sm text-gray-600">Insensible à la casse</span>
                    </label>
                </div>
            </div>

            <!-- Section: Intégration ERP -->
            <div class="p-6 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-900 mb-4">Intégration ERP</h2>
                <p class="text-sm text-gray-500 mb-4">Pour liaison avec un système externe (comptabilité, CRM, etc.)</p>

                <div>
                    <label for="reference_erp" class="block text-sm font-medium text-gray-700 mb-1">Référence ERP</label>
                    <input
                        type="text"
                        id="reference_erp"
                        name="reference_erp"
                        value="<?= htmlspecialchars($correspondent['reference_erp'] ?? '') ?>"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                        placeholder="Ex: CLI-001234 ou identifiant système externe"
                    >
                </div>
            </div>

            <!-- Section: Notes -->
            <div class="p-6 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-900 mb-4">Notes internes</h2>

                <div>
                    <textarea
                        id="notes"
                        name="notes"
                        rows="3"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                        placeholder="Notes internes (non visibles dans les documents)"
                    ><?= htmlspecialchars($correspondent['notes'] ?? '') ?></textarea>
                </div>
            </div>

            <!-- Actions -->
            <div class="p-6 flex items-center justify-end gap-3 bg-gray-50 rounded-b-lg">
                <a href="<?= url('/admin/correspondents') ?>" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-100 text-gray-700">
                    Annuler
                </a>
                <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-medium">
                    <?= $isEdit ? 'Enregistrer les modifications' : 'Créer le correspondant' ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Afficher/masquer les champs selon le type
function toggleTypeFields() {
    const type = document.getElementById('type').value;
    const fieldNomEntreprise = document.getElementById('field-nom-entreprise');
    const fieldPrenom = document.getElementById('field-prenom');
    const labelName = document.getElementById('label-name');

    if (type === 'entreprise' || type === 'administration') {
        fieldNomEntreprise.classList.remove('hidden');
        fieldPrenom.classList.add('hidden');
        labelName.textContent = 'Personne de contact';
        document.getElementById('name').placeholder = 'Nom du contact principal';
        document.getElementById('name').required = false;
        document.getElementById('nom_entreprise').required = true;
    } else {
        fieldNomEntreprise.classList.add('hidden');
        fieldPrenom.classList.remove('hidden');
        labelName.textContent = 'Nom *';
        document.getElementById('name').placeholder = 'Ex: Dupont';
        document.getElementById('name').required = true;
        document.getElementById('nom_entreprise').required = false;
    }
}

// Initialiser au chargement
document.addEventListener('DOMContentLoaded', function() {
    toggleTypeFields();
});
</script>
