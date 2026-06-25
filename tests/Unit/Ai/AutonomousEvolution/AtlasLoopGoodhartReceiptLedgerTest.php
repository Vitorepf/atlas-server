<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopAntiGoodhartUnifiedRefusal;
use App\Services\Ai\AutonomousEvolution\AtlasLoopGoodhartReceiptLedger;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Tests\TestCase;

final class AtlasLoopGoodhartReceiptLedgerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable(AtlasLoopGoodhartReceiptLedger::TABLE)) {
            Schema::create(AtlasLoopGoodhartReceiptLedger::TABLE, function (Blueprint $table): void {
                $table->uuid('receipt_id')->primary();
                $table->string('campaign_id', 64)->nullable();
                $table->string('task_id', 96)->nullable();
                $table->timestamp('verdict_at');
                $table->boolean('refused');
                $table->json('pattern_ids');
                $table->json('facts');
                $table->json('source_classes');
                $table->json('evidence_refs');
                $table->string('judge_commit_sha', 64);
                $table->timestamps();
            });
        }
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists(AtlasLoopGoodhartReceiptLedger::TABLE);
        parent::tearDown();
    }

    public function test_every_verdict_writes_exactly_one_row_with_correct_refused_flag(): void
    {
        $ledger = new AtlasLoopGoodhartReceiptLedger;
        $ledger->setJudgeCommitShaProvider(fn (): string => 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeef');

        $refused = AtlasLoopAntiGoodhartUnifiedRefusal::verdict([
            [
                'source' => AtlasLoopAntiGoodhartUnifiedRefusal::SOURCE_PROXY,
                'pattern_id' => 'cyclomatic-proxy',
                'fact' => ['mutation_kills' => 0],
            ],
        ]);
        $allowed = AtlasLoopAntiGoodhartUnifiedRefusal::verdict([]); // not refused

        $ledger->record($refused, ['campaign_id' => 'C1', 'task_id' => 'T1']);
        $ledger->record($refused, ['campaign_id' => 'C1', 'task_id' => 'T2']);
        $ledger->record($refused, ['campaign_id' => 'C1', 'task_id' => 'T3']);
        $ledger->record($allowed, ['campaign_id' => 'C1', 'task_id' => 'T4']);
        $ledger->record($allowed, ['campaign_id' => 'C1', 'task_id' => 'T5']);

        $this->assertSame(5, $ledger->count());
        $this->assertSame(3, (int) DB::table(AtlasLoopGoodhartReceiptLedger::TABLE)->where('refused', true)->count());
        $this->assertSame(2, (int) DB::table(AtlasLoopGoodhartReceiptLedger::TABLE)->where('refused', false)->count());
    }

    public function test_ledger_exposes_no_delete_or_update_methods(): void
    {
        $ref = new ReflectionClass(AtlasLoopGoodhartReceiptLedger::class);
        foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $name = strtolower($method->getName());
            $this->assertStringNotContainsString('delete', $name, 'public ledger surface must NOT expose a delete method');
            $this->assertStringNotContainsString('truncate', $name);
            $this->assertStringNotContainsString('update', $name, "found update-like method: {$method->getName()}");
            $this->assertStringNotContainsString('purge', $name);
        }
    }

    public function test_assert_immutable_since_reports_zero_deletions_from_service_path(): void
    {
        $ledger = new AtlasLoopGoodhartReceiptLedger;
        $ledger->setJudgeCommitShaProvider(fn (): string => 'face');
        $verdict = AtlasLoopAntiGoodhartUnifiedRefusal::verdict([]);
        for ($i = 0; $i < 3; $i++) {
            $ledger->record($verdict);
        }

        $this->assertSame(0, $ledger->assertImmutableSince('2020-01-01 00:00:00'));
    }

    public function test_judge_commit_sha_from_provider_is_persisted_on_each_row(): void
    {
        $ledger = new AtlasLoopGoodhartReceiptLedger;
        $ledger->setJudgeCommitShaProvider(fn (): string => 'cafebabecafebabecafebabecafebabecafebabe');
        $receiptId = $ledger->record(AtlasLoopAntiGoodhartUnifiedRefusal::verdict([]));

        $row = $ledger->get((string) $receiptId);
        $this->assertSame('cafebabecafebabecafebabecafebabecafebabe', (string) ($row['judge_commit_sha'] ?? ''));
    }
}
