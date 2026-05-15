<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneScopeLockRuntimeValidator;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketBuilder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasAiSelfConstructionAgentControlPlaneScopeLockRuntimeValidatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_constants_canonical(): void
    {
        $this->assertSame('atlas.self_construction.agent_control_plane_scope_lock_runtime_validation.v1', AgentControlPlaneScopeLockRuntimeValidator::SCHEMA_VERSION);
        $this->assertSame('persistent_local_agent_control_plane_scope_lock_runtime_validator', AgentControlPlaneScopeLockRuntimeValidator::MODE);
        $this->assertArrayHasKey('self_improvement', AgentControlPlaneScopeLockRuntimeValidator::FORBIDDEN_AXES);
        $this->assertArrayHasKey('programming', AgentControlPlaneScopeLockRuntimeValidator::FORBIDDEN_AXES);
        $this->assertArrayHasKey('atlas_code_controllers', AgentControlPlaneScopeLockRuntimeValidator::FORBIDDEN_AXES);
        $this->assertArrayHasKey('routes_api', AgentControlPlaneScopeLockRuntimeValidator::FORBIDDEN_AXES);
        $this->assertArrayHasKey('atlas_desktop', AgentControlPlaneScopeLockRuntimeValidator::FORBIDDEN_AXES);
        $this->assertArrayHasKey('forge', AgentControlPlaneScopeLockRuntimeValidator::FORBIDDEN_AXES);
        $this->assertArrayHasKey('rivals', AgentControlPlaneScopeLockRuntimeValidator::FORBIDDEN_AXES);
        $this->assertArrayHasKey('cartografia', AgentControlPlaneScopeLockRuntimeValidator::FORBIDDEN_AXES);
        $this->assertArrayHasKey('voice', AgentControlPlaneScopeLockRuntimeValidator::FORBIDDEN_AXES);
    }

    public function test_valid_scope(): void
    {
        $packet = $this->packet();
        $result = (new AgentControlPlaneScopeLockRuntimeValidator)->validate($packet);
        $this->assertSame('valid', $result['status']);
        $this->assertSame([], $result['blockers']);
        $this->assertNotEmpty($result['scope_lock_hash']);
        $this->assertNotEmpty($result['validation_hash']);
        $this->assertFalse($result['runtime_execution_allowed']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertFalse($result['provider_call_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['self_programming_allowed']);
        $this->assertFalse($result['ledger_write_allowed']);
        $this->assertFalse($result['completion_real_allowed']);
    }

    public function test_empty_allowed_blocks(): void
    {
        $packet = $this->packetWith(['allowed_files' => [], 'scope_in' => []]);
        $result = (new AgentControlPlaneScopeLockRuntimeValidator)->validate($packet);
        $this->assertSame('blocked', $result['status']);
        $this->assertContains('allowed_files_empty', $result['blockers']);
    }

    public function test_forbidden_overlap_blocks(): void
    {
        $packet = $this->packetWith([
            'allowed_files' => ['app/Services/Ai/SelfConstruction/A.php', 'app/Services/Ai/SelfConstruction/B.php'],
            'forbidden_files' => ['app/Services/Ai/SelfConstruction/B.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/A.php'],
        ]);
        $result = (new AgentControlPlaneScopeLockRuntimeValidator)->validate($packet);
        $this->assertSame('blocked', $result['status']);
        $this->assertContains('forbidden_overlap', $result['blockers']);
    }

    public function test_path_traversal_blocks(): void
    {
        $packet = $this->packet();
        $packet['normalized_scope']['allowed_files'] = ['../etc/passwd'];
        $result = (new AgentControlPlaneScopeLockRuntimeValidator)->validate($packet);
        $this->assertSame('blocked', $result['status']);
        $this->assertContains('path_traversal', $result['blockers']);
    }

    public function test_self_improvement_axis_blocks(): void
    {
        $this->axisBlocked('app/Services/Ai/SelfImprovement/Foo.php');
    }

    public function test_programming_axis_blocks(): void
    {
        $this->axisBlocked('app/Services/Ai/Programming/Foo.php');
    }

    public function test_atlas_code_controller_axis_blocks(): void
    {
        $this->axisBlocked('app/Http/Controllers/AtlasCode/Foo.php');
    }

    public function test_routes_api_blocks(): void
    {
        $this->axisBlocked('routes/api.php');
    }

    public function test_atlas_desktop_axis_blocks(): void
    {
        $this->axisBlocked('atlas-desktop/src/App.tsx');
    }

    public function test_forge_axis_blocks(): void
    {
        $this->axisBlocked('forge/Foo.php');
    }

    public function test_rivals_axis_blocks(): void
    {
        $this->axisBlocked('rivals/Bar.php');
    }

    public function test_cartografia_axis_blocks(): void
    {
        $this->axisBlocked('cartografia/Map.tsx');
    }

    public function test_voice_axis_blocks(): void
    {
        $this->axisBlocked('voice/voice.php');
    }

    public function test_max_files_limit(): void
    {
        $packet = $this->packet();
        $packet['normalized_scope']['allowed_files'] = array_map(fn (int $i) => "app/X{$i}.php", range(1, 30));
        $result = (new AgentControlPlaneScopeLockRuntimeValidator)->validate($packet, ['max_files' => 10]);
        $this->assertSame('blocked', $result['status']);
        $this->assertContains('too_many_allowed_files', $result['blockers']);
    }

    public function test_rollback_required(): void
    {
        $packet = $this->packet();
        $packet['rollback_requirements']['rollback_strategy'] = '';
        $result = (new AgentControlPlaneScopeLockRuntimeValidator)->validate($packet);
        $this->assertSame('blocked', $result['status']);
        $this->assertContains('rollback_strategy_missing', $result['blockers']);
    }

    public function test_continuation_required(): void
    {
        $packet = $this->packet();
        $packet['continuation_requirements']['continuation_required'] = false;
        $result = (new AgentControlPlaneScopeLockRuntimeValidator)->validate($packet);
        $this->assertSame('blocked', $result['status']);
        $this->assertContains('continuation_summary_required', $result['blockers']);
    }

    public function test_evidence_required(): void
    {
        $packet = $this->packet();
        $packet['evidence_requirements']['required'] = [];
        $result = (new AgentControlPlaneScopeLockRuntimeValidator)->validate($packet);
        $this->assertSame('blocked', $result['status']);
        $this->assertContains('evidence_requirements_missing', $result['blockers']);
    }

    public function test_high_risk_blocks_runtime_claim(): void
    {
        $packet = $this->packet();
        $packet['risk_classification']['risk_level'] = 'high';
        $result = (new AgentControlPlaneScopeLockRuntimeValidator)->validate($packet);
        $this->assertSame('blocked', $result['status']);
        $this->assertContains('risk_level_too_high_for_runtime_claim', $result['blockers']);
    }

    public function test_blocked_packet_not_planned(): void
    {
        $packet = $this->packet();
        $packet['status'] = 'blocked';
        $result = (new AgentControlPlaneScopeLockRuntimeValidator)->validate($packet);
        $this->assertSame('blocked', $result['status']);
        $this->assertContains('task_packet_not_planned', $result['blockers']);
    }

    public function test_non_execution_guarantees(): void
    {
        $packet = $this->packet();
        $result = (new AgentControlPlaneScopeLockRuntimeValidator)->validate($packet);
        foreach ([
            'scope_lock_runtime_validator_does_not_start_codex',
            'scope_lock_runtime_validator_does_not_call_codex_cli_or_app',
            'scope_lock_runtime_validator_does_not_spawn_subprocess',
            'scope_lock_runtime_validator_does_not_invoke_adapter',
            'scope_lock_runtime_validator_does_not_call_provider',
            'scope_lock_runtime_validator_does_not_dispatch_work',
            'scope_lock_runtime_validator_does_not_spend_tokens',
            'scope_lock_runtime_validator_does_not_enable_self_programming',
            'scope_lock_runtime_validator_does_not_write_ledger',
            'scope_lock_runtime_validator_does_not_persist_lease',
            'scope_lock_runtime_validator_does_not_mutate_pointer',
        ] as $expected) {
            $this->assertContains($expected, $result['non_execution_guarantees']);
        }
    }

    public function test_cli_status_returns_payload(): void
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-scope-lock-runtime-validator-status' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('atlas.self_construction_agent_control_plane_scope_lock_runtime_validator_status.v1', $payload['schema_version']);
        $this->assertSame('valid', data_get($payload, 'agent_control_plane_scope_lock_runtime_validator_status.status'));
    }

    public function test_cli_quartet_works(): void
    {
        foreach (['contract', 'preflight', 'implementation-packet'] as $stage) {
            Artisan::call('atlas:ai:self-construction', [
                "--agent-control-plane-scope-lock-runtime-validator-{$stage}" => true,
                '--json' => true,
            ]);
            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $stageKey = str_replace('-', '_', $stage);
            $this->assertSame("atlas.self_construction_agent_control_plane_scope_lock_runtime_validator_{$stageKey}.v1", $payload['schema_version']);
        }
    }

    public function test_validation_hash_stable(): void
    {
        $svc = new AgentControlPlaneScopeLockRuntimeValidator;
        $packet = $this->packet();
        $a = $svc->validate($packet);
        $b = $svc->validate($packet);
        $this->assertSame($a['scope_lock_hash'], $b['scope_lock_hash']);
        $this->assertSame($a['validation_hash'], $b['validation_hash']);
    }

    public function test_full_validation_payload_shape(): void
    {
        $result = (new AgentControlPlaneScopeLockRuntimeValidator)->validate($this->packet());
        foreach ([
            'schema_version', 'mode', 'generated_at', 'status', 'task_packet_id', 'task_packet_hash',
            'blockers', 'warnings', 'normalized_scope_lock', 'scope_lock_hash',
            'forbidden_axis_count', 'traversal_count', 'forbidden_overlap_count',
            'allowed_file_count', 'write_set_count', 'read_set_count', 'max_files',
            'runtime_execution_allowed', 'dispatch_allowed', 'provider_call_allowed',
            'token_spend_allowed', 'self_programming_allowed', 'ledger_write_allowed',
            'completion_real_allowed', 'non_execution_guarantees', 'human_summary', 'validation_hash',
        ] as $key) {
            $this->assertArrayHasKey($key, $result, "Missing $key");
        }
        foreach ([
            'allowed_files', 'forbidden_files', 'scope_in', 'scope_out',
            'write_set', 'read_set', 'risk_level', 'rollback_strategy',
            'continuation_required', 'evidence_requirements',
            'forbidden_axis_hits', 'path_traversal', 'forbidden_in_allowed',
        ] as $key) {
            $this->assertArrayHasKey($key, $result['normalized_scope_lock'], "Missing scope $key");
        }
    }

    public function test_unknown_risk_level_warns(): void
    {
        $packet = $this->packet();
        $packet['risk_classification']['risk_level'] = 'unobtanium';
        $result = (new AgentControlPlaneScopeLockRuntimeValidator)->validate($packet);
        $this->assertContains('risk_level_unknown_defaulting_low', $result['warnings']);
        $this->assertSame('low', $result['normalized_scope_lock']['risk_level']);
    }

    public function test_traversal_constants(): void
    {
        $this->assertContains('../', AgentControlPlaneScopeLockRuntimeValidator::TRAVERSAL_NEEDLES);
        $this->assertContains('/..', AgentControlPlaneScopeLockRuntimeValidator::TRAVERSAL_NEEDLES);
        $this->assertContains('..\\', AgentControlPlaneScopeLockRuntimeValidator::TRAVERSAL_NEEDLES);
    }

    public function test_allowed_risk_constants(): void
    {
        $this->assertContains('low', AgentControlPlaneScopeLockRuntimeValidator::ALLOWED_RISK_LEVELS);
        $this->assertContains('medium', AgentControlPlaneScopeLockRuntimeValidator::ALLOWED_RISK_LEVELS);
        $this->assertContains('high', AgentControlPlaneScopeLockRuntimeValidator::ALLOWED_RISK_LEVELS);
        $this->assertContains('critical', AgentControlPlaneScopeLockRuntimeValidator::ALLOWED_RISK_LEVELS);
        $this->assertContains('high', AgentControlPlaneScopeLockRuntimeValidator::ALLOWED_RISK_LEVELS_BLOCKED);
        $this->assertContains('critical', AgentControlPlaneScopeLockRuntimeValidator::ALLOWED_RISK_LEVELS_BLOCKED);
    }

    private function axisBlocked(string $path): void
    {
        $packet = $this->packetWith([
            'allowed_files' => [$path],
            'scope_in' => [$path],
        ]);
        $result = (new AgentControlPlaneScopeLockRuntimeValidator)->validate($packet);
        $this->assertSame('blocked', $result['status']);
        $this->assertContains('forbidden_axis', $result['blockers']);
        $this->assertGreaterThan(0, $result['forbidden_axis_count']);
    }

    /**
     * @return array<string, mixed>
     */
    private function packet(): array
    {
        return (new AgentControlPlaneTaskPacketBuilder)->build([
            'objective' => 'scope validator test',
            'operator_id' => 'tester',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/Foo.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/Foo.php'],
            'acceptance_criteria' => ['ok'],
            'required_evidence' => ['task_packet_created'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function packetWith(array $overrides): array
    {
        return (new AgentControlPlaneTaskPacketBuilder)->build(array_merge([
            'objective' => 'scope validator test variant',
            'operator_id' => 'tester',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/Foo.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/Foo.php'],
            'acceptance_criteria' => ['ok'],
            'required_evidence' => ['task_packet_created'],
        ], $overrides));
    }
}
