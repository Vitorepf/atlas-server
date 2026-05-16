<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeContractView;
use PHPUnit\Framework\TestCase;

final class ScopeContractViewTest extends TestCase
{
    use SchemaContractAssertions;

    public function test_round_trip(): void
    {
        $v = new ScopeContractView(
            allowedFiles: ['a'],
            watchedFiles: ['w'],
            forbiddenFiles: ['f'],
            expectedMaxFiles: 3,
        );
        $rebuilt = ScopeContractView::fromArray($v->toCanonicalArray());
        $this->assertHashStable($v, $rebuilt);
        $this->assertContractSurface($v);
    }
}
