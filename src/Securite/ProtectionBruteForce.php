<?php

declare(strict_types=1);

namespace Ged\Securite;

/** Limitation locale des essais de connexion, sans révéler si un compte existe. */
final class ProtectionBruteForce
{
    public function __construct(private readonly string $fichier)
    {
    }

    public function attenteRestante(string $identifiant, ?int $maintenant = null): int
    {
        $maintenant ??= time();
        $etat = $this->lire();
        $cle = $this->cle($identifiant);

        return max(0, (int) (($etat[$cle]['bloque_jusqua'] ?? 0) - $maintenant));
    }

    public function echec(string $identifiant, ?int $maintenant = null): int
    {
        $maintenant ??= time();
        $etat = $this->lire();
        $cle = $this->cle($identifiant);
        $essais = (int) ($etat[$cle]['essais'] ?? 0) + 1;
        $attente = $essais < 5 ? 0 : 60 * (2 ** min(6, $essais - 5));
        $etat[$cle] = ['essais' => $essais, 'bloque_jusqua' => $maintenant + $attente];
        $this->ecrire($etat);

        return $attente;
    }

    public function succes(string $identifiant): void
    {
        $etat = $this->lire();
        unset($etat[$this->cle($identifiant)]);
        $this->ecrire($etat);
    }

    /** @return array<string, array{essais:int,bloque_jusqua:int}> */
    private function lire(): array
    {
        if (!is_file($this->fichier)) {
            return [];
        }
        try {
            /** @var array<string, array{essais:int,bloque_jusqua:int}> $etat */
            $etat = json_decode((string) file_get_contents($this->fichier), true, 512, JSON_THROW_ON_ERROR);
            return $etat;
        } catch (\JsonException) {
            return [];
        }
    }

    /** @param array<string, array{essais:int,bloque_jusqua:int}> $etat */
    private function ecrire(array $etat): void
    {
        $dossier = dirname($this->fichier);
        if (!is_dir($dossier)) {
            mkdir($dossier, 0700, true);
        }
        file_put_contents($this->fichier, json_encode($etat, JSON_THROW_ON_ERROR), LOCK_EX);
    }

    private function cle(string $identifiant): string
    {
        return hash('sha256', mb_strtolower(trim($identifiant)));
    }
}
