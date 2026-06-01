<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasDevFlowMapProductOptionsV1Part01Service;
use Tests\TestCase;

/**
 * Pins the SETTLED rules of the flow/product map recorte (Parte 1): the
 * golden-rule arena gate (six preconditions), the order-of-battle final-step
 * gate, the honest completion state machine (passed requires evidence),
 * risk-based spec routing and the context-diary mandatory-field schema.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1-part-01.md
 */
class AtlasDevFlowMapProductOptionsV1Part01Test extends TestCase
{
    private function service(): AtlasDevFlowMapProductOptionsV1Part01Service
    {
        return new AtlasDevFlowMapProductOptionsV1Part01Service();
    }

    /**
     * "Regra De Ouro": the measurement arena may change ONLY when all six
     * preconditions hold. A partial set is blocked with `golden_rule_not_yet` and
     * the exact missing preconditions are reported; the full set unlocks it.
     */
    public function test_golden_rule_requires_all_six_preconditions(): void
    {
        $s = $this->service();

        $partial = $s->goldenRuleGate([
            'execution_contract' => true,
            'reproducible_local_results' => true,
            // four still missing
        ]);
        $this->assertFalse($partial['may_change_arena']);
        $this->assertSame('golden_rule_not_yet', $partial['reason']);
        $this->assertContains('selected_battery_tasks', $partial['missing']);
        $this->assertContains('quality_cost_time_metrics', $partial['missing']);
        $this->assertContains('escalation_policy', $partial['missing']);
        $this->assertContains('comparison_criterion', $partial['missing']);

        $full = $s->goldenRuleGate([
            'execution_contract' => true,
            'reproducible_local_results' => true,
            'selected_battery_tasks' => true,
            'quality_cost_time_metrics' => true,
            'escalation_policy' => true,
            'comparison_criterion' => true,
        ]);
        $this->assertTrue($full['may_change_arena']);
        $this->assertSame([], $full['missing']);
        $this->assertSame('all_six_golden_rule_preconditions_met', $full['reason']);
    }

    /**
     * "Ordem De Batalha": preparing for the arena (step 7) must not start until
     * steps 1..6 are done. With only 1..5 complete it is blocked and the 6th step
     * is the named remaining prerequisite; with all six done it unlocks.
     */
    public function test_order_of_battle_gates_arena_prep_until_first_six_done(): void
    {
        $s = $this->service();

        $blocked = $s->orderOfBattleGate([
            'understand_current_atlas_dev_flows',
            'receive_user_contexts_and_record_value',
            'extract_product_and_architecture_principles',
            'turn_principles_into_testable_hypotheses',
            'turn_hypotheses_into_small_atlas_dev_changes',
        ]);
        $this->assertFalse($blocked['may_prepare_arena']);
        $this->assertSame(
            ['measure_locally_against_representative_tasks'],
            $blocked['remaining_prerequisites']
        );

        $ready = $s->orderOfBattleGate([
            'understand_current_atlas_dev_flows',
            'receive_user_contexts_and_record_value',
            'extract_product_and_architecture_principles',
            'turn_principles_into_testable_hypotheses',
            'turn_hypotheses_into_small_atlas_dev_changes',
            'measure_locally_against_representative_tasks',
        ]);
        $this->assertTrue($ready['may_prepare_arena']);
        $this->assertSame([], $ready['remaining_prerequisites']);
    }

    /**
     * Context 2 honest completion: `passed` only exists WITH evidence. A claimed
     * `passed` without evidence is downgraded to needs_review and is not a
     * success; with evidence it is a real success. An unknown state coerces to
     * `failed`.
     */
    public function test_passed_requires_evidence_else_downgraded(): void
    {
        $s = $this->service();

        $noEvidence = $s->honestCompletion('passed', false);
        $this->assertSame('needs_review', $noEvidence['state']);
        $this->assertFalse($noEvidence['is_success']);
        $this->assertTrue($noEvidence['downgraded']);

        $withEvidence = $s->honestCompletion('passed', true);
        $this->assertSame('passed', $withEvidence['state']);
        $this->assertTrue($withEvidence['is_success']);
        $this->assertFalse($withEvidence['downgraded']);

        $unknown = $s->honestCompletion('looks_good', true);
        $this->assertSame('failed', $unknown['state']);
        $this->assertFalse($unknown['is_success']);
    }

    /**
     * needs_review, failed and escalate_forge are valid honest states but never
     * full successes (only `passed` with evidence is).
     */
    public function test_non_passed_states_are_never_full_success(): void
    {
        $s = $this->service();

        foreach (['needs_review', 'failed', 'escalate_forge'] as $state) {
            $verdict = $s->honestCompletion($state, true);
            $this->assertSame($state, $verdict['state'], "{$state} should be preserved");
            $this->assertFalse($verdict['is_success'], "{$state} must not be a full success");
        }
    }

    /**
     * Context 2 intake: spec strength is proportional to risk. Low risk → light
     * spec; R3 → strong spec; R4/R5 → strong spec AND plan-only. A spec is always
     * required regardless of risk.
     */
    public function test_spec_routing_scales_with_risk(): void
    {
        $s = $this->service();

        $low = $s->specRoutingFor('R1');
        $this->assertSame('light', $low['spec_strength']);
        $this->assertTrue($low['spec_required']);
        $this->assertFalse($low['plan_only']);

        $elevated = $s->specRoutingFor('R3');
        $this->assertSame('strong', $elevated['spec_strength']);
        $this->assertFalse($elevated['plan_only']);

        $high = $s->specRoutingFor('R5');
        $this->assertSame('strong', $high['spec_strength']);
        $this->assertTrue($high['plan_only']);
        $this->assertTrue($high['spec_required']);
    }

    /**
     * "Diario De Contexto Da Sessao": an entry is complete only when every one of
     * the ten mandatory fields is a non-empty string. A two-field stub is
     * incomplete and reports the missing fields; a full entry is complete.
     */
    public function test_context_entry_requires_all_mandatory_fields(): void
    {
        $s = $this->service();

        $stub = $s->contextEntryComplete([
            'fonte' => 'conversa',
            'texto_recebido' => 'ideia',
        ]);
        $this->assertFalse($stub['complete']);
        $this->assertContains('valor_estrategico', $stub['missing_fields']);
        $this->assertContains('backlog_candidato', $stub['missing_fields']);
        $this->assertContains('hipoteses', $stub['missing_fields']);

        $full = $s->contextEntryComplete([
            'fonte' => 'conversa anterior',
            'texto_recebido' => 'tres velocidades de execucao',
            'valor_estrategico' => 'separa diario de enterprise',
            'muda_na_tese' => 'vitoria vem do ciclo completo',
            'fluxos_afetados' => 'intake, open brain, orchestrator',
            'hipoteses' => 'contexto selecionado vence modelo puro',
            'experimentos' => 'bateria local de tarefas',
            'decisoes' => 'fast path e o modo diario',
            'riscos' => 'fast path virar heavy path caro',
            'backlog_candidato' => 'budget de chamadas por task',
        ]);
        $this->assertTrue($full['complete']);
        $this->assertSame([], $full['missing_fields']);
    }
}
