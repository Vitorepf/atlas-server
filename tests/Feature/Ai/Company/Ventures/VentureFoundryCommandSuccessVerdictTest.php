<?php

namespace Tests\Feature\Ai\Company\Ventures;

use App\Models\AiVenture;
use App\Models\AiVentureIdea;
use App\Services\Ai\Company\Ventures\Reward\ReconciledCashEventStore;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesStrategyRuntimeTables;
use Tests\Concerns\CreatesVentureFoundryTables;
use Tests\TestCase;

/**
 * Obra #7 W2 observe-wire: `atlas:venture venture-show` now surfaces the
 * Phase-1 honest success verdict (K1 reconciled cash + K4 health gate) as the
 * additive `success_verdict` envelope field. Exercises the REAL command path.
 */
class VentureFoundryCommandSuccessVerdictTest extends TestCase
{
    use CreatesStrategyRuntimeTables;
    use CreatesVentureFoundryTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createStrategyRuntimeTables();
        $this->createVentureFoundryTables();
        Schema::dropIfExists('ai_reconciled_cash_events');
        Schema::create('ai_reconciled_cash_events', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('schema_version', 120)->default('v1');
            $t->string('uuid', 64)->unique();
            $t->string('venture_id', 120)->index();
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
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_reconciled_cash_events');
        $this->dropVentureFoundryTables();
        $this->dropStrategyRuntimeTables();
        parent::tearDown();
    }

    private function promoteVenture(): AiVenture
    {
        $exit = $this->artisan('atlas:venture', [
            'action' => 'idea-register',
            '--title' => 'SaaS de conciliacao',
            '--problem' => 'Receita self-report mente',
            '--icp' => 'Operador Atlas',
            '--pain' => 'Verdito de sucesso desonesto',
            '--json' => true,
        ])->run();
        $this->assertSame(0, $exit);

        $exit = $this->artisan('atlas:venture', [
            'action' => 'promote',
            '--idea' => AiVentureIdea::query()->firstOrFail()->idea_id,
            '--name' => 'Concilia',
            '--thesis' => 'Sucesso so com caixa conciliado.',
            '--json' => true,
        ])->run();
        $this->assertSame(0, $exit);

        return AiVenture::query()->firstOrFail();
    }

    /** @return array<string,mixed> Real command run, captured JSON envelope. */
    private function showJson(string $ventureId): array
    {
        $exit = Artisan::call('atlas:venture', [
            'action' => 'venture-show',
            '--venture' => $ventureId,
            '--json' => true,
        ]);
        $this->assertSame(0, $exit, 'observe field must never break venture-show');

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);

        return $payload;
    }

    public function test_venture_show_surfaces_reconciled_success_verdict(): void
    {
        $venture = $this->promoteVenture();

        // K1: 3 consecutive months of settled reconciled cash >= R$1.000 (threshold).
        $cash = new ReconciledCashEventStore;
        foreach (['2026-04' => 120000, '2026-05' => 130000, '2026-06' => 110000] as $m => $cents) {
            $cash->record([
                'venture_id' => $venture->venture_id,
                'source' => 'payment_processor',
                'external_ref' => "pi-$m",
                'amount_cents' => $cents,
                'occurred_at' => "$m-05T10:00:00Z",
            ]);
        }

        $verdict = $this->showJson($venture->venture_id)['success_verdict'] ?? null;
        $this->assertIsArray($verdict, 'venture-show must carry the new success_verdict field');

        // MRR sustained (3 consecutive months over threshold) but NO health
        // signals exist at the CLI surface -> fail-closed gate blocks success.
        $this->assertSame('succeeded', $verdict['mrr_status']);
        $this->assertSame('blocked_by_health', $verdict['status']);
        $this->assertSame('blocked_unknown', $verdict['health_verdict']);
        $this->assertSame(3, $verdict['months_observed']);
        $this->assertSame(3, $verdict['trailing_streak']);
        $this->assertSame(120000, $verdict['monthly_cents']['2026-04']);
    }

    public function test_venture_show_verdict_is_insufficient_without_reconciled_cash(): void
    {
        $venture = $this->promoteVenture();

        $verdict = $this->showJson($venture->venture_id)['success_verdict'] ?? null;
        $this->assertIsArray($verdict);
        $this->assertSame('insufficient_data', $verdict['status']);
        $this->assertSame(0, $verdict['months_observed']);
    }

    public function test_venture_show_fails_open_to_null_when_cash_table_is_absent(): void
    {
        $venture = $this->promoteVenture();
        Schema::dropIfExists('ai_reconciled_cash_events');

        $payload = $this->showJson($venture->venture_id);
        $this->assertArrayHasKey('success_verdict', $payload);
        $this->assertNull($payload['success_verdict']);
        $this->assertSame('ok', $payload['status'], 'envelope status untouched by the observe field');
    }
}
