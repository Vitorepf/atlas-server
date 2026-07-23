<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCompoundingReadinessManifest;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainCompoundingReadinessManifestTest extends TestCase
{
    public function test_fixture_proof_is_reported_but_temporal_and_comparative_gaps_remain_explicit(): void
    {
        $result = (new AtlasExternalBrainCompoundingReadinessManifest)->evaluate([
            'fixtures' => [['id' => 'longitudinal', 'passed' => true, 'evidence_refs' => ['ledger://fixture-1']]],
            'live_evidence_refs' => ['ledger://fixture-1'], 'outcome_windows_elapsed' => false, 'real_campaigns' => 1,
        ]);

        self::assertSame('implemented_not_proven', $result['status']);
        self::assertTrue($result['implemented_fixture_proof']);
        self::assertContains('real_0h_150d_outcome_window_not_elapsed', $result['temporal_gaps']);
        self::assertContains('three_real_comparative_campaigns_missing', $result['comparative_gaps']);
        self::assertFalse($result['claim_allowed']);
    }

    public function test_missing_live_refs_or_failed_fixture_blocks_readiness(): void
    {
        $result = (new AtlasExternalBrainCompoundingReadinessManifest)->evaluate([
            'fixtures' => [['id' => 'late-regression', 'passed' => false]], 'live_evidence_refs' => [],
        ]);

        self::assertSame('blocked', $result['status']);
        self::assertFalse($result['implemented_fixture_proof']);
        self::assertContains('fixture_failures_present', $result['blockers']);
        self::assertContains('live_evidence_refs_missing', $result['blockers']);
        self::assertFalse($result['wave_promotion_allowed']);
    }

    public function test_manifest_is_deterministic_and_non_mutative(): void
    {
        $input = ['fixtures' => [['id' => 'x', 'passed' => true, 'evidence_refs' => ['r:x']]], 'live_evidence_refs' => ['r:x'], 'temporal_gaps' => ['gap:t'], 'comparative_gaps' => ['gap:c']];
        $service = new AtlasExternalBrainCompoundingReadinessManifest;
        self::assertSame($service->evaluate($input), $service->evaluate($input));
        self::assertFalse($service->evaluate($input)['mutates_claims_or_routes']);
    }
}
