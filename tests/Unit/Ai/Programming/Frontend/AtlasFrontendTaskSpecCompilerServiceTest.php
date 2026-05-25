<?php

namespace Tests\Unit\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendTaskSpecCompilerService;
use Tests\TestCase;

class AtlasFrontendTaskSpecCompilerServiceTest extends TestCase
{
    public function test_saas_dashboard_task_spec_compiles_routes_viewports_acceptance_and_gates(): void
    {
        $spec = app(AtlasFrontendTaskSpecCompilerService::class)->compile([
            'task' => 'Criar dashboard SaaS multiempresa com settings, design system, performance e live preview',
            'workspace' => '/tmp/acme',
            'acceptance' => true,
            'company_profile' => true,
            'live' => true,
        ]);

        $this->assertSame(AtlasFrontendTaskSpecCompilerService::SCHEMA_VERSION, $spec['schema_version']);
        $this->assertSame('ready', $spec['status']);
        $this->assertFalse((bool) $spec['raw_task_returned']);
        $this->assertContains('saas_frontend', $spec['task_types']);
        $this->assertContains('/dashboard', $spec['routes']);
        $this->assertContains('/settings', $spec['routes']);
        $this->assertContains('desktop', $spec['viewports']);
        $this->assertContains('tablet', $spec['viewports']);
        $this->assertContains('mobile', $spec['viewports']);
        $this->assertContains('preview_variant', $spec['states']);
        $this->assertContains('frontend_execution_gate', $spec['required_gates']);
        $this->assertContains('visual_quality_gate', $spec['required_gates']);
        $this->assertContains('design_system_drift_gate', $spec['required_gates']);
        $this->assertContains('live_source_patch_boundary_gate', $spec['required_gates']);
        $this->assertContains('source_patch_recovery_test', $spec['required_tests']);
        $this->assertSame('medium', $spec['ambiguity_level']);
        $this->assertSame('high', $spec['risk_level']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $spec['task_spec_hash']);
    }

    public function test_vague_frontend_task_blocks_execution_without_acceptance_context(): void
    {
        $spec = app(AtlasFrontendTaskSpecCompilerService::class)->compile([
            'task' => 'Deixar a tela mais bonita e premium para inúmeras empresas',
        ]);

        $this->assertSame('blocked', $spec['status']);
        $this->assertSame('high', $spec['ambiguity_level']);
        $this->assertFalse((bool) data_get($spec, 'execution_policy.allowed_to_execute'));
        $this->assertContains('acceptance_context_required', collect($spec['blockers'])->pluck('id')->all());
        $this->assertContains('design_direction_selection_recommended', collect($spec['warnings'])->pluck('id')->all());
    }
}
