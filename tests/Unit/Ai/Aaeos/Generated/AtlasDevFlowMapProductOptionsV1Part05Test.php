<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasDevFlowMapProductOptionsV1Part05Service;
use Tests\TestCase;

/**
 * Pins the documented Atlas Dev Flow Map And Product Options rules (Parte 5 ·
 * "Conteudo Extraido"): executor selection, quality-gate attachment + final
 * status, the normalized repair contract (debug cap = 3), the Dev->Forge
 * promotion target incl. the thin_small_bug veto and the never-auto-create-Obra
 * rule, and the use-case -> canonical-flow map.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1-part-05.md
 */
class AtlasDevFlowMapProductOptionsV1Part05Test extends TestCase
{
    private function service(): AtlasDevFlowMapProductOptionsV1Part05Service
    {
        return new AtlasDevFlowMapProductOptionsV1Part05Service();
    }

    /**
     * §Programming Orchestrator — executor table. Harness need (forge OR explicit
     * requirement) outranks the repair loop, which outranks the light path.
     */
    public function test_executor_selection_follows_documented_table(): void
    {
        $s = $this->service();

        // Light Dev flow, no repair/harness -> simple_provider_execution.
        $this->assertSame(
            $s::EXECUTOR_SIMPLE,
            $s->decideExecutor($s::PROFILE_DEV, false, false)['executor_decision']
        );

        // Full Dev with repair/quality loop -> dev_repair_executor.
        $this->assertSame(
            $s::EXECUTOR_DEV_REPAIR,
            $s->decideExecutor($s::PROFILE_DEV, false, true)['executor_decision']
        );

        // Forge profile -> engineering_harness even with a repair loop present.
        $this->assertSame(
            $s::EXECUTOR_ENGINEERING_HARNESS,
            $s->decideExecutor($s::PROFILE_FORGE, false, true)['executor_decision']
        );

        // Dev profile but intent/risk explicitly requires the harness -> harness.
        $this->assertSame(
            $s::EXECUTOR_ENGINEERING_HARNESS,
            $s->decideExecutor($s::PROFILE_DEV, true, false)['executor_decision']
        );
    }

    /**
     * §Quality Gate E Repair · Quality gate. Attached on complete mode OR
     * max_iterations > 1; required_final_status is `passed` in complete/fair and
     * `not_failed` in the light/single-shot path.
     */
    public function test_quality_gate_attachment_and_required_final_status(): void
    {
        $s = $this->service();

        $complete = $s->decideQualityGate(true, 1);
        $this->assertTrue($complete['quality_gate_attached']);
        $this->assertSame('passed', $complete['required_final_status']);
        $this->assertSame('plan_validate_execute', $complete['procedure']);

        // Light single-shot: not attached.
        $single = $s->decideQualityGate(false, 1);
        $this->assertFalse($single['quality_gate_attached']);
        $this->assertNull($single['required_final_status']);

        // Multi-iteration (not complete): attached, but only requires not_failed.
        $multi = $s->decideQualityGate(false, 3);
        $this->assertTrue($multi['quality_gate_attached']);
        $this->assertSame('not_failed', $multi['required_final_status']);
    }

    /**
     * §Repair / §Casos De Uso · Debug. max_iterations is normalized into
     * [1, 3]; the capsule never allows fallback; heavy repair requires evidence
     * and structural repair requires the Kernel repair decision.
     */
    public function test_repair_contract_caps_iterations_and_locks_capsule(): void
    {
        $s = $this->service();

        // Requested 9 -> capped at the documented debug ceiling of 3.
        $heavy = $s->repairContract(9, true, true);
        $this->assertSame(3, $heavy['max_iterations']);
        $this->assertFalse($heavy['fallback_allowed_in_capsule']);
        $this->assertTrue($heavy['stop_when_passed']);
        $this->assertTrue($heavy['stop_if_quality_worsens']);
        $this->assertTrue($heavy['evidence_required']);
        $this->assertTrue($heavy['kernel_repair_decision_required']);

        // Requested 0 -> floored at 1; light repair needs no evidence.
        $floor = $s->repairContract(0);
        $this->assertSame(1, $floor['max_iterations']);
        $this->assertFalse($floor['evidence_required']);
        $this->assertFalse($floor['kernel_repair_decision_required']);
    }

    /**
     * §Dev -> Forge Promotion. The thin_small_bug veto (<= 1 file, no risk) pins
     * the target to `none`; heavy risk + wide architecture escalates to
     * forge_obra; and promotion is ALWAYS a preview — never auto-creates an Obra.
     */
    public function test_promotion_target_honours_thin_bug_veto_and_never_auto_creates_obra(): void
    {
        $s = $this->service();

        $thin = $s->decidePromotionTarget(['file_count' => 1, 'risk_or_production' => false]);
        $this->assertSame($s::TARGET_NONE, $thin['target']);
        $this->assertTrue($thin['thin_small_bug_veto']);
        $this->assertFalse($thin['auto_create_obra']);
        $this->assertTrue($thin['preview_only']);

        $forge = $s->decidePromotionTarget([
            'file_count' => 9,
            'subsystem_count' => 3,
            'risk_or_production' => true,
            'architecture_or_refactor' => true,
        ]);
        $this->assertSame($s::TARGET_FORGE_OBRA, $forge['target']);
        $this->assertFalse($forge['auto_create_obra']);

        // Recurrent failure on a multi-file change but no risk -> obra_candidate.
        $candidate = $s->decidePromotionTarget(['file_count' => 4, 'recurrent_failure' => true]);
        $this->assertSame($s::TARGET_OBRA_CANDIDATE, $candidate['target']);
    }

    /**
     * §Casos De Uso. Each documented use case maps to its canonical flow and
     * posture; debug specifically routes to programming.repair with
     * max_iterations = 3, and review is read-only with no patch by default.
     */
    public function test_use_case_flow_map_matches_documented_cases(): void
    {
        $s = $this->service();

        $question = $s->mapUseCaseFlow('technical_question');
        $this->assertSame('programming.dev', $question['flow']);
        $this->assertFalse($question['produces_patch']);
        $this->assertFalse($question['quality_gate_mandatory']);

        $debug = $s->mapUseCaseFlow('debug');
        $this->assertSame('programming.repair', $debug['flow']);
        $this->assertSame(3, $debug['max_iterations']);
        $this->assertTrue($debug['produces_patch']);

        $review = $s->mapUseCaseFlow('review');
        $this->assertSame('programming.review', $review['flow']);
        $this->assertFalse($review['produces_patch']);

        $smallBug = $s->mapUseCaseFlow('small_bug');
        $this->assertSame('programming.dev', $smallBug['flow']);
        $this->assertFalse($smallBug['promote_to_obra']);
    }

    /**
     * §Open Brain E Contexto. Off flag wins outright; forge/require raise the
     * floor to `required`; --require-open-brain additionally fails closed.
     */
    public function test_open_brain_mode_resolution(): void
    {
        $s = $this->service();

        $this->assertSame('auto', $s->resolveOpenBrainMode()['mode']);
        $this->assertSame('off', $s->resolveOpenBrainMode(false, true)['mode']);
        $this->assertSame('required', $s->resolveOpenBrainMode(true)['mode']);

        $require = $s->resolveOpenBrainMode(false, false, true);
        $this->assertSame('required', $require['mode']);
        $this->assertTrue($require['fail_closed']);
    }
}
