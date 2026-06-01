<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasMetaSddContractService;
use Tests\TestCase;

/**
 * Pins the executable contract from the doc: the 14 required meta_spec fields,
 * the L0..L7 layer ladder (L7 autonomy-sensitive), the 9 required questions, the
 * 12-stage strict Meta-SDD flow (no skip), the 5 prohibitions ("ANY violated
 * blocks"), the 4-condition Minimal Meta-SDD Exception ("ALL must hold"), and the
 * top-level gate that blocks a risky change lacking a rollback. Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/self-construction/meta-sdd-contract.md
 */
class AtlasMetaSddContractTest extends TestCase
{
    private function service(): AtlasMetaSddContractService
    {
        return new AtlasMetaSddContractService;
    }

    /** A fully populated, valid 14-field meta-spec. */
    private function fullMetaSpec(): array
    {
        return [
            'id' => 'meta-spec-1',
            'title' => 'A Meta-SDD',
            'target_layer' => 'l1',
            'target_capability' => 'kernel',
            'current_maturity' => 'L2',
            'target_maturity' => 'L3',
            'problem' => 'a gap',
            'goal' => 'close it',
            'non_goals' => 'no autonomy change',
            'dependencies' => 'evidence ledger',
            'affected_authority_docs' => 'doc.md',
            'affected_runtime_components' => 'kernel',
            'risk_level' => 'low',
            'autonomy_allowed' => 'advisory',
            'rollback_strategy' => 'revert commit',
            'evidence_required' => 'docs-health',
        ];
    }

    public function test_meta_spec_requires_all_sixteen_documented_fields(): void
    {
        // "Required Meta-Spec Fields": the meta_spec block lists 16 keys; all must
        // be present and non-empty or the spec is incomplete.
        $svc = $this->service();
        $this->assertCount(16, AtlasMetaSddContractService::META_SPEC_FIELDS);

        $this->assertTrue($svc->validateMetaSpec($this->fullMetaSpec())['complete']);

        $missingRollback = $this->fullMetaSpec();
        $missingRollback['rollback_strategy'] = '';   // blank counts as missing
        $r = $svc->validateMetaSpec($missingRollback);
        $this->assertFalse($r['complete']);
        $this->assertSame(['rollback_strategy'], $r['missing_fields']);
        $this->assertSame(15, $r['provided_count']);
    }

    public function test_layer_ladder_is_l0_to_l7_and_only_l7_is_autonomy_sensitive(): void
    {
        // "Layer Classification": L0..L7, with L7 = Autonomy/Self-Programming.
        $svc = $this->service();
        $this->assertCount(8, AtlasMetaSddContractService::LAYER_LADDER);

        $l0 = $svc->classifyLayer('L0');
        $this->assertSame('documentation/governance', $l0['scope']);
        $this->assertFalse($l0['autonomy_sensitive']);

        $l7 = $svc->classifyLayer('l7');
        $this->assertSame('Autonomy/Self-Programming', $l7['scope']);
        $this->assertTrue($l7['autonomy_sensitive']);

        $this->assertNull($svc->classifyLayer('L9'));
    }

    public function test_required_questions_gate_needs_all_nine_answered(): void
    {
        // "Required Questions": 9 questions; all must be answered before impl.
        $svc = $this->service();
        $this->assertCount(9, AtlasMetaSddContractService::REQUIRED_QUESTIONS);

        $allKeys = array_keys(AtlasMetaSddContractService::REQUIRED_QUESTIONS);
        $answers = [];
        foreach ($allKeys as $k) {
            $answers[$k] = 'answered';
        }
        $this->assertTrue($svc->answerRequiredQuestions($answers)['answered']);

        // Drop the rollback question -> not answered, listed as unanswered.
        unset($answers['rollback_possible']);
        $r = $svc->answerRequiredQuestions($answers);
        $this->assertFalse($r['answered']);
        $this->assertContains('rollback_possible', $r['unanswered']);
        $this->assertSame(8, $r['answered_count']);
    }

