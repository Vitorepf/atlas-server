<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasMemoryCognitiveImmuneLearningKernelService;
use Tests\TestCase;

/**
 * Pins the cognitive immune contract from the doc: every input is born in
 * quarantine (all eligibility false, status unclassified); the Input Classes
 * table decides what can ever become memory; the G0-G8 ladder needs ALL gates
 * green to promote and the first broken rung stops the climb; a class that can
 * never become knowledge is never promoted; critical/policy scope can never be
 * auto (Rule 7); promotion enters probation as `watch`, never straight to
 * `trusted`; embedding + Constelacao gates default-deny. Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/memory/cognitive-immune-learning-kernel.md
 */
class AtlasMemoryCognitiveImmuneLearningKernelTest extends TestCase
{
    private function service(): AtlasMemoryCognitiveImmuneLearningKernelService
    {
        return new AtlasMemoryCognitiveImmuneLearningKernelService;
    }

    /** Safe candidate with every gate signal green and a non-critical scope. */
    private function greenCandidate(string $class, string $scope = 'project'): array
    {
        return [
            'input_class' => $class,
            'scope' => $scope,
            'capture_consented' => true,
            'atomic_claim' => true,
            'future_signal' => true,
            'provider_safe' => true,
            'no_contradiction' => true,
            'outcome_validated' => true,
            'scope_resolved' => true,
            'promotion_mode_set' => true,
            'probation_entered' => true,
            'promotion_mode' => 'auto',
        ];
    }

    public function test_default_state_is_full_cognitive_quarantine(): void
    {
        $state = $this->service()->defaultState();

        $this->assertFalse($state['memory_eligible']);
        $this->assertFalse($state['context_eligible']);
        $this->assertFalse($state['constellation_eligible']);
        $this->assertFalse($state['embedding_allowed']);
        $this->assertSame('unclassified', $state['promotion_status']);
    }

    public function test_input_classes_table_marks_noise_as_never_memory_and_candidates_as_eligible(): void
    {
        $service = $this->service();

        // Doc "Nao" rows: can never become knowledge.
        foreach (['trivial_query', 'operational_ephemeral', 'task_or_reminder', 'conversation_trace', 'prompt_injection', 'untrusted_content'] as $noise) {
            $this->assertFalse($service->classify($noise)['can_become_memory'], "$noise must not be promotable");
        }

        // Candidate rows: can become memory (with gates).
        foreach (['project_evidence', 'personal_fact_candidate', 'technical_learning_candidate', 'strategic_insight_candidate'] as $cand) {
            $this->assertTrue($service->classify($cand)['can_become_memory'], "$cand must be promotable");
        }

        // "comprar pao" -> task_or_reminder, never memory; classification keeps quarantine.
        $task = $service->classify('task_or_reminder');
        $this->assertSame('task_routine', $task['destination']);
        $this->assertFalse($task['state']['memory_eligible']);

        // Unknown class collapses to the most conservative class.
        $this->assertSame('untrusted_content', $service->classify('something_unseen')['class']);
    }

    public function test_all_gates_green_promotes_and_enters_probation_as_watch_not_trusted(): void
    {
        $result = $this->service()->evaluatePromotion(
            $this->greenCandidate('technical_learning_candidate')
        );

        $this->assertTrue($result['promote']);
        $this->assertNull($result['failed_gate']);
        $this->assertSame(['G0', 'G1', 'G2', 'G3', 'G4', 'G5', 'G6', 'G7', 'G8'], $result['passed_gates']);
        // G8 probation: never straight to trusted.
        $this->assertSame('watch', $result['resulting_state']);
        $this->assertNotSame('trusted', $result['resulting_state']);
    }

    public function test_first_broken_gate_stops_the_ladder_and_blocks_promotion(): void
    {
        $candidate = $this->greenCandidate('strategic_insight_candidate');
        // Break G4 (contradiction scan): a newer decision conflicts.
        $candidate['no_contradiction'] = false;

        $result = $this->service()->evaluatePromotion($candidate);

        $this->assertFalse($result['promote']);
        $this->assertSame('G4', $result['failed_gate']);
        // Ladder is ordered: G0-G3 passed, G4 failed, G5+ cannot pass.
        $this->assertSame(['G0', 'G1', 'G2', 'G3'], $result['passed_gates']);
        $this->assertSame('candidate', $result['resulting_state']);
    }

    public function test_class_that_cannot_become_memory_is_never_promoted_even_with_all_signals(): void
    {
        // Every gate signal is green, but the class is pure noise.
        $result = $this->service()->evaluatePromotion(
            $this->greenCandidate('trivial_query')
        );

        $this->assertFalse($result['promote']);
        $this->assertFalse($result['can_become_memory']);
        $this->assertContains('class_cannot_become_memory', $result['reasons']);
    }

