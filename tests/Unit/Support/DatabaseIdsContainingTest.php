<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\DatabaseIdsContaining;
use PHPUnit\Framework\TestCase;

final class DatabaseIdsContainingTest extends TestCase
{
    public function test_empty_needles_returns_empty_without_querying(): void
    {
        $this->assertSame([], DatabaseIdsContaining::query('ai_jobs', ['payload'], []));
    }
}
