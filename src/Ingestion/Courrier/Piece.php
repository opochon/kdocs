<?php

declare(strict_types=1);

namespace Ged\Ingestion\Courrier;

/** Pièce jointe d'un courriel : ce que le moteur doit déposer (nom, type, disposition, contenu). */
final class Piece
{
    public function __construct(
        public readonly string $nom,
        public readonly string $type,
        public readonly string $disposition,
        public readonly string $contenu,
    ) {
    }

    public function estInline(): bool
    {
        return strtolower($this->disposition) === 'inline';
    }

    /** @param array<string, mixed> $donnees */
    public static function depuisTableau(array $donnees): self
    {
        return new self(
            (string) ($donnees['nom'] ?? ''),
            (string) ($donnees['type'] ?? 'application/octet-stream'),
            (string) ($donnees['disposition'] ?? 'attachment'),
            (string) ($donnees['contenu'] ?? ''),
        );
    }

    /** @return array<string, mixed> */
    public function pourTableau(): array
    {
        return ['nom' => $this->nom, 'type' => $this->type, 'disposition' => $this->disposition, 'contenu' => $this->contenu];
    }
}
