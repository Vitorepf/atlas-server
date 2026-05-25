<?php

namespace Tests\Unit\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendDesignDossierService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendProviderInstructionPacketService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasFrontendProviderInstructionPacketServiceTest extends TestCase
{
    public function test_ready_repo_packet_forces_provider_to_follow_runbook_evidence_and_claim_policy(): void
    {
        $payload = app(AtlasFrontendProviderInstructionPacketService::class)->compile([
            'task' => 'Refinar dashboard BlackInk com design premium responsivo',
            'workspace' => $this->readyWorkspace(),
            'provider' => 'codex_cli',
            'acceptance_criteria' => true,
            'test_plan' => true,
            'visual_quality_plan' => true,
            'evidence_plan' => true,
            'senior_design_review' => true,
        ]);

        $this->assertSame(AtlasFrontendProviderInstructionPacketService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame('provider_safe_frontend_execution_instruction_packet', $payload['packet_type']);
        $this->assertSame('codex_cli', $payload['target_provider']);
        $this->assertSame('repo_root', data_get($payload, 'frontend_app_scope.status'));
        $this->assertContains('preserve_repo_design_system_and_product_intent', $payload['provider_mandates']);
        $this->assertContains('never_claim_world_best_or_done_without_certified_evidence', $payload['provider_mandates']);
        $this->assertContains('run_browser_and_static_anti_slop_detectors_or_record_blocker', $payload['provider_mandates']);
        $this->assertContains('preserve_selected_repo_as_workspace_and_frontend_app_as_subscope', $payload['provider_mandates']);
        $this->assertContains('generic_landing_page_when_product_context_exists', $payload['forbidden_provider_behaviors']);
        $this->assertContains('screenshot_only_completion_claim', $payload['forbidden_provider_behaviors']);
        $this->assertContains('treating_frontend_app_subscope_as_selected_workspace_or_space', $payload['forbidden_provider_behaviors']);
        $this->assertSame(AtlasFrontendProviderInstructionPacketService::EXECUTION_GUARDRAILS_SCHEMA_VERSION, data_get($payload, 'provider_execution_guardrails.schema_version'));
        $this->assertTrue((bool) data_get($payload, 'provider_execution_guardrails.selected_workspace_contract.selected_repository_remains_primary_workspace'));
        $this->assertTrue((bool) data_get($payload, 'provider_execution_guardrails.selected_workspace_contract.frontend_app_is_subscope_only'));
        $this->assertFalse((bool) data_get($payload, 'provider_execution_guardrails.selected_workspace_contract.space_runtime_required'));
        $this->assertContains('atlas_frontend_static_anti_slop_detector', data_get($payload, 'provider_execution_guardrails.mandatory_detector_receipts'));
        $this->assertContains('atlas_frontend_browser_detector_event', data_get($payload, 'provider_execution_guardrails.mandatory_detector_receipts'));
        $this->assertContains('delivery_done', data_get($payload, 'provider_execution_guardrails.forbidden_claims_until_receipts_exist'));
        $this->assertTrue((bool) data_get($payload, 'provider_execution_guardrails.world_best_claim_gate.requires_decisive_lead_each_complete_case'));
        $this->assertSame(2, data_get($payload, 'provider_execution_guardrails.world_best_claim_gate.minimum_decisive_lead_points'));
        $this->assertContains('visual_quality_report', $payload['required_evidence']);
        $this->assertContains('run_certification_hash', $payload['required_evidence']);
        $this->assertTrue((bool) data_get($payload, 'claim_policy.provider_must_return_receipts_not_claims'));
        $this->assertTrue((bool) data_get($payload, 'claim_policy.completion_claim_requires_guardrail_receipts'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.world_best_claim_allowed'));
        $commands = implode("\n", collect($payload['execution_sequence'])->flatMap(fn (array $step): array => $step['commands'])->all());
        $this->assertStringContainsString('pnpm install --frozen-lockfile', $commands);
        $this->assertStringContainsString('atlas:frontend:run-certify', $commands);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $payload['provider_instruction_packet_hash']);
    }

    public function test_packet_blocks_provider_when_gate_or_repo_context_is_missing(): void
    {
        $payload = app(AtlasFrontendProviderInstructionPacketService::class)->compile([
            'task' => 'Criar tela',
            'workspace' => sys_get_temp_dir().'/atlas-frontend-provider-packet-missing-'.bin2hex(random_bytes(4)),
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('work_order_phase_blocked_pre_execution_gate', $payload['blockers']);
        $this->assertContains('runbook_repo_workspace_not_found', $payload['blockers']);
        $this->assertContains('provide_acceptance_criteria', $payload['required_next_actions']);
    }

    public function test_read_only_packet_projects_runbook_without_writing_evidence_kit(): void
    {
        $evidence = sys_get_temp_dir().'/atlas-frontend-provider-packet-read-only-evidence-'.bin2hex(random_bytes(4));

        $payload = app(AtlasFrontendProviderInstructionPacketService::class)->compile([
            'task' => 'Refinar dashboard BlackInk com design premium responsivo',
            'workspace' => $this->readyWorkspace(),
            'provider' => 'codex_cli',
            'evidence_output' => $evidence,
            'write_evidence_kit' => false,
            'acceptance_criteria' => true,
            'test_plan' => true,
            'visual_quality_plan' => true,
            'evidence_plan' => true,
            'senior_design_review' => true,
        ]);

        $this->assertSame('ready', $payload['status']);
        $this->assertTrue((bool) data_get($payload, 'claim_policy.read_only_packet_does_not_write_evidence_kit'));
        $this->assertFileDoesNotExist($evidence.'/evidence-kit-manifest.json');
    }

    public function test_packet_carries_frontend_app_scope_into_provider_sequence(): void
    {
        $payload = app(AtlasFrontendProviderInstructionPacketService::class)->compile([
            'task' => 'Refinar dashboard BlackInk com design premium responsivo',
            'workspace' => $this->readyWorkspace(),
            'frontend_app' => 'apps/web',
            'provider' => 'codex_cli',
            'write_evidence_kit' => false,
            'acceptance_criteria' => true,
            'test_plan' => true,
            'visual_quality_plan' => true,
            'evidence_plan' => true,
            'senior_design_review' => true,
        ]);

        $this->assertSame('ready', $payload['status']);
        $this->assertSame('subscope_selected', data_get($payload, 'frontend_app_scope.status'));
        $this->assertSame('apps/web', data_get($payload, 'frontend_app_scope.relative_name'));
        $this->assertSame(hash('sha256', 'apps/web'), data_get($payload, 'frontend_app_scope.relative_name_hash'));
        $this->assertTrue((bool) data_get($payload, 'frontend_app_scope.repo_workspace_remains_primary'));
        $this->assertSame('subscope_selected', data_get($payload, 'provider_execution_guardrails.selected_workspace_contract.frontend_app_scope_status'));
        $this->assertSame(hash('sha256', 'apps/web'), data_get($payload, 'provider_execution_guardrails.selected_workspace_contract.frontend_app_relative_name_hash'));
        $this->assertFalse((bool) data_get($payload, 'provider_execution_guardrails.selected_workspace_contract.space_runtime_required'));
        $commands = implode("\n", collect($payload['execution_sequence'])->flatMap(fn (array $step): array => $step['commands'])->all());
        $this->assertStringContainsString("cd 'apps/web' && pnpm install --frozen-lockfile", $commands);
        $this->assertStringContainsString("cd 'apps/web' && pnpm run test", $commands);
    }

    public function test_packet_blocks_invalid_frontend_app_scope(): void
    {
        $payload = app(AtlasFrontendProviderInstructionPacketService::class)->compile([
            'task' => 'Refinar dashboard BlackInk com design premium responsivo',
            'workspace' => $this->readyWorkspace(),
            'frontend_app' => '../secrets',
            'provider' => 'codex_cli',
            'write_evidence_kit' => false,
            'acceptance_criteria' => true,
            'test_plan' => true,
            'visual_quality_plan' => true,
            'evidence_plan' => true,
            'senior_design_review' => true,
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('invalid_subscope', data_get($payload, 'frontend_app_scope.status'));
        $this->assertContains('runbook_frontend_app_scope_invalid_relative_frontend_app_subscope', $payload['blockers']);
    }

    private function readyWorkspace(): string
    {
        $workspace = sys_get_temp_dir().'/atlas-frontend-provider-packet-ready-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($workspace.'/src/components/ui');
        File::ensureDirectoryExists($workspace.'/src/pages');
        File::ensureDirectoryExists($workspace.'/apps/web');
        File::put($workspace.'/pnpm-lock.yaml', 'lockfileVersion: 9.0');
        $package = json_encode([
            'scripts' => [
                'dev' => 'vite --host 127.0.0.1',
                'test' => 'vitest run',
                'build' => 'vite build',
                'typecheck' => 'tsc --noEmit',
                'lint' => 'eslint .',
            ],
            'dependencies' => ['react' => '^latest', 'vite' => '^latest', 'tailwindcss' => '^latest'],
        ], JSON_THROW_ON_ERROR);
        File::put($workspace.'/package.json', $package);
        File::put($workspace.'/apps/web/package.json', $package);
        File::put($workspace.'/index.html', '<div id="root"></div>');
        File::put($workspace.'/src/main.tsx', 'import React from "react";');
        File::put($workspace.'/src/pages/Dashboard.tsx', 'export function Dashboard() { return <main className="bg-primary text-white" />; }');
        File::put($workspace.'/src/components/ui/Button.tsx', 'export function Button() { return <button className="bg-primary text-white" />; }');
        File::put($workspace.'/src/styles.css', ':root { --color-primary: #123456; --space-2: 8px; }');

        foreach (app(AtlasFrontendDesignDossierService::class)->requiredDocuments() as $definition) {
            File::ensureDirectoryExists(dirname($workspace.'/'.$definition['path']));
            File::put($workspace.'/'.$definition['path'], $this->filledDocument((string) $definition['title'], (array) $definition['sections']));
        }

        return $workspace;
    }

    /**
     * @param  array<int,string>  $sections
     */
    private function filledDocument(string $title, array $sections): string
    {
        $lines = ['# '.$title, '', 'Status: canonical', ''];
        foreach ($sections as $section) {
            $lines[] = '## '.$section;
            $lines[] = 'Approved frontend operating context for premium company software, including UX constraints, visual principles, measurable quality gates, and release evidence required before delivery.';
            $lines[] = '';
        }

        return implode(PHP_EOL, $lines);
    }
}
