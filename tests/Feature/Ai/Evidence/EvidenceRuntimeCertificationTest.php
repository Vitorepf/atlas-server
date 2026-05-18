<?php

namespace Tests\Feature\Ai\Evidence;

use App\Services\Ai\Evidence\BlockerService;
use App\Services\Ai\Evidence\CertificationRuntimeService;
use App\Services\Ai\Evidence\EvidencePackService;
use App\Services\Ai\Evidence\ReceiptService;
use Tests\Concerns\CreatesEvidenceRuntimeTables;
use Tests\TestCase;

class EvidenceRuntimeCertificationTest extends TestCase
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

    public function test_certification_without_evidence_pack_fails(): void
    {
        /** @var CertificationRuntimeService $service */
        $service = app(CertificationRuntimeService::class);

        $cert = $service->certify([
            'target_type' => EvidencePackService::TARGET_MISSION,
            'target_id' => 'mission-no-evidence',
        ]);

        $this->assertSame(CertificationRuntimeService::STATUS_FAILED, $cert->status);
        $this->assertNull($cert->certified_at);
        $missing = collect($cert->missing_requirements)->pluck('requirement')->all();
        $this->assertContains('evidence_refs_present', $missing);
        $this->assertContains('evidence_pack_not_empty', $missing);
    }

    public function test_certification_with_missing_required_requirements_fails(): void
    {
        /** @var EvidencePackService $packs */
        $packs = app(EvidencePackService::class);
        /** @var ReceiptService $receipts */
        $receipts = app(ReceiptService::class);
        /** @var CertificationRuntimeService $service */
        $service = app(CertificationRuntimeService::class);

        $targetId = 'mission-missing-req';
        $receipt = $receipts->emit([
            'receipt_type' => ReceiptService::TYPE_COMMAND,
            'action' => 'test-action',
            'target_type' => EvidencePackService::TARGET_MISSION,
            'target_id' => $targetId,
        ]);
        $packs->build([
            'target_type' => EvidencePackService::TARGET_MISSION,
            'target_id' => $targetId,
            'receipt_refs' => [['id' => $receipt->id, 'kind' => 'receipt']],
        ]);

        $cert = $service->certify([
            'target_type' => EvidencePackService::TARGET_MISSION,
            'target_id' => $targetId,
            'required_requirements' => ['mission_has_objectives', 'mission_foundation_certification_passed'],
            'provided_requirements' => [],
        ]);

        $this->assertSame(CertificationRuntimeService::STATUS_FAILED, $cert->status);
        $missing = collect($cert->missing_requirements)->pluck('requirement')->all();
        $this->assertContains('mission_has_objectives', $missing);
        $this->assertContains('mission_foundation_certification_passed', $missing);
    }

    public function test_certification_with_critical_blocker_is_blocked(): void
    {
        /** @var EvidencePackService $packs */
        $packs = app(EvidencePackService::class);
        /** @var BlockerService $blockers */
        $blockers = app(BlockerService::class);
        /** @var CertificationRuntimeService $service */
        $service = app(CertificationRuntimeService::class);

        $targetType = EvidencePackService::TARGET_MISSION;
        $targetId = 'mission-blocked';

        $packs->build([
            'target_type' => $targetType,
            'target_id' => $targetId,
            'receipt_refs' => [['kind' => 'receipt', 'note' => 'placeholder']],
        ]);

        $blockers->open([
            'target_type' => $targetType,
            'target_id' => $targetId,
            'blocker_type' => BlockerService::KIND_MISSING_PERMISSION,
            'severity' => BlockerService::SEVERITY_CRITICAL,
            'reason' => 'operator approval missing',
        ]);

        $cert = $service->certify([
            'target_type' => $targetType,
            'target_id' => $targetId,
        ]);

        $this->assertSame(CertificationRuntimeService::STATUS_BLOCKED, $cert->status);
        $this->assertNotEmpty($cert->blocker_refs);
        $this->assertFalse($service->canComplete($targetType, $targetId));
    }

    public function test_completed_blocked_without_certification_passed(): void
    {
        /** @var CertificationRuntimeService $service */
        $service = app(CertificationRuntimeService::class);

        $this->assertFalse(
            $service->canComplete(EvidencePackService::TARGET_MISSION, 'no-such-target'),
            'canComplete must be false when no certification exists',
        );
    }
}
