<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCapabilityEvidenceProvenanceLedger;
use Tests\TestCase;

final class AtlasExternalBrainCapabilityEvidenceProvenanceLedgerTest extends TestCase
{
    private function ledger(): AtlasExternalBrainCapabilityEvidenceProvenanceLedger
    {
        return new AtlasExternalBrainCapabilityEvidenceProvenanceLedger;
    }

    public function test_runnable_test_or_gate_outranks_docs_only_screenshot_raw_queue_count_and_unknown(): void
    {
        $runnable = $this->ledger()->assess([
            'capability_id' => 'a',
            'evidence' => [['type' => 'runnable_test_or_gate', 'age_days' => 1]],
        ]);
        $docs = $this->ledger()->assess([
            'capability_id' => 'b',
            'evidence' => [['type' => 'docs_only', 'age_days' => 1]],
        ]);
        $screenshot = $this->ledger()->assess([
            'capability_id' => 'c',
            'evidence' => [['type' => 'screenshot', 'age_days' => 1]],
        ]);
        $rawCount = $this->ledger()->assess([
            'capability_id' => 'd',
            'evidence' => [['type' => 'raw_queue_count', 'age_days' => 1]],
        ]);
        $unknown = $this->ledger()->assess([
            'capability_id' => 'e',
            'evidence' => [['type' => 'some_unrecognized_type', 'age_days' => 1]],
        ]);
        $missing = $this->ledger()->assess(['capability_id' => 'f']);

        self::assertSame('runnable_proof', $runnable['evidence_tier']);
        self::assertSame('high', $runnable['confidence']);

        self::assertSame('docs_only', $docs['evidence_tier']);
        self::assertSame('medium', $docs['confidence']);

        self::assertSame('screenshot', $screenshot['evidence_tier']);
        self::assertSame('low', $screenshot['confidence']);

        self::assertSame('raw_queue_count', $rawCount['evidence_tier']);
        self::assertSame('very_low', $rawCount['confidence']);

        self::assertSame('raw_queue_count', $unknown['evidence_tier']);
        self::assertSame('no_evidence', $missing['evidence_tier']);
        self::assertSame('none', $missing['confidence']);

        $rank = ['runnable_proof' => 0, 'docs_only' => 1, 'screenshot' => 2, 'raw_queue_count' => 3, 'no_evidence' => 4];
        self::assertLessThan($rank[$docs['evidence_tier']], $rank[$runnable['evidence_tier']]);
        self::assertLessThan($rank[$screenshot['evidence_tier']], $rank[$docs['evidence_tier']]);
        self::assertLessThan($rank[$rawCount['evidence_tier']], $rank[$screenshot['evidence_tier']]);
        self::assertLessThan($rank[$missing['evidence_tier']], $rank[$rawCount['evidence_tier']]);
    }

    public function test_stale_evidence_downgrades_confidence_and_sets_refresh_required(): void
    {
        $stale = $this->ledger()->assess([
            'capability_id' => 'a',
            'evidence' => [['type' => 'runnable_test_or_gate', 'age_days' => 90]],
        ]);
        $fresh = $this->ledger()->assess([
            'capability_id' => 'b',
            'evidence' => [['type' => 'runnable_test_or_gate', 'age_days' => 5]],
        ]);

        self::assertSame('stale', $stale['freshness_status']);
        self::assertTrue($stale['refresh_required']);
        self::assertSame('medium', $stale['confidence']);

        self::assertSame('fresh', $fresh['freshness_status']);
        self::assertFalse($fresh['refresh_required']);
        self::assertSame('high', $fresh['confidence']);
    }

    public function test_leverage_proven_requires_downstream_uses_and_score_is_zero_without_consumer(): void
    {
        $noDownstream = $this->ledger()->assess([
            'capability_id' => 'a',
            'evidence' => [['type' => 'runnable_test_or_gate', 'age_days' => 1]],
        ]);
        $withDownstream = $this->ledger()->assess([
            'capability_id' => 'b',
            'evidence' => [['type' => 'runnable_test_or_gate', 'age_days' => 1]],
            'downstream_uses' => ['app/Console/Commands/SomeCommand.php'],
        ]);

        self::assertSame('unproven_for_leverage', $noDownstream['leverage_proven']);
        self::assertSame(0.0, $noDownstream['downstream_leverage_score']);

        self::assertSame('proven', $withDownstream['leverage_proven']);
        self::assertGreaterThan(0.0, $withDownstream['downstream_leverage_score']);
    }
}
