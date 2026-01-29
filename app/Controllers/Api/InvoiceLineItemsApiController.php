<?php
/**
 * K-Docs - Invoice Line Items API Controller
 * API REST pour les lignes de facture
 */

namespace KDocs\Controllers\Api;

use KDocs\Models\InvoiceLineItem;
use KDocs\Services\Extraction\InvoiceLineItemExtractor;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class InvoiceLineItemsApiController extends ApiController
{
    /**
     * GET /api/documents/{id}/line-items
     * Liste les lignes de facture d'un document
     */
    public function index(Request $request, Response $response, array $args): Response
    {
        $documentId = (int)$args['id'];

        $lineItems = InvoiceLineItem::getForDocument($documentId);
        $totals = InvoiceLineItem::calculateTotals($documentId);

        return $this->successResponse($response, [
            'line_items' => $lineItems,
            'totals' => $totals
        ]);
    }

    /**
     * POST /api/documents/{id}/line-items/extract
     * Extrait les lignes de facture avec l'IA
     */
    public function extract(Request $request, Response $response, array $args): Response
    {
        $documentId = (int)$args['id'];
        $data = $request->getParsedBody();

        $extractor = new InvoiceLineItemExtractor();

        if (!$extractor->isAvailable()) {
            return $this->errorResponse($response, 'Service d\'extraction non disponible (Claude API non configurée)', 503);
        }

        // Forcer la réextraction si demandé
        $force = $data['force'] ?? false;

        if ($force) {
            $result = $extractor->reextract($documentId);
        } else {
            // Vérifier si des lignes existent déjà
            if ($extractor->hasLineItems($documentId)) {
                return $this->successResponse($response, [
                    'message' => 'Lignes déjà extraites',
                    'line_items' => InvoiceLineItem::getForDocument($documentId),
                    'totals' => InvoiceLineItem::calculateTotals($documentId)
                ]);
            }
            $result = $extractor->extract($documentId);
        }

        if (!$result['success']) {
            return $this->errorResponse($response, $result['error'] ?? 'Erreur d\'extraction', 500);
        }

        return $this->successResponse($response, [
            'message' => 'Extraction réussie',
            'line_count' => $result['line_count'],
            'line_items' => InvoiceLineItem::getForDocument($documentId),
            'totals' => InvoiceLineItem::calculateTotals($documentId),
            'invoice_info' => $result['invoice_info'] ?? null,
            'execution_time_ms' => $result['execution_time_ms']
        ]);
    }

    /**
     * GET /api/documents/{documentId}/line-items/{lineId}
     * Récupère une ligne spécifique
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $documentId = (int)$args['documentId'];
        $lineId = (int)$args['lineId'];

        $lineItem = InvoiceLineItem::find($lineId);

        if (!$lineItem || $lineItem['document_id'] != $documentId) {
            return $this->errorResponse($response, 'Ligne non trouvée', 404);
        }

        return $this->successResponse($response, $lineItem);
    }

    /**
     * POST /api/documents/{id}/line-items
     * Crée une nouvelle ligne manuellement
     */
    public function create(Request $request, Response $response, array $args): Response
    {
        $documentId = (int)$args['id'];
        $data = $request->getParsedBody();

        if (empty($data['description'])) {
            return $this->errorResponse($response, 'La description est requise');
        }

        // Trouver le prochain numéro de ligne
        $existingLines = InvoiceLineItem::getForDocument($documentId);
        $nextLineNumber = count($existingLines) + 1;

        try {
            $lineId = InvoiceLineItem::create([
                'document_id' => $documentId,
                'line_number' => $nextLineNumber,
                'quantity' => $data['quantity'] ?? null,
                'unit' => $data['unit'] ?? null,
                'code' => $data['code'] ?? null,
                'description' => $data['description'],
                'unit_price' => $data['unit_price'] ?? null,
                'discount_percent' => $data['discount_percent'] ?? null,
                'tax_rate' => $data['tax_rate'] ?? null,
                'tax_amount' => $data['tax_amount'] ?? null,
                'line_total' => $data['line_total'] ?? null,
                'compte_comptable' => $data['compte_comptable'] ?? null,
                'centre_cout' => $data['centre_cout'] ?? null,
                'projet' => $data['projet'] ?? null
            ]);

            $lineItem = InvoiceLineItem::find($lineId);
            return $this->successResponse($response, $lineItem, 'Ligne créée', 201);

        } catch (\Exception $e) {
            return $this->errorResponse($response, 'Erreur lors de la création: ' . $e->getMessage(), 500);
        }
    }

    /**
     * PUT /api/documents/{documentId}/line-items/{lineId}
     * Met à jour une ligne
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $documentId = (int)$args['documentId'];
        $lineId = (int)$args['lineId'];
        $data = $request->getParsedBody();

        $lineItem = InvoiceLineItem::find($lineId);

        if (!$lineItem || $lineItem['document_id'] != $documentId) {
            return $this->errorResponse($response, 'Ligne non trouvée', 404);
        }

        try {
            InvoiceLineItem::update($lineId, $data);
            $updatedLine = InvoiceLineItem::find($lineId);
            return $this->successResponse($response, $updatedLine, 'Ligne mise à jour');
        } catch (\Exception $e) {
            return $this->errorResponse($response, 'Erreur lors de la mise à jour: ' . $e->getMessage(), 500);
        }
    }

    /**
     * DELETE /api/documents/{documentId}/line-items/{lineId}
     * Supprime une ligne
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $documentId = (int)$args['documentId'];
        $lineId = (int)$args['lineId'];

        $lineItem = InvoiceLineItem::find($lineId);

        if (!$lineItem || $lineItem['document_id'] != $documentId) {
            return $this->errorResponse($response, 'Ligne non trouvée', 404);
        }

        try {
            InvoiceLineItem::delete($lineId);
            return $this->successResponse($response, ['id' => $lineId], 'Ligne supprimée');
        } catch (\Exception $e) {
            return $this->errorResponse($response, 'Erreur lors de la suppression: ' . $e->getMessage(), 500);
        }
    }

    /**
     * DELETE /api/documents/{id}/line-items
     * Supprime toutes les lignes d'un document
     */
    public function deleteAll(Request $request, Response $response, array $args): Response
    {
        $documentId = (int)$args['id'];

        try {
            InvoiceLineItem::deleteForDocument($documentId);
            return $this->successResponse($response, [], 'Toutes les lignes supprimées');
        } catch (\Exception $e) {
            return $this->errorResponse($response, 'Erreur lors de la suppression: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/documents/{id}/line-items/reorder
     * Réordonne les lignes
     */
    public function reorder(Request $request, Response $response, array $args): Response
    {
        $documentId = (int)$args['id'];
        $data = $request->getParsedBody();

        if (empty($data['line_ids']) || !is_array($data['line_ids'])) {
            return $this->errorResponse($response, 'line_ids (tableau) est requis');
        }

        try {
            InvoiceLineItem::reorder($documentId, $data['line_ids']);
            return $this->successResponse($response, [
                'line_items' => InvoiceLineItem::getForDocument($documentId)
            ], 'Lignes réordonnées');
        } catch (\Exception $e) {
            return $this->errorResponse($response, 'Erreur lors du réordonnancement: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/documents/{id}/line-items/extraction-history
     * Historique des extractions pour un document
     */
    public function extractionHistory(Request $request, Response $response, array $args): Response
    {
        $documentId = (int)$args['id'];

        $extractor = new InvoiceLineItemExtractor();
        $history = $extractor->getExtractionHistory($documentId);

        return $this->successResponse($response, $history);
    }
}
