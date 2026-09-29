<?php

declare(strict_types=1);

namespace Ged\Recherche;

/**
 * Un document lu du fonds (fichier + `.meta.json` voisin), prêt à être indexé. Le dossier fait foi :
 * l'identifiant n'est qu'une commodité quand la ligne d'index du Socle existe.
 */
final readonly class DocumentFonds
{
    public function __construct(
        public string $chemin,
        public ?int $documentId,
        public string $titre,
        public ?string $type,
        public ?string $emetteur,
        public ?string $date,
        public ?float $montant,
        public string $texte,
        public string $signature,
    ) {
    }
}
