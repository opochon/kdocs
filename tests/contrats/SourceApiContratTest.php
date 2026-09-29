<?php

declare(strict_types=1);

namespace Ged\Tests\Contrats;

require_once __DIR__ . '/SourceContrat.php';

use Ged\Ingestion\Travaux;
use Ged\Jonctions\SourceApi;

final class SourceApiContratTest extends SourceAvecBaseContrat
{
    protected function releverDeuxFois(): array
    {
        $zone = $this->tmp . '/api';
        mkdir($zone, 0775, true);
        file_put_contents($zone . '/reprise.pdf', '%PDF API synthétique');
        $source = new SourceApi($zone, new Travaux($this->pdo), $this->pdo);
        $premier = $source->relever();
        (new Travaux($this->pdo))->recevoir($premier[0]);

        return [$premier, $source->relever()];
    }
}
