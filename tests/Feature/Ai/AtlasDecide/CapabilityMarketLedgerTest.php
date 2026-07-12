<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\AtlasDecide;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\AtlasDecide\CapabilityMarketClearingService;
use App\Services\Ai\AtlasDecide\CapabilityMarketRequest;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CapabilityMarketLedgerTest extends TestCase
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

    public function test_market_clear_persists_one_replayable_decision_receipt(): void
    {
        $request = CapabilityMarketRequest::fromArray([
            'order_hash' => str_repeat('a', 64), 'snapshot_hash' => str_repeat('b', 64), 'authority_hash' => str_repeat('c', 64),
            'risk_class' => 'R3', 'required_capabilities' => ['php'], 'topology' => 'single',
        ]);
        $routes = [[
            'id' => 'kernel', 'capabilities' => ['php'], 'authority_status' => 'active', 'allowed' => true,
            'quality_status' => 'proven', 'quality_hash' => str_repeat('d', 64), 'verifier_families' => ['a', 'b'],
            'available' => true, 'estimated_time_ms' => 10, 'estimated_cost' => 1.0, 'provider_version' => 'v1',
        ]];
        $service = new CapabilityMarketClearingService(app(AtlasEvidenceLedger::class));

        $first = $service->clear($request, $routes);
        $second = $service->clear($request, $routes);

        self::assertSame($first->decisionHash, $second->decisionHash);
        self::assertSame(1, AtlasLedgerEvent::query()->where('event_type', LedgerEventType::DecisionIssued->value)->count());
        self::assertSame('capability.market.cleared', AtlasLedgerEvent::query()->firstOrFail()->payload['event_name']);
    }
}
