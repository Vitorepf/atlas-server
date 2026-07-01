<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainWeakOutputRepairLoop;
use Tests\TestCase;

final class AtlasExternalBrainWeakOutputRepairLoopTest extends TestCase
{
    private function loop(): AtlasExternalBrainWeakOutputRepairLoop
    {
        return new AtlasExternalBrainWeakOutputRepairLoop;
    }

    private function baseProposal(): array
    {
        return [
            'objective' => 'Do a real thing',
            'allowed_files' => ['app/Services/Ai/Example.php'],
            'acceptance_criteria' => ['Runnable: ./vendor/bin/phpunit must pass.'],
            'required_evidence' => ['tests_or_gates_result'],
        ];
    }

    // ── refused classes ───────────────────────────────────────────────────

    public function test_poison_proposal_is_refused_with_next_scaffold_constraint(): void
    {
        $r = $this->loop()->repair(['proposal' => $this->baseProposal(), 'is_poison' => true]);

        self::assertNull($r['repaired_candidate']);
        self::assertNotEmpty($r['next_scaffold_constraint']);
        self::assertSame('unrecoverable', $r['failure_class']);
    }

    public function test_give_back_proposal_is_refused_with_next_scaffold_constraint(): void
    {
        $r = $this->loop()->repair(['proposal' => $this->baseProposal(), 'is_give_back' => true]);

        self::assertNull($r['repaired_candidate']);
        self::assertNotEmpty($r['next_scaffold_constraint']);
    }

    public function test_empty_objective_is_refused_with_next_scaffold_constraint(): void
    {
        $proposal = $this->baseProposal();
        $proposal['objective'] = '';

        $r = $this->loop()->repair(['proposal' => $proposal]);

        self::assertNull($r['repaired_candidate']);
        self::assertNotEmpty($r['next_scaffold_constraint']);
    }

    public function test_duplicate_target_is_refused_with_next_scaffold_constraint(): void
    {
        $r = $this->loop()->repair(['proposal' => $this->baseProposal(), 'is_duplicate' => true]);

        self::assertNull($r['repaired_candidate']);
        self::assertSame('duplicate_target', $r['failure_class']);
        self::assertNotEmpty($r['next_scaffold_constraint']);
    }

    public function test_proxy_proof_is_refused_with_next_scaffold_constraint(): void
    {
        $r = $this->loop()->repair([
            'proposal' => $this->baseProposal(),
            'weakness_labels' => ['proxy_proof'],
        ]);

        self::assertNull($r['repaired_candidate']);
        self::assertSame('proxy_or_fake_value_output', $r['failure_class']);
        self::assertNotEmpty($r['next_scaffold_constraint']);
    }

    public function test_fake_value_is_refused_with_next_scaffold_constraint(): void
    {
        $r = $this->loop()->repair([
            'proposal' => $this->baseProposal(),
            'weakness_labels' => ['fake_value'],
        ]);

        self::assertNull($r['repaired_candidate']);
        self::assertSame('proxy_or_fake_value_output', $r['failure_class']);
    }

    public function test_shallow_duplication_is_refused_with_next_scaffold_constraint(): void
    {
        $r = $this->loop()->repair([
            'proposal' => $this->baseProposal(),
            'weakness_labels' => ['shallow_duplication'],
        ]);

        self::assertNull($r['repaired_candidate']);
        self::assertSame('low_value', $r['failure_class']);
        self::assertNotEmpty($r['next_scaffold_constraint']);
    }

    public function test_template_farming_is_refused_with_next_scaffold_constraint(): void
    {
        $r = $this->loop()->repair([
            'proposal' => $this->baseProposal(),
            'weakness_labels' => ['template_farming'],
        ]);

        self::assertNull($r['repaired_candidate']);
        self::assertSame('low_value', $r['failure_class']);
    }

    public function test_fake_confidence_is_refused_with_next_scaffold_constraint(): void
    {
        $r = $this->loop()->repair([
            'proposal' => $this->baseProposal(),
            'weakness_labels' => ['fake_confidence'],
        ]);

        self::assertNull($r['repaired_candidate']);
        self::assertSame('low_value', $r['failure_class']);
    }

    // ── repaired classes ──────────────────────────────────────────────────

