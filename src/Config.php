<?php

declare(strict_types=1);

namespace Ged;

/**
 * Configuration : fichier .env à la racine, surchargé par l'environnement du processus
 * (les tests et le harness imposent leurs valeurs ainsi : DB_NAME=gedv2_test).
 */
final class Config
{
    /** @var array<string, string> */
    private array $valeurs = [];

    public function __construct(private readonly string $racine)
    {
        $fichier = $racine . '/.env';
        if (is_file($fichier)) {
            foreach (file($fichier, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $ligne) {
                $ligne = trim($ligne);
                if ($ligne === '' || str_starts_with($ligne, '#') || !str_contains($ligne, '=')) {
                    continue;
                }
                [$cle, $valeur] = explode('=', $ligne, 2);
                $this->valeurs[trim($cle)] = trim(trim($valeur), "\"'");
            }
        }
    }

    public function get(string $cle, string $defaut = ''): string
    {
        $env = getenv($cle);
        if ($env !== false && $env !== '') {
            return $env;
        }

        return $this->valeurs[$cle] ?? $defaut;
    }

    public function racine(): string
    {
        return $this->racine;
    }

    /** Chemin absolu d'une valeur relative à la racine du projet (STORAGE_ROOT…). */
    public function chemin(string $cle, string $defaut): string
    {
        $v = $this->get($cle, $defaut);

        return preg_match('#^([A-Za-z]:[\\\\/]|/)#', $v) === 1 ? $v : $this->racine . '/' . $v;
    }
}
