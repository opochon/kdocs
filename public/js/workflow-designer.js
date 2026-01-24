/**
 * K-Docs - Workflow Designer JavaScript
 * Gestion du canvas workflow avec drag & drop
 * Version 2.0 - Style Alfresco avec formulaires dynamiques
 */

class WorkflowDesigner {
    constructor(containerId, options = {}) {
        this.container = document.getElementById(containerId);
        this.basePath = options.basePath || '';
        this.workflowId = options.workflowId || null;
        this.nodeCatalog = options.nodeCatalog || {};
        this.nodes = [];
        this.edges = [];
        this.selectedNode = null;
        this.nextNodeId = 1;
        this.dragOffset = { x: 0, y: 0 };
        this.isDragging = false;
        this.dragNode = null;
        this.isConnecting = false;
        this.connectingFrom = null;
        this.connectingOutput = null;
        this.tempLine = null;

        // Initialiser le renderer de formulaires
        this.formRenderer = new NodeConfigFormRenderer({
            basePath: this.basePath
        });

        // Construire l'index des nodes par type
        this.nodeIndex = {};
        Object.entries(this.nodeCatalog).forEach(([category, nodes]) => {
            nodes.forEach(node => {
                this.nodeIndex[node.type] = { ...node, category };
            });
        });

        this.init();
    }

    init() {
        this.render();
        this.setupEventListeners();
        this.loadWorkflow();
    }

    /**
     * Charge les options pour les selects dynamiques
     */
    async loadOptions() {
        await this.formRenderer.loadOptions();
    }
    
    render() {
        this.container.innerHTML = `
            <svg id="workflow-canvas" class="w-full h-full">
                <defs>
                    <marker id="arrowhead" markerWidth="10" markerHeight="10" refX="9" refY="3" orient="auto">
                        <polygon points="0 0, 10 3, 0 6" fill="#6b7280" />
                    </marker>
                </defs>
                <g id="edges-layer"></g>
                <g id="nodes-layer"></g>
            </svg>
        `;
        this.canvas = document.getElementById('workflow-canvas');
        this.edgesLayer = document.getElementById('edges-layer');
        this.nodesLayer = document.getElementById('nodes-layer');
    }
    
    setupEventListeners() {
        // Drag & drop depuis la toolbox
        document.querySelectorAll('.node-toolbox-item').forEach(item => {
            item.addEventListener('dragstart', (e) => {
                e.dataTransfer.setData('node-type', item.dataset.nodeType);
            });
        });
        
        // Drop sur le canvas
        this.canvas.addEventListener('dragover', (e) => {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'copy';
        });
        
        this.canvas.addEventListener('drop', (e) => {
            e.preventDefault();
            const nodeType = e.dataTransfer.getData('node-type');
            if (nodeType) {
                const rect = this.canvas.getBoundingClientRect();
                const x = e.clientX - rect.left;
                const y = e.clientY - rect.top;
                this.addNode(nodeType, x, y);
            }
        });
        
        // Clic hors node = déselectionner
        this.canvas.addEventListener('click', (e) => {
            if (e.target === this.canvas || e.target.id === 'edges-layer' || e.target.id === 'nodes-layer') {
                this.selectedNode = null;
                this.renderNodes();
                document.getElementById('config-panel')?.classList.add('hidden');
            }
        });
    }
    
    addNode(nodeType, x, y) {
        const nodeId = `node_${this.nextNodeId++}`;
        const node = {
            id: nodeId,
            type: nodeType,
            x: x,
            y: y,
            name: this.getNodeName(nodeType),
            config: {},
        };
        
        this.nodes.push(node);
        this.renderNodes();
        this.renderEdges();
    }
    