    public function test_flow_is_twelve_plus_one_strict_stages_and_rejects_skips(): void
    {
        // "Meta-SDD Flow": the documented chain has 13 stages (gap ... maturity
        // update proposal). They are strictly sequential.
        $svc = $this->service();
        $this->assertCount(13, AtlasMetaSddContractService::FLOW_STAGES);

        // Nothing done yet -> next stage is the very first, `gap` (stage 1).
        $start = $svc->nextFlowStage([]);
        $this->assertSame('gap', $start['next_stage']);
        $this->assertSame(1, $start['next_stage_number']);
        $this->assertTrue($start['ordered']);

        // First three done in order -> next is the 4th stage, docs_update.
        $r = $svc->nextFlowStage(['gap', 'layer_risk_classification', 'research_if_unstable']);
        $this->assertTrue($r['ordered']);
        $this->assertSame('docs_update', $r['next_stage']);
        $this->assertSame(4, $r['next_stage_number']);

        // Jumping to small_implementation before meta_spec/review -> out of order.
        $bad = $svc->nextFlowStage(['gap', 'small_implementation']);
        $this->assertFalse($bad['ordered']);
        $this->assertNull($bad['next_stage']);
    }

    public function test_any_violated_prohibition_blocks_the_change(): void
    {
        // "Prohibitions": 5 hard rules; ANY violated forbids the change.
        $svc = $this->service();
        $this->assertCount(5, AtlasMetaSddContractService::PROHIBITIONS);

        $this->assertTrue($svc->evaluateProhibitions([])['allowed']);

        $r = $svc->evaluateProhibitions([
            AtlasMetaSddContractService::PROHIBITION_CORE_FROM_PLAIN_INTENT,
        ]);
        $this->assertFalse($r['allowed']);
        $this->assertTrue($r['blocked']);
        $this->assertContains('core_change_from_plain_intent', $r['violated']);
    }

    public function test_minimal_exception_needs_all_four_conditions_true(): void
    {
        // "Minimal Meta-SDD Exception": tiny doc fix qualifies only if ALL hold;
        // even then evidence is still required.
        $svc = $this->service();

        $all = [
            'no_runtime_behavior_change' => true,
            'no_authority_order_change' => true,
            'no_policy_autonomy_security_change' => true,
            'docs_health_and_diff_pass' => true,
        ];
        $ok = $svc->qualifiesForMinimalException($all);
        $this->assertTrue($ok['qualifies']);
        $this->assertSame('minimal_meta_sdd', $ok['path']);
        $this->assertTrue($ok['evidence_still_required']);

        // One condition false -> falls back to the full Meta-SDD.
        $all['no_policy_autonomy_security_change'] = false;
        $no = $svc->qualifiesForMinimalException($all);
        $this->assertFalse($no['qualifies']);
        $this->assertSame('full_meta_sdd', $no['path']);
        $this->assertContains('no_policy_autonomy_security_change', $no['unmet_conditions']);
    }

    public function test_gate_blocks_risky_change_without_rollback(): void
    {
        // Top-level gate enforces "No spec that lacks rollback for risky changes":
        // a complete-looking high-risk spec with a blank rollback must be blocked.
        $svc = $this->service();

        $risky = $this->fullMetaSpec();
        $risky['risk_level'] = 'high';
        $risky['rollback_strategy'] = '';   // no rollback on a risky change

        $allQuestions = [];
        foreach (array_keys(AtlasMetaSddContractService::REQUIRED_QUESTIONS) as $k) {
            $allQuestions[$k] = 'answered';
        }

        $r = $svc->gate($risky, [], $allQuestions);
        $this->assertFalse($r['proceed']);
        $this->assertSame('meta_sdd_blocked', $r['status']);
        $this->assertTrue($r['is_risky']);
        $this->assertContains('risky_change_without_rollback', $r['blocking_reasons']);

        // Same change, now WITH a rollback strategy and complete spec -> proceeds.
        $safe = $risky;
        $safe['rollback_strategy'] = 'revert the commit and restore the doc';
        $ok = $svc->gate($safe, [], $allQuestions);
        $this->assertTrue($ok['proceed']);
        $this->assertSame('meta_sdd_ready', $ok['status']);
        $this->assertSame([], $ok['blocking_reasons']);
    }
}
