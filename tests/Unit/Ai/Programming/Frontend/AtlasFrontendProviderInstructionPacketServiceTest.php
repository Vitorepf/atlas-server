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
        $this->assertContains('preserve_repo_design_system_and_product_intent', $payload['provider_mandates']);
        $this->assertContains('never_claim_world_best_or_done_without_certified_evidence', $payload['provider_mandates']);
        $this->assertContains('generic_landing_page_when_product_context_exists', $payload['forbidden_provider_behaviors']);
        $this->assertContains('screenshot_only_completion_claim', $payload['forbidden_provider_behaviors']);
        $this->assertContains('visual_quality_report', $payload['required_evidence']);
        $this->assertContains('run_certification_hash', $payload['required_evidence']);
        $this->assertTrue((bool) data_get($payload, 'claim_policy.provider_must_return_receipts_not_claims'));
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

    private function readyWorkspace(): string
    {
        $workspace = sys_get_temp_dir().'/atlas-frontend-provider-packet-ready-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($workspace.'/src/components/ui');
        File::ensureDirectoryExists($workspace.'/src/pages');
        File::put($workspace.'/pnpm-lock.yaml', 'lockfileVersion: 9.0');
        File::put($workspace.'/package.json', json_encode([
            'scripts' => [
                'dev' => 'vite --host 127.0.0.1',
                'test' => 'vitest run',
                'build' => 'vite build',
                'typecheck' => 'tsc --noEmit',
                'lint' => 'eslint .',
            ],
            'dependencies' => ['react' => '^latest', 'vite' => '^latest', 'tailwindcss' => '^latest'],
        ], JSON_THROW_ON_ERROR));
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
