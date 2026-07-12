<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Product;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Product\ProductIntentCase;
use App\Services\Ai\Product\ProductIntentCourt;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ProductIntentCourtLedgerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('atlas_ledger_events');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_ledger_events');
        parent::tearDown();
    }

    public function test_only_admitted_intent_emits_one_replayable_unit_frozen_event(): void
    {
        $court = new ProductIntentCourt(ledger: app(AtlasEvidenceLedger::class));
        $case = ProductIntentCase::fromArray([
            'human_request' => 'Melhorar checkout para reduzir falhas', 'mode' => 'dev', 'risk_class' => 'R3',
            'problem' => 'checkout', 'user' => 'buyer', 'value' => 'payment completes',
            'metric' => 'success >= 0.98', 'observation_window' => '30d', 'source_refs' => ['brief:1'],
            'world_snapshot_hash' => str_repeat('a', 64), 'world_snapshot_status' => 'fresh',
            'falsifiers' => ['success below baseline'], 'acceptance' => ['payment path works'],
            'alternatives' => ['retain current checkout'],
            'non_goals' => ['change pricing'],
            'side_effects' => [['description' => 'latency', 'containment' => 'alert']],
        ]);

        $first = $court->adjudicate($case);
        $second = $court->adjudicate($case);

        self::assertSame('admitted', $first->status);
        self::assertSame($first->intentHash, $second->intentHash);
        self::assertSame(1, AtlasLedgerEvent::query()->where('event_type', LedgerEventType::UnitFrozen->value)->count());
        self::assertSame('unit.frozen', AtlasLedgerEvent::query()->firstOrFail()->payload['event_name']);
    }
}
