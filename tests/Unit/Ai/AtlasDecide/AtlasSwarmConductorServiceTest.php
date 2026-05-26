<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AtlasDecide;

use App\Services\Ai\AtlasDecide\AtlasDecideMetaLearningService;
use App\Services\Ai\AtlasDecide\AtlasSwarmConductorService;
use App\Services\Ai\Governance\AtlasAutonomyAdmissionService;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use Tests\TestCase;

/**
 * Stub ADML that returns deterministic recommendations so we can exercise
 * Swarm Conductor paths without depending on real ledger state.
 */
final class StubAdmlForSwarm extends AtlasDecideMetaLearningService
{
    private string $signal;

    public function __construct(string $signal = 'ok')
    {
        // intentionally do not call parent::__construct to avoid DB needs
        $this->signal = $signal;
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
            'recommended_provider' => 'claude_code',
            'recommended_model' => 'opus-4.7',
            'runner_up_provider' => 'codex_cli',
            'runner_up_model' => 'gpt-5-codex',
            'actionable' => true,
        ];
    }
}

class AtlasSwarmConductorServiceTest extends TestCase
{
    private ?string $kernelLog = null;

    private ?string $admissionLog = null;

    private ?string $dispatchLog = null;

    private function buildSvc(string $admlSignal = 'ok'): AtlasSwarmConductorService
    {
        $u = uniqid('', true);
        $this->kernelLog = sys_get_temp_dir()."/atlas_swarm_kernel_{$u}.jsonl";
        $this->admissionLog = sys_get_temp_dir()."/atlas_swarm_admission_{$u}.jsonl";
        $this->dispatchLog = sys_get_temp_dir()."/atlas_swarm_{$u}.jsonl";

        $kernel = new AtlasConstitutionalKernelService;
        $kernel->setViolationsLogPathForTesting($this->kernelLog);

        $admission = new AtlasAutonomyAdmissionService($kernel);
        $admission->setTicketsLogPathForTesting($this->admissionLog);

        $adml = new StubAdmlForSwarm($admlSignal);

        $svc = new AtlasSwarmConductorService($adml, $kernel, $admission);
        $svc->setDispatchesLogPathForTesting($this->dispatchLog);

        return $svc;
    }

    protected function tearDown(): void
    {
        if ($this->kernelLog !== null) {
            @unlink($this->kernelLog);
        }
        if ($this->admissionLog !== null) {
            @unlink($this->admissionLog);
        }
        if ($this->dispatchLog !== null) {
            @unlink($this->dispatchLog);
        }
        parent::tearDown();
    }

    private function baseWork(array $o = []): array
    {
        return array_merge([
            'task_category' => 'code_generation',
            'role' => 'primary',
            'framework' => null,
            'parallelism' => 2,
            'scope' => ['privacy_class' => 'public'],
            'requested_autonomy' => 'execute_with_approval',
        ], $o);
    }

    public function test_dispatch_envelope_shape(): void
    {
        $svc = $this->buildSvc();
        $env = $svc->dispatch($this->baseWork());
        $this->assertSame(AtlasSwarmConductorService::ENVELOPE_SCHEMA, $env['schema_version']);
        $this->assertStringStartsWith('swarm_', $env['dispatch_id']);
        $this->assertStringStartsWith('sha256:', $env['dispatch_hash']);
    }

    public function test_parallelism_2_yields_two_arms(): void
    {
        $svc = $this->buildSvc();
        $env = $svc->dispatch($this->baseWork(['parallelism' => 2]));
        $this->assertSame(2, $env['effective_parallelism']);
        $this->assertCount(2, $env['arms']);
        $this->assertSame(AtlasSwarmConductorService::ARM_ORIGIN_RECOMMENDED, $env['arms'][0]['origin']);
        $this->assertSame(AtlasSwarmConductorService::ARM_ORIGIN_RUNNER_UP, $env['arms'][1]['origin']);
    }

    public function test_parallelism_1_yields_only_recommended(): void
    {
        $svc = $this->buildSvc();
        $env = $svc->dispatch($this->baseWork(['parallelism' => 1]));
        $this->assertSame(1, $env['effective_parallelism']);
        $this->assertCount(1, $env['arms']);
        $this->assertSame('claude_code', $env['arms'][0]['provider']);
    }

    public function test_parallelism_3_adds_local_fallback(): void
    {
        $svc = $this->buildSvc();
        $env = $svc->dispatch($this->baseWork(['parallelism' => 3]));
        $this->assertSame(3, $env['effective_parallelism']);
        $this->assertSame(AtlasSwarmConductorService::ARM_ORIGIN_LOCAL_FALLBACK, $env['arms'][2]['origin']);
        $this->assertSame('atlas_local', $env['arms'][2]['provider']);
    }

