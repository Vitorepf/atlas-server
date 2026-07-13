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

    public function test_missing_real_campaign_provenance_cannot_be_interpreted_as_world_trial_evidence(): void
    {
        $manifest = $this->manifest();
        unset(
            $manifest['campaigns'][0]['execution_source'],
            $manifest['campaigns'][0]['real_execution'],
            $manifest['campaigns'][0]['preregistration_hash'],
            $manifest['campaigns'][0]['native_receipt_hash'],
            $manifest['campaigns'][0]['evidence_pack_hash'],
            $manifest['campaigns'][0]['outcome_receipt_hash'],
        );

        $readiness = (new WorldTrialReadiness)->evaluate($manifest);

        self::assertFalse($readiness['eligible_claim']);
        self::assertContains('native_execution_source_missing:dev-a', $readiness['blockers']);
        self::assertContains('real_execution_unproven:dev-a', $readiness['blockers']);
        self::assertContains('native_receipt_hash_missing:dev-a', $readiness['blockers']);
        self::assertContains('outcome_receipt_hash_missing:dev-a', $readiness['blockers']);
    }

    public function test_mode_frontier_floors_cannot_be_lowered_by_manifest_metadata(): void
    {
        $forge = (new WorldTrialReadiness)->evaluate([
            'mode' => 'forge', 'required_exposure' => 1, 'required_outcome_days' => 1,
            'required_critical_dimensions' => ['correctness'], 'campaigns' => [],
        ]);
        $autonomos = (new WorldTrialReadiness)->evaluate([
            'mode' => 'autonomos', 'required_exposure' => 1, 'required_outcome_days' => 1,
            'required_critical_dimensions' => ['correctness'], 'campaigns' => [],
        ]);

        self::assertSame(30, $forge['required_exposure']);
        self::assertSame(150, $autonomos['required_outcome_days']);
    }

    public function test_elapsed_days_without_observed_window_receipts_never_becomes_ready(): void
    {
        $manifest = $this->manifest();
        unset($manifest['campaigns'][1]['outcome_windows']['30d']);

        $readiness = (new WorldTrialReadiness)->evaluate($manifest);

        self::assertFalse($readiness['eligible_claim']);
        self::assertFalse($readiness['elapsed_outcome']);
        self::assertContains('outcome_observation_missing:dev-b:30d', $readiness['blockers']);
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
            'execution_source' => 'native_runtime',
            'real_execution' => true,
            'preregistration_hash' => hash('sha256', 'preregistration:'.$id),
            'native_receipt_hash' => hash('sha256', 'native:'.$id),
            'evidence_pack_hash' => hash('sha256', 'evidence:'.$id),
            'outcome_receipt_hash' => hash('sha256', 'outcome:'.$id),
            'outcome_windows' => array_reduce(
                ['0h', '24h', '7d', '30d'],
                static function (array $windows, string $window) use ($id): array {
                    $windows[$window] = [
                        'state' => 'observed',
                        'source' => 'atlas_outcome_store',
                        'synthetic' => false,
                        'observation_hash' => hash('sha256', 'observation:'.$id.':'.$window),
                    ];

                    return $windows;
                },
                [],
            ),
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
