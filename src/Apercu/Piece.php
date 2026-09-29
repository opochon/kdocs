<?php

declare(strict_types=1);

namespace Ged\Apercu;

/**
 * Une pièce documentaire telle qu'elle existe sur le disque : le fichier fait foi, ses métadonnées
 * sont lues dans son `.meta.json` voisin (convention du secteur Socle).
 */
final class Piece
{
    public function __construct(
        public readonly int $id,
        public readonly string $chemin,
        public readonly string $titre,
        public readonly string $categorie,
        public readonly string $extension,
        public readonly int $pages,
    ) {
    }

    public function existe(): bool
    {
        return is_file($this->chemin);
    }

    public function nom(): string
    {
        return basename($this->chemin);
    }

    public function estPdf(): bool
    {
        return $this->categorie === 'pdf';
    }

    public function estImage(): bool
    {
        return $this->categorie === 'image';
    }

    public function estBureautique(): bool
    {
        return $this->categorie === 'bureautique';
    }

    /** Un scan TIFF n'est pas affichable tel quel par un navigateur : il passe par une conversion PNG. */
    public function estTiff(): bool
    {
        return in_array($this->extension, ['tif', 'tiff'], true);
    }
}
