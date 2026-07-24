<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Aaeos;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\AtlasLedgerReplayService;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AaeosLedgerTruncationCutoffTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('atlas_ledger_events');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_07_23_230000_harden_atlas_ledger_chain_and_journey_queries.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        parent::tearDown();
    }

    public function test_replay_refuses_prefix_and_suffix_truncation_against_the_authenticated_cutoff(): void
    {
        [$events, $cutoff] = $this->threeEventChain();
        $replay = app(AtlasLedgerReplayService::class);
        $chainKey = (string) $events[0]->chain_key_hash;

        self::assertTrue($replay->verifyTenantChain('tenant-cutoff', $chainKey, $cutoff)['valid']);
        $unsigned = $cutoff;
        unset($unsigned['authentication_tag']);
        self::assertSame(
            'cutoff_authentication_invalid',
            $replay->verifyTenantChain('tenant-cutoff', $chainKey, $unsigned)['failure_reason'],
        );

        DB::table('atlas_ledger_events')->where('event_id', $events[0]->event_id)->delete();
        self::assertSame(
            'cutoff_prefix_truncated',
            $replay->verifyTenantChain('tenant-cutoff', $chainKey, $cutoff)['failure_reason'],
        );

        Schema::dropIfExists('atlas_ledger_events');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_07_23_230000_harden_atlas_ledger_chain_and_journey_queries.php'))->up();
        [$events, $cutoff] = $this->threeEventChain();
        $chainKey = (string) $events[0]->chain_key_hash;

        DB::table('atlas_ledger_events')->where('event_id', $events[2]->event_id)->delete();
        self::assertSame(
            'cutoff_suffix_truncated',
            $replay->verifyTenantChain('tenant-cutoff', $chainKey, $cutoff)['failure_reason'],
        );
    }

    public function test_replay_refuses_middle_splice_and_stale_cutoff_with_precise_reasons(): void
    {
        [$events, $cutoff] = $this->threeEventChain();
        $replay = app(AtlasLedgerReplayService::class);
        $chainKey = (string) $events[0]->chain_key_hash;

        DB::table('atlas_ledger_events')->where('event_id', $events[1]->event_id)->delete();
        self::assertSame(
            'cutoff_event_count_mismatch',
            $replay->verifyTenantChain('tenant-cutoff', $chainKey, $cutoff)['failure_reason'],
        );

        Schema::dropIfExists('atlas_ledger_events');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_07_23_230000_harden_atlas_ledger_chain_and_journey_queries.php'))->up();
        [$events, $cutoff] = $this->threeEventChain();
        $chainKey = (string) $events[0]->chain_key_hash;
        $this->append(4);

        self::assertSame(
            'cutoff_stale',
            $replay->verifyTenantChain('tenant-cutoff', $chainKey, $cutoff)['failure_reason'],
        );
    }

    /**
     * @return array{0:array<int,AtlasLedgerEvent>,1:array<string,mixed>}
     */
    private function threeEventChain(): array
    {
        $events = [$this->append(1), $this->append(2), $this->append(3)];
        $cutoff = app(AtlasLedgerReplayService::class)->authenticatedCutoffForTenantChain(
            'tenant-cutoff',
            (string) $events[0]->chain_key_hash,
        );

        self::assertNotNull($cutoff);

        return [$events, $cutoff];
    }

    private function append(int $position): AtlasLedgerEvent
    {
        $event = app(AtlasEvidenceLedger::class)->record(
            LedgerEventType::AaeosCycleRecorded,
            ['step' => $position],
            [
                'tenant_id' => 'tenant-cutoff',
                'operator_id' => 'operator-cutoff',
                'envelope_id' => 'envelope-'.$position,
                'correlation_id' => 'journey-cutoff',
                'scope_type' => 'journey',
                'scope_id' => 'journey-cutoff',
                'occurred_at' => sprintf('2026-07-24T12:00:%02dZ', $position),
            ],
        );

        self::assertNotNull($event);

        return $event;
    }
}
