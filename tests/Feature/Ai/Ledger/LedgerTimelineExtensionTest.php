<?php

namespace Tests\Feature\Ai\Ledger;

use App\Models\AiAuditEvent;
use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Evidence\AuditEventService;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

/**
 * TEOS-I1 / M3 — Ledger Timeline Extension.
 *
 * The extension MUST be:
 *  - additive (legacy writers continue to work);
 *  - hash-stable (back-compat event_hash on AiAuditEvent when no new fields);
 *  - scope-queryable (eventsForScope / eventsForCorrelation);
 *  - append-only (AtlasLedgerEvent::save throws on update; delete forbidden).
 */
class LedgerTimelineExtensionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLedgerSchemas();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_audit_events');
        Schema::dropIfExists('atlas_ledger_events');
        parent::tearDown();
    }

    public function test_legacy_ledger_record_still_works_without_scope_fields(): void
    {
        $event = app(AtlasEvidenceLedger::class)->record(
            LedgerEventType::EnvelopeCreated,
            ['envelope_id' => 'legacy-env-1', 'note' => 'plain payload'],
            ['envelope_id' => 'legacy-env-1', 'correlation_id' => 'corr-legacy-1'],
        );

        $this->assertNotNull($event);
        $this->assertSame('legacy-env-1', $event->envelope_id);
        $this->assertNull($event->scope_type);
        $this->assertNull($event->scope_id);
        $this->assertNotNull($event->event_hash, 'event_hash should be computed even without scope.');
    }

    public function test_ledger_record_with_scope_persists_and_is_queryable(): void
    {
        $service = app(AtlasEvidenceLedger::class);

        $service->record(
            LedgerEventType::EnvelopeCreated,
            ['envelope_id' => 'mission-env-1'],
            [
                'envelope_id' => 'mission-env-1',
                'correlation_id' => 'corr-mission-1',
                'scope_type' => 'mission',
                'scope_id' => 'mission-abc',
            ],
        );
        $service->record(
            LedgerEventType::DecisionIssued,
            ['envelope_id' => 'mission-env-1'],
            [
                'envelope_id' => 'mission-env-1',
                'correlation_id' => 'corr-mission-1',
                'scope_type' => 'mission',
                'scope_id' => 'mission-abc',
                'causation_id' => 'corr-mission-1',
            ],
        );
        // Unrelated scope must not appear in the scoped query.
        $service->record(
            LedgerEventType::EnvelopeCreated,
            ['envelope_id' => 'other-env'],
            [
                'envelope_id' => 'other-env',
                'correlation_id' => 'corr-other',
                'scope_type' => 'mission',
                'scope_id' => 'mission-other',
            ],
        );

        $events = $service->eventsForScope('mission', 'mission-abc');
        $this->assertCount(2, $events);
        $this->assertSame(['ENVELOPE_CREATED', 'DECISION_ISSUED'], array_column($events, 'event_type'));

        $byCorrelation = $service->eventsForCorrelation('corr-mission-1');
        $this->assertCount(2, $byCorrelation);
    }

    public function test_event_hash_is_deterministic_for_same_inputs(): void
    {
        $fixed = [
            'event_id' => '01HV9STABLEXXXXXXXXXXXXXX',
            'event_type' => LedgerEventType::EnvelopeCreated->value,
            'envelope_id' => 'stable-env',
            'correlation_id' => 'corr-stable',
            'causation_id' => null,
            'scope_type' => 'mission',
            'scope_id' => 'mission-stable',
            'payload_hash' => hash('sha256', 'payload'),
            'occurred_at' => '2026-05-18T12:00:00.000000Z',
        ];

        $first = AtlasEvidenceLedger::computeEventHash($fixed);
        $second = AtlasEvidenceLedger::computeEventHash($fixed);
        $this->assertSame($first, $second);

        // Same input with keys in different order still produces the same hash.
        $shuffled = array_reverse($fixed, true);
        $this->assertSame($first, AtlasEvidenceLedger::computeEventHash($shuffled));

        // Different scope_id yields a different hash.
        $different = $fixed;
        $different['scope_id'] = 'mission-different';
        $this->assertNotSame($first, AtlasEvidenceLedger::computeEventHash($different));
    }

    public function test_event_hash_drops_null_fields_for_back_compat(): void
    {
        $minimal = [
            'event_type' => LedgerEventType::EnvelopeCreated->value,
            'envelope_id' => 'env-min',
            'correlation_id' => 'corr-min',
            'payload_hash' => 'abc',
            'occurred_at' => '2026-05-18T12:00:00.000000Z',
        ];
        $withNullScope = $minimal + [
            'scope_type' => null,
            'scope_id' => null,
            'causation_id' => null,
        ];

        $this->assertSame(
            AtlasEvidenceLedger::computeEventHash($minimal),
            AtlasEvidenceLedger::computeEventHash($withNullScope),
            'Null scope/causation fields must be dropped from the hash input.',
        );
    }

    public function test_atlas_ledger_event_is_append_only(): void
    {
        $service = app(AtlasEvidenceLedger::class);

        $event = $service->record(
            LedgerEventType::EnvelopeCreated,
            ['envelope_id' => 'append-only-env'],
            ['envelope_id' => 'append-only-env'],
        );
        $this->assertNotNull($event);

        $event->trace_id = 'mutated';
        $thrown = false;
        try {
            $event->save();
        } catch (LogicException) {
            $thrown = true;
        }
        $this->assertTrue($thrown, 'AtlasLedgerEvent::save must reject updates.');

        $deleteThrown = false;
        try {
            $event->delete();
        } catch (LogicException) {
            $deleteThrown = true;
        }
        $this->assertTrue($deleteThrown, 'AtlasLedgerEvent::delete must throw.');
    }

    public function test_audit_event_back_compat_when_timeline_fields_omitted(): void
    {
        $first = app(AuditEventService::class)->record(
            eventType: 'gate_run',
            targetType: 'mission',
            targetId: 'mission-1',
            payload: ['note' => 'legacy'],
        );
        $second = app(AuditEventService::class)->record(
            eventType: 'gate_run',
            targetType: 'mission',
            targetId: 'mission-1',
            payload: ['note' => 'legacy'],
        );

        $this->assertSame(
            $first->event_hash,
            $second->event_hash,
            'Legacy AuditEventService callers must keep producing identical event_hash.',
        );
        $this->assertNull($first->scope_type);
        $this->assertNull($first->correlation_id);
    }

    public function test_audit_event_with_timeline_fields_persists_and_is_queryable(): void
    {
        $service = app(AuditEventService::class);

        $service->record(
            eventType: 'evidence_attached',
            targetType: 'mission',
            targetId: 'mission-7',
            payload: ['ref' => 'receipt:a'],
            timeline: [
                'scope_type' => 'mission',
                'scope_id' => 'mission-7',
                'correlation_id' => 'corr-mission-7',
            ],
        );
        $service->record(
            eventType: 'certification_attempted',
            targetType: 'mission',
            targetId: 'mission-7',
            payload: ['ref' => 'receipt:b'],
            timeline: [
                'scope_type' => 'mission',
                'scope_id' => 'mission-7',
                'correlation_id' => 'corr-mission-7',
                'causation_id' => 'corr-mission-7',
            ],
        );

        $scoped = AiAuditEvent::query()
            ->where('scope_type', 'mission')
            ->where('scope_id', 'mission-7')
            ->orderBy('created_at')
            ->orderBy('id')
            ->pluck('event_type')
            ->all();
        $this->assertSame(['evidence_attached', 'certification_attempted'], $scoped);

        $byCorrelation = AiAuditEvent::query()
            ->where('correlation_id', 'corr-mission-7')
            ->get();
        $this->assertCount(2, $byCorrelation);

        $byCausation = AiAuditEvent::query()
            ->where('causation_id', 'corr-mission-7')
            ->get();
        $this->assertCount(1, $byCausation);
        $this->assertSame('certification_attempted', $byCausation->first()->event_type);
    }

    public function test_audit_event_hash_changes_when_timeline_fields_provided(): void
    {
        $service = app(AuditEventService::class);

        $legacy = $service->record(
            eventType: 'gate_run',
            targetType: 'mission',
            targetId: 'mission-hash',
            payload: ['ref' => 'r1'],
        );
        $withTimeline = $service->record(
            eventType: 'gate_run',
            targetType: 'mission',
            targetId: 'mission-hash',
            payload: ['ref' => 'r1'],
            timeline: ['scope_type' => 'mission', 'scope_id' => 'mission-hash'],
        );

        $this->assertNotSame(
            $legacy->event_hash,
            $withTimeline->event_hash,
            'Providing timeline fields must produce a distinct event_hash.',
        );
    }

    private function bootLedgerSchemas(): void
    {
        Schema::dropIfExists('atlas_ledger_events');
        Schema::dropIfExists('ai_audit_events');

        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_05_19_050000_extend_atlas_ledger_events_with_timeline_fields.php'))->up();

        (require database_path('migrations/2026_05_18_020000_create_ai_evidence_certification_runtime_tables.php'))->up();
        (require database_path('migrations/2026_05_19_050001_extend_ai_audit_events_with_timeline_fields.php'))->up();
    }
}
