<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOriginatorRoundRetrospectiveCompiler;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainOriginatorRoundRetrospectiveCompilerTest extends TestCase
{
    private AtlasExternalBrainOriginatorRoundRetrospectiveCompiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new AtlasExternalBrainOriginatorRoundRetrospectiveCompiler;
    }

    public function test_clean_round_emits_keep_directives(): void
    {
        $result = $this->compiler->compile([
            'enqueue_results' => [
                ['status' => 'enqueued', 'strategy' => 'standard'],
            ],
        ]);

        $this->assertNotEmpty($result['keep']);
        $this->assertTrue($result['clean_round']);
        $this->assertEmpty($result['avoid']);
        $this->assertEmpty($result['repair']);
        $this->assertEmpty($result['pivot']);
    }

    public function test_rejected_specs_emit_repair_directives(): void
    {
        $result = $this->compiler->compile([
            'enqueue_results' => [
                ['status' => 'rejected', 'reason' => 'missing_implementation'],
            ],
        ]);

        $this->assertNotEmpty($result['repair']);
        $this->assertFalse($result['clean_round']);
    }

    public function test_collision_pressure_emits_pivot_directives(): void
    {
        $result = $this->compiler->compile([
            'enqueue_results' => [
                ['status' => 'enqueued', 'strategy' => 'standard'],
            ],
            'queued_target_collisions' => [
                ['target' => 'app/Foo.php'],
            ],
        ]);

        $this->assertNotEmpty($result['pivot']);
        $this->assertFalse($result['clean_round']);
    }

    public function test_malformed_blockers_emit_avoid_directives(): void
    {
        $result = $this->compiler->compile([
            'enqueue_results' => [
                ['status' => 'enqueued', 'strategy' => 'standard'],
            ],
            'malformed_sweep' => [
                ['reason' => 'poison_risk'],
            ],
        ]);

        $this->assertNotEmpty($result['avoid']);
        $this->assertFalse($result['clean_round']);
    }

    public function test_health_gate_failures_emit_avoid_directives(): void
    {
        $result = $this->compiler->compile([
            'enqueue_results' => [
                ['status' => 'enqueued', 'strategy' => 'standard'],
            ],
            'health_gates' => [
                ['name' => 'scope_safety', 'passed' => false],
            ],
        ]);

        $this->assertNotEmpty($result['avoid']);
        $this->assertFalse($result['clean_round']);
    }

    public function test_schema_present(): void
    {
        $result = $this->compiler->compile([]);
        $this->assertSame(AtlasExternalBrainOriginatorRoundRetrospectiveCompiler::SCHEMA, $result['schema']);
    }
}
