<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopTarget;
use App\Models\AtlasLoopTask;
use App\Services\Ai\AutonomousEvolution\AtlasEvolutionTaskGenerator;
use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\AtlasLoopRealWorkScorecardService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopBackService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopQueueRefiller;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTargetDiscoveryService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTargetRepository;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopWorkShapeRouter;
use App\Services\Ai\AutonomousEvolution\LoopExecutionDriver;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopStore;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The live-soak starvation fix: discovery already stamped coverage deficits as
 * shape=characterization_test, but the refiller had no executor for that shape. This pins the
 * missing candidate->task conversion so a discovered self-loop coverage deficit becomes real,
 * scorecard-visible work instead of falling through to the generic generator and ending tasks=0.
 */
final class AtlasLoopCoverageDeficitRefillTest extends TestCase
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
                '2026_06_11_000100_add_quality_columns_to_atlas_loop_tables.php',
            ] as $file) {
                (require base_path('database/migrations/'.$file))->up();
            }
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            File::deleteDirectory($dir);
        }
        parent::tearDown();
    }

    private function repo(): string
    {
        $dir = sys_get_temp_dir().'/atlas-covdef-refill-'.bin2hex(random_bytes(5));
        $this->dirs[] = $dir;
        File::ensureDirectoryExists($dir.'/app/Services/Ai/AutonomousEvolution/Discovery');
        File::put($dir.'/app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopBacklogAutoFeederService.php', $this->source());

        return $dir;
    }

    private function repoWithSelfImprovementTarget(): string
    {
        $dir = sys_get_temp_dir().'/atlas-self-improve-refill-'.bin2hex(random_bytes(5));
        $this->dirs[] = $dir;
        File::ensureDirectoryExists($dir.'/app/Services/Ai/AutonomousEvolution/Discovery');
        File::ensureDirectoryExists($dir.'/tests/Unit/Ai/AutonomousEvolution/Discovery');
        File::put($dir.'/app/Services/Ai/AutonomousEvolution/Discovery/FooLoopHarness.php', <<<'PHP'
<?php

namespace App\Services\Ai\AutonomousEvolution\Discovery;

final class FooLoopHarness
{
    public function route(int $value): int
    {
        if ($value > 10) {
            return $value + 1;
        }
        if ($value > 5) {
            return $value + 2;
        }
        if ($value === 0) {
            return 0;
        }
        foreach (range(1, max(1, $value)) as $i) {
            if ($i % 2 === 0) {
                $value++;
            }
        }

        return $value;
    }
}
PHP);
        File::put($dir.'/tests/Unit/Ai/AutonomousEvolution/Discovery/FooLoopHarnessTest.php', <<<'PHP'
<?php

namespace Tests\Unit\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AutonomousEvolution\Discovery\FooLoopHarness;
use PHPUnit\Framework\TestCase;

final class FooLoopHarnessTest extends TestCase
{
    public function test_route_keeps_current_observable_behaviour(): void
    {
        $this->assertSame(12, (new FooLoopHarness())->route(11));
    }
}
PHP);

        return $dir;
    }

    private function source(): string
    {
        return <<<'PHP'
<?php

namespace App\Services\Ai\AutonomousEvolution\Discovery;

final class AtlasLoopBacklogAutoFeederService
{
    public function decide(int $a, int $b): bool
    {
        if ($a === $b) {
            return true;
        }
        if ($a >= 0 && $b <= 10) {
            return false;
        }
        if ($a > 0) {
            return true;
        }

        return false;
    }
}
PHP;
    }

    private function refiller(?LoopExecutionDriver $driver = null): AtlasLoopQueueRefiller
    {
        $driver ??= new class implements LoopExecutionDriver
        {
            public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
            {
                return ['status' => 'noop'];
            }
        };
        $this->app->bind(AtlasEvolutionTaskGenerator::class, fn () => new AtlasEvolutionTaskGenerator($driver));

        return new AtlasLoopQueueRefiller(
            app(AtlasLoopTargetDiscoveryService::class),
            app(AtlasLoopTargetRepository::class),
            app(AtlasEvolutionTaskGenerator::class),
            app(AtlasLoopBackService::class),
            app(AtlasLoopStore::class),
            null,
            new AtlasLoopHarnessGuard,
            null,
            null,
            new AtlasLoopWorkShapeRouter,
        );
    }

    private function campaign(string $repo): AtlasLoopCampaign
    {
        return AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => 'running',
            'goal' => 'coverage deficit refill',
            'base_workspace' => $repo,
            'provider' => '',
            'config' => ['decision_priority_enabled' => false],
        ]);
    }

    private function target(AtlasLoopCampaign $campaign): AtlasLoopTarget
    {
        return app(AtlasLoopTargetRepository::class)->upsert(
            (string) $campaign->id,
            'app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopBacklogAutoFeederService.php',
            hash('sha256', $this->source()),
            [
                'score' => 0.95,
                'self_contained' => 0.45,
                'improvement' => 1.0,
                'novelty' => 1.0,
                'signals' => [
                    'shape' => 'characterization_test',
                    'coverage_objective' => 'ADD a characterization test for AtlasLoopBacklogAutoFeederService',
                    'coverage_deficit' => 1.0,
                    'coverage_deficit_mutants' => 8,
                    'has_sibling_test' => false,
                    'framework_reach' => 1,
                ],
            ],
            ['origin' => 'discovery'],
        );
    }

    private function selfImprovementTarget(AtlasLoopCampaign $campaign): AtlasLoopTarget
    {
        $rel = 'app/Services/Ai/AutonomousEvolution/Discovery/FooLoopHarness.php';

        return app(AtlasLoopTargetRepository::class)->upsert(
            (string) $campaign->id,
            $rel,
            hash('sha256', 'foo-loop-harness'),
            [
                'score' => 0.95,
                'self_contained' => 0.45,
                'improvement' => 1.0,
                'novelty' => 1.0,
                'signals' => [
                    'backlog_reach' => 1.0,
                    'backlog_source' => 'meta_harness_self_improve',
                    'backlog_objective' => 'Melhorar o próprio harness do Loop em FooLoopHarness.php',
                    'is_self_improvement' => true,
                    'quality_bar' => 9.0,
                    'framework_reach' => 1,
                    'has_sibling_test' => true,
                    'sibling_test_path' => 'tests/Unit/Ai/AutonomousEvolution/Discovery/FooLoopHarnessTest.php',
                    'cyclomatic' => 8,
                ],
            ],
            ['origin' => 'discovery'],
        );
    }

    private function invokeEnqueue(AtlasLoopCampaign $campaign, AtlasLoopTarget $target): string
    {
        $refiller = $this->refiller();
        $ref = new \ReflectionMethod($refiller, 'generateAndEnqueue');
        $ref->setAccessible(true);

        return (string) $ref->invoke($refiller, $campaign, $target, '');
    }

    private function invokeEnqueueWithRefiller(AtlasLoopQueueRefiller $refiller, AtlasLoopCampaign $campaign, AtlasLoopTarget $target): string
    {
        $ref = new \ReflectionMethod($refiller, 'generateAndEnqueue');
        $ref->setAccessible(true);

        return (string) $ref->invoke($refiller, $campaign, $target, '');
    }

    public function test_coverage_deficit_target_enqueues_real_characterization_work(): void
    {
        config(['atlas.loop.characterization_test_lane_enabled' => true]);
        $campaign = $this->campaign($this->repo());
        $target = $this->target($campaign);

        $outcome = $this->invokeEnqueue($campaign, $target);

        $this->assertSame('enqueued', $outcome);
        $task = AtlasLoopTask::query()->where('campaign_id', $campaign->id)->first();
        $this->assertNotNull($task);
        $this->assertSame('coverage_gap_characterization', $task->source);
        $payload = is_array($task->payload) ? $task->payload : (array) json_decode((string) $task->payload, true);

        $this->assertSame('characterization_test', $payload['objective_kind'] ?? null);
        $this->assertSame('create_new_sibling', $payload['characterization_mode'] ?? null);
        $this->assertSame('return_true', $payload['characterization_operator'] ?? null);
        $this->assertStringContainsString('final class AtlasLoopBacklogAutoFeederService', (string) ($payload['target_content'] ?? ''));
        $this->assertSame(
            'tests/Unit/Ai/AutonomousEvolution/Discovery/AtlasLoopBacklogAutoFeederServiceTest.php',
            $payload['characterization_sibling_test'] ?? null,
        );
        $this->assertSame(AtlasLoopTarget::STATUS_QUEUED, $target->fresh()->status);

        $scorecard = app(AtlasLoopRealWorkScorecardService::class)->scorecard((string) $campaign->id);
        $this->assertSame(1, $scorecard['tasks_total']);
        $this->assertSame(1, $scorecard['real_work_tasks']);
        $this->assertSame(1, $scorecard['verification_tasks']);
        $this->assertTrue($scorecard['claim_policy']['loop_real_work_claim_allowed']);
    }

    public function test_first_proof_mode_promotes_characterization_priority_above_refactor_band(): void
    {
        config([
            'atlas.loop.characterization_test_lane_enabled' => true,
            'atlas.loop.characterization_first_proof_priority_enabled' => true,
            'atlas.loop.characterization_first_proof_priority' => 5200,
        ]);
        $campaign = $this->campaign($this->repo());
        $target = $this->target($campaign);

        $outcome = $this->invokeEnqueue($campaign, $target);

        $this->assertSame('enqueued', $outcome);
        $task = AtlasLoopTask::query()->where('campaign_id', $campaign->id)->first();
        $this->assertNotNull($task);
        $this->assertSame(5200, (int) $task->priority);
    }

    public function test_real_work_supply_profile_keeps_coverage_lane_while_proxy_refactor_is_disabled(): void
    {
        config([
            'atlas.loop.proxy_refactor_supply_enabled' => false,
            'atlas.loop.characterization_test_lane_enabled' => true,
            'atlas.loop.framework_refactor_enabled' => true,
            'atlas.loop.refactor_objectives_enabled' => true,
            'atlas.loop.framework_edge_gap_fallback_enabled' => false,
            'atlas.loop.generic_provider_fallback_enabled' => false,
        ]);
        $campaign = $this->campaign($this->repo());
        $target = $this->target($campaign);

        $outcome = $this->invokeEnqueue($campaign, $target);

        $this->assertSame('enqueued', $outcome);
        $task = AtlasLoopTask::query()->where('campaign_id', $campaign->id)->first();
        $this->assertNotNull($task);
        $payload = is_array($task->payload) ? $task->payload : (array) json_decode((string) $task->payload, true);
        $this->assertSame('characterization_test', $payload['objective_kind'] ?? null);

        $scorecard = app(AtlasLoopRealWorkScorecardService::class)->scorecard((string) $campaign->id);
        $this->assertSame(1, $scorecard['real_work_tasks']);
        $this->assertSame(0, $scorecard['proxy_refactor_tasks']);
        $this->assertTrue($scorecard['claim_policy']['loop_real_work_claim_allowed']);
    }

    public function test_meta_harness_self_improvement_is_grounded_before_generic_fallback_quarantine(): void
    {
        config([
            'atlas.loop.meta_harness_targets' => true,
            'atlas.loop.meta_harness_self_improve.enabled' => true,
            'atlas.loop.self_improve_grounding_enabled' => true,
            'atlas.loop.generic_provider_fallback_enabled' => false,
            'atlas.loop.proxy_refactor_supply_enabled' => false,
            'atlas.loop.quality_bar' => 9.0,
        ]);
        $campaign = $this->campaign($this->repoWithSelfImprovementTarget());
        $target = $this->selfImprovementTarget($campaign);

        $outcome = $this->invokeEnqueue($campaign, $target);

        $this->assertSame('enqueued', $outcome);
        $this->assertSame('self_improvement_task_synthesized', $target->fresh()->reason);

        $task = AtlasLoopTask::query()->where('campaign_id', $campaign->id)->first();
        $this->assertNotNull($task);
        $this->assertSame('self_improvement', $task->source);
        $payload = is_array($task->payload) ? $task->payload : (array) json_decode((string) $task->payload, true);
        $this->assertSame('refactor_extract_class', $payload['objective_kind']);
        $this->assertTrue((bool) $payload['is_self_improvement']);
        $this->assertStringContainsString('final class FooLoopHarness', (string) ($payload['target_content'] ?? ''));
        $this->assertTrue((bool) $payload['acceptance']['complexity_proof']);
        $this->assertTrue((bool) $payload['acceptance']['quality_bar_gate']);
        $this->assertSame(9.0, (float) $payload['acceptance']['quality_bar']);
        $this->assertContains('app/Services/Ai/AutonomousEvolution/Discovery/FooLoopHarnessSupport.php', $payload['allowed_files']);

        $scorecard = app(AtlasLoopRealWorkScorecardService::class)->scorecard((string) $campaign->id);
        $this->assertSame(1, $scorecard['real_work_tasks']);
        $this->assertSame(1, $scorecard['self_improvement_tasks']);
        $this->assertSame(0, $scorecard['proxy_refactor_tasks']);
    }

    public function test_meta_harness_self_improvement_wins_when_coverage_deficit_also_matches(): void
    {
        config([
            'atlas.loop.meta_harness_targets' => true,
            'atlas.loop.meta_harness_self_improve.enabled' => true,
            'atlas.loop.self_improve_grounding_enabled' => true,
            'atlas.loop.characterization_test_lane_enabled' => true,
            'atlas.loop.generic_provider_fallback_enabled' => false,
            'atlas.loop.proxy_refactor_supply_enabled' => false,
            'atlas.loop.quality_bar' => 9.0,
        ]);
        $campaign = $this->campaign($this->repoWithSelfImprovementTarget());
        $target = $this->selfImprovementTarget($campaign);
        $signals = is_array($target->signals) ? $target->signals : [];
        $signals['shape'] = 'characterization_test';
        $signals['coverage_objective'] = 'ADD a characterization test for FooLoopHarness';
        $signals['coverage_deficit'] = 1.0;
        $signals['coverage_deficit_mutants'] = 8;
        $target->forceFill(['signals' => $signals])->save();

        $outcome = $this->invokeEnqueue($campaign, $target);

        $this->assertSame('enqueued', $outcome);
        $this->assertSame('self_improvement_task_synthesized', $target->fresh()->reason);
        $task = AtlasLoopTask::query()->where('campaign_id', $campaign->id)->first();
        $this->assertNotNull($task);
        $this->assertSame('self_improvement', $task->source);
        $payload = is_array($task->payload) ? $task->payload : (array) json_decode((string) $task->payload, true);
        $this->assertTrue((bool) ($payload['is_self_improvement'] ?? false));
        $this->assertNotSame('characterization_test', $payload['objective_kind'] ?? null);
    }

    public function test_generic_provider_fallback_can_be_disabled_for_soak_supply(): void
    {
        config([
            'atlas.loop.generic_provider_fallback_enabled' => false,
            'atlas.loop.characterization_test_lane_enabled' => false,
            'atlas.loop.framework_refactor_enabled' => false,
            'atlas.loop.refactor_objectives_enabled' => false,
        ]);
        $campaign = $this->campaign($this->repo());
        $target = app(AtlasLoopTargetRepository::class)->upsert(
            (string) $campaign->id,
            'app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopBacklogAutoFeederService.php',
            hash('sha256', $this->source()),
            [
                'score' => 0.95,
                'self_contained' => 0.45,
                'improvement' => 1.0,
                'novelty' => 1.0,
                'signals' => [
                    'has_sibling_test' => false,
                    'framework_reach' => 0,
                    'impact_real_callers' => 1,
                    'cyclomatic' => 3,
                ],
            ],
            ['origin' => 'discovery'],
        );

        $refiller = $this->refiller(new class implements LoopExecutionDriver
        {
            public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
            {
                throw new \RuntimeException('provider fallback must not run during soak supply');
            }
        });

        $outcome = $this->invokeEnqueueWithRefiller($refiller, $campaign, $target);

        $this->assertSame('quarantined', $outcome);
        $this->assertSame(0, AtlasLoopTask::query()->where('campaign_id', $campaign->id)->count());
        $this->assertSame(AtlasLoopTarget::STATUS_QUARANTINED, $target->fresh()->status);
        $this->assertSame('generic_provider_fallback_disabled', $target->fresh()->reason);
    }

    public function test_policy_blocked_coverage_target_reopens_when_structured_lane_is_available(): void
    {
        config([
            'atlas.loop.generic_provider_fallback_enabled' => false,
            'atlas.loop.characterization_test_lane_enabled' => true,
        ]);
        $campaign = $this->campaign($this->repo());
        $target = $this->target($campaign);
        app(AtlasLoopTargetRepository::class)->quarantine($target->id, 'generic_provider_fallback_disabled');

        $reopened = app(AtlasLoopTargetRepository::class)->reopenPolicyBlockedForStructuredSupply((string) $campaign->id, 4);

        $this->assertSame(1, $reopened);
        $target->refresh();
        $this->assertSame(AtlasLoopTarget::STATUS_CANDIDATE, $target->status);
        $this->assertSame('policy_unblocked_reopened', $target->reason);
        $this->assertNull($target->claimed_by);
        $this->assertNull($target->lease_expires_at);
    }

    public function test_policy_blocked_refactor_target_reopens_only_when_refactor_supply_is_armed(): void
    {
        config([
            'atlas.loop.generic_provider_fallback_enabled' => false,
            'atlas.loop.characterization_test_lane_enabled' => false,
            'atlas.loop.framework_refactor_enabled' => true,
            'atlas.loop.refactor_objectives_enabled' => true,
            'atlas.loop.proxy_refactor_supply_enabled' => false,
        ]);
        $campaign = $this->campaign($this->repo());
        $target = app(AtlasLoopTargetRepository::class)->upsert(
            (string) $campaign->id,
            'app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopBacklogAutoFeederService.php',
            hash('sha256', $this->source()),
            [
                'score' => 0.95,
                'self_contained' => 0.45,
                'improvement' => 1.0,
                'novelty' => 1.0,
                'signals' => [
                    'framework_reach' => 1,
                    'heavy_refactor_candidate' => true,
                    'has_sibling_test' => true,
                    'sibling_test_path' => 'tests/Unit/Ai/AutonomousEvolution/Discovery/AtlasLoopBacklogAutoFeederServiceTest.php',
                    'cyclomatic' => 12,
                ],
            ],
            ['origin' => 'discovery'],
        );
        app(AtlasLoopTargetRepository::class)->quarantine($target->id, 'generic_provider_fallback_disabled');

        $this->assertSame(0, app(AtlasLoopTargetRepository::class)->reopenPolicyBlockedForStructuredSupply((string) $campaign->id, 4));
        $this->assertSame(AtlasLoopTarget::STATUS_QUARANTINED, $target->fresh()->status);

        config(['atlas.loop.proxy_refactor_supply_enabled' => true]);

        $this->assertSame(1, app(AtlasLoopTargetRepository::class)->reopenPolicyBlockedForStructuredSupply((string) $campaign->id, 4));
        $target->refresh();
        $this->assertSame(AtlasLoopTarget::STATUS_CANDIDATE, $target->status);
        $this->assertSame('policy_unblocked_reopened', $target->reason);
    }

    public function test_policy_blocked_refactor_target_below_complexity_floor_stays_quarantined(): void
    {
        config([
            'atlas.loop.generic_provider_fallback_enabled' => false,
            'atlas.loop.characterization_test_lane_enabled' => false,
            'atlas.loop.framework_refactor_enabled' => true,
            'atlas.loop.framework_refactor_min_cyclomatic' => 10,
            'atlas.loop.refactor_objectives_enabled' => true,
            'atlas.loop.proxy_refactor_supply_enabled' => true,
        ]);
        $campaign = $this->campaign($this->repo());
        $target = app(AtlasLoopTargetRepository::class)->upsert(
            (string) $campaign->id,
            'app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopBacklogAutoFeederService.php',
            hash('sha256', $this->source()),
            [
                'score' => 0.95,
                'self_contained' => 0.45,
                'improvement' => 1.0,
                'novelty' => 1.0,
                'signals' => [
                    'framework_reach' => 1,
                    'has_sibling_test' => true,
                    'sibling_test_path' => 'tests/Unit/Ai/AutonomousEvolution/Discovery/AtlasLoopBacklogAutoFeederServiceTest.php',
                    'cyclomatic' => 8,
                ],
            ],
            ['origin' => 'discovery'],
        );
        app(AtlasLoopTargetRepository::class)->quarantine($target->id, 'generic_provider_fallback_disabled');

        $this->assertSame(0, app(AtlasLoopTargetRepository::class)->reopenPolicyBlockedForStructuredSupply((string) $campaign->id, 4));
        $target->refresh();
        $this->assertSame(AtlasLoopTarget::STATUS_QUARANTINED, $target->status);
        $this->assertSame('generic_provider_fallback_disabled', $target->reason);
    }
}
