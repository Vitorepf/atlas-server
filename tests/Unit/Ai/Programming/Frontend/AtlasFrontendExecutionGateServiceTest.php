<?php

namespace Tests\Unit\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendExecutionGateService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendTaskSpecCompilerService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasFrontendExecutionGateServiceTest extends TestCase
{
    public function test_gate_blocks_frontend_execution_without_acceptance_context_and_review(): void
    {
        $workspace = sys_get_temp_dir().'/atlas-frontend-gate-blocked-'.bin2hex(random_bytes(4));
        File::makeDirectory($workspace);

        $payload = app(AtlasFrontendExecutionGateService::class)->evaluate([
            'task' => 'Criar frontend SaaS multiempresa com redesign do produto inteiro',
            'workspace' => $workspace,
        ]);

        $this->assertSame('atlas.frontend.execution_gate.v1', $payload['schema_version']);
        $this->assertSame('blocked', $payload['status']);
        $this->assertFalse((bool) $payload['execution_allowed']);
        $this->assertContains('acceptance_criteria_missing', collect($payload['blockers'])->pluck('id')->all());
        $this->assertContains('task_spec_acceptance_context_required', collect($payload['blockers'])->pluck('id')->all());
        $this->assertContains('company_design_dossier_required', collect($payload['blockers'])->pluck('id')->all());
        $this->assertContains('company_design_profile_required', collect($payload['blockers'])->pluck('id')->all());
        $this->assertContains('senior_design_review_missing', collect($payload['blockers'])->pluck('id')->all());
        $this->assertSame('blocked', data_get($payload, 'task_spec.status'));
        $this->assertFalse((bool) data_get($payload, 'task_spec.raw_task_returned'));
        $this->assertContains('inspect_ready_company_design_profile', $payload['required_next_actions']);
        $this->assertContains('run_atlas_frontend_design_dossier_template_or_fill_docs', $payload['required_next_actions']);
        $this->assertFalse((bool) data_get($payload, 'claim_policy.world_best_claim_allowed'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $payload['gate_hash']);
    }

    public function test_gate_passes_when_frontend_pre_execution_evidence_is_present(): void
    {
        $workspace = sys_get_temp_dir().'/atlas-frontend-gate-passed-'.bin2hex(random_bytes(4));
        File::makeDirectory($workspace.'/src/components/ui', 0755, true);
        file_put_contents($workspace.'/package.json', json_encode([
            'dependencies' => ['tailwindcss' => '^latest'],
        ]));
        file_put_contents($workspace.'/src/components/ui/Button.tsx', 'export function Button() { return <button className="bg-primary" />; }');
        file_put_contents($workspace.'/src/styles.css', ':root { --color-primary: #123456; }');

        $payload = app(AtlasFrontendExecutionGateService::class)->evaluate([
            'task' => 'Ajustar componente Button no frontend',
            'workspace' => $workspace,
            'acceptance_criteria' => true,
            'test_plan' => true,
            'visual_quality_plan' => true,
            'evidence_plan' => true,
        ]);

        $this->assertSame('passed', $payload['status']);
        $this->assertTrue((bool) $payload['execution_allowed']);
        $this->assertSame('ready', data_get($payload, 'task_spec.status'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) data_get($payload, 'task_spec.task_spec_hash'));
        $this->assertSame('ready', data_get($payload, 'inventory.status'));
        $this->assertSame([], $payload['blockers']);
        $this->assertContains('frontend_visual_quality_gate', $payload['required_gates']);
        $this->assertFalse((bool) data_get($payload, 'claim_policy.completion_claim_allowed'));
    }

    public function test_gate_blocks_when_declared_task_spec_hash_does_not_match_canonical_spec(): void
    {
        $task = 'Ajustar componente Button no frontend';
        $workspace = sys_get_temp_dir().'/atlas-frontend-gate-spec-'.bin2hex(random_bytes(4));
        File::makeDirectory($workspace.'/src/components', 0755, true);
        file_put_contents($workspace.'/package.json', json_encode(['dependencies' => ['react' => '^latest']]));
        file_put_contents($workspace.'/src/components/Button.tsx', 'export function Button() { return <button />; }');

        $matchingSpec = app(AtlasFrontendTaskSpecCompilerService::class)->compile([
            'task' => $task,
            'workspace' => $workspace,
            'acceptance' => true,
        ]);

        $passed = app(AtlasFrontendExecutionGateService::class)->evaluate([
            'task' => $task,
            'workspace' => $workspace,
            'task_spec_hash' => $matchingSpec['task_spec_hash'],
            'acceptance_criteria' => true,
            'test_plan' => true,
            'visual_quality_plan' => true,
            'evidence_plan' => true,
        ]);

        $blocked = app(AtlasFrontendExecutionGateService::class)->evaluate([
            'task' => $task,
            'workspace' => $workspace,
            'task_spec_hash' => str_repeat('a', 64),
            'acceptance_criteria' => true,
            'test_plan' => true,
            'visual_quality_plan' => true,
            'evidence_plan' => true,
        ]);

        $this->assertSame('passed', $passed['status']);
        $this->assertTrue((bool) data_get($passed, 'task_spec.declared_hash_matches'));
        $this->assertSame('blocked', $blocked['status']);
        $this->assertContains('task_spec_hash_mismatch', collect($blocked['blockers'])->pluck('id')->all());
        $this->assertContains('recompile_or_attach_matching_task_spec_hash', $blocked['required_next_actions']);
    }

    public function test_gate_carries_frontend_app_scope_from_task_spec(): void
    {
        $workspace = sys_get_temp_dir().'/atlas-frontend-gate-monorepo-'.bin2hex(random_bytes(4));
        File::makeDirectory($workspace.'/apps/web/src', 0755, true);

        $payload = app(AtlasFrontendExecutionGateService::class)->evaluate([
            'task' => 'Ajustar checkout web',
            'workspace' => $workspace,
            'frontend_app' => 'apps/web',
            'acceptance_criteria' => true,
            'test_plan' => true,
            'visual_quality_plan' => true,
            'evidence_plan' => true,
        ]);

        $this->assertSame('subscope_selected', data_get($payload, 'frontend_app_scope.status'));
        $this->assertSame('apps/web', data_get($payload, 'frontend_app_scope.relative_name'));
        $this->assertSame('apps/web', data_get($payload, 'task_spec.frontend_app_scope.relative_name'));
        $this->assertStringNotContainsString($workspace.'/apps/web', json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
