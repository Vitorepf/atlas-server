<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\MultiProject;

use App\Services\Ai\SelfConstruction\MultiProject\AtlasProjectLaneAdmissionPolicy;
use Tests\TestCase;

final class AtlasProjectLaneAdmissionPolicyTest extends TestCase
{
    private function validManifest(): array
    {
        return [
            'project_id'                    => 'atlas-server',
            'repo_root'                     => '/Users/vitorepf/develop/Atlas/atlas-server',
            'objective'                     => 'evolve the atlas-server scope at the highest patamar',
            'mainline_branch'               => 'main',
            'allowed_scope_roots'           => [
                '/Users/vitorepf/develop/Atlas/atlas-server/app',
                '/Users/vitorepf/develop/Atlas/atlas-server/tests',
            ],
            'verification_commands'         => ['php artisan test'],
            'merge_policy'                  => ['mode' => 'shared_main_with_scope_lock'],
            'rollback_policy'               => ['mode' => 'revert_commit'],
            'knowledge_sync_policy'         => ['mode' => 'atlas_engineering_index_after_commit'],
            // Self-Construction proof floor
            'context_freshness_command'     => 'php artisan atlas:context-pack --freshen',
            'queue_namespace'               => 'atlas-server:default',
            'receipt_ledger_path'           => '/Users/vitorepf/develop/Atlas/atlas-server/storage/ledger',
            'rollback_verification_command' => 'php artisan atlas:rollback --verify',
            'steady_state_owner'            => 'atlas_server',
            'autonomy_budget'               => [
                'max_parallel_workers' => 3,
                'max_daily_tasks' => 50,
                'max_risk_band' => 'medium',
            ],
            'provider_dependency_policy'    => 'none',
        ];
    }

    public function test_well_formed_manifest_is_admitted_with_atlas_native_defaults(): void
    {
        $verdict = (new AtlasProjectLaneAdmissionPolicy)->admit($this->validManifest());

        $this->assertTrue($verdict['admitted']);
        $this->assertSame([], $verdict['blocking_reasons']);
        $this->assertSame(AtlasProjectLaneAdmissionPolicy::ISOLATION, $verdict['workspace_policy']['isolation']);
        $this->assertSame('main', $verdict['workspace_policy']['mainline_branch']);

        // Atlas-native autonomy defaults — NEVER human/provider/shell-bound.
        $this->assertFalse($verdict['autonomy_defaults']['requires_human_approval']);
        $this->assertFalse($verdict['autonomy_defaults']['calls_external_providers']);
        $this->assertFalse($verdict['autonomy_defaults']['invokes_shell_or_git']);
    }

    public function test_missing_required_fields_block_admission(): void
    {
        $manifest = $this->validManifest();
        unset($manifest['objective'], $manifest['merge_policy']);

        $verdict = (new AtlasProjectLaneAdmissionPolicy)->admit($manifest);

        $this->assertFalse($verdict['admitted']);
        $this->assertContains('missing_required_field:objective', $verdict['blocking_reasons']);
        $this->assertContains('missing_required_field:merge_policy', $verdict['blocking_reasons']);
        $this->assertContains('objective_empty', $verdict['blocking_reasons']);
    }

    public function test_unsafe_repo_root_is_blocked(): void
    {
        foreach (['/', '/etc', '../sneaky', 'relative/path', '/var/tmp/../../etc'] as $bad) {
            $manifest = $this->validManifest();
            $manifest['repo_root'] = $bad;
            $verdict = (new AtlasProjectLaneAdmissionPolicy)->admit($manifest);
            $this->assertFalse($verdict['admitted'], "expected '$bad' to be unsafe");
            $this->assertContains('unsafe_repo_root', $verdict['blocking_reasons']);
        }
    }

    public function test_empty_allowed_scope_roots_blocks_admission(): void
    {
        $manifest = $this->validManifest();
        $manifest['allowed_scope_roots'] = [];

        $verdict = (new AtlasProjectLaneAdmissionPolicy)->admit($manifest);
        $this->assertFalse($verdict['admitted']);
        $this->assertContains('allowed_scope_roots_empty', $verdict['blocking_reasons']);
    }

