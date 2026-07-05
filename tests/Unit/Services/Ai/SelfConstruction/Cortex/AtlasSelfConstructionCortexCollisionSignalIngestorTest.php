<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\Cortex;

use App\Services\Ai\SelfConstruction\Cortex\AtlasSelfConstructionCortexCollisionSignalIngestor;
use Tests\TestCase;

final class AtlasSelfConstructionCortexCollisionSignalIngestorTest extends TestCase
{
    private function ingestor(): AtlasSelfConstructionCortexCollisionSignalIngestor
    {
        return new AtlasSelfConstructionCortexCollisionSignalIngestor;
    }

    // ── AC: critical collisions create deny signals ──

    public function test_critical_collision_creates_deny_signal(): void
    {
        $result = $this->ingestor()->ingest([
            ['target_family' => 'refactor', 'severity' => 'critical', 'reason' => 'already_queued'],
        ]);

        $this->assertCount(1, $result['deny_signals']);
        $this->assertSame('refactor', $result['deny_signals'][0]['target_family']);
        $this->assertContains('refactor', $result['denylist']);
    }

    // ── AC: warning collisions create pivot signals ──

    public function test_warning_collision_creates_pivot_signal(): void
    {
        $result = $this->ingestor()->ingest([
            ['target_family' => 'frontend', 'severity' => 'warning', 'reason' => 'high_pressure'],
        ]);

        $this->assertCount(1, $result['pivot_signals']);
        $this->assertSame('frontend', $result['pivot_signals'][0]['target_family']);
        $this->assertContains('frontend', $result['pivot_list']);
    }

    // ── deduplication ──

    public function test_duplicate_signals_deduplicated(): void
    {
        $result = $this->ingestor()->ingest([
            ['target_family' => 'refactor', 'severity' => 'critical', 'reason' => 'a'],
            ['target_family' => 'refactor', 'severity' => 'critical', 'reason' => 'b'],
        ]);

        $this->assertCount(1, $result['signals']);
    }

    // ── unknown severity ignored ──

    public function test_unknown_severity_ignored(): void
    {
        $result = $this->ingestor()->ingest([
            ['target_family' => 'unknown', 'severity' => 'info', 'reason' => 'test'],
        ]);

        $this->assertSame([], $result['signals']);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->ingestor()->ingest([]);

        $this->assertSame(AtlasSelfConstructionCortexCollisionSignalIngestor::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('signals', $result);
        $this->assertArrayHasKey('deny_signals', $result);
        $this->assertArrayHasKey('pivot_signals', $result);
        $this->assertArrayHasKey('denylist', $result);
        $this->assertArrayHasKey('pivot_list', $result);
    }

    public function test_result_is_deterministic(): void
    {
        $collisions = [
            ['target_family' => 'b', 'severity' => 'critical', 'reason' => 'x'],
            ['target_family' => 'a', 'severity' => 'warning', 'reason' => 'y'],
        ];

        $a = $this->ingestor()->ingest($collisions);
        $b = $this->ingestor()->ingest($collisions);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
