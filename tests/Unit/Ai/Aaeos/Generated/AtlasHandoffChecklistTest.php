<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasHandoffChecklistService;
use Tests\TestCase;

/**
 * Pins the documented Legacy Cleanup Handoff Checklist rules: the six
 * Before-Final-Message items, the five Required Validation commands, the four
 * Stop Conditions (absolute override) and the Final Report Shape.
 *
 * @see docs/engineering-knowledge-base/legacy-cleanup/handoff-checklist.md
 */
class AtlasHandoffChecklistTest extends TestCase
{
    private function service(): AtlasHandoffChecklistService
    {
        return new AtlasHandoffChecklistService();
    }

    /**
     * A handoff with all six checklist items done AND all five validation
     * commands ok AND no stop condition => ready, may emit the final report.
     */
    public function test_full_checklist_and_green_validation_is_ready(): void
    {
        $r = $this->service()->evaluate([
            'checklist' => [
                'cleanup_wave_stated' => true,
                'active_docs_listed' => true,
                'archived_sources_listed' => true,
                'redirects_listed' => true,
                'runtime_scope_confirmed' => true,
                'no_vault_promotion_unreviewed' => true,
            ],
            'validation' => [
                'docs_health' => true,
                'architecture_validate' => true,
                'diff_check' => true,
                'sync' => true,
                'index_code' => true,
            ],
            'stop_conditions' => [],
            'split_required_count' => 0,
        ]);

        $this->assertSame(AtlasHandoffChecklistService::VERDICT_READY, $r['verdict']);
        $this->assertSame(AtlasHandoffChecklistService::NEXT_EMIT_FINAL_REPORT, $r['required_next_action']);
        $this->assertTrue($r['may_emit_final_report']);
        $this->assertSame(6, $r['checklist']['done_count']);
        $this->assertSame(5, $r['validation']['ok_count']);
        $this->assertTrue($r['cleanup_complete']);
    }

    /**
     * "Stop and report if: a source contains sensitive personal material."
     * Even when the checklist is fully done and validation is green, a raised
     * stop condition is absolute => verdict=stop, next=stop_and_report.
     */
    public function test_stop_condition_overrides_complete_checklist(): void
    {
        $r = $this->service()->evaluate([
            'checklist' => [
                'cleanup_wave_stated' => true,
                'active_docs_listed' => true,
                'archived_sources_listed' => true,
                'redirects_listed' => true,
                'runtime_scope_confirmed' => true,
                'no_vault_promotion_unreviewed' => true,
            ],
            'validation' => [
                'docs_health' => true,
                'architecture_validate' => true,
                'diff_check' => true,
                'sync' => true,
                'index_code' => true,
            ],
            'stop_conditions' => [
                'sensitive_personal_material' => true,
            ],
        ]);

        $this->assertSame(AtlasHandoffChecklistService::VERDICT_STOP, $r['verdict']);
        $this->assertSame(AtlasHandoffChecklistService::NEXT_STOP_AND_REPORT, $r['required_next_action']);
        $this->assertFalse($r['may_emit_final_report']);
        $this->assertContains('sensitive_personal_material', $r['stop_conditions']['triggered']);
        $this->assertContains('stop:sensitive_personal_material', $r['reasons']);
    }

    /**
     * A delete candidate that still has live references is a Stop Condition,
     * and the predicate must refuse the final report.
     */
    public function test_live_reference_to_delete_candidate_stops(): void
    {
        $handoff = [
            'checklist' => array_fill_keys([
                'cleanup_wave_stated', 'active_docs_listed', 'archived_sources_listed',
                'redirects_listed', 'runtime_scope_confirmed', 'no_vault_promotion_unreviewed',
            ], true),
            'validation' => array_fill_keys([
                'docs_health', 'architecture_validate', 'diff_check', 'sync', 'index_code',
            ], true),
            'stop_conditions' => ['live_reference_to_delete_candidate' => true],
        ];

        $this->assertFalse($this->service()->mayEmitFinalReport($handoff));
        $this->assertSame(
            AtlasHandoffChecklistService::VERDICT_STOP,
            $this->service()->evaluate($handoff)['verdict'],
        );
    }

