<?php

namespace Tests\Unit\Ai\Rivals;

use App\Services\Ai\Rivals\Core\WorldTrialReadiness;
use Tests\TestCase;

final class WorldTrialReadinessTest extends TestCase
{
    public function test_complete_multi_campaign_frontier_is_reported_without_issuing_claims(): void
    {
        $readiness = (new WorldTrialReadiness)->evaluate($this->manifest());

        $this->assertSame('ready_for_separate_claim_adjudication', $readiness['status']);
        $this->assertTrue($readiness['implemented_trial']);
        $this->assertTrue($readiness['active_campaign']);
        $this->assertTrue($readiness['elapsed_outcome']);
        $this->assertTrue($readiness['eligible_claim']);
        $this->assertFalse($readiness['claim_issued']);
        $this->assertSame([], $readiness['blockers']);
    }

    public function test_readiness_is_fail_closed_for_underpowered_incomplete_and_synthetic_campaigns(): void
    {
        $manifest = $this->manifest();
        $manifest['campaigns'][0]['power'] = 0.89;
        $manifest['campaigns'][1]['outcome_days'] = 29;
        $manifest['campaigns'][2]['synthetic'] = true;
        $manifest['campaigns'][2]['contamination_free'] = false;
        $manifest['campaigns'][2]['itt_complete'] = false;
        $manifest['campaigns'][2]['critical_dimensions']['security'] = false;

        $readiness = (new WorldTrialReadiness)->evaluate($manifest);

        $this->assertSame('blocked', $readiness['status']);
        $this->assertFalse($readiness['eligible_claim']);
        $this->assertFalse($readiness['claim_issued']);
        $this->assertNotEmpty(preg_grep('/power_below_90_percent/', $readiness['blockers']));
        $this->assertNotEmpty(preg_grep('/outcome_window_incomplete/', $readiness['blockers']));
        $this->assertNotEmpty(preg_grep('/synthetic_campaign/', $readiness['blockers']));
        $this->assertNotEmpty(preg_grep('/contamination_detected/', $readiness['blockers']));
        $this->assertNotEmpty(preg_grep('/itt_incomplete/', $readiness['blockers']));
        $this->assertNotEmpty(preg_grep('/critical_dimension_missing_or_regressed/', $readiness['blockers']));
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        return [
            'mode' => 'dev',
            'required_exposure' => 150,
            'required_outcome_days' => 30,
            'required_critical_dimensions' => ['security', 'reliability'],
            'campaigns' => [
                $this->campaign('dev-a'),
                $this->campaign('dev-b'),
                $this->campaign('dev-c'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function campaign(string $id): array
    {
        return [
            'id' => $id,
            'synthetic' => false,
            'distinct_units' => 150,
            'power' => 0.90,
            'outcome_days' => 30,
            'contamination_free' => true,
            'itt_complete' => true,
            'critical_dimensions' => [
                'security' => true,
                'reliability' => true,
            ],
        ];
    }
}
