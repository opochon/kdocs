/**
 * K-Docs - PDF.js Viewer complet
 * Remplace l'iframe natif par PDF.js pour un contrôle total
 */

let pdfDoc = null;
let pdfPageNum = 1;
let pdfNumPages = 0;
let pdfScale = 1.0;
let pdfRotation = 0;
let pdfSearchText = '';
let pdfSearchResults = [];
let pdfSearchIndex = -1;
let pdfSearchMatches = []; // Stocke les matches avec leurs positions

// Charger PDF.js depuis CDN
function loadPDFJS() {
    return new Promise((resolve, reject) => {
        if (window.pdfjsLib) {
            resolve();
            return;
        }
        
        const script = document.createElement('script');
        script.src = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js';
        script.onload = () => {
            pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
            resolve();
        };
        script.onerror = reject;
        document.head.appendChild(script);
    });
}

// Initialiser le viewer PDF
async function initPDFViewer(pdfUrl) {
    try {
        await loadPDFJS();
        
        const loadingTask = pdfjsLib.getDocument({
            url: pdfUrl,
            cMapUrl: 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/cmaps/',
            cMapPacked: true,
        });
        
        pdfDoc = await loadingTask.promise;
        pdfNumPages = pdfDoc.numPages;
        pdfPageNum = 1;
        
        updatePageDisplay();
        renderPage(pdfPageNum);
        
        return true;
    } catch (error) {
        console.error('Erreur chargement PDF:', error);
        showToast('Erreur lors du chargement du PDF', 'error');
        return false;
    }
}

// Rendre une page du PDF
async function renderPage(num) {
    if (!pdfDoc) return;
    
    try {
        const page = await pdfDoc.getPage(num);
        const viewport = page.getViewport({ scale: pdfScale, rotation: pdfRotation });
        
        const canvas = document.getElementById('pdf-canvas');
        const context = canvas.getContext('2d');
        
        canvas.height = viewport.height;
        canvas.width = viewport.width;
        
        const renderContext = {
            canvasContext: context,
            viewport: viewport
        };
        
        await page.render(renderContext).promise;
        
        // Surligner les résultats de recherche si présents
        if (pdfSearchText && pdfSearchMatches.length > 0) {
            highlightSearchOnPage(page, viewport, context);
        }
        
        pdfPageNum = num;
        updatePageDisplay();
        updateSearchDisplay();
    } catch (error) {
        console.error('Erreur rendu page:', error);
    }
}

// Navigation pages
function previousPage() {
    if (pdfPageNum <= 1) return;
    pdfPageNum--;
    renderPage(pdfPageNum);
}

function nextPage() {
    if (pdfPageNum >= pdfNumPages) return;
    pdfPageNum++;
    renderPage(pdfPageNum);
}

function goToPage(num) {
    const pageNum = parseInt(num);
    if (pageNum >= 1 && pageNum <= pdfNumPages) {
        pdfPageNum = pageNum;
        renderPage(pdfPageNum);
    }
}

// Zoom
function zoomIn() {
    pdfScale = Math.min(pdfScale + 0.25, 3.0);
    renderPage(pdfPageNum);
    updateZoomDisplay();
}

function zoomOut() {
    pdfScale = Math.max(pdfScale - 0.25, 0.5);
    renderPage(pdfPageNum);
    updateZoomDisplay();
}

function setZoom(value) {
    if (value === 'fit-width') {
        fitToWidth();
    } else if (value === 'fit-page') {
        fitToPage();
    } else {
        pdfScale = parseFloat(value);
        renderPage(pdfPageNum);
        updateZoomDisplay();
    }
}

function fitToWidth() {
    if (!pdfDoc) return;
    const container = document.getElementById('viewer-container');
    const containerWidth = container.clientWidth - 40; // padding
    
    pdfDoc.getPage(pdfPageNum).then(page => {
        const viewport = page.getViewport({ scale: 1.0 });
        pdfScale = containerWidth / viewport.width;
        renderPage(pdfPageNum);
        updateZoomDisplay();
    });
}

function fitToPage() {
    if (!pdfDoc) return;
    const container = document.getElementById('viewer-container');
    const containerWidth = container.clientWidth - 40;
    const containerHeight = container.clientHeight - 40;
    
    pdfDoc.getPage(pdfPageNum).then(page => {
        const viewport = page.getViewport({ scale: 1.0 });
        const scaleX = containerWidth / viewport.width;
        const scaleY = containerHeight / viewport.height;
        pdfScale = Math.min(scaleX, scaleY);
        renderPage(pdfPageNum);
        updateZoomDisplay();
    });
}

