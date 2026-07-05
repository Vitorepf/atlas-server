<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\MultiProject;

use App\Services\Ai\SelfConstruction\MultiProject\AtlasProjectLaneContextFreshnessGate;
use Tests\TestCase;

final class AtlasProjectLaneContextFreshnessGateTest extends TestCase
{
    private const NOW = 2_000_000_000;

    private function manifest(array $windows = ['docs_sync' => 3600, 'code_index' => 3600, 'context_pack' => 600]): array
    {
        return ['project_id' => 'lane-x', 'freshness_window_seconds' => $windows];
    }

    public function test_all_evidence_present_and_fresh_is_conformant(): void
    {
        $verdict = (new AtlasProjectLaneContextFreshnessGate)->evaluate($this->manifest(), [
            'now_unix' => self::NOW,
            'docs_sync_last_unix' => self::NOW - 100,
            'code_index_last_unix' => self::NOW - 100,
            'context_pack_hash' => 'abc123',
            'context_pack_last_unix' => self::NOW - 100,
            'queue_namespace_last_unix' => self::NOW - 100,
            'receipt_ledger_hash' => 'rh1',
            'receipt_ledger_last_unix' => self::NOW - 100,
        ]);

        $this->assertTrue($verdict['conformant']);
        $this->assertSame([], $verdict['blockers']);
    }

    public function test_missing_docs_sync_blocks_with_named_reason(): void
    {
        $verdict = (new AtlasProjectLaneContextFreshnessGate)->evaluate($this->manifest(), [
            'now_unix' => self::NOW,
            'code_index_last_unix' => self::NOW - 100,
            'context_pack_hash' => 'abc',
            'context_pack_last_unix' => self::NOW - 100,
        ]);

        $this->assertFalse($verdict['conformant']);
        $this->assertContains('docs_sync_missing', $verdict['blockers']);
    }

    public function test_stale_code_index_blocks_with_named_reason(): void
    {
        $verdict = (new AtlasProjectLaneContextFreshnessGate)->evaluate($this->manifest(['docs_sync' => 3600, 'code_index' => 60, 'context_pack' => 60]), [
            'now_unix' => self::NOW,
            'docs_sync_last_unix' => self::NOW - 100,
            'code_index_last_unix' => self::NOW - 1_000,
            'context_pack_hash' => 'abc',
            'context_pack_last_unix' => self::NOW - 30,
        ]);

        $this->assertFalse($verdict['conformant']);
        $this->assertContains('code_index_stale', $verdict['blockers']);
    }

    public function test_missing_context_pack_hash_blocks(): void
    {
        $verdict = (new AtlasProjectLaneContextFreshnessGate)->evaluate($this->manifest(), [
            'now_unix' => self::NOW,
            'docs_sync_last_unix' => self::NOW - 100,
            'code_index_last_unix' => self::NOW - 100,
            'context_pack_last_unix' => self::NOW - 100,
        ]);

        $this->assertFalse($verdict['conformant']);
        $this->assertContains('context_pack_missing_hash', $verdict['blockers']);
    }

    public function test_per_evidence_freshness_windows_are_independent(): void
    {
        $gate = new AtlasProjectLaneContextFreshnessGate;

        // Tight 10s context_pack window: at age 30 ⇒ stale; long 3600s docs/code/queue/receipt windows ⇒ fresh.
        $verdict = $gate->evaluate($this->manifest(['docs_sync' => 3600, 'code_index' => 3600, 'context_pack' => 10]), [
            'now_unix' => self::NOW,
            'docs_sync_last_unix' => self::NOW - 100,
            'code_index_last_unix' => self::NOW - 100,
            'context_pack_hash' => 'h',
            'context_pack_last_unix' => self::NOW - 30,
            'queue_namespace_last_unix' => self::NOW - 100,
            'receipt_ledger_hash' => 'rh1',
            'receipt_ledger_last_unix' => self::NOW - 100,
        ]);

        $this->assertFalse($verdict['conformant']);
        $this->assertSame(['context_pack_stale'], $verdict['blockers']);
    }

    public function test_project_id_missing_blocks_conformant(): void
    {
        $verdict = (new AtlasProjectLaneContextFreshnessGate)->evaluate(
            ['freshness_window_seconds' => ['docs_sync' => 3600, 'code_index' => 3600, 'context_pack' => 600]],
            ['now_unix' => self::NOW, 'docs_sync_last_unix' => self::NOW - 100,
             'code_index_last_unix' => self::NOW - 100, 'context_pack_hash' => 'h',
             'context_pack_last_unix' => self::NOW - 100]
        );

        $this->assertFalse($verdict['conformant']);
        $this->assertContains('project_id_missing', $verdict['blockers']);
    }

