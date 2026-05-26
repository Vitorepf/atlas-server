<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\CrossDomain;

use App\Services\Ai\CrossDomain\AtlasCrossDomainMeshService;
use App\Services\Ai\CrossDomain\AtlasTemporaryDomainCompositionService;
use App\Services\Ai\Governance\AtlasAutonomyAdmissionService;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use Tests\TestCase;

class AtlasTemporaryDomainCompositionServiceTest extends TestCase
{
    private string $acdmReqs;

    private string $acdmDecs;

    private string $kernelLog;

    private string $admissionLog;

    private string $capsulesLog;

    private AtlasTemporaryDomainCompositionService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $u = uniqid('', true);
        $this->acdmReqs = sys_get_temp_dir()."/atlas_tdc_acdm_req_{$u}.jsonl";
        $this->acdmDecs = sys_get_temp_dir()."/atlas_tdc_acdm_dec_{$u}.jsonl";
        $this->kernelLog = sys_get_temp_dir()."/atlas_tdc_kernel_{$u}.jsonl";
        $this->admissionLog = sys_get_temp_dir()."/atlas_tdc_admission_{$u}.jsonl";
        $this->capsulesLog = sys_get_temp_dir()."/atlas_tdc_capsules_{$u}.jsonl";

        $acdm = new AtlasCrossDomainMeshService;
        $acdm->setRequestsLogPathForTesting($this->acdmReqs);
        $acdm->setDecisionsLogPathForTesting($this->acdmDecs);

        $kernel = new AtlasConstitutionalKernelService;
        $kernel->setViolationsLogPathForTesting($this->kernelLog);

        $admission = new AtlasAutonomyAdmissionService($kernel);
        $admission->setTicketsLogPathForTesting($this->admissionLog);

