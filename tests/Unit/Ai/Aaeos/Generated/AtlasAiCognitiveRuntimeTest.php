<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasAiCognitiveRuntimeService;
use Tests\TestCase;

/**
 * Pins the documented Atlas AI Cognitive Runtime LAW-level rules: the ten
 * Non-Negotiable Invariants, the 72h long-session readiness gate, the Retrieval
 * Quality DoD and the read-only Cognitive Audit Loop net value.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-cognitive-runtime.md
 */
class AtlasAiCognitiveRuntimeTest extends TestCase
{
    private function service(): AtlasAiCognitiveRuntimeService
    {
        return new AtlasAiCognitiveRuntimeService();
    }

    /**
     * Catalog sizes are exactly the documented counts: 10 invariants, 10 metrics,
     * 6 retrieval conditions, 5 required ref fields, 5 cost terms.
     */
    public function test_documented_catalog_sizes(): void
    {
        $this->assertCount(10, AtlasAiCognitiveRuntimeService::INVARIANTS);
        $this->assertCount(10, AtlasAiCognitiveRuntimeService::LONG_SESSION_METRICS);
        $this->assertCount(6, AtlasAiCognitiveRuntimeService::RETRIEVAL_CONDITIONS);
        $this->assertCount(5, AtlasAiCognitiveRuntimeService::REQUIRED_REF_FIELDS);
        $this->assertCount(5, AtlasAiCognitiveRuntimeService::NET_VALUE_COST_TERMS);
    }

    /**
     * Invariant #8: a contaminated prompt that is SHIPPED anyway is blocked
     * ("Contexto insuficiente e melhor que contexto contaminado"). Dropping the
     * contaminated context instead keeps the action allowed.
     */
    public function test_invariant_prefers_insufficient_over_contaminated(): void
    {
        $service = $this->service();

        $shipped = $service->evaluateInvariants([
            'prompt_contaminated' => true,
            'ship_contaminated' => true,
        ]);
        $this->assertSame(AtlasAiCognitiveRuntimeService::INVARIANTS_BLOCKED, $shipped['status']);
        $this->assertFalse($shipped['allowed']);
        $this->assertContains('prefer_insufficient_over_contaminated', $shipped['violated']);

        // Contaminated but dropped (not shipped) -> no #8 violation.
        $dropped = $service->evaluateInvariants([
            'prompt_contaminated' => true,
            'ship_contaminated' => false,
        ]);
        $this->assertTrue($dropped['allowed']);
        $this->assertNotContains('prefer_insufficient_over_contaminated', $dropped['violated']);
    }

    /**
     * Invariant #10: a surface that hand-assembles memory bypasses
     * retrieval/policy and is blocked ("Nenhuma surface monta memoria manualmente
     * no prompt"). A clean action passes all ten invariants.
     */
    public function test_invariant_blocks_manual_memory_assembly_and_clean_passes(): void
    {
        $service = $this->service();

        $manual = $service->evaluateInvariants(['manual_memory_assembly' => true]);
        $this->assertFalse($manual['allowed']);
        $this->assertContains('no_surface_assembles_memory_manually', $manual['violated']);

        $clean = $service->evaluateInvariants([]);
        $this->assertSame(AtlasAiCognitiveRuntimeService::INVARIANTS_OK, $clean['status']);
        $this->assertTrue($clean['allowed']);
        $this->assertSame([], $clean['violated']);
        $this->assertSame(0, $clean['violated_count']);
    }

    /**
     * 72h gate: no metric in alert -> `ready`. A soft alert (cost per useful hour)
     * -> `not_ready`. A hard-unsafe alert (context contamination) -> `unsafe`,
     * which is strictly worse than not_ready and is never `ready`.
     */
    public function test_long_session_readiness_three_verdicts(): void
    {
        $service = $this->service();

        $ready = $service->evaluateLongSessionReadiness(['alerts' => []]);
        $this->assertSame(AtlasAiCognitiveRuntimeService::SESSION_READY, $ready['verdict']);
        $this->assertTrue($ready['ready']);
        $this->assertSame(10, $ready['metrics_ok']);

        $soft = $service->evaluateLongSessionReadiness([
            'alerts' => ['cost_per_useful_hour' => true],
        ]);
        $this->assertSame(AtlasAiCognitiveRuntimeService::SESSION_NOT_READY, $soft['verdict']);
        $this->assertFalse($soft['ready']);
        $this->assertSame([], $soft['unsafe_alerts']);

        $unsafe = $service->evaluateLongSessionReadiness([
            'alerts' => ['context_contamination_rate' => true],
        ]);
        $this->assertSame(AtlasAiCognitiveRuntimeService::SESSION_UNSAFE, $unsafe['verdict']);
        $this->assertFalse($unsafe['ready']);
        $this->assertContains('context_contamination_rate', $unsafe['unsafe_alerts']);
    }

