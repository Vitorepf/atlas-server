<?php

namespace Tests\Feature\Ai\Company\Ventures\Reward;

use App\Models\AiReconciledCashEvent;
use App\Services\Ai\Company\Ventures\Reward\ReconciledCashEventStore;
use App\Services\Ai\Company\Ventures\VentureFoundryException;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReconciledCashEventStoreTest extends TestCase
{
    private string $venture;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('ai_reconciled_cash_events');
        Schema::create('ai_reconciled_cash_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.ai.venture.reconciled_cash_event.v1');
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
        Schema::dropIfExists('ai_reconciled_cash_events');
        parent::tearDown();
    }

    private function store(): ReconciledCashEventStore
    {
        return new ReconciledCashEventStore;
    }

    public function test_settled_credit_counts_toward_net(): void
    {
        $this->store()->record([
            'venture_id' => $this->venture,
            'source' => 'payment_processor',
            'external_ref' => 'pi_001',
            'amount_cents' => 150000,
            'occurred_at' => '2026-06-01T10:00:00Z',
        ]);

        $net = $this->store()->settledNetCentsForVenture(
            $this->venture, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30')
        );
        $this->assertSame(150000, $net);
    }

    public function test_unsettled_credit_does_not_count_until_settled(): void
    {
        $store = $this->store();
        $store->record([
            'venture_id' => $this->venture,
            'source' => 'payment_processor',
            'external_ref' => 'pi_horizon',
            'amount_cents' => 100000,
            'occurred_at' => '2026-06-05T10:00:00Z',
            'settlement_horizon_days' => 7,
        ]);

        $window = [Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30')];
        $this->assertSame(0, $store->settledNetCentsForVenture($this->venture, ...$window), 'clawback-pending cash must not pay');

        $store->settle('payment_processor', 'pi_horizon', Carbon::parse('2026-06-12'));
        $this->assertSame(100000, $store->settledNetCentsForVenture($this->venture, ...$window), 'settled cash counts');
    }

    public function test_refund_reduces_net(): void
    {
        $store = $this->store();
        $store->record(['venture_id' => $this->venture, 'source' => 'payment_processor', 'external_ref' => 'pi_002', 'amount_cents' => 100000, 'occurred_at' => '2026-06-01T10:00:00Z']);
        $refund = $store->record(['venture_id' => $this->venture, 'source' => 'payment_processor', 'external_ref' => 're_002', 'event_kind' => 'refund', 'amount_cents' => 40000, 'occurred_at' => '2026-06-03T10:00:00Z']);

        $this->assertSame(-40000, $refund->amount_cents, 'refund stored as negative');
        $net = $store->settledNetCentsForVenture($this->venture, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'));
        $this->assertSame(60000, $net);
    }

    public function test_db_rejects_self_reported_source(): void
    {
        // The honesty boundary is a SCHEMA invariant: a self-reported source
        // cannot even be inserted, bypassing any PHP guard.
        $this->expectException(QueryException::class);
        AiReconciledCashEvent::query()->create([
            'uuid' => (string) Str::uuid(),
            'venture_id' => $this->venture,
            'source' => 'operator', // not in the DB CHECK allow-list
            'event_kind' => 'credit',
            'external_ref' => 'fake_001',
            'amount_cents' => 999999,
            'currency' => 'BRL',
            'occurred_at' => Carbon::now(),
            'event_hash' => 'h_'.Str::uuid(),
        ]);
    }

    public function test_service_guard_also_rejects_self_reported_source(): void
    {
        $this->expectException(VentureFoundryException::class);
        $this->store()->record([
            'venture_id' => $this->venture,
            'source' => 'operator',
            'external_ref' => 'x',
            'amount_cents' => 1,
        ]);
    }

    public function test_idempotent_on_source_and_external_ref(): void
    {
        $store = $this->store();
        $a = $store->record(['venture_id' => $this->venture, 'source' => 'bank', 'external_ref' => 'txn_1', 'amount_cents' => 5000, 'occurred_at' => '2026-06-01T10:00:00Z']);
        $b = $store->record(['venture_id' => $this->venture, 'source' => 'bank', 'external_ref' => 'txn_1', 'amount_cents' => 5000, 'occurred_at' => '2026-06-01T10:00:00Z']);

        $this->assertSame($a->id, $b->id, 'same receipt counted once');
        $this->assertSame(1, AiReconciledCashEvent::query()->count());
    }

    public function test_monthly_settled_net_groups_by_month(): void
    {
        $store = $this->store();
        $store->record(['venture_id' => $this->venture, 'source' => 'payment_processor', 'external_ref' => 'a', 'amount_cents' => 100000, 'occurred_at' => '2026-04-01T10:00:00Z']);
        $store->record(['venture_id' => $this->venture, 'source' => 'payment_processor', 'external_ref' => 'b', 'amount_cents' => 120000, 'occurred_at' => '2026-05-01T10:00:00Z']);

        $monthly = $store->monthlySettledNetCents($this->venture);
        $this->assertSame(['2026-04' => 100000, '2026-05' => 120000], $monthly);
    }
}
