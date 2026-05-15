<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use PHPUnit\Framework\TestCase;

final class CapturesPaginationTest extends TestCase
{
    public function test_three_pages_have_no_duplicate_ids_under_concurrent_inserts(): void
    {
        // Initial test asserts the bug. Arm should evolve into the cursor variant.
        $this->markTestIncomplete('Implement cursor pagination first.');
    }
}
