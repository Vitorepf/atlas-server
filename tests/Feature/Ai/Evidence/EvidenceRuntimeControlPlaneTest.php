<?php

namespace Tests\Feature\Ai\Evidence;

use App\Services\Ai\Evidence\BlockerService;
use App\Services\Ai\Evidence\ClaimVerificationService;
use App\Services\Ai\Evidence\EvidenceControlPlaneService;
use App\Services\Ai\Evidence\EvidencePackService;
use Tests\Concerns\CreatesEvidenceRuntimeTables;
use Tests\TestCase;

class EvidenceRuntimeControlPlaneTest extends TestCase
{
    use CreatesEvidenceRuntimeTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createEvidenceRuntimeTables();
    }

    protected function tearDown(): void
    {
        $this->dropEvidenceRuntimeTables();
        parent::tearDown();
    }

    public function test_control_plane_snapshot_returns_canonical_schema(): void
    {
        /** @var EvidenceControlPlaneService $controlPlane */
        $controlPlane = app(EvidenceControlPlaneService::class);
        $snapshot = $controlPlane->snapshot();
        $this->assertSame(EvidenceControlPlaneService::SCHEMA, $snapshot['schema']);
        $this->assertArrayHasKey('totals', $snapshot);
        $this->assertArrayHasKey('open_blockers_count', $snapshot);
        $this->assertArrayHasKey('unverified_claims_count', $snapshot);
        $this->assertArrayHasKey('recent_events', $snapshot);
    }

    public function test_unverified_claim_counts_show_up(): void
    {
        /** @var ClaimVerificationService $claims */
        $claims = app(ClaimVerificationService::class);
        $claims->register([
            'claim_text' => 'unverified A',
            'claim_type' => ClaimVerificationService::TYPE_INFERRED,
        ]);
        $claims->register([
            'claim_text' => 'superiority blocked',
            'claim_type' => ClaimVerificationService::TYPE_SUPERIORITY,
        ]);

        /** @var BlockerService $blockers */
        $blockers = app(BlockerService::class);
        $blockers->open([
            'target_type' => EvidencePackService::TARGET_MISSION,
            'target_id' => 'mission-cp',
            'blocker_type' => BlockerService::KIND_LEGAL_OR_POLICY,
            'severity' => BlockerService::SEVERITY_CRITICAL,
            'reason' => 'jurisdiction unclear',
        ]);

        /** @var EvidenceControlPlaneService $controlPlane */
        $controlPlane = app(EvidenceControlPlaneService::class);
        $snapshot = $controlPlane->snapshot();
        $this->assertGreaterThanOrEqual(2, $snapshot['unverified_claims_count']);
        $this->assertGreaterThanOrEqual(1, $snapshot['open_blockers_count']);
        $this->assertGreaterThanOrEqual(1, $snapshot['critical_open_blockers_count']);
    }
}
