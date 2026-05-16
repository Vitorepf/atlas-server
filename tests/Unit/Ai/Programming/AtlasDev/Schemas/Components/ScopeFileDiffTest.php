<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeFileDiff;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ScopeFileDiffTest extends TestCase
{
    use SchemaContractAssertions;

    public function test_round_trip_and_contract_surface(): void
    {
        $d = new ScopeFileDiff(path: 'app/Foo.php', added: 12, removed: 3, fileHashAfter: 'aaa');
        $rebuilt = ScopeFileDiff::fromArray($d->toCanonicalArray());
        $this->assertHashStable($d, $rebuilt);
        $this->assertContractSurface($d);
    }

    public function test_negative_added_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ScopeFileDiff(path: 'p', added: -1, removed: 0, fileHashAfter: 'h');
    }

    public function test_empty_hash_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ScopeFileDiff(path: 'p', added: 0, removed: 0, fileHashAfter: '');
    }
}