    getNodeName(nodeType) {
        // Utiliser le catalogue si disponible
        const nodeInfo = this.nodeIndex[nodeType];
        if (nodeInfo) return nodeInfo.name;

        // Fallback
        const names = {
            'trigger_scan': 'Scan dossier',
            'trigger_upload': 'Upload',
            'trigger_manual': 'Démarrage manuel',
            'trigger_document_added': 'Document ajouté',
            'trigger_tag_added': 'Tag ajouté',
            'process_ocr': 'OCR',
            'process_ai_extract': 'Extraction IA',
            'process_classify': 'Classification',
            'condition_category': 'Type document',
            'condition_amount': 'Montant',
            'condition_tag': 'Tag',
            'condition_field': 'Champ personnalisé',
            'condition_correspondent': 'Correspondant',
            'action_assign_user': 'Assigner utilisateur',
            'action_assign_group': 'Assigner au groupe',
            'action_add_tag': 'Ajouter tag',
            'action_send_email': 'Envoyer email',
            'action_webhook': 'Webhook',
            'action_request_approval': 'Demande d\'approbation',
            'wait_approval': 'Approbation',
            'timer_delay': 'Délai',
        };
        return names[nodeType] || nodeType;
    }

    getNodeColor(nodeType) {
        // Utiliser le catalogue si disponible
        const nodeInfo = this.nodeIndex[nodeType];
        if (nodeInfo) return nodeInfo.color;

        // Fallback basé sur le préfixe
        if (nodeType.startsWith('trigger_')) return '#3b82f6'; // blue
        if (nodeType.startsWith('process_')) return '#10b981'; // green
        if (nodeType.startsWith('condition_')) return '#f59e0b'; // yellow
        if (nodeType.startsWith('action_')) return '#8b5cf6'; // purple
        if (nodeType.startsWith('wait_')) return '#f97316'; // orange
        if (nodeType.startsWith('timer_')) return '#06b6d4'; // cyan
        return '#6b7280'; // gray
    }

    /**
     * Obtient les sorties (outputs) d'un type de node
     */
    getNodeOutputs(nodeType) {
        const nodeInfo = this.nodeIndex[nodeType];
        if (nodeInfo) return nodeInfo.outputs || ['default'];
        return ['default'];
    }
    
    renderNodes() {
        this.nodesLayer.innerHTML = '';

        this.nodes.forEach(node => {
            const color = this.getNodeColor(node.type);
            const isSelected = this.selectedNode?.id === node.id;
            const outputs = this.getNodeOutputs(node.type);
            const hasMultipleOutputs = outputs.length > 1;

            // Calculer la hauteur en fonction du nombre de sorties
            const nodeHeight = hasMultipleOutputs ? 60 + (outputs.length - 1) * 20 : 60;

            const nodeGroup = document.createElementNS('http://www.w3.org/2000/svg', 'g');
            nodeGroup.setAttribute('class', 'workflow-node');
            nodeGroup.setAttribute('data-node-id', node.id);
            nodeGroup.setAttribute('transform', `translate(${node.x}, ${node.y})`);

            // Rectangle du node
            const rect = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
            rect.setAttribute('width', '160');
            rect.setAttribute('height', String(nodeHeight));
            rect.setAttribute('rx', '8');
            rect.setAttribute('fill', color);
            rect.setAttribute('stroke', isSelected ? '#000' : '#fff');
            rect.setAttribute('stroke-width', isSelected ? '3' : '1');
            rect.setAttribute('class', 'cursor-move');

            // Texte du nom
            const text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
            text.setAttribute('x', '80');
            text.setAttribute('y', hasMultipleOutputs ? '22' : '35');
            text.setAttribute('text-anchor', 'middle');
            text.setAttribute('fill', '#fff');
            text.setAttribute('font-size', '12');
            text.setAttribute('font-weight', '500');
            text.textContent = node.name;

            nodeGroup.appendChild(rect);
            nodeGroup.appendChild(text);

            // Handle d'entrée (sauf pour les triggers)
            if (!node.type.startsWith('trigger_')) {
                const inputHandle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
                inputHandle.setAttribute('cx', '0');
                inputHandle.setAttribute('cy', String(nodeHeight / 2));
                inputHandle.setAttribute('r', '6');
                inputHandle.setAttribute('fill', '#fff');
                inputHandle.setAttribute('stroke', color);
                inputHandle.setAttribute('stroke-width', '2');
                inputHandle.setAttribute('class', 'connection-handle input-handle');
                inputHandle.setAttribute('data-node-id', node.id);
                nodeGroup.appendChild(inputHandle);
            }

            // Handles de sortie (multiples si nécessaire)
            const outputLabels = {
                'true': 'Oui',
                'false': 'Non',
                'approved': 'Approuvé',
                'rejected': 'Refusé',
                'timeout': 'Timeout',
                'default': ''
            };

            const outputColors = {
                'true': '#10b981',      // vert
                'false': '#ef4444',     // rouge
                'approved': '#10b981',  // vert
                'rejected': '#ef4444',  // rouge
                'timeout': '#f59e0b',   // orange
                'default': '#fff'
            };

            outputs.forEach((output, index) => {
                const yPos = hasMultipleOutputs
                    ? 30 + index * 25
                    : nodeHeight / 2;

                const outputHandle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
                outputHandle.setAttribute('cx', '160');
                outputHandle.setAttribute('cy', String(yPos));
                outputHandle.setAttribute('r', '6');
                outputHandle.setAttribute('fill', outputColors[output] || '#fff');
                outputHandle.setAttribute('stroke', color);
                outputHandle.setAttribute('stroke-width', '2');
                outputHandle.setAttribute('class', 'connection-handle output-handle');
                outputHandle.setAttribute('data-node-id', node.id);
                outputHandle.setAttribute('data-output', output);
                nodeGroup.appendChild(outputHandle);

                // Label de sortie pour les sorties multiples
                if (hasMultipleOutputs && outputLabels[output]) {
                    const outputLabel = document.createElementNS('http://www.w3.org/2000/svg', 'text');
                    outputLabel.setAttribute('x', '150');
                    outputLabel.setAttribute('y', String(yPos + 4));
                    outputLabel.setAttribute('text-anchor', 'end');
                    outputLabel.setAttribute('fill', '#fff');
                    outputLabel.setAttribute('font-size', '10');
                    outputLabel.textContent = outputLabels[output];
                    nodeGroup.appendChild(outputLabel);
                }
            });

            // Événements
            nodeGroup.addEventListener('mousedown', (e) => this.startDrag(e, node));
            nodeGroup.addEventListener('click', (e) => {
                e.stopPropagation();
                this.selectNode(node);
            });

            this.nodesLayer.appendChild(nodeGroup);
        });
    }
    
