/**
 * K-Docs - Node Configuration Forms
 * Définition des formulaires de configuration pour chaque type de node
 * Version 2.0 - Style Alfresco
 */

const NodeConfigForms = {
    // ============================================
    // DÉCLENCHEURS (TRIGGERS)
    // ============================================

    'trigger_document_added': {
        title: 'Document ajouté',
        fields: [
            {
                name: 'filter_document_type_id',
                type: 'select',
                label: 'Filtrer par type de document',
                options: 'document_types',
                required: false,
                help: 'Optionnel: déclenche uniquement pour ce type'
            },
            {
                name: 'filter_storage_path_id',
                type: 'select',
                label: 'Filtrer par dossier de stockage',
                options: 'storage_paths',
                required: false,
                help: 'Optionnel: déclenche uniquement dans ce dossier'
            }
        ]
    },

    'trigger_tag_added': {
        title: 'Tag ajouté',
        fields: [
            {
                name: 'trigger_tag_ids',
                type: 'multiselect',
                label: 'Tags déclencheurs',
                options: 'tags',
                required: true,
                help: 'Le workflow démarre quand un de ces tags est ajouté'
            }
        ]
    },

    'trigger_scan': {
        title: 'Scan dossier',
        fields: [
            {
                name: 'watch_folder',
                type: 'text',
                label: 'Dossier à surveiller',
                placeholder: '/scan/input',
                required: true,
                help: 'Chemin du dossier à surveiller pour les nouveaux fichiers'
            },
            {
                name: 'file_patterns',
                type: 'text',
                label: 'Motifs de fichiers',
                placeholder: '*.pdf, *.jpg, *.png',
                help: 'Types de fichiers acceptés (séparés par virgule)'
            }
        ]
    },

    'trigger_upload': {
        title: 'Upload via interface',
        fields: [
            {
                name: 'allowed_extensions',
                type: 'text',
                label: 'Extensions autorisées',
                placeholder: 'pdf, jpg, png, docx',
                help: 'Extensions de fichiers acceptées (séparées par virgule)'
            }
        ]
    },

    'trigger_manual': {
        title: 'Démarrage manuel',
        fields: [
            {
                name: 'require_document',
                type: 'checkbox',
                label: 'Document requis',
                help: 'Exiger qu\'un document soit sélectionné pour démarrer'
            },
            {
                name: 'allowed_roles',
                type: 'multiselect',
                label: 'Rôles autorisés',
                options: 'groups',
                help: 'Groupes autorisés à démarrer ce workflow'
            }
        ]
    },

    // ============================================
    // CONDITIONS
    // ============================================

    'condition_category': {
        title: 'Type de document',
        fields: [
            {
                name: 'document_type_id',
                type: 'select',
                label: 'Type de document',
                options: 'document_types',
                required: true
            },
            {
                name: 'operator',
                type: 'select',
                label: 'Opérateur',
                options: [
                    { value: 'equals', label: 'Est égal à' },
                    { value: 'not_equals', label: 'Est différent de' }
                ],
                default: 'equals'
            }
        ]
    },

    'condition_amount': {
        title: 'Condition sur montant',
        fields: [
            {
                name: 'operator',
                type: 'select',
                label: 'Opérateur',
                options: [
                    { value: 'equals', label: 'Égal à' },
                    { value: 'greater_than', label: 'Supérieur à' },
                    { value: 'greater_or_equal', label: 'Supérieur ou égal à' },
                    { value: 'less_than', label: 'Inférieur à' },
                    { value: 'less_or_equal', label: 'Inférieur ou égal à' },
                    { value: 'between', label: 'Entre' }
                ],
                required: true
            },
            {
                name: 'value',
                type: 'number',
                label: 'Montant',
                step: '0.01',
                required: true
            },
            {
                name: 'value_max',
                type: 'number',
                label: 'Montant maximum',
                step: '0.01',
                conditional: { field: 'operator', equals: 'between' }
            },
            {
                name: 'currency',
                type: 'select',
                label: 'Devise',
                options: [
                    { value: '', label: 'Toutes devises' },
                    { value: 'CHF', label: 'CHF' },
                    { value: 'EUR', label: 'EUR' },
                    { value: 'USD', label: 'USD' }
                ]
            }
        ]
    },

    'condition_tag': {
        title: 'Condition sur tags',
        fields: [
            {
                name: 'mode',
                type: 'select',
                label: 'Mode de vérification',
                options: [
                    { value: 'has_any', label: 'A au moins un des tags' },
                    { value: 'has_all', label: 'A tous les tags' },
                    { value: 'has_none', label: 'N\'a aucun des tags' }
                ],
                required: true,
                default: 'has_any'
            },
            {
                name: 'tag_ids',
                type: 'multiselect',
                label: 'Tags',
                options: 'tags',
                required: true
            }
        ]
    },

    'condition_field': {
        title: 'Condition sur champ',
        fields: [
            {
                name: 'field_type',
                type: 'select',
                label: 'Type de champ',
                options: [
                    { value: 'standard', label: 'Champ standard' },
                    { value: 'classification', label: 'Champ de classification' },
                    { value: 'custom', label: 'Champ personnalisé' }
                ],
                required: true
            },
            {
                name: 'field_name',
                type: 'select',
                label: 'Champ',
                options: 'dynamic_fields',
                dependsOn: 'field_type',
                required: true
            },
            {
                name: 'operator',
                type: 'select',
                label: 'Opérateur',
                options: [
                    { value: 'equals', label: 'Égal à' },
                    { value: 'not_equals', label: 'Différent de' },
                    { value: 'contains', label: 'Contient' },
                    { value: 'not_contains', label: 'Ne contient pas' },
                    { value: 'starts_with', label: 'Commence par' },
                    { value: 'ends_with', label: 'Termine par' },
                    { value: 'is_empty', label: 'Est vide' },
                    { value: 'is_not_empty', label: 'N\'est pas vide' },
                    { value: 'greater_than', label: 'Supérieur à' },
                    { value: 'less_than', label: 'Inférieur à' },
                    { value: 'between', label: 'Entre' }
                ],
                required: true
            },
            {
                name: 'value',
                type: 'text',
                label: 'Valeur',
                conditional: { field: 'operator', notIn: ['is_empty', 'is_not_empty'] }
            },
            {
                name: 'value2',
                type: 'text',
                label: 'Valeur maximale',
                conditional: { field: 'operator', equals: 'between' }
            }
        ]
    },

    'condition_correspondent': {
        title: 'Condition sur correspondant',
        fields: [
            {
                name: 'mode',
                type: 'select',
                label: 'Mode de vérification',
                options: [
                    { value: 'is', label: 'Est' },
                    { value: 'is_not', label: 'N\'est pas' },
                    { value: 'is_any_of', label: 'Est l\'un de' },
                    { value: 'is_none_of', label: 'N\'est aucun de' }
                ],
                required: true,
                default: 'is'
            },
            {
                name: 'correspondent_ids',
                type: 'multiselect',
                label: 'Correspondants',
                options: 'correspondents',
                required: true
            }
        ]
    },

    // ============================================
    // TRAITEMENT (PROCESSING)
    // ============================================

    'process_ocr': {
        title: 'OCR - Extraction de texte',
        fields: [
            {
                name: 'language',
                type: 'select',
                label: 'Langue principale',
                options: [
                    { value: 'fra', label: 'Français' },
                    { value: 'eng', label: 'Anglais' },
                    { value: 'deu', label: 'Allemand' },
                    { value: 'ita', label: 'Italien' },
                    { value: 'fra+eng', label: 'Français + Anglais' },
                    { value: 'fra+deu', label: 'Français + Allemand' }
                ],
                default: 'fra'
            },
            {
                name: 'deskew',
                type: 'checkbox',
                label: 'Corriger l\'inclinaison',
                default: true
            },
            {
                name: 'clean',
                type: 'checkbox',
                label: 'Nettoyer l\'image',
                default: true
            }
        ]
    },

    'process_ai_extract': {
        title: 'Extraction IA',
        fields: [
            {
                name: 'extract_title',
                type: 'checkbox',
                label: 'Extraire le titre',
                default: true
            },
            {
                name: 'extract_date',
                type: 'checkbox',
                label: 'Extraire la date',
                default: true
            },
            {
                name: 'extract_amount',
                type: 'checkbox',
                label: 'Extraire le montant',
                default: true
            },
            {
                name: 'extract_correspondent',
                type: 'checkbox',
                label: 'Détecter le correspondant',
                default: true
            },
            {
                name: 'suggest_tags',
                type: 'checkbox',
                label: 'Suggérer des tags',
                default: true
            },
            {
                name: 'custom_fields',
                type: 'multiselect',
                label: 'Champs personnalisés à extraire',
                options: 'classification_fields',
                help: 'Champs de classification à remplir automatiquement'
            }
        ]
    },

    'process_classify': {
        title: 'Classification automatique',
        fields: [
            {
                name: 'auto_type',
                type: 'checkbox',
                label: 'Détecter le type de document',
                default: true
            },
            {
                name: 'confidence_threshold',
                type: 'number',
                label: 'Seuil de confiance (%)',
                min: 0,
                max: 100,
                default: 70,
                help: 'Seuil minimum pour appliquer la classification automatiquement'
            },
            {
                name: 'fallback_type_id',
                type: 'select',
                label: 'Type par défaut',
                options: 'document_types',
                help: 'Type à appliquer si la confiance est insuffisante'
            }
        ]
    },

    // ============================================
    // ACTIONS
    // ============================================

    'action_request_approval': {
        title: 'Demande d\'approbation',
        fields: [
            {
                name: 'assign_to_group_code',
                type: 'select',
                label: 'Groupe approbateur',
                options: 'groups',
                help: 'Groupe d\'utilisateurs qui recevra la demande'
            },
            {
                name: 'assign_to_user_id',
                type: 'select',
                label: 'Ou utilisateur spécifique',
                options: 'users',
                help: 'Alternative: assigner à un utilisateur précis'
            },
            {
                name: 'action_required',
                type: 'select',
                label: 'Action requise',
                options: [
                    { value: 'approve', label: 'Approuver' },
                    { value: 'reject', label: 'Refuser' },
                    { value: 'review', label: 'Réviser' },
                    { value: 'sign', label: 'Signer' }
                ],
                default: 'approve'
            },
            {
                name: 'email_subject',
                type: 'text',
                label: 'Sujet de l\'email',
                placeholder: 'Demande d\'approbation: {title}',
                help: 'Variables: {title}, {correspondent}, {amount}, {date}'
            },
            {
                name: 'message',
                type: 'textarea',
                label: 'Message personnalisé',
                rows: 3,
                placeholder: 'Merci de bien vouloir approuver ce document...'
            },
            {
                name: 'expires_hours',
                type: 'number',
                label: 'Expire après (heures)',
                default: 72,
                min: 1,
                help: 'Délai avant expiration de la demande'
            },
            {
                name: 'priority',
                type: 'select',
                label: 'Priorité',
                options: [
                    { value: 'low', label: 'Basse' },
                    { value: 'normal', label: 'Normale' },
                    { value: 'high', label: 'Haute' },
                    { value: 'urgent', label: 'Urgente' }
                ],
                default: 'normal'
            },
            {
                name: 'escalate_to_user_id',
                type: 'select',
                label: 'Escalader vers',
                options: 'users',
                help: 'Utilisateur pour escalade automatique si non traité'
            },
            {
                name: 'escalate_after_hours',
                type: 'number',
                label: 'Escalader après (heures)',
                placeholder: '24',
                min: 1
            }
        ]
    },

    'action_assign_group': {
        title: 'Assigner au groupe',
        fields: [
            {
                name: 'group_code',
                type: 'select',
                label: 'Groupe',
                options: 'groups',
                required: true
            },
            {
                name: 'notify_members',
                type: 'checkbox',
                label: 'Notifier les membres',
                default: true
            },
            {
                name: 'notification_message',
                type: 'textarea',
                label: 'Message de notification',
                rows: 2,
                placeholder: 'Un nouveau document vous a été assigné...',
                conditional: { field: 'notify_members', equals: true }
            }
        ]
    },

    'action_assign_user': {
        title: 'Assigner à un utilisateur',
        fields: [
            {
                name: 'user_id',
                type: 'select',
                label: 'Utilisateur',
                options: 'users',
                required: true
            },
            {
                name: 'notify',
                type: 'checkbox',
                label: 'Envoyer une notification',
                default: true
            }
        ]
    },

    'action_add_tag': {
        title: 'Ajouter des tags',
        fields: [
            {
                name: 'tag_ids',
                type: 'multiselect',
                label: 'Tags à ajouter',
                options: 'tags',
                required: true
            },
            {
                name: 'replace_existing',
                type: 'checkbox',
                label: 'Remplacer les tags existants',
                default: false,
                help: 'Si coché, supprime les tags existants avant d\'ajouter les nouveaux'
            }
        ]
    },

    'action_send_email': {
        title: 'Envoyer un email',
        fields: [
            {
                name: 'to_type',
                type: 'select',
                label: 'Destinataire',
                options: [
                    { value: 'user', label: 'Utilisateur spécifique' },
                    { value: 'group', label: 'Membres d\'un groupe' },
                    { value: 'owner', label: 'Propriétaire du document' },
                    { value: 'correspondent', label: 'Correspondant du document' },
                    { value: 'custom', label: 'Email personnalisé' }
                ],
                required: true
            },
            {
                name: 'to_user_id',
                type: 'select',
                label: 'Utilisateur',
                options: 'users',
                conditional: { field: 'to_type', equals: 'user' }
            },
            {
                name: 'to_group_code',
                type: 'select',
                label: 'Groupe',
                options: 'groups',
                conditional: { field: 'to_type', equals: 'group' }
            },
            {
                name: 'to_email',
                type: 'text',
                label: 'Adresse email',
                placeholder: 'exemple@domaine.com',
                conditional: { field: 'to_type', equals: 'custom' }
            },
            {
                name: 'subject',
                type: 'text',
                label: 'Sujet',
                placeholder: '{title} - Notification',
                required: true,
                help: 'Variables: {title}, {correspondent}, {amount}, {date}'
            },
            {
                name: 'body',
                type: 'textarea',
                label: 'Corps du message',
                rows: 5,
                help: 'Variables: {title}, {correspondent}, {amount}, {date}, {link}'
            },
            {
                name: 'attach_document',
                type: 'checkbox',
                label: 'Joindre le document',
                default: false
            }
        ]
    },

    'action_webhook': {
        title: 'Appel Webhook',
        fields: [
            {
                name: 'url',
                type: 'text',
                label: 'URL du webhook',
                placeholder: 'https://api.exemple.com/webhook',
                required: true
            },
            {
                name: 'method',
                type: 'select',
                label: 'Méthode HTTP',
                options: [
                    { value: 'POST', label: 'POST' },
                    { value: 'PUT', label: 'PUT' },
                    { value: 'GET', label: 'GET' },
                    { value: 'PATCH', label: 'PATCH' }
                ],
                default: 'POST'
            },
            {
                name: 'headers',
                type: 'textarea',
                label: 'En-têtes (JSON)',
                placeholder: '{"Authorization": "Bearer xxx"}',
                rows: 2
            },
            {
                name: 'payload_template',
                type: 'textarea',
                label: 'Template du payload (JSON)',
                rows: 4,
                placeholder: '{"document_id": "{id}", "title": "{title}"}',
                help: 'Variables: {id}, {title}, {correspondent}, {amount}, {date}'
            },
            {
                name: 'timeout',
                type: 'number',
                label: 'Timeout (secondes)',
                default: 30,
                min: 1,
                max: 300
            }
        ]
    },

    // ============================================
    // ATTENTES (WAITS)
    // ============================================

    'wait_approval': {
        title: 'Attendre approbation',
        fields: [
            {
                name: 'timeout_hours',
                type: 'number',
                label: 'Timeout (heures)',
                default: 72,
                min: 1,
                help: 'Durée maximale d\'attente avant timeout'
            },
            {
                name: 'reminder_hours',
                type: 'number',
                label: 'Rappel après (heures)',
                placeholder: '24',
                help: 'Envoyer un rappel si non traité'
            }
        ]
    },

    // ============================================
    // TEMPORISATEURS (TIMERS)
    // ============================================

    'timer_delay': {
        title: 'Délai d\'attente',
        fields: [
            {
                name: 'delay_value',
                type: 'number',
                label: 'Durée',
                min: 1,
                required: true
            },
            {
                name: 'delay_unit',
                type: 'select',
                label: 'Unité',
                options: [
                    { value: 'minutes', label: 'Minutes' },
                    { value: 'hours', label: 'Heures' },
                    { value: 'days', label: 'Jours' },
                    { value: 'weeks', label: 'Semaines' }
                ],
                required: true,
                default: 'hours'
            }
        ]
    }
};

