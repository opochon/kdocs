/**
 * K-Docs - Workflow Designer JavaScript
 * Version 2.0 - Style Alfresco complet avec formulaires dynamiques
 */

class WorkflowDesigner {
    constructor(containerId, options = {}) {
        this.container = document.getElementById(containerId);
        this.basePath = options.basePath || '';
        this.workflowId = options.workflowId || null;
        this.data = options.data || window.KDOCS_WORKFLOW_DATA || {};
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
                    <marker id="arrowhead-green" markerWidth="10" markerHeight="10" refX="9" refY="3" orient="auto">
                        <polygon points="0 0, 10 3, 0 6" fill="#10b981" />
                    </marker>
                    <marker id="arrowhead-red" markerWidth="10" markerHeight="10" refX="9" refY="3" orient="auto">
                        <polygon points="0 0, 10 3, 0 6" fill="#ef4444" />
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
        document.querySelectorAll('.node-toolbox-item').forEach(item => {
            item.addEventListener('dragstart', (e) => {
                e.dataTransfer.setData('node-type', item.dataset.nodeType);
                e.dataTransfer.effectAllowed = 'copy';
            });
        });
        
        this.canvas.addEventListener('dragover', (e) => {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'copy';
        });
        
        this.canvas.addEventListener('drop', (e) => {
            e.preventDefault();
            const nodeType = e.dataTransfer.getData('node-type');
            if (nodeType) {
                const rect = this.canvas.getBoundingClientRect();
                const x = e.clientX - rect.left - 75;
                const y = e.clientY - rect.top - 30;
                this.addNode(nodeType, Math.max(10, x), Math.max(10, y));
            }
        });
        
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
        const info = this.getNodeInfo(nodeType);
        const node = {
            id: nodeId,
            type: nodeType,
            x: x,
            y: y,
            name: info.name || this.getNodeName(nodeType),
            config: {},
            outputs: info.outputs || ['default'],
        };
        
        this.nodes.push(node);
        this.renderNodes();
        this.renderEdges();
        this.selectNode(node);
    }
    
    getNodeInfo(nodeType) {
        const catalog = this.data.nodeCatalog || {};
        for (const category of Object.values(catalog)) {
            const node = category.find(n => n.type === nodeType);
            if (node) return node;
        }
        return { name: nodeType, outputs: ['default'] };
    }
    
    getNodeName(nodeType) {
        const names = {
            'trigger_document_added': 'Document ajouté',
            'trigger_tag_added': 'Tag ajouté',
            'trigger_scan': 'Scan dossier',
            'trigger_upload': 'Upload',
            'trigger_manual': 'Démarrage manuel',
            'process_ocr': 'OCR',
            'process_ai_extract': 'Extraction IA',
            'process_classify': 'Classification',
            'condition_category': 'Type document',
            'condition_amount': 'Montant',
            'condition_tag': 'Tag',
            'condition_field': 'Champ',
            'condition_correspondent': 'Correspondant',
            'action_request_approval': 'Demande approbation',
            'action_assign_group': 'Assigner groupe',
            'action_assign_user': 'Assigner utilisateur',
            'action_add_tag': 'Ajouter tag',
            'action_send_email': 'Envoyer email',
            'action_webhook': 'Webhook',
            'wait_approval': 'Attendre approbation',
            'timer_delay': 'Délai',
        };
        return names[nodeType] || nodeType;
    }
    
    getNodeColor(nodeType) {
        if (nodeType.startsWith('trigger_')) return '#3b82f6';
        if (nodeType.startsWith('process_')) return '#10b981';
        if (nodeType.startsWith('condition_')) return '#f59e0b';
        if (nodeType.startsWith('action_')) return '#8b5cf6';
        if (nodeType.startsWith('wait_')) return '#f97316';
        if (nodeType.startsWith('timer_')) return '#06b6d4';
        return '#6b7280';
    }
    
