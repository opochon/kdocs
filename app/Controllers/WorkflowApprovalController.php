<?php
/**
 * K-Docs - WorkflowApprovalController
 * Gère les approbations via liens email (style Alfresco)
 */

namespace KDocs\Controllers;

use KDocs\Core\Database;
use KDocs\Core\Config;
use KDocs\Workflow\ExecutionEngine;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class WorkflowApprovalController
{
    /**
     * Page d'approbation publique (accessible via token)
     */
    public function showApprovalPage(Request $request, Response $response, array $args): Response
    {
        $token = $args['token'] ?? '';
        $action = $request->getQueryParams()['action'] ?? null;
        
        $db = Database::getInstance();
        $config = Config::load();
        $basePath = Config::basePath();
        
        // Récupérer le token d'approbation
        $stmt = $db->prepare("
            SELECT wat.*, d.title, d.original_filename, d.amount, d.currency, d.doc_date,
                   c.name as correspondent_name, dt.label as document_type_label,
                   u.username as assigned_user_name, ug.name as assigned_group_name
            FROM workflow_approval_tokens wat
            LEFT JOIN documents d ON wat.document_id = d.id
            LEFT JOIN correspondents c ON d.correspondent_id = c.id
            LEFT JOIN document_types dt ON d.document_type_id = dt.id
            LEFT JOIN users u ON wat.assigned_user_id = u.id
            LEFT JOIN groups ug ON wat.assigned_group_id = ug.id
            WHERE wat.token = ?
        ");
        $stmt->execute([$token]);
        $approval = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$approval) {
            return $this->renderError($response, 'Token invalide', 'Ce lien d\'approbation n\'existe pas ou a été supprimé.');
        }
        
        // Vérifier si déjà traité
        if ($approval['responded_at']) {
            return $this->renderAlreadyProcessed($response, $approval);
        }
        
        // Vérifier l'expiration
        if (strtotime($approval['expires_at']) < time()) {
            return $this->renderExpired($response, $approval);
        }
        
        // Si une action est demandée (approve/reject), la traiter
        if ($action === 'approve' || $action === 'reject') {
            return $this->showConfirmationPage($response, $approval, $action);
        }
        
        // Afficher la page de détail du document
        return $this->renderApprovalPage($response, $approval, $basePath);
    }
    
    /**
     * Page de confirmation avant action
     */
    private function showConfirmationPage(Response $response, array $approval, string $action): Response
    {
        $actionLabel = $action === 'approve' ? 'Approuver' : 'Refuser';
        $actionColor = $action === 'approve' ? 'green' : 'red';
        $actionIcon = $action === 'approve' ? '✅' : '❌';
        
        $title = $approval['title'] ?? $approval['original_filename'] ?? 'Document';
        $amount = $approval['amount'] ? number_format((float)$approval['amount'], 2, '.', ' ') . ' ' . ($approval['currency'] ?? 'CHF') : 'N/A';
        
        $html = $this->getPageLayout("Confirmer: {$actionLabel}", <<<HTML
        <div class="max-w-lg mx-auto">
            <div class="bg-white rounded-xl shadow-lg overflow-hidden">
                <div class="bg-{$actionColor}-600 text-white px-6 py-4">
                    <h1 class="text-xl font-semibold">{$actionIcon} Confirmer: {$actionLabel}</h1>
                </div>
                
                <div class="p-6">
                    <div class="mb-6 p-4 bg-gray-50 rounded-lg">
                        <h2 class="font-medium text-gray-900 mb-2">{$title}</h2>
                        <p class="text-sm text-gray-600">Montant: <strong>{$amount}</strong></p>
                        <p class="text-sm text-gray-600">Correspondant: {$approval['correspondent_name']}</p>
                        <p class="text-sm text-gray-600">Type: {$approval['document_type_label']}</p>
                    </div>
                    
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="{$action}">
                        
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Commentaire (optionnel)</label>
                            <textarea name="comment" rows="3" 
                                      class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-{$actionColor}-500 focus:border-{$actionColor}-500"
                                      placeholder="Ajoutez un commentaire..."></textarea>
                        </div>
                        
                        <div class="flex gap-3">
                            <button type="submit" 
                                    class="flex-1 px-4 py-3 bg-{$actionColor}-600 text-white font-medium rounded-lg hover:bg-{$actionColor}-700 transition-colors">
                                {$actionIcon} Confirmer: {$actionLabel}
                            </button>
                            <a href="?" 
                               class="px-4 py-3 bg-gray-200 text-gray-700 font-medium rounded-lg hover:bg-gray-300 transition-colors">
                                Annuler
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
HTML
        );
        
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
    
    /**
     * Traitement de la décision d'approbation
     */
    public function processApproval(Request $request, Response $response, array $args): Response
    {
        $token = $args['token'] ?? '';
        $data = $request->getParsedBody();
        $action = $data['action'] ?? '';
        $comment = $data['comment'] ?? '';
        
        $db = Database::getInstance();
        
        // Récupérer le token
        $stmt = $db->prepare("SELECT * FROM workflow_approval_tokens WHERE token = ?");
        $stmt->execute([$token]);
        $approval = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$approval) {
            return $this->renderError($response, 'Token invalide', 'Ce lien d\'approbation n\'existe pas.');
        }
        
        if ($approval['responded_at']) {
            return $this->renderAlreadyProcessed($response, $approval);
        }
        
        if (strtotime($approval['expires_at']) < time()) {
            return $this->renderExpired($response, $approval);
        }
        
        if (!in_array($action, ['approve', 'reject'])) {
            return $this->renderError($response, 'Action invalide', 'L\'action demandée n\'est pas reconnue.');
        }
        
        // Enregistrer la décision
        $decision = $action === 'approve' ? 'approved' : 'rejected';
        
        try {
            $db->beginTransaction();
            
            // Mettre à jour le token
            $stmt = $db->prepare("
                UPDATE workflow_approval_tokens 
                SET response_action = ?, response_comment = ?, responded_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$decision, $comment, $approval['id']]);
            
            // Mettre à jour la tâche d'approbation
            $stmt = $db->prepare("
                UPDATE workflow_approval_tasks 
                SET status = 'completed', decision = ?, comment = ?, completed_at = NOW()
                WHERE execution_id = ? AND status IN ('pending', 'in_progress')
            ");
            $stmt->execute([$decision, $comment, $approval['execution_id']]);
            
            // Enregistrer dans l'historique des décisions
            $stmt = $db->prepare("
                INSERT INTO workflow_decision_history
                (execution_id, document_id, node_id, token_id, decision, comment)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $approval['execution_id'],
                $approval['document_id'],
                $approval['node_id'],
                $approval['id'],
                $decision,
                $comment
            ]);

            // Mettre à jour le statut de validation sur le document
            $validationStatus = ($decision === 'approved') ? 'approved' : 'rejected';
            $stmt = $db->prepare("
                UPDATE documents
                SET validation_status = ?,
                    validated_by = ?,
                    validated_at = NOW(),
                    validation_comment = ?,
                    requires_approval = FALSE
                WHERE id = ?
            ");
            $stmt->execute([
                $validationStatus,
                $approval['assigned_user_id'],
                $comment,
                $approval['document_id']
            ]);

            // Enregistrer dans l'historique de validation du document
            $stmt = $db->prepare("
                INSERT INTO document_validation_history
                (document_id, action, from_status, to_status, performed_by, comment)
                VALUES (?, ?, 'pending', ?, ?, ?)
            ");
            $stmt->execute([
                $approval['document_id'],
                $decision,
                $validationStatus,
                $approval['assigned_user_id'],
                $comment
            ]);
            
            // Reprendre le workflow avec la décision
            ExecutionEngine::resume($approval['execution_id'], $decision);
            
            $db->commit();
            
            return $this->renderSuccess($response, $decision, $approval);
            
        } catch (\Exception $e) {
            $db->rollBack();
            error_log("WorkflowApprovalController error: " . $e->getMessage());
            return $this->renderError($response, 'Erreur', 'Une erreur est survenue lors du traitement. Veuillez réessayer.');
        }
    }
    
    /**
     * Rendu de la page d'approbation principale
     */
    private function renderApprovalPage(Response $response, array $approval, string $basePath): Response
    {
        $title = $approval['title'] ?? $approval['original_filename'] ?? 'Document';
        $amount = $approval['amount'] ? number_format((float)$approval['amount'], 2, '.', ' ') . ' ' . ($approval['currency'] ?? 'CHF') : 'N/A';
        $date = $approval['doc_date'] ?? 'N/A';
        $correspondent = $approval['correspondent_name'] ?? 'N/A';
        $type = $approval['document_type_label'] ?? 'N/A';
        $message = htmlspecialchars($approval['message'] ?? '');
        $expiresAt = date('d/m/Y à H:i', strtotime($approval['expires_at']));
        $viewUrl = $basePath . '/documents/' . $approval['document_id'];
        
        $html = $this->getPageLayout("Demande d'approbation", <<<HTML
        <div class="max-w-2xl mx-auto">
            <div class="bg-white rounded-xl shadow-lg overflow-hidden">
                <div class="bg-gradient-to-r from-blue-600 to-blue-700 text-white px-6 py-5">
                    <div class="flex items-center gap-3">
                        <div class="w-12 h-12 bg-white/20 rounded-full flex items-center justify-center">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                            </svg>
                        </div>
                        <div>
                            <h1 class="text-xl font-semibold">Demande d'approbation</h1>
                            <p class="text-blue-100 text-sm">Action requise avant le {$expiresAt}</p>
                        </div>
                    </div>
                </div>
                
                <div class="p-6">
                    <!-- Document Info -->
                    <div class="mb-6 p-5 bg-gray-50 rounded-xl border border-gray-100">
                        <h2 class="text-lg font-semibold text-gray-900 mb-4">{$title}</h2>
                        
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <span class="text-xs text-gray-500 uppercase tracking-wide">Montant</span>
                                <p class="text-lg font-bold text-gray-900">{$amount}</p>
                            </div>
                            <div>
                                <span class="text-xs text-gray-500 uppercase tracking-wide">Date</span>
                                <p class="text-gray-900">{$date}</p>
                            </div>
                            <div>
                                <span class="text-xs text-gray-500 uppercase tracking-wide">Correspondant</span>
                                <p class="text-gray-900">{$correspondent}</p>
                            </div>
                            <div>
                                <span class="text-xs text-gray-500 uppercase tracking-wide">Type</span>
                                <p class="text-gray-900">{$type}</p>
                            </div>
                        </div>
                        
                        <div class="mt-4 pt-4 border-t border-gray-200">
                            <a href="{$viewUrl}" target="_blank" class="inline-flex items-center gap-2 text-blue-600 hover:text-blue-700 text-sm font-medium">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                </svg>
                                Voir le document complet
                            </a>
                        </div>
                    </div>
                    
                    <!-- Message -->
                    {$message ? '<div class="mb-6 p-4 bg-yellow-50 border-l-4 border-yellow-400 rounded-r-lg"><p class="text-sm text-yellow-800"><strong>Message:</strong> ' . $message . '</p></div>' : ''}
                    
                    <!-- Actions -->
                    <div class="flex gap-4">
                        <a href="?action=approve" 
                           class="flex-1 flex items-center justify-center gap-2 px-6 py-4 bg-green-600 text-white font-semibold rounded-xl hover:bg-green-700 transition-colors shadow-lg shadow-green-600/30">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                            Approuver
                        </a>
                        <a href="?action=reject" 
                           class="flex-1 flex items-center justify-center gap-2 px-6 py-4 bg-red-600 text-white font-semibold rounded-xl hover:bg-red-700 transition-colors shadow-lg shadow-red-600/30">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                            Refuser
                        </a>
                    </div>
                </div>
                
                <div class="px-6 py-4 bg-gray-50 border-t border-gray-100">
                    <p class="text-xs text-gray-500 text-center">
                        K-Docs - Système de gestion documentaire
                    </p>
                </div>
            </div>
        </div>
HTML
        );
        
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
    
    private function renderSuccess(Response $response, string $decision, array $approval): Response
    {
        $isApproved = $decision === 'approved';
        $icon = $isApproved ? '✅' : '❌';
        $title = $isApproved ? 'Document approuvé' : 'Document refusé';
        $color = $isApproved ? 'green' : 'red';
        $docTitle = $approval['title'] ?? $approval['original_filename'] ?? 'Document';
        
        $html = $this->getPageLayout($title, <<<HTML
        <div class="max-w-md mx-auto text-center">
            <div class="bg-white rounded-xl shadow-lg p-8">
                <div class="w-20 h-20 bg-{$color}-100 rounded-full flex items-center justify-center mx-auto mb-6">
                    <span class="text-4xl">{$icon}</span>
                </div>
                <h1 class="text-2xl font-bold text-gray-900 mb-2">{$title}</h1>
                <p class="text-gray-600 mb-6">Votre décision a été enregistrée pour le document<br><strong>"{$docTitle}"</strong></p>
                <p class="text-sm text-gray-500">Vous pouvez fermer cette page.</p>
            </div>
        </div>
HTML
        );
        
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
    
    private function renderAlreadyProcessed(Response $response, array $approval): Response
    {
        $decision = $approval['response_action'] === 'approved' ? 'approuvé' : 'refusé';
        $date = date('d/m/Y à H:i', strtotime($approval['responded_at']));
        
        $html = $this->getPageLayout('Déjà traité', <<<HTML
        <div class="max-w-md mx-auto text-center">
            <div class="bg-white rounded-xl shadow-lg p-8">
                <div class="w-20 h-20 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-6">
                    <span class="text-4xl">📋</span>
                </div>
                <h1 class="text-2xl font-bold text-gray-900 mb-2">Déjà traité</h1>
                <p class="text-gray-600 mb-4">Ce document a déjà été <strong>{$decision}</strong> le {$date}.</p>
                <p class="text-sm text-gray-500">Aucune action supplémentaire n'est requise.</p>
            </div>
        </div>
HTML
        );
        
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
    
    private function renderExpired(Response $response, array $approval): Response
    {
        $expiredAt = date('d/m/Y à H:i', strtotime($approval['expires_at']));
        
        $html = $this->getPageLayout('Lien expiré', <<<HTML
        <div class="max-w-md mx-auto text-center">
            <div class="bg-white rounded-xl shadow-lg p-8">
                <div class="w-20 h-20 bg-orange-100 rounded-full flex items-center justify-center mx-auto mb-6">
                    <span class="text-4xl">⏰</span>
                </div>
                <h1 class="text-2xl font-bold text-gray-900 mb-2">Lien expiré</h1>
                <p class="text-gray-600 mb-4">Ce lien d'approbation a expiré le {$expiredAt}.</p>
                <p class="text-sm text-gray-500">Veuillez contacter l'administrateur si vous avez besoin d'un nouveau lien.</p>
            </div>
        </div>
HTML
        );
        
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
    
    private function renderError(Response $response, string $title, string $message): Response
    {
        $html = $this->getPageLayout($title, <<<HTML
        <div class="max-w-md mx-auto text-center">
            <div class="bg-white rounded-xl shadow-lg p-8">
                <div class="w-20 h-20 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-6">
                    <span class="text-4xl">⚠️</span>
                </div>
                <h1 class="text-2xl font-bold text-gray-900 mb-2">{$title}</h1>
                <p class="text-gray-600">{$message}</p>
            </div>
        </div>
HTML
        );
        
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8')->withStatus(400);
    }
    
    private function getPageLayout(string $title, string $content): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title} - K-Docs</title>
    <link rel="stylesheet" href="/kdocs/public/css/tailwind.css">
</head>
<body class="bg-gradient-to-br from-gray-100 to-gray-200 min-h-screen py-12 px-4">
    {$content}
</body>
</html>
HTML;
    }
}
