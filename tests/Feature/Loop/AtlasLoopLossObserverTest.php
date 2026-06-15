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
 * L4-4: Loss-observer diário.
 *
 * Congela a autópsia que faltava no soak: quando o ledger mostra a MESMA razão de
 * rejeição dominando várias tasks recentes, o observer abre um intent de backlog
 * endereçável e dedupado para o próprio Loop atacar no próximo refill.
 */
final class AtlasLoopLossObserverTest extends TestCase
{
    private string $manifestPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manifestPath = storage_path('framework/testing/atlas-loop-loss-observer-'.(string) Str::uuid().'.json');
        if (! Schema::hasTable('atlas_loop_campaigns')) {
            foreach ([
                '2026_06_02_000100_create_atlas_loop_runtime_tables.php',
                '2026_06_02_000200_complete_atlas_loop_runtime_schema.php',
            ] as $file) {
                (require base_path('database/migrations/'.$file))->up();
            }
        }
        @File::delete($this->manifestPath);
    }

    protected function tearDown(): void
    {
        @File::delete($this->manifestPath);
        parent::tearDown();
    }

    public function test_synthetic_dominant_gate_failure_opens_deduped_backlog_intent(): void
    {
        $campaign = AtlasLoopCampaign::query()->create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 'l4-4-loss-observer-test',
            'base_workspace' => base_path(),
            'config' => [],
            'max_seconds' => 3600,
        ]);
        $target = 'app/Services/Ai/AutonomousEvolution/AtlasLoopTaskGrinder.php';
        $reason = 'gate_error:semantic_certifier_timeout';
        for ($i = 0; $i < 3; $i++) {
            $taskId = (string) Str::uuid();
            DB::table('atlas_loop_tasks')->insert([
                'id' => $taskId,
                'campaign_id' => $campaign->id,
                'schema_version' => 'atlas.loop.task.v1',
                'status' => 'done',
                'source' => 'discovery',
                'self_contained' => true,
                'target_path' => $target,
                'objective' => 'synthetic loss '.$i,
                'payload' => json_encode(['allowed_files' => [$target]]),
                'priority' => 0,
                'attempts' => 1,
                'max_attempts' => 1,
                'dedupe_key' => hash('sha256', $campaign->id.'|'.$i),
                'result' => json_encode(['status' => 'no_winner']),
                'created_at' => now()->subHour(),
                'updated_at' => now()->subHour(),
            ]);
            DB::table('atlas_loop_explorations')->insert([
                'id' => (string) Str::uuid(),
                'campaign_id' => $campaign->id,
                'task_id' => $taskId,
                'schema_version' => 'atlas.loop.exploration.v1',
                'objective' => 'synthetic loss '.$i,
                'provider' => 'test',
                'scenarios_explored' => 1,
                'scenarios_accepted' => 0,
                'has_winner' => false,
                'converged' => false,
                'rejected_reasons' => json_encode([$reason]),
                'elapsed_seconds' => 1.0,
                'created_at' => now()->subMinutes(30),
                'updated_at' => now()->subMinutes(30),
            ]);
        }

        $exit = Artisan::call('atlas:loop:loss-observer', [
            '--campaign' => $campaign->id,
            '--window-hours' => '24',
            '--min-occurrences' => '3',
            '--manifest-path' => $this->manifestPath,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame('acted', $payload['status']);
        $this->assertSame(1, $payload['dominant_count']);
        $this->assertSame($reason, $payload['dominant_patterns'][0]['reason']);
        $this->assertSame($target, $payload['dominant_patterns'][0]['target_path']);
        $this->assertSame('enqueued', $payload['actions'][0]['status']);

        $manifest = json_decode((string) File::get($this->manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(1, $manifest['items']);
        $this->assertSame('loss_observer', $manifest['items'][0]['source']);
        $this->assertSame($target, $manifest['items'][0]['path']);
        $this->assertStringContainsString($reason, $manifest['items'][0]['objective']);

        Artisan::call('atlas:loop:loss-observer', [
            '--campaign' => $campaign->id,
            '--window-hours' => '24',
            '--min-occurrences' => '3',
            '--manifest-path' => $this->manifestPath,
            '--json' => true,
        ]);
        $again = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('duplicate', $again['actions'][0]['status'], 'segunda autópsia não duplica intent');
        $manifestAgain = json_decode((string) File::get($this->manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(1, $manifestAgain['items']);
    }

    /**
     * REGRESSION (#13): a loss reason that dominates across MULTIPLE files is N pieces of heavy-refactor
     * re-attack work, not one. The old `dominantPatterns` grouped by reason ALONE and emitted only the
     * single most-frequent path (array_key_first) — silently DROPPING every other affected file AND
     * mis-reporting the survivor's `occurrences` as the inflated cross-path sum. This freezes the fix:
     * the same reason spanning two distinct targets yields TWO patterns, each with its OWN honest count.
     */
    public function test_same_loss_reason_across_two_files_emits_one_pattern_per_file_with_honest_counts(): void
    {
        $campaign = AtlasLoopCampaign::query()->create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 'l4-4-loss-observer-multipath',
            'base_workspace' => base_path(),
            'config' => [],
            'max_seconds' => 3600,
        ]);
        $reason = 'gate_error:complexity_not_reduced';
        // The SAME reason hits file A three times and file B twice. Group total = 5 (clears the
        // dominance gate at min_occurrences=3); each emitted pattern must carry its OWN per-file count.
        $seed = function (string $target, int $times) use ($campaign, $reason): void {
            for ($i = 0; $i < $times; $i++) {
                $taskId = (string) Str::uuid();
                DB::table('atlas_loop_tasks')->insert([
                    'id' => $taskId,
                    'campaign_id' => $campaign->id,
                    'schema_version' => 'atlas.loop.task.v1',
                    'status' => 'done',
                    'source' => 'discovery',
                    'self_contained' => true,
                    'target_path' => $target,
                    'objective' => 'multipath loss '.$target.' '.$i,
                    'payload' => json_encode(['allowed_files' => [$target]]),
                    'priority' => 0,
                    'attempts' => 1,
                    'max_attempts' => 1,
                    'dedupe_key' => hash('sha256', $campaign->id.'|'.$target.'|'.$i),
                    'result' => json_encode(['status' => 'no_winner']),
                    'created_at' => now()->subHour(),
                    'updated_at' => now()->subHour(),
                ]);
                DB::table('atlas_loop_explorations')->insert([
                    'id' => (string) Str::uuid(),
                    'campaign_id' => $campaign->id,
                    'task_id' => $taskId,
                    'schema_version' => 'atlas.loop.exploration.v1',
                    'objective' => 'multipath loss '.$target.' '.$i,
                    'provider' => 'test',
                    'scenarios_explored' => 1,
                    'scenarios_accepted' => 0,
                    'has_winner' => false,
                    'converged' => false,
                    'rejected_reasons' => json_encode([$reason]),
                    'elapsed_seconds' => 1.0,
                    'created_at' => now()->subMinutes(30),
                    'updated_at' => now()->subMinutes(30),
                ]);
            }
        };
        $fileA = 'app/Services/Ai/AutonomousEvolution/AtlasLoopTaskGrinder.php';
        $fileB = 'app/Services/Ai/AutonomousEvolution/AtlasLoopSignalAnalyzer.php';
        $seed($fileA, 3);
        $seed($fileB, 2);

        Artisan::call('atlas:loop:loss-observer', [
            '--campaign' => $campaign->id,
            '--window-hours' => '24',
            '--min-occurrences' => '3',
            '--manifest-path' => $this->manifestPath,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('acted', $payload['status']);
        // BOTH files surface — not just the dominant one (the #13 bug dropped file B entirely).
        $this->assertSame(2, $payload['dominant_count'], 'one pattern PER affected file, not one for the whole reason');

        $byPath = [];
        foreach ($payload['dominant_patterns'] as $pattern) {
            $this->assertSame($reason, $pattern['reason']);
            $byPath[$pattern['target_path']] = $pattern;
        }
        $this->assertArrayHasKey($fileA, $byPath, 'the dominant file is present');
        $this->assertArrayHasKey($fileB, $byPath, 'the second affected file is NOT dropped');
        // Each pattern carries its OWN honest count — NOT the inflated cross-path sum (the #13 lie was 5).
        $this->assertSame(3, $byPath[$fileA]['occurrences'], 'file A reports its own 3 hits');
        $this->assertSame(3, $byPath[$fileA]['target_hits']);
        $this->assertSame(2, $byPath[$fileB]['occurrences'], 'file B reports its own 2 hits, not the cross-path 5');
        $this->assertSame(2, $byPath[$fileB]['target_hits']);

        // Both produced an addressable, distinct backlog intent (distinct source_key → no dedup collision).
        $manifest = json_decode((string) File::get($this->manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $paths = array_column($manifest['items'], 'path');
        $this->assertContains($fileA, $paths);
        $this->assertContains($fileB, $paths);
        $this->assertCount(2, $manifest['items'], 'two distinct files → two distinct intents');
    }
}
