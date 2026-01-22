/**
 * K-Docs - Workflow Designer JavaScript
 * Gestion du canvas workflow avec drag & drop
 */

class WorkflowDesigner {
    constructor(containerId, options = {}) {
        this.container = document.getElementById(containerId);
        this.basePath = options.basePath || '';
        this.workflowId = options.workflowId || null;
        this.nodes = [];
        this.edges = [];
        this.selectedNode = null;
        this.nextNodeId = 1;
        this.dragOffset = { x: 0, y: 0 };
        this.isDragging = false;
        this.dragNode = null;
        this.isConnecting = false;
        this.connectingFrom = null;
        this.tempLine = null;
        
        this.init();
    }
    
    init() {
        this.render();
        this.setupEventListeners();
        this.loadWorkflow();
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
        const names = {
            'trigger_scan': 'Scan dossier',
            'trigger_upload': 'Upload',
            'trigger_manual': 'Démarrage manuel',
            'process_ocr': 'OCR',
            'process_ai_extract': 'Extraction IA',
            'process_classify': 'Classification',
            'condition_category': 'Type document',
            'action_assign_user': 'Assigner utilisateur',
            'action_add_tag': 'Ajouter tag',
            'wait_approval': 'Approbation',
        };
        return names[nodeType] || nodeType;
    }
    
    getNodeColor(nodeType) {
        if (nodeType.startsWith('trigger_')) return '#3b82f6'; // blue
        if (nodeType.startsWith('process_')) return '#10b981'; // green
        if (nodeType.startsWith('condition_')) return '#f59e0b'; // yellow
        if (nodeType.startsWith('action_')) return '#8b5cf6'; // purple
        if (nodeType.startsWith('wait_')) return '#f97316'; // orange
        return '#6b7280'; // gray
    }
    
