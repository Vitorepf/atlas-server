<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskQuality;

use App\Services\Ai\SelfConstruction\TaskQuality\AtlasTaskRespecPlanBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasTaskRespecPlanBuilder: clean facts ⇒ keep; hidden poison ⇒ give_back_hint;
 * too_many_deficiencies ⇒ quarantine_candidate; contradictory_acceptance ⇒ rewrite_objective;
 * missing_files ⇒ add_missing_allowed_file_candidate; too_broad_scope ⇒ split_task_candidate;
 * autonomy_regression ⇒ split_task_candidate with replaces_authority='non_atlas_native' (Atlas-native
 * replacement, never human dependency).
 */
final class AtlasTaskRespecPlanBuilderTest extends TestCase
{
    public function test_clean_facts_yield_keep(): void
    {
        $r = (new AtlasTaskRespecPlanBuilder)->build(['packet_id' => 'p-clean']);
        $this->assertSame(AtlasTaskRespecPlanBuilder::ACTION_KEEP, $r['action']);
    }

    public function test_too_many_deficiencies_yield_quarantine(): void
    {
        $r = (new AtlasTaskRespecPlanBuilder)->build(['packet_id' => 'p-too-many', 'too_many_deficiencies' => true]);
        $this->assertSame(AtlasTaskRespecPlanBuilder::ACTION_QUARANTINE, $r['action']);
    }

    public function test_cli_clobber_yields_quarantine(): void
    {
        $r = (new AtlasTaskRespecPlanBuilder)->build(['packet_id' => 'p-cli', 'cli_clobber' => true]);
        $this->assertSame(AtlasTaskRespecPlanBuilder::ACTION_QUARANTINE, $r['action']);
    }

    public function test_contradictory_acceptance_yields_rewrite_objective(): void
    {
        $r = (new AtlasTaskRespecPlanBuilder)->build(['packet_id' => 'p-c', 'contradictory_acceptance' => true]);
        $this->assertSame(AtlasTaskRespecPlanBuilder::ACTION_REWRITE_OBJECTIVE, $r['action']);
    }

    public function test_missing_files_yield_add_missing_allowed_file_candidate(): void
    {
        $r = (new AtlasTaskRespecPlanBuilder)->build(['packet_id' => 'p-m', 'missing_files' => ['app/X.php']]);
        $this->assertSame(AtlasTaskRespecPlanBuilder::ACTION_ADD_FILE, $r['action']);
    }

    public function test_autonomy_regression_yields_split_candidate_with_atlas_native_replacement(): void
    {
        $r = (new AtlasTaskRespecPlanBuilder)->build(['packet_id' => 'p-ar', 'autonomy_regression' => true]);
        $this->assertSame(AtlasTaskRespecPlanBuilder::ACTION_SPLIT, $r['action']);
        $this->assertSame('non_atlas_native', $r['replaces_authority']);
    }

    public function test_too_broad_scope_yields_split_candidate(): void
    {
        $r = (new AtlasTaskRespecPlanBuilder)->build(['packet_id' => 'p-b', 'hidden_poison_facts' => ['too_broad_scope']]);
        $this->assertSame(AtlasTaskRespecPlanBuilder::ACTION_SPLIT, $r['action']);
    }

    public function test_named_hidden_poison_yields_give_back_hint(): void
    {
        $r = (new AtlasTaskRespecPlanBuilder)->build(['packet_id' => 'p-h', 'hidden_poison_facts' => ['stale_dependency']]);
        $this->assertSame(AtlasTaskRespecPlanBuilder::ACTION_GIVE_BACK, $r['action']);
    }

    public function test_forbidden_petreo_missing_files_yield_quarantine_candidate(): void
    {
        $r = (new AtlasTaskRespecPlanBuilder)->build([
            'packet_id' => 'p-forbidden',
            'missing_files' => ['app/Services/Ai/Brain/SomePetreoFile.php'],
            'forbidden_missing_files' => ['app/Services/Ai/Brain/SomePetreoFile.php'],
        ]);
        $this->assertSame(AtlasTaskRespecPlanBuilder::ACTION_QUARANTINE, $r['action']);
        $this->assertStringContainsString('forbidden_or_petreo_file', $r['rationale']);
        $this->assertContains('allowed_files', $r['affected_fields']);
    }

    public function test_safe_impl_test_pair_yields_add_missing_allowed_file_candidate(): void
    {
        $r = (new AtlasTaskRespecPlanBuilder)->build([
            'packet_id' => 'p-safe',
            'missing_files' => ['app/Services/Ai/AtlasFoo.php', 'tests/Unit/Ai/AtlasFooTest.php'],
            'forbidden_missing_files' => [],
        ]);
        $this->assertSame(AtlasTaskRespecPlanBuilder::ACTION_ADD_FILE, $r['action']);
        $this->assertContains('/opt/homebrew/bin/php artisan test', $r['revalidation_gates']);
    }

