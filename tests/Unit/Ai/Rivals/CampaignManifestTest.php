<?php

namespace Tests\Unit\Ai\Rivals;

use App\Services\Ai\Rivals\Core\CampaignManifest;
use App\Services\Ai\Rivals\Core\WorldTrialReadiness;
use InvalidArgumentException;
use Tests\TestCase;

class CampaignManifestTest extends TestCase
{
    /** @return list<array<string,mixed>> */
    private function healthySpecs(): array
    {
        $mk = fn (string $stack, string $risk, string $dur, array $units): array => [
            'stack' => $stack, 'risk' => $risk, 'duration' => $dur, 'unit_ids' => $units,
            'power' => 0.95, 'outcome_days' => 30, 'synthetic' => false,
            'contamination_free' => true, 'itt_complete' => true,
            'critical_dimensions' => ['appsec_privacy', 'performance_resilience'],
        ];

        return [
            $mk('php_laravel', 'R3', 'durable_task', ['u1', 'u2']),
            $mk('ts_react', 'R4', 'obra', ['u3', 'u4']),
            $mk('python_data', 'R5', 'continuous', ['u5', 'u6']),
        ];
    }

    public function test_assemble_spans_stack_risk_duration_with_disjoint_units(): void
    {
        $manifest = (new CampaignManifest)->assemble('dev', $this->healthySpecs(), [
            'required_exposure' => 2,
            'required_outcome_days' => 30,
            'required_critical_dimensions' => ['appsec_privacy', 'performance_resilience'],
        ]);

        $this->assertCount(3, $manifest['campaigns']);
        $this->assertSame(['R3', 'R4', 'R5'], array_column($manifest['campaigns'], 'risk'));
        $this->assertSame(['php_laravel', 'ts_react', 'python_data'], array_column($manifest['campaigns'], 'stack'));

        // the readiness gate accepts a genuinely complete campaign set
        $readiness = (new WorldTrialReadiness)->evaluate($manifest);
        $this->assertSame([], $readiness['blockers'], implode(',', $readiness['blockers']));
        $this->assertTrue($readiness['eligible_claim']);
        $this->assertFalse($readiness['claim_issued']);
    }

    public function test_overlapping_units_across_campaigns_are_rejected(): void
    {
        $specs = $this->healthySpecs();
        $specs[1]['unit_ids'] = ['u1', 'u9']; // reuse u1 from campaign 0

        $this->expectException(InvalidArgumentException::class);
        (new CampaignManifest)->assemble('dev', $specs);
    }

    public function test_fewer_than_three_campaigns_blocks_readiness(): void
    {
        $specs = array_slice($this->healthySpecs(), 0, 2);
        $manifest = (new CampaignManifest)->assemble('dev', $specs, ['required_exposure' => 2]);

        $readiness = (new WorldTrialReadiness)->evaluate($manifest);
        $this->assertFalse($readiness['eligible_claim']);
        $this->assertNotEmpty(preg_grep('/^campaign_count_below_three/', $readiness['blockers']));
    }

    public function test_synthetic_dry_trial_is_never_eligible_for_a_production_claim(): void
    {
        $specs = $this->healthySpecs();
        foreach ($specs as &$spec) {
            $spec['synthetic'] = true;
        }
        unset($spec);

        $manifest = (new CampaignManifest)->assemble('dev', $specs, ['required_exposure' => 2]);
        $readiness = (new WorldTrialReadiness)->evaluate($manifest);

        $this->assertFalse($readiness['eligible_claim']);
        $this->assertFalse($readiness['claim_issued']);
        $this->assertNotEmpty(preg_grep('/^synthetic_campaign/', $readiness['blockers']));
    }
}
