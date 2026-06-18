<?php

namespace Tests\Feature\Ai\VentureFoundry\Success;

use App\Services\Ai\VentureFoundry\Health\VentureHealthGate;
use App\Services\Ai\VentureFoundry\Reward\ReconciledCashEventStore;
use App\Services\Ai\VentureFoundry\Success\VentureReconciledSuccessEvaluator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class VentureReconciledSuccessEvaluatorTest extends TestCase
{
    private string $venture;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('ai_reconciled_cash_events');
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
        Schema::dropIfExists('ai_reconciled_cash_events');
        parent::tearDown();
    }

    private function evaluator(): VentureReconciledSuccessEvaluator
    {
        return new VentureReconciledSuccessEvaluator(new ReconciledCashEventStore, new VentureHealthGate);
    }

    private function recordMonth(string $ym, int $cents): void
    {
        (new ReconciledCashEventStore)->record([
            'venture_id' => $this->venture, 'source' => 'payment_processor',
            'external_ref' => $ym.'-'.$cents, 'amount_cents' => $cents, 'occurred_at' => $ym.'-05T10:00:00Z',
        ]);
    }

    private function allGreen(): array
    {
        return ['solvency' => 'green', 'churn' => 'green', 'concentration' => 'green', 'legality' => 'green', 'deliverability' => 'green'];
    }

    public function test_insufficient_when_under_min_months(): void
    {
        $this->recordMonth('2026-04', 120000);
        $r = $this->evaluator()->evaluate($this->venture, $this->allGreen());
        $this->assertSame(VentureReconciledSuccessEvaluator::STATUS_INSUFFICIENT, $r['status']);
    }

    public function test_succeeded_with_sustained_reconciled_mrr_and_health(): void
    {
        $this->recordMonth('2026-04', 120000);
        $this->recordMonth('2026-05', 130000);
        $this->recordMonth('2026-06', 110000);
        $r = $this->evaluator()->evaluate($this->venture, $this->allGreen());
        $this->assertSame(VentureReconciledSuccessEvaluator::STATUS_SUCCEEDED, $r['status']);
        $this->assertSame('succeeded', $r['mrr_status']);
    }

    public function test_sustained_mrr_but_unhealthy_is_blocked_not_succeeded(): void
    {
        $this->recordMonth('2026-04', 120000);
        $this->recordMonth('2026-05', 130000);
        $this->recordMonth('2026-06', 110000);
        $signals = $this->allGreen();
        $signals['legality'] = 'red';
        $r = $this->evaluator()->evaluate($this->venture, $signals);
        $this->assertSame(VentureReconciledSuccessEvaluator::STATUS_BLOCKED_BY_HEALTH, $r['status']);
        $this->assertSame('succeeded', $r['mrr_status'], 'MRR is sustained...');
        $this->assertContains('legality', $r['health_blocking']['red'], '...but health red blocks success');
    }

    public function test_below_threshold_month_is_not_yet(): void
    {
        $this->recordMonth('2026-04', 120000);
        $this->recordMonth('2026-05', 50000); // below R$1000
        $this->recordMonth('2026-06', 110000);
        $r = $this->evaluator()->evaluate($this->venture, $this->allGreen());
        $this->assertSame(VentureReconciledSuccessEvaluator::STATUS_NOT_YET, $r['status']);
    }

    public function test_churned_after_streak_is_failed(): void
    {
        foreach (['2026-01', '2026-02', '2026-03'] as $m) {
            $this->recordMonth($m, 120000);
        }
        $this->recordMonth('2026-04', 10000);  // dropped below
        $this->recordMonth('2026-05', 10000);
        $this->recordMonth('2026-06', 10000);
        $r = $this->evaluator()->evaluate($this->venture, $this->allGreen());
        $this->assertSame(VentureReconciledSuccessEvaluator::STATUS_FAILED, $r['status']);
    }
}
