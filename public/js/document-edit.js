/**
 * K-Docs - Amélioration édition métadonnées
 * Autocomplétion et sélection visuelle améliorée
 */

(function() {
    // Autocomplétion pour correspondants
    const correspondentSelect = document.getElementById('correspondent_id');
    if (correspondentSelect) {
        // Convertir le select en autocomplétion
        convertSelectToAutocomplete(correspondentSelect, 'correspondent');
    }
    
    // Autocomplétion pour types de documents
    const typeSelect = document.getElementById('document_type_id');
    if (typeSelect) {
        convertSelectToAutocomplete(typeSelect, 'document_type');
    }
    
    // Recherche dans les tags
    const tagsContainer = document.querySelector('.tags-container');
    if (tagsContainer) {
        addTagSearch(tagsContainer);
    }
    
    // Validation en temps réel
    const form = document.querySelector('form');
    if (form) {
        addRealTimeValidation(form);
    }
})();

// Convertir un select en autocomplétion
function convertSelectToAutocomplete(selectElement, type) {
    const wrapper = document.createElement('div');
    wrapper.className = 'relative';
    selectElement.parentNode.insertBefore(wrapper, selectElement);
    wrapper.appendChild(selectElement);
    
    // Créer l'input d'autocomplétion
    const input = document.createElement('input');
    input.type = 'text';
    input.className = selectElement.className + ' autocomplete-input';
    input.placeholder = 'Rechercher...';
    input.autocomplete = 'off';
    
    // Remplacer le select par l'input (mais garder le select caché pour le formulaire)
    selectElement.style.display = 'none';
    wrapper.appendChild(input);
    
    // Créer la dropdown
    const dropdown = document.createElement('div');
    dropdown.className = 'absolute z-50 w-full bg-white border border-gray-300 rounded-lg shadow-lg mt-1 max-h-60 overflow-y-auto hidden';
    dropdown.id = type + '-dropdown';
    wrapper.appendChild(dropdown);
    
    // Options du select
    const options = Array.from(selectElement.options);
    let filteredOptions = options;
    
    // Fonction de filtrage
    function filterOptions(searchTerm) {
        if (!searchTerm) {
            filteredOptions = options;
        } else {
            const term = searchTerm.toLowerCase();
            filteredOptions = options.filter(opt => 
                opt.text.toLowerCase().includes(term)
            );
        }
        renderDropdown();
    }
    
    // Rendre la dropdown
    function renderDropdown() {
        dropdown.innerHTML = '';
        
        if (filteredOptions.length === 0) {
            dropdown.innerHTML = '<div class="px-4 py-2 text-gray-500 text-sm">Aucun résultat</div>';
            dropdown.classList.remove('hidden');
            return;
        }
        
        filteredOptions.forEach(option => {
            if (option.value === '') return; // Skip "Aucun"
            
            const item = document.createElement('div');
            item.className = 'px-4 py-2 hover:bg-gray-100 cursor-pointer';
            item.textContent = option.text;
            
            item.addEventListener('click', () => {
                selectElement.value = option.value;
                input.value = option.text;
                dropdown.classList.add('hidden');
                
                // Mettre à jour visuellement
                if (option.value === '') {
                    input.classList.remove('border-blue-500');
                } else {
                    input.classList.add('border-blue-500');
                }
            });
            
            dropdown.appendChild(item);
        });
        
        dropdown.classList.remove('hidden');
    }
    
    // Événements sur l'input
    input.addEventListener('input', (e) => {
        filterOptions(e.target.value);
    });
    
    input.addEventListener('focus', () => {
        if (filteredOptions.length > 0) {
            renderDropdown();
        }
    });
    
    // Fermer la dropdown en cliquant ailleurs
    document.addEventListener('click', (e) => {
        if (!wrapper.contains(e.target)) {
            dropdown.classList.add('hidden');
        }
    });
    
    // Initialiser avec la valeur sélectionnée
    const selectedOption = options.find(opt => opt.selected && opt.value !== '');
    if (selectedOption) {
        input.value = selectedOption.text;
        input.classList.add('border-blue-500');
    }
    
    // Recherche AJAX pour correspondants (si beaucoup de résultats)
    if (type === 'correspondent' && options.length > 20) {
        input.addEventListener('input', debounce((e) => {
            const searchTerm = e.target.value;
            if (searchTerm.length >= 2) {
                fetch(`<?= url('/api/correspondents/search') ?>?q=${encodeURIComponent(searchTerm)}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success && data.results) {
                            filteredOptions = data.results.map(item => ({
                                value: item.id,
                                text: item.name
                            }));
                            renderDropdown();
                        }
                    })
                    .catch(error => console.error('Erreur recherche:', error));
            }
        }, 300));
    }
}

// Ajouter recherche dans les tags
function addTagSearch(container) {
    const searchWrapper = document.createElement('div');
    searchWrapper.className = 'mb-3';
    
    const searchInput = document.createElement('input');
    searchInput.type = 'text';
    searchInput.placeholder = 'Rechercher un tag...';
    searchInput.className = 'w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500';
    
    const tagsList = container.querySelector('.flex.flex-wrap');
    if (!tagsList) return;
    
    const originalTags = Array.from(tagsList.children);
    
    searchInput.addEventListener('input', (e) => {
        const searchTerm = e.target.value.toLowerCase();
        
        originalTags.forEach(tag => {
            const tagText = tag.textContent.toLowerCase();
            if (tagText.includes(searchTerm)) {
                tag.style.display = '';
            } else {
                tag.style.display = 'none';
            }
        });
    });
    
    container.insertBefore(searchWrapper, tagsList);
    searchWrapper.appendChild(searchInput);
}

// Validation en temps réel
function addRealTimeValidation(form) {
    const titleInput = document.getElementById('title');
    const amountInput = document.getElementById('amount');
    
    // Validation titre
    if (titleInput) {
        titleInput.addEventListener('blur', () => {
            if (!titleInput.value.trim()) {
                showFieldError(titleInput, 'Le titre est requis');
            } else {
                clearFieldError(titleInput);
            }
        });
        
        titleInput.addEventListener('input', () => {
            if (titleInput.value.trim()) {
                clearFieldError(titleInput);
            }
        });
    }
    
    // Validation montant
    if (amountInput) {
        amountInput.addEventListener('blur', () => {
            const value = parseFloat(amountInput.value);
            if (amountInput.value && (isNaN(value) || value < 0)) {
                showFieldError(amountInput, 'Le montant doit être un nombre positif');
            } else {
                clearFieldError(amountInput);
            }
        });
    }
    
    // Validation avant soumission
    form.addEventListener('submit', (e) => {
        let isValid = true;
        
        if (titleInput && !titleInput.value.trim()) {
            showFieldError(titleInput, 'Le titre est requis');
            isValid = false;
        }
        
        if (!isValid) {
            e.preventDefault();
            if (typeof showToast !== 'undefined') {
                showToast('Veuillez corriger les erreurs dans le formulaire', 'error');
            }
        }
    });
}

// Afficher erreur sur un champ
function showFieldError(input, message) {
    clearFieldError(input);
    
    input.classList.add('border-red-500');
    input.classList.remove('border-gray-300');
    
    const errorDiv = document.createElement('div');
    errorDiv.className = 'text-red-600 text-sm mt-1';
    errorDiv.textContent = message;
    errorDiv.dataset.fieldError = 'true';
    
    input.parentNode.appendChild(errorDiv);
}

// Effacer erreur sur un champ
function clearFieldError(input) {
    input.classList.remove('border-red-500');
    input.classList.add('border-gray-300');
    
    const errorDiv = input.parentNode.querySelector('[data-field-error="true"]');
    if (errorDiv) {
        errorDiv.remove();
    }
}

// Debounce helper
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}
