<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasEvolutionImplementationHandoffService;
use Tests\TestCase;

/**
 * Pins the documented Evolution Implementation Handoff rules.
 *
 * @see docs/engineering-knowledge-base/evolution/implementation-handoff.md
 */
class AtlasEvolutionImplementationHandoffTest extends TestCase
{
    private function service(): AtlasEvolutionImplementationHandoffService
    {
        return new AtlasEvolutionImplementationHandoffService();
    }

    /**
     * Canonical Order: with AP-99 and AP-100 done, the only work an agent may
     * open next is order 3 (AP-101 Retrieval Router), and it may proceed.
     */
    public function test_canonical_order_returns_first_not_done_item(): void
    {
        $d = $this->service()->nextWork([
            'ap_99_provider_performance_contract',
            'ap_100_context_pack_manifest_reflection',
        ]);

        $this->assertSame(AtlasEvolutionImplementationHandoffService::ORDER_PROCEED, $d['verdict']);
        $this->assertTrue($d['may_proceed']);
        $this->assertSame('ap_101_retrieval_router', $d['next_key']);
        $this->assertSame(3, $d['next_work']['order']);
        $this->assertSame(2, $d['completed_count']);
        $this->assertSame(9, $d['total']);
    }

    /**
     * Decision "Close one DoD before opening the next autonomy layer": claiming
     * a later item done while an earlier one is still open is an out-of-order
     * violation, and the agent may NOT proceed.
     */
    public function test_canonical_order_flags_out_of_order_completion(): void
    {
        $d = $this->service()->nextWork([
            'ap_99_provider_performance_contract',
            'ap_101_retrieval_router', // opened before AP-100 closed
        ]);

        $this->assertSame(AtlasEvolutionImplementationHandoffService::ORDER_OUT_OF_ORDER, $d['verdict']);
        $this->assertFalse($d['may_proceed']);
        $this->assertContains('ap_101_retrieval_router', $d['out_of_order']);
    }

    /**
     * Canonical Order: when all nine items are done the verdict is all_complete
     * and there is no next work.
     */
    public function test_canonical_order_all_complete(): void
    {
        $allKeys = array_column(
            AtlasEvolutionImplementationHandoffService::CANONICAL_ORDER,
            'key'
        );

        $d = $this->service()->nextWork($allKeys);

        $this->assertSame(AtlasEvolutionImplementationHandoffService::ORDER_COMPLETE, $d['verdict']);
        $this->assertTrue($d['all_complete']);
        $this->assertNull($d['next_work']);
        $this->assertSame(9, $d['completed_count']);
    }

    /**
     * Agent Handoff: a statement missing tests, validation commands and docs
     * updated is incomplete and names exactly those three missing fields.
     */
    public function test_agent_handoff_incomplete_lists_missing_fields(): void
    {
        $d = $this->service()->evaluateHandoff([
            'ap_or_child_doc' => 'AP-99 Provider Performance Contract',
            'contract_extended' => 'AtlasProviderPerformanceRoadmapService',
            'files_owned' => ['app/Services/Ai/Aaeos/Generated/Example.php'],
            'migrations_or_events' => 'none',
            // tests_added, validation_commands_run, docs_updated omitted
        ]);

        $this->assertSame(AtlasEvolutionImplementationHandoffService::HANDOFF_INCOMPLETE, $d['verdict']);
        $this->assertFalse($d['complete']);
        $this->assertSame(
            ['tests_added', 'validation_commands_run', 'docs_updated'],
            $d['missing']
        );
        $this->assertSame(4, $d['present_count']);
        $this->assertSame(7, $d['total']);
    }

