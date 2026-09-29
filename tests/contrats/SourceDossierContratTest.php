<?php

declare(strict_types=1);

namespace Ged\Tests\Contrats;

require_once __DIR__ . '/SourceContrat.php';

use Ged\Ingestion\SourceDossier;

final class SourceDossierContratTest extends SourceContrat
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/gedv2-contrat-dossier-' . bin2hex(random_bytes(5));
        mkdir($this->tmp . '/arrivee', 0775, true);
        file_put_contents($this->tmp . '/arrivee/facture.pdf', '%PDF-1.4 synthétique');
    }

    protected function tearDown(): void { SourceDossierContratFixture::supprimer($this->tmp); }

    protected function releverDeuxFois(): array
    {
        $source = new SourceDossier($this->tmp . '/arrivee', $this->tmp . '/travail', $this->tmp . '/etat.json');
        self::assertSame([], $source->relever(), 'premier relevé : le fichier doit devenir stable');
        $premier = $source->relever();

        return [$premier, $source->relever()];
    }
}
