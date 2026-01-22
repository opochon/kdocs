/**
 * Script de test de navigation pour tester toutes les pages
 * À exécuter dans la console du navigateur après connexion
 */

(function() {
    'use strict';
    
    const basePath = '/kdocs';
    const logEndpoint = 'http://127.0.0.1:7242/ingest/998051e9-d527-4a28-a1df-0c90d62f9f01';
    
    function log(data) {
        fetch(logEndpoint, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                timestamp: Date.now(),
                location: data.location || 'test_navigation.js',
                message: data.message || '',
                data: data.data || {},
                sessionId: 'debug-session',
                runId: 'run1',
                hypothesisId: data.hypothesisId || 'A'
            })
        }).catch(() => {});
    }
    
    const routes = [
        {path: '/', name: 'Dashboard'},
        {path: '/dashboard', name: 'Dashboard'},
        {path: '/documents', name: 'Documents'},
        {path: '/documents/upload', name: 'Upload'},
        {path: '/chat', name: 'Chat IA'},
        {path: '/tasks', name: 'Tasks'},
        {path: '/admin', name: 'Admin'},
        {path: '/admin/users', name: 'Users'},
        {path: '/admin/users/create', name: 'Create User'},
        {path: '/admin/settings', name: 'Settings'},
        {path: '/admin/correspondents', name: 'Correspondents'},
        {path: '/admin/correspondents/create', name: 'Create Correspondent'},
        {path: '/admin/tags', name: 'Tags'},
        {path: '/admin/tags/create', name: 'Create Tag'},
        {path: '/admin/document-types', name: 'Document Types'},
        {path: '/admin/document-types/create', name: 'Create Document Type'},
        {path: '/admin/custom-fields', name: 'Custom Fields'},
        {path: '/admin/custom-fields/create', name: 'Create Custom Field'},
        {path: '/admin/storage-paths', name: 'Storage Paths'},
        {path: '/admin/storage-paths/create', name: 'Create Storage Path'},
        {path: '/admin/workflows', name: 'Workflows'},
        {path: '/admin/workflows/new/designer', name: 'Workflow Designer'},
        {path: '/admin/webhooks', name: 'Webhooks'},
        {path: '/admin/webhooks/create', name: 'Create Webhook'},
        {path: '/admin/audit-logs', name: 'Audit Logs'},
        {path: '/admin/export-import', name: 'Export/Import'},
        {path: '/admin/mail-accounts', name: 'Mail Accounts'},
        {path: '/admin/mail-accounts/create', name: 'Create Mail Account'},
        {path: '/admin/scheduled-tasks', name: 'Scheduled Tasks'},
    ];
    
    let currentIndex = 0;
    const results = [];
    
    function testNextRoute() {
        if (currentIndex >= routes.length) {
            console.log('=== Test terminé ===');
            console.table(results);
            log({
                location: 'test_navigation.js::testNextRoute',
                message: 'All tests completed',
                data: {results: results},
                hypothesisId: 'A'
            });
            return;
        }
        
        const route = routes[currentIndex];
        const url = basePath + route.path;
        
        log({
            location: 'test_navigation.js::testNextRoute',
            message: 'Testing route',
            data: {path: route.path, name: route.name},
            hypothesisId: 'A'
        });
        
        fetch(url, {method: 'GET', credentials: 'include'})
            .then(response => {
                const result = {
                    path: route.path,
                    name: route.name,
                    status: response.status,
                    ok: response.ok,
                    error: null
                };
                
                if (!response.ok) {
                    result.error = `HTTP ${response.status}`;
                    log({
                        location: 'test_navigation.js::testNextRoute',
                        message: 'Route failed',
                        data: result,
                        hypothesisId: 'A'
                    });
                }
                
                results.push(result);
                console.log(`${response.ok ? '✓' : '✗'} [${response.status}] ${route.path} - ${route.name}`);
                
                currentIndex++;
                setTimeout(testNextRoute, 500); // 500ms delay
            })
            .catch(error => {
                const result = {
                    path: route.path,
                    name: route.name,
                    status: 0,
                    ok: false,
                    error: error.message
                };
                results.push(result);
                log({
                    location: 'test_navigation.js::testNextRoute',
                    message: 'Route error',
                    data: result,
                    hypothesisId: 'A'
                });
                console.error(`✗ [ERROR] ${route.path} - ${route.name}: ${error.message}`);
                
                currentIndex++;
                setTimeout(testNextRoute, 500);
            });
    }
    
    console.log('=== Démarrage du test de navigation ===');
    console.log(`Base path: ${basePath}`);
    console.log(`Total routes: ${routes.length}`);
    console.log('Démarrage dans 2 secondes...\n');
    
    setTimeout(() => {
        testNextRoute();
    }, 2000);
})();
