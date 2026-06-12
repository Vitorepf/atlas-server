<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopBacklogIntentSource;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTargetDiscoveryService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTargetRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAtlasEngineeringCodeTables;
use Tests\TestCase;

/**
 * L3-2: intents guiados pelo BACKLOG REAL (ataca os 94% de waste do Marco Zero).
 *
 * O Loop deixa de inventar tarefa genérica: a fonte de backlog emite alvos com OBJETIVO
 * ESPECÍFICO (manifesto curado de itens endereçáveis) e a descoberta os PROMOVE no ranking
 * com o sinal `backlog_reach` + o objetivo nomeado. Estes testes congelam: (a) o manifesto
 * só admite alvos que existem na árvore (backlog endereçável, não fantasma); (b) a flag OFF
 * não muda nada; (c) a flag ON injeta o alvo de backlog no topo com o sinal e o objetivo.
 */
final class AtlasLoopBacklogIntentTest extends TestCase
{
    use CreatesAtlasEngineeringCodeTables;

    /** @var list<string> */
    private array $dirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('atlas_loop_targets')) {
            foreach ([
                '2026_06_02_000100_create_atlas_loop_runtime_tables.php',
                '2026_06_02_000200_complete_atlas_loop_runtime_schema.php',
            ] as $file) {
                (require base_path('database/migrations/'.$file))->up();
            }
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->dirs as $d) {
            File::deleteDirectory($d);
        }
        @File::delete(storage_path('app/atlas/loop/backlog-intents.json'));
        $this->dropAtlasEngineeringCodeTables();
        parent::tearDown();
    }

    private function repoWith(string $relFile, string $contents): string
    {
        $d = sys_get_temp_dir().'/atlas-backlog-'.bin2hex(random_bytes(4));
        $this->dirs[] = $d;
        File::ensureDirectoryExists(dirname($d.'/'.$relFile));
        File::put($d.'/'.$relFile, $contents);

        return $d;
    }

    private function seedManifest(array $items): void
    {
        File::ensureDirectoryExists(storage_path('app/atlas/loop'));
        File::put(storage_path('app/atlas/loop/backlog-intents.json'), json_encode(['items' => $items]));
    }

    public function test_manifest_only_admits_targets_that_exist_in_the_tree(): void
    {
        $repo = $this->repoWith('app/Services/Real.php', "<?php\nclass Real {}\n");
        $this->seedManifest([
            ['path' => 'app/Services/Real.php', 'objective' => 'Endurecer Real', 'priority' => 0.9],
            ['path' => 'app/Services/Ghost.php', 'objective' => 'fantasma', 'priority' => 0.99],
        ]);

        $candidates = (new AtlasLoopBacklogIntentSource())->candidates($repo, 12);

        $paths = array_column($candidates, 'path');
        $this->assertContains('app/Services/Real.php', $paths, 'alvo existente é admitido');
        $this->assertNotContains('app/Services/Ghost.php', $paths, 'alvo fantasma é rejeitado');
        $this->assertSame('Endurecer Real', $candidates[0]['objective']);
    }

    public function test_manifest_items_are_ordered_by_priority_desc(): void
    {
        $repo = $this->repoWith('app/Services/A.php', "<?php\n");
        File::put($repo.'/app/Services/B.php', "<?php\n");
        $this->seedManifest([
            ['path' => 'app/Services/A.php', 'objective' => 'low', 'priority' => 0.6],
            ['path' => 'app/Services/B.php', 'objective' => 'high', 'priority' => 0.95],
        ]);

        $candidates = (new AtlasLoopBacklogIntentSource())->candidates($repo, 12);

        $this->assertSame('app/Services/B.php', $candidates[0]['path'], 'maior prioridade primeiro');
    }

    public function test_discovery_promotes_backlog_target_with_signal_when_flag_on(): void
    {
        $repo = $this->repoWith('app/Services/Target.php', "<?php\nclass Target {\n  public function f(){ return 1/0; }\n}\n");
        $this->seedManifest([
            ['path' => 'app/Services/Target.php', 'objective' => 'Corrigir divisão por zero em f()', 'priority' => 0.9],
        ]);
        config(['atlas.loop.discovery_backlog_intents' => true]);

        $discovery = new AtlasLoopTargetDiscoveryService(
            app(AtlasLoopTargetRepository::class),
            null,
            new AtlasLoopBacklogIntentSource(),
        );
        $result = $discovery->discover($repo, 'camp-l32', ['roots' => ['app/Services'], 'limit' => 5]);

        $paths = array_column($result['top'], 'path');
        $this->assertContains('app/Services/Target.php', $paths, 'o alvo de backlog é descoberto');

        // O target persistido carrega o sinal de backlog + o objetivo nomeado.
        $target = app(AtlasLoopTargetRepository::class)->claimTop('camp-l32', 5);
        $row = collect($target)->first(fn ($t) => $t->target_path === 'app/Services/Target.php');
        $this->assertNotNull($row, 'o alvo de backlog foi reivindicável');
        $signals = is_array($row->signals ?? null) ? $row->signals : (array) json_decode((string) $row->signals, true);
        $this->assertSame(1.0, (float) ($signals['backlog_reach'] ?? 0), 'sinal backlog_reach presente');
        $this->assertStringContainsString('divisão por zero', (string) ($signals['backlog_objective'] ?? ''));
    }

    public function test_impact_ranking_boosts_indexed_surface_and_cooldown_downranks_recent_targets(): void
    {
        $campaignId = 'camp-l41';
        $highImpact = 'app/Services/HighImpact.php';
        $recentFarmed = 'app/Services/RecentFarmed.php';
        $repo = $this->repoWith($highImpact, $this->classFixture('HighImpact'));
        File::put($repo.'/'.$recentFarmed, $this->classFixture('RecentFarmed'));
        $this->createAtlasEngineeringCodeTables();
        $this->seedCodeSymbols($highImpact, 9);
        $this->seedCodeSymbols($recentFarmed, 1);
        $this->seedRecentTargetActivity($campaignId, $recentFarmed);
        config([
            'atlas.loop.impact_ranking_enabled' => true,
            'atlas.loop.target_cooldown_enabled' => true,
            'atlas.loop.target_cooldown_hours' => 24,
            'atlas.loop.discovery_backlog_intents' => false,
        ]);

        $discovery = new AtlasLoopTargetDiscoveryService(app(AtlasLoopTargetRepository::class));
        $result = $discovery->discover($repo, $campaignId, ['roots' => ['app/Services'], 'limit' => 5]);

        $this->assertSame($highImpact, $result['top'][0]['path'], 'impacto real supera o alvo recém-farmado');

        $rows = DB::table('atlas_loop_targets')
            ->where('campaign_id', $campaignId)
            ->get()
            ->keyBy('target_path');
        $highSignals = (array) json_decode((string) $rows[$highImpact]->signals, true);
        $recentSignals = (array) json_decode((string) $rows[$recentFarmed]->signals, true);

        $this->assertSame(9, (int) ($highSignals['impact_code_symbols'] ?? 0), 'surface indexada entra no ranking');
        $this->assertGreaterThan(0.0, (float) ($highSignals['impact_rank'] ?? 0), 'impact_rank é material');
        $this->assertSame(1.0, (float) ($recentSignals['target_cooldown'] ?? 0), 'cooldown carimba o alvo recente');
        $this->assertGreaterThanOrEqual(2, (int) ($recentSignals['target_cooldown_hits'] ?? 0), 'tasks+proposals recentes contam');
        $this->assertLessThan((float) $rows[$highImpact]->score, (float) $rows[$recentFarmed]->score, 'cooldown derruba score final');
    }

    public function test_flag_off_injects_nothing(): void
    {
        $repo = $this->repoWith('app/Services/Target.php', "<?php\nclass Target {}\n");
        $this->seedManifest([
            ['path' => 'app/Services/Target.php', 'objective' => 'algo', 'priority' => 0.9],
        ]);
        config(['atlas.loop.discovery_backlog_intents' => false]);

        $discovery = new AtlasLoopTargetDiscoveryService(
            app(AtlasLoopTargetRepository::class),
            null,
            new AtlasLoopBacklogIntentSource(),
        );
        $result = $discovery->discover($repo, 'camp-off', ['roots' => ['app/Services'], 'limit' => 5]);

        // Sem a flag, o alvo só apareceria por mérito estrutural — e como a classe é trivial
        // e self-contained, não carrega o sinal de backlog.
        $target = app(AtlasLoopTargetRepository::class)->claimTop('camp-off', 5);
        $row = collect($target)->first(fn ($t) => $t->target_path === 'app/Services/Target.php');
        if ($row !== null) {
            $signals = is_array($row->signals ?? null) ? $row->signals : (array) json_decode((string) $row->signals, true);
            $this->assertArrayNotHasKey('backlog_reach', $signals, 'flag OFF não injeta sinal de backlog');
        }
        $this->assertTrue(true);
    }

    private function classFixture(string $class): string
    {
        $padding = implode("\n", array_map(static fn (int $i): string => '  // pad '.$i, range(1, 42)));

        return <<<PHP
        <?php
        final class {$class}
        {
        {$padding}
            public function handle(int \$value): int
            {
                return \$value + 1;
            }
        }
        PHP;
    }

    private function seedCodeSymbols(string $path, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            DB::table('atlas_engineering_code_symbols')->insert([
                'id' => (string) Str::uuid(),
                'symbol_type' => 'method',
                'symbol_name' => basename($path, '.php').'::m'.$i,
                'file_path' => $path,
                'status' => 'active',
                'source_hash' => hash('sha256', $path.'|'.$i),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function seedRecentTargetActivity(string $campaignId, string $path): void
    {
        DB::table('atlas_loop_tasks')->insert([
            'id' => (string) Str::uuid(),
            'campaign_id' => $campaignId,
            'schema_version' => 'atlas.loop.task.v1',
            'status' => 'done',
            'source' => 'discovery',
            'self_contained' => true,
            'target_path' => $path,
            'objective' => 'recent task on same target',
            'payload' => json_encode([]),
            'priority' => 0,
            'attempts' => 0,
            'max_attempts' => 1,
            'dedupe_key' => hash('sha256', $campaignId.'|task|'.$path),
            'created_at' => now()->subHours(2),
            'updated_at' => now()->subHours(2),
        ]);
        DB::table('atlas_loop_proposals')->insert([
            'id' => (string) Str::uuid(),
            'campaign_id' => $campaignId,
            'task_id' => null,
            'schema_version' => 'atlas.loop.proposal.v1',
            'status' => 'certified_for_review',
            'objective' => 'recent proposal on same target',
            'provider' => 'test',
            'target_path' => $path,
            'diff_text' => "--- a/{$path}\n+++ b/{$path}\n",
            'proposal_hash' => hash('sha256', $campaignId.'|proposal|'.$path),
            'metric' => json_encode([]),
            'acceptance_hash' => null,
            'scenarios_explored' => 1,
            'scenarios_accepted' => 1,
            'winning_scenario' => 'test',
            'merged_to_main' => false,
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);
    }
}
