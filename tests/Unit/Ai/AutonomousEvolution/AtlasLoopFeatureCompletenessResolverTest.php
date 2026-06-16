<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopFeatureCompletenessResolver;
use PHPUnit\Framework\TestCase;

/**
 * ACDE F2 — pins the completeness checklist: one falsifiable criterion per verification atom, deterministically
 * derived from the atom the verifier already enforces (never an opinion). Pure (no container).
 */
final class AtlasLoopFeatureCompletenessResolverTest extends TestCase
{
    public function test_emits_one_falsifiable_criterion_per_atom_type(): void
    {
        $atoms = [
            ['type' => 'method_return', 'method' => 'total', 'expected' => 42],
            ['type' => 'command_output', 'command' => 'php artisan x', 'output_contains' => 'done', 'exit_code' => 0],
            ['type' => 'http_response', 'method' => 'GET', 'path' => '/health', 'status' => 200, 'body_contains' => 'ok'],
            ['type' => 'event_dispatched', 'event_class' => 'App\\Events\\Shipped', 'trigger' => ['type' => 'method_call', 'method' => 'ship']],
            ['type' => 'job_dispatched', 'job_class' => 'App\\Jobs\\Email', 'trigger' => ['type' => 'http_request', 'method' => 'POST', 'path' => '/send']],
            ['type' => 'db_state', 'table' => 'orders', 'count_operator' => '>=', 'expected_count' => 1, 'trigger' => ['type' => 'artisan_call', 'command' => 'orders:make']],
        ];

        $checklist = (new AtlasLoopFeatureCompletenessResolver)->resolve($atoms);

        $this->assertCount(6, $checklist);
        // contiguous, atom-ordered indices
        $this->assertSame([0, 1, 2, 3, 4, 5], array_column($checklist, 'index'));
        $this->assertSame(
            ['method_return', 'command_output', 'http_response', 'event_dispatched', 'job_dispatched', 'db_state'],
            array_column($checklist, 'type'),
        );

        $this->assertStringContainsString('`total()` returns 42', $checklist[0]['criterion']);
        $this->assertStringContainsString("Command `php artisan x` exits 0 and output contains 'done'", $checklist[1]['criterion']);
        $this->assertStringContainsString("GET /health responds 200 and body contains 'ok'", $checklist[2]['criterion']);
        $this->assertStringContainsString('Event `App\\Events\\Shipped` is dispatched when calling `ship()`', $checklist[3]['criterion']);
        $this->assertStringContainsString('Job `App\\Jobs\\Email` is dispatched when POST /send', $checklist[4]['criterion']);
        $this->assertStringContainsString('Table `orders` has row count >= 1 after artisan `orders:make` runs', $checklist[5]['criterion']);
    }

    public function test_skips_incomplete_atoms_and_renders_literals(): void
    {
        $atoms = [
            ['type' => 'method_return', 'method' => '', 'expected' => 1],       // no method => skipped
            ['type' => 'method_return', 'method' => 'flag', 'expected' => true],
            ['type' => 'method_return', 'method' => 'name', 'expected' => 'Atlas', 'static' => true],
            ['type' => 'unknown_kind', 'foo' => 'bar'],                          // unsupported => skipped
        ];

        $checklist = (new AtlasLoopFeatureCompletenessResolver)->resolve($atoms);

        $this->assertCount(2, $checklist);
        $this->assertSame([0, 1], array_column($checklist, 'index'));
        $this->assertStringContainsString('Public method `flag()` returns true', $checklist[0]['criterion']);
        $this->assertStringContainsString("Public static method `name()` returns 'Atlas'", $checklist[1]['criterion']);
    }

    public function test_empty_atoms_yield_empty_checklist(): void
    {
        $this->assertSame([], (new AtlasLoopFeatureCompletenessResolver)->resolve([]));
    }
}
