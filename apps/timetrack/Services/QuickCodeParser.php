<?php
/**
 * K-Time - Quick Code Parser
 * Parse la saisie rapide : "2.5hA1 pAA2 description"
 */

namespace KDocs\Apps\Timetrack\Services;

use KDocs\Apps\Timetrack\Models\Project;
use KDocs\Apps\Timetrack\Models\Supply;

class QuickCodeParser
{
    // Pattern: 2.5h ou 2,5h ou 2h30 avec code projet optionnel
    private const DURATION_PATTERN = '/(\d+(?:[.,]\d+)?)\s*h\s*(\d+)?\s*([A-Z][A-Z0-9]*)?/i';

    // Pattern: pAA2 = 2 unites du produit AA
    private const SUPPLY_PATTERN = '/p([A-Z]{2})(\d+(?:[.,]\d+)?)?/i';

    /**
     * Parse une saisie rapide
     *
     * @param string $input Ex: "2.5hA1 pAA2 travaux peinture"
     * @return array ['entries' => [...], 'supplies' => [...], 'description' => '...']
     */
    public function parse(string $input): array
    {
        $result = [
            'entries' => [],
            'supplies' => [],
            'description' => '',
            'raw' => $input,
        ];

        $remaining = $input;

        // 1. Extraire les durees avec projets
        if (preg_match_all(self::DURATION_PATTERN, $input, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $hours = floatval(str_replace(',', '.', $match[1]));

                // Si format 2h30 (minutes apres h)
                if (!empty($match[2])) {
                    $hours += intval($match[2]) / 60;
                }

                $projectCode = $match[3] ?? null;
                $project = null;
                $client = null;

                if ($projectCode) {
                    $project = Project::findByQuickCode($projectCode);
                    if ($project) {
                        $client = $project->client_id;
                    }
                }

                $result['entries'][] = [
                    'duration' => round($hours, 2),
                    'project_code' => $projectCode,
                    'project_id' => $project?->id,
                    'project_name' => $project?->name,
                    'client_id' => $client,
                    'client_name' => $project?->client_name,
                ];

                // Retirer du texte restant
                $remaining = str_replace($match[0], '', $remaining);
            }
        }

        // 2. Extraire les fournitures
        if (preg_match_all(self::SUPPLY_PATTERN, $input, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $supplyCode = strtoupper($match[1]);
                $quantity = isset($match[2]) ? floatval(str_replace(',', '.', $match[2])) : 1;

                $supply = Supply::findByQuickCode($supplyCode);

                $result['supplies'][] = [
                    'code' => $supplyCode,
                    'quantity' => $quantity,
                    'supply_id' => $supply?->id,
                    'supply_name' => $supply?->name,
                    'unit_price' => $supply?->sell_price,
                    'total_price' => $supply ? ($supply->sell_price * $quantity) : null,
                ];

                // Retirer du texte restant
                $remaining = str_replace($match[0], '', $remaining);
            }
        }

        // 3. Le reste est la description
        $result['description'] = trim(preg_replace('/\s+/', ' ', $remaining));

        return $result;
    }

    /**
     * Valide une saisie rapide
     */
    public function validate(string $input): array
    {
        $errors = [];
        $parsed = $this->parse($input);

        if (empty($parsed['entries'])) {
            $errors[] = 'Aucune duree detectee. Format: 2.5h ou 2h30';
        }

        foreach ($parsed['entries'] as $entry) {
            if ($entry['duration'] <= 0) {
                $errors[] = 'Duree invalide';
            }
            if ($entry['duration'] > 24) {
                $errors[] = 'Duree superieure a 24h';
            }
            if ($entry['project_code'] && !$entry['project_id']) {
                $errors[] = "Projet '{$entry['project_code']}' non trouve";
            }
        }

        foreach ($parsed['supplies'] as $supply) {
            if ($supply['code'] && !$supply['supply_id']) {
                $errors[] = "Fourniture '{$supply['code']}' non trouvee";
            }
        }

        return $errors;
    }

    /**
     * Genere un apercu formaté
     */
    public function preview(string $input): string
    {
        $parsed = $this->parse($input);
        $parts = [];

        foreach ($parsed['entries'] as $entry) {
            $line = $this->formatDuration($entry['duration']);
            if ($entry['project_name']) {
                $line .= ' ' . $entry['project_name'];
                if ($entry['client_name']) {
                    $line .= ' (' . $entry['client_name'] . ')';
                }
            } elseif ($entry['project_code']) {
                $line .= ' [' . $entry['project_code'] . '?]';
            }
            $parts[] = $line;
        }

        foreach ($parsed['supplies'] as $supply) {
            $line = $supply['quantity'] . 'x ';
            if ($supply['supply_name']) {
                $line .= $supply['supply_name'];
            } else {
                $line .= '[' . $supply['code'] . '?]';
            }
            $parts[] = $line;
        }

        if ($parsed['description']) {
            $parts[] = '"' . $parsed['description'] . '"';
        }

        return implode(' + ', $parts);
    }

    /**
     * Formate une duree en heures
     */
    private function formatDuration(float $hours): string
    {
        $h = floor($hours);
        $m = round(($hours - $h) * 60);

        if ($m > 0) {
            return sprintf('%dh%02d', $h, $m);
        }
        return $h . 'h';
    }
}
