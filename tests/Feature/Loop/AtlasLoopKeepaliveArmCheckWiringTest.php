<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\AutonomousEvolution\AtlasLoopSoakPlanService;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * WIRING — the 24/7 keepalive now emits an ADVISORY soak arm-check (default OFF). When armed and the arm-check
 * is NOT ready (e.g. a proxy-supply farm armed), it logs + stamps ONE Evidence-Ledger receipt — but NEVER
 * blocks/kills the supervisor. Flag OFF ⇒ no SoakPlanService call, byte-identical.
 *
 * Master is armed (so handle() reaches runKeepalive) and the campaigns table is migrated (0 rows ⇒ a clean
 * no-op keepalive). A ledger spy counts the advisory receipts.
 */
final class AtlasLoopKeepaliveArmCheckWiringTest extends TestCase
{
    use ArmsAtlasLoopMaster;

    private AtlasEvidenceLedger $ledgerSpy;

    private string $logPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->armLoopMasterOn();

        if (! Schema::hasTable('atlas_loop_campaigns')) {
            (require base_path('database/migrations/2026_06_02_000100_create_atlas_loop_runtime_tables.php'))->up();
        }

        $this->logPath = storage_path('logs/loop-keepalive-arm-check.log');
        @unlink($this->logPath);

        // Counting ledger spy (AtlasEvidenceLedger is not final): captures every record() call.
        $this->ledgerSpy = new class extends AtlasEvidenceLedger
        {
            /** @var list<array<string,mixed>> */
            public array $records = [];

            public function __construct() {}

            public function record(LedgerEventType $type, array $payload, array $context = []): ?AtlasLedgerEvent
            {
                $this->records[] = ['type' => $type->value, 'payload' => $payload];

                return null;
            }
        };
        $this->app->instance(AtlasEvidenceLedger::class, $this->ledgerSpy);
    }

    protected function tearDown(): void
    {
        @unlink($this->logPath);
        $this->disarmLoopMaster();
        parent::tearDown();
    }

    /** @return list<array<string,mixed>> the advisory ledger records */
    private function advisoryRecords(): array
    {
        return array_values(array_filter(
            $this->ledgerSpy->records,
            static fn (array $r): bool => ($r['payload']['decision'] ?? null) === 'keepalive_arm_check_advisory',
        ));
    }

    /** Drive the arm-check to READY: every Fibonacci flag ON and every proxy/merge farm OFF. */
    private function armReady(): void
    {
        foreach (AtlasLoopSoakPlanService::FIBONACCI_FLAGS as $flag) {
            config(['atlas.loop.'.$flag => true]);
        }
        foreach (array_merge(AtlasLoopSoakPlanService::PROXY_WORKTYPE_FLAGS, AtlasLoopSoakPlanService::PROXY_SUPPLY_FLAGS) as $flag) {
            config(['atlas.loop.'.$flag => false]);
        }
        // objective_producer without feature-origination is also a proxy farm; self/obra auto-merge would flip
        // the requested propose-only soak to auto_merge. Keep them all off for a ready, material, propose-only soak.
        config([
            'atlas.loop.objective_producer_enabled' => false,
            'atlas.loop.producer_feature_origination_enabled' => false,
            'atlas.loop.self_improvement_auto_merge_enabled' => false,
            'atlas.loop.obra_auto_merge_enabled' => false,
        ]);
    }

    public function test_flag_off_makes_no_arm_check_call_and_is_byte_identical(): void
    {
        config(['atlas.loop.keepalive_arm_check_enabled' => false]);

        $code = Artisan::call('atlas:loop:keepalive', ['--json' => true]);

        $this->assertSame(0, $code);
        $this->assertSame([], $this->advisoryRecords(), 'OFF ⇒ no advisory ledger receipt');
        $this->assertFileDoesNotExist($this->logPath, 'OFF ⇒ no arm-check log written');
    }

    public function test_flag_on_and_ready_is_a_no_op(): void
    {
        config(['atlas.loop.keepalive_arm_check_enabled' => true]);
        $this->armReady();

        $code = Artisan::call('atlas:loop:keepalive', ['--json' => true]);

        $this->assertSame(0, $code);
        $this->assertSame([], $this->advisoryRecords(), 'ready ⇒ no advisory receipt');
        $this->assertFileDoesNotExist($this->logPath, 'ready ⇒ no advisory log');
    }

    public function test_flag_on_and_not_ready_stamps_exactly_one_advisory_and_never_blocks(): void
    {
        config(['atlas.loop.keepalive_arm_check_enabled' => true]);
        // Fibonacci flags left OFF (default) ⇒ arm-check NOT ready.

        $code = Artisan::call('atlas:loop:keepalive', ['--json' => true]);

        // ADVISORY, never blocking: keepalive still returns SUCCESS even though the arm-check failed.
        $this->assertSame(0, $code, 'arm-check is advisory — it never blocks the keepalive');

        $records = $this->advisoryRecords();
        $this->assertCount(1, $records, 'exactly ONE advisory receipt per execution');
        $this->assertSame(LedgerEventType::DecisionIssued->value, $records[0]['type']);
        $this->assertNotEmpty($records[0]['payload']['blocking'] ?? [], 'the receipt carries the non-empty blocking list');
    }
}
