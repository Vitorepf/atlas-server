<?php

namespace Tests\Feature\Ai\Evidence;

use App\Services\Ai\Evidence\AuditEventService;
use Tests\Concerns\CreatesEvidenceRuntimeTables;
use Tests\TestCase;

class EvidenceRuntimeAuditEventTest extends TestCase
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

    public function test_audit_event_generates_hash_and_persists(): void
    {
        /** @var AuditEventService $svc */
        $svc = app(AuditEventService::class);
        $event = $svc->record(
            AuditEventService::EVENT_RECEIPT_EMITTED,
            'mission',
            'mission-aaa',
            ['note' => 'unit test'],
        );

        $this->assertNotNull($event->event_hash);
        $this->assertSame(AuditEventService::EVENT_RECEIPT_EMITTED, $event->event_type);
        $this->assertSame('mission', $event->target_type);
        $this->assertSame('mission-aaa', $event->target_id);
    }

    public function test_audit_event_hash_is_deterministic_for_same_input(): void
    {
        /** @var AuditEventService $svc */
        $svc = app(AuditEventService::class);
        $payload = ['k1' => 'v1', 'k2' => ['nested' => true, 'list' => [3, 2, 1]]];

        $a = $svc->record(AuditEventService::EVENT_CLAIM_MADE, 'claim', 'claim-1', $payload);
        $b = $svc->record(AuditEventService::EVENT_CLAIM_MADE, 'claim', 'claim-1', $payload);

        $this->assertSame($a->event_hash, $b->event_hash);
    }
}
