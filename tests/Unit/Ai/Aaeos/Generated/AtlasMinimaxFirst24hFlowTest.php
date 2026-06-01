<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasMinimaxFirst24hFlowService;
use Tests\TestCase;

/**
 * Pins the canonical decision rules of the MiniMax-First 24h flow contract:
 * the writer gate ("Sem ownership_map, allowed_files e output_contract, nenhum
 * writer pode editar"), the worker model (logical agents unbounded, LLM workers
 * clamped to budget, one writer lease per ownership scope), DeepSeek escalation
 * ("parece mais facil" is a hard stop; only the closed set of technical reasons
 * is valid), the premium-judge non-24h-worker rule, the Hard Stops, and the
 * retry rule "encerrar retry quando a mesma falha reaparecer sem nova evidencia".
 * Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/atlas-minimax-first-24h-flow-v1.md
 */
class AtlasMinimaxFirst24hFlowTest extends TestCase
{
    private function service(): AtlasMinimaxFirst24hFlowService
    {
        return new AtlasMinimaxFirst24hFlowService;
    }

    public function test_writer_is_blocked_without_ownership_map_allowed_files_and_output_contract(): void
    {
        // "Work Plan Obrigatorio": without the three gate fields no writer edits.
        $svc = $this->service();

        $blocked = $svc->decideWorkPlan([
            'touches_code' => true,
            'wants_writer' => true,
            'work_plan' => ['ownership_map' => ['mod' => 'owner']], // allowed_files + output_contract missing
        ]);
        $this->assertFalse($blocked['writer_admitted']);
        $this->assertSame(['allowed_files', 'output_contract'], $blocked['missing_writer_gate_fields']);
        $this->assertFalse($blocked['can_proceed']);

        // With all three present (plus the rest of the work plan), the writer is admitted.
        $admitted = $svc->decideWorkPlan([
            'touches_code' => true,
            'wants_writer' => true,
            'work_plan' => [
                'objective' => 'o', 'repo_scope' => 'r', 'source_refs' => ['s'], 'shards' => ['a'],
                'ownership_map' => ['m' => 'o'], 'must_keep' => ['k'], 'context_pack_hash' => 'h',
                'logical_agents' => ['x'], 'llm_worker_budget' => 2, 'writer_lease_plan' => ['p'],
                'output_contract' => 'small_patch', 'tests_required' => ['t'],
                'deepseek_escalation_conditions' => ['c'], 'rollback_ref' => 'rb',
                'allowed_files' => ['app/Foo.php'],
            ],
        ]);
        $this->assertTrue($admitted['writer_admitted']);
        $this->assertSame([], $admitted['missing_writer_gate_fields']);
        $this->assertTrue($admitted['work_plan_complete']);
        $this->assertTrue($admitted['can_proceed']);
    }

    public function test_deficit_pattern_maps_to_canonical_implementation_and_forces_shard_plan(): void
    {
        // "Deficit Translation Table" — huge-repo-single-call => index/shards/etc.
        $r = $this->service()->decideWorkPlan([
            'touches_code' => false,
            'wants_writer' => false,
            'deficit_patterns' => ['huge_repo_single_call', 'giant_long_output'],
            'work_plan' => [],
        ]);

        $this->assertTrue($r['work_plan_required']);
        $this->assertSame(['huge_repo_single_call', 'giant_long_output'], $r['matched_deficits']);
        $this->assertSame(
            'index_shards_summaries_ownership_map_context_pack',
            $r['deficit_implementations']['huge_repo_single_call'],
        );
        $this->assertSame(
            'small_patch_small_spec_verifiable_diff',
            $r['deficit_implementations']['giant_long_output'],
        );
    }

    public function test_worker_model_logical_unbounded_workers_clamped_to_budget_one_writer_per_scope(): void
    {
        // "Worker Model": many logical agents, few LLM workers, one writer/scope.
        $r = $this->service()->classifyWorkerPool([
            'logical_agents' => 20,
            'llm_worker_budget' => 2,
            'requested_workers' => 8,      // wants 8, budget is 2
            'ownership_scopes' => ['mod_a', 'mod_b'],
            'requested_writers' => 3,      // 3 writers over 2 distinct scopes => collision
        ]);

        $this->assertFalse($r['logical_agents_bounded']); // logical agents never clamped
        $this->assertSame(20, $r['logical_agents']);
        $this->assertSame(2, $r['admitted_llm_workers']); // clamped to budget
        $this->assertTrue($r['llm_worker_throttled']);
        $this->assertSame(2, $r['admitted_writers']);     // one lease per distinct scope
        $this->assertTrue($r['writer_scope_collision']);
    }

