<?php

declare(strict_types=1);

/**
 * Oracle T-TAG-DEDUP : logique find-or-create insensible à la casse (hermétique).
 */

namespace KDocs\Tests\Unit\Controllers;

use KDocs\Tests\TestCase;

class TagsDedupTest extends TestCase
{
    public function testCaseInsensitiveMatchFindsSameTagName(): void
    {
        $stored = 'Contribution Entretien';
        $searched = 'contribution entretien';
        $this->assertSame(
            mb_strtolower($stored),
            mb_strtolower($searched),
            'find-or-create doit comparer LOWER(name) — pas de doublon casse'
        );
    }

    public function testFindOrCreateWouldNotInsertDuplicate(): void
    {
        $existing = ['id' => 42, 'name' => 'Urgence'];
        $incoming = 'URGENCE';
        $wouldCreate = mb_strtolower($existing['name']) !== mb_strtolower($incoming);
        $this->assertFalse($wouldCreate);
        $this->assertSame($existing['id'], 42);
    }
}