    public function test_parallelism_clamped_to_max(): void
    {
        $svc = $this->buildSvc();
        $env = $svc->dispatch($this->baseWork(['parallelism' => 99]));
        $this->assertSame(AtlasSwarmConductorService::MAX_PARALLELISM, $env['requested_parallelism']);
    }

    public function test_insufficient_evidence_blocks_dispatch(): void
    {
        $svc = $this->buildSvc('insufficient_evidence');
        $env = $svc->dispatch($this->baseWork());
        $this->assertSame(0, $env['effective_parallelism']);
        $this->assertSame([], $env['arms']);
        $this->assertSame(AtlasAutonomyAdmissionService::DECISION_DENY, $env['admission_decision']);
    }

    public function test_missing_task_or_role_throws(): void
    {
        $svc = $this->buildSvc();
        $this->expectException(\InvalidArgumentException::class);
        $svc->dispatch(['task_category' => '', 'role' => 'primary']);
    }

    public function test_claim_policy_hardcoded_safe(): void
    {
        $svc = $this->buildSvc();
        $env = $svc->dispatch($this->baseWork());
        $this->assertFalse($env['claim_policy']['aggregate_winner_claim_allowed']);
        $this->assertFalse($env['claim_policy']['rivals_claim_allowed']);
        $this->assertFalse($env['claim_policy']['benchmark_claim_allowed']);
        $this->assertFalse($env['claim_policy']['superiority_claim_allowed']);
    }

    public function test_cyber_privacy_caps_admission(): void
    {
        $svc = $this->buildSvc();
        $env = $svc->dispatch($this->baseWork([
            'scope' => ['privacy_class' => 'cyber'],
            'requested_autonomy' => 'autonomous',
        ]));
        $this->assertNotSame(AtlasAutonomyAdmissionService::DECISION_ALLOW_AUTONOMOUS, $env['admission_decision']);
    }

    public function test_persisted_dispatches(): void
    {
        $svc = $this->buildSvc();
        $svc->dispatch($this->baseWork());
        $svc->dispatch($this->baseWork());
        $list = $svc->listDispatches();
        $this->assertCount(2, $list);
        foreach ($list as $d) {
            $this->assertSame(AtlasSwarmConductorService::ENVELOPE_SCHEMA, $d['schema_version']);
        }
    }

    public function test_last_dispatch(): void
    {
        $svc = $this->buildSvc();
        $this->assertNull($svc->lastDispatch());
        $env = $svc->dispatch($this->baseWork());
        $this->assertSame($env['dispatch_id'], $svc->lastDispatch()['dispatch_id']);
    }

    public function test_arms_have_distinct_ids(): void
    {
        $svc = $this->buildSvc();
        $env = $svc->dispatch($this->baseWork(['parallelism' => 3]));
        $ids = array_map(static fn ($a) => $a['arm_id'], $env['arms']);
        $this->assertSame(count($ids), count(array_unique($ids)));
    }

    public function test_elastic_disable_suppresses_local_fallback_arm(): void
    {
        $u = uniqid('', true);
        $kernelLog = sys_get_temp_dir()."/atlas_swarm_kernel_el_{$u}.jsonl";
        $admissionLog = sys_get_temp_dir()."/atlas_swarm_admission_el_{$u}.jsonl";
        $elasticLog = sys_get_temp_dir()."/atlas_swarm_elastic_{$u}.jsonl";
        $dispatchLog = sys_get_temp_dir()."/atlas_swarm_dispatch_el_{$u}.jsonl";

        $kernel = new AtlasConstitutionalKernelService;
        $kernel->setViolationsLogPathForTesting($kernelLog);
        $kernel->setElasticStateLogPathForTesting($elasticLog);
        $kernel->flipElastic(
            'swarm_local_fallback_enabled',
            false,
            'operator',
            'disable atlas_local fallback for test'
        );

        $admission = new AtlasAutonomyAdmissionService($kernel);
        $admission->setTicketsLogPathForTesting($admissionLog);
        $adml = new StubAdmlForSwarm('ok');
        $svc = new AtlasSwarmConductorService($adml, $kernel, $admission);
        $svc->setDispatchesLogPathForTesting($dispatchLog);

        $env = $svc->dispatch($this->baseWork(['parallelism' => 3]));

        // parallelism=3 would normally include the local fallback arm. With
        // the elastic invariant flipped off, the local fallback must NOT appear.
        $origins = array_map(static fn ($a) => $a['origin'], $env['arms']);
        $this->assertNotContains(AtlasSwarmConductorService::ARM_ORIGIN_LOCAL_FALLBACK, $origins);
        $this->assertSame(2, $env['effective_parallelism']);

        @unlink($kernelLog);
        @unlink($admissionLog);
        @unlink($elasticLog);
        @unlink($dispatchLog);
    }
}
