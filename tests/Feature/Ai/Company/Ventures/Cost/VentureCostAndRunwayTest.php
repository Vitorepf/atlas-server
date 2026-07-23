<?php

namespace Tests\Feature\Ai\Company\Ventures\Cost;

use App\Services\Ai\Company\Ventures\Cost\VentureCostAttributionLedger;
use App\Services\Ai\Company\Ventures\Cost\VentureRunwayReader;
use App\Services\Ai\Company\Ventures\Reward\ReconciledCashEventStore;
use App\Services\Ai\Company\Ventures\VentureFoundryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class VentureCostAndRunwayTest extends TestCase
{
    private string $venture;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('ai_venture_cost_attributions');
        Schema::dropIfExists('ai_reconciled_cash_events');

        Schema::create('ai_venture_cost_attributions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.ai.venture.cost_attribution.v1');
            $table->string('uuid', 64)->unique();
            $table->uuid('venture_id')->index();
            $table->enum('cost_mode', ['token_cost', 'operational']);
            $table->bigInteger('cost_microusd');
            $table->string('category', 60)->nullable();
            $table->string('source_ref', 255)->nullable();
            $table->string('provider', 80)->nullable();
            $table->string('model', 120)->nullable();
            $table->timestamp('occurred_at');
            $table->string('attribution_hash', 64)->unique();
            $table->timestamps();
        });

        Schema::create('ai_reconciled_cash_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('v1');
            $table->string('uuid', 64)->unique();
            $table->uuid('venture_id')->index();
            $table->enum('source', ['payment_processor', 'bank', 'external_reconciled']);
            $table->enum('event_kind', ['credit', 'refund', 'chargeback', 'dispute', 'failed_renewal'])->default('credit');
            $table->string('external_ref', 255);
            $table->bigInteger('amount_cents');
            $table->string('currency', 10)->default('BRL');
            $table->timestamp('occurred_at');
            $table->unsignedInteger('settlement_horizon_days')->default(0);
            $table->timestamp('settled_at')->nullable();
            $table->string('reverses_external_ref', 255)->nullable();
            $table->json('raw_payload')->nullable();
            $table->string('event_hash', 64)->unique();
            $table->timestamps();
            $table->unique(['source', 'external_ref'], 'uniq_reconciled_cash_source_ref');
        });

        $this->venture = (string) Str::uuid();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_venture_cost_attributions');
        Schema::dropIfExists('ai_reconciled_cash_events');
        parent::tearDown();
    }

    private function ledger(): VentureCostAttributionLedger
    {
        return new VentureCostAttributionLedger;
    }

    public function test_burn_is_unknown_when_no_cost_rows(): void
    {
        $this->assertNull(
            $this->ledger()->totalCostMicroUsd($this->venture, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30')),
            'no rows => UNKNOWN (null), never zero'
        );
    }

    public function test_records_and_sums_cost(): void
    {
        $l = $this->ledger();
        $l->record(['venture_id' => $this->venture, 'cost_mode' => 'token_cost', 'cost_microusd' => 250000, 'occurred_at' => '2026-06-02T10:00:00Z']);
        $l->record(['venture_id' => $this->venture, 'cost_mode' => 'token_cost', 'cost_microusd' => 150000, 'occurred_at' => '2026-06-03T10:00:00Z']);

        $this->assertSame(400000, $l->totalCostMicroUsd($this->venture, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30')));
    }

    public function test_monthly_groups(): void
    {
        $l = $this->ledger();
        $l->record(['venture_id' => $this->venture, 'cost_mode' => 'token_cost', 'cost_microusd' => 100000, 'occurred_at' => '2026-04-10T10:00:00Z']);
        $l->record(['venture_id' => $this->venture, 'cost_mode' => 'operational', 'cost_microusd' => 300000, 'occurred_at' => '2026-05-10T10:00:00Z']);

        $this->assertSame(['2026-04' => 100000, '2026-05' => 300000], $l->monthlyCostMicroUsd($this->venture));
    }

    public function test_negative_cost_rejected(): void
    {
        $this->expectException(VentureFoundryException::class);
        $this->ledger()->record(['venture_id' => $this->venture, 'cost_mode' => 'token_cost', 'cost_microusd' => -5]);
    }

    public function test_runway_unknown_without_cost(): void
    {
        $reader = new VentureRunwayReader(new ReconciledCashEventStore, $this->ledger());
        $r = $reader->assess($this->venture, Carbon::parse('2026-06-30'));
        $this->assertSame(VentureRunwayReader::STATUS_UNKNOWN, $r['status']);
    }

    public function test_runway_needs_fx_then_computes(): void
    {
        $now = Carbon::parse('2026-06-30T00:00:00Z');
        $cash = new ReconciledCashEventStore;
        // settled cash: R$2.000,00 = 200000 cents
        $cash->record(['venture_id' => $this->venture, 'source' => 'payment_processor', 'external_ref' => 'pi_x', 'amount_cents' => 200000, 'occurred_at' => '2026-06-10T10:00:00Z']);
        // burn in last 30d: 100000 micro-USD
        $this->ledger()->record(['venture_id' => $this->venture, 'cost_mode' => 'token_cost', 'cost_microusd' => 100000, 'occurred_at' => '2026-06-15T10:00:00Z']);

        $reader = new VentureRunwayReader($cash, $this->ledger());

        $needsFx = $reader->assess($this->venture, $now);
        $this->assertSame(VentureRunwayReader::STATUS_NEEDS_FX, $needsFx['status'], 'different units => honest needs_fx, no fabricated runway');
        $this->assertSame(100000, $needsFx['monthly_burn_microusd']);
        $this->assertSame(200000, $needsFx['settled_net_cents']);

        // supply FX: 1 cash cent = 2000 micro-USD  => cash = 200000*2000 = 4.0e8 ; runway = 4.0e8/1.0e5 = 4000 months
        $computed = $reader->assess($this->venture, $now, 2000.0);
        $this->assertSame(VentureRunwayReader::STATUS_COMPUTED, $computed['status']);
        $this->assertEqualsWithDelta(4000.0, $computed['runway_months'], 0.01);
    }
}
