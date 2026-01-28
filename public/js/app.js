/**
 * K-Docs - JavaScript global
 * - CSRF Token handling
 * - Thème sombre (Priorité 3.5)
 * - Raccourcis clavier (Priorité 3.6)
 * - Notifications toast (Priorité 3.7)
 * - Mode plein écran (Priorité 3.8)
 */

// ===== CSRF TOKEN HANDLING =====
(function() {
    // Récupérer le token CSRF depuis la meta tag
    function getCSRFToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : null;
    }

    // Intercepter toutes les requêtes fetch pour ajouter le token CSRF
    const originalFetch = window.fetch;
    window.fetch = function(url, options = {}) {
        const method = (options.method || 'GET').toUpperCase();

        // Ajouter le token CSRF pour les méthodes modificatrices (sauf API)
        if (['POST', 'PUT', 'PATCH', 'DELETE'].includes(method)) {
            const token = getCSRFToken();
            if (token && !url.includes('/api/')) {
                options.headers = options.headers || {};
                if (options.headers instanceof Headers) {
                    options.headers.set('X-CSRF-Token', token);
                } else {
                    options.headers['X-CSRF-Token'] = token;
                }
            }
        }

        return originalFetch.call(this, url, options);
    };

    // Exposer la fonction pour usage manuel
    window.getCSRFToken = getCSRFToken;
})();

// ===== THÈME SOMBRE (Priorité 3.5) =====
(function() {
    const themeToggle = document.getElementById('theme-toggle');
    const html = document.documentElement;
    
    // Charger le thème sauvegardé
    const savedTheme = localStorage.getItem('theme') || 'light';
    if (savedTheme === 'dark') {
        html.classList.add('dark');
    }
    
    // Toggle du thème
    if (themeToggle) {
        themeToggle.addEventListener('click', function() {
            html.classList.toggle('dark');
            const isDark = html.classList.contains('dark');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            const themeIcon = document.getElementById('theme-icon');
            if (themeIcon) {
                themeIcon.textContent = isDark ? '☀️' : '🌙';
            }
            if (typeof showToast !== 'undefined') {
                showToast(isDark ? 'Thème sombre activé' : 'Thème clair activé', 'success');
            }
        });
        
        // Mettre à jour l'icône au chargement
        const themeIcon = document.getElementById('theme-icon');
        if (themeIcon && html.classList.contains('dark')) {
            themeIcon.textContent = '☀️';
        }
    }
})();

// ===== NOTIFICATIONS TOAST (Priorité 3.7) =====
let toastContainer = null;

function initToastContainer() {
    if (!toastContainer) {
        toastContainer = document.createElement('div');
        toastContainer.id = 'toast-container';
        toastContainer.className = 'fixed top-4 right-4 z-50 space-y-2';
        document.body.appendChild(toastContainer);
    }
}

function showToast(message, type = 'info', duration = 3000) {
    initToastContainer();
    
    const toast = document.createElement('div');
    const bgColors = {
        success: 'bg-green-500',
        error: 'bg-red-500',
        warning: 'bg-yellow-500',
        info: 'bg-blue-500'
    };
    
    toast.className = `${bgColors[type] || bgColors.info} text-white px-6 py-3 rounded-lg shadow-lg flex items-center gap-3 min-w-[300px] animate-slide-in`;
    toast.innerHTML = `
        <span>${message}</span>
        <button onclick="this.parentElement.remove()" class="ml-auto text-white hover:text-gray-200">✕</button>
    `;
    
    toastContainer.appendChild(toast);
    
    setTimeout(() => {
        toast.style.animation = 'slide-out 0.3s ease-out';
        setTimeout(() => toast.remove(), 300);
    }, duration);
}

// ===== RACCOURCIS CLAVIER (Priorité 3.6) =====
document.addEventListener('keydown', function(e) {
    // Ctrl/Cmd + K : Recherche
    if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
        e.preventDefault();
        const searchInput = document.querySelector('input[name="search"]');
        if (searchInput) {
            searchInput.focus();
            searchInput.select();
        }
    }
    
    // Ctrl/Cmd + U : Upload
    if ((e.ctrlKey || e.metaKey) && e.key === 'u') {
        e.preventDefault();
        const uploadLink = document.querySelector('a[href*="/documents/upload"]');
        if (uploadLink) {
            window.location.href = uploadLink.href;
        }
    }
    
    // Ctrl/Cmd + D : Documents
    if ((e.ctrlKey || e.metaKey) && e.key === 'd') {
        e.preventDefault();
        window.location.href = '/documents';
    }
    
    // Échap : Fermer modals/dropdowns
    if (e.key === 'Escape') {
        const modals = document.querySelectorAll('.modal, [role="dialog"]');
        modals.forEach(modal => {
            if (!modal.classList.contains('hidden')) {
                modal.classList.add('hidden');
            }
        });
    }
    
    // / : Recherche rapide (si pas dans un input)
    if (e.key === '/' && e.target.tagName !== 'INPUT' && e.target.tagName !== 'TEXTAREA') {
        e.preventDefault();
        const searchInput = document.querySelector('input[name="search"]');
        if (searchInput) {
            searchInput.focus();
        }
    }
});

// ===== MODE PLEIN ÉCRAN (Priorité 3.8) =====
function toggleFullscreen(element) {
    if (!document.fullscreenElement) {
        element.requestFullscreen().catch(err => {
            showToast('Impossible d\'activer le mode plein écran', 'error');
        });
    } else {
        document.exitFullscreen();
    }
}

// Détecter les changements de mode plein écran
document.addEventListener('fullscreenchange', function() {
    const isFullscreen = !!document.fullscreenElement;
    const fullscreenBtn = document.getElementById('fullscreen-toggle');
    if (fullscreenBtn) {
        fullscreenBtn.textContent = isFullscreen ? '⛶ Sortir' : '⛶ Plein écran';
    }
});

// ===== CSS pour animations =====
const style = document.createElement('style');
style.textContent = `
    @keyframes slide-in {
        from {
            transform: translateX(100%);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }
    
    @keyframes slide-out {
        from {
            transform: translateX(0);
            opacity: 1;
        }
        to {
            transform: translateX(100%);
            opacity: 0;
        }
    }
    
    .animate-slide-in {
        animation: slide-in 0.3s ease-out;
    }
    
    /* Thème sombre */
    .dark {
        color-scheme: dark;
    }
    
    .dark body {
        background-color: #111827;
        color: #f9fafb;
    }
    
    .dark .bg-white {
        background-color: #1f2937;
    }
    
    .dark .bg-gray-50 {
        background-color: #111827;
    }
    
    .dark .text-gray-800 {
        color: #f9fafb;
    }
    
    .dark .text-gray-600 {
        color: #d1d5db;
    }
    
    .dark .border-gray-200 {
        border-color: #374151;
    }
`;
document.head.appendChild(style);
