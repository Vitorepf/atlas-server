<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * L4-2: Backlog auto-alimentado.
 *
 * Congela o contrato: sinais reais e repetidos viram intents endereçáveis no manifesto,
 * itens manuais são preservados, e a segunda execução dedupa.
 */
final class AtlasLoopBacklogAutoFeedTest extends TestCase
{
    private string $manifestPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manifestPath = storage_path('framework/testing/atlas-loop-backlog-feed-'.(string) Str::uuid().'.json');
        if (! Schema::hasTable('atlas_loop_campaigns')) {
            foreach ([
                '2026_06_02_000100_create_atlas_loop_runtime_tables.php',
                '2026_06_02_000200_complete_atlas_loop_runtime_schema.php',
            ] as $file) {
                (require base_path('database/migrations/'.$file))->up();
            }
        }
        if (! Schema::hasTable('failure_signatures')) {
            (require base_path('database/migrations/2026_05_07_160000_create_failure_signatures_table.php'))->up();
        }
        DB::table('failure_signatures')->delete();
        @File::delete($this->manifestPath);
    }

    protected function tearDown(): void
    {
        @File::delete($this->manifestPath);
        parent::tearDown();
    }

    public function test_repeated_real_signals_grow_manifest_and_rerun_dedupes(): void
    {
        config([
            'atlas.loop.backlog_auto_feed.include_scorecard_weak_receipts' => false,
            'atlas.loop.backlog_auto_feed.include_sweep_findings' => false,
        ]);
        $manual = 'app/Services/Ai/AutonomousEvolution/Campaign/AtlasLoopCampaignSupervisor.php';
        $failureTarget = 'app/Services/Ai/AutonomousEvolution/AtlasLoopTaskGrinder.php';
        $residualTarget = 'app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopTargetDiscoveryService.php';
        $this->seedManifest([
            ['path' => $manual, 'objective' => 'Item manual preservado', 'priority' => 0.5, 'source' => 'manual'],
        ]);

        $campaign = AtlasLoopCampaign::query()->create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 'l4-2-auto-feed-test',
            'base_workspace' => base_path(),
            'config' => [],
            'max_seconds' => 3600,
        ]);
        $this->seedFailureCorpus($failureTarget, 2);
        $this->seedResidualTasks($campaign->id, $residualTarget, 2);

        $exit = Artisan::call('atlas:loop:backlog-feed', [
            '--campaign' => $campaign->id,
            '--window-hours' => '24',
            '--min-signal-count' => '2',
            '--max-items' => '5',
            '--manifest-path' => $this->manifestPath,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame('acted', $payload['status']);
        $this->assertSame(2, $payload['enqueued_count']);
        $this->assertGreaterThanOrEqual(1, $payload['source_counts']['failure_corpus']);
        $this->assertGreaterThanOrEqual(1, $payload['source_counts']['campaign_residuals']);

        $manifest = json_decode((string) File::get($this->manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(3, $manifest['items']);
        $sources = array_column($manifest['items'], 'source');
        $paths = array_column($manifest['items'], 'path');
        $this->assertContains('manual', $sources);
        $this->assertContains('auto_feed:failure_corpus', $sources);
        $this->assertContains('auto_feed:campaign_residual', $sources);
        $this->assertContains($manual, $paths);
        $this->assertContains($failureTarget, $paths);
        $this->assertContains($residualTarget, $paths);

        Artisan::call('atlas:loop:backlog-feed', [
            '--campaign' => $campaign->id,
            '--window-hours' => '24',
            '--min-signal-count' => '2',
            '--max-items' => '5',
            '--manifest-path' => $this->manifestPath,
            '--json' => true,
        ]);
        $again = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(0, $again['enqueued_count']);
        $this->assertSame(['duplicate', 'duplicate'], array_column($again['actions'], 'status'));

        $manifestAgain = json_decode((string) File::get($this->manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(3, $manifestAgain['items']);
    }

    /**
     * @param  list<array<string,mixed>>  $items
     */
    private function seedManifest(array $items): void
    {
        File::ensureDirectoryExists(dirname($this->manifestPath));
        File::put($this->manifestPath, json_encode(['items' => $items], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function seedFailureCorpus(string $path, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            DB::table('failure_signatures')->insert([
                'envelope_id' => 'l4-2-'.$i,
                'source_ledger_event_id' => null,
                'signature_key' => 'l4-2-recurring-timeout',
                'domain' => 'loop',
                'category' => 'gate_error',
                'sub_cause' => 'semantic_certifier_timeout',
                'context_summary' => 'Repeated failure in '.$path.' while grinding Loop task.',
                'canonical_features' => json_encode(['path' => $path]),
                'vector_embedding' => null,
                'similarity_to_previous' => null,
                'recurrence_count' => 1,
                'recorded_at' => now()->subMinutes(15),
                'created_at' => now()->subMinutes(15),
                'updated_at' => now()->subMinutes(15),
            ]);
        }
    }

    private function seedResidualTasks(string $campaignId, string $path, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            DB::table('atlas_loop_tasks')->insert([
                'id' => (string) Str::uuid(),
                'campaign_id' => $campaignId,
                'schema_version' => 'atlas.loop.task.v1',
                'status' => 'done',
                'source' => 'discovery',
                'self_contained' => true,
                'target_path' => $path,
                'objective' => 'residual no proposal '.$i,
                'payload' => json_encode(['allowed_files' => [$path]]),
                'priority' => 0,
                'attempts' => 1,
                'max_attempts' => 1,
                'dedupe_key' => hash('sha256', $campaignId.'|residual|'.$i),
                'result' => json_encode(['status' => 'no_winner', 'reason' => 'no_winner_after_search']),
                'created_at' => now()->subMinutes(20),
                'updated_at' => now()->subMinutes(20),
            ]);
        }
    }
}
