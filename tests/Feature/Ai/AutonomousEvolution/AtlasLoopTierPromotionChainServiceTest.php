<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\AutonomousEvolution;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\AutonomousEvolution\AtlasLoopTierPromotionChainService;
use App\Services\Ai\NightShift\AtlasNightShiftAreaFocusContractRegistry;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusOperatorDecisionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Obra #14 H3.2 — S49→S50→S55 promotion chain (implement-only).
 * NO provider is ever invoked; the kill switch is exercised through the loop
 * master switch test seam only.
 */
class AtlasLoopTierPromotionChainServiceTest extends TestCase
{
    private const AREA = AtlasNightShiftAreaFocusContractRegistry::AREA_AGENTIC_ENGINEERING_OS;

    private string $switchEnvPath;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('atlas_autonomy_tier_promotions');
        Schema::dropIfExists('atlas_ledger_events');
        (require database_path('migrations/2026_07_06_100000_create_atlas_autonomy_tier_promotions_table.php'))->up();
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_05_19_050000_extend_atlas_ledger_events_with_timeline_fields.php'))->up();

        $this->switchEnvPath = tempnam(sys_get_temp_dir(), 'atlas-switch-');
        AtlasLoopMasterSwitch::$envPathOverride = $this->switchEnvPath;
        $this->setMasterSwitch(false);
    }

    protected function tearDown(): void
    {
        AtlasLoopMasterSwitch::$envPathOverride = null;
        @unlink($this->switchEnvPath);
        Schema::dropIfExists('atlas_autonomy_tier_promotions');
        Schema::dropIfExists('atlas_ledger_events');
        parent::tearDown();
    }

    public function test_signed_receipt_promotes_to_tier_one_and_records_ledger_event(): void
    {
        $this->setMasterSwitch(true); // operator turned the loop on: kill switch clear

        $result = $this->chain()->promote($this->signedReceipt(), self::AREA);

        $this->assertSame('promote', $result['decision']);
        $this->assertSame(1, $result['tier']);
        $this->assertTrue($result['persisted']);
        $this->assertSame([], $result['blockers']);

        $row = DB::table('atlas_autonomy_tier_promotions')->where('area_id', self::AREA)->first();
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row->tier);
        $this->assertSame(1, (int) $row->operator_signed);
        $this->assertSame($result['receipt_hash'], $row->receipt_hash);

        $event = AtlasLedgerEvent::query()
            ->where('emitter_stage', 'atlas.loop.tier_promotion_chain')
            ->first();
        $this->assertNotNull($event);
        $this->assertSame(
            AtlasLoopTierPromotionChainService::PROMOTION_EVENT_SCHEMA,
            $event->payload['schema_version'],
        );
        $this->assertSame(self::AREA, $event->payload['area_id']);

        $this->assertSame(1, $this->chain()->activeTier(self::AREA));
    }

    public function test_unsigned_receipt_blocks_and_persists_nothing(): void
    {
        $this->setMasterSwitch(true);

        $receipt = $this->signedReceipt();
        $receipt['operator_signed'] = false;

        $result = $this->chain()->promote($receipt, self::AREA);

        $this->assertSame('block', $result['decision']);
        $this->assertFalse($result['persisted']);
        $this->assertContains('operator_receipt_signature_missing', $result['blockers']);
        $this->assertSame(0, DB::table('atlas_autonomy_tier_promotions')->count());
        $this->assertSame(0, AtlasLedgerEvent::query()->count());
        $this->assertSame(0, $this->chain()->activeTier(self::AREA));
    }

    public function test_invalid_receipt_schema_blocks(): void
    {
        $this->setMasterSwitch(true);

        $receipt = $this->signedReceipt();
        $receipt['schema_version'] = 'something.else.v1';

        $result = $this->chain()->promote($receipt, self::AREA);

        $this->assertSame('block', $result['decision']);
        $this->assertContains('receipt_schema_invalid', $result['blockers']);
        $this->assertSame(0, DB::table('atlas_autonomy_tier_promotions')->count());
    }

    public function test_kill_switch_active_blocks_promotion_and_drops_active_tier_to_zero(): void
    {
        // Promote legitimately with the master switch ON...
        $this->setMasterSwitch(true);
        $this->chain()->promote($this->signedReceipt(), self::AREA);
        $this->assertSame(1, $this->chain()->activeTier(self::AREA));

        // ...then the operator kills the loop: tier drops to 0 even though the promotion row persists.
        $this->setMasterSwitch(false);
        $this->assertSame(1, DB::table('atlas_autonomy_tier_promotions')->count());
        $this->assertSame(0, $this->chain()->activeTier(self::AREA));

        // And a new promotion under an active kill switch blocks.
        $result = $this->chain()->promote($this->signedReceipt(2), self::AREA);
        $this->assertSame('block', $result['decision']);
        $this->assertContains('kill_switch_active', $result['blockers']);
    }

    public function test_readiness_reflects_states(): void
    {
        $this->setMasterSwitch(true);

        $before = $this->chain()->readiness();
        $this->assertTrue($before['implemented']);
        $this->assertTrue($before['audited']);
        $this->assertSame(0, $before['tier']);
        $this->assertFalse($before['operator_signed']);

        $this->chain()->promote($this->signedReceipt(), self::AREA);

        $after = $this->chain()->readiness();
        $this->assertSame(1, $after['tier']);
        $this->assertTrue($after['operator_signed']);
    }

    public function test_first_merge_outcome_ledger_event_schema(): void
    {
        $payload = $this->chain()->recordFirstMergeOutcome([
            'area_id' => self::AREA,
            'outcome' => 'merged',
            'merge_performed' => true,
            'evidence_refs' => ['ref-1'],
        ]);

        $this->assertSame(AtlasLoopTierPromotionChainService::FIRST_MERGE_EVENT_SCHEMA, $payload['schema_version']);
        $this->assertTrue($payload['acid_test_passed']);

        $event = AtlasLedgerEvent::query()->first();
        $this->assertNotNull($event);
        $this->assertSame(AtlasLoopTierPromotionChainService::FIRST_MERGE_EVENT_SCHEMA, $event->payload['schema_version']);

        $notMerged = $this->chain()->recordFirstMergeOutcome([
            'area_id' => self::AREA,
            'outcome' => 'merged',
            'merge_performed' => false,
        ]);
        $this->assertFalse($notMerged['acid_test_passed']);
    }

    private function chain(): AtlasLoopTierPromotionChainService
    {
        return app(AtlasLoopTierPromotionChainService::class);
    }

    /**
     * @return array<string,mixed>
     */
    private function signedReceipt(int $tier = 1): array
    {
        return [
            'schema_version' => AreaFocusOperatorDecisionService::RECEIPT_SCHEMA,
            'operator_signed' => true,
            'approved' => true,
            'requested_tier' => $tier,
            'area_id' => self::AREA,
            'operator_actor' => 'vitor',
            'decision' => 'accept',
        ];
    }

    private function setMasterSwitch(bool $on): void
    {
        file_put_contents($this->switchEnvPath, AtlasLoopMasterSwitch::KEY.'='.($on ? 'true' : 'false')."\n");
    }
}
