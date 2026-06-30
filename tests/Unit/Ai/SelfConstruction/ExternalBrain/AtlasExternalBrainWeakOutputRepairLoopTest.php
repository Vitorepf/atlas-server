<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainWeakOutputRepairLoop;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainWeakOutputRepairLoopTest extends TestCase
{
    private AtlasExternalBrainWeakOutputRepairLoop $loop;

    protected function setUp(): void
    {
        $this->loop = new AtlasExternalBrainWeakOutputRepairLoop;
    }

    private function proposal(array $overrides = []): array
    {
        return array_merge([
            'objective'            => 'Implement FooService to handle bar events.',
            'allowed_files'        => ['app/Services/FooService.php'],
            'acceptance_criteria'  => ['Runnable: ./vendor/bin/phpunit tests/Unit/FooServiceTest.php must pass.'],
            'required_evidence'    => ['tests_or_gates_result'],
        ], $overrides);
    }

    private function input(array $proposalOverrides = [], array $inputOverrides = []): array
    {
        return array_merge([
            'proposal'        => $this->proposal($proposalOverrides),
            'weakness_labels' => [],
            'is_poison'       => false,
            'is_give_back'    => false,
            'is_duplicate'    => false,
            'dedup_conflict'  => '',
        ], $inputOverrides);
    }

    // ── Schema / AC4 required keys ────────────────────────────────────────────

    public function test_result_has_required_keys(): void
    {
        $result = $this->loop->repair($this->input());

        foreach (['schema', 'failure_class', 'repaired_candidate', 'repair_steps', 'refusal_reason', 'next_scaffold_constraint'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
        $this->assertSame(AtlasExternalBrainWeakOutputRepairLoop::SCHEMA, $result['schema']);
    }

    // ── AC2: classification — unrecoverable ──────────────────────────────────

    public function test_poison_flag_yields_unrecoverable(): void
    {
        $result = $this->loop->repair($this->input([], ['is_poison' => true]));

        $this->assertSame(AtlasExternalBrainWeakOutputRepairLoop::CLASS_UNRECOVERABLE, $result['failure_class']);
        $this->assertNull($result['repaired_candidate']);
        $this->assertSame([], $result['repair_steps']);
        $this->assertNotNull($result['refusal_reason']);
    }

    public function test_give_back_flag_yields_unrecoverable(): void
    {
        $result = $this->loop->repair($this->input([], ['is_give_back' => true]));

        $this->assertSame(AtlasExternalBrainWeakOutputRepairLoop::CLASS_UNRECOVERABLE, $result['failure_class']);
    }

    public function test_empty_objective_yields_unrecoverable(): void
    {
        $result = $this->loop->repair($this->input(['objective' => '']));

        $this->assertSame(AtlasExternalBrainWeakOutputRepairLoop::CLASS_UNRECOVERABLE, $result['failure_class']);
        $this->assertStringContainsString('empty', $result['refusal_reason']);
    }

    // ── AC2: classification — duplicate_target ───────────────────────────────

    public function test_is_duplicate_flag_yields_duplicate_target(): void
    {
        $result = $this->loop->repair($this->input([], ['is_duplicate' => true]));

        $this->assertSame(AtlasExternalBrainWeakOutputRepairLoop::CLASS_DUPLICATE_TARGET, $result['failure_class']);
        $this->assertNull($result['repaired_candidate']);
    }

    public function test_dedup_conflict_field_yields_duplicate_target(): void
    {
        $result = $this->loop->repair($this->input([], ['dedup_conflict' => 'task-abc-123']));

        $this->assertSame(AtlasExternalBrainWeakOutputRepairLoop::CLASS_DUPLICATE_TARGET, $result['failure_class']);
        $this->assertStringContainsString('task-abc-123', $result['refusal_reason']);
    }

    // ── AC2: classification — low_value ──────────────────────────────────────

    public function test_shallow_duplication_weakness_yields_low_value(): void
    {
        $result = $this->loop->repair($this->input([], ['weakness_labels' => ['shallow_duplication']]));

        $this->assertSame(AtlasExternalBrainWeakOutputRepairLoop::CLASS_LOW_VALUE, $result['failure_class']);
        $this->assertNull($result['repaired_candidate']);
    }

    public function test_template_farming_weakness_yields_low_value(): void
    {
        $result = $this->loop->repair($this->input([], ['weakness_labels' => ['template_farming']]));

        $this->assertSame(AtlasExternalBrainWeakOutputRepairLoop::CLASS_LOW_VALUE, $result['failure_class']);
    }

    public function test_fake_confidence_weakness_yields_low_value(): void
    {
        $result = $this->loop->repair($this->input([], ['weakness_labels' => ['fake_confidence']]));

        $this->assertSame(AtlasExternalBrainWeakOutputRepairLoop::CLASS_LOW_VALUE, $result['failure_class']);
    }

    // ── AC2: classification — fixable_missing_evidence ───────────────────────

    public function test_empty_required_evidence_yields_fixable_missing_evidence(): void
    {
        $result = $this->loop->repair($this->input(['required_evidence' => []]));

        $this->assertSame(AtlasExternalBrainWeakOutputRepairLoop::CLASS_FIXABLE_MISSING_EVIDENCE, $result['failure_class']);
        $this->assertNotNull($result['repaired_candidate']);
        $this->assertNotEmpty($result['repair_steps']);
    }

    public function test_all_non_runnable_acceptance_yields_fixable_missing_evidence(): void
    {
        $result = $this->loop->repair($this->input([
            'acceptance_criteria' => ['It should work fine', 'Tests should pass eventually'],
        ]));

        $this->assertSame(AtlasExternalBrainWeakOutputRepairLoop::CLASS_FIXABLE_MISSING_EVIDENCE, $result['failure_class']);
    }

    public function test_repaired_candidate_has_required_evidence_added(): void
    {
        $result = $this->loop->repair($this->input(['required_evidence' => []]));

        $this->assertNotEmpty($result['repaired_candidate']['required_evidence']);
    }

    public function test_repaired_candidate_has_runnable_criterion_added(): void
    {
        $result = $this->loop->repair($this->input([
            'acceptance_criteria' => ['should look good'],
            'required_evidence'   => ['tests_or_gates_result'],
        ]));

        $found = false;
        foreach ($result['repaired_candidate']['acceptance_criteria'] as $ac) {
            foreach (['phpunit', 'artisan', 'vendor/bin'] as $marker) {
                if (str_contains(strtolower($ac), $marker)) {
                    $found = true;
                }
            }
        }
        $this->assertTrue($found, 'Expected a runnable criterion to be injected');
    }

    // ── AC2: classification — fixable_scope_shape ────────────────────────────

    public function test_over_broad_scope_weakness_yields_fixable_scope_shape(): void
    {
        $result = $this->loop->repair($this->input([], ['weakness_labels' => ['over_broad_scope']]));

        $this->assertSame(AtlasExternalBrainWeakOutputRepairLoop::CLASS_FIXABLE_SCOPE_SHAPE, $result['failure_class']);
        $this->assertNotNull($result['repaired_candidate']);
    }

    public function test_missing_code_search_weakness_yields_fixable_scope_shape(): void
    {
        $result = $this->loop->repair($this->input([], ['weakness_labels' => ['missing_code_search']]));

        $this->assertSame(AtlasExternalBrainWeakOutputRepairLoop::CLASS_FIXABLE_SCOPE_SHAPE, $result['failure_class']);
    }

    public function test_weak_acceptance_weakness_yields_fixable_scope_shape(): void
    {
        $result = $this->loop->repair($this->input([], ['weakness_labels' => ['weak_acceptance']]));

        $this->assertSame(AtlasExternalBrainWeakOutputRepairLoop::CLASS_FIXABLE_SCOPE_SHAPE, $result['failure_class']);
    }

    // ── AC3: deterministic instructions only for fixable, refused for rest ───

    public function test_fixable_class_has_no_refusal_reason(): void
    {
        $result = $this->loop->repair($this->input(['required_evidence' => []]));

        $this->assertNull($result['refusal_reason']);
    }

    public function test_refused_class_has_no_repaired_candidate(): void
    {
        $result = $this->loop->repair($this->input([], ['is_poison' => true]));

        $this->assertNull($result['repaired_candidate']);
        $this->assertSame([], $result['repair_steps']);
    }

    // ── AC4: next_scaffold_constraint is always present ───────────────────────

    public function test_next_scaffold_constraint_is_non_empty_for_unrecoverable(): void
    {
        $result = $this->loop->repair($this->input([], ['is_poison' => true]));

        $this->assertNotEmpty($result['next_scaffold_constraint']);
    }

    public function test_next_scaffold_constraint_is_non_empty_for_fixable(): void
    {
        $result = $this->loop->repair($this->input(['required_evidence' => []]));

        $this->assertNotEmpty($result['next_scaffold_constraint']);
    }

    // ── Ordering: unrecoverable beats duplicate ───────────────────────────────

    public function test_unrecoverable_takes_precedence_over_duplicate(): void
    {
        $result = $this->loop->repair($this->input([], [
            'is_poison'    => true,
            'is_duplicate' => true,
        ]));

        $this->assertSame(AtlasExternalBrainWeakOutputRepairLoop::CLASS_UNRECOVERABLE, $result['failure_class']);
    }

    // ── Ordering: duplicate beats low_value ──────────────────────────────────

    public function test_duplicate_takes_precedence_over_low_value(): void
    {
        $result = $this->loop->repair($this->input([], [
            'is_duplicate'    => true,
            'weakness_labels' => ['template_farming'],
        ]));

        $this->assertSame(AtlasExternalBrainWeakOutputRepairLoop::CLASS_DUPLICATE_TARGET, $result['failure_class']);
    }

    // ── No silent repair of duplicate or low-value ────────────────────────────

    public function test_duplicate_is_never_silently_repaired(): void
    {
        $result = $this->loop->repair($this->input([], ['is_duplicate' => true]));

        $this->assertNull($result['repaired_candidate']);
        $this->assertSame([], $result['repair_steps']);
    }

    public function test_low_value_is_never_silently_repaired(): void
    {
        $result = $this->loop->repair($this->input([], ['weakness_labels' => ['template_farming']]));

        $this->assertNull($result['repaired_candidate']);
        $this->assertSame([], $result['repair_steps']);
    }

    // ── AC2: classification — fixable_missing_implementation_file ────────────

    public function test_empty_allowed_files_yields_fixable_missing_impl_file(): void
    {
        $result = $this->loop->repair($this->input(['allowed_files' => []]));

        $this->assertSame(
            AtlasExternalBrainWeakOutputRepairLoop::CLASS_FIXABLE_MISSING_IMPL_FILE,
            $result['failure_class'],
        );
        $this->assertNotNull($result['repaired_candidate']);
        $this->assertNotEmpty($result['repair_steps']);
    }

    public function test_only_test_files_in_allowed_files_yields_fixable_missing_impl_file(): void
    {
        $result = $this->loop->repair($this->input([
            'allowed_files' => ['tests/Unit/FooServiceTest.php'],
        ]));

        $this->assertSame(
            AtlasExternalBrainWeakOutputRepairLoop::CLASS_FIXABLE_MISSING_IMPL_FILE,
            $result['failure_class'],
        );
    }

    public function test_impl_file_with_test_file_does_not_yield_missing_impl_file(): void
    {
        // Mix of impl + test → NOT missing impl file
        $result = $this->loop->repair($this->input([
            'allowed_files' => ['app/Services/FooService.php', 'tests/Unit/FooServiceTest.php'],
        ]));

        $this->assertNotSame(
            AtlasExternalBrainWeakOutputRepairLoop::CLASS_FIXABLE_MISSING_IMPL_FILE,
            $result['failure_class'],
        );
    }

    public function test_repaired_candidate_has_suggested_implementation_file(): void
    {
        $result = $this->loop->repair($this->input(['allowed_files' => []]));

        $this->assertArrayHasKey('_suggested_implementation_file', $result['repaired_candidate']);
        $this->assertNotEmpty($result['repaired_candidate']['_suggested_implementation_file']);
    }

    // ── over_broad_scope narrows allowed_files ────────────────────────────────

    public function test_over_broad_scope_narrows_allowed_files(): void
    {
        $manyFiles = [
            'app/Services/A.php', 'app/Services/B.php', 'app/Services/C.php',
            'app/Services/D.php', 'app/Services/E.php',
        ];

        $result = $this->loop->repair($this->input(
            ['allowed_files' => $manyFiles],
            ['weakness_labels' => ['over_broad_scope']],
        ));

        $this->assertSame(AtlasExternalBrainWeakOutputRepairLoop::CLASS_FIXABLE_SCOPE_SHAPE, $result['failure_class']);
        $this->assertLessThanOrEqual(2, count($result['repaired_candidate']['allowed_files']));
    }

    // ── stale evidence / vague objective / low impact / proxy-fake-value ──────

    public function test_stale_evidence_weakness_yields_evidence_refresh(): void
    {
        $result = $this->loop->repair($this->input([], ['weakness_labels' => ['stale_evidence']]));

        $this->assertSame(AtlasExternalBrainWeakOutputRepairLoop::CLASS_STALE_EVIDENCE, $result['failure_class']);
        $this->assertSame(AtlasExternalBrainWeakOutputRepairLoop::REPAIR_ACTION_EVIDENCE_REFRESH, $result['repair_action']);
        $this->assertNull($result['refusal_reason']);
        $this->assertNotEmpty($result['repaired_candidate']);
    }

    public function test_vague_objective_weakness_yields_rewrite_acceptance(): void
    {
        $result = $this->loop->repair($this->input([], ['weakness_labels' => ['vague_objective']]));

        $this->assertSame(AtlasExternalBrainWeakOutputRepairLoop::CLASS_VAGUE_OBJECTIVE, $result['failure_class']);
        $this->assertSame(AtlasExternalBrainWeakOutputRepairLoop::REPAIR_ACTION_REWRITE_ACCEPTANCE, $result['repair_action']);
        $this->assertNull($result['refusal_reason']);
    }

    public function test_low_impact_weakness_yields_scope_tighten(): void
    {
        $result = $this->loop->repair($this->input([], ['weakness_labels' => ['low_impact']]));

        $this->assertSame(AtlasExternalBrainWeakOutputRepairLoop::CLASS_LOW_IMPACT, $result['failure_class']);
        $this->assertSame(AtlasExternalBrainWeakOutputRepairLoop::REPAIR_ACTION_SCOPE_TIGHTEN, $result['repair_action']);
        $this->assertNull($result['refusal_reason']);
    }

    public function test_proxy_proof_weakness_is_refused_with_durable_negative_result(): void
    {
        $result = $this->loop->repair($this->input([], ['weakness_labels' => ['proxy_proof']]));

        $this->assertSame(AtlasExternalBrainWeakOutputRepairLoop::CLASS_PROXY_OR_FAKE_VALUE, $result['failure_class']);
        $this->assertNull($result['repaired_candidate']);
        $this->assertNull($result['repair_action']);
        $this->assertNotEmpty($result['refusal_reason']);
    }

    public function test_fake_value_weakness_is_refused_with_durable_negative_result(): void
    {
        $result = $this->loop->repair($this->input([], ['weakness_labels' => ['fake_value']]));

        $this->assertSame(AtlasExternalBrainWeakOutputRepairLoop::CLASS_PROXY_OR_FAKE_VALUE, $result['failure_class']);
        $this->assertNull($result['repaired_candidate']);
    }

    public function test_proxy_proof_takes_precedence_over_low_value(): void
    {
        $result = $this->loop->repair($this->input([], ['weakness_labels' => ['proxy_proof', 'shallow_duplication']]));

        $this->assertSame(AtlasExternalBrainWeakOutputRepairLoop::CLASS_PROXY_OR_FAKE_VALUE, $result['failure_class']);
    }

    public function test_fixable_classes_always_have_repair_action_key(): void
    {
        $result = $this->loop->repair($this->input(['required_evidence' => []]));

        $this->assertArrayHasKey('repair_action', $result);
    }
}