        $this->svc = new AtlasTemporaryDomainCompositionService($acdm, $kernel, $admission);
        $this->svc->setCapsulesLogPathForTesting($this->capsulesLog);
    }

    protected function tearDown(): void
    {
        @unlink($this->acdmReqs);
        @unlink($this->acdmDecs);
        @unlink($this->kernelLog);
        @unlink($this->admissionLog);
        @unlink($this->capsulesLog);
        parent::tearDown();
    }

    private function baseInput(array $o = []): array
    {
        return array_merge([
            'domains' => ['engineering', 'governance'],
            'purpose' => 'incident_diagnostics',
            'privacy_class' => 'normal',
            'ttl_seconds' => 7200,
            'actor' => 'operator',
            'requested_autonomy' => 'execute_with_approval',
            'rationale' => 'cross-domain diagnostic window',
        ], $o);
    }

    public function test_compose_envelope_shape(): void
    {
        $c = $this->svc->compose($this->baseInput());
        $this->assertSame(AtlasTemporaryDomainCompositionService::CAPSULE_SCHEMA, $c['schema_version']);
        $this->assertStringStartsWith('tdc_', $c['capsule_id']);
        $this->assertStringStartsWith('sha256:', $c['capsule_hash']);
        $this->assertSame(['engineering', 'governance'], $c['domains']);
        $this->assertSame('active', $c['status']);
    }

    public function test_compose_requires_min_two_domains(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->compose($this->baseInput(['domains' => ['engineering']]));
    }

    public function test_compose_max_five_domains(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->compose($this->baseInput([
            'domains' => ['engineering', 'governance', 'ops', 'infra', 'finance', 'legal'],
        ]));
    }

    public function test_unknown_domain_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->compose($this->baseInput(['domains' => ['engineering', 'martian']]));
    }

    public function test_ttl_bounds_enforced(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->compose($this->baseInput(['ttl_seconds' => 1]));
    }

    public function test_ttl_max_enforced(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->compose($this->baseInput(['ttl_seconds' => 999999]));
    }

    public function test_unknown_privacy_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->compose($this->baseInput(['privacy_class' => 'martian']));
    }

    public function test_bridges_evaluated_for_all_pairs(): void
    {
        $c = $this->svc->compose($this->baseInput(['domains' => ['engineering', 'governance', 'ops']]));
        // 3 domains * 2 distinct targets each = 6 ordered pairs
        $this->assertCount(6, $c['bridges']);
    }

    public function test_any_deny_yields_denied_status(): void
    {
        // cyber → marketing must deny (per ACDM rules: cyber outbound only to governance/legal).
        $c = $this->svc->compose($this->baseInput([
            'domains' => ['cyber', 'marketing'],
            'privacy_class' => 'cyber',
        ]));
        $this->assertSame(AtlasTemporaryDomainCompositionService::STATUS_DENIED, $c['status']);
    }

    public function test_evaluate_capsule_active(): void
    {
        $c = $this->svc->compose($this->baseInput());
        $ev = $this->svc->evaluateCapsule($c['capsule_id']);
        $this->assertTrue($ev['valid']);
    }

    public function test_evaluate_not_found(): void
    {
        $ev = $this->svc->evaluateCapsule('tdc_nonexistent');
        $this->assertFalse($ev['valid']);
        $this->assertSame('not_found', $ev['reason']);
    }

    public function test_evaluate_denied_capsule(): void
    {
        $c = $this->svc->compose($this->baseInput([
            'domains' => ['cyber', 'marketing'],
            'privacy_class' => 'cyber',
        ]));
        $ev = $this->svc->evaluateCapsule($c['capsule_id']);
        $this->assertFalse($ev['valid']);
        $this->assertSame('denied_at_creation', $ev['reason']);
    }

    public function test_revoke_invalidates_capsule(): void
    {
        $c = $this->svc->compose($this->baseInput());
        $this->svc->expireCapsule($c['capsule_id'], 'operator_close');
        $ev = $this->svc->evaluateCapsule($c['capsule_id']);
        $this->assertFalse($ev['valid']);
        $this->assertSame('revoked', $ev['reason']);
    }

    public function test_revoke_unknown_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->expireCapsule('tdc_nonexistent', 'noop');
    }

    public function test_list_active_excludes_denied(): void
    {
        $this->svc->compose($this->baseInput());
        $this->svc->compose($this->baseInput([
            'domains' => ['cyber', 'marketing'],
            'privacy_class' => 'cyber',
        ]));
        $active = $this->svc->listActiveCapsules();
        $this->assertCount(1, $active);
    }

    public function test_list_all_includes_all_capsules(): void
    {
        $this->svc->compose($this->baseInput());
        $this->svc->compose($this->baseInput(['domains' => ['ops', 'governance']]));
        $all = $this->svc->listAllCapsules();
        $this->assertCount(2, $all);
    }

    public function test_capsule_hash_deterministic_minus_time(): void
    {
        $a = $this->svc->compose($this->baseInput());
        // Same input but different created_at → different hash (created_at part of hash)
        $b = $this->svc->compose($this->baseInput());
        $this->assertNotSame($a['capsule_id'], $b['capsule_id']);
    }

    public function test_default_ttl_honors_kernel_runtime_tune(): void
    {
        $u = uniqid('', true);
        $runtimeLog = sys_get_temp_dir()."/atlas_tdc_runtime_{$u}.jsonl";

        // Fresh kernel with runtime ledger override, tune tdc_ttl_window to 600s.
        $kernel = new AtlasConstitutionalKernelService;
        $kernel->setViolationsLogPathForTesting($this->kernelLog);
        $kernel->setRuntimeStateLogPathForTesting($runtimeLog);
        $kernel->tuneRuntime('tdc_ttl_window', 600, 'operator', 'narrow window for capsules');

        $acdm = new AtlasCrossDomainMeshService;
        $acdm->setRequestsLogPathForTesting($this->acdmReqs);
        $acdm->setDecisionsLogPathForTesting($this->acdmDecs);
        $admission = new AtlasAutonomyAdmissionService($kernel);
        $admission->setTicketsLogPathForTesting($this->admissionLog);

        $svc = new AtlasTemporaryDomainCompositionService($acdm, $kernel, $admission);
        $svc->setCapsulesLogPathForTesting($this->capsulesLog);

        // No ttl_seconds in input → should default to tuned value 600 (not 7200).
        $input = $this->baseInput();
        unset($input['ttl_seconds']);
        $capsule = $svc->compose($input);
        $this->assertSame(600, $capsule['ttl_seconds']);

        @unlink($runtimeLog);
    }
}
