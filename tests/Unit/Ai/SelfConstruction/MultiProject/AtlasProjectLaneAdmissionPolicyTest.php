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
}
