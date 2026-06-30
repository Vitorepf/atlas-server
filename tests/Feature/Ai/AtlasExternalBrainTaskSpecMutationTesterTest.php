<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainTaskSpecMutationTester;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainTaskSpecMutationTesterTest extends TestCase
{
    private AtlasExternalBrainTaskSpecMutationTester $tester;

    protected function setUp(): void
    {
        $this->tester = new AtlasExternalBrainTaskSpecMutationTester;
    }

    /** A strong spec that should kill every mutation. */
    private function strongSpec(): array
    {
        return [
            'objective'              => 'Harden AtlasExternalBrainTaskSpecMutationTester to detect shallow template-farm survivors before they enter the queue.',
            'allowed_files'          => ['app/Services/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainTaskSpecMutationTester.php'],
            'acceptance_criteria'    => ['All 11 mutations must be caught; hardened=true must be returned when no mutant survives the gate.'],
            'required_evidence'      => ['tests_or_gates_result', 'implementation_notes'],
            'target_exists_in_queue' => true,
        ];
    }

    // ── Schema ────────────────────────────────────────────────────────────────

    public function test_schema_is_present_in_output(): void
    {
        $result = $this->tester->test($this->strongSpec());

        $this->assertSame(AtlasExternalBrainTaskSpecMutationTester::SCHEMA, $result['schema']);
    }

    public function test_output_has_required_keys(): void
    {
        $result = $this->tester->test($this->strongSpec());

        foreach (['schema', 'mutation_results', 'surviving_mutants', 'spec_gate_weaknesses', 'hardened'] as $k) {
            $this->assertArrayHasKey($k, $result, "Missing key: {$k}");
        }
    }

    public function test_each_mutation_result_has_required_keys(): void
    {
        $result = $this->tester->test($this->strongSpec());

        foreach ($result['mutation_results'] as $mutation) {
            foreach (['mutation_type', 'description', 'caught', 'weakness_signal', 'repair_hint', 'fail_closed'] as $k) {
                $this->assertArrayHasKey($k, $mutation, "Mutation {$mutation['mutation_type']} missing: {$k}");
            }
        }
    }

    // ── AC1: mutations flag specs missing concrete class names / unique claims ─

    public function test_no_concrete_class_in_objective_mutation_is_caught(): void
    {
        $result = $this->tester->test($this->strongSpec());

        $m = $this->byType($result['mutation_results'])['no_concrete_class_in_objective'] ?? null;
        $this->assertNotNull($m, 'no_concrete_class_in_objective mutation missing');
        $this->assertTrue($m['caught']);
    }

    public function test_no_unique_claim_in_acceptance_mutation_is_caught(): void
    {
        $result = $this->tester->test($this->strongSpec());

        $m = $this->byType($result['mutation_results'])['no_unique_claim_in_acceptance'] ?? null;
        $this->assertNotNull($m, 'no_unique_claim_in_acceptance mutation missing');
        $this->assertTrue($m['caught']);
    }

    public function test_template_farm_objective_mutation_is_caught(): void
    {
        $result = $this->tester->test($this->strongSpec());

        $this->assertTrue($this->byType($result['mutation_results'])['template_farm_objective']['caught']);
    }

    public function test_vague_acceptance_mutation_is_caught(): void
    {
        $result = $this->tester->test($this->strongSpec());

        $this->assertTrue($this->byType($result['mutation_results'])['vague_acceptance']['caught']);
    }

    public function test_all_mutation_types_present_in_mutation_results(): void
    {
        $result = $this->tester->test($this->strongSpec());

        $types = array_column($result['mutation_results'], 'mutation_type');
        foreach ([
            'missing_implementation_file', 'test_only_scope', 'vague_acceptance',
            'contradictory_acceptance', 'no_evidence', 'weak_required_evidence',
            'duplicate_target', 'weak_objective', 'template_farm_objective',
            'no_concrete_class_in_objective', 'no_unique_claim_in_acceptance',
        ] as $expected) {
            $this->assertContains($expected, $types, "Missing mutation type: {$expected}");
        }
    }

    // ── AC2: hardened=false when high-risk mutant survives ────────────────────

    public function test_hardened_false_when_duplicate_target_not_in_queue(): void
    {
        $spec                         = $this->strongSpec();
        $spec['target_exists_in_queue'] = false;

        $result = $this->tester->test($spec);

        $this->assertFalse($result['hardened']);
        $this->assertContains('duplicate_target', $result['surviving_mutants']);
    }

    public function test_spec_gate_weaknesses_populated_when_mutant_survives(): void
    {
        $spec                         = $this->strongSpec();
        $spec['target_exists_in_queue'] = false;

        $result = $this->tester->test($spec);

        $this->assertNotEmpty($result['spec_gate_weaknesses']);
        $this->assertContains('gate_allows_duplicate_target_not_yet_in_queue', $result['spec_gate_weaknesses']);
    }

    public function test_repair_hint_non_empty_for_surviving_mutant(): void
    {
        $spec                         = $this->strongSpec();
        $spec['target_exists_in_queue'] = false;

        $result       = $this->tester->test($spec);
        $byType       = $this->byType($result['mutation_results']);
        $survivorType = $result['surviving_mutants'][0] ?? null;

        $this->assertNotNull($survivorType);
        $this->assertNotEmpty($byType[$survivorType]['repair_hint']);
    }

    public function test_hardened_false_when_multiple_impl_files_allows_missing_file_mutation(): void
    {
        $spec                  = $this->strongSpec();
        $spec['allowed_files'] = ['app/Services/Foo.php', 'app/Services/Bar.php'];

        $result = $this->tester->test($spec);

        $this->assertFalse($result['hardened']);
        $this->assertContains('missing_implementation_file', $result['surviving_mutants']);
    }

    public function test_weakness_signal_null_for_all_caught_mutations(): void
    {
        $result = $this->tester->test($this->strongSpec());

        foreach ($result['mutation_results'] as $m) {
            if ($m['caught']) {
                $this->assertNull($m['weakness_signal'], "weakness_signal should be null when caught: {$m['mutation_type']}");
            }
        }
    }

    public function test_weakness_signal_non_null_for_surviving_mutation(): void
    {
        $spec                         = $this->strongSpec();
        $spec['target_exists_in_queue'] = false;

        $result = $this->tester->test($spec);
        $byType = $this->byType($result['mutation_results']);

        $this->assertNotNull($byType['duplicate_target']['weakness_signal']);
    }

    // ── AC3: hardened=true only when all mutations killed ────────────────────

    public function test_hardened_true_when_strong_spec_kills_all_mutations(): void
    {
        $result = $this->tester->test($this->strongSpec());

        $this->assertTrue($result['hardened']);
        $this->assertSame([], $result['surviving_mutants']);
        $this->assertSame([], $result['spec_gate_weaknesses']);
    }

    public function test_test_only_scope_caught_when_impl_file_present(): void
    {
        $result = $this->tester->test($this->strongSpec());

        $this->assertTrue($this->byType($result['mutation_results'])['test_only_scope']['caught']);
    }

    // ── AC4: pure and deterministic ───────────────────────────────────────────

    public function test_same_input_produces_same_output_twice(): void
    {
        $spec = $this->strongSpec();

        $this->assertSame($this->tester->test($spec), $this->tester->test($spec));
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    /** @return array<string,array<string,mixed>> */
    private function byType(array $mutations): array
    {
        $indexed = [];
        foreach ($mutations as $m) {
            $indexed[(string) ($m['mutation_type'] ?? '')] = $m;
        }
        return $indexed;
    }
}
