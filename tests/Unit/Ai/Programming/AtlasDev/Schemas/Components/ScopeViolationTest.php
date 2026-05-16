<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeViolation;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ScopeViolationTest extends TestCase
{
    use SchemaContractAssertions;

    public function test_round_trip(): void
    {
        $v = new ScopeViolation(kind: ScopeViolation::KIND_FORBIDDEN_TOUCH, path: 'config/secret.php', detail: 'forbidden path');
        $rebuilt = ScopeViolation::fromArray($v->toCanonicalArray());
        $this->assertHashStable($v, $rebuilt);
        $this->assertTrue($v->isFailing());
        $this->assertContractSurface($v);
    }

    public function test_invalid_kind_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ScopeViolation(kind: 'something_else', path: null, detail: 'd');
    }

    public function test_empty_detail_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ScopeViolation(kind: ScopeViolation::KIND_UNEXPECTED_TOUCH, path: 'p', detail: '');
    }
}
