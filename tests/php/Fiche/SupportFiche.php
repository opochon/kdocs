<?php

declare(strict_types=1);

namespace Ged\Tests\Fiche;

use Ged\Socle\DepotFichiers;

/**
 * Socle commun des oracles du secteur Fiche : base `gedv2_test` remise à neuf et racine documentaire
 * éphémère (le trait du Socle). Chaque pièce est déposée par le VRAI `DepotFichiers`, sans `dossier`
 * explicite, pour que le rangement année/type/émetteur soit exercé (les déplacements sont réels).
 */
trait SupportFiche
{
    use \Ged\Tests\Socle\SupportCorpus;

    /** Dépose une pièce de test et rend son identifiant d'index. */
    private function deposerFiche(string $nom, string $titre, string $contenu, array $extra = []): int
    {
        return (new DepotFichiers($this->config))->deposer($this->pieceADeposer($nom, $contenu), [
            'titre' => $titre,
            'type' => 'Facture',
            'emetteur' => 'Atelier Fictif SA',
            'date' => '2026-10-14',
            'texte' => $contenu,
            'statut' => 'valide',
        ] + $extra);
    }
}
