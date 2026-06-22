<?php

declare(strict_types=1);

namespace Tests\Feature\Loop\Discovery;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopTask;
use App\Services\Ai\AutonomousEvolution\AtlasEvolutionTaskGenerator;
use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopBackService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopComplexTargetDecomposer;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopFrameworkRefactorSynthesizer;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopQueueRefiller;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopRefactorObjectiveSynthesizer;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTargetDiscoveryService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTargetRepository;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopWorkShapeRouter;
use App\Services\Ai\AutonomousEvolution\LoopExecutionDriver;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopStore;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * §2 · DOC-GAP DOMINANCE — the brain originates a capability the canonical docs DEMAND but no symbol provides:
 * net-new feature work the proxy cyclomatic/coverage scan can never surface (it only sees code that EXISTS).
 * Armed ⇒ the queue holds a source='doc_gap' red→green feature directive the proxy lanes never produce; OFF ⇒
 * none (byte-identical). The 5th brain-dominant work type (dedup / orphan-wiring / bug-fix / decompose / doc-gap).
 */
final class AtlasLoopDocGapDominanceTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('atlas_loop_targets')) {
            foreach (['2026_06_02_000100_create_atlas_loop_runtime_tables.php', '2026_06_02_000200_complete_atlas_loop_runtime_schema.php'] as $file) {
                (require base_path('database/migrations/'.$file))->up();
            }
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->dirs as $d) {
            File::deleteDirectory($d);
        }
        parent::tearDown();
    }

    /** A repo whose canonical docs NAME a capability (AtlasLoopEgressFirewall) no symbol in scope provides. */
    private function repo(): string
    {
        $d = sys_get_temp_dir().'/atlas-docgap-dom-'.bin2hex(random_bytes(6));
        $this->dirs[] = $d;
        File::ensureDirectoryExists($d.'/app/X');
        File::ensureDirectoryExists($d.'/docs');
        File::put($d.'/app/X/Existing.php', "<?php\n\nnamespace App\\X;\n\nfinal class Existing { public function go(): int { return 1; } }\n");
        File::put($d.'/docs/spec.md', "# Spec\n\nThe loop MUST provide `AtlasLoopEgressFirewall` to protect sovereignty — no symbol provides it yet.\n");

        return $d;
    }

    private function campaign(string $repo): AtlasLoopCampaign
    {
        return AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => 'running',
            'goal' => 'docgap-dom',
            'base_workspace' => $repo,
            'provider' => '',
            'config' => ['decision_priority_enabled' => false],
        ]);
    }

    private function armScope(bool $docGapEnabled): void
    {
        config([
            'atlas.loop.doc_gap_supply_enabled' => $docGapEnabled,
            'atlas.loop.doc_gap_supply_docs_roots' => ['docs'],
            'atlas.loop.campaign.discovery_roots' => ['app/X'],
            // every proxy + other-brain lane OFF, so a source='doc_gap' task can ONLY come from the doc-gap brain.
            'atlas.loop.generic_provider_fallback_enabled' => false,
            'atlas.loop.dedup_supply_enabled' => false,
            'atlas.loop.orphan_wiring_supply_enabled' => false,
            'atlas.loop.decompose_supply_enabled' => false,
            'atlas.loop.refactor_objectives_enabled' => false,
            'atlas.loop.framework_refactor_enabled' => false,
        ]);
    }

    private function refiller(): AtlasLoopQueueRefiller
    {
        $this->app->bind(AtlasEvolutionTaskGenerator::class, fn () => new AtlasEvolutionTaskGenerator(new class implements LoopExecutionDriver
        {
            public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
            {
                return ['status' => 'noop'];
            }
        }));

        return new AtlasLoopQueueRefiller(
            app(AtlasLoopTargetDiscoveryService::class),
            app(AtlasLoopTargetRepository::class),
            app(AtlasEvolutionTaskGenerator::class),
            app(AtlasLoopBackService::class),
            app(AtlasLoopStore::class),
            new AtlasLoopRefactorObjectiveSynthesizer,
            new AtlasLoopHarnessGuard,
            new AtlasLoopFrameworkRefactorSynthesizer,
            null,
            new AtlasLoopWorkShapeRouter,
            null, null, null, null, null, null, null, null, null, null,
            new AtlasLoopComplexTargetDecomposer,
        );
    }

    private function docGapTasks(string $campaignId)
    {
        return AtlasLoopTask::query()->where('campaign_id', $campaignId)->where('source', 'doc_gap')->get();
    }

    public function test_armed_brain_mints_a_red_gated_doc_gap_feature_the_proxy_cannot(): void
    {
        $repo = $this->repo();
        $this->armScope(docGapEnabled: true);
        $campaign = $this->campaign($repo);

        $this->refiller()->refill($campaign, 4);

        $tasks = $this->docGapTasks($campaign->id);
        $this->assertCount(1, $tasks, 'the brain mints exactly one doc-gap capability task');

        $task = $tasks->first();
        $payload = is_array($task->payload) ? $task->payload : (array) json_decode((string) $task->payload, true);
        $this->assertSame('feature', $payload['objective_kind'] ?? null);
        $this->assertTrue($payload['red_required'] ?? false, 'the new capability test must be RED first (Guard 4)');
        $this->assertTrue($payload['comprehension_originated'] ?? false, 'provenance: the brain, not the proxy scan');
        $this->assertSame('AtlasLoopEgressFirewall', $payload['capability'] ?? null);
        $this->assertStringContainsString('AtlasLoopEgressFirewall', (string) $task->target_path, 'anchored on the expected capability path');
    }

    public function test_flag_off_mints_no_doc_gap_task_byte_identical(): void
    {
        $repo = $this->repo();
        $this->armScope(docGapEnabled: false);
        $campaign = $this->campaign($repo);

        $this->refiller()->refill($campaign, 4);

        $this->assertCount(0, $this->docGapTasks($campaign->id), 'flag OFF => the brain mints nothing (byte-identical)');
    }
}