    public function test_empty_verification_commands_block_admission(): void
    {
        $manifest = $this->validManifest();
        $manifest['verification_commands'] = [];

        $verdict = (new AtlasProjectLaneAdmissionPolicy)->admit($manifest);
        $this->assertFalse($verdict['admitted']);
        $this->assertContains('verification_commands_empty', $verdict['blocking_reasons']);
    }

    public function test_scope_root_escaping_repo_root_is_blocked(): void
    {
        $manifest = $this->validManifest();
        $manifest['allowed_scope_roots'] = ['/somewhere/else'];

        $verdict = (new AtlasProjectLaneAdmissionPolicy)->admit($manifest);
        $this->assertFalse($verdict['admitted']);
        $this->assertContains('allowed_scope_roots_escape_repo_root', $verdict['blocking_reasons']);
    }

    public function test_policy_is_deterministic_byte_identical_envelope(): void
    {
        $svc = new AtlasProjectLaneAdmissionPolicy;
        $a = $svc->admit($this->validManifest());
        $b = $svc->admit($this->validManifest());

        $this->assertSame(json_encode($a), json_encode($b));
    }

    // ── Self-Construction proof floor ─────────────────────────────────────────

    public function test_missing_context_freshness_command_blocks_admission(): void
    {
        $manifest = $this->validManifest();
        unset($manifest['context_freshness_command']);
        $verdict = (new AtlasProjectLaneAdmissionPolicy)->admit($manifest);

        $this->assertFalse($verdict['admitted']);
        $this->assertContains('missing_required_field:context_freshness_command', $verdict['blocking_reasons']);
    }

    public function test_missing_queue_namespace_blocks_admission(): void
    {
        $manifest = $this->validManifest();
        unset($manifest['queue_namespace']);
        $verdict = (new AtlasProjectLaneAdmissionPolicy)->admit($manifest);

        $this->assertFalse($verdict['admitted']);
        $this->assertContains('missing_required_field:queue_namespace', $verdict['blocking_reasons']);
    }

    public function test_missing_receipt_ledger_path_blocks_admission(): void
    {
        $manifest = $this->validManifest();
        unset($manifest['receipt_ledger_path']);
        $verdict = (new AtlasProjectLaneAdmissionPolicy)->admit($manifest);

        $this->assertFalse($verdict['admitted']);
        $this->assertContains('missing_required_field:receipt_ledger_path', $verdict['blocking_reasons']);
    }

    public function test_missing_rollback_verification_command_blocks_admission(): void
    {
        $manifest = $this->validManifest();
        unset($manifest['rollback_verification_command']);
        $verdict = (new AtlasProjectLaneAdmissionPolicy)->admit($manifest);

        $this->assertFalse($verdict['admitted']);
        $this->assertContains('missing_required_field:rollback_verification_command', $verdict['blocking_reasons']);
    }

    public function test_missing_steady_state_owner_blocks_admission(): void
    {
        $manifest = $this->validManifest();
        unset($manifest['steady_state_owner']);
        $verdict = (new AtlasProjectLaneAdmissionPolicy)->admit($manifest);

        $this->assertFalse($verdict['admitted']);
        $this->assertContains('missing_required_field:steady_state_owner', $verdict['blocking_reasons']);
    }

    public function test_steady_state_owner_not_atlas_server_blocks_admission(): void
    {
        $manifest = $this->validManifest();
        $manifest['steady_state_owner'] = 'human';
        $verdict = (new AtlasProjectLaneAdmissionPolicy)->admit($manifest);

        $this->assertFalse($verdict['admitted']);
        $this->assertContains('steady_state_owner_must_be_atlas_server', $verdict['blocking_reasons']);
    }

    public function test_fully_specified_proof_floor_manifest_is_admitted(): void
    {
        $verdict = (new AtlasProjectLaneAdmissionPolicy)->admit($this->validManifest());

        $this->assertTrue($verdict['admitted']);
        $this->assertSame([], $verdict['blocking_reasons']);
        $this->assertSame(AtlasProjectLaneAdmissionPolicy::ISOLATION, $verdict['workspace_policy']['isolation']);
    }

    // ── dependency flags block admission ─────────────────────────────────────

    public function test_requires_human_flag_blocks_admission(): void
    {
        $manifest = $this->validManifest();
        $manifest['requires_human'] = true;
        $verdict = (new AtlasProjectLaneAdmissionPolicy)->admit($manifest);

        $this->assertFalse($verdict['admitted']);
        $this->assertContains('human_dependency_blocks_admission', $verdict['blocking_reasons']);
    }

