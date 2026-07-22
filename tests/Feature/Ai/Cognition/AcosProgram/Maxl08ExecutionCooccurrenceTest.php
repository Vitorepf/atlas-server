<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Cognition\AcosProgram;

use App\Services\Ai\Cognition\AcosProgram\AcosMaxLedgerRotationRegistry;
use App\Services\Ai\Cognition\AcosProgram\AcosMaxMeasureSeriesRegistry;
use App\Services\Ai\Cognition\AcosProgram\ExecutionContextCooccurrenceService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class Maxl08ExecutionCooccurrenceTest extends TestCase
{
    private string $runsPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runsPath = sys_get_temp_dir().'/atlas_maxl08_'.uniqid('', true).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->runsPath);
        parent::tearDown();
    }

    public function test_measured_run_reports_correlational_cooccurrence_with_denominators(): void
    {
        $this->writeRuns([
            [
                'run_id' => 'run-1',
                'measured' => true,
                'green_run' => true,
                'outcome_receipt_id' => 'green-1',
                'delivered_refs' => ['memory:a', 'memory:b'],
                'used_refs' => ['memory:b', 'memory:c'],
            ],
            [
                'run_id' => 'run-2',
                'measured' => false,
                'green_run' => false,
                'delivered_refs' => ['memory:d'],
                'used_refs' => ['memory:d'],
            ],
        ]);

        $payload = $this->report(['--runs' => $this->runsPath]);

        $this->assertSame('ok', $payload['status']);
        $this->assertSame('correlational_cooccurrence', $payload['context_causal_binding']);
        $this->assertFalse($payload['enforcement_allowed']);
        $this->assertSame(2, data_get($payload, 'denominator.runs'));
        $this->assertSame(1, data_get($payload, 'denominator.measured_runs'));
        $this->assertSame(0.5, data_get($payload, 'denominator.measured_share'));
        $this->assertSame(['memory:b'], data_get($payload, 'cooccurrences.0.intersection_refs'));
        $this->assertTrue(data_get($payload, 'claim_policy.intersection_alone_is_not_causal'));
    }

    public function test_measured_share_zero_is_unmeasurable_without_number(): void
    {
        $this->writeRuns([[
            'run_id' => 'run-1',
            'measured' => false,
            'green_run' => true,
            'delivered_refs' => ['memory:a'],
            'used_refs' => ['memory:a'],
        ]]);

        $payload = $this->report(['--runs' => $this->runsPath]);

        $this->assertSame('unmeasurable', $payload['status']);
        $this->assertSame('measured_share_zero', $payload['reason']);
        $this->assertSame([], $payload['cooccurrences']);
        $this->assertSame(0.0, data_get($payload, 'denominator.measured_share'));
    }

    public function test_maxl08_series_and_rotation_policy_are_registered(): void
    {
        $entry = collect((new AcosMaxMeasureSeriesRegistry())->entries())
            ->firstWhere('slice', 'MAXL-08');

        $this->assertSame(ExecutionContextCooccurrenceService::MEASURE_ID, $entry['series'] ?? null);
        $this->assertSame('command', $entry['source_type'] ?? null);
        $this->assertNotNull((new AcosMaxLedgerRotationRegistry())->policyFor(ExecutionContextCooccurrenceService::MEASURE_ID));
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function report(array $options): array
    {
        $exit = Artisan::call('atlas:context:execution-cooccurrence', $options + ['--json' => true]);
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
