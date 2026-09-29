<?php

declare(strict_types=1);

namespace Ged\Contrats;

/**
 * Contrat entre Ingestion (qui a lu une pièce) et Classement (qui propose ses métadonnées).
 * Implémenté par le secteur Classement.
 */
interface Classeur
{
    /**
     * @param array<string, mixed> $analyse ce que le moteur a lu (facture QR, origine : lot, pages, clés)
     * @return array{
     *   titre:?string, type:?string, emetteur:?string, date:?string, montant:?float, etiquettes:list<string>,
     *   confiance:float, source:string, preuve:?string, valide:bool, raison:?string
     * } `valide` = classé seul (confiance ≥ seuil ET preuve) ; sinon le document va dans « À traiter » avec `raison`.
     */
    public function proposer(string $texte, array $analyse, string $nomFichier): array;
}