    renderNodes() {
        this.nodesLayer.innerHTML = '';
        
        this.nodes.forEach(node => {
            const color = this.getNodeColor(node.type);
            const isSelected = this.selectedNode?.id === node.id;
            
            const nodeGroup = document.createElementNS('http://www.w3.org/2000/svg', 'g');
            nodeGroup.setAttribute('class', 'workflow-node');
            nodeGroup.setAttribute('data-node-id', node.id);
            nodeGroup.setAttribute('transform', `translate(${node.x}, ${node.y})`);
            
            // Rectangle du node
            const rect = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
            rect.setAttribute('width', '150');
            rect.setAttribute('height', '60');
            rect.setAttribute('rx', '8');
            rect.setAttribute('fill', color);
            rect.setAttribute('stroke', isSelected ? '#000' : '#fff');
            rect.setAttribute('stroke-width', isSelected ? '3' : '1');
            rect.setAttribute('class', 'cursor-move');
            
            // Texte
            const text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
            text.setAttribute('x', '75');
            text.setAttribute('y', '35');
            text.setAttribute('text-anchor', 'middle');
            text.setAttribute('fill', '#fff');
            text.setAttribute('font-size', '12');
            text.setAttribute('font-weight', '500');
            text.textContent = node.name;
            
            nodeGroup.appendChild(rect);
            nodeGroup.appendChild(text);
            
            // Handles de connexion (entrée et sortie)
            const inputHandle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
            inputHandle.setAttribute('cx', '0');
            inputHandle.setAttribute('cy', '30');
            inputHandle.setAttribute('r', '6');
            inputHandle.setAttribute('fill', '#fff');
            inputHandle.setAttribute('stroke', color);
            inputHandle.setAttribute('stroke-width', '2');
            inputHandle.setAttribute('class', 'connection-handle input-handle');
            inputHandle.setAttribute('data-node-id', node.id);
            
            const outputHandle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
            outputHandle.setAttribute('cx', '150');
            outputHandle.setAttribute('cy', '30');
            outputHandle.setAttribute('r', '6');
            outputHandle.setAttribute('fill', '#fff');
            outputHandle.setAttribute('stroke', color);
            outputHandle.setAttribute('stroke-width', '2');
            outputHandle.setAttribute('class', 'connection-handle output-handle');
            outputHandle.setAttribute('data-node-id', node.id);
            
            nodeGroup.appendChild(inputHandle);
            nodeGroup.appendChild(outputHandle);
            
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
        
        this.edges.forEach(edge => {
            const sourceNode = this.nodes.find(n => n.id === edge.source);
            const targetNode = this.nodes.find(n => n.id === edge.target);
            
            if (!sourceNode || !targetNode) return;
            
            const x1 = sourceNode.x + 150;
            const y1 = sourceNode.y + 30;
            const x2 = targetNode.x;
            const y2 = targetNode.y + 30;
            
            const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
            line.setAttribute('x1', x1);
            line.setAttribute('y1', y1);
            line.setAttribute('x2', x2);
            line.setAttribute('y2', y2);
            line.setAttribute('stroke', '#6b7280');
            line.setAttribute('stroke-width', '2');
            line.setAttribute('marker-end', 'url(#arrowhead)');
            line.setAttribute('class', 'workflow-edge');
            line.setAttribute('data-edge-id', edge.id);
            
            // Double-clic pour supprimer la connexion
            line.addEventListener('dblclick', () => {
                if (confirm('Supprimer cette connexion ?')) {
                    this.edges = this.edges.filter(e => e.id !== edge.id);
                    this.renderEdges();
                }
            });
            
            this.edgesLayer.appendChild(line);
        });
    }
    
    startDrag(e, node) {
        // Si on clique sur un handle de connexion
        if (e.target.classList.contains('connection-handle')) {
            e.stopPropagation();
            e.preventDefault();
            
            if (e.target.classList.contains('output-handle')) {
                this.startConnection(node, e);
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
    
    startConnection(sourceNode, e) {
        this.connectingFrom = sourceNode;
        this.isConnecting = true;
        
        const rect = this.canvas.getBoundingClientRect();
        this.tempLine = document.createElementNS('http://www.w3.org/2000/svg', 'line');
        this.tempLine.setAttribute('x1', sourceNode.x + 150);
        this.tempLine.setAttribute('y1', sourceNode.y + 30);
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
        const exists = this.edges.some(e => 
            e.source === this.connectingFrom.id && e.target === targetNode.id
        );
        
        if (!exists) {
            this.edges.push({
                id: `edge_${Date.now()}`,
                source: this.connectingFrom.id,
                target: targetNode.id,
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
        
        content.innerHTML = `
            <div class="space-y-4">
                <div>
                    <label class="block text-xs font-medium text-gray-700 mb-1">Nom</label>
                    <input type="text" id="node-name" value="${node.name}" 
                           class="w-full px-3 py-2 border border-gray-300 rounded text-sm">
                </div>
                ${this.getConfigFields(node.type, node.config)}
                <div class="flex gap-2">
                    <button id="btn-save-node-config"
                            class="flex-1 px-3 py-2 bg-gray-900 text-white text-sm rounded hover:bg-gray-800">
                        Enregistrer
                    </button>
                    <button id="btn-delete-node"
                            class="px-3 py-2 bg-red-100 text-red-700 text-sm rounded hover:bg-red-200">
                        Supprimer
                    </button>
                </div>
            </div>
        `;
        
        // Attacher les event listeners avec le bon contexte
        const saveBtn = document.getElementById('btn-save-node-config');
        const deleteBtn = document.getElementById('btn-delete-node');
        
        if (saveBtn) {
            saveBtn.addEventListener('click', () => this.saveNodeConfig());
        }
        if (deleteBtn) {
            deleteBtn.addEventListener('click', () => this.deleteNode());
        }
    }
    
    getConfigFields(nodeType, config) {
        if (nodeType === 'trigger_scan') {
            return `
                <div>
                    <label class="block text-xs font-medium text-gray-700 mb-1">Dossier à surveiller</label>
                    <input type="text" id="config-watch_folder" value="${config.watch_folder || ''}" 
                           class="w-full px-3 py-2 border border-gray-300 rounded text-sm"
                           placeholder="/scan/input">
                </div>
            `;
        }
        if (nodeType === 'action_assign_user') {
            return `
                <div>
                    <label class="block text-xs font-medium text-gray-700 mb-1">ID Utilisateur</label>
                    <input type="number" id="config-user_id" value="${config.user_id || ''}" 
                           class="w-full px-3 py-2 border border-gray-300 rounded text-sm">
                </div>
            `;
        }
        if (nodeType === 'action_add_tag') {
            return `
                <div>
                    <label class="block text-xs font-medium text-gray-700 mb-1">IDs Tags (séparés par virgule)</label>
                    <input type="text" id="config-tag_ids" value="${(config.tag_ids || []).join(',')}" 
                           class="w-full px-3 py-2 border border-gray-300 rounded text-sm"
                           placeholder="1, 2, 3">
                </div>
            `;
        }
        return '<p class="text-sm text-gray-500">Aucune configuration requise</p>';
    }
    
    saveNodeConfig() {
        if (!this.selectedNode) return;
        
        const nameInput = document.getElementById('node-name');
        if (nameInput) {
            this.selectedNode.name = nameInput.value;
        }
        
        const config = {};
        document.querySelectorAll('#config-content input[id^="config-"]').forEach(input => {
            const key = input.id.replace('config-', '');
            if (key === 'tag_ids') {
                config[key] = input.value.split(',').map(id => parseInt(id.trim())).filter(id => !isNaN(id));
            } else {
                config[key] = input.value;
            }
        });
        
        this.selectedNode.config = config;
        this.renderNodes();
        
        // Feedback visuel
        const saveBtn = document.getElementById('btn-save-node-config');
        if (saveBtn) {
            const originalText = saveBtn.textContent;
            saveBtn.textContent = '✓ Enregistré';
            saveBtn.classList.add('bg-green-600');
            setTimeout(() => {
                saveBtn.textContent = originalText;
                saveBtn.classList.remove('bg-green-600');
            }, 1000);
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
                config: node.config,
                position_x: Math.round(node.x),
                position_y: Math.round(node.y),
                is_entry_point: index === 0,
            })),
            connections: this.edges.map(edge => ({
                from_node_id: parseInt(edge.source.replace('node_', '')),
                to_node_id: parseInt(edge.target.replace('node_', '')),
                output_name: 'default',
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