    renderEdges() {
        this.edgesLayer.innerHTML = '';

        // Couleurs des connexions par type de sortie
        const outputColors = {
            'true': '#10b981',      // vert
            'false': '#ef4444',     // rouge
            'approved': '#10b981',  // vert
            'rejected': '#ef4444',  // rouge
            'timeout': '#f59e0b',   // orange
            'default': '#6b7280'    // gris
        };

        this.edges.forEach(edge => {
            const sourceNode = this.nodes.find(n => n.id === edge.source);
            const targetNode = this.nodes.find(n => n.id === edge.target);

            if (!sourceNode || !targetNode) return;

            // Calculer la position Y de sortie en fonction de l'output
            const outputs = this.getNodeOutputs(sourceNode.type);
            const outputIndex = outputs.indexOf(edge.output || 'default');
            const hasMultipleOutputs = outputs.length > 1;
            const sourceHeight = hasMultipleOutputs ? 60 + (outputs.length - 1) * 20 : 60;
            const sourceY = hasMultipleOutputs
                ? 30 + Math.max(0, outputIndex) * 25
                : sourceHeight / 2;

            // Calculer la position Y d'entrée du target
            const targetOutputs = this.getNodeOutputs(targetNode.type);
            const targetHasMultiple = targetOutputs.length > 1;
            const targetHeight = targetHasMultiple ? 60 + (targetOutputs.length - 1) * 20 : 60;
            const targetY = targetHeight / 2;

            const x1 = sourceNode.x + 160;
            const y1 = sourceNode.y + sourceY;
            const x2 = targetNode.x;
            const y2 = targetNode.y + targetY;

            // Utiliser une courbe de Bézier pour un meilleur rendu
            const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            const dx = Math.abs(x2 - x1) * 0.5;
            const d = `M ${x1} ${y1} C ${x1 + dx} ${y1}, ${x2 - dx} ${y2}, ${x2} ${y2}`;
            path.setAttribute('d', d);
            path.setAttribute('fill', 'none');
            path.setAttribute('stroke', outputColors[edge.output] || outputColors['default']);
            path.setAttribute('stroke-width', '2');
            path.setAttribute('marker-end', 'url(#arrowhead)');
            path.setAttribute('class', 'workflow-edge');
            path.setAttribute('data-edge-id', edge.id);

            // Double-clic pour supprimer la connexion
            path.addEventListener('dblclick', () => {
                if (confirm('Supprimer cette connexion ?')) {
                    this.edges = this.edges.filter(e => e.id !== edge.id);
                    this.renderEdges();
                }
            });

            this.edgesLayer.appendChild(path);
        });
    }
    
