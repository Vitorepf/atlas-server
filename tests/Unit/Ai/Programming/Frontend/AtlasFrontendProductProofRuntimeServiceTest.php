<?php

namespace Tests\Unit\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendDesignDossierService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendProductProofRuntimeService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasFrontendProductProofRuntimeServiceTest extends TestCase
{
    public function test_catalog_defines_multi_company_demo_proofs_without_public_claim(): void
    {
        $payload = app(AtlasFrontendProductProofRuntimeService::class)->catalog();

        $this->assertSame('atlas.frontend.product_proof_runtime.v1', $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame('local_product_demo_catalog', $payload['proof_type']);
        $this->assertSame(5, $payload['demo_count']);
        $this->assertContains('external_hosted_product_site_required_for_public_distribution_claim', $payload['remaining_gaps']);
        $this->assertNotContains('local_demo_catalog_missing_required_proofs', $payload['remaining_gaps']);
        $this->assertTrue((bool) data_get($payload, 'publication_policy.public_site_claim_requires_hosted_demo'));
        $this->assertTrue((bool) data_get($payload, 'publication_policy.local_catalog_is_not_public_distribution'));
        $this->assertFalse((bool) data_get($payload, 'publication_policy.raw_customer_source_returned'));
        $this->assertContains('live_mode_repair_loop', collect($payload['demos'])->pluck('id')->all());
        $this->assertSame('ready', data_get($payload, 'checks.0.status'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $payload['product_proof_hash']);
    }

    public function test_build_static_bundle_creates_publishable_local_artifacts_without_hosting_claim(): void
    {
        $output = sys_get_temp_dir().'/atlas-frontend-proof-bundle-'.bin2hex(random_bytes(4));

        $payload = app(AtlasFrontendProductProofRuntimeService::class)->buildStaticBundle($output);

        $this->assertSame('atlas.frontend.product_proof_bundle.v1', $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertTrue((bool) data_get($payload, 'publication_policy.publishable_static_bundle_created'));
        $this->assertFalse((bool) data_get($payload, 'publication_policy.external_hosting_verified'));
        $this->assertFileExists($output.'/index.html');
        $this->assertFileExists($output.'/manifest.json');
        $this->assertCount(5, $payload['assets']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $payload['bundle_hash']);
    }

    public function test_pilot_dossier_prepares_real_repo_execution_without_done_or_world_best_claim(): void
    {
        $workspace = $this->readyWorkspace('atlas-frontend-proof-pilot-ready');
        $output = sys_get_temp_dir().'/atlas-frontend-proof-pilot-output-'.bin2hex(random_bytes(4));

        $payload = app(AtlasFrontendProductProofRuntimeService::class)->pilotDossier([
            'task' => 'Refinar BlackInk com dashboard premium responsivo',
            'workspace' => $workspace,
            'provider' => 'codex_cli',
            'output' => $output,
            'acceptance_criteria' => true,
            'test_plan' => true,
            'visual_quality_plan' => true,
            'evidence_plan' => true,
            'senior_design_review' => true,
        ]);

        $this->assertSame(AtlasFrontendProductProofRuntimeService::PILOT_DOSSIER_SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready_for_operator_execution', $payload['status']);
        $this->assertSame('company_repo_frontend_pilot_dossier', $payload['proof_type']);
        $this->assertTrue((bool) data_get($payload, 'readiness.provider_packet_ready'));
        $this->assertTrue((bool) data_get($payload, 'readiness.runbook_ready'));
        $this->assertFalse((bool) data_get($payload, 'readiness.measured_evidence_present'));
        $this->assertFalse((bool) data_get($payload, 'readiness.world_best_claim_allowed'));
        $this->assertContains('never_claim_world_best_or_done_without_certified_evidence', data_get($payload, 'execution_contract.provider_mandates'));
        $this->assertContains('run_real_rival_replay_before_world_best_claim', $payload['required_next_actions']);
        $this->assertTrue((bool) data_get($payload, 'claim_policy.ready_for_operator_execution_is_not_delivery_done'));
        $this->assertFileExists($output.'/pilot-dossier.json');
        $this->assertFileExists($output.'/evidence-kit/evidence-kit-manifest.json');
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $payload['pilot_dossier_hash']);
    }

    public function test_pilot_dossier_blocks_when_repo_context_cannot_support_provider_execution(): void
    {
        $payload = app(AtlasFrontendProductProofRuntimeService::class)->pilotDossier([
            'task' => 'Criar tela bonita',
            'workspace' => sys_get_temp_dir().'/atlas-frontend-proof-pilot-missing-'.bin2hex(random_bytes(4)),
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('provider_packet_work_order_phase_blocked_pre_execution_gate', $payload['blockers']);
        $this->assertContains('runbook_repo_workspace_not_found', $payload['blockers']);
        $this->assertFalse((bool) data_get($payload, 'claim_policy.world_best_claim_allowed'));
    }

    private function readyWorkspace(string $prefix): string
    {
        $workspace = sys_get_temp_dir().'/'.$prefix.'-'.bin2hex(random_bytes(4));
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
