<?php
/**
 * Recherche unifiée — plein texte + filtres basiques (B1.3)
 *
 * @var \KDocs\Search\SearchResult $result
 * @var \KDocs\Search\SearchQuery $searchQuery
 * @var array $documentTypes
 * @var array $correspondents
 * @var string $q
 */
use KDocs\Core\Config;
$base = Config::basePath();
$total = $result->total ?? 0;
?>

<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold" style="color:var(--ink)">Recherche</h1>
            <p class="text-sm mt-1" style="color:var(--dim)">Plein texte et filtres sur la bibliothèque</p>
        </div>
        <a href="<?= url('/search?mode=chat') ?>" class="inline-flex items-center gap-2 px-3 py-1.5 text-sm border rounded-lg btn-secondary">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/>
            </svg>
            Assistant IA
        </a>
    </div>

    <form method="GET" action="<?= url('/search') ?>" class="ds-card p-4 space-y-4">
        <div>
            <label for="search-q" class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">Mots-clés</label>
            <input type="search" id="search-q" name="q" value="<?= htmlspecialchars($q) ?>"
                   placeholder="Titre, contenu OCR, correspondant…"
                   class="form-input text-sm">
        </div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label for="search-type" class="block text-xs font-medium mb-1" style="color:var(--ink-soft)">Type de document</label>
                <select id="search-type" name="document_type_id" class="w-full px-2 py-1.5 text-sm rounded-lg">
                    <option value="">Tous</option>
                    <?php foreach ($documentTypes as $type): ?>
                    <option value="<?= (int) $type['id'] ?>" <?= ($searchQuery->documentTypeId ?? null) == $type['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($type['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="search-correspondent" class="block text-xs font-medium mb-1" style="color:var(--ink-soft)">Correspondant</label>
                <select id="search-correspondent" name="correspondent_id" class="w-full px-2 py-1.5 text-sm rounded-lg">
                    <option value="">Tous</option>
                    <?php foreach ($correspondents as $corr): ?>
                    <option value="<?= (int) $corr['id'] ?>" <?= ($searchQuery->correspondentId ?? null) == $corr['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($corr['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="search-scope" class="block text-xs font-medium mb-1" style="color:var(--ink-soft)">Périmètre</label>
                <select id="search-scope" name="scope" class="w-full px-2 py-1.5 text-sm rounded-lg">
                    <option value="all" <?= ($searchQuery->searchScope ?? 'all') === 'all' ? 'selected' : '' ?>>Tout</option>
                    <option value="name" <?= ($searchQuery->searchScope ?? '') === 'name' ? 'selected' : '' ?>>Titre uniquement</option>
                    <option value="content" <?= ($searchQuery->searchScope ?? '') === 'content' ? 'selected' : '' ?>>Contenu OCR</option>
                </select>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <button type="submit" class="px-4 py-2 text-sm font-medium rounded-lg btn-primary">Rechercher</button>
            <?php if ($q !== '' || $searchQuery->documentTypeId || $searchQuery->correspondentId): ?>
            <a href="<?= url('/search') ?>" class="px-4 py-2 text-sm btn-ghost">Réinitialiser</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($q !== '' || $searchQuery->documentTypeId || $searchQuery->correspondentId): ?>
    <p class="text-sm" style="color:var(--ink-soft)">
        <?= (int) $total ?> résultat<?= $total > 1 ? 's' : '' ?>
        <?php if (!empty($result->semanticUsed)): ?>
        <span style="color:var(--dim)">· recherche sémantique complémentaire</span>
        <?php endif; ?>
    </p>

    <?php if (empty($result->documents)): ?>
    <div class="text-center py-12 ds-card">
        <svg class="mx-auto h-10 w-10" style="color:var(--muted)" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
        </svg>
        <p class="mt-3 text-sm" style="color:var(--ink-soft)">Aucun document ne correspond à votre recherche.</p>
    </div>
    <?php else: ?>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
        <?php foreach ($result->documents as $doc): ?>
        <a href="<?= url('/documents/' . (int) $doc['id']) ?>"
           class="ds-card ds-card--link overflow-hidden transition-colors">
            <div class="aspect-[3/4] relative" style="background:var(--app-bg)">
                <?php $documentId = (int) $doc['id']; $alt = $doc['title'] ?? $doc['original_filename'] ?? 'Document'; include __DIR__ . '/../components/document_thumbnail.php'; ?>
            </div>
            <div class="p-3">
                <p class="text-sm font-medium truncate" style="color:var(--ink)"><?= htmlspecialchars($doc['title'] ?: ($doc['original_filename'] ?? 'Sans titre')) ?></p>
                <?php if (!empty($doc['correspondent_name'])): ?>
                <p class="text-xs mt-0.5 truncate" style="color:var(--dim)"><?= htmlspecialchars($doc['correspondent_name']) ?></p>
                <?php endif; ?>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php else: ?>
    <div class="border border-dashed rounded-lg p-8 text-center text-sm" style="background:var(--surface);border-color:var(--border);color:var(--dim)">
        Saisissez un terme ou choisissez un filtre pour lancer une recherche.
    </div>
    <?php endif; ?>
</div>
