<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Anomaly\AtlasLoopAnomalyReceiptLedger;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasLoopAnomalyCommandTest extends TestCase
{
    private string $factsPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->factsPath = sys_get_temp_dir().'/atlas_anomaly_facts_'.bin2hex(random_bytes(6)).'.json';
        // Share a singleton ledger so history sees what inspect appends within the same test.
        $this->app->singleton(AtlasLoopAnomalyReceiptLedger::class);
    }

    protected function tearDown(): void
    {
        @unlink($this->factsPath);
        parent::tearDown();
    }

    private function writeJson(array $data): void
    {
        file_put_contents($this->factsPath, json_encode($data, JSON_UNESCAPED_SLASHES));
    }

    public function test_baseline_action_emits_signal_counts_and_rates(): void
    {
        $this->writeJson([
            'window' => ['start' => '2026-06-01T00:00:00Z', 'end' => '2026-06-08T00:00:00Z'],
            'receipts' => [
                ['ts' => '2026-06-02T00:00:00Z', 'outcome' => 'completion'],
                ['ts' => '2026-06-03T00:00:00Z', 'outcome' => 'completion'],
                ['ts' => '2026-06-04T00:00:00Z', 'outcome' => 'give_back'],
                ['ts' => '2026-06-05T00:00:00Z', 'outcome' => 'merge'],
                ['ts' => '2026-06-06T00:00:00Z', 'outcome' => 'cancel'],
            ],
        ]);
        Artisan::call('atlas:loop:anomaly', ['action' => 'baseline', '--facts' => $this->factsPath, '--json' => true]);
        $output = trim(Artisan::output());
        $p = json_decode($output, true);

        foreach (['completion', 'give_back', 'merge', 'cancel'] as $signal) {
            $this->assertArrayHasKey($signal, $p);
            $this->assertArrayHasKey('count_in_window', $p[$signal]);
            $this->assertArrayHasKey('rate', $p[$signal]);
        }
        $this->assertNoSeverityAdjective($output);
    }

    public function test_inspect_appends_matching_deviations_to_the_ledger(): void
    {
        // Baseline with low rate; current rate spikes well above threshold.
        $this->writeJson([
            'baseline' => [
                'give_back' => [
                    'rate' => 0.05,
                    'buckets' => [0.05, 0.05, 0.04, 0.06, 0.05, 0.05, 0.04],
                ],
            ],
            'current_window' => [
                'window_start' => '2026-06-20T00:00:00Z',
                'window_end' => '2026-06-21T00:00:00Z',
                'give_back' => ['rate' => 0.95],
            ],
            'observed_at' => '2026-06-21T00:05:00Z',
        ]);

        Artisan::call('atlas:loop:anomaly', ['action' => 'inspect', '--facts' => $this->factsPath, '--json' => true]);
        $output = trim(Artisan::output());
        $p = json_decode($output, true);

        $this->assertGreaterThanOrEqual(1, count($p['detected']));
        $this->assertGreaterThanOrEqual(1, count($p['appended']));
        $this->assertSame('give_back', $p['detected'][0]['signal']);
        $this->assertNoSeverityAdjective($output);
    }

    public function test_history_returns_appended_facts_for_signal(): void
    {
        $ledger = $this->app->make(AtlasLoopAnomalyReceiptLedger::class);
        $ledger->append([
            'signal' => 'give_back',
            'baseline_rate' => 0.05,
            'current_rate' => 0.95,
            'delta_in_sigmas' => 6.0,
            'window_start' => '2026-06-20T00:00:00Z',
            'window_end' => '2026-06-21T00:00:00Z',
            'observed_at' => '2026-06-21T00:05:00Z',
        ]);

        Artisan::call('atlas:loop:anomaly', ['action' => 'history', '--signal' => 'give_back', '--since' => '2026-06-19T00:00:00Z', '--until' => '2026-06-22T00:00:00Z', '--json' => true]);
        $output = trim(Artisan::output());
        $p = json_decode($output, true);

        $this->assertSame('give_back', $p['signal']);
        $this->assertCount(1, $p['rows']);
        $this->assertSame(0.05, $p['rows'][0]['baseline_rate']);
        $this->assertNoSeverityAdjective($output);
    }

    public function test_unknown_action_yields_error(): void
    {
        $exit = Artisan::call('atlas:loop:anomaly', ['action' => 'bogus', '--json' => true]);
        $this->assertNotSame(0, $exit);
    }

    private function assertNoSeverityAdjective(string $stdout): void
    {
        foreach (['anomaly', 'alert', 'critical', 'warning', 'suspicious'] as $word) {
            $this->assertStringNotContainsStringIgnoringCase($word, $stdout, "stdout must NOT contain severity adjective '{$word}'");
        }
    }
}
