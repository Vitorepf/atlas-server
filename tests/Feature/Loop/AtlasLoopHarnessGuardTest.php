<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopBacklogIntentSource;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTargetDiscoveryService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTargetRepository;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * L3-12: meta-loop COM freio. O Loop pode afiar o próprio harness — mas o conjunto de
 * SEGURANÇA (frozen judge, gates, never-merge, este guard) é PÉTREO: jamais um alvo,
 * independentemente de flag, backlog ou score. É o invariante anti-runaway que impede o
 * loop de editar a própria fechadura. Arquivos de harness não-segurança só entram com a
 * flag meta ON.
 */
final class AtlasLoopHarnessGuardTest extends TestCase
{
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
        parent::tearDown();
    }

    public function test_safety_files_are_forbidden_self_targets_always(): void
    {
        $guard = new AtlasLoopHarnessGuard();

        foreach (AtlasLoopHarnessGuard::FORBIDDEN_SELF_TARGETS as $forbidden) {
            $this->assertTrue($guard->isForbiddenSelfTarget($forbidden), "$forbidden é pétreo-proibido");
            // Proibido em AMBOS os estados de flag — o loop nunca edita a própria fechadura.
            $this->assertSame('forbidden', $guard->admit($forbidden, false));
            $this->assertSame('forbidden', $guard->admit($forbidden, true));
        }
    }

    public function test_forbidden_set_covers_the_critical_safety_spine(): void
    {
        // Ratchet: o conjunto proibido NÃO pode encolher abaixo da espinha de segurança.
        $set = implode('|', AtlasLoopHarnessGuard::FORBIDDEN_SELF_TARGETS);
        foreach ([
            'AtlasEvolutionFrozenJudge',
            'AtlasLoopProposalPromotionGate',
            'AtlasLoopAutoMergeService',
            'AtlasLoopNetDirectionGuard',
            'AtlasLoopHarnessGuard',
            'AtlasLoopUtilityGradeService',       // o termômetro honesto (pétreo desde 14/06)
            'AtlasLoopWiredCallerService',         // a fonte de verdade que a régua re-resolve
            'complete_atlas_loop_runtime_schema', // never-merge CHECK+trigger
            'governed_merge_door',                // a porta governada
            'AtlasLoopNextWorkDecider',            // o priorizador ungameable
            'AtlasLoopIdeaTreeAccessor',           // ARBOR-GRAFT: substrato de candidatos/árvore
            'AtlasLoopSelectAdjuster',             // ARBOR-GRAFT: termo de SELECT determinístico
        ] as $critical) {
            $this->assertStringContainsString($critical, $set, "$critical deve estar no conjunto proibido");
        }
    }

    public function test_harness_target_gated_by_flag_non_safety_file(): void
    {
        $guard = new AtlasLoopHarnessGuard();
        $harnessNonSafety = 'app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopQueueRefiller.php';

        $this->assertFalse($guard->isForbiddenSelfTarget($harnessNonSafety), 'não é segurança');
        $this->assertTrue($guard->isHarnessTarget($harnessNonSafety), 'é harness');
        $this->assertSame('harness_gated', $guard->admit($harnessNonSafety, false), 'flag OFF rejeita');
        $this->assertSame('admissible', $guard->admit($harnessNonSafety, true), 'flag ON admite');
    }

    public function test_ordinary_app_file_is_always_admissible(): void
    {
        $guard = new AtlasLoopHarnessGuard();
        $ordinary = 'app/Support/TerminalMarkdownRenderer.php';

        $this->assertSame('admissible', $guard->admit($ordinary, false));
        $this->assertSame('admissible', $guard->admit($ordinary, true));
    }

    public function test_discovery_never_surfaces_a_forbidden_file_even_via_backlog(): void
    {
        // Mesmo se um arquivo de SEGURANÇA for injetado no backlog com prioridade máxima,
        // a discovery NUNCA o entrega como alvo (o guard é o chokepoint final).
        $repo = sys_get_temp_dir().'/atlas-hg-'.bin2hex(random_bytes(4));
        $this->dirs[] = $repo;
        $forbidden = 'app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php';
        File::ensureDirectoryExists(dirname($repo.'/'.$forbidden));
        File::put($repo.'/'.$forbidden, "<?php\nclass AtlasEvolutionFrozenJudge {}\n");

        File::ensureDirectoryExists(storage_path('app/atlas/loop'));
        File::put(storage_path('app/atlas/loop/backlog-intents.json'), json_encode(['items' => [
            ['path' => $forbidden, 'objective' => 'tentar enfraquecer o juiz', 'priority' => 1.0],
        ]]));
        config(['atlas.loop.discovery_backlog_intents' => true, 'atlas.loop.meta_harness_targets' => true]);

        $discovery = new AtlasLoopTargetDiscoveryService(
            app(AtlasLoopTargetRepository::class),
            null,
            new AtlasLoopBacklogIntentSource(),
            new AtlasLoopHarnessGuard(),
        );
        $result = $discovery->discover($repo, 'camp-hg', ['roots' => ['app/Services'], 'limit' => 10]);

        $paths = array_column($result['top'], 'path');
        $this->assertNotContains($forbidden, $paths, 'o frozen judge NUNCA é alvo, nem via backlog priorizado');
    }
}