    public function test_requires_operator_flag_blocks_admission(): void
    {
        $manifest = $this->validManifest();
        $manifest['requires_operator'] = true;
        $verdict = (new AtlasProjectLaneAdmissionPolicy)->admit($manifest);

        $this->assertFalse($verdict['admitted']);
        $this->assertContains('operator_dependency_blocks_admission', $verdict['blocking_reasons']);
    }

    public function test_calls_external_providers_flag_blocks_admission(): void
    {
        $manifest = $this->validManifest();
        $manifest['calls_external_providers'] = true;
        $verdict = (new AtlasProjectLaneAdmissionPolicy)->admit($manifest);

        $this->assertFalse($verdict['admitted']);
        $this->assertContains('external_provider_dependency_blocks_admission', $verdict['blocking_reasons']);
    }

    // ── AC: autonomy_budget must declare max_parallel_workers, max_daily_tasks, max_risk_band ──

    public function test_missing_autonomy_budget_field_blocks_admission(): void
    {
        $manifest = $this->validManifest();
        unset($manifest['autonomy_budget']);
        $verdict = (new AtlasProjectLaneAdmissionPolicy)->admit($manifest);

        $this->assertFalse($verdict['admitted']);
        $this->assertContains('missing_required_field:autonomy_budget', $verdict['blocking_reasons']);
    }

    public function test_autonomy_budget_missing_max_parallel_workers_blocks_admission(): void
    {
        $manifest = $this->validManifest();
        unset($manifest['autonomy_budget']['max_parallel_workers']);
        $verdict = (new AtlasProjectLaneAdmissionPolicy)->admit($manifest);

        $this->assertFalse($verdict['admitted']);
        $this->assertContains('autonomy_budget_missing:max_parallel_workers', $verdict['blocking_reasons']);
    }

    public function test_autonomy_budget_missing_max_daily_tasks_blocks_admission(): void
    {
        $manifest = $this->validManifest();
        unset($manifest['autonomy_budget']['max_daily_tasks']);
        $verdict = (new AtlasProjectLaneAdmissionPolicy)->admit($manifest);

        $this->assertFalse($verdict['admitted']);
        $this->assertContains('autonomy_budget_missing:max_daily_tasks', $verdict['blocking_reasons']);
    }

    public function test_autonomy_budget_missing_max_risk_band_blocks_admission(): void
    {
        $manifest = $this->validManifest();
        unset($manifest['autonomy_budget']['max_risk_band']);
        $verdict = (new AtlasProjectLaneAdmissionPolicy)->admit($manifest);

        $this->assertFalse($verdict['admitted']);
        $this->assertContains('autonomy_budget_missing:max_risk_band', $verdict['blocking_reasons']);
    }

    // ── AC: context_freshness_command must be Atlas-local, not provider/API bound ──

    public function test_provider_bound_context_freshness_command_blocks_admission(): void
    {
        foreach (['curl https://api.openai.com/v1/models', 'https://anthropic.com/api/context', 'call gpt-4 to refresh context'] as $badCommand) {
            $manifest = $this->validManifest();
            $manifest['context_freshness_command'] = $badCommand;
            $verdict = (new AtlasProjectLaneAdmissionPolicy)->admit($manifest);

            $this->assertFalse($verdict['admitted'], "expected '$badCommand' to be blocked");
            $this->assertContains('context_freshness_command_provider_bound', $verdict['blocking_reasons']);
        }
    }

    public function test_atlas_local_context_freshness_command_is_accepted(): void
    {
        $verdict = (new AtlasProjectLaneAdmissionPolicy)->admit($this->validManifest());
        $this->assertTrue($verdict['admitted']);
    }

    // ── AC: provider_dependency_policy must be 'none' ──────────────────────────

    public function test_missing_provider_dependency_policy_blocks_admission(): void
    {
        $manifest = $this->validManifest();
        unset($manifest['provider_dependency_policy']);
        $verdict = (new AtlasProjectLaneAdmissionPolicy)->admit($manifest);

        $this->assertFalse($verdict['admitted']);
        $this->assertContains('missing_required_field:provider_dependency_policy', $verdict['blocking_reasons']);
    }