    public function test_contradictory_acceptance_emits_affected_fields_and_runnable_php_gate(): void
    {
        $r = (new AtlasTaskRespecPlanBuilder)->build([
            'packet_id' => 'p-contr',
            'contradictory_acceptance' => true,
            'contradicting_fields' => ['acceptance_criteria', 'allowed_files'],
        ]);
        $this->assertSame(AtlasTaskRespecPlanBuilder::ACTION_REWRITE_OBJECTIVE, $r['action']);
        $this->assertContains('acceptance_criteria', $r['affected_fields']);
        $this->assertContains('allowed_files', $r['affected_fields']);
        $this->assertContains('/opt/homebrew/bin/php artisan test', $r['revalidation_gates']);
    }

    public function test_autonomy_regression_never_introduces_human_operator_or_provider_language(): void
    {
        $r = (new AtlasTaskRespecPlanBuilder)->build(['packet_id' => 'p-ar2', 'autonomy_regression' => true]);
        $this->assertSame(AtlasTaskRespecPlanBuilder::ACTION_SPLIT, $r['action']);
        $this->assertSame('non_atlas_native', $r['replaces_authority']);
        $this->assertContains('/opt/homebrew/bin/php artisan test', $r['revalidation_gates']);
        $forbidden = ['human', 'operator', 'external_provider', 'requires_human', 'requires_operator'];
        foreach ($forbidden as $word) {
            $this->assertStringNotContainsStringIgnoringCase($word, $r['rationale'],
                "autonomy_regression rationale must not mention '{$word}'");
        }
    }

    // ── AC1: blocked_reason_mapping, proof_requirements, rollback_plan, validation_gates ──

    public function test_ready_plan_includes_all_new_ac_fields(): void
    {
        $r = (new AtlasTaskRespecPlanBuilder)->build([
            'packet_id' => 'p-ready',
            'contradictory_acceptance' => true,
            'source_evidence' => ['doc:some-evidence'],
            'implementation_target' => 'app/Services/Ai/AtlasFoo.php',
        ]);

        $this->assertArrayHasKey('blocked_reason_mapping', $r);
        $this->assertArrayHasKey('proof_requirements', $r);
        $this->assertArrayHasKey('rollback_plan', $r);
        $this->assertArrayHasKey('validation_gates', $r);
        $this->assertSame(
            AtlasTaskRespecPlanBuilder::ACTION_REWRITE_OBJECTIVE,
            $r['blocked_reason_mapping']['contradictory_acceptance'],
        );
        $this->assertNotEmpty($r['proof_requirements']);
        $this->assertArrayHasKey('description', $r['rollback_plan']);
        $this->assertArrayHasKey('requires_confirmation', $r['rollback_plan']);
        $this->assertSame($r['revalidation_gates'], $r['validation_gates']);
        $this->assertSame(AtlasTaskRespecPlanBuilder::STATUS_READY, $r['status']);
    }

    // ── AC2: not_ready when source evidence or implementation target is missing ──

    public function test_missing_source_evidence_marks_plan_not_ready(): void
    {
        $r = (new AtlasTaskRespecPlanBuilder)->build([
            'packet_id' => 'p-no-evidence',
            'contradictory_acceptance' => true,
            'source_evidence' => [],
            'implementation_target' => 'app/Services/Ai/AtlasFoo.php',
        ]);

        $this->assertSame(AtlasTaskRespecPlanBuilder::STATUS_NOT_READY, $r['status']);
    }

    public function test_missing_implementation_target_marks_plan_not_ready(): void
    {
        $r = (new AtlasTaskRespecPlanBuilder)->build([
            'packet_id' => 'p-no-target',
            'contradictory_acceptance' => true,
            'source_evidence' => ['doc:some-evidence'],
            'implementation_target' => '',
        ]);

        $this->assertSame(AtlasTaskRespecPlanBuilder::STATUS_NOT_READY, $r['status']);
    }

    public function test_keep_action_is_always_ready_regardless_of_evidence(): void
    {
        $r = (new AtlasTaskRespecPlanBuilder)->build(['packet_id' => 'p-keep']);

        $this->assertSame(AtlasTaskRespecPlanBuilder::ACTION_KEEP, $r['action']);
        $this->assertSame(AtlasTaskRespecPlanBuilder::STATUS_READY, $r['status']);
    }

    // ── AC3: high-risk rollback requirements ──────────────────────────────────

    public function test_quarantine_rollback_requires_confirmation(): void
    {
        $r = (new AtlasTaskRespecPlanBuilder)->build(['packet_id' => 'p-q', 'too_many_deficiencies' => true]);

        $this->assertTrue($r['rollback_plan']['requires_confirmation']);
        $this->assertNotEmpty($r['rollback_plan']['description']);
    }

    public function test_split_rollback_requires_confirmation(): void
    {
        $r = (new AtlasTaskRespecPlanBuilder)->build(['packet_id' => 'p-split', 'hidden_poison_facts' => ['too_broad_scope']]);

        $this->assertTrue($r['rollback_plan']['requires_confirmation']);
    }

    public function test_add_file_rollback_does_not_require_confirmation(): void
    {
        $r = (new AtlasTaskRespecPlanBuilder)->build(['packet_id' => 'p-add', 'missing_files' => ['app/X.php']]);

        $this->assertFalse($r['rollback_plan']['requires_confirmation']);
    }
}