    startDrag(e, node) {
        // Si on clique sur un handle de connexion
        if (e.target.classList.contains('connection-handle')) {
            e.stopPropagation();
            e.preventDefault();

            if (e.target.classList.contains('output-handle')) {
                const outputName = e.target.getAttribute('data-output') || 'default';
                this.startConnection(node, e, outputName);
            }
            return;
        }

        this.isDragging = true;
        this.dragNode = node;
        const rect = this.canvas.getBoundingClientRect();
        this.dragOffset = {
            x: e.clientX - rect.left - node.x,
            y: e.clientY - rect.top - node.y,
        };

        document.addEventListener('mousemove', this.onDrag);
        document.addEventListener('mouseup', this.onDragEnd);
    }
    
    startConnection(sourceNode, e, outputName = 'default') {
        this.connectingFrom = sourceNode;
        this.connectingOutput = outputName;
        this.isConnecting = true;

        // Calculer la position Y du handle de sortie
        const outputs = this.getNodeOutputs(sourceNode.type);
        const outputIndex = outputs.indexOf(outputName);
        const hasMultipleOutputs = outputs.length > 1;
        const nodeHeight = hasMultipleOutputs ? 60 + (outputs.length - 1) * 20 : 60;
        const yPos = hasMultipleOutputs ? 30 + outputIndex * 25 : nodeHeight / 2;

        const rect = this.canvas.getBoundingClientRect();
        this.tempLine = document.createElementNS('http://www.w3.org/2000/svg', 'line');
        this.tempLine.setAttribute('x1', sourceNode.x + 160);
        this.tempLine.setAttribute('y1', sourceNode.y + yPos);
        this.tempLine.setAttribute('x2', e.clientX - rect.left);
        this.tempLine.setAttribute('y2', e.clientY - rect.top);
        this.tempLine.setAttribute('stroke', '#3b82f6');
        this.tempLine.setAttribute('stroke-width', '2');
        this.tempLine.setAttribute('stroke-dasharray', '5,5');
        this.tempLine.setAttribute('marker-end', 'url(#arrowhead)');
        this.edgesLayer.appendChild(this.tempLine);

        document.addEventListener('mousemove', this.onConnectionDrag);
        document.addEventListener('mouseup', this.onConnectionEnd);

        this.canvas.style.cursor = 'crosshair';
    }
    
    onConnectionDrag = (e) => {
        if (!this.isConnecting || !this.tempLine) return;
        
        const rect = this.canvas.getBoundingClientRect();
        this.tempLine.setAttribute('x2', e.clientX - rect.left);
        this.tempLine.setAttribute('y2', e.clientY - rect.top);
    };
    
    onConnectionEnd = (e) => {
        document.removeEventListener('mousemove', this.onConnectionDrag);
        document.removeEventListener('mouseup', this.onConnectionEnd);
        
        if (this.tempLine) {
            this.tempLine.remove();
            this.tempLine = null;
        }
        
        // Vérifier si on a relâché sur un input-handle
        const target = e.target;
        if (target.classList && target.classList.contains('input-handle')) {
            const targetNodeId = target.getAttribute('data-node-id');
            const targetNode = this.nodes.find(n => n.id === targetNodeId);
            if (targetNode && this.connectingFrom && this.connectingFrom.id !== targetNodeId) {
                this.finishConnection(targetNode);
            }
        }
        
        this.isConnecting = false;
        this.connectingFrom = null;
        this.canvas.style.cursor = 'default';
    };
    