    public function test_rule_seven_critical_scope_can_never_be_auto_promoted(): void
    {
        $result = $this->service()->evaluatePromotion(
            $this->greenCandidate('strategic_insight_candidate', 'global')
        );

        // Gates all pass, but a global/critical scope is forced off auto.
        $this->assertNull($result['failed_gate']);
        $this->assertSame('review', $result['promotion_mode']);
        $this->assertNotSame('auto', $result['promotion_mode']);
        $this->assertContains('critical_scope_forced_review', $result['reasons']);
    }

    public function test_embedding_quarantine_denies_noise_and_requires_full_provenance(): void
    {
        $service = $this->service();

        // Sensitive class is denied even with otherwise-complete metadata.
        $denied = $service->embeddingAllowed('private_sensitive', [
            'origin' => 'x', 'trust_level' => 'low', 'privacy' => 'sensitive',
            'retention' => '30d', 'expires_at' => 'soon', 'embedding_allowed' => true,
            'tombstone_status' => 'none',
        ]);
        $this->assertFalse($denied['allowed']);

        // Allowed class but missing metadata -> still blocked, lists missing fields.
        $missing = $service->embeddingAllowed('technical_learning_candidate', ['origin' => 'doc']);
        $this->assertFalse($missing['allowed']);
        $this->assertContains('trust_level', $missing['missing_meta']);

        // Allowed class with full provenance + explicit flag -> allowed.
        $ok = $service->embeddingAllowed('technical_learning_candidate', [
            'origin' => 'doc', 'trust_level' => 'high', 'privacy' => 'safe',
            'retention' => '180d', 'expires_at' => '2026-12-01', 'embedding_allowed' => true,
            'tombstone_status' => 'none',
        ]);
        $this->assertTrue($ok['allowed']);
    }

    public function test_constellation_gate_and_forgetting_receipt_default_deny_without_full_evidence(): void
    {
        $service = $this->service();

        // Missing clearances -> not eligible; full clearances on a strategic
        // candidate -> eligible.
        $blocked = $service->constellationEligible('strategic_insight_candidate', ['provenance' => 'doc']);
        $this->assertFalse($blocked['eligible']);
        $this->assertContains('reason', $blocked['missing_clearances']);

        $full = $service->constellationEligible('strategic_insight_candidate', [
            'provenance' => 'doc', 'semantic_value' => 'high', 'scope' => 'global',
            'reversibility' => 'reversible', 'privacy_clearance' => 'cleared', 'reason' => 'analogy',
        ]);
        $this->assertTrue($full['eligible']);

        // A demotion without a reason or evidence is an invalid forgetting receipt.
        $this->assertFalse($service->forgettingReceipt('ttl_expiration', '', '')['valid']);
        $this->assertTrue($service->forgettingReceipt('supersession', 'newer memory', 'ledger:abc')['valid']);

        // The 8 non-negotiable rules are all present.
        $this->assertCount(8, $service->nonNegotiableRules());
    }

    /* ---------------------------------------------------------------------
     | Consolidated CognitiveImmunePromotionGateEvaluator (default-OFF opt-in).
     | OFF: the new helper is inert (null) and evaluatePromotion() is unchanged.
     | ON: the helper delegates to the pure kernel and returns its richer verdict.
     * -------------------------------------------------------------------- */

    public function test_promotion_gate_evaluator_helper_is_null_when_flag_off(): void
    {
        config()->set('atlas.cognitive_immune.promotion_gate_evaluator_enabled', false);

        $this->assertNull($this->service()->evaluatePromotionGates(['atomic_claim_present' => true]));
    }

    public function test_promotion_gate_evaluator_helper_delegates_to_kernel_when_flag_on(): void
    {
        config()->set('atlas.cognitive_immune.promotion_gate_evaluator_enabled', true);

        $signals = ['atomic_claim_present' => true, 'contradicts_newer' => true];

        $expected = (new \App\Services\Ai\Cognition\CognitiveImmunePromotionGateEvaluator)->evaluate($signals);

        $this->assertSame($expected, $this->service()->evaluatePromotionGates($signals));
        // The richer kernel taxonomy the local evaluatePromotion() does not emit.
        $this->assertSame('blocked', $expected['promotion_status']);
        $this->assertContains('G4', $expected['blocking_gate_ids']);
    }

    public function test_live_evaluate_promotion_path_is_unchanged_when_evaluator_flag_off(): void
    {
        // Behavior-preserving guard: the consumer's own ladder still answers,
        // independent of the new opt-in kernel helper.
        config()->set('atlas.cognitive_immune.promotion_gate_evaluator_enabled', false);

        $verdict = $this->service()->evaluatePromotion($this->greenCandidate('technical_learning_candidate'));

        $this->assertTrue($verdict['promote']);
        $this->assertSame('watch', $verdict['resulting_state']);
        $this->assertNull($verdict['failed_gate']);
    }
}
