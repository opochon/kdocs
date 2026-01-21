<?php
// $document est passé depuis le contrôleur
use KDocs\Core\Config;
$base = Config::basePath();
?>

<div class="max-w-4xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-gray-800">Détails du document</h1>
        <a href="<?= url('/documents') ?>" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
            ← Retour à la liste
        </a>
    </div>

    <div class="bg-white rounded-lg shadow p-6 space-y-6">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <?php if (!empty($document['asn'])): ?>
            <div>
                <label class="block text-sm font-medium text-gray-500 mb-1">ASN</label>
                <p class="text-lg font-semibold text-gray-900 font-mono">
                    <?= htmlspecialchars($document['asn']) ?>
                </p>
            </div>
            <?php endif; ?>
            
            <div>
                <label class="block text-sm font-medium text-gray-500 mb-1">Titre</label>
                <p class="text-lg font-semibold text-gray-900">
                    <?= htmlspecialchars($document['title'] ?: $document['original_filename']) ?>
                </p>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-500 mb-1">Type de document</label>
                <span class="inline-block px-3 py-1 bg-blue-100 text-blue-800 rounded">
                    <?= htmlspecialchars($document['document_type_label'] ?: 'Non défini') ?>
                </span>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-500 mb-1">Fichier original</label>
                <p class="text-gray-900"><?= htmlspecialchars($document['original_filename']) ?></p>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-500 mb-1">Taille</label>
                <p class="text-gray-900">
                    <?= $document['file_size'] ? number_format($document['file_size'] / 1024, 2) . ' KB' : '-' ?>
                </p>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-500 mb-1">Date du document</label>
                <p class="text-gray-900">
                    <?= $document['document_date'] ? date('d/m/Y', strtotime($document['document_date'])) : ($document['doc_date'] ? date('d/m/Y', strtotime($document['doc_date'])) : '-') ?>
                </p>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-500 mb-1">Montant</label>
                <p class="text-gray-900">
                    <?php if ($document['amount']): ?>
                        <?= number_format($document['amount'], 2, ',', ' ') ?> <?= htmlspecialchars($document['currency']) ?>
                    <?php else: ?>
                        -
                    <?php endif; ?>
                </p>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-500 mb-1">Correspondant</label>
                <p class="text-gray-900">
                    <?= htmlspecialchars($document['correspondent_name'] ?: 'Non défini') ?>
                </p>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-500 mb-1">Uploadé par</label>
                <p class="text-gray-900">
                    <?= htmlspecialchars($document['created_by_username'] ?: 'Inconnu') ?>
                </p>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-500 mb-1">Date d'upload</label>
                <p class="text-gray-900">
                    <?= date('d/m/Y à H:i', strtotime($document['created_at'])) ?>
                </p>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-500 mb-1">Type MIME</label>
                <p class="text-gray-900"><?= htmlspecialchars($document['mime_type']) ?></p>
            </div>
            
            <?php if (!empty($document['storage_path_name'])): ?>
            <div>
                <label class="block text-sm font-medium text-gray-500 mb-1">Chemin de stockage</label>
                <p class="text-gray-900 font-mono text-sm"><?= htmlspecialchars($document['storage_path_path']) ?></p>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Custom Fields (Phase 2.1) -->
        <?php
        try {
            $customFieldValues = \KDocs\Models\CustomField::getValuesForDocument($document['id']);
            if (!empty($customFieldValues)):
        ?>
        <div class="border-t pt-6">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">Champs personnalisés</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <?php foreach ($customFieldValues as $cfv): ?>
                <div>
                    <label class="block text-sm font-medium text-gray-500 mb-1"><?= htmlspecialchars($cfv['field_name']) ?></label>
                    <p class="text-gray-900">
                        <?php if ($cfv['field_type'] === 'boolean'): ?>
                            <?= $cfv['value'] ? 'Oui' : 'Non' ?>
                        <?php else: ?>
                            <?= htmlspecialchars($cfv['value']) ?>
                        <?php endif; ?>
                    </p>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
            endif;
        } catch (\Exception $e) {
            // Table custom_fields n'existe pas encore
        }
        ?>

        <?php if (!empty($tags)): ?>
        <div class="border-t pt-6">
            <label class="block text-sm font-medium text-gray-500 mb-2">Tags</label>
            <div class="flex flex-wrap gap-2">
                <?php foreach ($tags as $tag): ?>
                <span class="inline-block px-3 py-1 text-xs rounded-full"
                      style="background-color: <?= htmlspecialchars($tag['color'] ?? '#6b7280') ?>20; color: <?= htmlspecialchars($tag['color'] ?? '#6b7280') ?>">
                    <?= htmlspecialchars($tag['name']) ?>
                </span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($document['ocr_text'])): ?>
        <div class="border-t pt-6">
            <label class="block text-sm font-medium text-gray-500 mb-2">Texte extrait (OCR)</label>
            <div class="bg-gray-50 rounded-lg p-4 max-h-96 overflow-y-auto">
                <pre class="text-sm text-gray-700 whitespace-pre-wrap"><?= htmlspecialchars($document['ocr_text']) ?></pre>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="border-t pt-6">
            <div class="flex items-center justify-between">
                <div class="flex gap-2">
                    <a 
                        href="<?= url('/documents/' . $document['id'] . '/edit') ?>" 
                        class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700"
                    >
                        ✏️ Modifier
                    </a>
                    <a 
                        href="<?= url('/documents/' . $document['id'] . '/download') ?>" 
                        class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700"
                    >
                        📥 Télécharger
                    </a>
                    <?php if (strpos($document['mime_type'], 'pdf') !== false || strpos($document['mime_type'], 'image') !== false): ?>
                    <button 
                        onclick="openViewer(<?= $document['id'] ?>)"
                        class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700"
                    >
                        👁️ Visualiser
                    </button>
                    <?php endif; ?>
                    <form method="POST" 
                          action="<?= url('/documents/' . $document['id'] . '/delete') ?>" 
                          onsubmit="if(confirm('Êtes-vous sûr de vouloir supprimer ce document ? Il sera déplacé dans la corbeille.')) { showToast('Document supprimé', 'success'); return true; } return false;"
                          class="inline">
                        <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
                            🗑️ Supprimer
                        </button>
                    </form>
                    <button onclick="shareDocument(<?= $document['id'] ?>)" class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700">
                        🔗 Partager
                    </button>
                    <a href="<?= url('/documents/' . $document['id'] . '/history') ?>" class="px-4 py-2 bg-gray-500 text-white rounded-lg hover:bg-gray-600">
                        📜 Historique
                    </a>
                </div>
                <div class="text-sm text-gray-500">
                    ID: <?= $document['id'] ?>
                    <?php if ($document['is_indexed']): ?>
                        <span class="ml-2 text-green-600">✓ Indexé</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Visualiseur PDF intégré avec PDF.js (Style Paperless-ngx) -->
