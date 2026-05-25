<?php

namespace Tests\Unit\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendSkillPackService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasFrontendSkillPackServiceTest extends TestCase
{
    public function test_exports_provider_safe_skill_pack_without_delivery_or_world_best_claim(): void
    {
        $output = sys_get_temp_dir().'/atlas-frontend-skill-pack-'.bin2hex(random_bytes(4));

        $payload = app(AtlasFrontendSkillPackService::class)->export([
            'output' => $output,
        ]);

        $this->assertSame(AtlasFrontendSkillPackService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame('provider_safe_atlas_frontend_operating_skill', $payload['skill_pack_type']);
        $this->assertFileExists($output.'/SKILL.md');
        $this->assertFileExists($output.'/reference/commands.md');
        $this->assertFileExists($output.'/reference/evidence.md');
        $this->assertFileExists($output.'/reference/claim-policy.md');
        $this->assertFileExists($output.'/reference/provider-handoff.md');
        $this->assertFileExists($output.'/manifest.json');
        $this->assertTrue((bool) data_get($payload, 'capability_boundary.portable_provider_instruction_surface'));
        $this->assertTrue((bool) data_get($payload, 'capability_boundary.skill_pack_is_not_execution_evidence'));
        $this->assertFalse((bool) data_get($payload, 'capability_boundary.raw_customer_source_returned'));
        $this->assertFalse((bool) data_get($payload, 'capability_boundary.world_best_claim_allowed'));
        $this->assertSame(AtlasFrontendSkillPackService::RUNTIME_GUARDRAILS_SCHEMA_VERSION, data_get($payload, 'runtime_guardrails.schema_version'));
        $this->assertTrue((bool) data_get($payload, 'runtime_guardrails.selected_workspace_contract.scan_folder_for_repositories_before_selection'));
        $this->assertTrue((bool) data_get($payload, 'runtime_guardrails.selected_workspace_contract.operator_selected_repository_is_primary_workspace'));
        $this->assertTrue((bool) data_get($payload, 'runtime_guardrails.selected_workspace_contract.frontend_app_is_optional_subscope_not_space'));
        $this->assertFalse((bool) data_get($payload, 'runtime_guardrails.selected_workspace_contract.space_runtime_required'));
        $this->assertContains('atlas:frontend:provider-packet', data_get($payload, 'runtime_guardrails.authoritative_runtime_commands'));
        $this->assertContains('atlas_frontend_browser_detector_event', data_get($payload, 'runtime_guardrails.mandatory_detector_receipts'));
        $this->assertSame(2, data_get($payload, 'runtime_guardrails.claim_boundary.minimum_decisive_lead_points'));
        $this->assertContains('install_or_attach_skill_pack_to_provider_then_run_provider_packet_or_proof_pilot', $payload['required_next_actions']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $payload['skill_pack_hash']);

        $skill = file_get_contents($output.'/SKILL.md') ?: '';
        $this->assertStringContainsString('Atlas Frontend', $skill);
        $this->assertStringContainsString('Never claim world-best', $skill);
        $this->assertStringContainsString('operator-selected repository is the workspace', $skill);
        $this->assertStringContainsString('never a Space or separate selected workspace', $skill);
        $this->assertStringContainsString('browser-side detector events', $skill);
        $this->assertStringContainsString('atlas:frontend:proof pilot', file_get_contents($output.'/reference/commands.md') ?: '');
        $this->assertStringContainsString('Templates from `atlas:frontend:evidence-kit` are scaffolds', file_get_contents($output.'/reference/evidence.md') ?: '');
    }

    public function test_installs_skill_pack_inside_company_repo_without_delivery_claim(): void
    {
        $workspace = sys_get_temp_dir().'/atlas-frontend-skill-pack-install-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($workspace);

        $payload = app(AtlasFrontendSkillPackService::class)->install([
            'workspace' => $workspace,
        ]);

        $this->assertSame(AtlasFrontendSkillPackService::INSTALL_SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('installed', $payload['status']);
        $this->assertSame('company_repo_atlas_frontend_skill_install', $payload['install_type']);
        $this->assertSame('.atlas/skills/atlas-frontend/SKILL.md', data_get($payload, 'provider_activation.skill_path'));
        $this->assertTrue((bool) data_get($payload, 'provider_activation.provider_should_read_before_frontend_edits'));
        $this->assertTrue((bool) data_get($payload, 'provider_activation.provider_packet_required_before_frontend_edits'));
        $this->assertTrue((bool) data_get($payload, 'provider_activation.selected_repo_is_workspace_frontend_app_is_subscope'));
        $this->assertTrue((bool) data_get($payload, 'claim_policy.install_is_not_execution_evidence'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.world_best_claim_allowed'));
        $this->assertFileExists($workspace.'/.atlas/skills/atlas-frontend/SKILL.md');
        $this->assertFileExists($workspace.'/.atlas/skills/atlas-frontend/manifest.json');
        $this->assertFileExists($workspace.'/.atlas/skills/atlas-frontend/install-receipt.json');
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $payload['skill_pack_install_hash']);
    }

    public function test_install_blocks_missing_workspace(): void
    {
        $payload = app(AtlasFrontendSkillPackService::class)->install([
            'workspace' => sys_get_temp_dir().'/atlas-frontend-skill-pack-install-missing-'.bin2hex(random_bytes(4)),
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('workspace_not_found', $payload['blockers']);
        $this->assertContains('skill_pack_export_not_ready', $payload['blockers']);
    }
}