    public function test_missing_now_unix_blocks_conformant(): void
    {
        $verdict = (new AtlasProjectLaneContextFreshnessGate)->evaluate(
            ['project_id' => 'lane-x', 'freshness_window_seconds' => ['docs_sync' => 3600, 'code_index' => 3600, 'context_pack' => 600]],
            ['docs_sync_last_unix' => 100, 'code_index_last_unix' => 100, 'context_pack_hash' => 'h', 'context_pack_last_unix' => 100]
        );

        $this->assertFalse($verdict['conformant']);
        $this->assertContains('now_unix_missing', $verdict['blockers']);
    }

    public function test_invalid_freshness_window_blocks_conformant(): void
    {
        $verdict = (new AtlasProjectLaneContextFreshnessGate)->evaluate(
            ['project_id' => 'lane-x', 'freshness_window_seconds' => ['docs_sync' => -1, 'code_index' => 3600, 'context_pack' => 600]],
            ['now_unix' => self::NOW, 'docs_sync_last_unix' => self::NOW - 100,
             'code_index_last_unix' => self::NOW - 100, 'context_pack_hash' => 'h',
             'context_pack_last_unix' => self::NOW - 100]
        );

        $this->assertFalse($verdict['conformant']);
        $this->assertContains('invalid_freshness_window:docs_sync', $verdict['blockers']);
    }

    public function test_context_pack_project_mismatch_blocks_conformant(): void
    {
        $verdict = (new AtlasProjectLaneContextFreshnessGate)->evaluate(
            $this->manifest(),
            ['now_unix' => self::NOW, 'docs_sync_last_unix' => self::NOW - 100,
             'code_index_last_unix' => self::NOW - 100, 'context_pack_hash' => 'h',
             'context_pack_last_unix' => self::NOW - 100, 'context_pack_project_id' => 'other-lane']
        );

        $this->assertFalse($verdict['conformant']);
        $this->assertContains('context_pack_project_mismatch', $verdict['blockers']);
    }

    public function test_fresh_all_evidence_same_project_lane_passes(): void
    {
        $verdict = (new AtlasProjectLaneContextFreshnessGate)->evaluate(
            $this->manifest(),
            ['now_unix' => self::NOW, 'docs_sync_last_unix' => self::NOW - 100,
             'code_index_last_unix' => self::NOW - 100, 'context_pack_hash' => 'h',
             'context_pack_last_unix' => self::NOW - 100, 'context_pack_project_id' => 'lane-x',
             'queue_namespace_last_unix' => self::NOW - 100,
             'receipt_ledger_hash' => 'rh1', 'receipt_ledger_last_unix' => self::NOW - 100]
        );

        $this->assertTrue($verdict['conformant']);
        $this->assertSame([], $verdict['blockers']);
    }

    // ── queue_namespace + receipt_ledger ──────────────────────────────────────

    public function test_missing_queue_namespace_blocks_with_named_reason(): void
    {
        $verdict = (new AtlasProjectLaneContextFreshnessGate)->evaluate($this->manifest(), [
            'now_unix' => self::NOW,
            'docs_sync_last_unix' => self::NOW - 100,
            'code_index_last_unix' => self::NOW - 100,
            'context_pack_hash' => 'h',
            'context_pack_last_unix' => self::NOW - 100,
            'receipt_ledger_hash' => 'rh1',
            'receipt_ledger_last_unix' => self::NOW - 100,
        ]);

        $this->assertFalse($verdict['conformant']);
        $this->assertContains('queue_namespace_missing', $verdict['blockers']);
    }

    public function test_stale_queue_namespace_blocks_with_named_reason(): void
    {
        $verdict = (new AtlasProjectLaneContextFreshnessGate)->evaluate(
            ['project_id' => 'lane-x', 'freshness_window_seconds' => ['docs_sync' => 3600, 'code_index' => 3600, 'context_pack' => 3600, 'queue_namespace' => 60, 'receipt_ledger' => 3600]],
            ['now_unix' => self::NOW,
             'docs_sync_last_unix' => self::NOW - 100,
             'code_index_last_unix' => self::NOW - 100,
             'context_pack_hash' => 'h', 'context_pack_last_unix' => self::NOW - 100,
             'queue_namespace_last_unix' => self::NOW - 1_000,
             'receipt_ledger_hash' => 'rh1', 'receipt_ledger_last_unix' => self::NOW - 100]
        );

        $this->assertFalse($verdict['conformant']);
        $this->assertContains('queue_namespace_stale', $verdict['blockers']);
    }

