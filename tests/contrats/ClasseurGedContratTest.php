<?php

declare(strict_types=1);

namespace Ged\Tests\Contrats;

require_once __DIR__ . '/ClasseurContrat.php';

use Ged\Classement\ClasseurGed;
use Ged\Classement\ClientIa;
use Ged\Classement\Habitudes;
use Ged\Contrats\Classeur;

final class ClasseurGedContratTest extends ClasseurContrat
{
    protected function classeur(?ClientIa $ia = null): Classeur
    {
        return new ClasseurGed($this->config, $this->pdo, $ia ?? new IaContrat(null), new Habitudes($this->pdo));
    }
}
