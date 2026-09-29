<?php

declare(strict_types=1);

namespace Ged\Workflows;

use Ged\Config;
use PDO;

/**
 * Lecture du fonds documentaire par les `.meta.json` posés à côté des fichiers : le dossier fait foi,
 * aucune dépendance à l'index pour le contenu.
 *
 * L'identifiant d'index vient du `.meta.json` quand il y figure. Le Socle ne l'y écrit pas encore :
 * en attendant, on le résout par le `chemin_relatif` dans la table `documents` (lecture seule), sans
 * jamais déduire le contenu de la base. Le fossé entre essai à blanc et réalité reste nul : le moteur
 * voit exactement ce que le dossier porte.
 */
final class Fonds
{
    public function __construct(
        private readonly string $racine,
        private readonly ?PDO $pdo = null,
    ) {
    }

    public static function depuis(Config $config, ?PDO $pdo = null): self
    {
        return new self($config->chemin('STORAGE_ROOT', 'tests/corpus/apercu/documents'), $pdo);
    }

    public function racine(): string
    {
        return $this->racine;
    }

    /** @return list<array<string, mixed>> toutes les fiches, triées par identifiant */
    public function toutes(): array
    {
        $fiches = [];
        foreach ($this->fichiersMeta() as $metaFichier) {
            $fiche = $this->lire($metaFichier);
            if ($fiche !== null) {
                $fiches[$fiche['id']] = $fiche;
            }
        }
        ksort($fiches);

        return array_values($fiches);
    }

    /** @return array<string, mixed>|null */
    public function parId(int $id): ?array
    {
        foreach ($this->toutes() as $fiche) {
            if ($fiche['id'] === $id) {
                return $fiche;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function fichiersMeta(): array
    {
        if (!is_dir($this->racine)) {
            return [];
        }
        $fichiers = [];
        $iterateur = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($this->racine, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME),
                static fn (string $chemin): bool => !str_starts_with(basename($chemin), '.')
            )
        );
        foreach ($iterateur as $chemin) {
            if (is_string($chemin) && str_ends_with($chemin, '.meta.json')) {
                $fichiers[] = $chemin;
            }
        }
        sort($fichiers);

        return $fichiers;
    }

    /** @return array<string, mixed>|null */
    private function lire(string $metaFichier): ?array
    {
        $donnees = json_decode((string) file_get_contents($metaFichier), true);
        if (!is_array($donnees)) {
            return null;
        }
        $fichier = (string) ($donnees['fichier'] ?? substr(basename($metaFichier), 0, -strlen('.meta.json')));
        // Un `.meta.json` ne peut jamais désigner un fichier hors de la racine documentaire.
        if ($fichier === '' || str_contains($fichier, '..') || str_contains($fichier, '/') || str_contains($fichier, '\\')) {
            return null;
        }
        $chemin = dirname($metaFichier) . DIRECTORY_SEPARATOR . $fichier;
        if (!is_file($chemin)) {
            return null;
        }

        $id = isset($donnees['id']) && is_numeric($donnees['id']) ? (int) $donnees['id'] : $this->idDepuisIndex($chemin);
        if ($id === null || $id < 1) {
            return null;   // pièce hors index : elle n'a pas encore d'entrée à proposer
        }
        $montant = $donnees['montant'] ?? null;

        return [
            'id' => $id,
            'titre' => (string) ($donnees['titre'] ?? $fichier),
            'type' => isset($donnees['type']) && $donnees['type'] !== '' ? (string) $donnees['type'] : null,
            'emetteur' => isset($donnees['emetteur']) && $donnees['emetteur'] !== '' ? (string) $donnees['emetteur'] : null,
            'date' => (string) ($donnees['date'] ?? $donnees['date_document'] ?? '') ?: null,
            'montant' => is_numeric($montant) ? (float) $montant : null,
            'etiquettes' => array_values(array_map('strval', (array) ($donnees['etiquettes'] ?? []))),
            'texte' => (string) ($donnees['texte'] ?? ''),
            'echeance' => (string) ($donnees['echeance'] ?? $donnees['date_echeance'] ?? '') ?: null,
            'meta' => $donnees,
            'chemin' => $chemin,
        ];
    }

    /** Résout l'identifiant d'index par le chemin du fichier (lecture seule, jamais une écriture). */
    private function idDepuisIndex(string $cheminAbsolu): ?int
    {
        if ($this->pdo === null) {
            return null;
        }
        $relatif = str_replace('\\', '/', substr($cheminAbsolu, strlen(rtrim($this->racine, '/\\')) + 1));
        try {
            $requete = $this->pdo->prepare('SELECT id FROM documents WHERE chemin_relatif = ? LIMIT 1');
            $requete->execute([$relatif]);
            $id = $requete->fetchColumn();
        } catch (\PDOException) {
            return null;
        }

        return $id === false ? null : (int) $id;
    }
}
