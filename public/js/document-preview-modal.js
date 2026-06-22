/**
 * Modale prévisualisation document — shell open/close/navigate (B1.5)
 * Dépend de loadDocumentPreview() définie dans documents/index.php
 */
(function () {
    'use strict';

    window.currentPreviewIndex = 0;
    window.documentsList = [];

    window.collectDocumentIds = function collectDocumentIds() {
        window.documentsList = [];
        document.querySelectorAll('.document-card[data-doc-id]').forEach(function (card) {
            var id = parseInt(card.dataset.docId, 10);
            if (id > 0) {
                window.documentsList.push(id);
            }
        });
    };

    window.openDocumentPreview = function openDocumentPreview(docId, index) {
        if (!docId) return;

        window.collectDocumentIds();
        window.currentPreviewIndex = index;

        var modal = document.getElementById('document-preview-modal');
        var panel = document.getElementById('preview-panel');
        if (!modal || !panel) return;

        modal.classList.remove('hidden');
        document.body.style.overflow = 'hidden';

        setTimeout(function () {
            panel.classList.remove('translate-x-full');
        }, 10);

        if (typeof window.loadDocumentPreview === 'function') {
            window.loadDocumentPreview(docId);
        }
        window.updatePreviewNavigation();
    };

    window.closeDocumentPreview = function closeDocumentPreview() {
        var modal = document.getElementById('document-preview-modal');
        var panel = document.getElementById('preview-panel');
        if (!modal || !panel) return;

        panel.classList.add('translate-x-full');

        setTimeout(function () {
            modal.classList.add('hidden');
            document.body.style.overflow = '';
            var viewer = document.getElementById('preview-viewer');
            var loading = document.getElementById('preview-loading');
            if (viewer) {
                viewer.innerHTML = '';
                viewer.classList.add('hidden');
            }
            if (loading) {
                loading.classList.remove('hidden');
            }
        }, 300);
    };

    window.navigatePreview = function navigatePreview(direction) {
        var newIndex = window.currentPreviewIndex + direction;
        if (newIndex >= 0 && newIndex < window.documentsList.length) {
            window.currentPreviewIndex = newIndex;
            var docId = window.documentsList[newIndex];
            if (typeof window.loadDocumentPreview === 'function') {
                window.loadDocumentPreview(docId);
            }
            window.updatePreviewNavigation();
        }
    };

    window.updatePreviewNavigation = function updatePreviewNavigation() {
        var prevBtn = document.getElementById('preview-prev-btn');
        var nextBtn = document.getElementById('preview-next-btn');
        var position = document.getElementById('preview-position');
        if (prevBtn) prevBtn.disabled = window.currentPreviewIndex <= 0;
        if (nextBtn) nextBtn.disabled = window.currentPreviewIndex >= window.documentsList.length - 1;
        if (position) {
            position.textContent = (window.currentPreviewIndex + 1) + ' / ' + window.documentsList.length;
        }
    };
})();