    public function test_provider_dependency_policy_not_none_blocks_admission(): void
    {
        $manifest = $this->validManifest();
        $manifest['provider_dependency_policy'] = 'claude_code';
        $verdict = (new AtlasProjectLaneAdmissionPolicy)->admit($manifest);

        $this->assertFalse($verdict['admitted']);
        $this->assertContains('provider_dependency_policy_must_be_none', $verdict['blocking_reasons']);
    }

    // ── AC: admitted verdicts include autonomy_budget and provider_dependency_policy ──

    public function test_admitted_verdict_includes_autonomy_budget_and_provider_dependency_policy(): void
    {
        $verdict = (new AtlasProjectLaneAdmissionPolicy)->admit($this->validManifest());

        $this->assertTrue($verdict['admitted']);
        $this->assertSame([
            'max_parallel_workers' => 3,
            'max_daily_tasks' => 50,
            'max_risk_band' => 'medium',
        ], $verdict['autonomy_budget']);
        $this->assertSame('none', $verdict['provider_dependency_policy']);
    }

    // ── AC: missing owner scope, queue namespace, isolation evidence or knowledge sync readiness blocks admission ──

    public function test_missing_owner_scope_blocks_admission(): void
    {
        $manifest = $this->validManifest();
        $manifest['allowed_scope_roots'] = [];

        $verdict = (new AtlasProjectLaneAdmissionPolicy)->admit($manifest);

        $this->assertFalse($verdict['admitted']);
        $this->assertContains('allowed_scope_roots_empty', $verdict['blockers']);
    }

    public function test_missing_queue_namespace_blocks_admission_with_blockers(): void
    {
        $manifest = $this->validManifest();
        unset($manifest['queue_namespace']);

        $verdict = (new AtlasProjectLaneAdmissionPolicy)->admit($manifest);

        $this->assertFalse($verdict['admitted']);
        $this->assertContains('missing_required_field:queue_namespace', $verdict['blockers']);
    }

    public function test_missing_knowledge_sync_readiness_blocks_admission(): void
    {
        $manifest = $this->validManifest();
        unset($manifest['knowledge_sync_policy']);

        $verdict = (new AtlasProjectLaneAdmissionPolicy)->admit($manifest);

        $this->assertFalse($verdict['admitted']);
        $this->assertContains('missing_required_field:knowledge_sync_policy', $verdict['blockers']);
    }

    // ── AC: admitted lanes use isolation=shared_local_main_with_scope_lock ──

    public function test_admitted_lanes_use_shared_local_main_with_scope_lock(): void
    {
        $verdict = (new AtlasProjectLaneAdmissionPolicy)->admit($this->validManifest());

        $this->assertTrue($verdict['admitted']);
        $this->assertSame('shared_local_main_with_scope_lock', $verdict['workspace_policy']['isolation']);
    }

    // ── AC: admission output includes blockers and required_fields_status ──

    public function test_admission_output_includes_blockers(): void
    {
        $manifest = $this->validManifest();
        unset($manifest['queue_namespace']);

        $verdict = (new AtlasProjectLaneAdmissionPolicy)->admit($manifest);

        $this->assertArrayHasKey('blockers', $verdict);
        $this->assertIsArray($verdict['blockers']);
        $this->assertNotEmpty($verdict['blockers']);
    }

    public function test_admission_output_includes_required_fields_status(): void
    {
        $verdict = (new AtlasProjectLaneAdmissionPolicy)->admit($this->validManifest());

        $this->assertArrayHasKey('required_fields_status', $verdict);
        $this->assertIsArray($verdict['required_fields_status']);

        // All required fields should be present (true) in a valid manifest
        foreach ($verdict['required_fields_status'] as $field => $present) {
            $this->assertTrue($present, "field {$field} should be present in valid manifest");
        }
    }

    public function test_required_fields_status_shows_missing_fields_as_false(): void
    {
        $manifest = $this->validManifest();
        unset($manifest['queue_namespace']);

        $verdict = (new AtlasProjectLaneAdmissionPolicy)->admit($manifest);

        $this->assertArrayHasKey('required_fields_status', $verdict);
        $this->assertFalse($verdict['required_fields_status']['queue_namespace']);
        $this->assertTrue($verdict['required_fields_status']['project_id']);
    }
}
