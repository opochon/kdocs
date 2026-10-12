<?php

declare(strict_types=1);

namespace Ged\Ingestion;

use RuntimeException;

/**
 * Échec NOMMÉ d'une étape de consommation, sans exception muette.
 *
 * Équivalent GEDv2 de `ConsumerError` : tout échec de la chaîne (fichier absent, doublon, numéro
 * d'archive hors bornes ou déjà pris, dossier impossible, étape invalide) porte le NOM du fichier
 * concerné et la CAUSE. C'est la règle GEDv2 « une erreur se nomme (étape, cause), jamais un 500 muet ».
 */
final class ErreurConsommation extends RuntimeException
{
    public function __construct(string $fichier, string $cause)
    {
        parent::__construct($fichier . ' : ' . $cause);
    }
}
