<?php

declare(strict_types=1);

namespace KDocs\Apps\Erpconnect\Controllers;

use KDocs\Apps\Erpconnect\Services\ErpConnectService;
use KDocs\Apps\Erpconnect\Services\KTimeUnavailableException;
use KDocs\Core\Database;

/**
 * Contrôleur plugin K-ERP Connect.
 *
 * makeService() est protected pour permettre l'injection dans les tests
 * via une sous-classe anonyme (pattern ContractsController).
 */
class ErpConnectController
{
    /**
     * Fabrique du service — surchargeable dans les tests.
     */
    protected function makeService(): ErpConnectService
    {
        return new ErpConnectService(Database::getInstance());
    }

    /**
     * GET /erpconnect/proposal/{documentId}
     *
     * Retourne la proposition de ventilation K-Time (JSON, appelé en AJAX par le panneau).
     *
     * @param array<string,mixed> $args
     */
    public function proposal(object $request, object $response, array $args = []): object
    {
        $documentId = (int) ($args['documentId'] ?? 0);
        if ($documentId <= 0) {
            $response->getBody()->write(json_encode(['error' => 'documentId invalide']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        try {
            $proposal = $this->makeService()->buildProposal($documentId);
        } catch (\Throwable $e) {
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }

        $response->getBody()->write(json_encode($proposal, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * POST /erpconnect/submit/{documentId}
     *
     * Introduit la facture dans K-Time avec les choix utilisateur par ligne.
     * Corps JSON : {supplier_id?, total_ht?, currency?, lines: {lineId: {action}}}
     *
     * @param array<string,mixed> $args
     */
    public function submit(object $request, object $response, array $args = []): object
    {
        $documentId = (int) ($args['documentId'] ?? 0);
        if ($documentId <= 0) {
            $response->getBody()->write(json_encode(['error' => 'documentId invalide']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $body = $request->getParsedBody() ?? [];

        try {
            $result = $this->makeService()->submitToKTime($documentId, $body);
        } catch (KTimeUnavailableException $e) {
            $response->getBody()->write(json_encode([
                'error'           => 'K-Time indisponible',
                'ktime_available' => false,
                'detail'          => $e->getMessage(),
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(503);
        } catch (\Throwable $e) {
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }

        $response->getBody()->write(json_encode($result, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * POST /erpconnect/refresh/{documentId}
     *
     * Rafraîchit le statut de validation depuis K-Time (« bon pour accord »).
     *
     * @param array<string,mixed> $args
     */
    public function refresh(object $request, object $response, array $args = []): object
    {
        $documentId = (int) ($args['documentId'] ?? 0);
        if ($documentId <= 0) {
            $response->getBody()->write(json_encode(['error' => 'documentId invalide']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        try {
            $result = $this->makeService()->refreshStatus($documentId);
        } catch (KTimeUnavailableException) {
            $response->getBody()->write(json_encode([
                'error'           => 'K-Time indisponible',
                'ktime_available' => false,
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(503);
        } catch (\Throwable $e) {
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }

        $response->getBody()->write(json_encode($result, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * POST /erpconnect/api/block/{documentId}
     *
     * Demande un blocage AVEC cause dans K-Time.
     * Corps JSON : {kind: 'note_credit'|'correction_facture'|'blocage_paiement', cause: string}
     *
     * @param array<string,mixed> $args
     */
    public function block(object $request, object $response, array $args = []): object
    {
        $documentId = (int) ($args['documentId'] ?? 0);
        if ($documentId <= 0) {
            $response->getBody()->write(json_encode(['error' => 'documentId invalide']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $body  = $request->getParsedBody() ?? [];
        $kind  = (string) ($body['kind'] ?? '');
        $cause = (string) ($body['cause'] ?? '');

        try {
            $result = $this->makeService()->requestBlock($documentId, $kind, $cause);
        } catch (KTimeUnavailableException $e) {
            $response->getBody()->write(json_encode([
                'error'           => 'K-Time indisponible',
                'ktime_available' => false,
                'detail'          => $e->getMessage(),
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(503);
        } catch (\Throwable $e) {
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }

        $response->getBody()->write(json_encode($result, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * GET /erpconnect/panel/{documentId}
     *
     * Affiche le panneau HTML de proposition ERP.
     * Charge la proposition via AJAX (GET /erpconnect/proposal/{documentId}).
     *
     * @param array<string,mixed> $args
     */
    public function panel(object $request, object $response, array $args = []): object
    {
        $documentId = (int) ($args['documentId'] ?? 0);
        $appUrl     = rtrim((string) env('APP_URL', ''), '/');

        ob_start();
        include __DIR__ . '/../templates/panel.php';
        $html = (string) ob_get_clean();

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