    /**
     * "Required Validation": every command must report ok. One failing command
     * (here architecture-validate) that is still related to the cleanup keeps
     * the handoff incomplete (not stop) and lists the failing command.
     */
    public function test_one_failing_validation_command_blocks_as_incomplete(): void
    {
        $r = $this->service()->evaluate([
            'checklist' => array_fill_keys([
                'cleanup_wave_stated', 'active_docs_listed', 'archived_sources_listed',
                'redirects_listed', 'runtime_scope_confirmed', 'no_vault_promotion_unreviewed',
            ], true),
            'validation' => [
                'docs_health' => true,
                'architecture_validate' => ['status' => 'fail', 'related_to_cleanup' => true],
                'diff_check' => true,
                'sync' => true,
                'index_code' => true,
            ],
        ]);

        $this->assertSame(AtlasHandoffChecklistService::VERDICT_INCOMPLETE, $r['verdict']);
        $this->assertSame(AtlasHandoffChecklistService::NEXT_COMPLETE_CHECKLIST, $r['required_next_action']);
        $this->assertFalse($r['validation']['all_ok']);
        $this->assertSame(['architecture_validate'], $r['validation']['failing']);
        $this->assertContains('validation_not_ok:architecture_validate', $r['reasons']);
    }

    /**
     * "validation fails in a way unrelated to the cleanup" is itself a Stop
     * Condition. A failing command flagged related_to_cleanup=false must be
     * derived into the stop verdict even without an explicit stop flag.
     */
    public function test_unrelated_validation_failure_is_derived_stop(): void
    {
        $r = $this->service()->evaluate([
            'checklist' => array_fill_keys([
                'cleanup_wave_stated', 'active_docs_listed', 'archived_sources_listed',
                'redirects_listed', 'runtime_scope_confirmed', 'no_vault_promotion_unreviewed',
            ], true),
            'validation' => [
                'docs_health' => true,
                'architecture_validate' => true,
                'diff_check' => true,
                'sync' => ['status' => 'fail', 'related_to_cleanup' => false],
                'index_code' => true,
            ],
        ]);

        $this->assertSame(AtlasHandoffChecklistService::VERDICT_STOP, $r['verdict']);
        $this->assertContains('unrelated_validation_failure', $r['stop_conditions']['triggered']);
    }

    /**
     * Empty handoff (safe default, as the command runs): no item checked, no
     * validation ran => incomplete, all six items missing, all five commands
     * failing, and the final report is refused.
     */
    public function test_empty_handoff_is_incomplete_with_all_items_missing(): void
    {
        $r = $this->service()->evaluate([]);

        $this->assertSame(AtlasHandoffChecklistService::VERDICT_INCOMPLETE, $r['verdict']);
        $this->assertFalse($r['may_emit_final_report']);
        $this->assertCount(6, $r['checklist']['missing']);
        $this->assertSame(0, $r['validation']['ok_count']);
        $this->assertSame(5, count($r['validation']['failing']));
    }

    /**
     * A ready handoff that still has N split_required docs is ready to hand off
     * but NOT cleanup_complete; the Final Report Shape surfaces the remaining
     * count in its "Restante" line exactly as the doc template requires.
     */
    public function test_ready_with_remaining_split_required_is_not_complete(): void
    {
        $r = $this->service()->evaluate([
            'checklist' => array_fill_keys([
                'cleanup_wave_stated', 'active_docs_listed', 'archived_sources_listed',
                'redirects_listed', 'runtime_scope_confirmed', 'no_vault_promotion_unreviewed',
            ], true),
            'validation' => array_fill_keys([
                'docs_health', 'architecture_validate', 'diff_check', 'sync', 'index_code',
            ], true),
            'split_required_count' => 3,
        ]);

        $this->assertSame(AtlasHandoffChecklistService::VERDICT_READY, $r['verdict']);
        $this->assertSame(3, $r['split_required_count']);
        $this->assertFalse($r['cleanup_complete']);

        $shape = $this->service()->finalReportShape([
            'compacted' => 'X',
            'preserved' => 'Y',
            'child_docs' => 2,
            'split_required_count' => 3,
        ]);
        $this->assertSame(['3 docs split_required'], $shape['restante']);
        $this->assertSame('criei 2 child docs', $shape['concluido'][2]);
    }
}
