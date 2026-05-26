<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AtlasDecide;

use App\Services\Ai\AtlasDecide\AtlasDecideGatewayConsultationService;
use App\Services\Ai\AtlasDecide\AtlasDecideMetaLearningService;
use App\Services\Ai\Governance\AtlasAutonomyAdmissionService;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use Tests\TestCase;

final class StubAdmlForGateway extends AtlasDecideMetaLearningService
{
    private ?array $route;

    public function __construct(?array $route = null)
    {
        $this->route = $route;
    }

    public function activeRouteFor(string $taskCategory, string $role, ?string $framework = null): ?array
    {
        return $this->route;
    }
}

class AtlasDecideGatewayConsultationServiceTest extends TestCase
{
    private string $kernelLog;

    private string $admissionLog;

    private string $consultLog;

    private function build(?array $activeRoute): AtlasDecideGatewayConsultationService
    {
        $u = uniqid('', true);
        $this->kernelLog = sys_get_temp_dir()."/atlas_gwc_kernel_{$u}.jsonl";
        $this->admissionLog = sys_get_temp_dir()."/atlas_gwc_admission_{$u}.jsonl";
        $this->consultLog = sys_get_temp_dir()."/atlas_gwc_consult_{$u}.jsonl";

        $kernel = new AtlasConstitutionalKernelService;
        $kernel->setViolationsLogPathForTesting($this->kernelLog);
        $admission = new AtlasAutonomyAdmissionService($kernel);
        $admission->setTicketsLogPathForTesting($this->admissionLog);
        $adml = new StubAdmlForGateway($activeRoute);

        $svc = new AtlasDecideGatewayConsultationService($adml, $kernel, $admission);
        $svc->setLogPathForTesting($this->consultLog);

        return $svc;
    }

    protected function tearDown(): void
    {
        @unlink($this->kernelLog);
        @unlink($this->admissionLog);
        @unlink($this->consultLog);
        parent::tearDown();
    }

    public function test_envelope_shape(): void
    {
        $svc = $this->build(null);
        $env = $svc->consult([
            'task_category' => 'code_generation', 'role' => 'primary', 'privacy_class' => 'public',
        ]);
        $this->assertSame(AtlasDecideGatewayConsultationService::ENVELOPE_SCHEMA, $env['schema_version']);
        $this->assertStringStartsWith('sha256:', $env['envelope_hash']);
    }

    public function test_no_active_route_returns_free_to_choose(): void
    {
        $svc = $this->build(null);
        $env = $svc->consult([
            'task_category' => 'code_generation', 'role' => 'primary', 'privacy_class' => 'public',
        ]);
        $this->assertSame(AtlasDecideGatewayConsultationService::VERDICT_FREE_TO_CHOOSE, $env['verdict']);
        $this->assertNull($env['active_route']);
    }

    public function test_active_route_with_autonomous_admission_returns_follow_learned(): void
    {
        $svc = $this->build([
            'provider' => 'claude_code',
            'model' => 'opus-4.7',
            'mode' => 'active',
        ]);
        $env = $svc->consult([
            'task_category' => 'code_generation',
            'role' => 'primary',
            'privacy_class' => 'public',
        ]);
        $this->assertSame(AtlasDecideGatewayConsultationService::VERDICT_FOLLOW_LEARNED, $env['verdict']);
        $this->assertSame('claude_code', $env['active_route']['provider']);
    }

    public function test_sensitive_privacy_blocks_route(): void
    {
        $svc = $this->build([
            'provider' => 'claude_code',
            'model' => 'opus-4.7',
        ]);
        $env = $svc->consult([
            'task_category' => 'code_generation',
            'role' => 'primary',
            'privacy_class' => 'cyber',
        ]);
        // cyber privacy forces Admission to require_human_approval; verdict is requires_approval.
        $this->assertContains($env['verdict'], [
            AtlasDecideGatewayConsultationService::VERDICT_REQUIRES_APPROVAL,
            AtlasDecideGatewayConsultationService::VERDICT_BLOCKED,
        ]);
    }

    public function test_consultations_persisted_append_only(): void
    {
        $svc = $this->build(null);
        $svc->consult(['task_category' => 'a', 'role' => 'primary', 'privacy_class' => 'public']);
        $svc->consult(['task_category' => 'b', 'role' => 'primary', 'privacy_class' => 'public']);
        $this->assertCount(2, $svc->listConsultations());
    }

    public function test_kernel_decision_carried(): void
    {
        $svc = $this->build(null);
        $env = $svc->consult(['task_category' => 'a', 'role' => 'primary', 'privacy_class' => 'public']);
        $this->assertContains($env['kernel_decision'], ['allow', 'block', 'allow_with_human_approval']);
        $this->assertStringStartsWith('sha256:', $env['kernel_hash']);
    }
}
