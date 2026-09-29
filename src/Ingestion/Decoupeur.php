<?php

declare(strict_types=1);

namespace Ged\Ingestion;

/** Découpe un fichier reçu en pièces (une pièce = un document). */
interface Decoupeur
{
    /**
     * @return list<array{fichier:string, pages:list<int>, texte:string, analyse:array<string, mixed>}>
     *         une entrée par pièce ; un fichier d'une seule pièce rend une seule entrée
     */
    public function decouper(string $fichier, string $dossierSortie): array;
}
