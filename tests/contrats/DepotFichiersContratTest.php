<?php

declare(strict_types=1);

namespace Ged\Tests\Contrats;

require_once __DIR__ . '/DepotContrat.php';

use Ged\Config;
use Ged\Contrats\Depot;
use Ged\Socle\DepotFichiers;

final class DepotFichiersContratTest extends DepotContrat
{
    protected function depot(): Depot
    {
        return new DepotFichiers(new Config(dirname(__DIR__, 2)));
    }
}
