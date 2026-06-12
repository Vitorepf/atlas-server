<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AtlasDecide;

use App\Models\AiJob;
use App\Services\Ai\AiContextPackBuilder;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiProviderHealthCheck;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\AiProviderResult;
use App\Services\Ai\ValueObjects\AiContextPack;
use App\Services\Ai\AtlasDecide\AtlasDecideMetaLearningService;
use App\Services\Ai\AtlasDecide\AtlasEngineeringRunConductorService;
use App\Services\Ai\AtlasDecide\AtlasSwarmConductorService;
use App\Services\Ai\AtlasDecide\AtlasSwarmExecutorService;
use App\Services\Ai\AtlasDecide\AtlasConductorRoutingMemory;
use App\Services\Ai\AtlasDecide\AtlasSwarmProductionResolverService;
use App\Services\Ai\Compounding\AtlasCompoundingMemoryService;
use App\Services\Ai\Compounding\AtlasCompoundingRuntimeService;
use App\Services\Ai\Governance\AtlasAutonomyAdmissionService;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use App\Services\Ai\Programming\Sdd\Compilers\SpecCritic;
use App\Services\Ai\RealExecution\AtlasLiveCodeDeliveryService;
use App\Services\Ai\VerifiedExecution\AtlasVerifiedExecutionRuntimeService;
use Mockery;
use Tests\TestCase;

/**
 * Deterministic ADML stub so the conductor exercises real dispatch paths
 * without depending on ledger state. Mirrors StubAdmlForSwarm.
 */
final class StubAdmlForConduct extends AtlasDecideMetaLearningService
{
    public function __construct(private string $signal = 'ok', private string $provider = 'claude_cli')
    {
        // intentionally do not call parent::__construct — recommend() is overridden.
    }

    public function recommend(array $scope): array
    {
        if ($this->signal === 'insufficient_evidence') {
            return [
                'schema_version' => self::RECOMMENDATION_SCHEMA,
                'scope' => $scope,
                'signal' => 'insufficient_evidence',
                'recommended_provider' => null,
                'recommended_model' => null,
                'runner_up_provider' => null,
                'runner_up_model' => null,
            ];
        }

        return [
            'schema_version' => self::RECOMMENDATION_SCHEMA,
            'scope' => $scope,
            'signal' => 'ok',
            'recommended_provider' => $this->provider,
            'recommended_model' => 'model-x',
            'runner_up_provider' => null,
            'runner_up_model' => null,
            'actionable' => true,
        ];
    }
}

class AtlasEngineeringRunConductorServiceTest extends TestCase
{
    /** @var list<string> */
    private array $tmp = [];

    protected function tearDown(): void
    {
        foreach ($this->tmp as $p) {
            @unlink($p);
        }
        Mockery::close();
        parent::tearDown();
    }

    private function tmpPath(string $tag): string
    {
        $p = sys_get_temp_dir().'/atlas_conduct_'.$tag.'_'.uniqid('', true).'.jsonl';
        $this->tmp[] = $p;

        return $p;
    }

    private function swarmConductor(string $signal = 'ok', string $provider = 'claude_cli'): AtlasSwarmConductorService
    {
        $kernel = new AtlasConstitutionalKernelService;
        $kernel->setViolationsLogPathForTesting($this->tmpPath('kernel'));

        $admission = new AtlasAutonomyAdmissionService($kernel);
        $admission->setTicketsLogPathForTesting($this->tmpPath('admission'));

        $svc = new AtlasSwarmConductorService(new StubAdmlForConduct($signal, $provider), $kernel, $admission);
        $svc->setDispatchesLogPathForTesting($this->tmpPath('dispatch'));
        $svc->setOutcomesLogPathForTesting($this->tmpPath('outcomes'));

        return $svc;
    }

    private function executor(): AtlasSwarmExecutorService
    {
        $svc = app(AtlasSwarmExecutorService::class);
        $svc->setLogPathForTesting($this->tmpPath('executor'));
        $svc->setResolver(null);

        return $svc;
    }

