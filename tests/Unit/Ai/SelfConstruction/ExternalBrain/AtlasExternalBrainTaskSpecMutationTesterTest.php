<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainTaskSpecMutationTester;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainTaskSpecMutationTesterTest extends TestCase
{
    private AtlasExternalBrainTaskSpecMutationTester $tester;

    protected function setUp(): void
    {
        $this->tester = new AtlasExternalBrainTaskSpecMutationTester;
    }

    private function fullSpec(array $overrides = []): array
    {
        return array_merge([
            'objective'             => 'Implement FooService so that bar is computed correctly.',
            'allowed_files'         => ['app/Services/FooService.php'],
            'acceptance_criteria'   => ['The test suite must pass with zero failures.'],
            'required_evidence'     => ['tests_or_gates_result'],
            'target_exists_in_queue' => true,
        ], $overrides);
    }

    // ── Schema / required keys ────────────────────────────────────────────────

    public function test_result_has_required_keys(): void
    {
        $result = $this->tester->test($this->fullSpec());

        foreach (['schema', 'mutation_results', 'surviving_mutants', 'spec_gate_weaknesses', 'hardened'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
        $this->assertSame(AtlasExternalBrainTaskSpecMutationTester::SCHEMA, $result['schema']);
    }

    public function test_mutation_results_contains_all_nine_mutations(): void
    {
        $result = $this->tester->test($this->fullSpec());

        $types = array_column($result['mutation_results'], 'mutation_type');

        foreach ([
            'missing_implementation_file',
            'test_only_scope',
            'vague_acceptance',
            'contradictory_acceptance',
            'no_evidence',
            'weak_required_evidence',
            'duplicate_target',
            'weak_objective',
            'template_farm_objective',
        ] as $m) {
            $this->assertContains($m, $types, "Expected mutation type '{$m}' in results");
        }
        $this->assertCount(9, $result['mutation_results']);
    }

    // ── AC1: missing_implementation_file ──────────────────────────────────────

    public function test_single_file_spec_catches_missing_implementation_file(): void
    {
        $result = $this->tester->test($this->fullSpec([
            'allowed_files' => ['app/Services/FooService.php'],
        ]));

        $r = $this->findMutation($result, 'missing_implementation_file');
        $this->assertTrue($r['caught']);
    }

    public function test_multi_file_spec_does_not_catch_missing_implementation_file(): void
    {
        // Dropping one file from 2 still leaves 1 → gate doesn't catch it
        $result = $this->tester->test($this->fullSpec([
            'allowed_files' => ['app/Services/FooService.php', 'tests/FooTest.php'],
        ]));

        $r = $this->findMutation($result, 'missing_implementation_file');
        $this->assertFalse($r['caught']);
        $this->assertContains('missing_implementation_file', $result['surviving_mutants']);
        $this->assertNotEmpty($result['spec_gate_weaknesses']);
    }

    // ── AC1: vague_acceptance ─────────────────────────────────────────────────

    public function test_vague_acceptance_mutation_is_always_caught(): void
    {
        $result = $this->tester->test($this->fullSpec());

        $r = $this->findMutation($result, 'vague_acceptance');
        $this->assertTrue($r['caught']);
    }

    // ── AC1: no_evidence ──────────────────────────────────────────────────────

    public function test_no_evidence_mutation_is_always_caught(): void
    {
        $result = $this->tester->test($this->fullSpec());

        $r = $this->findMutation($result, 'no_evidence');
        $this->assertTrue($r['caught']);
    }

    // ── AC1: duplicate_target ─────────────────────────────────────────────────

    public function test_duplicate_target_caught_when_target_exists_in_queue(): void
    {
        $result = $this->tester->test($this->fullSpec(['target_exists_in_queue' => true]));

        $r = $this->findMutation($result, 'duplicate_target');
        $this->assertTrue($r['caught']);
    }

    public function test_duplicate_target_survives_when_target_not_in_queue(): void
    {
        $result = $this->tester->test($this->fullSpec(['target_exists_in_queue' => false]));

        $r = $this->findMutation($result, 'duplicate_target');
        $this->assertFalse($r['caught']);
        $this->assertContains('duplicate_target', $result['surviving_mutants']);
    }

    // ── AC1: weak_objective ───────────────────────────────────────────────────

    public function test_weak_objective_mutation_is_always_caught(): void
    {
        $result = $this->tester->test($this->fullSpec());

        $r = $this->findMutation($result, 'weak_objective');
        $this->assertTrue($r['caught']);
    }

    // ── AC2: surviving mutants → spec_gate_weaknesses ────────────────────────

    public function test_surviving_mutants_reported_as_spec_gate_weaknesses(): void
    {
        $result = $this->tester->test($this->fullSpec(['target_exists_in_queue' => false]));

        // duplicate_target survives
        $this->assertNotEmpty($result['spec_gate_weaknesses']);
        // weakness_signal is non-null for the survivor
        $r = $this->findMutation($result, 'duplicate_target');
        $this->assertNotNull($r['weakness_signal']);
    }

    public function test_caught_mutations_have_null_weakness_signal(): void
    {
        $result = $this->tester->test($this->fullSpec(['target_exists_in_queue' => true]));

        $r = $this->findMutation($result, 'duplicate_target');
        $this->assertNull($r['weakness_signal']);
    }

    // ── hardened flag ─────────────────────────────────────────────────────────

    public function test_fully_hardened_spec_has_no_survivors(): void
    {
        // single file + dup in queue → all mutations caught
        $result = $this->tester->test($this->fullSpec([
            'allowed_files'          => ['app/Services/FooService.php'],
            'target_exists_in_queue' => true,
        ]));

        $this->assertTrue($result['hardened']);
        $this->assertSame([], $result['surviving_mutants']);
    }

    public function test_spec_with_survivors_is_not_hardened(): void
    {
        $result = $this->tester->test($this->fullSpec(['target_exists_in_queue' => false]));

        $this->assertFalse($result['hardened']);
    }

    // ── repair_hint + fail_closed on every result ─────────────────────────────

    public function test_every_mutation_result_has_repair_hint_and_fail_closed(): void
    {
        $result = $this->tester->test($this->fullSpec());

        foreach ($result['mutation_results'] as $r) {
            $type = $r['mutation_type'];
            $this->assertArrayHasKey('repair_hint', $r, "{$type} missing repair_hint");
            $this->assertArrayHasKey('fail_closed', $r, "{$type} missing fail_closed");
            $this->assertIsString($r['repair_hint'], "{$type} repair_hint must be string");
            $this->assertNotEmpty($r['repair_hint'], "{$type} repair_hint must not be empty");
            $this->assertIsBool($r['fail_closed'], "{$type} fail_closed must be bool");
        }
    }

    public function test_weak_required_evidence_is_not_fail_closed(): void
    {
        $r = $this->findMutation($this->tester->test($this->fullSpec()), 'weak_required_evidence');
        $this->assertFalse($r['fail_closed'], 'weak_required_evidence is a warn-only mutation');
    }

    // ── test_only_scope ───────────────────────────────────────────────────────

    public function test_only_scope_caught_when_all_files_are_tests(): void
    {
        $result = $this->tester->test($this->fullSpec([
            'allowed_files' => ['tests/Feature/FooTest.php'],
        ]));

        $r = $this->findMutation($result, 'test_only_scope');
        $this->assertTrue($r['caught']);
    }

    public function test_only_scope_is_always_caught(): void
    {
        $result = $this->tester->test($this->fullSpec([
            'allowed_files' => ['app/Services/FooService.php', 'tests/FooTest.php'],
        ]));

        $r = $this->findMutation($result, 'test_only_scope');
        $this->assertTrue($r['caught'], 'test_only_scope mutation always injects test-only scope, always caught');
        $this->assertNull($r['weakness_signal']);
    }

    // ── contradictory_acceptance ──────────────────────────────────────────────

    public function test_contradictory_acceptance_is_always_caught(): void
    {
        $result = $this->tester->test($this->fullSpec());

        $r = $this->findMutation($result, 'contradictory_acceptance');
        $this->assertTrue($r['caught']);
        $this->assertNull($r['weakness_signal']);
    }

    // ── weak_required_evidence ────────────────────────────────────────────────

    public function test_weak_required_evidence_is_always_caught(): void
    {
        $result = $this->tester->test($this->fullSpec());

        $r = $this->findMutation($result, 'weak_required_evidence');
        $this->assertTrue($r['caught']);
    }

    // ── template_farm_objective ───────────────────────────────────────────────

    public function test_template_farm_objective_is_always_caught(): void
    {
        $result = $this->tester->test($this->fullSpec());

        $r = $this->findMutation($result, 'template_farm_objective');
        $this->assertTrue($r['caught']);
        $this->assertNull($r['weakness_signal']);
    }

    // ── helper ────────────────────────────────────────────────────────────────

    private function findMutation(array $result, string $type): array
    {
        foreach ($result['mutation_results'] as $r) {
            if ($r['mutation_type'] === $type) {
                return $r;
            }
        }
        $this->fail("Mutation type '{$type}' not found in results.");
    }
}
