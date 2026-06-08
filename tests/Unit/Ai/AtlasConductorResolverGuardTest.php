<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Services\Ai\AtlasDecide\AtlasConductorResolverGuard;
use PHPUnit\Framework\TestCase;

class AtlasConductorResolverGuardTest extends TestCase
{
    private AtlasConductorResolverGuard $guard;

    /** @var array<string,mixed> */
    private array $schema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = new AtlasConductorResolverGuard();
        $this->schema = ['type' => 'object', 'required' => ['ok'], 'properties' => ['ok' => ['type' => 'boolean']]];
    }

    public function test_no_options_returns_inner_unchanged(): void
    {
        $inner = static fn (array $a, array $c): array => ['result' => 'success', 'output' => 'x'];

        // Default path must be byte-for-byte identical — the very same closure.
        $this->assertSame($inner, $this->guard->decorate($inner, []));
    }

    public function test_valid_schema_output_passes_through(): void
    {
        $inner = static fn (array $a, array $c): array => [
            'result' => 'success', 'latency_ms' => 5, 'quality_score' => 0.9, 'output' => json_encode(['ok' => true]),
        ];
        $decorated = $this->guard->decorate($inner, ['output_schema' => $this->schema]);

        $out = $decorated([], ['input' => 'hello']);

        $this->assertSame('success', $out['result']);
        $this->assertSame(0.9, $out['quality_score']);
    }

    public function test_schema_mismatch_fails_the_arm(): void
    {
        $inner = static fn (array $a, array $c): array => ['result' => 'success', 'output' => 'this is prose, not json'];
        $decorated = $this->guard->decorate($inner, ['output_schema' => $this->schema]);

        $out = $decorated([], ['input' => 'hello']);

        $this->assertSame('failure', $out['result']);
        $this->assertStringStartsWith('schema_unsatisfied', $out['output']);
    }

    public function test_turn_budget_is_shared_across_arms_and_refuses_without_calling_inner(): void
    {
        $calls = 0;
        $inner = function (array $a, array $c) use (&$calls): array {
            $calls++;

            return ['result' => 'success', 'output' => 'ok'];
        };
        // Ceiling 6 units. Arm 1: 8 chars => 2 units (ok). Arm 2: 40 chars => 10 units (refused).
        $decorated = $this->guard->decorate($inner, ['turn_budget_units' => 6.0]);

        $arm1 = $decorated([], ['input' => str_repeat('a', 8)]);
        $this->assertSame('success', $arm1['result']);

        $arm2 = $decorated([], ['input' => str_repeat('a', 40)]);
        $this->assertSame('turn_budget_exhausted', $arm2['output']);
        $this->assertSame('failure', $arm2['result']);

        // The refused arm must NOT have invoked the (would-be-real-spend) inner resolver.
        $this->assertSame(1, $calls);
    }
}