<?php if (strpos($document['mime_type'], 'pdf') !== false || strpos($document['mime_type'], 'image') !== false): ?>
<link rel="stylesheet" href="<?= url('/css/pdf-viewer.css') ?>">
<div id="pdf-viewer-modal" class="hidden fixed inset-0 bg-black bg-opacity-90 z-50 flex flex-col">
    <div class="bg-white flex flex-col h-full">
        <!-- Barre d'outils -->
        <div class="viewer-toolbar">
            <div class="toolbar-group">
                <button onclick="closePDFViewer()" class="toolbar-btn" title="Fermer (Esc)">
                    ✕ Fermer
                </button>
            </div>
            
            <?php if (strpos($document['mime_type'], 'pdf') !== false): ?>
            <!-- Navigation pages -->
            <div class="toolbar-group">
                <button id="prev-page-btn" onclick="previousPage()" class="toolbar-btn" title="Page précédente (←)">
                    ◀
                </button>
                <input type="number" id="page-input" min="1" value="1" class="toolbar-input" 
                       onchange="goToPage(this.value)" onkeypress="if(event.key==='Enter') goToPage(this.value)">
                <span id="page-info" class="text-sm px-2">Page 1 / 1</span>
                <button id="next-page-btn" onclick="nextPage()" class="toolbar-btn" title="Page suivante (→)">
                    ▶
                </button>
            </div>
            <?php endif; ?>
            
            <!-- Zoom -->
            <div class="toolbar-group">
                <button onclick="zoomOut()" class="toolbar-btn" title="Zoom arrière (-)">🔍-</button>
                <span id="zoom-level" class="text-sm px-2">100%</span>
                <button onclick="zoomIn()" class="toolbar-btn" title="Zoom avant (+)">🔍+</button>
                <select id="zoom-select" onchange="setZoom(this.value)" class="toolbar-select">
                    <option value="0.5">50%</option>
                    <option value="0.75">75%</option>
                    <option value="1.0" selected>100%</option>
                    <option value="1.25">125%</option>
                    <option value="1.5">150%</option>
                    <option value="2.0">200%</option>
                    <option value="3.0">300%</option>
                    <option value="fit-width">Ajuster largeur</option>
                    <option value="fit-page">Ajuster page</option>
                </select>
            </div>
            
            <?php if (strpos($document['mime_type'], 'pdf') !== false): ?>
            <!-- Rotation -->
            <div class="toolbar-group">
                <button onclick="rotatePDF()" class="toolbar-btn" title="Rotation (R)">↻</button>
            </div>
            
            <!-- Recherche dans PDF -->
            <div class="toolbar-group">
                <div class="search-bar">
                    <input type="text" id="pdf-search-input" class="search-input" 
                           placeholder="Rechercher dans le PDF..." 
                           onkeypress="if(event.key==='Enter') searchInPDF(this.value)">
                    <button onclick="searchInPDF(document.getElementById('pdf-search-input').value)" class="toolbar-btn">🔍</button>
                    <button onclick="previousSearchResult()" class="toolbar-btn" title="Résultat précédent">▲</button>
                    <button onclick="nextSearchResult()" class="toolbar-btn" title="Résultat suivant">▼</button>
                    <span id="search-results" class="search-results"></span>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Actions -->
            <div class="toolbar-group ml-auto">
                <?php if (strpos($document['mime_type'], 'pdf') !== false): ?>
                <button onclick="downloadCurrentPage()" class="toolbar-btn" title="Télécharger la page actuelle">
                    📄 Télécharger page
                </button>
                <?php endif; ?>
                <a href="<?= url('/documents/' . $document['id'] . '/download') ?>" 
                   class="toolbar-btn" title="Télécharger le document">
                    📥 Télécharger
                </a>
                <button onclick="toggleFullscreen()" class="toolbar-btn" title="Plein écran (F)">
                    ⛶ Plein écran
                </button>
            </div>
        </div>
        
        <!-- Zone de visualisation -->
        <div class="flex-1 overflow-auto" id="viewer-container">
            <?php if (strpos($document['mime_type'], 'pdf') !== false): ?>
            <canvas id="pdf-canvas"></canvas>
            <?php else: ?>
            <img 
                id="image-viewer"
                src="<?= url('/documents/' . $document['id'] . '/view') ?>"
                alt="<?= htmlspecialchars($document['title'] ?: $document['original_filename']) ?>"
                class="max-w-full max-h-full object-contain m-auto"
                style="zoom: 1;"
            />
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="<?= url('/js/pdf-viewer.js') ?>"></script>
<script>
let currentZoom = 100;

