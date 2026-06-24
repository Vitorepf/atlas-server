<?php

namespace Tests\Feature\Marketing;

use App\Models\AiKeywordDecisionLedger;
use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Campaign\KeywordDecisionLedgerRepository;
use App\Services\Ai\MarketingDomain\Campaign\KeywordDecisionReceipt;
use App\Services\Ai\MarketingDomain\Campaign\KeywordIntelligencePipeline;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Locks the L12 durable ledger: Decision-Receipts persist to DB, are idempotent on receipt_hash (same
 * deterministic decision → 1 row), and a whole pipeline run's selection is queryable by run_hash —
 * "proveniência por decisão" consultável em escala, sem re-rodar nada.
 *
 * As migrations globais do projeto são Postgres-only (CREATE EXTENSION) e não rodam no sqlite :memory: do
 * teste; então criamos só a tabela do ledger aqui — persistência real, isolada das migrations do projeto.
 */
class KeywordDecisionLedgerRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('ai_keyword_decision_ledger');
        Schema::create('ai_keyword_decision_ledger', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('receipt_hash', 40)->unique();
            $table->string('run_hash', 64)->nullable()->index();
            $table->string('keyword', 255)->index();
            $table->unsignedTinyInteger('score')->nullable();
            $table->string('band', 20)->nullable();
            $table->string('family', 60)->nullable();
            $table->string('intent_tier', 4)->nullable();
            $table->string('investment_verdict', 20)->nullable();
            $table->string('investment_basis', 30)->nullable();
            $table->string('account_risk', 20)->default('none');
            $table->string('core_version', 20);
            $table->json('receipt');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_keyword_decision_ledger');
        parent::tearDown();
    }

    private function receipt(string $keyword = 'blue salt trick', int $score = 90): array
    {
        return (new KeywordDecisionReceipt)->issue([
            'keyword' => $keyword,
            'score' => $score,
            'band' => 'scale',
            'family' => 'mechanism_trick',
            'match_type' => 'exact/phrase',
            'intent' => ['tier' => 'T4', 'action' => 'buy', 'polarity' => 'neutral', 'confidence' => 0.85],
            'investment' => ['verdict' => 'investimento', 'basis' => 'forecast_prior'],
            'account_risk' => ['risk_level' => 'high'],
            'mind_state' => ['awareness' => 'most_aware'],
        ]);
    }

    public function test_records_and_reads_back(): void
    {
        $repo = new KeywordDecisionLedgerRepository;
        $rec = $this->receipt();
        $repo->record($rec, 'run-abc');

        $this->assertDatabaseCount('ai_keyword_decision_ledger', 1);
        $found = $repo->find($rec['receipt_hash']);
        $this->assertNotNull($found);
        $this->assertSame('blue salt trick', $found->keyword);
        $this->assertSame('T4', $found->intent_tier);
        $this->assertSame('investimento', $found->investment_verdict);
        $this->assertIsArray($found->receipt); // o recibo inteiro com proveniência fica auditável
    }

    public function test_idempotent_same_receipt_no_duplicate(): void
    {
        $repo = new KeywordDecisionLedgerRepository;
        $rec = $this->receipt();
        $repo->record($rec, 'run-abc');
        $repo->record($rec, 'run-abc'); // mesma decisão determinística

        $this->assertDatabaseCount('ai_keyword_decision_ledger', 1);
    }

    public function test_distinct_decisions_make_distinct_rows(): void
    {
        $repo = new KeywordDecisionLedgerRepository;
        $repo->record($this->receipt('blue salt trick', 90), 'run-abc');
        $repo->record($this->receipt('gelatin trick', 80), 'run-abc');

        $this->assertDatabaseCount('ai_keyword_decision_ledger', 2);
        $this->assertCount(2, $repo->forRun('run-abc'));
    }

    public function test_record_run_persists_a_whole_pipeline_selection(): void
    {
        $asset = new AiMarketingVslAsset([
            'mechanism_name' => 'Triple Hormone Drops Protocol',
            'trick' => 'at-home retatrutide protocol',
            'niche' => 'weight loss',
            'offer' => ['product_name' => 'Lipo Bliss'],
        ]);
        $run = (new KeywordIntelligencePipeline)->run($asset, ['payout' => 120, 'cvr' => 0.012]);

        $n = (new KeywordDecisionLedgerRepository)->recordRun($run);
        $this->assertGreaterThan(0, $n, 'um run grava os recibos da seleção');
        $this->assertSame($n, AiKeywordDecisionLedger::query()->where('run_hash', $run['run_hash'])->count());
    }
}
