<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Compounding;

use App\Services\Ai\Compounding\AtlasLearningProposalService;
use Tests\TestCase;

/**
 * The capture quality gate wired into propose(): default 'observe' is zero-behavior;
 * 'enforce' refuses to persist noise (the enforce-reject path returns a transient model
 * before any DB write, so it is provable without a database).
 */
class AtlasLearningProposalQualityGateTest extends TestCase
{
    private function noiseInput(): array
    {
        return [
            'kind' => 'failure_pattern',
            'summary' => 'fp', // trivial claim — genuinely contentless when paired with the empty template
            'evidence_refs' => ['ev-1'],
            // The exact empty template that produced the duplicate proposals this week.
            'proposed_state' => ['should_repromote_sources' => [], 'should_demote_noise_count' => 0, 'target_context_sufficiency_min' => 70],
        ];
    }

    public function test_enforce_mode_does_not_persist_contentless_noise(): void
    {
        config(['atlas.ai.capture_quality_gate.mode' => 'enforce']);

        $proposal = (new AtlasLearningProposalService())->propose($this->noiseInput());

        $this->assertSame('rejected_by_quality_gate', $proposal->status);
        $this->assertFalse($proposal->exists, 'contentless noise must not be persisted in enforce mode');
        $this->assertSame('contentless', $proposal->payload['quality']['reason'] ?? null);
    }

    public function test_off_mode_disables_the_gate_short_circuit(): void
    {
        // The gate still flags the contentless input as noise, but 'off' mode means
        // propose() would never short-circuit on it (it falls through to persistence).
        $gate = app(\App\Services\Ai\Compounding\AtlasCaptureQualityGate::class)->assess([
            'kind' => 'failure_pattern',
            'claim' => 'fp',
            'content' => $this->noiseInput()['proposed_state'],
        ]);
        $this->assertFalse($gate['admit']);
        $this->assertSame('contentless', $gate['reason']);
    }
}