function openViewer(id) {
    const modal = document.getElementById('pdf-viewer-modal');
    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
    
    <?php if (strpos($document['mime_type'], 'pdf') !== false): ?>
    // Initialiser PDF.js
    const pdfUrl = '<?= url('/documents/' . $document['id'] . '/view') ?>';
    initPDFViewer(pdfUrl);
    <?php else: ?>
    // Pour les images, on gère le zoom
    currentZoom = 100;
    updateImageZoom();
    <?php endif; ?>
}

function closeViewer() {
    <?php if (strpos($document['mime_type'], 'pdf') !== false): ?>
    closePDFViewer();
    <?php else: ?>
    document.getElementById('pdf-viewer-modal').classList.add('hidden');
    document.body.style.overflow = '';
    <?php endif; ?>
}

// Gestion zoom pour images
function zoomIn() {
    <?php if (strpos($document['mime_type'], 'pdf') === false): ?>
    currentZoom = Math.min(currentZoom + 25, 200);
    updateImageZoom();
    <?php endif; ?>
}

function zoomOut() {
    <?php if (strpos($document['mime_type'], 'pdf') === false): ?>
    currentZoom = Math.max(currentZoom - 25, 25);
    updateImageZoom();
    <?php endif; ?>
}

function setZoom(value) {
    <?php if (strpos($document['mime_type'], 'pdf') === false): ?>
    currentZoom = parseInt(value);
    updateImageZoom();
    <?php endif; ?>
}

function updateImageZoom() {
    const img = document.getElementById('image-viewer');
    if (img) {
        img.style.zoom = currentZoom / 100;
    }
    const zoomLevel = document.getElementById('zoom-level');
    const zoomSelect = document.getElementById('zoom-select');
    if (zoomLevel) zoomLevel.textContent = currentZoom + '%';
    if (zoomSelect) zoomSelect.value = currentZoom;
}
</script>
<?php endif; ?>