    private function fakeProvider(callable $runHandler): AiProvider
    {
        return new class($runHandler) implements AiProvider
        {
            public function __construct(private $runHandler) {}

            public function key(): string
            {
                return 'fake';
            }

            public function run(AiJob $job, string $prompt): AiProviderResult
            {
                return ($this->runHandler)($job, $prompt);
            }

            public function runStreaming(AiJob $job, string $prompt, ?callable $onEvent = null): AiProviderResult
            {
                return $this->run($job, $prompt);
            }

            public function health(): AiProviderHealthCheck
            {
                return new AiProviderHealthCheck(true, 'ok', null);
            }
        };
    }

    private function fakeManager(AiProvider $provider): AiProviderManager
    {
        $mgr = Mockery::mock(AiProviderManager::class);
        $mgr->shouldReceive('get')->andReturn($provider);

        return $mgr;
    }

    /**
     * A provider that increments $counter->n on every run() — lets a test prove
     * directly that NO provider was invoked (count 0) on shadow/downgrade paths.
     */
    private function spyProvider(object $counter): AiProvider
    {
        return $this->fakeProvider(function () use ($counter): AiProviderResult {
            $counter->n++;

            return new AiProviderResult(true, 'real provider output', [], 0, 42, 'real provider output', '');
        });
    }

    private function noProviderManager(): AiProviderManager
    {
        $mgr = Mockery::mock(AiProviderManager::class);
        $mgr->shouldReceive('get')->andThrow(new \RuntimeException('provider get must NOT be called in shadow/downgraded paths'));

        return $mgr;
    }

    private function conductor(
        AtlasSwarmConductorService $sc,
        AtlasSwarmProductionResolverService $pr,
        ?AtlasVerifiedExecutionRuntimeService $ve = null,
        ?AtlasCompoundingMemoryService $mem = null,
        ?SpecCritic $critic = null,
    ): AtlasEngineeringRunConductorService {
        return new AtlasEngineeringRunConductorService($sc, $this->executor(), $pr, $ve, $mem, $critic);
    }