    finishConnection(targetNode) {
        // Vérifier si une connexion existe déjà avec le même output
        const exists = this.edges.some(e =>
            e.source === this.connectingFrom.id &&
            e.target === targetNode.id &&
            e.output === this.connectingOutput
        );

        if (!exists) {
            this.edges.push({
                id: `edge_${Date.now()}`,
                source: this.connectingFrom.id,
                target: targetNode.id,
                output: this.connectingOutput || 'default',
            });
            this.renderEdges();
        }
    }
    
    onDrag = (e) => {
        if (!this.isDragging || !this.dragNode) return;
        
        const rect = this.canvas.getBoundingClientRect();
        this.dragNode.x = e.clientX - rect.left - this.dragOffset.x;
        this.dragNode.y = e.clientY - rect.top - this.dragOffset.y;
        
        this.renderNodes();
        this.renderEdges();
    };
    
    onDragEnd = () => {
        this.isDragging = false;
        this.dragNode = null;
        document.removeEventListener('mousemove', this.onDrag);
        document.removeEventListener('mouseup', this.onDragEnd);
    };
    
    selectNode(node) {
        this.selectedNode = node;
        this.renderNodes();
        this.showConfigPanel(node);
    }
    
    showConfigPanel(node) {
        const panel = document.getElementById('config-panel');
        const content = document.getElementById('config-content');

        if (!panel || !content) return;

        panel.classList.remove('hidden');

        // Obtenir les infos du node depuis le catalogue
        const nodeInfo = this.nodeIndex[node.type] || {};
        const color = nodeInfo.color || this.getNodeColor(node.type);

        // Générer le formulaire dynamique
        const formHtml = this.formRenderer.renderForm(node.type, node.config);

        content.innerHTML = `
            <div class="space-y-4">
                <!-- En-tête avec le type de node -->
                <div class="flex items-center gap-2 pb-3 border-b border-gray-200">
                    <div class="w-8 h-8 rounded flex items-center justify-center" style="background-color: ${color}20;">
                        <span class="text-sm font-bold" style="color: ${color};">${node.type.split('_')[0].charAt(0).toUpperCase()}</span>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-800">${nodeInfo.name || node.type}</p>
                        <p class="text-xs text-gray-500">${nodeInfo.description || ''}</p>
                    </div>
                </div>

                <!-- Nom personnalisé du node -->
                <div>
                    <label class="block text-xs font-medium text-gray-700 mb-1">
                        Nom du node
                        <span class="text-gray-400 font-normal">(affiché sur le canvas)</span>
                    </label>
                    <input type="text" id="node-name" value="${this.escapeHtml(node.name)}"
                           class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:ring-blue-500 focus:border-blue-500">
                </div>

                <!-- Formulaire de configuration dynamique -->
                <div id="dynamic-config-form">
                    ${formHtml}
                </div>

                <!-- Boutons d'action -->
                <div class="flex gap-2 pt-3 border-t border-gray-200">
                    <button id="btn-save-node-config"
                            class="flex-1 px-3 py-2 bg-gray-900 text-white text-sm rounded hover:bg-gray-800 transition-colors">
                        Appliquer
                    </button>
                    <button id="btn-delete-node"
                            class="px-3 py-2 bg-red-100 text-red-700 text-sm rounded hover:bg-red-200 transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                        </svg>
                    </button>
                </div>
            </div>
        `;

        // Initialiser les champs conditionnels
        const formContainer = content.querySelector('.node-config-form');
        if (formContainer) {
            this.formRenderer.initConditionalFields(formContainer);
        }

        // Attacher les event listeners
        const saveBtn = document.getElementById('btn-save-node-config');
        const deleteBtn = document.getElementById('btn-delete-node');

        if (saveBtn) {
            saveBtn.addEventListener('click', () => this.saveNodeConfig());
        }
        if (deleteBtn) {
            deleteBtn.addEventListener('click', () => this.deleteNode());
        }
    }

