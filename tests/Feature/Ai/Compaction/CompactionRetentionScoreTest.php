<?php

namespace Tests\Feature\Ai\Compaction;

use App\Models\AtlasLongHorizonCompactionReceipt;
use App\Services\Ai\AiCompactionService;
use App\Services\Ai\LongHorizon\AtlasLongHorizonCanon;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\CreatesLongHorizonPersistenceTables;
use Tests\TestCase;

final class CompactionRetentionScoreTest extends TestCase
{
    use CreatesLongHorizonPersistenceTables;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createLongHorizonPersistenceTables();
    }

    protected function tearDown(): void
    {
        $this->dropLongHorizonPersistenceTables();

        parent::tearDown();
    }

    public function test_compact_for_scope_persists_context_retention_score_sensitive_to_summary_content(): void
    {
        $service = app(AiCompactionService::class);

        $full = $service->compactForScope([
            'scope_type' => AtlasLongHorizonCanon::SCOPE_TYPE_MISSION,
            'scope_id' => 'mission-retention-full',
            'must_keep_items' => [
                ['id' => 'mk-alpha', 'kind' => 'decision', 'digest' => 'Alpha retained'],
                ['id' => 'mk-beta', 'kind' => 'decision', 'digest' => 'Beta retained'],
            ],
        ]);
        $degraded = $service->compactForScope([
            'scope_type' => AtlasLongHorizonCanon::SCOPE_TYPE_MISSION,
            'scope_id' => 'mission-retention-degraded',
            'must_keep_items' => [
                ['id' => 'mk-alpha', 'kind' => 'decision', 'digest' => 'Alpha retained'],
                ['id' => 'mk-beta', 'kind' => 'decision', 'digest' => 'Beta retained'],
            ],
            'forced_discards' => [
                ['id' => 'mk-beta', 'reason' => AtlasLongHorizonCanon::DISCARDED_REASON_BUDGET_PRESSURE],
            ],
        ]);

        $this->assertSame(1.0, $full['context_retention_score']);
        $this->assertLessThan($full['context_retention_score'], $degraded['context_retention_score']);

        $rows = AtlasLongHorizonCompactionReceipt::query()->orderBy('created_at')->get();
        $this->assertSame(1.0, $rows[0]->context_retention_score);
        $this->assertSame($degraded['context_retention_score'], $rows[1]->context_retention_score);
    }

    public function test_token_economy_command_exposes_retention_aggregates_from_compaction_receipts(): void
    {
        app(AiCompactionService::class)->compactForScope([
            'scope_type' => AtlasLongHorizonCanon::SCOPE_TYPE_MISSION,
            'scope_id' => 'mission-retention-command',
            'must_keep_items' => [
                ['id' => 'mk-alpha', 'kind' => 'decision', 'digest' => 'Alpha retained'],
                ['id' => 'mk-beta', 'kind' => 'decision', 'digest' => 'Beta retained'],
            ],
            'forced_discards' => [
                ['id' => 'mk-beta', 'reason' => AtlasLongHorizonCanon::DISCARDED_REASON_BUDGET_PRESSURE],
            ],
        ]);

        $exit = Artisan::call('atlas:context:token-economy', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.context_retention.runtime.v1', data_get($payload, 'retention.schema_version'));
        $this->assertSame(1, data_get($payload, 'retention.receipt_count'));
        $this->assertLessThan(1.0, data_get($payload, 'retention.average_context_retention_score'));
        $this->assertSame(1.0, data_get($payload, 'retention.compaction_loss_rate'));
    }
}
