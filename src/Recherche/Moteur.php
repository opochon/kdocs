<?php

declare(strict_types=1);

namespace Ged\Recherche;

/**
 * Contrat du moteur de recherche, pour que l'écran puisse être jugé avec un moteur en panne sans base.
 */
interface Moteur
{
    public function rechercher(Requete $requete): Resultat;
}
