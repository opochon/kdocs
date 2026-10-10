<?php

declare(strict_types=1);

namespace Ged\Securite;

use RuntimeException;

/**
 * Refus nommé d'un dépôt ou d'un déplacement dans un dossier de droits où le compte n'a pas le
 * niveau `deposer` (F10) : le message porte le dossier visé et le niveau manquant. Levé par le
 * service (`Socle\DepotFichiers`) AVANT tout déplacement, pour que fichier, `.meta.json` et index
 * restent intacts.
 */
final class RefusDepot extends RuntimeException
{
}
