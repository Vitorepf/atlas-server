<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Context\Retrieval;

use App\Services\Ai\Cognition\AcosProgram\AcosMaxLedgerRotationRegistry;
use App\Services\Ai\Cognition\AcosProgram\AcosMaxMeasureSeriesRegistry;
use App\Services\Ai\Context\Retrieval\GoldenCounterfactualReplayService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class Maxl07GoldenCounterfactualTest extends TestCase
{
    private string $runsPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runsPath = sys_get_temp_dir().'/atlas_maxl07_'.uniqid('', true).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->runsPath);
        parent::tearDown();
    }

    public function test_paired_golden_runs_publish_delta(): void
    {
        $this->writeRuns([
            [
                'arm' => 'without',
                'decision_id' => 'decision-1',
                'run_id' => 'run-without',
                'commit' => 'abc123',
                'recall_at_5' => 0.4,
                'executed_at' => '2026-07-12T10:00:00+00:00',
            ],
            [
                'arm' => 'with',
                'decision_id' => 'decision-1',
                'run_id' => 'run-with',
                'commit' => 'def456',
                'recall_at_5' => 0.7,
                'executed_at' => '2026-07-12T10:05:00+00:00',
            ],
        ]);

        $payload = $this->report(['--runs' => $this->runsPath, '--decision-id' => 'decision-1']);

        $this->assertSame('ok', $payload['status']);
        $this->assertSame(0.4, data_get($payload, 'counterfactual.recall_at_5_without'));
        $this->assertSame(0.7, data_get($payload, 'counterfactual.recall_at_5_with'));
        $this->assertSame(0.3, data_get($payload, 'counterfactual.delta'));
        $this->assertFalse(data_get($payload, 'claim_policy.git_checkout_performed'));
    }

    public function test_missing_pair_skips_without_fabricating_delta(): void
    {
        $this->writeRuns([[
            'arm' => 'with',
            'decision_id' => 'decision-1',
            'run_id' => 'run-with',
            'commit' => 'def456',
            'recall_at_5' => 0.7,
        ]]);

        $payload = $this->report(['--runs' => $this->runsPath, '--decision-id' => 'decision-1']);

        $this->assertSame('skipped', $payload['status']);
        $this->assertSame('paired_arms_missing', $payload['reason']);
        $this->assertArrayNotHasKey('delta', (array) $payload['counterfactual']);
    }

    public function test_maxl07_series_and_rotation_policy_are_registered(): void
    {
        $entry = collect((new AcosMaxMeasureSeriesRegistry())->entries())
            ->firstWhere('slice', 'MAXL-07');

        $this->assertSame(GoldenCounterfactualReplayService::MEASURE_ID, $entry['series'] ?? null);
        $this->assertSame('command', $entry['source_type'] ?? null);
        $this->assertNotNull((new AcosMaxLedgerRotationRegistry())->policyFor(GoldenCounterfactualReplayService::MEASURE_ID));
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function report(array $options): array
    {
        $exit = Artisan::call('atlas:context:golden-counterfactual', $options + ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);

        return $payload;
    }

    /**
     * @param  list<array<string,mixed>>  $runs
     */
    private function writeRuns(array $runs): void
    {
        file_put_contents($this->runsPath, json_encode(['runs' => $runs], JSON_THROW_ON_ERROR));
    }
}