    public function test_deepseek_escalation_blocks_easier_reason_but_allows_a_canonical_reason(): void
    {
        $svc = $this->service();

        // Hard Stop: "DeepSeek for usado porque 'parece mais facil', sem escalation reason."
        $easier = $svc->decideDeepSeekEscalation([
            'reason' => 'it_seems_easier',
            'consumes_context_pack' => true,
        ]);
        $this->assertFalse($easier['allowed']);
        $this->assertFalse($easier['reason_valid']);

        // A canonical technical reason that consumes the Atlas context pack is admitted.
        $valid = $svc->decideDeepSeekEscalation([
            'reason' => 'single_window_500k_1m_tokens',
            'consumes_context_pack' => true,
        ]);
        $this->assertTrue($valid['allowed']);
        $this->assertTrue($valid['reason_valid']);

        // Even a valid reason is rejected if it would feed DeepSeek a raw dump
        // ("Ele nao deve receber dump bruto como padrao.").
        $rawDump = $svc->decideDeepSeekEscalation([
            'reason' => 'single_window_500k_1m_tokens',
            'consumes_context_pack' => false,
        ]);
        $this->assertFalse($rawDump['allowed']);
        $this->assertContains('deepseek_blocked_raw_dump_requires_context_pack', $rawDump['reasons']);
    }

    public function test_premium_judges_may_never_be_a_24h_worker_but_minimax_is_the_worker(): void
    {
        // "Provider Roles": "Codex e Claude premium nao sao workers 24/7."
        $svc = $this->service();

        $minimax = $svc->providerRole('minimax-m2.7');
        $this->assertSame('worker_24h', $minimax['role']);
        $this->assertTrue($minimax['may_run_as_24h_worker']);

        foreach (['codex-gpt-5.5', 'claude'] as $premium) {
            $role = $svc->providerRole($premium);
            $this->assertSame('premium_judge', $role['role']);
            $this->assertFalse($role['may_run_as_24h_worker'], "{$premium} must not be a 24h worker");
        }
    }

    public function test_hard_stops_block_cycle_and_retry_stops_on_repeated_failure_without_new_evidence(): void
    {
        $svc = $this->service();

        // "Hard Stops": any single triggered condition blocks the cycle.
        $stop = $svc->evaluateHardStops([
            'multiple_writers_same_scope' => true,
            'deepseek_without_reason' => true,
        ]);
        $this->assertTrue($stop['blocked']);
        $this->assertSame(
            ['multiple_writers_same_scope', 'deepseek_without_reason'],
            $stop['triggered'],
        );

        $clean = $svc->evaluateHardStops([]);
        $this->assertFalse($clean['blocked']);

        // AI rule: "Encerrar retry quando a mesma falha reaparecer sem nova evidencia."
        $repeated = $svc->decideRetry([
            'attempt' => 2,
            'max_attempts' => 3,
            'same_failure_signature' => true,
            'new_evidence' => false,
        ]);
        $this->assertFalse($repeated['may_retry']);
        $this->assertContains('repeated_failure_without_new_evidence', $repeated['reasons']);

        // Same signature but with NEW evidence (and budget left) may retry.
        $withEvidence = $svc->decideRetry([
            'attempt' => 2,
            'max_attempts' => 3,
            'same_failure_signature' => true,
            'new_evidence' => true,
        ]);
        $this->assertTrue($withEvidence['may_retry']);
    }

    public function test_flow_steps_are_ordered_map_then_reduce_then_cross_check_then_write_lease(): void
    {
        // "Fluxo" steps 1..10: Map(4) -> Reduce(5) -> Cross-check(6) -> Write-lease(7) -> Verify(8).
        $steps = $this->service()->flowSteps();

        $this->assertCount(10, $steps);
        $this->assertSame('intake', $steps[0]);
        $this->assertSame('evidence', $steps[9]);

        $order = array_flip($steps);
        $this->assertLessThan($order['reduce'], $order['map']);
        $this->assertLessThan($order['cross_check'], $order['reduce']);
        $this->assertLessThan($order['write_lease'], $order['cross_check']);
        $this->assertLessThan($order['verify'], $order['write_lease']);
    }
}