// Rotation
function rotatePDF() {
    pdfRotation = (pdfRotation + 90) % 360;
    renderPage(pdfPageNum);
}

// Recherche dans le PDF avec surbrillance
async function searchInPDF(text) {
    if (!pdfDoc || !text) {
        pdfSearchText = '';
        pdfSearchResults = [];
        pdfSearchIndex = -1;
        pdfSearchMatches = [];
        renderPage(pdfPageNum); // Re-rendre sans surbrillance
        return;
    }
    
    pdfSearchText = text;
    pdfSearchResults = [];
    pdfSearchIndex = -1;
    pdfSearchMatches = [];
    
    const searchTextLower = text.toLowerCase();
    
    for (let i = 1; i <= pdfNumPages; i++) {
        const page = await pdfDoc.getPage(i);
        const textContent = await page.getTextContent();
        
        // Chercher les occurrences dans les items de texte
        const pageMatches = [];
        textContent.items.forEach((item, itemIndex) => {
            const itemText = item.str.toLowerCase();
            let searchIndex = 0;
            
            while ((searchIndex = itemText.indexOf(searchTextLower, searchIndex)) !== -1) {
                // Obtenir les coordonnées du texte
                const transform = item.transform;
                const x = transform[4];
                const y = transform[5];
                const width = item.width || 0;
                const height = item.height || 0;
                
                // Calculer la position du match dans l'item
                const matchStart = searchIndex;
                const matchEnd = searchIndex + text.length;
                const matchWidth = (matchEnd - matchStart) * (width / item.str.length);
                const matchX = x + (matchStart * (width / item.str.length));
                
                pageMatches.push({
                    page: i,
                    x: matchX,
                    y: y,
                    width: matchWidth,
                    height: height,
                    transform: transform
                });
                
                searchIndex += text.length;
            }
        });
        
        if (pageMatches.length > 0) {
            pdfSearchResults.push(i);
            pdfSearchMatches.push(...pageMatches);
        }
    }
    
    if (pdfSearchResults.length > 0) {
        pdfSearchIndex = 0;
        goToPage(pdfSearchResults[0]);
        updateSearchDisplay();
        showToast(`${pdfSearchMatches.length} résultat(s) trouvé(s)`, 'success');
    } else {
        pdfSearchMatches = [];
        showToast('Aucun résultat trouvé', 'info');
    }
}

function nextSearchResult() {
    if (pdfSearchResults.length === 0) return;
    pdfSearchIndex = (pdfSearchIndex + 1) % pdfSearchResults.length;
    goToPage(pdfSearchResults[pdfSearchIndex]);
    updateSearchDisplay();
}

function previousSearchResult() {
    if (pdfSearchResults.length === 0) return;
    pdfSearchIndex = (pdfSearchIndex - 1 + pdfSearchResults.length) % pdfSearchResults.length;
    goToPage(pdfSearchResults[pdfSearchIndex]);
    updateSearchDisplay();
}

// Surligner les résultats de recherche sur la page actuelle
function highlightSearchOnPage(page, viewport, context) {
    if (!pdfSearchText || pdfSearchMatches.length === 0) return;
    
    // Filtrer les matches pour la page actuelle
    const pageMatches = pdfSearchMatches.filter(m => m.page === pdfPageNum);
    if (pageMatches.length === 0) return;
    
    // Obtenir le contenu texte de la page pour les coordonnées exactes
    page.getTextContent().then(textContent => {
        // Sauvegarder l'état du contexte
        context.save();
        
        // Style de surbrillance
        context.fillStyle = 'rgba(255, 255, 0, 0.4)'; // Jaune semi-transparent
        context.strokeStyle = 'rgba(255, 200, 0, 0.9)';
        context.lineWidth = 1.5;
        
        const searchTextLower = pdfSearchText.toLowerCase();
        
        // Parcourir les items de texte pour trouver les matches
        textContent.items.forEach(item => {
            const itemText = item.str.toLowerCase();
            if (itemText.includes(searchTextLower)) {
                const transform = item.transform;
                const x = transform[4];
                const y = transform[5];
                const width = item.width || 0;
                const height = item.height || 0;
                
                // Convertir les coordonnées en coordonnées du viewport
                const viewportX = x - viewport.viewBox[0];
                const viewportY = viewport.height - (y - viewport.viewBox[1]);
                
                // Dessiner le rectangle de surbrillance
                context.fillRect(viewportX, viewportY - height, width, height);
                context.strokeRect(viewportX, viewportY - height, width, height);
            }
        });
        
        // Restaurer l'état du contexte
        context.restore();
    }).catch(err => {
        console.error('Erreur surbrillance:', err);
    });
}

