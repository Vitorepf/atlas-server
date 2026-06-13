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
}