    /**
     * @return array<string,mixed>
     */
    private function cleanSpec(): array
    {
        return [
            'objective' => 'Implement a token bucket rate limiter for the API gateway',
            'context' => 'The gateway currently has no rate limiting and is exposed to request bursts',
            'expected_behavior' => 'Requests over the configured rate receive HTTP 429 with a Retry-After header',
            'rollback' => 'Feature-flag the limiter; disabling the flag restores prior behaviour immediately',
            'likely_files' => ['app/Http/Middleware/RateLimiter.php'],
            'risks' => ['false positives under legitimate burst traffic'],
            'tests' => ['RateLimiterTest::test_blocks_over_limit'],
            'evidence_required' => ['phpunit suite green'],
            'completion_criteria' => ['RateLimiterTest passes (phpunit) and the quality gate is green'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function work(array $o = []): array
    {
        return array_merge([
            'task_category' => 'code_generation',
            'role' => 'primary',
            'framework' => null,
            'parallelism' => 1,
            'scope' => ['privacy_class' => 'public'],
            'privacy_class' => 'public',
            'requested_autonomy' => 'execute_with_approval',
            'input' => 'do the thing',
        ], $o);
    }

    public function test_shadow_executes_plan_and_returns_governed_envelope_without_spend(): void
    {
        $calls = (object) ['n' => 0];
        $pr = new AtlasSwarmProductionResolverService($this->fakeManager($this->spyProvider($calls)));
        $conductor = $this->conductor($this->swarmConductor('ok', 'claude_cli'), $pr);

        $env = $conductor->run($this->work(), ['mode' => 'shadow']);

        $this->assertSame(0, $calls->n, 'SHADOW must invoke zero providers (no token spend)');
        $this->assertSame(AtlasEngineeringRunConductorService::STATUS_EXECUTED, $env['status']);
        $this->assertSame(AtlasEngineeringRunConductorService::MODE_SHADOW, $env['mode']);
        $this->assertSame(AtlasEngineeringRunConductorService::MODE_SHADOW, $env['requested_mode']);
        $this->assertNull($env['mode_downgrade_reason']);
        $this->assertIsArray($env['winner']);
        $this->assertSame('success', $env['winner']['result']);
        $this->assertSame('claude_cli', $env['winner']['provider']);
        $this->assertGreaterThanOrEqual(1, $env['effective_parallelism']);
        $this->assertStringStartsWith('sha256:', $env['run_hash']);
        $this->assertIsArray($env['compounding_candidate']);
        $this->assertFalse($env['claim_policy']['rivals_claim_allowed']);
        $this->assertFalse($env['claim_policy']['benchmark_claim_allowed']);
    }

    public function test_authored_plan_dag_runs_nodes_in_dependency_order_shadow(): void
    {
        $calls = (object) ['n' => 0];
        $pr = new AtlasSwarmProductionResolverService($this->fakeManager($this->spyProvider($calls)));
        $conductor = $this->conductor($this->swarmConductor('ok', 'claude_cli'), $pr);

        $plan = ['nodes' => [
            ['node_id' => 'reason', 'task_category' => 'reasoning', 'role' => 'engineer', 'depends_on' => ['gather']],
            ['node_id' => 'gather', 'task_category' => 'retrieval', 'role' => 'researcher'],
        ]];
        $env = $conductor->run($this->work(), ['mode' => 'shadow', 'plan' => $plan]);

        $this->assertSame(0, $calls->n, 'SHADOW plan must invoke zero providers');
        $this->assertSame(AtlasEngineeringRunConductorService::STATUS_EXECUTED, $env['status']);
        $this->assertSame(2, $env['plan_trace']['node_count']);
        // The dependency edge (reason depends_on gather) forces gather first.
        $ids = array_map(static fn (array $n): string => $n['node_id'], $env['plan_trace']['nodes']);
        $this->assertSame(['gather', 'reason'], $ids);
        $this->assertSame('claude_cli', $env['plan_trace']['nodes'][0]['winner_provider']);
        $this->assertSame('success', $env['plan_trace']['nodes'][0]['winner_result']);
        $this->assertStringStartsWith('sha256:', $env['run_hash']);
    }

    public function test_authored_plan_dag_blocks_a_cyclic_plan_with_zero_dispatch(): void
    {
        $calls = (object) ['n' => 0];
        $pr = new AtlasSwarmProductionResolverService($this->fakeManager($this->spyProvider($calls)));
        $conductor = $this->conductor($this->swarmConductor('ok', 'claude_cli'), $pr);

        $plan = ['nodes' => [
            ['node_id' => 'a', 'task_category' => 'retrieval', 'role' => 'r', 'depends_on' => ['b']],
            ['node_id' => 'b', 'task_category' => 'reasoning', 'role' => 'e', 'depends_on' => ['a']],
        ]];
        $env = $conductor->run($this->work(), ['mode' => 'shadow', 'plan' => $plan]);

        $this->assertSame(0, $calls->n);
        $this->assertSame(AtlasEngineeringRunConductorService::STATUS_PLAN_BLOCKED, $env['status']);
        $this->assertSame('plan_has_cycle', $env['plan_trace']['blocked_reason']);
        $this->assertSame(0, $env['plan_trace']['node_count']);
    }

    public function test_no_dispatch_when_insufficient_routing_evidence(): void
    {
        $pr = new AtlasSwarmProductionResolverService($this->noProviderManager());
        $conductor = $this->conductor($this->swarmConductor('insufficient_evidence'), $pr);

        $env = $conductor->run($this->work(), ['mode' => 'shadow']);

        $this->assertSame(AtlasEngineeringRunConductorService::STATUS_NO_DISPATCH, $env['status']);
        $this->assertSame(0, $env['effective_parallelism']);
        $this->assertNull($env['winner']);
        $this->assertNull($env['compounding_candidate']);
    }

    public function test_live_requested_but_flag_off_downgrades_to_shadow(): void
    {
        config(['atlas.patamar4.swarm_production_resolver_enabled' => false]);

        $calls = (object) ['n' => 0];
        $pr = new AtlasSwarmProductionResolverService($this->fakeManager($this->spyProvider($calls)));
        $conductor = $this->conductor($this->swarmConductor('ok'), $pr);

        $env = $conductor->run($this->work(), ['mode' => 'live', 'operator_approved' => true]);

        $this->assertSame(0, $calls->n, 'a flag-off downgrade must not spend on any provider');
        $this->assertSame(AtlasEngineeringRunConductorService::MODE_SHADOW, $env['mode']);
        $this->assertSame(AtlasEngineeringRunConductorService::MODE_LIVE, $env['requested_mode']);
        $this->assertSame('production_resolver_disabled', $env['mode_downgrade_reason']);
        $this->assertSame(AtlasEngineeringRunConductorService::STATUS_EXECUTED, $env['status']);
    }

    public function test_live_runs_real_provider_when_enabled_and_approved(): void
    {
        config(['atlas.patamar4.swarm_production_resolver_enabled' => true]);

        $calls = (object) ['n' => 0];
        $fake = $this->fakeProvider(function () use ($calls): AiProviderResult {
            $calls->n++;

            return new AiProviderResult(true, 'real provider output', [], 0, 42, 'real provider output', '');
        });
        $pr = new AtlasSwarmProductionResolverService($this->fakeManager($fake));
        $conductor = $this->conductor($this->swarmConductor('ok', 'claude_cli'), $pr);

        $env = $conductor->run($this->work(), ['mode' => 'live', 'operator_approved' => true]);

        $this->assertSame(AtlasEngineeringRunConductorService::MODE_LIVE, $env['mode'], 'live should not downgrade when enabled + approved');
        $this->assertNull($env['mode_downgrade_reason']);
        $this->assertSame(AtlasEngineeringRunConductorService::STATUS_EXECUTED, $env['status']);
        $this->assertIsArray($env['winner']);
        $this->assertSame('success', $env['winner']['result']);
        $this->assertSame('claude_cli', $env['winner']['provider']);
        $this->assertSame(1, $calls->n, 'the real provider must be invoked exactly once for the single arm');
    }

    public function test_live_without_operator_approval_downgrades_to_shadow(): void
    {
        config(['atlas.patamar4.swarm_production_resolver_enabled' => true]);

        $calls = (object) ['n' => 0];
        $pr = new AtlasSwarmProductionResolverService($this->fakeManager($this->spyProvider($calls)));
        $conductor = $this->conductor($this->swarmConductor('ok'), $pr);

        $env = $conductor->run($this->work(), ['mode' => 'live', 'operator_approved' => false]);

        $this->assertSame(0, $calls->n, 'an unapproved approval-required verdict must not spend on any provider');
        $this->assertSame(AtlasEngineeringRunConductorService::MODE_SHADOW, $env['mode']);
        $this->assertSame(AtlasEngineeringRunConductorService::MODE_LIVE, $env['requested_mode']);
        $this->assertSame('autonomy_requires_operator_approval', $env['mode_downgrade_reason']);
    }

    public function test_verify_gate_blocks_when_changed_files_uncovered(): void
    {
        $ve = app(AtlasVerifiedExecutionRuntimeService::class);
        $pr = new AtlasSwarmProductionResolverService($this->noProviderManager());
        $conductor = $this->conductor($this->swarmConductor('ok'), $pr, $ve);

        $env = $conductor->run($this->work(), [
            'mode' => 'shadow',
            'verify' => true,
            'changed_files' => ['app/SomeUncoveredFile.php'],
        ]);

        $this->assertSame(AtlasEngineeringRunConductorService::STATUS_VERIFIED_BLOCKED, $env['status']);
        $this->assertIsArray($env['verification']);
        $this->assertSame(AtlasVerifiedExecutionRuntimeService::STATUS_BLOCKED, $env['verification']['status']);
    }

    /**
     * Exhaustive proof that the sovereignty guard is an ALLOWLIST: only an
     * explicitly-authorizing verdict reaches LIVE; everything else (including an
     * empty/unknown decision from a future dispatch source) fails closed to
     * SHADOW with a reason. No silent escalation to real provider spend.
     */
    public function test_decide_mode_is_an_exhaustive_allowlist(): void
    {
        $shadow = AtlasEngineeringRunConductorService::MODE_SHADOW;
        $live = AtlasEngineeringRunConductorService::MODE_LIVE;
        $decide = static fn (string $mode, bool $enabled, string $admission, bool $approved): array =>
            AtlasEngineeringRunConductorService::decideMode($mode, $enabled, $admission, $approved);

        // A non-live request never reaches live, regardless of everything else.
        $this->assertSame([$shadow, null], $decide('shadow', true, 'allow_autonomous', true));

        // Flag gate holds before admission is even consulted.
        $this->assertSame([$shadow, 'production_resolver_disabled'], $decide($live, false, 'allow_autonomous', true));

        // The only two paths to LIVE.
        $this->assertSame([$live, null], $decide($live, true, 'allow_autonomous', false));
        $this->assertSame([$live, null], $decide($live, true, 'allow_with_approval', true));

        // Approval-required without approval fails closed.
        $this->assertSame([$shadow, 'autonomy_requires_operator_approval'], $decide($live, true, 'allow_with_approval', false));

        // Deny fails closed.
        $this->assertSame([$shadow, 'autonomy_admission_denied'], $decide($live, true, 'deny', true));

        // Empty / unknown verdict (e.g. a future dispatch source) fails closed —
        // this is the defense-in-depth the allowlist adds over a blocklist.
        $this->assertSame([$shadow, 'autonomy_decision_unrecognized'], $decide($live, true, '', true));
        $this->assertSame([$shadow, 'autonomy_decision_unrecognized'], $decide($live, true, 'a_future_verdict', true));
    }

    public function test_recalled_governed_memory_is_injected_into_the_live_provider_prompt(): void
    {
        config(['atlas.patamar4.swarm_production_resolver_enabled' => true]);

        $captured = (object) ['prompt' => ''];
        $fake = $this->fakeProvider(function ($job, string $prompt) use ($captured): AiProviderResult {
            $captured->prompt = $prompt;

            return new AiProviderResult(true, 'ok', [], 0, 1, 'ok', '');
        });
        $pr = new AtlasSwarmProductionResolverService($this->fakeManager($fake));

        $memory = Mockery::mock(AtlasCompoundingMemoryService::class);
        $memory->shouldReceive('approvedForFlow')->andReturn([
            ['memory_id' => 'm1', 'claim' => 'prefer deterministic kernels for safety', 'confidence' => 90],
        ]);

        $conductor = $this->conductor($this->swarmConductor('ok', 'claude_cli'), $pr, null, $memory);
        $env = $conductor->run($this->work(), ['mode' => 'live', 'operator_approved' => true]);

        $this->assertSame(AtlasEngineeringRunConductorService::MODE_LIVE, $env['mode']);
        $this->assertStringContainsString('prefer deterministic kernels for safety', $captured->prompt, 'recalled memory must reach the live provider prompt');
        $this->assertStringContainsString('do the thing', $captured->prompt, 'base intent must be preserved verbatim');
        $this->assertSame(1, $env['context_injection']['recalled_count']);
        $this->assertSame(['m1'], $env['context_injection']['memory_ids']);
    }

    public function test_context_injection_is_graceful_without_a_memory_source(): void
    {
        $calls = (object) ['n' => 0];
        $pr = new AtlasSwarmProductionResolverService($this->fakeManager($this->spyProvider($calls)));
        $conductor = $this->conductor($this->swarmConductor('ok'), $pr, null, null);

        $env = $conductor->run($this->work(), ['mode' => 'shadow']);

        $this->assertSame(0, $calls->n);
        $this->assertSame(AtlasEngineeringRunConductorService::CONTEXT_INJECTION_SCHEMA, $env['context_injection']['schema_version']);
        $this->assertSame(0, $env['context_injection']['recalled_count']);
        $this->assertSame([], $env['context_injection']['memory_ids']);
    }

    public function test_compounding_candidate_is_pipeline_ready_with_evidence_but_never_self_promotes(): void
    {
        $calls = (object) ['n' => 0];
        $pr = new AtlasSwarmProductionResolverService($this->fakeManager($this->spyProvider($calls)));
        $conductor = $this->conductor($this->swarmConductor('ok', 'claude_cli'), $pr);

        $env = $conductor->run($this->work(), [
            'mode' => 'shadow',
            'evidence_refs' => ['receipt:abc', 'ledger:xyz'],
        ]);

        $candidate = $env['compounding_candidate'];
        $this->assertIsArray($candidate);
        $this->assertSame('code_generation', $candidate['flow_id']);
        $this->assertSame(['receipt:abc', 'ledger:xyz'], $candidate['evidence_refs']);
        $this->assertTrue($candidate['pipeline_ready']);
        // The conductor emits a pipeline-ready signal but NEVER self-promotes —
        // promotion (confidence>=70 + revalidation) is the compounding pipeline's gate.
        $this->assertFalse($candidate['promotion_allowed']);
    }

    public function test_sdd_gate_blocks_an_ambiguous_spec_before_any_dispatch_or_spend(): void
    {
        $calls = (object) ['n' => 0];
        $pr = new AtlasSwarmProductionResolverService($this->fakeManager($this->spyProvider($calls)));
        $conductor = $this->conductor($this->swarmConductor('ok'), $pr, null, null, new SpecCritic);

        $env = $conductor->run($this->work(), ['mode' => 'shadow', 'spec' => ['objective' => 'do something']]);

        $this->assertSame(0, $calls->n, 'a spec-blocked run must never dispatch or spend');
        $this->assertSame(AtlasEngineeringRunConductorService::STATUS_SPEC_BLOCKED, $env['status']);
        $this->assertTrue($env['spec_review']['has_blocking_questions']);
        $this->assertNotEmpty($env['spec_review']['clarification_questions']);
        $this->assertSame(0, $env['effective_parallelism']);
        $this->assertNull($env['winner']);
    }

    public function test_sdd_gate_passes_a_clean_spec_and_proceeds(): void
    {
        $pr = new AtlasSwarmProductionResolverService($this->noProviderManager());
        $conductor = $this->conductor($this->swarmConductor('ok', 'claude_cli'), $pr, null, null, new SpecCritic);

        $env = $conductor->run($this->work(), ['mode' => 'shadow', 'spec' => $this->cleanSpec()]);

        $this->assertNotSame(AtlasEngineeringRunConductorService::STATUS_SPEC_BLOCKED, $env['status']);
        $this->assertSame(AtlasEngineeringRunConductorService::STATUS_EXECUTED, $env['status']);
        $this->assertIsArray($env['spec_review']);
        $this->assertFalse($env['spec_review']['has_blocking_questions']);
    }

    public function test_sdd_gate_is_skipped_when_no_spec_supplied(): void
    {
        $pr = new AtlasSwarmProductionResolverService($this->noProviderManager());
        $conductor = $this->conductor($this->swarmConductor('ok'), $pr, null, null, new SpecCritic);

        $env = $conductor->run($this->work(), ['mode' => 'shadow']);

        $this->assertNull($env['spec_review'], 'no spec supplied -> gate skipped, run proceeds');
        $this->assertSame(AtlasEngineeringRunConductorService::STATUS_EXECUTED, $env['status']);
    }

    public function test_recall_never_breaks_the_run_when_the_memory_source_throws(): void
    {
        $calls = (object) ['n' => 0];
        $pr = new AtlasSwarmProductionResolverService($this->fakeManager($this->spyProvider($calls)));
        $memory = Mockery::mock(AtlasCompoundingMemoryService::class);
        $memory->shouldReceive('approvedForFlow')->andThrow(new \RuntimeException('memory store unavailable'));
        $conductor = $this->conductor($this->swarmConductor('ok', 'claude_cli'), $pr, null, $memory);

        $env = $conductor->run($this->work(), ['mode' => 'shadow']);

        $this->assertSame(AtlasEngineeringRunConductorService::STATUS_EXECUTED, $env['status'], 'a throwing memory source must never break the run');
        $this->assertSame(0, $env['context_injection']['recalled_count']);
    }

    public function test_rich_context_injects_full_context_pack_into_the_live_prompt(): void
    {
        config(['atlas.patamar4.swarm_production_resolver_enabled' => true]);

        $captured = (object) ['prompt' => ''];
        $fake = $this->fakeProvider(function ($job, string $prompt) use ($captured): AiProviderResult {
            $captured->prompt = $prompt;

            return new AiProviderResult(true, 'ok', [], 0, 1, 'ok', '');
        });
        $pr = new AtlasSwarmProductionResolverService($this->fakeManager($fake));

        $pack = Mockery::mock(AiContextPack::class);
        $pack->shouldReceive('toPromptSection')->andReturn("[Context Pack]\n- ref: app/Http/Middleware/RateLimiter.php");
        $pack->shouldReceive('contextRefs')->andReturn(['a', 'b', 'c']);
        $builder = Mockery::mock(AiContextPackBuilder::class);
        $builder->shouldReceive('build')->andReturn($pack);

        $conductor = new AtlasEngineeringRunConductorService(
            $this->swarmConductor('ok', 'claude_cli'), $this->executor(), $pr, null, null, null, $builder,
        );
        $env = $conductor->run($this->work(), ['mode' => 'live', 'operator_approved' => true, 'rich_context' => true]);

        $this->assertStringContainsString('[Context Pack]', $captured->prompt, 'the assembled context pack must reach the live provider prompt');
        $this->assertStringContainsString('do the thing', $captured->prompt, 'base intent preserved');
        $this->assertTrue($env['context_injection']['context_pack_present']);
        $this->assertSame(3, $env['context_injection']['context_pack_refs']);
    }

    public function test_rich_context_is_off_by_default_so_the_pack_builder_is_never_called(): void
    {
        $calls = (object) ['n' => 0];
        $pr = new AtlasSwarmProductionResolverService($this->fakeManager($this->spyProvider($calls)));

        $builder = Mockery::mock(AiContextPackBuilder::class);
        $builder->shouldReceive('build')->never(); // proves SHADOW planning stays fast: no heavy assembly unless opted in

        $conductor = new AtlasEngineeringRunConductorService(
            $this->swarmConductor('ok'), $this->executor(), $pr, null, null, null, $builder,
        );
        $env = $conductor->run($this->work(), ['mode' => 'shadow']);

        $this->assertFalse($env['context_injection']['context_pack_present']);
        $this->assertSame(0, $env['context_injection']['context_pack_refs']);
    }

    public function test_live_run_feeds_the_compounding_pipeline_when_opted_in(): void
    {
        config(['atlas.patamar4.swarm_production_resolver_enabled' => true]);
        $fake = $this->fakeProvider(fn () => new AiProviderResult(true, 'real output', [], 0, 5, 'real output', ''));
        $pr = new AtlasSwarmProductionResolverService($this->fakeManager($fake));

        $runtime = Mockery::mock(AtlasCompoundingRuntimeService::class);
        $runtime->shouldReceive('recordExecution')->once()->with(Mockery::on(function (array $payload): bool {
            $signal = $payload['learning_signal'] ?? null;

            return is_array($signal)
                && isset($signal['claim'])
                && is_string($signal['claim'])
                && ! str_contains($signal['claim'], 'should inform future routing, retrieval or execution when matching evidence recurs')
                && str_contains($signal['claim'], 'do the thing')
                && ($signal['memory_type'] ?? null) === 'engineering_run_memory'
                && ($signal['flow_id'] ?? null) === 'code_generation'
                && ($signal['scope'] ?? null) === 'engineering'
                && ($signal['evidence_refs'] ?? null) === ['receipt:compounding-live']
                && ($payload['evidence_refs'] ?? null) === ['receipt:compounding-live'];
        }))->andReturn([
            'learning_candidate' => ['status' => 'distilled'],
            'compounding_memory' => ['id' => 'mem-1'],
        ]);

        $conductor = new AtlasEngineeringRunConductorService(
            $this->swarmConductor('ok', 'codex_cli'), $this->executor(), $pr, null, null, null, null, $runtime,
        );
        $env = $conductor->run($this->work(), [
            'mode' => 'live',
            'operator_approved' => true,
            'compound' => true,
            'evidence_refs' => ['receipt:compounding-live'],
        ]);

        $this->assertSame(AtlasEngineeringRunConductorService::MODE_LIVE, $env['mode']);
        $this->assertIsArray($env['compounding_record']);
        $this->assertTrue($env['compounding_record']['recorded']);
        $this->assertSame('mem-1', $env['compounding_record']['compounding_memory_id']);
    }

    public function test_shadow_never_feeds_the_compounding_pipeline_even_when_opted_in(): void
    {
        $calls = (object) ['n' => 0];
        $pr = new AtlasSwarmProductionResolverService($this->fakeManager($this->spyProvider($calls)));

        $runtime = Mockery::mock(AtlasCompoundingRuntimeService::class);
        $runtime->shouldReceive('recordExecution')->never(); // a SHADOW plan must never train the learning system

        $conductor = new AtlasEngineeringRunConductorService(
            $this->swarmConductor('ok', 'codex_cli'), $this->executor(), $pr, null, null, null, null, $runtime,
        );
        $env = $conductor->run($this->work(), ['mode' => 'shadow', 'compound' => true]);

        $this->assertNull($env['compounding_record']);
    }

    public function test_live_run_delivers_code_via_the_routed_provider_when_opted_in(): void
    {
        config(['atlas.patamar4.swarm_production_resolver_enabled' => true]);
        $fake = $this->fakeProvider(fn (): AiProviderResult => new AiProviderResult(true, 'ok', [], 0, 5, 'ok', ''));
        $pr = new AtlasSwarmProductionResolverService($this->fakeManager($fake));

        $delivery = Mockery::mock(AtlasLiveCodeDeliveryService::class);
        $delivery->shouldReceive('deliver')->once()->andReturn([
            'status' => 'certified', 'certified' => true, 'target_file' => 'X.php', 'provider' => 'codex_cli',
        ]);

        $conductor = new AtlasEngineeringRunConductorService(
            $this->swarmConductor('ok', 'codex_cli'), $this->executor(), $pr, null, null, null, null, null, $delivery,
        );
        $env = $conductor->run($this->work(), ['mode' => 'live', 'operator_approved' => true, 'deliver_code' => true, 'target_file' => 'X.php']);

        $this->assertIsArray($env['code_delivery']);
        $this->assertTrue($env['code_delivery']['certified']);
        $this->assertSame('codex_cli', $env['code_delivery']['provider']);
    }

    public function test_shadow_never_delivers_code_even_when_opted_in(): void
    {
        $calls = (object) ['n' => 0];
        $pr = new AtlasSwarmProductionResolverService($this->fakeManager($this->spyProvider($calls)));

        $delivery = Mockery::mock(AtlasLiveCodeDeliveryService::class);
        $delivery->shouldReceive('deliver')->never(); // code delivery spends provider tokens — never in SHADOW

        $conductor = new AtlasEngineeringRunConductorService(
            $this->swarmConductor('ok', 'codex_cli'), $this->executor(), $pr, null, null, null, null, null, $delivery,
        );
        $env = $conductor->run($this->work(), ['mode' => 'shadow', 'deliver_code' => true]);

        $this->assertNull($env['code_delivery']);
    }

    public function test_auto_routes_from_routing_memory_when_no_provider_given(): void
    {
        $memory = new AtlasConductorRoutingMemory;
        $memory->setLogPathForTesting($this->tmpPath('routing'));
        $memory->record(['task_category' => 'code_generation', 'role' => 'primary', 'provider' => 'codex_cli', 'result' => 'success', 'latency_ms' => 100]);

        $pr = new AtlasSwarmProductionResolverService($this->noProviderManager());
        // ADML has no route -> the auto-routed provider (learned from memory) builds the arm.
        $conductor = new AtlasEngineeringRunConductorService(
            $this->swarmConductor('insufficient_evidence'), $this->executor(), $pr, null, null, null, null, null, null, $memory,
        );

        $env = $conductor->run($this->work(), ['mode' => 'shadow']);

        $this->assertIsArray($env['auto_routed']);
        $this->assertSame('codex_cli', $env['auto_routed']['provider']);
        $this->assertSame(AtlasEngineeringRunConductorService::STATUS_EXECUTED, $env['status']);
        $this->assertSame('codex_cli', $env['winner']['provider']);
    }
}