    /**
     * Échappe le HTML pour éviter les injections XSS
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text || '';
        return div.innerHTML;
    }

    saveNodeConfig() {
        if (!this.selectedNode) return;

        // Récupérer le nom personnalisé
        const nameInput = document.getElementById('node-name');
        if (nameInput) {
            this.selectedNode.name = nameInput.value;
        }

        // Récupérer les valeurs du formulaire dynamique
        const formContainer = document.querySelector('.node-config-form');
        if (formContainer) {
            this.selectedNode.config = this.formRenderer.getFormValues(formContainer);
        }

        this.renderNodes();

        // Feedback visuel
        const saveBtn = document.getElementById('btn-save-node-config');
        if (saveBtn) {
            const originalText = saveBtn.textContent;
            const originalClass = saveBtn.className;
            saveBtn.textContent = 'Enregistré';
            saveBtn.className = saveBtn.className.replace('bg-gray-900', 'bg-green-600');
            setTimeout(() => {
                saveBtn.textContent = originalText;
                saveBtn.className = originalClass;
            }, 1500);
        }
    }
    
    deleteNode() {
        if (!this.selectedNode) {
            alert('Aucun node sélectionné');
            return;
        }
        
        const nodeId = this.selectedNode.id;
        const nodeName = this.selectedNode.name;
        
        if (confirm(`Supprimer le node "${nodeName}" ?`)) {
            this.nodes = this.nodes.filter(n => n.id !== nodeId);
            this.edges = this.edges.filter(e => e.source !== nodeId && e.target !== nodeId);
            this.selectedNode = null;
            
            const panel = document.getElementById('config-panel');
            if (panel) panel.classList.add('hidden');
            
            this.renderNodes();
            this.renderEdges();
        }
    }
    
    async loadWorkflow() {
        if (!this.workflowId) {
            this.renderNodes();
            return;
        }
        
        try {
            const response = await fetch(`${this.basePath}/api/workflows/${this.workflowId}`);
            const data = await response.json();
            
            if (data.success && data.data) {
                this.nodes = (data.data.nodes || []).map(node => ({
                    id: `node_${node.id}`,
                    type: node.node_type,
                    x: node.position_x || 100,
                    y: node.position_y || 100,
                    name: node.name,
                    config: node.config || {},
                    originalId: node.id,
                }));
                
                this.edges = (data.data.connections || []).map(conn => ({
                    id: `edge_${conn.id}`,
                    source: `node_${conn.from_node_id}`,
                    target: `node_${conn.to_node_id}`,
                    originalId: conn.id,
                }));
                
                this.nextNodeId = Math.max(...this.nodes.map(n => parseInt(n.id.replace('node_', '')) || 0), 0) + 1;
                this.renderNodes();
                this.renderEdges();
            }
        } catch (error) {
            console.error('Erreur chargement workflow:', error);
        }
    }
    
    async saveWorkflow() {
        if (this.nodes.length === 0) {
            alert('Ajoutez au moins un node avant de sauvegarder');
            return;
        }

        const workflowName = prompt('Nom du workflow:', 'Nouveau Workflow');
        if (!workflowName) return;

        const workflowData = {
            name: workflowName,
            nodes: this.nodes.map((node, index) => ({
                node_type: node.type,
                name: node.name,
                config: node.config || {},
                position_x: Math.round(node.x),
                position_y: Math.round(node.y),
                is_entry_point: node.type.startsWith('trigger_') || index === 0,
            })),
            connections: this.edges.map(edge => ({
                from_node_id: parseInt(edge.source.replace('node_', '')),
                to_node_id: parseInt(edge.target.replace('node_', '')),
                output_name: edge.output || 'default',
            })),
        };
        
        try {
            const url = this.workflowId 
                ? `${this.basePath}/api/workflows/${this.workflowId}`
                : `${this.basePath}/api/workflows`;
            const method = this.workflowId ? 'PUT' : 'POST';
            
            const response = await fetch(url, {
                method: method,
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(workflowData),
            });
            
            const data = await response.json();
            if (data.success) {
                alert('Workflow enregistré avec succès!');
                if (!this.workflowId && data.data?.id) {
                    window.location.href = `${this.basePath}/admin/workflows/${data.data.id}/designer`;
                }
            } else {
                alert('Erreur: ' + (data.error || 'Erreur inconnue'));
            }
        } catch (error) {
            console.error('Erreur sauvegarde:', error);
            alert('Erreur lors de la sauvegarde');
        }
    }
}

// Exposer globalement
window.WorkflowDesigner = WorkflowDesigner;
