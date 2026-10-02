<?php

declare(strict_types=1);

namespace Ged\Archivage;

/**
 * Vérification reprise : chaque appel ne traite que quelques justificatifs et moins d'une seconde.
 * Une navigation HTTP ne se transforme donc jamais en vérification longue du fonds entier.
 */
final class TacheVerification
{
    public function __construct(private readonly string $racineFonds)
    {
    }

    /** @return array<string,mixed> */
    public function demarrer(): array
    {
        $tache = [
            'id' => bin2hex(random_bytes(12)), 'etat' => 'en_cours', 'position' => 0,
            'fichiers' => Scellement::parcourir($this->racineFonds), 'ecarts' => [], 'scellements' => 0,
        ];
        $this->ecrire($tache);

        return $tache;
    }

    /** @return array<string,mixed> */
    public function reprendre(string $id, int $maximum = 5): array
    {
        $tache = $this->lire($id);
        if (($tache['etat'] ?? '') !== 'en_cours') {
            return $tache;
        }
        $debut = microtime(true);
        $verificateur = new Verificateur($this->racineFonds);
        $fichiers = is_array($tache['fichiers'] ?? null) ? $tache['fichiers'] : [];
        $position = (int) ($tache['position'] ?? 0);
        $traites = 0;
        while ($position < count($fichiers) && $traites < max(1, $maximum) && microtime(true) - $debut < 1.0) {
            $bilan = $verificateur->verifier((string) $fichiers[$position]);
            foreach ($bilan['ecarts'] as $ecart) {
                $tache['ecarts'][] = (string) $bilan['scellement'] . ' : ' . $ecart;
            }
            $position++;
            $traites++;
        }
        $tache['position'] = $position;
        $tache['scellements'] = count($fichiers);
        if ($position >= count($fichiers)) {
            $tache['etat'] = 'terminee';
            $tache['ok'] = $tache['ecarts'] === [];
        }
        $this->ecrire($tache);

        return $tache;
    }

    /** @return array<string,mixed> */
    private function lire(string $id): array
    {
        if (preg_match('/^[a-f0-9]{24}$/', $id) !== 1) {
            throw ErreurScellement::scellement('tâche de vérification inconnue');
        }
        $contenu = is_file($this->fichier($id)) ? file_get_contents($this->fichier($id)) : false;
        $tache = is_string($contenu) ? json_decode($contenu, true) : null;
        if (!is_array($tache)) {
            throw ErreurScellement::scellement('tâche de vérification introuvable');
        }

        return $tache;
    }

    /** @param array<string,mixed> $tache */
    private function ecrire(array $tache): void
    {
        $dossier = $this->racineFonds . '/.archivage/taches';
        if (!is_dir($dossier) && !mkdir($dossier, 0775, true) && !is_dir($dossier)) {
            throw ErreurScellement::scellement('impossible de créer le dossier des tâches de vérification');
        }
        if (file_put_contents($this->fichier((string) $tache['id']), json_encode($tache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) === false) {
            throw ErreurScellement::scellement('impossible d’enregistrer la tâche de vérification');
        }
    }

    private function fichier(string $id): string
    {
        return $this->racineFonds . '/.archivage/taches/' . $id . '.json';
    }
}
