<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AtlasDecide;

use App\Services\Ai\AtlasDecide\AtlasDecideLiveOutcomeFeedbackService;
use Tests\TestCase;

class AtlasDecideLiveOutcomeFeedbackServiceTest extends TestCase
{
    private string $log;

    private AtlasDecideLiveOutcomeFeedbackService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->log = sys_get_temp_dir().'/atlas_live_outcome_'.uniqid('', true).'.jsonl';
        $this->svc = new AtlasDecideLiveOutcomeFeedbackService;
        $this->svc->setLogPathForTesting($this->log);
    }

    protected function tearDown(): void
    {
        @unlink($this->log);
        parent::tearDown();
    }

    public function test_record_envelope_shape(): void
    {
        $entry = $this->svc->record([
            'task_category' => 'code_generation',
            'role' => 'primary',
            'framework' => 'laravel',
            'provider' => 'claude_cli',
            'model' => 'opus-4.7',
            'result' => 'success',
            'latency_ms' => 1200,
            'quality_score' => 0.92,
        ]);
        $this->assertSame(AtlasDecideLiveOutcomeFeedbackService::OUTCOME_SCHEMA, $entry['schema_version']);
        $this->assertStringStartsWith('sha256:', $entry['entry_hash']);
        $this->assertSame('claude_cli', $entry['provider']);
        $this->assertSame(0.92, $entry['quality_score']);
    }

    public function test_record_requires_task_category_role_and_provider(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->record(['result' => 'success']);
    }

    public function test_record_rejects_unknown_result(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->record([
            'task_category' => 'a', 'role' => 'b', 'provider' => 'c', 'result' => 'maybe',
        ]);
    }

    private function seedOutcomes(string $provider, int $success, int $failure, int $timeout = 0): void
    {
        $base = [
            'task_category' => 'code_generation',
            'role' => 'primary',
            'framework' => 'laravel',
            'provider' => $provider,
            'model' => 'm',
        ];
        for ($i = 0; $i < $success; $i++) {
            $this->svc->record($base + ['result' => 'success', 'latency_ms' => 100]);
        }
        for ($i = 0; $i < $failure; $i++) {
            $this->svc->record($base + ['result' => 'failure', 'latency_ms' => 100]);
        }
        for ($i = 0; $i < $timeout; $i++) {
            $this->svc->record($base + ['result' => 'timeout', 'latency_ms' => 5000]);
        }
    }

    public function test_route_stats_groups_by_provider(): void
    {
        $this->seedOutcomes('claude_cli', 9, 1);
        $this->seedOutcomes('codex_cli', 4, 6);

        $stats = $this->svc->routeStats('code_generation', 'primary', 'laravel');
        $this->assertCount(2, $stats['providers']);
        $byKey = [];
        foreach ($stats['providers'] as $p) {
            $byKey[$p['provider']] = $p;
        }
        $this->assertSame(0.9, $byKey['claude_cli']['success_rate']);
        $this->assertSame(0.4, $byKey['codex_cli']['success_rate']);
    }

    public function test_degradation_signal_insufficient_evidence(): void
    {
        $this->seedOutcomes('claude_cli', 2, 0); // below MIN_CALLS_FOR_SIGNAL=5
        $sig = $this->svc->degradationSignal('code_generation', 'primary', 'laravel', 'claude_cli', 'm');
        $this->assertSame(
            AtlasDecideLiveOutcomeFeedbackService::SIGNAL_INSUFFICIENT_EVIDENCE,
            $sig['signal']
        );
        $this->assertNull($sig['success_rate']);
    }

    public function test_degradation_signal_healthy(): void
    {
        $this->seedOutcomes('claude_cli', 9, 1);
        $sig = $this->svc->degradationSignal('code_generation', 'primary', 'laravel', 'claude_cli', 'm');
        $this->assertSame(AtlasDecideLiveOutcomeFeedbackService::SIGNAL_HEALTHY, $sig['signal']);
        $this->assertSame(0.9, $sig['success_rate']);
    }

    public function test_degradation_signal_degrading(): void
    {
        // 6/10 = 0.6 — below DEGRADATION_THRESHOLD (0.7), above BROKEN_THRESHOLD (0.4)
        $this->seedOutcomes('claude_cli', 6, 4);
        $sig = $this->svc->degradationSignal('code_generation', 'primary', 'laravel', 'claude_cli', 'm');
        $this->assertSame(AtlasDecideLiveOutcomeFeedbackService::SIGNAL_DEGRADING, $sig['signal']);
    }

    public function test_degradation_signal_broken(): void
    {
        // 3/10 = 0.3 — below BROKEN_THRESHOLD (0.4)
        $this->seedOutcomes('claude_cli', 3, 7);
        $sig = $this->svc->degradationSignal('code_generation', 'primary', 'laravel', 'claude_cli', 'm');
        $this->assertSame(AtlasDecideLiveOutcomeFeedbackService::SIGNAL_BROKEN, $sig['signal']);
    }

    public function test_envelope_carries_thresholds_and_hash(): void
    {
        $this->seedOutcomes('claude_cli', 9, 1);
        $sig = $this->svc->degradationSignal('code_generation', 'primary', 'laravel', 'claude_cli', 'm');
        $this->assertSame(AtlasDecideLiveOutcomeFeedbackService::SIGNAL_SCHEMA, $sig['schema_version']);
        $this->assertStringStartsWith('sha256:', $sig['envelope_hash']);
        $this->assertSame(0.7, $sig['thresholds']['degradation_threshold']);
        $this->assertSame(0.4, $sig['thresholds']['broken_threshold']);
    }
}
