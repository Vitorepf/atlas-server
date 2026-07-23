<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainSimplificationReplayHarness;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainSimplificationReplayHarnessTest extends TestCase
{
    private function harness(): AtlasExternalBrainSimplificationReplayHarness
    {
        return new AtlasExternalBrainSimplificationReplayHarness;
    }

    private function receipt(array $overrides = []): array
    {
        return array_merge([
            'output' => ['status' => 'ok'],
            'errors' => [],
            'evidence_hash' => 'hash-abc',
            'side_effect_summary' => 'writes_none',
        ], $overrides);
    }

    // ── AC: matching_replay_case ────────────────────────────────────────────

    public function test_matching_replay_case_is_approved_with_empty_replay_diff(): void
    {
        $r = $this->harness()->replay([
            'before_receipts' => $this->receipt(),
            'after_receipts' => $this->receipt(),
        ]);

        $this->assertTrue($r['approved']);
        $this->assertSame('match', $r['verdict']);
        $this->assertSame([], $r['replay_diff']);
    }

    // ── AC: divergent_replay_fail_case ──────────────────────────────────────

    public function test_output_divergence_fails_closed(): void
    {
        $r = $this->harness()->replay([
            'before_receipts' => $this->receipt(['output' => ['status' => 'ok']]),
            'after_receipts' => $this->receipt(['output' => ['status' => 'changed']]),
        ]);

        $this->assertFalse($r['approved']);
        $this->assertSame('divergent', $r['verdict']);
        $this->assertContains('output_divergence', $r['replay_diff']);
    }

    public function test_error_divergence_fails_closed(): void
    {
        $r = $this->harness()->replay([
            'before_receipts' => $this->receipt(['errors' => []]),
            'after_receipts' => $this->receipt(['errors' => ['RuntimeException']]),
        ]);

        $this->assertFalse($r['approved']);
        $this->assertContains('error_divergence', $r['replay_diff']);
    }

    public function test_evidence_hash_divergence_fails_closed(): void
    {
        $r = $this->harness()->replay([
            'before_receipts' => $this->receipt(['evidence_hash' => 'hash-abc']),
            'after_receipts' => $this->receipt(['evidence_hash' => 'hash-xyz']),
        ]);

        $this->assertFalse($r['approved']);
        $this->assertContains('evidence_hash_divergence', $r['replay_diff']);
    }

    public function test_side_effect_summary_divergence_fails_closed(): void
    {
        $r = $this->harness()->replay([
            'before_receipts' => $this->receipt(['side_effect_summary' => 'writes_none']),
            'after_receipts' => $this->receipt(['side_effect_summary' => 'writes_db']),
        ]);

        $this->assertFalse($r['approved']);
        $this->assertContains('side_effect_summary_divergence', $r['replay_diff']);
    }

    // ── AC: missing receipt fails closed with exact replay_diff entries ──────

    public function test_missing_before_receipt_fails_closed(): void
    {
        $r = $this->harness()->replay(['after_receipts' => $this->receipt()]);

        $this->assertFalse($r['approved']);
        $this->assertSame(['missing_before_receipt'], $r['replay_diff']);
    }

    public function test_missing_after_receipt_fails_closed(): void
    {
        $r = $this->harness()->replay(['before_receipts' => $this->receipt()]);

        $this->assertFalse($r['approved']);
        $this->assertSame(['missing_after_receipt'], $r['replay_diff']);
    }

    public function test_both_receipts_missing_names_both_gaps(): void
    {
        $r = $this->harness()->replay([]);

        $this->assertFalse($r['approved']);
        $this->assertSame(['missing_before_receipt', 'missing_after_receipt'], $r['replay_diff']);
    }

    public function test_multiple_divergences_are_all_named(): void
    {
        $r = $this->harness()->replay([
            'before_receipts' => $this->receipt(),
            'after_receipts' => $this->receipt(['evidence_hash' => 'different', 'side_effect_summary' => 'writes_db']),
        ]);

        $this->assertContains('evidence_hash_divergence', $r['replay_diff']);
        $this->assertContains('side_effect_summary_divergence', $r['replay_diff']);
        $this->assertCount(2, $r['replay_diff']);
    }

    // ── Determinism ────────────────────────────────────────────────────────

    public function test_replay_is_deterministic(): void
    {
        $facts = ['before_receipts' => $this->receipt(), 'after_receipts' => $this->receipt()];
        $a = $this->harness()->replay($facts);
        $b = $this->harness()->replay($facts);

        $this->assertSame(json_encode($a), json_encode($b));
    }

    public function test_schema_version_present(): void
    {
        $r = $this->harness()->replay([]);
        $this->assertSame(AtlasExternalBrainSimplificationReplayHarness::SCHEMA, $r['schema']);
    }
}
