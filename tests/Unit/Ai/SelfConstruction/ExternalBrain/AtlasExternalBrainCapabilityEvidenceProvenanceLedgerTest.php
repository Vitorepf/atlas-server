<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCapabilityEvidenceProvenanceLedger;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainCapabilityEvidenceProvenanceLedgerTest extends TestCase
{
    private function ledger(): AtlasExternalBrainCapabilityEvidenceProvenanceLedger
    {
        return new AtlasExternalBrainCapabilityEvidenceProvenanceLedger;
    }

    private function capability(array $overrides = []): array
    {
        return array_merge([
            'capability_id' => 'cap-1',
            'evidence' => [['type' => 'runnable_test_or_gate', 'age_days' => 1]],
            'downstream_uses' => ['organ-x'],
        ], $overrides);
    }

    // ── AC: runnable test/gate evidence stronger than docs-only or screenshot ──

    public function test_runnable_evidence_outranks_docs_only(): void
    {
        $r = $this->ledger()->assess($this->capability([
            'evidence' => [
                ['type' => 'docs_only', 'age_days' => 1],
                ['type' => 'runnable_test_or_gate', 'age_days' => 1],
            ],
        ]));

        $this->assertSame(AtlasExternalBrainCapabilityEvidenceProvenanceLedger::TIER_RUNNABLE_PROOF, $r['evidence_tier']);
    }

    public function test_runnable_evidence_outranks_screenshot(): void
    {
        $r = $this->ledger()->assess($this->capability([
            'evidence' => [
                ['type' => 'screenshot', 'age_days' => 1],
                ['type' => 'runnable_test_or_gate', 'age_days' => 1],
            ],
        ]));

        $this->assertSame(AtlasExternalBrainCapabilityEvidenceProvenanceLedger::TIER_RUNNABLE_PROOF, $r['evidence_tier']);
    }

    public function test_docs_only_outranks_screenshot(): void
    {
        $r = $this->ledger()->assess($this->capability([
            'evidence' => [
                ['type' => 'screenshot', 'age_days' => 1],
                ['type' => 'docs_only', 'age_days' => 1],
            ],
        ]));

        $this->assertSame(AtlasExternalBrainCapabilityEvidenceProvenanceLedger::TIER_DOCS_ONLY, $r['evidence_tier']);
    }

    public function test_runnable_confidence_is_higher_than_docs_only_confidence(): void
    {
        $runnable = $this->ledger()->assess($this->capability(['evidence' => [['type' => 'runnable_test_or_gate', 'age_days' => 1]]]));
        $docs = $this->ledger()->assess($this->capability(['evidence' => [['type' => 'docs_only', 'age_days' => 1]]]));

        $this->assertSame('high', $runnable['confidence']);
        $this->assertSame('medium', $docs['confidence']);
    }

    // ── AC: stale evidence lowers confidence and marks refresh_required=true ──

    public function test_stale_evidence_marks_refresh_required(): void
    {
        $r = $this->ledger()->assess($this->capability([
            'evidence' => [['type' => 'runnable_test_or_gate', 'age_days' => 60]],
        ]));

        $this->assertSame(AtlasExternalBrainCapabilityEvidenceProvenanceLedger::FRESHNESS_STALE, $r['freshness_status']);
        $this->assertTrue($r['refresh_required']);
    }

    public function test_stale_evidence_downgrades_confidence(): void
    {
        $fresh = $this->ledger()->assess($this->capability(['evidence' => [['type' => 'runnable_test_or_gate', 'age_days' => 1]]]));
        $stale = $this->ledger()->assess($this->capability(['evidence' => [['type' => 'runnable_test_or_gate', 'age_days' => 60]]]));

        $this->assertSame('high', $fresh['confidence']);
        $this->assertSame('medium', $stale['confidence']);
    }

    public function test_fresh_evidence_does_not_require_refresh(): void
    {
        $r = $this->ledger()->assess($this->capability(['evidence' => [['type' => 'runnable_test_or_gate', 'age_days' => 1]]]));
        $this->assertFalse($r['refresh_required']);
        $this->assertSame(AtlasExternalBrainCapabilityEvidenceProvenanceLedger::FRESHNESS_FRESH, $r['freshness_status']);
    }

    // ── AC: no downstream use → unproven_for_leverage even with evidence ─────

    public function test_no_downstream_use_marks_unproven_for_leverage_despite_strong_evidence(): void
    {
        $r = $this->ledger()->assess($this->capability([
            'evidence' => [['type' => 'runnable_test_or_gate', 'age_days' => 1]],
            'downstream_uses' => [],
        ]));

        $this->assertSame(AtlasExternalBrainCapabilityEvidenceProvenanceLedger::LEVERAGE_UNPROVEN, $r['leverage_proven']);
    }

    public function test_downstream_use_present_marks_proven(): void
    {
        $r = $this->ledger()->assess($this->capability(['downstream_uses' => ['consumer-a']]));
        $this->assertSame(AtlasExternalBrainCapabilityEvidenceProvenanceLedger::LEVERAGE_PROVEN, $r['leverage_proven']);
    }

    // ── AC: output shape ───────────────────────────────────────────────────────

    public function test_output_has_all_required_keys(): void
    {
        $r = $this->ledger()->assess($this->capability());

        foreach (['capability_id', 'evidence_tier', 'freshness_status', 'confidence', 'refresh_required', 'leverage_proven'] as $key) {
            $this->assertArrayHasKey($key, $r, "Missing key: {$key}");
        }
    }

    // ── no evidence ───────────────────────────────────────────────────────────

    public function test_no_evidence_yields_no_evidence_tier_and_none_confidence(): void
    {
        $r = $this->ledger()->assess($this->capability(['evidence' => []]));

        $this->assertSame(AtlasExternalBrainCapabilityEvidenceProvenanceLedger::TIER_NO_EVIDENCE, $r['evidence_tier']);
        $this->assertSame('none', $r['confidence']);
        $this->assertSame(AtlasExternalBrainCapabilityEvidenceProvenanceLedger::FRESHNESS_UNKNOWN, $r['freshness_status']);
    }

    // ── determinism ───────────────────────────────────────────────────────────

    public function test_assess_is_deterministic(): void
    {
        $capability = $this->capability();
        $a = $this->ledger()->assess($capability);
        $b = $this->ledger()->assess($capability);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
