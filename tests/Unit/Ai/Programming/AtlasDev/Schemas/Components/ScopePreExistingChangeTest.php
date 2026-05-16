<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopePreExistingChange;
use PHPUnit\Framework\TestCase;

final class ScopePreExistingChangeTest extends TestCase
{
    use SchemaContractAssertions;

    public function test_round_trip(): void
    {
        $c = new ScopePreExistingChange(path: 'work/in-progress.md', preserved: true);
        $rebuilt = ScopePreExistingChange::fromArray($c->toCanonicalArray());
        $this->assertHashStable($c, $rebuilt);
        $this->assertContractSurface($c);
    }
}
