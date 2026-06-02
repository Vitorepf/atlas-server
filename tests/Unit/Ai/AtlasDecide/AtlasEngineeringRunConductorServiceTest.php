<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AtlasDecide;

use App\Models\AiJob;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiProviderHealthCheck;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\AiProviderResult;
use App\Services\Ai\AtlasDecide\AtlasDecideMetaLearningService;
use App\Services\Ai\AtlasDecide\AtlasEngineeringRunConductorService;
use App\Services\Ai\AtlasDecide\AtlasSwarmConductorService;
use App\Services\Ai\AtlasDecide\AtlasSwarmExecutorService;
use App\Services\Ai\AtlasDecide\AtlasSwarmProductionResolverService;
use App\Services\Ai\Governance\AtlasAutonomyAdmissionService;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
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
    ): AtlasEngineeringRunConductorService {
        return new AtlasEngineeringRunConductorService($sc, $this->executor(), $pr, $ve);
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
}