// Mettre à jour l'affichage de la recherche
function updateSearchDisplay() {
    const searchInfo = document.getElementById('search-info');
    const prevBtn = document.getElementById('prev-search-btn');
    const nextBtn = document.getElementById('next-search-btn');
    
    if (pdfSearchMatches.length > 0) {
        const currentPageMatches = pdfSearchMatches.filter(m => m.page === pdfPageNum).length;
        const totalMatches = pdfSearchMatches.length;
        const currentResult = pdfSearchResults.indexOf(pdfPageNum) + 1;
        const totalResults = pdfSearchResults.length;
        
        if (searchInfo) {
            searchInfo.textContent = `${currentResult}/${totalResults} page(s) - ${totalMatches} résultat(s)`;
            searchInfo.style.display = 'inline-block';
        }
        
        if (prevBtn) {
            prevBtn.style.display = 'inline-block';
            prevBtn.disabled = pdfSearchIndex === 0;
        }
        
        if (nextBtn) {
            nextBtn.style.display = 'inline-block';
            nextBtn.disabled = pdfSearchIndex === pdfSearchResults.length - 1;
        }
    } else {
        if (searchInfo) searchInfo.style.display = 'none';
        if (prevBtn) prevBtn.style.display = 'none';
        if (nextBtn) nextBtn.style.display = 'none';
    }
}

// Mise à jour de l'affichage
function updatePageDisplay() {
    const pageInfo = document.getElementById('page-info');
    const pageInput = document.getElementById('page-input');
    
    if (pageInfo) {
        pageInfo.textContent = `Page ${pdfPageNum} / ${pdfNumPages}`;
    }
    if (pageInput) {
        pageInput.value = pdfPageNum;
        pageInput.max = pdfNumPages;
    }
    
    // Désactiver/activer les boutons
    const prevBtn = document.getElementById('prev-page-btn');
    const nextBtn = document.getElementById('next-page-btn');
    
    if (prevBtn) prevBtn.disabled = pdfPageNum <= 1;
    if (nextBtn) nextBtn.disabled = pdfPageNum >= pdfNumPages;
}

function updateZoomDisplay() {
    const zoomLevel = document.getElementById('zoom-level');
    const zoomSelect = document.getElementById('zoom-select');
    
    if (zoomLevel) {
        zoomLevel.textContent = Math.round(pdfScale * 100) + '%';
    }
    if (zoomSelect) {
        zoomSelect.value = pdfScale.toFixed(2);
    }
}

// Mode plein écran
function toggleFullscreen() {
    const modal = document.getElementById('pdf-viewer-modal');
    if (!document.fullscreenElement) {
        modal.requestFullscreen().catch(err => {
            console.error('Erreur plein écran:', err);
        });
    } else {
        document.exitFullscreen();
    }
}

// Télécharger la page actuelle
function downloadCurrentPage() {
    if (!pdfDoc) return;
    
    const canvas = document.getElementById('pdf-canvas');
    canvas.toBlob(blob => {
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `page-${pdfPageNum}.png`;
        a.click();
        URL.revokeObjectURL(url);
    });
}

// Fermer le viewer
function closePDFViewer() {
    pdfDoc = null;
    pdfPageNum = 1;
    pdfNumPages = 0;
    pdfScale = 1.0;
    pdfRotation = 0;
    
    const modal = document.getElementById('pdf-viewer-modal');
    if (modal) {
        modal.classList.add('hidden');
    }
    document.body.style.overflow = '';
}

// Raccourcis clavier
document.addEventListener('keydown', function(e) {
    const modal = document.getElementById('pdf-viewer-modal');
    if (!modal || modal.classList.contains('hidden')) return;
    
    // Ne pas intercepter si on est dans un input
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
    
    switch(e.key) {
        case 'Escape':
            e.preventDefault();
            closePDFViewer();
            break;
        case 'ArrowLeft':
            e.preventDefault();
            previousPage();
            break;
        case 'ArrowRight':
            e.preventDefault();
            nextPage();
            break;
        case '+':
        case '=':
            e.preventDefault();
            zoomIn();
            break;
        case '-':
            e.preventDefault();
            zoomOut();
            break;
        case '0':
            e.preventDefault();
            setZoom(1.0);
            break;
        case 'f':
        case 'F':
            e.preventDefault();
            toggleFullscreen();
            break;
        case 'r':
        case 'R':
            e.preventDefault();
            rotatePDF();
            break;
    }
});
