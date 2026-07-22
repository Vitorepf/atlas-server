<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Cognition\AcosProgram;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\EvidenceLedgerDailyChainHeadAnchor;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog\EvidenceLedgerHashChainIntegrityVerifier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class Maxl02EvidenceLedgerHashChainTest extends TestCase
{
    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        parent::tearDown();
    }

    public function test_three_scoped_events_verify_ok_delete_middle_reports_gap_edit_payload_reports_tampered_and_append_stays_ok(): void
    {
        $this->migrateMaxl02Ledger();

        [$first, $second, $third] = $this->recordThreeScopedEvents('case-acceptance');

        self::assertNull($first->prev_event_hash);
        self::assertSame($first->event_hash, $second->prev_event_hash);
        self::assertSame($second->event_hash, $third->prev_event_hash);
        self::assertSame('hash_chained', $third->chain_basis);
        self::assertSame('ok', $this->verifyScope('case-acceptance')['status']);

        DB::table('atlas_ledger_events')->where('event_id', $second->event_id)->delete();
        $gap = $this->verifyScope('case-acceptance');
        self::assertSame('gap', $gap['status']);
        self::assertSame(1, $gap['gap_count']);

        $this->resetMaxl02Ledger();
        [, $tampered] = $this->recordThreeScopedEvents('case-tampered');
        DB::table('atlas_ledger_events')
            ->where('event_id', $tampered->event_id)
            ->update([
                'payload' => json_encode(
                    ['decision_hash' => str_repeat('f', 64), 'event_name' => 'maxl02.payload.edited'],
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                ),
            ]);

        $tamperResult = $this->verifyScope('case-tampered');
        self::assertSame('tampered', $tamperResult['status']);
        self::assertSame([$tampered->event_id], $tamperResult['tampered_event_ids']);

        $this->resetMaxl02Ledger();
        $this->recordThreeScopedEvents('case-append');
        $this->recordEvent('01JMAXL02APPEND0000000000004', 'case-append', 4);
        $appendResult = $this->verifyScope('case-append');
        self::assertSame('ok', $appendResult['status']);
        self::assertSame(4, $appendResult['chain_length']);
    }

    public function test_events_without_scope_fall_back_to_correlation_chain(): void
    {
        $this->migrateMaxl02Ledger();

        $ledger = app(AtlasEvidenceLedger::class);
        $first = $ledger->record(LedgerEventType::DecisionIssued, [
            'event_name' => 'maxl02.correlation.1',
            'decision_hash' => str_repeat('a', 64),
        ], [
            'event_id' => '01JMAXL02CORR00000000000001',
            'tenant_id' => 'default',
            'operator_id' => 'system',
            'envelope_id' => 'maxl02-correlation-envelope-1',
            'correlation_id' => 'maxl02-correlation-chain',
            'occurred_at' => CarbonImmutable::parse('2026-07-12T05:00:01Z'),
        ]);
        $second = $ledger->record(LedgerEventType::DecisionIssued, [
            'event_name' => 'maxl02.correlation.2',
            'decision_hash' => str_repeat('b', 64),
        ], [
            'event_id' => '01JMAXL02CORR00000000000002',
            'tenant_id' => 'default',
            'operator_id' => 'system',
            'envelope_id' => 'maxl02-correlation-envelope-2',
            'correlation_id' => 'maxl02-correlation-chain',
            'occurred_at' => CarbonImmutable::parse('2026-07-12T05:00:02Z'),
        ]);

        self::assertInstanceOf(AtlasLedgerEvent::class, $first);
        self::assertInstanceOf(AtlasLedgerEvent::class, $second);
        self::assertSame($first->event_hash, $second->prev_event_hash);

        $result = (new EvidenceLedgerHashChainIntegrityVerifier)->verifyStoredCorrelationChain('maxl02-correlation-chain');

        self::assertSame('correlation_id:maxl02-correlation-chain', $result['chain_key']);
        self::assertSame('ok', $result['status']);
    }

    public function test_legacy_rows_are_labelled_legacy_unchained_without_claiming_a_false_chain(): void
    {
        $this->migrateMaxl01Ledger();
        DB::table('atlas_ledger_events')->insert([
            'event_id' => '01JMAXL02LEGACY000000000001',
            'schema_version' => AtlasEvidenceLedger::SCHEMA_VERSION,
            'tenant_id' => 'default',
            'operator_id' => 'system',
            'envelope_id' => 'legacy-envelope',
            'receipt_id' => null,
            'trace_id' => null,
            'correlation_id' => 'legacy-correlation',
            'causation_id' => null,
            'event_type' => LedgerEventType::DecisionIssued->value,
            'emitter_stage' => 'test.legacy',
            'emitter_version' => 'v1',
            'payload' => json_encode(['event_name' => 'maxl02.legacy'], JSON_THROW_ON_ERROR),
            'payload_hash' => hash('sha256', json_encode(['event_name' => 'maxl02.legacy'], JSON_THROW_ON_ERROR)),
            'scope_type' => 'maxl02_scope',
            'scope_id' => 'legacy-case',
            'event_hash' => str_repeat('1', 64),
            'occurred_at' => '2026-07-12 04:00:00',
            'created_at' => '2026-07-12 04:00:00',
            'updated_at' => '2026-07-12 04:00:00',
        ]);

        (require database_path('migrations/2026_07_12_021500_add_hash_chain_to_atlas_ledger_events.php'))->up();

        $legacy = DB::table('atlas_ledger_events')->where('event_id', '01JMAXL02LEGACY000000000001')->first();
        self::assertSame('legacy_unchained', $legacy->chain_basis);
        self::assertNull($legacy->prev_event_hash);

        $result = $this->verifyScope('legacy-case');
        self::assertSame('legacy_unchained', $result['status']);
        self::assertSame(1, $result['legacy_unchained_count']);
        self::assertSame(0, $result['chain_length']);
    }

    public function test_daily_chain_head_anchor_writes_git_bound_jsonl_with_declared_threat_model(): void
    {
        $this->migrateMaxl02Ledger();
        $this->recordThreeScopedEvents('case-anchor');
        $path = storage_path('framework/testing/maxl02-chain-head-anchor.jsonl');
        @unlink($path);

        $anchor = new EvidenceLedgerDailyChainHeadAnchor(
            verifier: new EvidenceLedgerHashChainIntegrityVerifier,
            anchorPath: $path,
            gitHeadResolver: static fn (): string => str_repeat('a', 40),
        );

        $row = $anchor->anchorDay(CarbonImmutable::parse('2026-07-12T23:00:00Z'));

        self::assertSame('atlas.evidence.ledger_chain_head_anchor.v1', $row['schema_version']);
        self::assertSame('2026-07-12', $row['anchor_date']);
        self::assertSame(str_repeat('a', 40), $row['git_head']);
        self::assertSame('git_tracked_jsonl', $row['external_anchor']);
        self::assertStringContainsString('accidental_or_partial', $row['threat_model']);
        self::assertSame(1, $row['chain_count']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $row['anchor_hash']);

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertCount(1, $lines);
        $persisted = json_decode((string) $lines[0], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($row['anchor_hash'], $persisted['anchor_hash']);
        self::assertSame('maxl02_scope:case-anchor', $persisted['chains'][0]['chain_key']);
    }

    private function migrateBaseLedgerOnly(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
    }

    private function migrateMaxl01Ledger(): void
    {
        $this->migrateBaseLedgerOnly();

        (require database_path('migrations/2026_07_12_011200_repair_atlas_ledger_events_hash_and_scope_columns.php'))->up();
    }

    private function migrateMaxl02Ledger(): void
    {
        $this->migrateMaxl01Ledger();

        (require database_path('migrations/2026_07_12_021500_add_hash_chain_to_atlas_ledger_events.php'))->up();
    }

    private function resetMaxl02Ledger(): void
    {
        Schema::dropIfExists('atlas_ledger_events');
        $this->migrateMaxl02Ledger();
    }

    /**
     * @return array{0:AtlasLedgerEvent,1:AtlasLedgerEvent,2:AtlasLedgerEvent}
     */
    private function recordThreeScopedEvents(string $scopeId): array
    {
        return [
            $this->recordEvent('01JMAXL02CHAIN0000000000001', $scopeId, 1),
            $this->recordEvent('01JMAXL02CHAIN0000000000002', $scopeId, 2),
            $this->recordEvent('01JMAXL02CHAIN0000000000003', $scopeId, 3),
        ];
    }

    private function recordEvent(string $eventId, string $scopeId, int $ordinal): AtlasLedgerEvent
    {
        $event = app(AtlasEvidenceLedger::class)->record(LedgerEventType::DecisionIssued, [
            'event_name' => 'maxl02.chain.'.$ordinal,
            'decision_hash' => hash('sha256', $scopeId.':'.$ordinal),
        ], [
            'event_id' => $eventId,
            'tenant_id' => 'default',
            'operator_id' => 'system',
            'envelope_id' => 'maxl02-envelope-'.$scopeId.'-'.$ordinal,
            'correlation_id' => 'maxl02-correlation-'.$scopeId,
            'scope_type' => 'maxl02_scope',
            'scope_id' => $scopeId,
            'occurred_at' => CarbonImmutable::parse(sprintf('2026-07-12T04:00:%02dZ', $ordinal)),
        ]);

        self::assertInstanceOf(AtlasLedgerEvent::class, $event);

        return $event;
    }

    /**
     * @return array<string,mixed>
     */
    private function verifyScope(string $scopeId): array
    {
        return (new EvidenceLedgerHashChainIntegrityVerifier)->verifyStoredScopeChain('maxl02_scope', $scopeId);
    }
}
