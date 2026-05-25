<?php

namespace Tests\Unit\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendControlPlaneService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendProductProofRuntimeService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendRivalReplayHarnessService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasFrontendControlPlaneServiceTest extends TestCase
{
    public function test_control_plane_allows_governed_runtime_claim_but_blocks_world_best_without_real_replay_and_public_receipt(): void
    {
        $payload = app(AtlasFrontendControlPlaneService::class)->snapshot();

        $this->assertSame(AtlasFrontendControlPlaneService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('warning', $payload['status']);
        $this->assertTrue((bool) data_get($payload, 'readiness_levels.runtime_contract_ready'));
        $this->assertTrue((bool) data_get($payload, 'claim_policy.may_claim_more_complete_than_impeccable'));
        $this->assertTrue((bool) data_get($payload, 'claim_policy.may_claim_more_complete_than_claude_design_plugin'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.world_best_claim_allowed'));
        $this->assertTrue((bool) data_get($payload, 'claim_policy.local_publication_report_is_not_public_distribution'));
        $this->assertSame('not_requested', data_get($payload, 'readiness_levels.publication_attestation_status'));
        $this->assertSame('not_requested', data_get($payload, 'signals.publication_attestation.status'));
        $this->assertTrue((bool) data_get($payload, 'signals.publication_attestation.claim_policy.local_bundle_is_not_public_distribution'));
        $this->assertContains('external_rival_replay_not_completed', $payload['warnings']);
        $this->assertContains('public_distribution_receipt_not_verified', $payload['warnings']);
        $this->assertContains('do_not_claim_world_best_until_replay_and_public_distribution_are_verified', $payload['required_next_actions']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $payload['control_plane_hash']);
    }

    public function test_control_plane_attests_local_bundle_without_public_distribution_claim(): void
    {
        $bundle = sys_get_temp_dir().'/atlas-frontend-control-plane-bundle-'.bin2hex(random_bytes(4));
        app(AtlasFrontendProductProofRuntimeService::class)->buildStaticBundle($bundle);

        $payload = app(AtlasFrontendControlPlaneService::class)->snapshot([
            'bundle' => $bundle,
        ]);

        $this->assertSame('warning', $payload['status']);
        $this->assertFalse((bool) data_get($payload, 'claim_policy.public_distribution_claim_allowed'));
        $this->assertSame('local_bundle_ready_publication_pending', data_get($payload, 'readiness_levels.publication_attestation_status'));
        $this->assertSame('local_bundle_ready_publication_pending', data_get($payload, 'signals.publication_attestation.status'));
        $this->assertFalse((bool) data_get($payload, 'signals.publication_attestation.claim_policy.public_distribution_claim_allowed'));
        $this->assertContains('provide_operator_approved_publication_receipt', data_get($payload, 'signals.publication_attestation.required_next_actions'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) data_get($payload, 'signals.publication_attestation.attestation_hash'));
    }

    public function test_control_plane_passes_rival_evidence_directory_into_benchmark_signal(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-control-plane-rival-evidence-'.bin2hex(random_bytes(4));
        app(AtlasFrontendRivalReplayHarnessService::class)->writeTemplate($dir);

        $payload = app(AtlasFrontendControlPlaneService::class)->snapshot([
            'rival_evidence' => $dir,
        ]);

        $this->assertSame(hash('sha256', $dir), data_get($payload, 'input_scope.rival_evidence_directory_hash'));
        $this->assertTrue((bool) data_get($payload, 'signals.benchmark.scope.rival_evidence_directory_supplied'));
        $this->assertSame(hash('sha256', $dir), data_get($payload, 'signals.benchmark.scope.rival_evidence_directory_hash'));
        $this->assertSame('ready_for_replay', data_get($payload, 'signals.rival_replay.status'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.world_best_claim_allowed'));
    }

    public function test_control_plane_blocks_company_dispatch_when_gauntlet_fails(): void
    {
        $workspace = sys_get_temp_dir().'/atlas-frontend-control-plane-missing-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($workspace);

        $payload = app(AtlasFrontendControlPlaneService::class)->snapshot([
            'task' => 'Criar redesign premium SaaS novo',
            'workspace' => $workspace,
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertFalse((bool) data_get($payload, 'claim_policy.provider_dispatch_allowed'));
        $this->assertContains('company_repo_gauntlet_blocked', $payload['blockers']);
        $this->assertContains('fill_company_design_dossier_docs', $payload['required_next_actions']);
        $this->assertContains('generate_or_write_product_blueprint', $payload['required_next_actions']);
    }

    public function test_control_plane_carries_frontend_app_scope_into_gauntlet_signal(): void
    {
        $workspace = sys_get_temp_dir().'/atlas-frontend-control-plane-monorepo-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($workspace.'/apps/web');

        $payload = app(AtlasFrontendControlPlaneService::class)->snapshot([
            'task' => 'Ajustar checkout web',
            'workspace' => $workspace,
            'frontend_app' => 'apps/web',
        ]);

        $this->assertSame(hash('sha256', 'apps/web'), data_get($payload, 'input_scope.frontend_app_hash'));
        $this->assertSame('subscope_selected', data_get($payload, 'signals.gauntlet.frontend_app_scope.status'));
        $this->assertSame('apps/web', data_get($payload, 'signals.gauntlet.frontend_app_scope.relative_name'));
        $this->assertTrue((bool) data_get($payload, 'signals.gauntlet.frontend_app_scope.repo_workspace_remains_primary'));
        $this->assertStringNotContainsString($workspace.'/apps/web', json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