/**
 * Classe pour générer et gérer les formulaires de configuration
 */
class NodeConfigFormRenderer {
    constructor(options = {}) {
        this.options = options;
        this.basePath = options.basePath || '';
        this.cachedOptions = {};
    }

    /**
     * Charge les options dynamiques depuis l'API
     */
    async loadOptions() {
        try {
            const response = await fetch(`${this.basePath}/api/workflow/options`);
            const data = await response.json();
            if (data.success) {
                this.cachedOptions = data.data;
            }
        } catch (error) {
            console.error('Erreur lors du chargement des options:', error);
        }
    }

    /**
     * Génère le HTML du formulaire pour un type de node
     */
    renderForm(nodeType, currentConfig = {}) {
        const formDef = NodeConfigForms[nodeType];
        if (!formDef) {
            return '<p class="text-sm text-gray-500">Aucune configuration requise pour ce type de node.</p>';
        }

        let html = `<div class="node-config-form" data-node-type="${nodeType}">`;
        html += `<h3 class="text-sm font-medium text-gray-800 mb-4">${formDef.title}</h3>`;

        formDef.fields.forEach(field => {
            html += this.renderField(field, currentConfig[field.name]);
        });

        html += '</div>';
        return html;
    }

    /**
     * Génère le HTML d'un champ de formulaire
     */
    renderField(field, value = null) {
        const id = `config-${field.name}`;
        const required = field.required ? 'required' : '';
        const defaultValue = value !== null && value !== undefined ? value : (field.default || '');

        // Attributs conditionnels pour affichage/masquage
        let conditionalAttrs = '';
        if (field.conditional) {
            conditionalAttrs = `data-conditional='${JSON.stringify(field.conditional)}'`;
        }

        let html = `<div class="form-field mb-4" ${conditionalAttrs}>`;

        // Label
        if (field.type !== 'checkbox') {
            html += `<label for="${id}" class="block text-xs font-medium text-gray-700 mb-1">
                ${field.label}
                ${field.required ? '<span class="text-red-500">*</span>' : ''}
            </label>`;
        }

        // Champ selon le type
        switch (field.type) {
            case 'text':
                html += `<input type="text" id="${id}" name="${field.name}"
                    value="${this.escapeHtml(String(defaultValue))}"
                    placeholder="${field.placeholder || ''}"
                    class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:ring-blue-500 focus:border-blue-500"
                    ${required}>`;
                break;

            case 'number':
                html += `<input type="number" id="${id}" name="${field.name}"
                    value="${defaultValue}"
                    min="${field.min !== undefined ? field.min : ''}"
                    max="${field.max !== undefined ? field.max : ''}"
                    step="${field.step || '1'}"
                    placeholder="${field.placeholder || ''}"
                    class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:ring-blue-500 focus:border-blue-500"
                    ${required}>`;
                break;

            case 'textarea':
                html += `<textarea id="${id}" name="${field.name}"
                    rows="${field.rows || 3}"
                    placeholder="${field.placeholder || ''}"
                    class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:ring-blue-500 focus:border-blue-500"
                    ${required}>${this.escapeHtml(String(defaultValue))}</textarea>`;
                break;

            case 'select':
                html += `<select id="${id}" name="${field.name}"
                    class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:ring-blue-500 focus:border-blue-500"
                    ${field.dependsOn ? `data-depends-on="${field.dependsOn}"` : ''}
                    ${required}>`;
                html += '<option value="">-- Sélectionner --</option>';
                html += this.renderSelectOptions(field.options, defaultValue);
                html += '</select>';
                break;

            case 'multiselect':
                html += `<select id="${id}" name="${field.name}" multiple
                    class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:ring-blue-500 focus:border-blue-500"
                    style="min-height: 100px;"
                    ${required}>`;
                html += this.renderSelectOptions(field.options, defaultValue);
                html += '</select>';
                html += '<p class="text-xs text-gray-400 mt-1">Maintenez Ctrl pour sélectionner plusieurs</p>';
                break;

            case 'checkbox':
                const checked = defaultValue === true || defaultValue === 'true' || defaultValue === '1' ? 'checked' : '';
                html += `<label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" id="${id}" name="${field.name}"
                        ${checked}
                        class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                    <span class="text-sm text-gray-700">${field.label}</span>
                </label>`;
                break;
        }

        // Texte d'aide
        if (field.help) {
            html += `<p class="text-xs text-gray-400 mt-1">${field.help}</p>`;
        }

        html += '</div>';
        return html;
    }