    /**
     * Retrieval DoD: a ref missing any required field fails condition #1, and a
     * ref that bypassed the deterministic filters fails condition #4
     * ("vector/hybrid search nunca bypassa filtros deterministicos"). A fully
     * described, filtered, fully-flagged retrieval is mature.
     */
    public function test_retrieval_quality_dod_metadata_and_filter_bypass(): void
    {
        $service = $this->service();

        $goodRef = [
            'source' => 'docs/x.md',
            'reason' => 'canonical',
            'scope' => 'architecture',
            'priority' => 100,
            'provider_safe_summary' => 'safe summary',
            'bypassed_filters' => false,
        ];

        // Missing provider_safe_summary -> condition #1 fails.
        $missing = $goodRef;
        unset($missing['provider_safe_summary']);
        $r1 = $service->evaluateRetrievalQuality([
            'refs' => [$missing],
            'ranking_privileges_canonical' => true,
            'records_excluded_refs_with_reason' => true,
            'code_tasks_get_code_and_tests' => true,
            'quality_metrics_measured' => true,
        ]);
        $this->assertFalse($r1['mature']);
        $this->assertContains('refs_carry_full_metadata', $r1['failed_conditions']);
        $this->assertContains(0, $r1['refs_missing_metadata']);

        // A ref that bypassed deterministic filters -> condition #4 fails.
        $bypassed = $goodRef;
        $bypassed['bypassed_filters'] = true;
        $r2 = $service->evaluateRetrievalQuality([
            'refs' => [$bypassed],
            'ranking_privileges_canonical' => true,
            'records_excluded_refs_with_reason' => true,
            'code_tasks_get_code_and_tests' => true,
            'quality_metrics_measured' => true,
        ]);
        $this->assertFalse($r2['mature']);
        $this->assertContains('vector_never_bypasses_filters', $r2['failed_conditions']);

        // Fully conformant -> mature, all six conditions met.
        $r3 = $service->evaluateRetrievalQuality([
            'refs' => [$goodRef],
            'ranking_privileges_canonical' => true,
            'records_excluded_refs_with_reason' => true,
            'code_tasks_get_code_and_tests' => true,
            'quality_metrics_measured' => true,
        ]);
        $this->assertTrue($r3['mature']);
        $this->assertSame(AtlasAiCognitiveRuntimeService::RETRIEVAL_MATURE, $r3['status']);
        $this->assertSame(6, $r3['conditions_met']);
    }

    /**
     * Net Value formula: net = gain - (wrong_context + avoidable_repetition +
     * objective_drift + cognitive_token_cost + policy_privacy_violations).
     * gain=10, costs sum to 4 -> net=6. The score is read-only and never grants
     * memory promotion or policy change; a policy/privacy cost is surfaced.
     */
    public function test_net_value_formula_is_read_only(): void
    {
        $service = $this->service();

        $score = $service->computeNetValue([
            'gain' => 10,
            'wrong_context' => 1,
            'avoidable_repetition' => 1,
            'objective_drift' => 0,
            'cognitive_token_cost' => 2,
            'policy_privacy_violations' => 0,
        ]);

        $this->assertSame(6.0, $score['net_value']);
        $this->assertSame(4.0, $score['cost_total']);
        $this->assertTrue($score['positive']);
        $this->assertTrue($score['read_only']);
        $this->assertFalse($score['may_promote_memory']);
        $this->assertFalse($score['may_alter_policy']);
        $this->assertFalse($score['has_policy_privacy_violation']);

        // A policy/privacy violation is surfaced even if the net stays positive.
        $violated = $service->computeNetValue([
            'gain' => 10,
            'policy_privacy_violations' => 1,
        ]);
        $this->assertSame(9.0, $violated['net_value']);
        $this->assertTrue($violated['has_policy_privacy_violation']);
        $this->assertFalse($violated['may_promote_memory']);
    }
}
