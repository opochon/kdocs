<?php

declare(strict_types=1);

namespace Ged\Ui;

use Ged\Config;
use Ged\Socle\Fichiers;
use Ged\Socle\Vues;
use Throwable;

/**
 * Données du rail de navigation, calculées à UN SEUL endroit pour tous les écrans :
 *
 * - le compteur « À traiter » est la FILE RÉELLE du fonds (documents `a_controler` dont le fichier existe,
 *   le dossier fait foi) — jamais un compteur d'écran différent d'un autre ;
 * - les VUES listées sont les vues réellement ENREGISTRÉES du fonds (`.ged/vues.json`), avec leur slug ;
 *   la barre latérale ne connaît donc aucun lien figé.
 *
 * Toute panne (base muette, registre illisible) rend la forme vide : le rail reste lisible, jamais un 500.
 */
final class Rail
{
    public function __construct(
        private readonly Config $config,
        private readonly ?int $compte = null,
        private readonly bool $administrateur = false,
    ) {
    }

    public static function depuisRacine(?string $racine = null, ?int $compte = null, bool $administrateur = false): self
    {
        return new self(new Config($racine ?? dirname(__DIR__, 2)), $compte, $administrateur);
    }

    /** @return array{compte_a_traiter:int, vues:list<array{id:int, nom:string, slug:string}>} */
    public function donnees(): array
    {
        return ['compte_a_traiter' => $this->compteATraiter(), 'vues' => $this->vues()];
    }

    /** Nombre de pièces réellement en attente de contrôle dans CE fonds, visibles par ce compte. */
    private function compteATraiter(): int
    {
        try {
            return ATraiter::depuisRacine($this->config->racine(), $this->compte, $this->administrateur)->compterAControler();
        } catch (Throwable) {
            return 0;
        }
    }

    /** @return list<array{id:int, nom:string, slug:string}> */
    private function vues(): array
    {
        try {
            $vues = [];
            foreach ((new Vues($this->config))->lister() as $vue) {
                $vues[] = ['id' => $vue['id'], 'nom' => $vue['nom'], 'slug' => Fichiers::slug($vue['nom'])];
            }

            return $vues;
        } catch (Throwable) {
            return [];
        }
    }
}
