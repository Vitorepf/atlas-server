<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainSimplificationCausalCreditGate;
use Tests\TestCase;

final class AtlasExternalBrainSimplificationCausalCreditGateTest extends TestCase
{
    public function test_real_reversible_simplification_receives_credit_only_after_causal_adjudication(): void
    {
        $result = (new AtlasExternalBrainSimplificationCausalCreditGate)->adjudicate($this->candidate());

        $this->assertTrue($result['credit_eligible']);
        $this->assertSame('promote_reversible', $result['causal_verdict']);
        $this->assertSame('causal_evidence_admitted', $result['reason']);
        $this->assertSame(120, $result['proof']['net_reduction_score']);
    }

    public function test_missing_or_uncertain_causal_evidence_holds_and_never_credits_line_count(): void
    {
        $candidate = $this->candidate();
        unset($candidate['causal_candidate']);
        $candidate['lines_deleted'] = 5000;

        $result = (new AtlasExternalBrainSimplificationCausalCreditGate)->adjudicate($candidate);

        $this->assertFalse($result['credit_eligible']);
        $this->assertSame('hold', $result['causal_verdict']);
        $this->assertSame('causal_candidate_missing', $result['reason']);
        $this->assertSame(0, $result['credited_reduction']);
    }

    public function test_non_reversible_or_non_real_outcome_is_not_promoted(): void
    {
        $candidate = $this->candidate();
        $candidate['causal_candidate']['reversible'] = false;
        $candidate['causal_candidate']['real_outcome'] = false;

        $result = (new AtlasExternalBrainSimplificationCausalCreditGate)->adjudicate($candidate);

        $this->assertFalse($result['credit_eligible']);
        $this->assertSame('causal_binding_unproven', $result['reason']);
        $this->assertSame(0, $result['credited_reduction']);
    }

    /** @return array<string,mixed> */
    private function candidate(): array
    {
        $hash = static fn (string $value): string => hash('sha256', $value);

        return [
            'candidate_id' => 'simplify-dead-helper',
            'kind' => 'deletion',
            'consumer_count' => 0,
            'consumer_paths' => [],
            'behavior_coverage' => true,
            'behavior_equivalence_commands' => ['php artisan test --filter=DeadHelperEquivalence'],
            'test_coverage' => true,
            'rollback_notes' => 'restore the previous proven version',
            'lines_deleted' => 120,
            'causal_candidate' => [
                'assignment_hash' => $hash('assignment'),
                'experiment_hash' => $hash('experiment'),
                'order_hash' => $hash('order'),
                'run_hash' => $hash('run'),
                'release_hash' => $hash('release'),
                'outcome_hash' => $hash('outcome'),
                'change_class' => 'simplification',
                'hypothesis' => 'Removing the dead helper preserves behavior and reduces maintenance surface.',
                'baseline' => 'previous proven helper version',
                'metric' => 'behavioral equivalence and maintenance surface',
                'window' => '30d',
                'effect' => 0.50,
                'ci_low' => 0.20,
                'ci_high' => 0.80,
                'confounders' => ['provider_drift' => 'controlled'],
                'rollback' => 'restore previous proven version',
                'reversible' => true,
                'assignment_precedes_run' => true,
                'real_outcome' => true,
                'authority_hash' => $hash('authority'),
                'scope' => 'atlas-dev:simplification:dead-helper',
                'expiry' => '2026-12-31T00:00:00+00:00',
            ],
        ];
    }
}
