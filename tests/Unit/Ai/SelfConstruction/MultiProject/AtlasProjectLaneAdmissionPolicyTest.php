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
            'project_id' => 'atlas-server',
            'repo_root' => '/Users/vitorepf/develop/Atlas/atlas-server',
            'objective' => 'evolve the atlas-server scope at the highest patamar',
            'mainline_branch' => 'main',
            'allowed_scope_roots' => [
                '/Users/vitorepf/develop/Atlas/atlas-server/app',
                '/Users/vitorepf/develop/Atlas/atlas-server/tests',
            ],
            'verification_commands' => ['php artisan test'],
            'merge_policy' => ['mode' => 'shared_main_with_scope_lock'],
            'rollback_policy' => ['mode' => 'revert_commit'],
            'knowledge_sync_policy' => ['mode' => 'atlas_engineering_index_after_commit'],
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
}
