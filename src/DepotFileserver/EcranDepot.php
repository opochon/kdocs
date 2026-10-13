<?php

declare(strict_types=1);

namespace Ged\DepotFileserver;

use Ged\Config;

/**
 * Données de l'écran « Dépôt fichier » : état du manifeste d'empreintes, écarts détectés hors GED
 * (journal), garantie du dépôt séparé. Aucune donnée n'est inventée : tout est lu dans le dossier.
 */
final class EcranDepot
{
    private const LIBELLES = [
        'nouveau' => 'Nouveau fichier',
        'modifie' => 'Modifié hors GED',
        'deplace' => 'Déplacé hors GED',
        'supprime' => 'Supprimé hors GED',
        'restaure' => 'Réapparu',
    ];

    public function __construct(private readonly Config $config)
    {
    }

    /**
     * @param array<string, mixed> $query paramètres d'URL (message d'action, erreur nommée)
     * @return array<string, mixed>
     */
    public function donnees(array $query): array
    {
        $racine = $this->config->chemin('STORAGE_ROOT', 'storage/documents');
        $garantie = $this->config->chemin('DEPOT_SEPARE', 'var/depot-separe');
        $veille = Veille::depuisConfig($this->config);

        $fichiers = (new Manifeste($racine))->lire()['fichiers'];
        $presents = 0;
        foreach ($fichiers as $entree) {
            if (($entree['etat'] ?? 'present') === 'present') {
                ++$presents;
            }
        }

        $journal = [];
        foreach ($veille->journal(50) as $evenement) {
            $type = (string) ($evenement['type'] ?? '');
            $journal[] = [
                'type' => $type,
                'libelle' => self::LIBELLES[$type] ?? $type,
                'chemin' => (string) ($evenement['chemin'] ?? ''),
                'ancien_chemin' => (string) ($evenement['ancien_chemin'] ?? ''),
                'instant' => (string) ($evenement['instant'] ?? ''),
                'empreinte' => substr((string) ($evenement['empreinte'] ?? ''), 0, 8),
            ];
        }

        return [
            'ecran' => 'depot-fileserver',
            'racine' => $racine,
            'garantie' => $garantie,
            'resume' => [
                'fichiers' => count($fichiers),
                'presents' => $presents,
                'absents' => count($fichiers) - $presents,
                'versions_garanties' => (new DepotSepare($garantie))->compter(),
                'instance' => (string) ($veille->dernierReleve()['instant'] ?? ''),
            ],
            'journal' => $journal,
            'message' => $this->message($query),
            'erreur' => isset($query['erreur']) ? (string) $query['erreur'] : null,
        ];
    }

    /** @param array<string, mixed> $query */
    private function message(array $query): ?string
    {
        $fait = isset($query['fait']) ? (string) $query['fait'] : '';
        $entier = static fn (string $cle): int => isset($query[$cle]) && is_numeric($query[$cle]) ? (int) $query[$cle] : 0;

        return match ($fait) {
            'scan' => sprintf(
                'Scan terminé : %d fichier(s), %d nouveau(x), %d modifié(s) hors GED, %d déplacé(s), %d supprimé(s).',
                $entier('fichiers'), $entier('nouveaux'), $entier('modifies'), $entier('deplaces'), $entier('supprimes'),
            ),
            'verification' => $entier('manquants') === 0
                ? sprintf('Garantie vérifiée : %d contenu(s), aucun manquant.', $entier('verifies'))
                : sprintf('Garantie incomplète : %d contenu(s) manquant(s) sur %d.', $entier('manquants'), $entier('verifies')),
            'export' => sprintf('Arborescence classique exportée : %d fichier(s).', $entier('exportes')),
            default => null,
        };
    }
}
