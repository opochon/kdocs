<?php
/**
 * K-Time - Entry Controller
 */

namespace KDocs\Apps\Timetrack\Controllers;

use KDocs\Core\Auth;
use KDocs\Apps\Timetrack\Models\Entry;
use KDocs\Apps\Timetrack\Models\Project;
use KDocs\Apps\Timetrack\Models\Client;
use KDocs\Apps\Timetrack\Services\QuickCodeParser;

class EntryController
{
    private function getUser()
    {
        $sessionId = $_COOKIE['kdocs_session'] ?? '';
        return Auth::getUserFromSession($sessionId);
    }

    /**
     * Liste des entrees (API JSON)
     */
    public function index($request, $response): \Psr\Http\Message\ResponseInterface
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json($response, ['success' => false, 'error' => 'Non authentifie'], 401);
        }

        $params = $request->getQueryParams();
        $date = $params['date'] ?? date('Y-m-d');
        $entries = Entry::byDate($user['id'], $date);

        return $this->json($response, [
            'success' => true,
            'data' => array_map(fn($e) => $this->formatEntry($e), $entries),
        ]);
    }

    /**
     * Creer une entree standard
     */
    public function store($request, $response): \Psr\Http\Message\ResponseInterface
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json($response, ['success' => false, 'error' => 'Non authentifie'], 401);
        }

        $data = json_decode($request->getBody()->getContents(), true) ?? [];

        // Validation
        if (empty($data['duration']) || $data['duration'] <= 0) {
            return $this->json($response, ['success' => false, 'error' => 'Duree requise'], 400);
        }

        // Recuperer le taux
        $rate = $data['rate'] ?? 150.00;
        if (!empty($data['project_id'])) {
            $project = Project::find($data['project_id']);
            if ($project) {
                $rate = $project->getRate();
                $data['client_id'] = $data['client_id'] ?? $project->client_id;
            }
        } elseif (!empty($data['client_id'])) {
            $client = Client::find($data['client_id']);
            if ($client) {
                $rate = $client->default_rate;
            }
        }

        $entry = Entry::create([
            'user_id' => $user['id'],
            'client_id' => $data['client_id'] ?? null,
            'project_id' => $data['project_id'] ?? null,
            'entry_date' => $data['entry_date'] ?? date('Y-m-d'),
            'duration' => $data['duration'],
            'description' => $data['description'] ?? null,
            'rate' => $rate,
            'billable' => $data['billable'] ?? true,
        ]);

        if ($entry) {
            return $this->json($response, [
                'success' => true,
                'data' => $this->formatEntry($entry),
            ]);
        } else {
            return $this->json($response, ['success' => false, 'error' => 'Erreur creation'], 500);
        }
    }

    /**
     * Saisie rapide via Quick Codes
     */
    public function quickCreate($request, $response): \Psr\Http\Message\ResponseInterface
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json($response, ['success' => false, 'error' => 'Non authentifie'], 401);
        }

        $data = json_decode($request->getBody()->getContents(), true) ?? [];
        $input = $data['input'] ?? '';

        if (empty($input)) {
            return $this->json($response, ['success' => false, 'error' => 'Saisie vide'], 400);
        }

        $parser = new QuickCodeParser();

        // Validation
        $errors = $parser->validate($input);
        if (!empty($errors)) {
            return $this->json($response, [
                'success' => false,
                'errors' => $errors,
                'preview' => $parser->preview($input),
            ], 400);
        }

        $parsed = $parser->parse($input);
        $createdEntries = [];

        foreach ($parsed['entries'] as $entryData) {
            // Recuperer le taux
            $rate = 150.00;
            if ($entryData['project_id']) {
                $project = Project::find($entryData['project_id']);
                if ($project) {
                    $rate = $project->getRate();
                }
            }

            $entry = Entry::create([
                'user_id' => $user['id'],
                'client_id' => $entryData['client_id'],
                'project_id' => $entryData['project_id'],
                'entry_date' => $data['date'] ?? date('Y-m-d'),
                'duration' => $entryData['duration'],
                'description' => $parsed['description'],
                'quick_input' => $input,
                'rate' => $rate,
                'billable' => true,
            ]);

            if ($entry) {
                $createdEntries[] = $this->formatEntry($entry);
            }
        }

        return $this->json($response, [
            'success' => true,
            'data' => $createdEntries,
            'parsed' => $parsed,
        ]);
    }

    /**
     * Parse preview (sans creer)
     */
    public function parsePreview($request, $response): \Psr\Http\Message\ResponseInterface
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json($response, ['success' => false, 'error' => 'Non authentifie'], 401);
        }

        $params = $request->getQueryParams();
        $input = $params['input'] ?? '';
        $parser = new QuickCodeParser();

        return $this->json($response, [
            'success' => true,
            'parsed' => $parser->parse($input),
            'preview' => $parser->preview($input),
            'errors' => $parser->validate($input),
        ]);
    }

    /**
     * Modifier une entree
     */
    public function update($request, $response, array $args): \Psr\Http\Message\ResponseInterface
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json($response, ['success' => false, 'error' => 'Non authentifie'], 401);
        }

        $id = (int) $args['id'];
        $entry = Entry::find($id);
        if (!$entry || $entry->user_id !== $user['id']) {
            return $this->json($response, ['success' => false, 'error' => 'Non trouve'], 404);
        }

        $data = json_decode($request->getBody()->getContents(), true) ?? [];
        $success = $entry->update($data);

        return $this->json($response, [
            'success' => $success,
            'data' => $this->formatEntry(Entry::find($id)),
        ]);
    }

    /**
     * Supprimer une entree
     */
    public function delete($request, $response, array $args): \Psr\Http\Message\ResponseInterface
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json($response, ['success' => false, 'error' => 'Non authentifie'], 401);
        }

        $id = (int) $args['id'];
        $entry = Entry::find($id);
        if (!$entry || $entry->user_id !== $user['id']) {
            return $this->json($response, ['success' => false, 'error' => 'Non trouve'], 404);
        }

        $success = $entry->delete();
        return $this->json($response, ['success' => $success]);
    }

    private function formatEntry(Entry $entry): array
    {
        return [
            'id' => $entry->id,
            'entry_date' => $entry->entry_date,
            'duration' => $entry->duration,
            'duration_formatted' => $this->formatDuration($entry->duration),
            'client_id' => $entry->client_id,
            'client_name' => $entry->client_name,
            'project_id' => $entry->project_id,
            'project_name' => $entry->project_name,
            'project_quick_code' => $entry->project_quick_code,
            'description' => $entry->description,
            'rate' => $entry->rate,
            'amount' => $entry->amount,
            'billable' => $entry->billable,
            'billed' => $entry->billed,
        ];
    }

    private function formatDuration(?float $hours): string
    {
        if ($hours === null) return '-';
        $h = floor($hours);
        $m = round(($hours - $h) * 60);
        return sprintf('%d:%02d', $h, $m);
    }

    private function json($response, array $data, int $code = 200): \Psr\Http\Message\ResponseInterface
    {
        $response->getBody()->write(json_encode($data));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($code);
    }
}
