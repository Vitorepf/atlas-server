<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Observability;

use App\Services\Ai\AutonomousEvolution\Observability\AtlasLoopProxyDriftFactDetector;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use ReflectionClass;
use Tests\TestCase;

final class AtlasLoopProxyDriftFactDetectorTest extends TestCase
{
    private string $signalsDir;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-06-24T12:00:00Z'));
        $this->signalsDir = sys_get_temp_dir().'/atlas-proxy-drift-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->signalsDir);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        File::deleteDirectory($this->signalsDir);

        parent::tearDown();
    }

    public function test_all_material_decisions_are_not_drifting(): void
    {
        $this->writeSignals(array_fill(0, 20, $this->decision('origination')));

        $result = $this->evaluate();

        $this->assertSame(['schema_version', 'campaign_id', 'sample_size', 'evaluated_at', 'drifting', 'drift_ratio', 'fact_evidence'], array_keys($result));
        $this->assertSame('atlas.loop.proxy_drift_fact.v1', $result['schema_version']);
        $this->assertSame('camp-1', $result['campaign_id']);
        $this->assertSame(20, $result['sample_size']);
        $this->assertSame('2026-06-24T12:00:00+00:00', $result['evaluated_at']);
        $this->assertFalse($result['drifting']);
        $this->assertSame(0.0, $result['drift_ratio']);
        $this->assertSame(['material' => 20, 'proxy' => 0, 'neutral' => 0], $result['fact_evidence']['classified']);
        $this->assertSame(['origination' => 20], $result['fact_evidence']['histogram']);

        $reflection = new ReflectionClass(AtlasLoopProxyDriftFactDetector::class);
        $this->assertSame(0.75, AtlasLoopProxyDriftFactDetector::DRIFT_THRESHOLD);
        $this->assertArrayHasKey('dead_code_removal', $reflection->getConstant('PROXY_WORK_TYPES'));
        $this->assertArrayHasKey('unused_import', $reflection->getConstant('PROXY_WORK_TYPES'));
        $this->assertArrayHasKey('whitespace', $reflection->getConstant('PROXY_WORK_TYPES'));
        $this->assertArrayHasKey('refactor_preserve', $reflection->getConstant('PROXY_WORK_TYPES'));
        $this->assertArrayHasKey('deduplication_only', $reflection->getConstant('PROXY_WORK_TYPES'));
        $this->assertArrayHasKey('origination', $reflection->getConstant('MATERIAL_WORK_TYPES'));
        $this->assertArrayHasKey('capability_leap', $reflection->getConstant('MATERIAL_WORK_TYPES'));
        $this->assertArrayHasKey('feature_add', $reflection->getConstant('MATERIAL_WORK_TYPES'));
        $this->assertArrayHasKey('bugfix', $reflection->getConstant('MATERIAL_WORK_TYPES'));
        $this->assertArrayHasKey('wiring_new', $reflection->getConstant('MATERIAL_WORK_TYPES'));
    }

    public function test_all_proxy_decisions_are_drifting_with_ratio_one(): void
    {
        $this->writeSignals(array_fill(0, 20, $this->decision('dead_code_removal')));

        $result = $this->evaluate();

        $this->assertTrue($result['drifting']);
        $this->assertSame(1.0, $result['drift_ratio']);
        $this->assertFalse($result['fact_evidence']['insufficient_sample']);
        $this->assertSame(['dead_code_removal' => 20], $result['fact_evidence']['histogram']);
    }

    public function test_half_proxy_half_material_is_not_drifting(): void
    {
        $signals = array_merge(
            array_fill(0, 10, $this->decision('dead-code-removal')),
            array_fill(0, 10, $this->decision('feature add')),
        );
        $this->writeSignals($signals);

        $result = $this->evaluate();

        $this->assertFalse($result['drifting']);
        $this->assertSame(0.5, $result['drift_ratio']);
        $this->assertSame(['material' => 10, 'proxy' => 10, 'neutral' => 0], $result['fact_evidence']['classified']);
        $this->assertSame(['dead_code_removal' => 10, 'feature_add' => 10], $result['fact_evidence']['histogram']);
    }

    public function test_insufficient_sample_fails_open_even_when_available_decisions_are_proxy(): void
    {
        $this->writeSignals(array_fill(0, 4, $this->decision('unused_import')));

        $result = $this->evaluate();

        $this->assertFalse($result['drifting']);
        $this->assertTrue($result['fact_evidence']['insufficient_sample']);
        $this->assertSame(4, $result['fact_evidence']['available_sample']);
        $this->assertSame(1.0, $result['drift_ratio']);
    }

    public function test_all_proxy_with_below_cap_signals_reports_drifting_true(): void
    {
        // 10 signals, all proxy, fewer than sample_size 20 but >= ceil(20/4)=5, so sufficient.
        // With the fix the ratio is 10/10=1.0 (not 10/20=0.5), so drift is correctly detected.
        $this->writeSignals(array_fill(0, 10, $this->decision('dead_code_removal')));

        $result = $this->evaluate();

        $this->assertTrue($result['drifting']);
        $this->assertSame(1.0, $result['drift_ratio']);
        $this->assertFalse($result['fact_evidence']['insufficient_sample']);
        $this->assertSame(10, $result['fact_evidence']['available_sample']);
    }

    public function test_unknown_work_type_counts_as_neutral_not_material_or_proxy(): void
    {
        $this->writeSignals(array_fill(0, 20, $this->decision('wild_experiment')));

        $result = $this->evaluate();

        $this->assertFalse($result['drifting']);
        $this->assertSame(0.0, $result['drift_ratio']);
        $this->assertSame(['material' => 0, 'proxy' => 0, 'neutral' => 20], $result['fact_evidence']['classified']);
        $this->assertSame(['wild_experiment' => 20], $result['fact_evidence']['histogram']);
    }

    private function evaluate(): array
    {
        return (new AtlasLoopProxyDriftFactDetector($this->signalsDir))->evaluate('camp-1', 20);
    }

    /**
     * @param  list<array<string,mixed>>  $signals
     */
    private function writeSignals(array $signals): void
    {
        File::put(
            $this->signalsDir.'/2026-06-24.jsonl',
            implode("\n", array_map(static fn (array $signal): string => json_encode($signal, JSON_THROW_ON_ERROR), $signals))."\n",
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function decision(string $workType): array
    {
        return [
            'schema_version' => 'atlas.loop.cycle_signal.v1',
            'emitted_at' => Carbon::now('UTC')->toIso8601String(),
            'stage' => 'DECISION',
            'campaign_id' => 'camp-1',
            'cycle_id' => 'cycle-1',
            'payload' => ['work_type' => $workType],
        ];
    }
}
