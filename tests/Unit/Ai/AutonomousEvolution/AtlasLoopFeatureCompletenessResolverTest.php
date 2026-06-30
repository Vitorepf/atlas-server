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

    public function test_each_criterion_includes_required_proof_handle_fields(): void
    {
        $checklist = (new AtlasLoopFeatureCompletenessResolver)->resolve([
            ['type' => 'command_output', 'command' => 'php artisan foo', 'exit_code' => 0],
            ['type' => 'db_state', 'table' => 'orders', 'expected_count' => 1],
        ]);

        $this->assertCount(2, $checklist);
        foreach ($checklist as $item) {
            foreach (['atom_id', 'proof_kind', 'proof_command_or_path', 'evidence_required', 'criterion'] as $field) {
                $this->assertArrayHasKey($field, $item, "criterion must include field: {$field}");
            }
        }
    }

    public function test_command_output_atom_exposes_runnable_command_as_proof_command_or_path(): void
    {
        $checklist = (new AtlasLoopFeatureCompletenessResolver)->resolve([
            ['type' => 'command_output', 'command' => 'php artisan test --filter=Foo', 'exit_code' => 0],
        ]);

        $this->assertSame('command_output', $checklist[0]['proof_kind']);
        $this->assertSame('php artisan test --filter=Foo', $checklist[0]['proof_command_or_path']);
        $this->assertSame('exit_code_and_output', $checklist[0]['evidence_required']);
    }

    public function test_other_atom_types_expose_stable_proof_kind_values(): void
    {
        $checklist = (new AtlasLoopFeatureCompletenessResolver)->resolve([
            ['type' => 'http_response', 'method' => 'POST', 'path' => '/api/x', 'status' => 201],
            ['type' => 'db_state', 'table' => 'users', 'expected_count' => 1],
            ['type' => 'event_dispatched', 'event_class' => 'App\\Events\\E'],
            ['type' => 'job_dispatched', 'job_class' => 'App\\Jobs\\J'],
            ['type' => 'method_return', 'method' => 'get', 'expected' => true],
        ]);

        $this->assertSame('http_response', $checklist[0]['proof_kind']);
        $this->assertSame('http_status_and_body', $checklist[0]['evidence_required']);
        $this->assertStringContainsString('/api/x', (string) $checklist[0]['proof_command_or_path']);
        $this->assertNull($checklist[1]['proof_command_or_path']);
        $this->assertSame('db_row_count', $checklist[1]['evidence_required']);
        $this->assertSame('event_dispatch_assertion', $checklist[2]['evidence_required']);
        $this->assertNull($checklist[2]['proof_command_or_path'], 'no command for event atoms');
        $this->assertSame('job_dispatch_assertion', $checklist[3]['evidence_required']);
        $this->assertSame('return_value', $checklist[4]['evidence_required']);
    }

    public function test_atom_id_falls_back_to_type_index_when_not_supplied(): void
    {
        $checklist = (new AtlasLoopFeatureCompletenessResolver)->resolve([
            ['type' => 'method_return', 'method' => 'foo', 'expected' => 1],
            ['type' => 'method_return', 'atom_id' => 'my-explicit-id', 'method' => 'bar', 'expected' => 2],
        ]);

        $this->assertSame('method_return_0', $checklist[0]['atom_id'], 'missing atom_id must fall back to type_index');
        $this->assertSame('my-explicit-id', $checklist[1]['atom_id'], 'explicit atom_id must be preserved');
    }

    public function test_empty_atoms_yield_empty_checklist(): void
    {
        $this->assertSame([], (new AtlasLoopFeatureCompletenessResolver)->resolve([]));
    }
}