    public function test_stale_evidence_is_repaired_with_deterministic_repair_steps(): void
    {
        $r = $this->loop()->repair([
            'proposal' => $this->baseProposal(),
            'weakness_labels' => ['stale_evidence'],
        ]);

        self::assertNotNull($r['repaired_candidate']);
        self::assertNotEmpty($r['repair_steps']);
        self::assertSame('stale_evidence', $r['failure_class']);
    }

    public function test_vague_objective_is_repaired_with_deterministic_repair_steps(): void
    {
        $r = $this->loop()->repair([
            'proposal' => $this->baseProposal(),
            'weakness_labels' => ['vague_objective'],
        ]);

        self::assertNotNull($r['repaired_candidate']);
        self::assertNotEmpty($r['repair_steps']);
        self::assertSame('vague_objective', $r['failure_class']);
    }

    public function test_low_impact_is_repaired_with_deterministic_repair_steps(): void
    {
        $r = $this->loop()->repair([
            'proposal' => $this->baseProposal(),
            'weakness_labels' => ['low_impact'],
        ]);

        self::assertNotNull($r['repaired_candidate']);
        self::assertNotEmpty($r['repair_steps']);
        self::assertSame('low_impact', $r['failure_class']);
    }

    public function test_missing_required_evidence_is_repaired_with_deterministic_repair_steps(): void
    {
        $proposal = $this->baseProposal();
        $proposal['required_evidence'] = [];

        $r = $this->loop()->repair(['proposal' => $proposal]);

        self::assertNotNull($r['repaired_candidate']);
        self::assertSame(['tests_or_gates_result', 'implementation_notes'], $r['repaired_candidate']['required_evidence']);
        self::assertNotEmpty($r['repair_steps']);
        self::assertSame('fixable_missing_evidence', $r['failure_class']);
    }

    public function test_missing_runnable_acceptance_is_repaired_with_deterministic_repair_steps(): void
    {
        $proposal = $this->baseProposal();
        $proposal['acceptance_criteria'] = ['the feature should work well'];

        $r = $this->loop()->repair(['proposal' => $proposal]);

        self::assertNotNull($r['repaired_candidate']);
        self::assertSame('fixable_missing_evidence', $r['failure_class']);
        self::assertNotEmpty($r['repair_steps']);
    }

    public function test_missing_implementation_file_is_repaired_with_deterministic_repair_steps(): void
    {
        $proposal = $this->baseProposal();
        $proposal['allowed_files'] = ['tests/Feature/Ai/ExampleTest.php'];

        $r = $this->loop()->repair(['proposal' => $proposal]);

        self::assertNotNull($r['repaired_candidate']);
        self::assertSame('fixable_missing_implementation_file', $r['failure_class']);
        self::assertNotEmpty($r['repair_steps']);
    }

    public function test_over_broad_scope_is_repaired_with_deterministic_repair_steps(): void
    {
        $proposal = $this->baseProposal();
        $proposal['allowed_files'] = array_map(fn (int $i) => "app/Services/Ai/File{$i}.php", range(1, 8));

        $r = $this->loop()->repair(['proposal' => $proposal, 'weakness_labels' => ['over_broad_scope']]);

        self::assertNotNull($r['repaired_candidate']);
        self::assertSame('fixable_scope_shape', $r['failure_class']);
        self::assertNotEmpty($r['repair_steps']);
        self::assertLessThanOrEqual(2, count($r['repaired_candidate']['allowed_files']));
    }

    // ── escalation ────────────────────────────────────────────────────────

    public function test_repeated_unrepaired_failures_escalate_instead_of_looping_forever(): void
    {
        $input = [
            'proposal' => $this->baseProposal(),
            'weakness_labels' => ['stale_evidence'],
            'repeat_count' => 999,
        ];

        $r = $this->loop()->repair($input);

        self::assertSame('escalated_repeated_unrepaired', $r['failure_class']);
        self::assertNull($r['repaired_candidate']);
        self::assertNotNull($r['escalation']);
        self::assertStringContainsString('escalate', $r['next_scaffold_constraint']);
    }

    public function test_low_repeat_count_does_not_escalate(): void
    {
        $r = $this->loop()->repair([
            'proposal' => $this->baseProposal(),
            'weakness_labels' => ['stale_evidence'],
            'repeat_count' => 1,
        ]);

        self::assertNotSame('escalated_repeated_unrepaired', $r['failure_class']);
    }
}
