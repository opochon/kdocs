<?php
/**
 * K-Time - Dashboard Controller
 */

namespace KDocs\Apps\Timetrack\Controllers;

use KDocs\Core\Auth;
use KDocs\Apps\Timetrack\Models\Entry;
use KDocs\Apps\Timetrack\Models\Timer;
use KDocs\Apps\Timetrack\Models\Client;
use KDocs\Apps\Timetrack\Models\Project;

class DashboardController
{
    public function index($request, $response): \Psr\Http\Message\ResponseInterface
    {
        // Get user from session
        $sessionId = $_COOKIE['kdocs_session'] ?? '';
        $user = Auth::getUserFromSession($sessionId);

        if (!$user) {
            return $response->withHeader('Location', '/kdocs/login')->withStatus(302);
        }

        $userId = $user['id'];

        // Date actuelle et semaine
        $today = date('Y-m-d');
        $weekStart = date('Y-m-d', strtotime('monday this week'));

        // Timer actif
        $activeTimer = Timer::findActive($userId);

        // Entrees du jour
        $todayEntries = Entry::byDate($userId, $today);
        $todayStats = Entry::sumByDate($userId, $today);

        // Stats semaine
        $weekStats = Entry::sumByWeek($userId, $weekStart);

        // Entrees de la semaine
        $weekEnd = date('Y-m-d', strtotime($weekStart . ' +6 days'));
        $weekEntries = Entry::byDateRange($userId, $weekStart, $weekEnd);

        // Grouper par jour
        $entriesByDay = [];
        foreach ($weekEntries as $entry) {
            $day = $entry->entry_date;
            if (!isset($entriesByDay[$day])) {
                $entriesByDay[$day] = [];
            }
            $entriesByDay[$day][] = $entry;
        }

        // Clients et projets pour le formulaire
        $clients = Client::all();
        $projects = Project::all();

        // Render
        ob_start();
        $this->render('dashboard', [
            'user' => $user,
            'today' => $today,
            'weekStart' => $weekStart,
            'activeTimer' => $activeTimer,
            'todayEntries' => $todayEntries,
            'todayStats' => $todayStats,
            'weekStats' => $weekStats,
            'entriesByDay' => $entriesByDay,
            'clients' => $clients,
            'projects' => $projects,
        ]);
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    private function render(string $template, array $data = []): void
    {
        extract($data);
        $templatePath = __DIR__ . '/../templates/' . $template . '.php';

        if (!file_exists($templatePath)) {
            echo "Template not found: $template";
            return;
        }

        include $templatePath;
    }
}
