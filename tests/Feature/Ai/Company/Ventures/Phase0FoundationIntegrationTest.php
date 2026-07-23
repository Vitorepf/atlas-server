<?php

namespace Tests\Feature\Ai\Company\Ventures;

use App\Services\Ai\Company\Ventures\Cost\VentureCostAttributionLedger;
use App\Services\Ai\Company\Ventures\Cost\VentureRunwayReader;
use App\Services\Ai\Company\Ventures\Health\VentureHealthGate;
use App\Services\Ai\Company\Ventures\Reward\ReconciledCashEventStore;
use App\Services\Ai\Company\Ventures\Safety\VentureActionDispatchController;
use App\Services\Ai\Company\Ventures\Safety\WindowedReservationService;
use App\Services\Ai\Company\Ventures\Success\VentureReconciledSuccessEvaluator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Consolidation proof: the 6 keystones + Phase-1 evaluator compose into ONE
 * honest pipeline (K1 cash -> K2 cost/runway -> K4 health -> K5 dispatch ->
 * Phase-1 success verdict). Proves the foundation is a coherent whole, not 7
 * isolated parts — on synthetic data, no real keys.
 */
class Phase0FoundationIntegrationTest extends TestCase
{
    private string $venture;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['ai_reconciled_cash_events', 'ai_venture_cost_attributions', 'ai_venture_spend_reservations', 'ai_venture_spend_windows'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::create('ai_reconciled_cash_events', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('schema_version', 120)->default('v1');
            $t->string('uuid', 64)->unique();
            $t->uuid('venture_id')->index();
            $t->enum('source', ['payment_processor', 'bank', 'external_reconciled']);
            $t->enum('event_kind', ['credit', 'refund', 'chargeback', 'dispute', 'failed_renewal'])->default('credit');
            $t->string('external_ref', 255);
            $t->bigInteger('amount_cents');
            $t->string('currency', 10)->default('BRL');
            $t->timestamp('occurred_at');
            $t->unsignedInteger('settlement_horizon_days')->default(0);
            $t->timestamp('settled_at')->nullable();
            $t->string('reverses_external_ref', 255)->nullable();
            $t->json('raw_payload')->nullable();
            $t->string('event_hash', 64)->unique();
            $t->timestamps();
            $t->unique(['source', 'external_ref'], 'uniq_rc');
        });
        Schema::create('ai_venture_cost_attributions', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('schema_version', 120)->default('v1');
            $t->string('uuid', 64)->unique();
            $t->uuid('venture_id')->index();
            $t->enum('cost_mode', ['token_cost', 'operational']);
            $t->bigInteger('cost_microusd');
            $t->string('category', 60)->nullable();
            $t->string('source_ref', 255)->nullable();
            $t->string('provider', 80)->nullable();
            $t->string('model', 120)->nullable();
            $t->timestamp('occurred_at');
            $t->string('attribution_hash', 64)->unique();
            $t->timestamps();
        });
        Schema::create('ai_venture_spend_windows', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('scope_ref', 120)->index();
            $t->enum('window_kind', ['hour', 'day', 'week', 'portfolio']);
            $t->string('window_key', 40);
            $t->bigInteger('cap_microusd');
            $t->bigInteger('reserved_microusd')->default(0);
            $t->timestamps();
            $t->unique(['scope_ref', 'window_kind', 'window_key'], 'uniq_sw');
        });
        Schema::create('ai_venture_spend_reservations', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('window_id')->index();
            $t->string('idempotency_key', 191)->unique();
            $t->bigInteger('amount_microusd');
            $t->timestamp('created_at')->nullable();
        });
        $this->venture = (string) Str::uuid();
    }

    protected function tearDown(): void
    {
        foreach (['ai_reconciled_cash_events', 'ai_venture_cost_attributions', 'ai_venture_spend_reservations', 'ai_venture_spend_windows'] as $t) {
            Schema::dropIfExists($t);
        }
        parent::tearDown();
    }

    private function green(): array
    {
        return ['solvency' => 'green', 'churn' => 'green', 'concentration' => 'green', 'legality' => 'green', 'deliverability' => 'green'];
    }

    public function test_golden_path_healthy_reconciled_venture_succeeds_and_acts(): void
    {
        $cash = new ReconciledCashEventStore;
        $cost = new VentureCostAttributionLedger;

        // K1: 3 months of settled reconciled cash >= R$1.000.
        foreach (['2026-04' => 120000, '2026-05' => 130000, '2026-06' => 110000] as $m => $cents) {
            $cash->record(['venture_id' => $this->venture, 'source' => 'payment_processor', 'external_ref' => "pi-$m", 'amount_cents' => $cents, 'occurred_at' => "$m-05T10:00:00Z"]);
        }
        // K2: measured burn.
        $cost->record(['venture_id' => $this->venture, 'cost_mode' => 'token_cost', 'cost_microusd' => 50000, 'occurred_at' => '2026-06-10T10:00:00Z']);

        // K2↔K1: runway composes (needs_fx without a rate — honest).
        $runway = (new VentureRunwayReader($cash, $cost))->assess($this->venture, \Illuminate\Support\Carbon::parse('2026-06-30'));
        $this->assertSame(VentureRunwayReader::STATUS_NEEDS_FX, $runway['status']);
        $this->assertSame(50000, $runway['monthly_burn_microusd']);

        // Phase 1: success verdict composes K1 (reconciled cash) + K4 (health).
        $verdict = (new VentureReconciledSuccessEvaluator($cash, new VentureHealthGate))->evaluate($this->venture, $this->green());
        $this->assertSame(VentureReconciledSuccessEvaluator::STATUS_SUCCEEDED, $verdict['status']);

        // K5: a reversible auto action is allowed; an irreversible one without mandate is blocked.
        $ctl = new VentureActionDispatchController(new WindowedReservationService);
        $this->assertSame(VentureActionDispatchController::ALLOW, $ctl->decide(['action_class' => 'marketing_post', 'venture_id' => $this->venture, 'autonomy' => 'auto'])['decision']);
        $this->assertSame(VentureActionDispatchController::BLOCK, $ctl->decide(['action_class' => 'charge', 'venture_id' => $this->venture, 'autonomy' => 'auto'])['decision']);
    }

    public function test_self_reported_cash_cannot_buy_success(): void
    {
        // Self-report is rejected at the DB (K1). No reconciled cash recorded.
        try {
            \App\Models\AiReconciledCashEvent::query()->create([
                'uuid' => (string) Str::uuid(), 'venture_id' => $this->venture, 'source' => 'operator',
                'event_kind' => 'credit', 'external_ref' => 'fake', 'amount_cents' => 9999999, 'currency' => 'BRL',
                'occurred_at' => \Illuminate\Support\Carbon::now(), 'event_hash' => 'h'.Str::uuid(),
            ]);
            $this->fail('self-reported cash should be rejected at the DB');
        } catch (\Illuminate\Database\QueryException $e) {
            // expected
        }

        $verdict = (new VentureReconciledSuccessEvaluator(new ReconciledCashEventStore, new VentureHealthGate))->evaluate($this->venture, $this->green());
        $this->assertSame(VentureReconciledSuccessEvaluator::STATUS_INSUFFICIENT, $verdict['status'], 'no honest path to success without reconciled cash');
    }

    public function test_sustained_revenue_but_unhealthy_does_not_succeed(): void
    {
        $cash = new ReconciledCashEventStore;
        foreach (['2026-04' => 120000, '2026-05' => 130000, '2026-06' => 110000] as $m => $cents) {
            $cash->record(['venture_id' => $this->venture, 'source' => 'bank', 'external_ref' => "tx-$m", 'amount_cents' => $cents, 'occurred_at' => "$m-05T10:00:00Z"]);
        }
        $signals = $this->green();
        $signals['legality'] = 'red'; // e.g. compliance breach

        $verdict = (new VentureReconciledSuccessEvaluator($cash, new VentureHealthGate))->evaluate($this->venture, $signals);
        $this->assertSame(VentureReconciledSuccessEvaluator::STATUS_BLOCKED_BY_HEALTH, $verdict['status']);
    }
}