    /**
     * Agent Handoff: all seven statements present (a value may be the string
     * 'none' for genuinely empty categories) yields a complete handoff.
     */
    public function test_agent_handoff_complete_when_all_seven_stated(): void
    {
        $d = $this->service()->evaluateHandoff([
            'ap_or_child_doc' => 'AP-101 Retrieval Router',
            'contract_extended' => 'AiContextPackBuilder',
            'files_owned' => ['a.php', 'b.php'],
            'migrations_or_events' => 'added retrieval_router_decided event',
            'tests_added' => ['RetrievalRouterTest'],
            'validation_commands_run' => 'docs-health, architecture-validate',
            'docs_updated' => 'evolution/README.md',
        ]);

        $this->assertSame(AtlasEvolutionImplementationHandoffService::HANDOFF_COMPLETE, $d['verdict']);
        $this->assertTrue($d['complete']);
        $this->assertSame([], $d['missing']);
        $this->assertSame(7, $d['present_count']);
    }

    /**
     * Done Means + forbidden_changes: a single structural blocker (a new
     * split_required blocker) keeps work not_done even when every other
     * criterion holds and all five validation commands are green.
     */
    public function test_done_means_split_required_blocker_forces_not_done(): void
    {
        $d = $this->service()->evaluateDoneMeans([
            'criteria' => [
                'no_split_required_blocker' => false, // a new split_required blocker exists
                'no_parallel_subsystem' => true,
                'canonical_index_points_to_child_doc' => true,
                'evidence_and_policy_explicit' => true,
                'override_and_autonomy_documented' => true,
            ],
            'validation' => [
                'architecture_validate' => true,
                'docs_health' => true,
                'knowledge_sync' => true,
                'index_code' => true,
                'git_diff_check' => true,
            ],
        ]);

        $this->assertSame(AtlasEvolutionImplementationHandoffService::NOT_DONE, $d['verdict']);
        $this->assertFalse($d['is_done']);
        $this->assertContains('split_required_blocker', $d['structural_blockers']);
        $this->assertContains('criterion_unmet:no_split_required_blocker', $d['reasons']);
    }

    /**
     * Done Means: all five DoD criteria satisfied AND all five validation
     * commands ok => done. A failing validation command alone reverts to
     * not_done (no readiness claim without green gates).
     */
    public function test_done_means_done_only_with_all_criteria_and_green_gates(): void
    {
        $criteria = [
            'no_split_required_blocker' => true,
            'no_parallel_subsystem' => true,
            'canonical_index_points_to_child_doc' => true,
            'evidence_and_policy_explicit' => true,
            'override_and_autonomy_documented' => true,
        ];
        $greenValidation = [
            'architecture_validate' => true,
            'docs_health' => true,
            'knowledge_sync' => ['status' => 'ok'],
            'index_code' => 'pass',
            'git_diff_check' => true,
        ];

        $done = $this->service()->evaluateDoneMeans([
            'criteria' => $criteria,
            'validation' => $greenValidation,
        ]);
        $this->assertSame(AtlasEvolutionImplementationHandoffService::DONE, $done['verdict']);
        $this->assertTrue($done['is_done']);
        $this->assertSame([], $done['reasons']);
        $this->assertTrue($this->service()->isDone([
            'criteria' => $criteria,
            'validation' => $greenValidation,
        ]));

        // Flip one gate red: the whole thing reverts to not_done.
        $redValidation = $greenValidation;
        $redValidation['git_diff_check'] = ['status' => 'fail'];
        $notDone = $this->service()->evaluateDoneMeans([
            'criteria' => $criteria,
            'validation' => $redValidation,
        ]);
        $this->assertSame(AtlasEvolutionImplementationHandoffService::NOT_DONE, $notDone['verdict']);
        $this->assertContains('git_diff_check', $notDone['failing_commands']);
    }

    /** Every decision is auditable and carries its stable receipt schema. */
    public function test_decisions_are_auditable_with_stable_schemas(): void
    {
        $this->assertSame(
            'atlas.evolution.implementation_handoff.order.v1',
            $this->service()->nextWork([])['schema']
        );
        $this->assertSame(
            'atlas.evolution.implementation_handoff.agent.v1',
            $this->service()->evaluateHandoff([])['schema']
        );
        $this->assertSame(
            'atlas.evolution.implementation_handoff.done.v1',
            $this->service()->evaluateDoneMeans([])['schema']
        );
    }
}
