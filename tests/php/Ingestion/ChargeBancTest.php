<?php

declare(strict_types=1);

namespace Ged\Tests\Ingestion;

use PHPUnit\Framework\TestCase;

/** Le banc de charge mesure la GED, sans dépendre de la latence d'un fournisseur IA externe. */
final class ChargeBancTest extends TestCase
{
    public function testLeBancDeChargeDesactiveLIAExterne(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/tools/banc/charge.php');

        self::assertStringContainsString("'INFOMANIAK_AI_ENABLED' => 'false'", $source);
    }
}
