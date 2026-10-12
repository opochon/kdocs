<?php

declare(strict_types=1);

namespace Ged\Securite;

use RuntimeException;

/**
 * Purge du journal d'audit par RÉTENTION (équivalent fonctionnel de la commande Paperless
 * `prune_audit_logs`) : le journal NDJSON ne grandit pas sans fin. Aucune donnée UTILISATEUR n'est
 * concernée (documents, versions, corbeille) — seule la trace technique d'audit est élaguée, et jamais une
 * ligne illisible : une entrée sans horodatage exploitable est CONSERVÉE, jamais détruite en silence.
 *
 * L'écriture est atomique (fichier temporaire puis renommage) : un lecteur ne voit jamais un journal
 * tronqué ; un échec de publication laisse le journal d'origine intact.
 */
final class PurgeAudit
{
    /**
     * @return array{avant:int, apres:int, supprimes:int}
     * @throws RuntimeException journal illisible ou publication impossible
     */
    public function purger(string $fichier, int $retentionJours, ?int $maintenant = null): array
    {
        if ($retentionJours < 1) {
            throw new RuntimeException('purge du journal : la rétention doit être d’au moins un jour');
        }
        if (!is_file($fichier)) {
            return ['avant' => 0, 'apres' => 0, 'supprimes' => 0];
        }
        $lignes = file($fichier, FILE_IGNORE_NEW_LINES);
        if ($lignes === false) {
            throw new RuntimeException('purge du journal : lecture impossible');
        }
        $limite = ($maintenant ?? time()) - $retentionJours * 86400;
        $gardees = [];
        $supprimes = 0;
        foreach ($lignes as $ligne) {
            if ($ligne === '') {
                continue;
            }
            if ($this->recente($ligne, $limite)) {
                $gardees[] = $ligne;
            } else {
                ++$supprimes;
            }
        }
        $this->publier($fichier, $gardees);

        return ['avant' => count($lignes), 'apres' => count($gardees), 'supprimes' => $supprimes];
    }

    /** Une entrée est récente si son horodatage est lisible et postérieur à la limite. Illisible : gardée. */
    private function recente(string $ligne, int $limite): bool
    {
        $entree = json_decode($ligne, true);
        if (!is_array($entree) || !is_string($entree['horodatage'] ?? null)) {
            return true;   // une ligne non exploitable est conservée : jamais de destruction silencieuse
        }
        $temps = strtotime($entree['horodatage']);

        return $temps === false || $temps >= $limite;
    }

    /** @param list<string> $lignes */
    private function publier(string $fichier, array $lignes): void
    {
        $contenu = $lignes === [] ? '' : implode(PHP_EOL, $lignes) . PHP_EOL;
        $temporaire = $fichier . '.purge-' . bin2hex(random_bytes(4));
        if (file_put_contents($temporaire, $contenu, LOCK_EX) === false) {
            throw new RuntimeException('purge du journal : écriture impossible');
        }
        if (!@rename($temporaire, $fichier)) {
            @unlink($temporaire);
            throw new RuntimeException('purge du journal : publication impossible');
        }
    }
}
