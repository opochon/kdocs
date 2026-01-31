<?php
/**
 * K-Time - Timer Controller
 */

namespace KDocs\Apps\Timetrack\Controllers;

use KDocs\Core\Auth;
use KDocs\Apps\Timetrack\Models\Timer;

class TimerController
{
    private function getUser()
    {
        $sessionId = $_COOKIE['kdocs_session'] ?? '';
        return Auth::getUserFromSession($sessionId);
    }

    /**
     * Statut du timer actif
     */
    public function status($request, $response): \Psr\Http\Message\ResponseInterface
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json($response, ['success' => false, 'error' => 'Non authentifie'], 401);
        }

        $timer = Timer::findActive($user['id']);

        return $this->json($response, [
            'success' => true,
            'active' => $timer !== null,
            'timer' => $timer?->toArray(),
        ]);
    }

    /**
     * Demarrer un timer
     */
    public function start($request, $response): \Psr\Http\Message\ResponseInterface
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json($response, ['success' => false, 'error' => 'Non authentifie'], 401);
        }

        $data = json_decode($request->getBody()->getContents(), true) ?? [];

        $timer = Timer::start(
            $user['id'],
            $data['client_id'] ?? null,
            $data['project_id'] ?? null,
            $data['description'] ?? null
        );

        return $this->json($response, [
            'success' => true,
            'timer' => $timer->toArray(),
        ]);
    }

    /**
     * Mettre en pause
     */
    public function pause($request, $response): \Psr\Http\Message\ResponseInterface
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json($response, ['success' => false, 'error' => 'Non authentifie'], 401);
        }

        $timer = Timer::findActive($user['id']);
        if (!$timer) {
            return $this->json($response, ['success' => false, 'error' => 'Pas de timer actif'], 404);
        }

        $success = $timer->pause();
        $timer = Timer::findActive($user['id']); // Refresh

        return $this->json($response, [
            'success' => $success,
            'timer' => $timer?->toArray(),
        ]);
    }

    /**
     * Reprendre
     */
    public function resume($request, $response): \Psr\Http\Message\ResponseInterface
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json($response, ['success' => false, 'error' => 'Non authentifie'], 401);
        }

        $timer = Timer::findActive($user['id']);
        if (!$timer) {
            return $this->json($response, ['success' => false, 'error' => 'Pas de timer actif'], 404);
        }

        $success = $timer->resume();
        $timer = Timer::findActive($user['id']); // Refresh

        return $this->json($response, [
            'success' => $success,
            'timer' => $timer?->toArray(),
        ]);
    }

    /**
     * Arreter et creer l'entree
     */
    public function stop($request, $response): \Psr\Http\Message\ResponseInterface
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json($response, ['success' => false, 'error' => 'Non authentifie'], 401);
        }

        $timer = Timer::findActive($user['id']);
        if (!$timer) {
            return $this->json($response, ['success' => false, 'error' => 'Pas de timer actif'], 404);
        }

        $entry = $timer->stop();

        return $this->json($response, [
            'success' => true,
            'entry' => $entry ? [
                'id' => $entry->id,
                'duration' => $entry->duration,
                'amount' => $entry->amount,
            ] : null,
            'message' => $entry ? 'Entree creee' : 'Timer annule (duree < 5min)',
        ]);
    }

    /**
     * Annuler le timer sans creer d'entree
     */
    public function cancel($request, $response): \Psr\Http\Message\ResponseInterface
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json($response, ['success' => false, 'error' => 'Non authentifie'], 401);
        }

        $timer = Timer::findActive($user['id']);
        if (!$timer) {
            return $this->json($response, ['success' => false, 'error' => 'Pas de timer actif'], 404);
        }

        $timer->delete();
        return $this->json($response, ['success' => true, 'message' => 'Timer annule']);
    }

    private function json($response, array $data, int $code = 200): \Psr\Http\Message\ResponseInterface
    {
        $response->getBody()->write(json_encode($data));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($code);
    }
}