    renderNodes() {
        this.nodesLayer.innerHTML = '';
        
        this.nodes.forEach(node => {
            const color = this.getNodeColor(node.type);
            const isSelected = this.selectedNode?.id === node.id;
            const outputs = node.outputs || ['default'];
            const hasMultipleOutputs = outputs.length > 1;
            const nodeHeight = hasMultipleOutputs ? 60 + (outputs.length - 1) * 20 : 60;
            
            const nodeGroup = document.createElementNS('http://www.w3.org/2000/svg', 'g');
            nodeGroup.setAttribute('class', 'workflow-node');
            nodeGroup.setAttribute('data-node-id', node.id);
            nodeGroup.setAttribute('transform', `translate(${node.x}, ${node.y})`);
            
            // Rectangle principal
            const rect = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
            rect.setAttribute('width', '160');
            rect.setAttribute('height', nodeHeight);
            rect.setAttribute('rx', '8');
            rect.setAttribute('fill', color);
            rect.setAttribute('stroke', isSelected ? '#1f2937' : '#ffffff');
            rect.setAttribute('stroke-width', isSelected ? '3' : '1');
            rect.setAttribute('class', 'cursor-move');
            rect.setAttribute('filter', 'drop-shadow(0 2px 4px rgba(0,0,0,0.1))');
            
            // Icône du type
            const iconBg = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
            iconBg.setAttribute('cx', '20');
            iconBg.setAttribute('cy', '20');
            iconBg.setAttribute('r', '12');
            iconBg.setAttribute('fill', 'rgba(255,255,255,0.2)');
            
            // Texte du nom
            const text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
            text.setAttribute('x', '40');
            text.setAttribute('y', '25');
            text.setAttribute('fill', '#fff');
            text.setAttribute('font-size', '12');
            text.setAttribute('font-weight', '500');
            text.textContent = node.name.length > 15 ? node.name.substring(0, 15) + '...' : node.name;
            
            // Type en petit
            const typeText = document.createElementNS('http://www.w3.org/2000/svg', 'text');
            typeText.setAttribute('x', '40');
            typeText.setAttribute('y', '40');
            typeText.setAttribute('fill', 'rgba(255,255,255,0.7)');
            typeText.setAttribute('font-size', '9');
            typeText.textContent = node.type.replace('_', ' ');
            
            nodeGroup.appendChild(rect);
            nodeGroup.appendChild(iconBg);
            nodeGroup.appendChild(text);
            nodeGroup.appendChild(typeText);
            
            // Handle d'entrée
            const inputHandle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
            inputHandle.setAttribute('cx', '0');
            inputHandle.setAttribute('cy', '30');
            inputHandle.setAttribute('r', '7');
            inputHandle.setAttribute('fill', '#fff');
            inputHandle.setAttribute('stroke', color);
            inputHandle.setAttribute('stroke-width', '2');
            inputHandle.setAttribute('class', 'connection-handle input-handle');
            inputHandle.setAttribute('data-node-id', node.id);
            nodeGroup.appendChild(inputHandle);
            
            // Handles de sortie (multiple pour conditions)
            outputs.forEach((output, index) => {
                const outputY = hasMultipleOutputs ? 25 + index * 25 : 30;
                
                const outputHandle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
                outputHandle.setAttribute('cx', '160');
                outputHandle.setAttribute('cy', outputY);
                outputHandle.setAttribute('r', '7');
                outputHandle.setAttribute('fill', output === 'true' || output === 'approved' ? '#10b981' : 
                                          output === 'false' || output === 'rejected' ? '#ef4444' : '#fff');
                outputHandle.setAttribute('stroke', color);
                outputHandle.setAttribute('stroke-width', '2');
                outputHandle.setAttribute('class', 'connection-handle output-handle');
                outputHandle.setAttribute('data-node-id', node.id);
                outputHandle.setAttribute('data-output', output);
                
                // Label de sortie pour les conditions
                if (hasMultipleOutputs) {
                    const outputLabel = document.createElementNS('http://www.w3.org/2000/svg', 'text');
                    outputLabel.setAttribute('x', '150');
                    outputLabel.setAttribute('y', outputY + 4);
                    outputLabel.setAttribute('fill', 'rgba(255,255,255,0.8)');
                    outputLabel.setAttribute('font-size', '8');
                    outputLabel.setAttribute('text-anchor', 'end');
                    outputLabel.textContent = output === 'true' ? 'Oui' : output === 'false' ? 'Non' : 
                                             output === 'approved' ? '✓' : output === 'rejected' ? '✗' : output;
                    nodeGroup.appendChild(outputLabel);
                }
                
                nodeGroup.appendChild(outputHandle);
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
        
        this.edges.forEach(edge => {
            const sourceNode = this.nodes.find(n => n.id === edge.source);
            const targetNode = this.nodes.find(n => n.id === edge.target);
            
            if (!sourceNode || !targetNode) return;
            
            const outputs = sourceNode.outputs || ['default'];
            const outputIndex = outputs.indexOf(edge.output || 'default');
            const hasMultipleOutputs = outputs.length > 1;
            
            const x1 = sourceNode.x + 160;
            const y1 = sourceNode.y + (hasMultipleOutputs ? 25 + Math.max(0, outputIndex) * 25 : 30);
            const x2 = targetNode.x;
            const y2 = targetNode.y + 30;
            
            // Courbe de Bézier pour des connexions plus élégantes
            const midX = (x1 + x2) / 2;
            const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            const d = `M ${x1} ${y1} C ${midX} ${y1}, ${midX} ${y2}, ${x2} ${y2}`;
            path.setAttribute('d', d);
            path.setAttribute('fill', 'none');
            
            // Couleur selon le type de sortie
            let strokeColor = '#6b7280';
            let marker = 'url(#arrowhead)';
            if (edge.output === 'true' || edge.output === 'approved') {
                strokeColor = '#10b981';
                marker = 'url(#arrowhead-green)';
            } else if (edge.output === 'false' || edge.output === 'rejected') {
                strokeColor = '#ef4444';
                marker = 'url(#arrowhead-red)';
            }
            
            path.setAttribute('stroke', strokeColor);
            path.setAttribute('stroke-width', '2');
            path.setAttribute('marker-end', marker);
            path.setAttribute('class', 'workflow-edge');
            path.setAttribute('data-edge-id', edge.id);
            
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
        if (e.target.classList.contains('connection-handle')) {
            e.stopPropagation();
            e.preventDefault();
            
            if (e.target.classList.contains('output-handle')) {
                const output = e.target.getAttribute('data-output') || 'default';
                this.startConnection(node, e, output);
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
    
    startConnection(sourceNode, e, output = 'default') {
        this.connectingFrom = sourceNode;
        this.connectingOutput = output;
        this.isConnecting = true;
        
        const rect = this.canvas.getBoundingClientRect();
        const outputs = sourceNode.outputs || ['default'];
        const outputIndex = outputs.indexOf(output);
        const hasMultipleOutputs = outputs.length > 1;
        const startY = sourceNode.y + (hasMultipleOutputs ? 25 + outputIndex * 25 : 30);
        
        this.tempLine = document.createElementNS('http://www.w3.org/2000/svg', 'line');
        this.tempLine.setAttribute('x1', sourceNode.x + 160);
        this.tempLine.setAttribute('y1', startY);
        this.tempLine.setAttribute('x2', e.clientX - rect.left);
        this.tempLine.setAttribute('y2', e.clientY - rect.top);
        this.tempLine.setAttribute('stroke', '#3b82f6');
        this.tempLine.setAttribute('stroke-width', '2');
        this.tempLine.setAttribute('stroke-dasharray', '5,5');
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
        
        const target = e.target;
        if (target.classList && target.classList.contains('input-handle')) {
            const targetNodeId = target.getAttribute('data-node-id');
            const targetNode = this.nodes.find(n => n.id === targetNodeId);
            if (targetNode && this.connectingFrom && this.connectingFrom.id !== targetNodeId) {
                this.finishConnection(targetNode, this.connectingOutput);
            }
        }
        
        this.isConnecting = false;
        this.connectingFrom = null;
        this.connectingOutput = 'default';
        this.canvas.style.cursor = 'default';
    };
    
    finishConnection(targetNode, output = 'default') {
        const exists = this.edges.some(e => 
            e.source === this.connectingFrom.id && e.target === targetNode.id && e.output === output
        );
        
        if (!exists) {
            this.edges.push({
                id: `edge_${Date.now()}`,
                source: this.connectingFrom.id,
                target: targetNode.id,
                output: output,
            });
            this.renderEdges();
        }
    }
    
    onDrag = (e) => {
        if (!this.isDragging || !this.dragNode) return;
        const rect = this.canvas.getBoundingClientRect();
        this.dragNode.x = Math.max(0, e.clientX - rect.left - this.dragOffset.x);
        this.dragNode.y = Math.max(0, e.clientY - rect.top - this.dragOffset.y);
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
        
        const configFields = this.getConfigFields(node.type, node.config);
        
        content.innerHTML = `
            <div class="space-y-4">
                <div>
                    <label class="block text-xs font-medium text-gray-700 mb-1">Nom du node</label>
                    <input type="text" id="node-name" value="${this.escapeHtml(node.name)}" 
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
                
                <div class="border-t border-gray-200 pt-4">
                    <h3 class="text-xs font-medium text-gray-500 uppercase mb-3">Configuration</h3>
                    ${configFields}
                </div>
                
                <div class="flex gap-2 pt-4 border-t border-gray-200">
                    <button id="btn-save-node-config"
                            class="flex-1 px-3 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700 transition-colors">
                        <i class="fas fa-check mr-1"></i> Enregistrer
                    </button>
                    <button id="btn-delete-node"
                            class="px-3 py-2 bg-red-100 text-red-700 text-sm rounded-lg hover:bg-red-200 transition-colors">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </div>
        `;
        
        document.getElementById('btn-save-node-config')?.addEventListener('click', () => this.saveNodeConfig());
        document.getElementById('btn-delete-node')?.addEventListener('click', () => this.deleteNode());
    }
    
    getConfigFields(nodeType, config) {
        const data = this.data;
        
        // Formulaires dynamiques selon le type de node
        switch (nodeType) {
            case 'trigger_document_added':
                return this.buildFormFields([
                    { type: 'multiselect', name: 'filter_document_type_ids', label: 'Types de document', options: data.documentTypes?.map(t => ({value: t.id, label: t.label})) || [], value: config.filter_document_type_ids },
                    { type: 'multiselect', name: 'filter_tag_ids', label: 'Filtrer par tags', options: data.tags?.map(t => ({value: t.id, label: t.name})) || [], value: config.filter_tag_ids },
                    { type: 'number', name: 'filter_min_amount', label: 'Montant minimum', value: config.filter_min_amount },
                    { type: 'number', name: 'filter_max_amount', label: 'Montant maximum', value: config.filter_max_amount },
                ], config);
                
            case 'condition_category':
                return this.buildFormFields([
                    { type: 'select', name: 'match_mode', label: 'Mode', options: [
                        {value: 'exact', label: 'Type exact'},
                        {value: 'any', label: 'A un type'},
                        {value: 'none', label: 'Sans type'},
                        {value: 'list', label: 'Dans la liste'},
                    ], value: config.match_mode || 'exact' },
                    { type: 'select', name: 'document_type_id', label: 'Type de document', options: data.documentTypes?.map(t => ({value: t.id, label: t.label})) || [], value: config.document_type_id },
                ], config);
                
            case 'condition_amount':
                return this.buildFormFields([
                    { type: 'select', name: 'operator', label: 'Opérateur', options: [
                        {value: '>', label: 'Supérieur à (>)'},
                        {value: '>=', label: 'Supérieur ou égal (>=)'},
                        {value: '<', label: 'Inférieur à (<)'},
                        {value: '<=', label: 'Inférieur ou égal (<=)'},
                        {value: '==', label: 'Égal à (=)'},
                        {value: '!=', label: 'Différent de (≠)'},
                        {value: 'between', label: 'Entre'},
                    ], value: config.operator || '>' },
                    { type: 'number', name: 'value', label: 'Valeur', value: config.value },
                    { type: 'number', name: 'value2', label: 'Valeur 2 (pour "entre")', value: config.value2 },
                ], config);
                
            case 'condition_tag':
                return this.buildFormFields([
                    { type: 'select', name: 'match_mode', label: 'Mode', options: [
                        {value: 'any', label: 'Au moins un tag'},
                        {value: 'all', label: 'Tous les tags'},
                        {value: 'none', label: 'Aucun de ces tags'},
                        {value: 'has_any', label: 'A au moins un tag'},
                        {value: 'has_none', label: 'Sans aucun tag'},
                    ], value: config.match_mode || 'any' },
                    { type: 'multiselect', name: 'tag_ids', label: 'Tags', options: data.tags?.map(t => ({value: t.id, label: t.name})) || [], value: config.tag_ids },
                ], config);
                
            case 'condition_field':
                return this.buildFormFields([
                    { type: 'select', name: 'field_type', label: 'Type de champ', options: [
                        {value: 'document', label: 'Champ standard'},
                        {value: 'classification', label: 'Champ de classification'},
                        {value: 'custom', label: 'Champ personnalisé'},
                    ], value: config.field_type || 'document' },
                    { type: 'select', name: 'field_name', label: 'Champ', options: [
                        {value: 'amount', label: 'Montant'},
                        {value: 'title', label: 'Titre'},
                        {value: 'status', label: 'Statut'},
                        ...((data.classificationFields || []).map(f => ({value: f.field_code, label: f.field_name})))
                    ], value: config.field_name },
                    { type: 'select', name: 'operator', label: 'Opérateur', options: [
                        {value: '==', label: 'Égal'},
                        {value: '!=', label: 'Différent'},
                        {value: 'contains', label: 'Contient'},
                        {value: '>', label: 'Supérieur'},
                        {value: '<', label: 'Inférieur'},
                    ], value: config.operator || '==' },
                    { type: 'text', name: 'value', label: 'Valeur', value: config.value },
                ], config);
                
            case 'condition_correspondent':
                return this.buildFormFields([
                    { type: 'select', name: 'match_mode', label: 'Mode', options: [
                        {value: 'exact', label: 'Correspondant exact'},
                        {value: 'any', label: 'A un correspondant'},
                        {value: 'none', label: 'Sans correspondant'},
                        {value: 'is_supplier', label: 'Est un fournisseur'},
                        {value: 'is_customer', label: 'Est un client'},
                        {value: 'list', label: 'Dans la liste'},
                    ], value: config.match_mode || 'exact' },
                    { type: 'select', name: 'correspondent_id', label: 'Correspondant', options: data.correspondents?.map(c => ({value: c.id, label: c.name})) || [], value: config.correspondent_id },
                ], config);
                
            case 'action_request_approval':
                return this.buildFormFields([
                    { type: 'select', name: 'assign_to_group_id', label: 'Groupe approbateur', options: data.groups?.map(g => ({value: g.id, label: g.name})) || [], value: config.assign_to_group_id },
                    { type: 'select', name: 'assign_to_user_id', label: 'Ou utilisateur', options: data.users?.map(u => ({value: u.id, label: u.full_name || u.username})) || [], value: config.assign_to_user_id },
                    { type: 'text', name: 'email_subject', label: 'Sujet email', value: config.email_subject || 'Demande d\'approbation: {title}', placeholder: '{title}, {correspondent}, {amount}...' },
                    { type: 'textarea', name: 'message', label: 'Message', value: config.message },
                    { type: 'number', name: 'expires_hours', label: 'Expire après (heures)', value: config.expires_hours || 72 },
                    { type: 'select', name: 'priority', label: 'Priorité', options: [
                        {value: 'low', label: 'Basse'},
                        {value: 'normal', label: 'Normale'},
                        {value: 'high', label: 'Haute'},
                        {value: 'urgent', label: 'Urgente'},
                    ], value: config.priority || 'normal' },
                    { type: 'select', name: 'escalate_to_user_id', label: 'Escalader à', options: data.users?.map(u => ({value: u.id, label: u.full_name || u.username})) || [], value: config.escalate_to_user_id },
                    { type: 'number', name: 'escalate_after_hours', label: 'Escalader après (heures)', value: config.escalate_after_hours },
                ], config);
                
            case 'action_assign_group':
                return this.buildFormFields([
                    { type: 'select', name: 'group_id', label: 'Groupe', options: data.groups?.map(g => ({value: g.id, label: g.name})) || [], value: config.group_id },
                    { type: 'text', name: 'group_code', label: 'Ou code groupe', value: config.group_code, placeholder: 'ACCOUNTING, SUPERVISORS...' },
                    { type: 'select', name: 'assignment_type', label: 'Type d\'assignation', options: [
                        {value: 'processor', label: 'À traiter'},
                        {value: 'reviewer', label: 'À réviser'},
                        {value: 'approver', label: 'À approuver'},
                    ], value: config.assignment_type || 'processor' },
                    { type: 'checkbox', name: 'notify_members', label: 'Notifier les membres', value: config.notify_members },
                ], config);
                
            case 'action_assign_user':
                return this.buildFormFields([
                    { type: 'select', name: 'user_id', label: 'Utilisateur', options: data.users?.map(u => ({value: u.id, label: u.full_name || u.username})) || [], value: config.user_id },
                ], config);
                
            case 'action_add_tag':
                return this.buildFormFields([
                    { type: 'multiselect', name: 'tag_ids', label: 'Tags à ajouter', options: data.tags?.map(t => ({value: t.id, label: t.name})) || [], value: config.tag_ids },
                ], config);
                
            case 'action_send_email':
                return this.buildFormFields([
                    { type: 'text', name: 'to', label: 'Destinataire(s)', value: config.to, placeholder: 'email@example.com, autre@example.com' },
                    { type: 'text', name: 'subject', label: 'Sujet', value: config.subject, placeholder: 'Notification: {title}' },
                    { type: 'textarea', name: 'body', label: 'Corps (HTML)', value: config.body },
                    { type: 'checkbox', name: 'include_document', label: 'Joindre le document', value: config.include_document },
                ], config);
                
            case 'timer_delay':
                return this.buildFormFields([
                    { type: 'number', name: 'delay_seconds', label: 'Délai (secondes)', value: config.delay_seconds },
                    { type: 'number', name: 'delay_minutes', label: 'Ou minutes', value: config.delay_minutes },
                    { type: 'number', name: 'delay_hours', label: 'Ou heures', value: config.delay_hours },
                ], config);
                
            default:
                return '<p class="text-sm text-gray-500 italic">Aucune configuration requise pour ce composant.</p>';
        }
    }
    
    buildFormFields(fields, currentConfig) {
        return fields.map(field => {
            const value = currentConfig[field.name] ?? field.value ?? '';
            const id = `config-${field.name}`;
            
            switch (field.type) {
                case 'text':
                    return `
                        <div class="mb-3">
                            <label class="block text-xs font-medium text-gray-600 mb-1">${field.label}</label>
                            <input type="text" id="${id}" value="${this.escapeHtml(value)}" 
                                   placeholder="${field.placeholder || ''}"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500">
                        </div>`;
                        
                case 'number':
                    return `
                        <div class="mb-3">
                            <label class="block text-xs font-medium text-gray-600 mb-1">${field.label}</label>
                            <input type="number" id="${id}" value="${value}" step="any"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500">
                        </div>`;
                        
                case 'textarea':
                    return `
                        <div class="mb-3">
                            <label class="block text-xs font-medium text-gray-600 mb-1">${field.label}</label>
                            <textarea id="${id}" rows="3"
                                      class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500">${this.escapeHtml(value)}</textarea>
                        </div>`;
                        
                case 'select':
                    const options = (field.options || []).map(o => 
                        `<option value="${o.value}" ${o.value == value ? 'selected' : ''}>${this.escapeHtml(o.label)}</option>`
                    ).join('');
                    return `
                        <div class="mb-3">
                            <label class="block text-xs font-medium text-gray-600 mb-1">${field.label}</label>
                            <select id="${id}" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500">
                                <option value="">-- Sélectionner --</option>
                                ${options}
                            </select>
                        </div>`;
                        
                case 'multiselect':
                    const selectedValues = Array.isArray(value) ? value : [];
                    const checkboxes = (field.options || []).map(o => `
                        <label class="flex items-center gap-2 p-1.5 hover:bg-gray-50 rounded cursor-pointer">
                            <input type="checkbox" name="${id}[]" value="${o.value}" 
                                   ${selectedValues.includes(o.value) || selectedValues.includes(String(o.value)) ? 'checked' : ''}
                                   class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                            <span class="text-sm text-gray-700">${this.escapeHtml(o.label)}</span>
                        </label>
                    `).join('');
                    return `
                        <div class="mb-3">
                            <label class="block text-xs font-medium text-gray-600 mb-1">${field.label}</label>
                            <div id="${id}" class="max-h-32 overflow-y-auto border border-gray-300 rounded-lg p-2 bg-white">
                                ${checkboxes || '<span class="text-gray-400 text-sm">Aucune option</span>'}
                            </div>
                        </div>`;
                        
                case 'checkbox':
                    return `
                        <div class="mb-3">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" id="${id}" ${value ? 'checked' : ''}
                                       class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                <span class="text-sm text-gray-700">${field.label}</span>
                            </label>
                        </div>`;
                        
                default:
                    return '';
            }
        }).join('');
    }
    
    saveNodeConfig() {
        if (!this.selectedNode) return;
        
        const nameInput = document.getElementById('node-name');
        if (nameInput) {
            this.selectedNode.name = nameInput.value;
        }
        
        const config = {};
        document.querySelectorAll('#config-content input[id^="config-"], #config-content select[id^="config-"], #config-content textarea[id^="config-"]').forEach(input => {
            const key = input.id.replace('config-', '');
            
            if (input.type === 'checkbox') {
                config[key] = input.checked;
            } else if (input.type === 'number') {
                config[key] = input.value ? parseFloat(input.value) : null;
            } else {
                config[key] = input.value;
            }
        });
        
        // Gérer les multiselect (checkboxes multiples)
        document.querySelectorAll('#config-content div[id^="config-"]').forEach(container => {
            const checkboxes = container.querySelectorAll('input[type="checkbox"]');
            if (checkboxes.length > 0) {
                const key = container.id.replace('config-', '');
                config[key] = Array.from(checkboxes)
                    .filter(cb => cb.checked)
                    .map(cb => isNaN(cb.value) ? cb.value : parseInt(cb.value));
            }
        });
        
        this.selectedNode.config = config;
        this.renderNodes();
        
        // Feedback visuel
        const btn = document.getElementById('btn-save-node-config');
        if (btn) {
            const originalHTML = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-check mr-1"></i> Enregistré !';
            btn.classList.remove('bg-blue-600');
            btn.classList.add('bg-green-600');
            setTimeout(() => {
                btn.innerHTML = originalHTML;
                btn.classList.remove('bg-green-600');
                btn.classList.add('bg-blue-600');
            }, 1500);
        }
    }
    
    deleteNode() {
        if (!this.selectedNode) return;
        
        if (confirm(`Supprimer "${this.selectedNode.name}" ?`)) {
            const nodeId = this.selectedNode.id;
            this.nodes = this.nodes.filter(n => n.id !== nodeId);
            this.edges = this.edges.filter(e => e.source !== nodeId && e.target !== nodeId);
            this.selectedNode = null;
            
            document.getElementById('config-panel')?.classList.add('hidden');
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
                this.nodes = (data.data.nodes || []).map(node => {
                    const info = this.getNodeInfo(node.node_type);
                    return {
                        id: `node_${node.id}`,
                        type: node.node_type,
                        x: node.position_x || 100,
                        y: node.position_y || 100,
                        name: node.name,
                        config: node.config || {},
                        outputs: info.outputs || ['default'],
                        originalId: node.id,
                    };
                });
                
                this.edges = (data.data.connections || []).map(conn => ({
                    id: `edge_${conn.id}`,
                    source: `node_${conn.from_node_id}`,
                    target: `node_${conn.to_node_id}`,
                    output: conn.output_name || 'default',
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
            alert('Ajoutez au moins un composant avant de sauvegarder');
            return;
        }
        
        const workflowName = prompt('Nom du workflow:', 'Nouveau Workflow');
        if (!workflowName) return;
        
        // Trouver les entry points (nodes sans connexion entrante)
        const nodesWithIncoming = new Set(this.edges.map(e => e.target));
        
        const workflowData = {
            name: workflowName,
            nodes: this.nodes.map((node) => ({
                node_type: node.type,
                name: node.name,
                config: node.config,
                position_x: Math.round(node.x),
                position_y: Math.round(node.y),
                is_entry_point: !nodesWithIncoming.has(node.id),
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
                alert('✅ Workflow enregistré avec succès !');
                if (!this.workflowId && data.data?.id) {
                    window.location.href = `${this.basePath}/admin/workflows/${data.data.id}/designer`;
                }
            } else {
                alert('❌ Erreur: ' + (data.error || 'Erreur inconnue'));
            }
        } catch (error) {
            console.error('Erreur sauvegarde:', error);
            alert('❌ Erreur lors de la sauvegarde');
        }
    }
    
    escapeHtml(text) {
        if (text === null || text === undefined) return '';
        const div = document.createElement('div');
        div.textContent = String(text);
        return div.innerHTML;
    }
}

window.WorkflowDesigner = WorkflowDesigner;
