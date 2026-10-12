<?php

declare(strict_types=1);

namespace Ged\Workflows;

use Ged\Base;
use Ged\Config;
use PDO;

/**
 * Données de l'écran d'ADMINISTRATION des workflows (réservé aux détenteurs de `workflow.gerer`) :
 * liste, création, édition, activation, ordre et historique des exécutions. Le formulaire reste simple
 * (un déclencheur, une action) et s'appuie sur les mêmes classes que le moteur — aucun second modèle.
 */
final class Admin
{
    /** Filtres proposés par le formulaire : clé du champ Paperless gardé dans le déclencheur. */
    public const FILTRES = [
        'aucun' => 'Aucun filtre',
        'filter_filename' => 'Nom de fichier (joker *)',
        'filter_path' => 'Chemin (joker *)',
        'filter_has_correspondent' => 'Émetteur',
        'filter_has_document_type' => 'Type de document',
        'filter_has_storage_path' => 'Dossier de stockage',
        'filter_has_tags' => 'Étiquette',
    ];

    /** Champs qu'une action d'affectation ou de retrait peut viser. */
    public const CHAMPS = [
        'titre' => 'Titre',
        'etiquettes' => 'Étiquettes',
        'type' => 'Type de document',
        'emetteur' => 'Émetteur',
        'dossier' => 'Dossier de stockage',
        'proprietaire' => 'Propriétaire',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function depuisRacine(?string $racine = null): self
    {
        $config = new Config($racine ?? dirname(__DIR__, 2));

        return new self(Base::pdo($config));
    }

    /** @return array<string, mixed> */
    public function liste(): array
    {
        $installe = true;
        $workflows = [];
        try {
            foreach ((new DepotWorkflows($this->pdo))->toutes() as $workflow) {
                $workflows[] = $this->resume($workflow);
            }
        } catch (\PDOException) {
            $installe = false;
        }

        return [
            'installe' => $installe,
            'workflows' => $workflows,
            'declencheurs' => Declencheur::TYPES,
            'actions' => Action::TYPES,
            'filtres' => self::FILTRES,
            'champs' => self::CHAMPS,
        ];
    }

    /** @return array<string, mixed>|null null si le workflow n'existe pas */
    public function edition(int $id): ?array
    {
        $workflow = (new DepotWorkflows($this->pdo))->parId($id);
        if ($workflow === null) {
            return null;
        }

        $synthese = $this->resume($workflow);
        $synthese['executions'] = (new Executions($this->pdo))->pourWorkflow($id, 25);

        return [
            'workflow' => $workflow,
            'resume' => $synthese,
            'executions' => $synthese['executions'],
            'declencheurs' => Declencheur::TYPES,
            'actions' => Action::TYPES,
            'filtres' => self::FILTRES,
            'champs' => self::CHAMPS,
        ];
    }

    /**
     * @param array<string, mixed> $workflow
     * @return array<string, mixed>
     */
    private function resume(array $workflow): array
    {
        $declencheurs = [];
        foreach ((array) ($workflow['triggers'] ?? []) as $trigger) {
            if (!is_array($trigger)) {
                continue;
            }
            $type = (int) ($trigger['type'] ?? 0);
            $filtre = '';
            foreach (self::FILTRES as $cle => $libelle) {
                if ($cle !== 'aucun' && isset($trigger[$cle]) && $trigger[$cle] !== '' && $trigger[$cle] !== []) {
                    $filtre = $libelle . ' : ' . (is_array($trigger[$cle]) ? implode(', ', $trigger[$cle]) : (string) $trigger[$cle]);
                    break;
                }
            }
            $declencheurs[] = (Declencheur::TYPES[$type] ?? '?') . ($filtre !== '' ? ' — ' . $filtre : '');
        }

        $actions = [];
        foreach ((array) ($workflow['actions'] ?? []) as $action) {
            if (!is_array($action)) {
                continue;
            }
            $actions[] = Action::TYPES[(int) ($action['type'] ?? 0)] ?? '?';
        }

        $executions = (new Executions($this->pdo))->pourWorkflow((int) $workflow['id'], 1);

        return [
            'id' => (int) $workflow['id'],
            'nom' => (string) $workflow['nom'],
            'ordre' => (int) $workflow['ordre'],
            'actif' => (bool) $workflow['actif'],
            'declencheurs' => $declencheurs,
            'actions' => $actions,
            'derniere_execution' => $executions[0]['run_at'] ?? null,
        ];
    }
}