    public function test_missing_receipt_ledger_hash_blocks(): void
    {
        $verdict = (new AtlasProjectLaneContextFreshnessGate)->evaluate($this->manifest(), [
            'now_unix' => self::NOW,
            'docs_sync_last_unix' => self::NOW - 100,
            'code_index_last_unix' => self::NOW - 100,
            'context_pack_hash' => 'h',
            'context_pack_last_unix' => self::NOW - 100,
            'queue_namespace_last_unix' => self::NOW - 100,
            'receipt_ledger_last_unix' => self::NOW - 100,
        ]);

        $this->assertFalse($verdict['conformant']);
        $this->assertContains('receipt_ledger_hash_missing', $verdict['blockers']);
    }

    public function test_stale_receipt_ledger_blocks(): void
    {
        $verdict = (new AtlasProjectLaneContextFreshnessGate)->evaluate(
            ['project_id' => 'lane-x', 'freshness_window_seconds' => ['docs_sync' => 3600, 'code_index' => 3600, 'context_pack' => 3600, 'queue_namespace' => 3600, 'receipt_ledger' => 60]],
            ['now_unix' => self::NOW,
             'docs_sync_last_unix' => self::NOW - 100,
             'code_index_last_unix' => self::NOW - 100,
             'context_pack_hash' => 'h', 'context_pack_last_unix' => self::NOW - 100,
             'queue_namespace_last_unix' => self::NOW - 100,
             'receipt_ledger_hash' => 'rh1', 'receipt_ledger_last_unix' => self::NOW - 1_000]
        );

        $this->assertFalse($verdict['conformant']);
        $this->assertContains('receipt_ledger_stale', $verdict['blockers']);
    }

    public function test_verdict_carries_no_numeric_score_field(): void
    {
        $verdict = (new AtlasProjectLaneContextFreshnessGate)->evaluate($this->manifest(), ['now_unix' => self::NOW]);

        foreach (array_keys($verdict) as $key) {
            $this->assertStringNotContainsString('score', strtolower((string) $key), 'verdict must NOT carry a numeric score field');
        }
    }

    public function test_evaluation_is_deterministic_byte_identical(): void
    {
        $gate = new AtlasProjectLaneContextFreshnessGate;
        $obs = [
            'now_unix' => self::NOW,
            'docs_sync_last_unix' => self::NOW - 100,
            'code_index_last_unix' => self::NOW - 100,
            'context_pack_hash' => 'h',
            'context_pack_last_unix' => self::NOW - 100,
        ];

        $this->assertSame(json_encode($gate->evaluate($this->manifest(), $obs)), json_encode($gate->evaluate($this->manifest(), $obs)));
    }

    // ── evaluateReadiness(): freshness breakdown + memory_snapshot + readiness_status ──

    private function freshObservations(): array
    {
        return [
            'now_unix' => self::NOW,
            'docs_sync_last_unix' => self::NOW - 100,
            'code_index_last_unix' => self::NOW - 100,
            'context_pack_hash' => 'h',
            'context_pack_last_unix' => self::NOW - 100,
            'queue_namespace_last_unix' => self::NOW - 100,
            'receipt_ledger_hash' => 'rh1',
            'receipt_ledger_last_unix' => self::NOW - 100,
            'memory_snapshot_last_unix' => self::NOW - 100,
        ];
    }

    // ── AC: fresh lane ────────────────────────────────────────────────────────────

    public function test_fresh_lane_reports_all_dimensions_fresh_and_ready(): void
    {
        $verdict = (new AtlasProjectLaneContextFreshnessGate)->evaluateReadiness($this->manifest(), $this->freshObservations());

        foreach (['context_pack', 'code_index', 'docs', 'queue_state', 'memory_snapshot'] as $dim) {
            $this->assertSame(AtlasProjectLaneContextFreshnessGate::FRESHNESS_FRESH, $verdict['freshness'][$dim], "expected {$dim} fresh");
        }
        $this->assertSame(AtlasProjectLaneContextFreshnessGate::READINESS_READY, $verdict['readiness_status']);
        $this->assertSame([], $verdict['blockers']);
    }

    // ── AC: stale docs ────────────────────────────────────────────────────────────

