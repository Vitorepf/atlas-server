<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\AtlasLoopMetaHarnessAbLiftService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopBacklogIntentSource;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopMetaHarnessIntentSource;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTargetDiscoveryService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTargetRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * L6-1: o "loop-proposes-harness" path congelado.
 *
 * O medidor A/B já estava ligado; o gap era SOAK-GATED: nada ENFILEIRAVA uma melhoria do
 * próprio harness, então o braço `meta_harness` ficava em zero para sempre. Esta perna é o
 * produtor honesto desse braço. Estes testes congelam o invariante:
 *   (1) com as flags ON, a fonte emite candidatos de harness NÃO-segurança com objetivo nomeado;
 *   (2) o conjunto PÉTREO (frozen judge, gates, never-merge) NUNCA é emitido — em flag alguma;
 *   (3) flag OFF (qualquer das duas) ⇒ lista vazia (fail-closed);
 *   (4) end-to-end pela discovery: um alvo de harness aparece, o pétreo nunca aparece;
 *   (5) os tasks resultantes caem no braço `meta_harness` do medidor A/B (case_count vivo enche sozinho).
 */
final class AtlasLoopMetaHarnessIntentTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    private string $campaignId;

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
        DB::table('atlas_loop_proposals')->delete();
        DB::table('atlas_loop_tasks')->delete();
        DB::table('atlas_loop_campaigns')->delete();
        $this->campaignId = (string) Str::uuid();
        DB::table('atlas_loop_campaigns')->insert([
            'id' => $this->campaignId,
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => 'running',
            'goal' => 'l6-1-meta-harness-intent-test',
            'base_workspace' => base_path(),
            'config' => json_encode([]),
            'max_seconds' => 3600,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->dirs as $d) {
            File::deleteDirectory($d);
        }
        parent::tearDown();
    }

    /**
     * Builds a temp repo whose harness directory contains a PÉTREO file (frozen judge), an
     * admissible NON-SAFETY harness file, and an ordinary app file. The harness fixture has
     * real framework reach + 40+ LOC so the framework-target discovery path can admit it.
     */
    private function repoWithHarnessTree(): string
    {
        $d = sys_get_temp_dir().'/atlas-meta-harness-'.bin2hex(random_bytes(4));
        $this->dirs[] = $d;

        $harnessDir = 'app/Services/Ai/AutonomousEvolution';
        File::ensureDirectoryExists($d.'/'.$harnessDir.'/Discovery');
        File::ensureDirectoryExists($d.'/app/Support');

        // PÉTREO: o frozen judge — jamais um alvo.
        File::put(
            $d.'/'.$harnessDir.'/AtlasEvolutionFrozenJudge.php',
            $this->harnessFixture('AtlasEvolutionFrozenJudge'),
        );
        // Admissível: harness NÃO-segurança.
        File::put(
            $d.'/'.$harnessDir.'/Discovery/AtlasLoopQueueRefiller.php',
            $this->harnessFixture('AtlasLoopQueueRefiller'),
        );
        // Ordinário (fora do harness).
        File::put(
            $d.'/app/Support/PlainHelper.php',
            $this->harnessFixture('PlainHelper'),
        );

        return $d;
    }

    private function harnessFixture(string $class): string
    {
        $body = str_repeat("        // honest body line keeping the fixture above the LOC floor\n", 30);

        return "<?php\n\ndeclare(strict_types=1);\n\nuse Illuminate\\Support\\Facades\\DB;\n\n"
            ."final class {$class}\n{\n    public function handle(int \$n): int\n    {\n"
            ."        DB::table('x')->count();\n{$body}        return \$n + 1;\n    }\n}\n";
    }

    public function test_source_emits_non_safety_harness_intents_when_flags_on(): void
    {
        config([
            'atlas.loop.meta_harness_targets' => true,
            'atlas.loop.meta_harness_self_improve.enabled' => true,
        ]);
        $repo = $this->repoWithHarnessTree();

        $candidates = (new AtlasLoopMetaHarnessIntentSource(new AtlasLoopHarnessGuard()))->candidates($repo, 12);
        $paths = array_column($candidates, 'path');

        $this->assertContains(
            'app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopQueueRefiller.php',
            $paths,
            'o harness não-segurança é proposto',
        );
        $this->assertNotContains(
            'app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php',
            $paths,
            'o frozen judge (pétreo) NUNCA é proposto',
        );
        $this->assertNotContains('app/Support/PlainHelper.php', $paths, 'só harness, nada ordinário');
        foreach ($candidates as $c) {
            $this->assertSame('meta_harness_self_improve', $c['source']);
            $this->assertStringContainsString('harness', $c['objective']);
        }
    }

    public function test_petreo_set_is_never_emitted_even_with_flags_on(): void
    {
        config([
            'atlas.loop.meta_harness_targets' => true,
            'atlas.loop.meta_harness_self_improve.enabled' => true,
        ]);
        $repo = $this->repoWithHarnessTree();

        // Adiciona TODO o conjunto pétreo conhecido à árvore — nenhum pode ser emitido.
        foreach (AtlasLoopHarnessGuard::FORBIDDEN_SELF_TARGETS as $forbidden) {
            if (! str_starts_with($forbidden, 'app/')) {
                continue; // migrations etc. ficam fora do diretório de harness
            }
            File::ensureDirectoryExists(dirname($repo.'/'.$forbidden));
            File::put($repo.'/'.$forbidden, $this->harnessFixture(basename($forbidden, '.php')));
        }

        $candidates = (new AtlasLoopMetaHarnessIntentSource(new AtlasLoopHarnessGuard()))->candidates($repo, 50);
        $paths = array_column($candidates, 'path');

        foreach (AtlasLoopHarnessGuard::FORBIDDEN_SELF_TARGETS as $forbidden) {
            $this->assertNotContains($forbidden, $paths, "$forbidden é pétreo — jamais emitido");
        }
    }

    public function test_either_flag_off_yields_nothing(): void
    {
        $repo = $this->repoWithHarnessTree();
        $source = new AtlasLoopMetaHarnessIntentSource(new AtlasLoopHarnessGuard());

        config(['atlas.loop.meta_harness_targets' => false, 'atlas.loop.meta_harness_self_improve.enabled' => true]);
        $this->assertSame([], $source->candidates($repo, 12), 'flag pétrea OFF ⇒ vazio');

        config(['atlas.loop.meta_harness_targets' => true, 'atlas.loop.meta_harness_self_improve.enabled' => false]);
        $this->assertSame([], $source->candidates($repo, 12), 'flag da perna OFF ⇒ vazio');
    }

    public function test_discovery_surfaces_meta_harness_target_and_never_the_petreo_set(): void
    {
        config([
            'atlas.loop.meta_harness_targets' => true,
            'atlas.loop.meta_harness_self_improve.enabled' => true,
            'atlas.loop.discovery_backlog_intents' => true,
            'atlas.loop.discovery_framework_targets' => true,
            'atlas.loop.impact_ranking_enabled' => false,
            'atlas.loop.orphan_gate_enabled' => false,
            'atlas.loop.target_cooldown_enabled' => false,
        ]);
        $repo = $this->repoWithHarnessTree();

        $discovery = new AtlasLoopTargetDiscoveryService(
            app(AtlasLoopTargetRepository::class),
            null,
            new AtlasLoopBacklogIntentSource(null, new AtlasLoopMetaHarnessIntentSource(new AtlasLoopHarnessGuard())),
            new AtlasLoopHarnessGuard(),
        );
        $result = $discovery->discover($repo, $this->campaignId, [
            'roots' => ['app/Services', 'app/Support'],
            'limit' => 10,
        ]);

        $paths = array_column($result['top'], 'path');
        $this->assertContains(
            'app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopQueueRefiller.php',
            $paths,
            'a discovery entrega o alvo de meta-harness (loop-proposes-harness path vivo)',
        );
        $this->assertNotContains(
            'app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php',
            $paths,
            'o chokepoint do HarnessGuard mantém o pétreo fora — mesmo via backlog',
        );
    }

    public function test_meta_harness_tasks_land_in_the_meta_harness_arm_of_the_ab_measurer(): void
    {
        // Enfileira tasks REAIS como o loop-proposes-harness path produziria: um alvo de
        // harness não-segurança e um alvo ordinário. O medidor A/B deve separá-los por braço.
        $this->loopTask('app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopQueueRefiller.php', certified: true);
        $this->loopTask('app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopQueueRefiller.php', certified: true);
        $this->loopTask('app/Support/PlainHelper.php', certified: true);
        $this->loopTask('app/Support/PlainHelper.php', certified: false);

        $payload = (new AtlasLoopMetaHarnessAbLiftService(new AtlasLoopHarnessGuard()))->measure([
            'enabled' => true,
            'min_cases_per_arm' => 2,
            'min_lift' => 0.01,
        ]);

        $this->assertSame(2, data_get($payload, 'arms.meta_harness.case_count'), 'os 2 tasks de harness caem no braço meta');
        $this->assertSame(2, data_get($payload, 'arms.ordinary.case_count'), 'os 2 ordinários caem no braço ordinary');
        $this->assertSame(1.0, (float) data_get($payload, 'arms.meta_harness.certification_rate'));
        $this->assertSame(0.5, (float) data_get($payload, 'arms.ordinary.certification_rate'));
        $this->assertSame(0.5, (float) data_get($payload, 'lift.certification_rate_delta'));
        $this->assertTrue((bool) $payload['completion_claim_allowed']);
    }

    private function loopTask(string $path, bool $certified): void
    {
        $id = (string) Str::uuid();
        DB::table('atlas_loop_tasks')->insert([
            'id' => $id,
            'campaign_id' => $this->campaignId,
            'schema_version' => 'atlas.loop.task.v1',
            'status' => 'done',
            'source' => 'discovery',
            'self_contained' => false,
            'target_path' => $path,
            'objective' => 'meta-harness intent fixture',
            'payload' => json_encode(['allowed_files' => [$path]]),
            'priority' => 0,
            'attempts' => 1,
            'max_attempts' => 1,
            'dedupe_key' => hash('sha256', $path.microtime().random_bytes(4)),
            'result' => json_encode(['proposals_certified_for_review' => $certified ? 1 : 0]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        if (! $certified) {
            return;
        }
        DB::table('atlas_loop_proposals')->insert([
            'id' => (string) Str::uuid(),
            'campaign_id' => $this->campaignId,
            'task_id' => $id,
            'schema_version' => 'atlas.loop.proposal.v1',
            'status' => 'certified_for_review',
            'objective' => 'meta-harness proposal fixture',
            'provider' => 'fixture',
            'target_path' => $path,
            'diff_text' => 'diff --git a/'.$path.' b/'.$path."\n",
            'proposal_hash' => hash('sha256', 'proposal '.$path.random_bytes(4)),
            'metric' => json_encode(['ok' => true]),
            'acceptance_hash' => hash('sha256', 'accept '.$path.random_bytes(4)),
            'scenarios_explored' => 1,
            'scenarios_accepted' => 1,
            'winning_scenario' => 'fixture',
            'merged_to_main' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
