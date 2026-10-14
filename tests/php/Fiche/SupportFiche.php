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
    use \Ged\Tests\Socle\SupportCorpus {
        setUpCorpus as private setUpCorpusSocle;
    }

    /**
     * Remise à neuf du Socle, PUIS réinstallation de l'index plein texte que `SupportCorpus` retire sans
     * le reposer. Les suites suivantes de la même passe PHPUnit (Ingestion, Recherche) en dépendent :
     * un test du secteur Fiche, exécuté tôt, ne doit pas laisser la base de test sans `recherche_textes`
     * (sinon `Courrier\ModuleTest` échoue en `SQLSTATE[42S02]`, hors de son propre test).
     */
    private function setUpCorpus(): void
    {
        $this->setUpCorpusSocle();
        \Ged\Recherche\Schema::installer($this->pdo);
    }

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