    public function test_stale_docs_reports_degraded_readiness(): void
    {
        $observations = $this->freshObservations();
        $observations['docs_sync_last_unix'] = self::NOW - 10_000;

        $verdict = (new AtlasProjectLaneContextFreshnessGate)->evaluateReadiness($this->manifest(), $observations);

        $this->assertSame(AtlasProjectLaneContextFreshnessGate::FRESHNESS_STALE, $verdict['freshness']['docs']);
        $this->assertSame(AtlasProjectLaneContextFreshnessGate::READINESS_DEGRADED, $verdict['readiness_status']);
    }

    // ── AC: stale code index ──────────────────────────────────────────────────────

    public function test_stale_code_index_reports_degraded_readiness(): void
    {
        $observations = $this->freshObservations();
        $observations['code_index_last_unix'] = self::NOW - 10_000;

        $verdict = (new AtlasProjectLaneContextFreshnessGate)->evaluateReadiness($this->manifest(), $observations);

        $this->assertSame(AtlasProjectLaneContextFreshnessGate::FRESHNESS_STALE, $verdict['freshness']['code_index']);
        $this->assertSame(AtlasProjectLaneContextFreshnessGate::READINESS_DEGRADED, $verdict['readiness_status']);
    }

    // ── AC: stale memory ──────────────────────────────────────────────────────────

    public function test_stale_memory_snapshot_reports_degraded_readiness(): void
    {
        $observations = $this->freshObservations();
        $observations['memory_snapshot_last_unix'] = self::NOW - 10_000;

        $verdict = (new AtlasProjectLaneContextFreshnessGate)->evaluateReadiness($this->manifest(), $observations);

        $this->assertSame(AtlasProjectLaneContextFreshnessGate::FRESHNESS_STALE, $verdict['freshness']['memory_snapshot']);
        $this->assertContains('memory_snapshot_stale', $verdict['blockers']);
        $this->assertSame(AtlasProjectLaneContextFreshnessGate::READINESS_DEGRADED, $verdict['readiness_status']);
    }

    public function test_missing_memory_snapshot_is_never_assumed_fresh(): void
    {
        $observations = $this->freshObservations();
        unset($observations['memory_snapshot_last_unix']);

        $verdict = (new AtlasProjectLaneContextFreshnessGate)->evaluateReadiness($this->manifest(), $observations);

        $this->assertSame(AtlasProjectLaneContextFreshnessGate::FRESHNESS_MISSING, $verdict['freshness']['memory_snapshot']);
    }

    // ── AC: missing queue state ───────────────────────────────────────────────────

    public function test_missing_queue_state_reports_degraded_readiness(): void
    {
        $observations = $this->freshObservations();
        unset($observations['queue_namespace_last_unix']);

        $verdict = (new AtlasProjectLaneContextFreshnessGate)->evaluateReadiness($this->manifest(), $observations);

        $this->assertSame(AtlasProjectLaneContextFreshnessGate::FRESHNESS_MISSING, $verdict['freshness']['queue_state']);
        $this->assertSame(AtlasProjectLaneContextFreshnessGate::READINESS_DEGRADED, $verdict['readiness_status']);
    }

    // ── AC: mixed degraded readiness ──────────────────────────────────────────────

    public function test_mixed_stale_and_missing_dimensions_report_degraded_not_blocked(): void
    {
        $observations = $this->freshObservations();
        $observations['docs_sync_last_unix'] = self::NOW - 10_000;
        unset($observations['queue_namespace_last_unix']);
        $observations['memory_snapshot_last_unix'] = self::NOW - 10_000;

        $verdict = (new AtlasProjectLaneContextFreshnessGate)->evaluateReadiness($this->manifest(), $observations);

        $this->assertSame(AtlasProjectLaneContextFreshnessGate::FRESHNESS_STALE, $verdict['freshness']['docs']);
        $this->assertSame(AtlasProjectLaneContextFreshnessGate::FRESHNESS_MISSING, $verdict['freshness']['queue_state']);
        $this->assertSame(AtlasProjectLaneContextFreshnessGate::FRESHNESS_STALE, $verdict['freshness']['memory_snapshot']);
        $this->assertSame(AtlasProjectLaneContextFreshnessGate::READINESS_DEGRADED, $verdict['readiness_status']);
        $this->assertGreaterThanOrEqual(3, count($verdict['blockers']));
    }

    public function test_structural_misconfiguration_reports_blocked_not_degraded(): void
    {
        $manifest = ['freshness_window_seconds' => ['docs_sync' => 3600, 'code_index' => 3600, 'context_pack' => 600]];

        $verdict = (new AtlasProjectLaneContextFreshnessGate)->evaluateReadiness($manifest, $this->freshObservations());

        $this->assertSame(AtlasProjectLaneContextFreshnessGate::READINESS_BLOCKED, $verdict['readiness_status']);
    }
}
