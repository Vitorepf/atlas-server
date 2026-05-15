<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneRuntimePilotCertificationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasAiSelfConstructionAgentControlPlaneRuntimePilotCertificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_constants_canonical(): void
    {
        $this->assertSame('atlas.self_construction.agent_control_plane_runtime_pilot_certification.v1', AgentControlPlaneRuntimePilotCertificationService::SCHEMA_VERSION);
        $this->assertSame('read_only_agent_control_plane_runtime_pilot_certification', AgentControlPlaneRuntimePilotCertificationService::MODE);
    }

    public function test_valid_pilot_passes(): void
    {
        $pilot = $this->validPilot();
        $cert = (new AgentControlPlaneRuntimePilotCertificationService)->certify($pilot);
        $this->assertSame('available', $cert['status']);
        $this->assertSame(0, $cert['failed_count']);
        $this->assertSame($cert['check_count'], $cert['passed_count']);
    }

    public function test_invalid_task_blocks(): void
    {
        $pilot = $this->validPilot();
        $pilot['task_packet']['status'] = 'blocked';
        $pilot['task_packet']['task_packet_hash'] = '';
        $cert = (new AgentControlPlaneRuntimePilotCertificationService)->certify($pilot);
        $this->assertContains($cert['status'], ['blocked', 'warning']);
        $this->assertContains('task_packet_invalid_or_blocked', $cert['failures']);
    }

    public function test_unsafe_scope_blocks(): void
    {
        $pilot = $this->validPilot();
        $pilot['scope_lock_plan']['status'] = 'planned_blocked';
        $pilot['scope_lock_plan']['cross_axis_blockers'] = [['axis' => 'x', 'path' => 'y']];
        $cert = (new AgentControlPlaneRuntimePilotCertificationService)->certify($pilot);
        $this->assertSame('blocked', $cert['status']);
        $this->assertContains('scope_lock_unsafe', $cert['failures']);
    }

    public function test_runtime_true_blocks(): void
    {
        $pilot = $this->validPilot();
        $pilot['runtime_execution_allowed'] = true;
        $cert = (new AgentControlPlaneRuntimePilotCertificationService)->certify($pilot);
        $this->assertSame('blocked', $cert['status']);
        $this->assertContains('runtime_safety_flag_true', $cert['failures']);
    }

    public function test_ledger_write_planned_blocks(): void
    {
        $pilot = $this->validPilot();
        $pilot['evidence_ledger_dry_run']['ledger_write_allowed'] = true;
        $cert = (new AgentControlPlaneRuntimePilotCertificationService)->certify($pilot);
        $this->assertSame('blocked', $cert['status']);
        $this->assertContains('ledger_write_allowed_true', $cert['failures']);
    }

    public function test_provider_call_true_blocks(): void
    {
        $pilot = $this->validPilot();
        $pilot['provider_call_allowed'] = true;
        $cert = (new AgentControlPlaneRuntimePilotCertificationService)->certify($pilot);
        $this->assertSame('blocked', $cert['status']);
        $this->assertContains('provider_call_allowed_true', $cert['failures']);
    }

    public function test_dispatch_true_blocks(): void
    {
        $pilot = $this->validPilot();
        $pilot['dispatch_allowed'] = true;
        $cert = (new AgentControlPlaneRuntimePilotCertificationService)->certify($pilot);
        $this->assertSame('blocked', $cert['status']);
        $this->assertContains('dispatch_allowed_true', $cert['failures']);
    }

    public function test_self_programming_blocks(): void
    {
        $pilot = $this->validPilot();
        $pilot['self_programming_allowed'] = true;
        $cert = (new AgentControlPlaneRuntimePilotCertificationService)->certify($pilot);
        $this->assertSame('blocked', $cert['status']);
        $this->assertContains('self_programming_allowed_true', $cert['failures']);
    }

    public function test_certification_hash_stable(): void
    {
        $pilot = $this->validPilot();
        $svc = new AgentControlPlaneRuntimePilotCertificationService;
        $a = $svc->certify($pilot);
        $b = $svc->certify($pilot);
        $this->assertSame($a['certification_hash'], $b['certification_hash']);
        $this->assertNotSame($a['certification_id'], $b['certification_id']);
    }

    public function test_cli_status_returns_payload(): void
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-runtime-pilot-certification-status' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('atlas.self_construction_agent_control_plane_runtime_pilot_certification_status.v1', $payload['schema_version']);
        $this->assertSame('available', data_get($payload, 'agent_control_plane_runtime_pilot_certification_status.status'));
        $this->assertGreaterThanOrEqual(16, (int) data_get($payload, 'agent_control_plane_runtime_pilot_certification_status.check_count'));
    }

    public function test_cli_quartet_works(): void
    {
        foreach (['contract', 'preflight', 'implementation-packet'] as $stage) {
            Artisan::call('atlas:ai:self-construction', [
                "--agent-control-plane-runtime-pilot-certification-{$stage}" => true,
                '--json' => true,
            ]);
            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $stageKey = str_replace('-', '_', $stage);
            $this->assertSame(
                "atlas.self_construction_agent_control_plane_runtime_pilot_certification_{$stageKey}.v1",
                $payload['schema_version'],
            );
        }
    }

    public function test_runtime_flags_false(): void
    {
        $pilot = $this->validPilot();
        $cert = (new AgentControlPlaneRuntimePilotCertificationService)->certify($pilot);
        $this->assertFalse($cert['execution_allowed']);
        $this->assertFalse($cert['dispatch_allowed']);
        $this->assertFalse($cert['ledger_write_allowed']);
        $this->assertFalse($cert['provider_call_allowed']);
        $this->assertFalse($cert['self_programming_allowed']);
    }

    public function test_check_definitions_and_failure_codes(): void
    {
        $valid = $this->validPilot();
        $cert = (new AgentControlPlaneRuntimePilotCertificationService)->certify($valid);
        $this->assertSame($cert['check_count'], $cert['passed_count']);
        $this->assertSame(0, $cert['failed_count']);
        $this->assertSame('available', $cert['status']);

        $tests = [
            ['mutator' => function (array &$p): void {
                $p['task_packet']['status'] = 'blocked';
            }, 'failure' => 'task_packet_invalid_or_blocked'],
            ['mutator' => function (array &$p): void {
                $p['claim_lease_simulation']['lease_status'] = 'simulated_conflict';
            }, 'failure' => 'claim_lease_not_granted'],
            ['mutator' => function (array &$p): void {
                $p['scope_lock_plan']['status'] = 'planned_blocked';
            }, 'failure' => 'scope_lock_unsafe'],
            ['mutator' => function (array &$p): void {
                $p['evidence_ledger_dry_run']['receipt_count'] = 0;
            }, 'failure' => 'evidence_ledger_dry_run_incomplete'],
            ['mutator' => function (array &$p): void {
                $p['continuation_summary']['continuation_hash'] = '';
            }, 'failure' => 'continuation_summary_incomplete'],
            ['mutator' => function (array &$p): void {
                $p['work_product_manifest_plan']['collection_allowed'] = true;
            }, 'failure' => 'work_product_manifest_incomplete'],
            ['mutator' => function (array &$p): void {
                $p['cost_import_dry_run']['token_spend_allowed'] = true;
            }, 'failure' => 'cost_import_dry_run_incomplete'],
            ['mutator' => function (array &$p): void {
                $p['multi_agent_parallelism_plan']['status'] = 'planned_blocked';
            }, 'failure' => 'multi_agent_plan_unsafe'],
        ];

        foreach ($tests as $case) {
            $pilot = $this->validPilot();
            ($case['mutator'])($pilot);
            $result = (new AgentControlPlaneRuntimePilotCertificationService)->certify($pilot);
            $this->assertContains($case['failure'], $result['failures'], "Expected failure {$case['failure']}");
            $this->assertSame('blocked', $result['status']);
        }
    }

    public function test_full_guarantee_set(): void
    {
        $cert = (new AgentControlPlaneRuntimePilotCertificationService)->certify($this->validPilot());
        foreach ([
            'runtime_pilot_certification_does_not_start_codex',
            'runtime_pilot_certification_does_not_call_codex_cli_or_app',
            'runtime_pilot_certification_does_not_spawn_subprocess',
            'runtime_pilot_certification_does_not_invoke_adapter',
            'runtime_pilot_certification_does_not_call_provider',
            'runtime_pilot_certification_does_not_dispatch_work',
            'runtime_pilot_certification_does_not_spend_tokens',
            'runtime_pilot_certification_does_not_enable_self_programming',
            'runtime_pilot_certification_does_not_write_ledger',
            'runtime_pilot_certification_does_not_mutate_pointer',
            'runtime_pilot_certification_does_not_promote_completion_claim',
        ] as $expected) {
            $this->assertContains($expected, $cert['non_execution_guarantees']);
        }
        $this->assertContains($cert['status'], ['available', 'warning', 'blocked']);
        $this->assertIsArray($cert['failures']);
        $this->assertIsArray($cert['checks']);
        $this->assertSame(16, $cert['check_count']);
    }

    public function test_payload_fully_shaped(): void
    {
        $pilot = $this->validPilot();
        $cert = (new AgentControlPlaneRuntimePilotCertificationService)->certify($pilot);
        foreach ([
            'schema_version', 'mode', 'certification_id', 'generated_at', 'status', 'checks',
            'check_count', 'passed_count', 'failed_count', 'failures', 'pilot_hash', 'pilot_status',
            'read_only', 'runtime_disabled', 'execution_allowed', 'dispatch_allowed',
            'provider_call_allowed', 'token_spend_allowed', 'self_programming_allowed',
            'ledger_write_allowed', 'completion_claim_allowed', 'non_execution_guarantees',
            'human_summary', 'certification_hash',
        ] as $key) {
            $this->assertArrayHasKey($key, $cert, "Missing $key");
        }
        foreach ([
            'task_packet_valid', 'claim_lease_simulated', 'scope_lock_safe',
            'evidence_ledger_dry_run_complete', 'continuation_summary_complete',
            'work_product_manifest_complete', 'cost_import_dry_run_complete',
            'multi_agent_plan_safe', 'runtime_safety_all_false', 'no_ledger_write',
            'no_provider_call', 'no_dispatch', 'no_adapter_execution', 'no_self_programming',
            'chain_integrity_summary_present', 'replay_summary_present',
        ] as $check) {
            $this->assertArrayHasKey($check, $cert['checks'], "Missing check: $check");
            $this->assertTrue($cert['checks'][$check], "Check $check should pass");
        }
        $this->assertContains('runtime_pilot_certification_does_not_start_codex', $cert['non_execution_guarantees']);
        $this->assertContains('runtime_pilot_certification_does_not_call_codex_cli_or_app', $cert['non_execution_guarantees']);
        $this->assertContains('runtime_pilot_certification_does_not_dispatch_work', $cert['non_execution_guarantees']);
        $this->assertContains('runtime_pilot_certification_does_not_spend_tokens', $cert['non_execution_guarantees']);
        $this->assertContains('runtime_pilot_certification_does_not_enable_self_programming', $cert['non_execution_guarantees']);
        $this->assertContains('runtime_pilot_certification_does_not_write_ledger', $cert['non_execution_guarantees']);
        $this->assertContains('runtime_pilot_certification_does_not_mutate_pointer', $cert['non_execution_guarantees']);
        $this->assertContains('runtime_pilot_certification_does_not_promote_completion_claim', $cert['non_execution_guarantees']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validPilot(): array
    {
        return [
            'status' => 'available',
            'pilot_hash' => str_repeat('a', 64),
            'task_packet' => [
                'status' => 'planned',
                'task_packet_hash' => str_repeat('b', 64),
                'scope_hash' => str_repeat('c', 64),
            ],
            'claim_lease_simulation' => [
                'lease_status' => 'simulated_granted',
            ],
            'scope_lock_plan' => [
                'status' => 'planned_safe',
                'cross_axis_blockers' => [],
                'unsafe_path_blockers' => [],
            ],
            'evidence_ledger_dry_run' => [
                'receipt_count' => 7,
                'ledger_write_allowed' => false,
                'dry_run_only' => true,
            ],
            'continuation_summary' => [
                'continuation_hash' => str_repeat('d', 64),
                'next_actions' => ['x'],
            ],
            'work_product_manifest_plan' => [
                'status' => 'planned',
                'collection_allowed' => false,
                'automatic_collection_runtime_enabled' => false,
            ],
            'cost_import_dry_run' => [
                'status' => 'planned',
                'automatic_cost_import_runtime_enabled' => false,
                'token_spend_allowed' => false,
                'provider_call_allowed' => false,
            ],
            'multi_agent_parallelism_plan' => [
                'status' => 'planned',
            ],
            'chain_integrity_summary' => [
                'invariants_all_true' => true,
                'runtime_safety_all_false' => true,
            ],
            'replay_summary' => [
                'invariants_all_true' => true,
                'replay_hash' => str_repeat('e', 64),
            ],
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
            'completion_claim_allowed' => false,
        ];
    }
}