    /**
     * Génère les options d'un select
     */
    renderSelectOptions(optionsSource, selectedValue) {
        let html = '';
        let options = [];

        // Si c'est une chaîne, charger depuis le cache
        if (typeof optionsSource === 'string') {
            options = this.cachedOptions[optionsSource] || [];
            // Transformer en format standardisé
            options = options.map(opt => ({
                value: opt.id || opt.code || opt.value,
                label: opt.name || opt.label || opt.username || opt.path
            }));
        } else if (Array.isArray(optionsSource)) {
            options = optionsSource;
        }

        // Gérer les valeurs multiples (pour multiselect)
        const selectedValues = Array.isArray(selectedValue) ? selectedValue : [selectedValue];

        options.forEach(opt => {
            const val = opt.value !== undefined ? opt.value : opt;
            const label = opt.label !== undefined ? opt.label : opt;
            const isSelected = selectedValues.includes(val) || selectedValues.includes(String(val));
            html += `<option value="${this.escapeHtml(String(val))}" ${isSelected ? 'selected' : ''}>${this.escapeHtml(String(label))}</option>`;
        });

        return html;
    }

    /**
     * Récupère les valeurs du formulaire
     */
    getFormValues(formContainer) {
        const values = {};
        const formDef = NodeConfigForms[formContainer.dataset.nodeType];

        if (!formDef) return values;

        formDef.fields.forEach(field => {
            const input = formContainer.querySelector(`[name="${field.name}"]`);
            if (!input) return;

            if (field.type === 'checkbox') {
                values[field.name] = input.checked;
            } else if (field.type === 'multiselect') {
                values[field.name] = Array.from(input.selectedOptions).map(opt => opt.value);
            } else if (field.type === 'number') {
                values[field.name] = input.value !== '' ? parseFloat(input.value) : null;
            } else {
                values[field.name] = input.value;
            }
        });

        return values;
    }

    /**
     * Initialise les champs conditionnels
     */
    initConditionalFields(formContainer) {
        const conditionalFields = formContainer.querySelectorAll('[data-conditional]');

        conditionalFields.forEach(fieldDiv => {
            const condition = JSON.parse(fieldDiv.dataset.conditional);
            const dependentField = formContainer.querySelector(`[name="${condition.field}"]`);

            if (dependentField) {
                const updateVisibility = () => {
                    let show = false;
                    const currentValue = dependentField.type === 'checkbox' ? dependentField.checked : dependentField.value;

                    if (condition.equals !== undefined) {
                        show = currentValue === condition.equals || currentValue === String(condition.equals);
                    } else if (condition.notIn) {
                        show = !condition.notIn.includes(currentValue);
                    }

                    fieldDiv.style.display = show ? 'block' : 'none';
                };

                dependentField.addEventListener('change', updateVisibility);
                updateVisibility();
            }
        });
    }

    /**
     * Échappe le HTML
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}

// Exposer globalement
window.NodeConfigForms = NodeConfigForms;
window.NodeConfigFormRenderer = NodeConfigFormRenderer;
