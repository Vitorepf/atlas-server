<?php

namespace Tests\Unit\Ai\Rivals;

use App\Services\Ai\Rivals\Core\CampaignManifest;
use App\Services\Ai\Rivals\Core\WorldTrialReadiness;
use Illuminate\Support\Facades\File;
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
            'execution_source' => 'native_runtime', 'real_execution' => true,
            'preregistration_hash' => hash('sha256', 'preregistration:'.$stack),
            'native_receipt_hash' => hash('sha256', 'native:'.$stack),
            'evidence_pack_hash' => hash('sha256', 'evidence:'.$stack),
            'outcome_receipt_hash' => hash('sha256', 'outcome:'.$stack),
            'outcome_windows' => array_reduce(
                ['0h', '24h', '7d', '30d'],
                static function (array $windows, string $window) use ($stack): array {
                    $windows[$window] = [
                        'state' => 'observed',
                        'source' => 'atlas_outcome_store',
                        'synthetic' => false,
                        'observation_hash' => hash('sha256', 'observation:'.$stack.':'.$window),
                    ];

                    return $windows;
                },
                [],
            ),
            'contamination_free' => true, 'itt_complete' => true,
            'critical_dimensions' => ['appsec_privacy', 'performance_resilience'],
        ];

        return [
            $mk('php_laravel', 'R3', 'durable_task', array_map(static fn (int $i): string => 'u'.$i, range(1, 150))),
            $mk('ts_react', 'R4', 'obra', array_map(static fn (int $i): string => 'u'.(150 + $i), range(1, 150))),
            $mk('python_data', 'R5', 'continuous', array_map(static fn (int $i): string => 'u'.(300 + $i), range(1, 150))),
        ];
    }

    public function test_assemble_spans_stack_risk_duration_with_disjoint_units(): void
    {
        $manifest = (new CampaignManifest)->assemble('dev', $this->healthySpecs(), [
            'required_exposure' => 150,
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

    public function test_read_file_requires_canonical_schema_and_allowed_storage_root(): void
    {
        $path = sys_get_temp_dir().'/rivals-manifest-'.uniqid().'.json';
        File::put($path, json_encode(['schema_version' => 'wrong'], JSON_THROW_ON_ERROR));

        $this->expectException(InvalidArgumentException::class);
        (new CampaignManifest)->readFile($path);
    }

    public function test_persisted_campaign_artifact_is_hash_bound_and_immutable(): void
    {
        $root = sys_get_temp_dir().'/rivals_campaign_artifact_'.uniqid();
        config()->set('atlas_rivals.storage_root', $root);
        $manifest = (new CampaignManifest)->assemble('quality-foundry', [], [
            'required_critical_dimensions' => ['correctness'],
        ]);

        try {
            $service = new CampaignManifest;
            $path = $service->persist('campaign-a', $manifest);
            $loaded = $service->readFile($path);
            self::assertSame('campaign-a', $loaded['campaign_id']);
            self::assertSame($loaded['artifact_hash'], $service->readFile($path)['artifact_hash']);
            self::assertSame($path, $service->persist('campaign-a', $manifest));

            $manifest['description'] = 'divergent';
            $this->expectException(InvalidArgumentException::class);
            $service->persist('campaign-a', $manifest);
        } finally {
            File::deleteDirectory($root);
        }
    }
}
