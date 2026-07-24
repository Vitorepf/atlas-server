<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Aaeos;

use App\Services\Ai\Aaeos\Control\AaeosP4RealOperationGauntlet;
use Tests\TestCase;

/**
 * P4: real entry-point preflight + residual honesty when durable PG missing.
 */
final class AaeosP4RealOperationGauntletTest extends TestCase
{
    public function test_preflight_refuses_missing_pg_roles(): void
    {
        $pre = AaeosP4RealOperationGauntlet::preflight([
            'ATLAS_P4_PG_PRODUCER_URL' => '',
            'ATLAS_P4_PG_VERIFIER_URL' => '',
        ]);
        $this->assertFalse($pre['durable_pg_ready']);
        $this->assertContains('atlas_p4_pg_producer_url_missing', $pre['blockers']);
        $this->assertContains('atlas_p4_pg_verifier_url_missing', $pre['blockers']);
        $this->assertTrue($pre['phpunit_alone_never_qualifies']);
    }

    public function test_plan_only_dev_cli_entry_is_partial_not_qualified_real_operation(): void
    {
        $cmd = base_path('bin/atlas');
        $this->assertFileExists($cmd);

        // Drive the real CLI entry (help path) — full plan-only runs can hang without provider/PG.
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open(
            [$cmd, 'dev', '--help'],
            $descriptors,
            $pipes,
            base_path(),
        );
        $this->assertIsResource($proc);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(0, $exit, $stdout."\n".$stderr);
        $this->assertStringContainsString('plan-only', $stdout.$stderr);

        $receipt = AaeosP4RealOperationGauntlet::journeyReceipt('dev', [
            'exit_code' => 0,
            'stdout' => $stdout,
            'command' => 'bin/atlas dev --help',
        ], [
            'plan_only' => true,
            'env' => [
                'ATLAS_P4_PG_PRODUCER_URL' => '',
                'ATLAS_P4_PG_VERIFIER_URL' => '',
            ],
            'r104_transport_open' => true,
            'code_sha' => (string) trim((string) shell_exec('git rev-parse HEAD') ?: ''),
        ]);

        $this->assertSame(AaeosP4RealOperationGauntlet::STATUS_BLOCKED_OPS_PARTIAL, $receipt['journey_terminal_status']);
        $this->assertFalse($receipt['real_operation_qualified']);
        $this->assertTrue($receipt['exit_zero_alone_never_qualifies']);
        $this->assertContains('durable_pg_roles_required_for_real_operation', $receipt['blockers']);
        $this->assertArrayHasKey('stdout_fingerprint', $receipt);
        $this->assertNotSame('', $receipt['stdout_fingerprint']);
    }

    public function test_exit_zero_alone_never_qualifies_even_with_pg_env_strings(): void
    {
        $receipt = AaeosP4RealOperationGauntlet::journeyReceipt('forge', [
            'exit_code' => 0,
            'stdout' => 'ok',
            'command' => 'bin/atlas forge demo',
        ], [
            'plan_only' => false,
            'env' => [
                'ATLAS_P4_PG_PRODUCER_URL' => 'pgsql://atlas_p4_producer@localhost/atlas_p4',
                'ATLAS_P4_PG_VERIFIER_URL' => 'pgsql://atlas_p4_verifier@localhost/atlas_p4',
            ],
        ]);

        $this->assertFalse($receipt['real_operation_qualified']);
        $this->assertTrue(
            in_array('derived_capability_proofs_incomplete', $receipt['blockers'], true)
            || in_array('producer_status_not_completed', $receipt['blockers'], true),
        );
    }

    public function test_exit_zero_blocked_senior_loop_never_qualifies_real_operation(): void
    {
        $stdout = json_encode([
            'status' => 'blocked',
            'blockers' => ['senior_loop_execution_not_passed'],
            'run_summary' => [
                'completion_state' => 'blocked',
                'provider_call' => [
                    'provider' => 'hermes_cli',
                    'provider_calls' => 1,
                    'exit_code' => 1,
                    'error_codes' => [
                        'governor_authority_absent:blocked|risk:verification_not_passed|risk_blocked|court_authority_not_eligible',
                    ],
                ],
                'verification_receipt_hash' => str_repeat('11', 32),
            ],
        ], JSON_THROW_ON_ERROR);

        $receipt = AaeosP4RealOperationGauntlet::journeyReceipt('dev', [
            'exit_code' => 0,
            'stdout' => $stdout,
            'command' => 'php artisan atlas:dev:senior-loop:run --json',
        ], [
            'plan_only' => false,
            'env' => [
                'ATLAS_P4_PG_PRODUCER_URL' => 'pgsql://atlas_p4_producer@localhost/atlas_p4',
                'ATLAS_P4_PG_VERIFIER_URL' => 'pgsql://atlas_p4_verifier@localhost/atlas_p4',
            ],
            'provider_spawn_proof' => [
                'provider' => 'hermes_cli',
                'provider_receipt_hash' => str_repeat('ab', 32),
                'spawned' => true,
            ],
            'authority_lineage_proof' => [
                'authority_ref' => 'mandate-1',
                'authority_hash' => str_repeat('cd', 32),
                'authority_revision' => 1,
            ],
        ]);

        $this->assertFalse($receipt['real_operation_qualified']);
        $this->assertTrue($receipt['blocked_status_exit_zero_never_qualifies']);
        $this->assertContains('producer_status_not_completed', $receipt['blockers']);
        $this->assertSame('blocked', $receipt['producer_terminal']['status']);
        $this->assertFalse($receipt['structured_residual']['court_authority_eligible']);
        $this->assertNotEmpty($receipt['structured_residual']['named_residuals']);
        $this->assertStringContainsString(
            'court_authority_not_eligible',
            implode(' ', $receipt['structured_residual']['named_residuals']),
        );
    }

    public function test_autonomos_queue_scan_limit_promotes_status_reason_into_named_residuals(): void
    {
        // Mirrors live atlas:task next envelope (status/reason, no blockers array).
        $stdout = json_encode([
            'schema' => 'atlas.task_serving.envelope.v1',
            'status' => 'queue_scan_limit_exceeded',
            'client_id' => 'aaeos-p4-goal-probe',
            'task' => null,
            'reason' => 'queue_scan_limit_exceeded',
            'candidate_count' => 0,
            'minimum_claimable_count' => 1,
            'scan_limit' => 50,
        ], JSON_THROW_ON_ERROR);

        $terminal = AaeosP4RealOperationGauntlet::deriveProducerTerminalFromStdout($stdout);
        $this->assertSame('queue_scan_limit_exceeded', $terminal['status']);
        $this->assertFalse($terminal['completed']);
        $this->assertContains('queue_scan_limit_exceeded', $terminal['error_codes']);

        $receipt = AaeosP4RealOperationGauntlet::journeyReceipt('autonomos', [
            'exit_code' => 0,
            'stdout' => $stdout,
            'command' => 'php artisan atlas:task next --client=aaeos-p4-goal-probe --json',
        ], [
            'plan_only' => false,
            'aaeos_initiated' => false,
            'env' => [
                'ATLAS_P4_PG_PRODUCER_URL' => 'pgsql://atlas_p4_producer@localhost/atlas_p4',
                'ATLAS_P4_PG_VERIFIER_URL' => 'pgsql://atlas_p4_verifier@localhost/atlas_p4',
            ],
        ]);

        $this->assertFalse($receipt['real_operation_qualified']);
        $this->assertContains('queue_scan_limit_exceeded', $receipt['structured_residual']['named_residuals']);
        $this->assertNotEmpty($receipt['structured_residual']['named_residuals']);
        $blockerJoined = implode(' ', array_map('strval', $receipt['blockers']));
        $this->assertStringContainsString('queue_scan_limit_exceeded', $blockerJoined);
        // Must not invent workspace_not_ready when producer said queue_scan_limit_exceeded.
        $joined = implode(' ', $receipt['structured_residual']['named_residuals']);
        $this->assertStringNotContainsString('workspace_not_ready', $joined);
    }

    public function test_structured_residual_names_court_gate_from_live_payload(): void
    {
        $livePath = base_path('storage/app/aaeos-p4-mut-dev/senior-loop-bind.json');
        $stdout = is_file($livePath)
            ? (string) file_get_contents($livePath)
            : json_encode([
                'status' => 'blocked',
                'run_summary' => [
                    'provider_call' => [
                        'error_codes' => [
                            'governor_authority_absent:blocked|risk:verification_not_passed|risk_blocked|court_authority_not_eligible',
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR);
        $terminal = AaeosP4RealOperationGauntlet::deriveProducerTerminalFromStdout($stdout);
        $this->assertFalse($terminal['completed']);
        $residual = AaeosP4RealOperationGauntlet::structuredResidualFromProducerPayload(
            $terminal['payload'],
            $terminal['status'],
            $terminal['error_codes'],
        );
        $this->assertContains('producer_eng_not_released', $residual['blockers']);
        $this->assertFalse($residual['residual']['real_operation_completed']);
        $this->assertSame('residual_honest_partial_not_fabricated', $residual['residual']['honesty']);
        $joined = implode(' ', $residual['residual']['named_residuals']);
        $this->assertTrue(
            str_contains($joined, 'court_authority_not_eligible')
            || str_contains($joined, 'governor_authority_absent')
            || str_contains($joined, 'verification_not_passed'),
            'expected court/governor residual, got: '.$joined,
        );
    }

    public function test_caller_set_capability_bools_are_forbidden(): void
    {
        $receipt = AaeosP4RealOperationGauntlet::journeyReceipt('dev', [
            'exit_code' => 0,
            'stdout' => 'completed',
            'command' => 'bin/atlas dev real',
        ], [
            'plan_only' => false,
            'env' => [
                'ATLAS_P4_PG_PRODUCER_URL' => 'pgsql://atlas_p4_producer@localhost/atlas_p4',
                'ATLAS_P4_PG_VERIFIER_URL' => 'pgsql://atlas_p4_verifier@localhost/atlas_p4',
            ],
            'provider_spawn_attested' => true,
            'authority_lineage_present' => true,
            'provider_spawn_proof' => [
                'provider' => 'codex_cli',
                'provider_receipt_hash' => str_repeat('ab', 32),
                'spawned' => true,
            ],
            'authority_lineage_proof' => [
                'authority_ref' => 'mandate-1',
                'authority_hash' => str_repeat('cd', 32),
                'authority_revision' => 1,
            ],
        ]);

        $this->assertFalse($receipt['real_operation_qualified']);
        $this->assertContains('caller_set_capability_flags_forbidden', $receipt['blockers']);
    }

    public function test_full_derived_proofs_yield_real_operation_completed(): void
    {
        $receipt = AaeosP4RealOperationGauntlet::journeyReceipt('dev', [
            'exit_code' => 0,
            'stdout' => 'completed',
            'command' => 'bin/atlas dev real',
        ], [
            'plan_only' => false,
            'env' => [
                'ATLAS_P4_PG_PRODUCER_URL' => 'pgsql://atlas_p4_producer@localhost/atlas_p4',
                'ATLAS_P4_PG_VERIFIER_URL' => 'pgsql://atlas_p4_verifier@localhost/atlas_p4',
            ],
            'provider_spawn_proof' => [
                'provider' => 'codex_cli',
                'provider_receipt_hash' => str_repeat('ab', 32),
                'spawned' => true,
            ],
            'authority_lineage_proof' => [
                'authority_ref' => 'mandate-1',
                'authority_hash' => str_repeat('cd', 32),
                'authority_revision' => 1,
            ],
        ]);

        $this->assertTrue($receipt['real_operation_qualified']);
        $this->assertSame(AaeosP4RealOperationGauntlet::STATUS_REAL_OPERATION_COMPLETED, $receipt['journey_terminal_status']);
        $this->assertTrue($receipt['capability_proof_derived_not_caller_set']);
    }

    public function test_forge_live_execution_status_is_producer_terminal_not_aemor_recorded(): void
    {
        $stdout = json_encode([
            'schema_version' => 'atlas.forge_live_execution_certification.v1',
            'forge_live_execution_status' => 'passed',
            'remaining_blockers' => [],
            'external_provider_call' => false,
            'aemor_outcome' => ['status' => 'recorded'],
        ], JSON_THROW_ON_ERROR);

        $terminal = AaeosP4RealOperationGauntlet::deriveProducerTerminalFromStdout($stdout);
        $this->assertSame('passed', $terminal['status']);
        $this->assertTrue($terminal['completed']);
    }

    public function test_senior_loop_payload_authority_lineage_is_derived_not_invented(): void
    {
        $stdout = json_encode([
            'status' => 'passed',
            'schema_version' => 'atlas.dev.senior_engineer_loop_execution.v1',
            'execution_hash' => str_repeat('ee', 32),
            'run_summary' => [
                'completion_state' => 'passed',
                'verification_status' => 'passed',
                'scope_guard_status' => 'passed',
                'verification_receipt_hash' => str_repeat('aa', 32),
                'provider_call' => [
                    'provider' => 'hermes_cli',
                    'provider_calls' => 1,
                    'exit_code' => 0,
                    'error_codes' => [],
                ],
                'authority_lineage' => [
                    'authority_ref' => 'decafbaddecafbaddecafbaddecafbad',
                    'authority_hash' => str_repeat('cd', 32),
                    'authority_revision' => 1,
                    'source' => 'confirmed_dev_run',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $receipt = AaeosP4RealOperationGauntlet::journeyReceipt('dev', [
            'exit_code' => 0,
            'stdout' => $stdout,
            'command' => 'php artisan atlas:dev:senior-loop:run --json',
        ], [
            'plan_only' => false,
            'env' => [
                'ATLAS_P4_PG_PRODUCER_URL' => 'pgsql://atlas_p4_producer@localhost/atlas_p4',
                'ATLAS_P4_PG_VERIFIER_URL' => 'pgsql://atlas_p4_verifier@localhost/atlas_p4',
            ],
        ]);

        $this->assertTrue($receipt['real_operation_qualified'], 'blockers='.implode(',', $receipt['blockers']));
        $this->assertSame(AaeosP4RealOperationGauntlet::STATUS_REAL_OPERATION_COMPLETED, $receipt['journey_terminal_status']);
        $this->assertNotNull($receipt['authority_lineage_proof']);
        $this->assertSame('decafbaddecafbaddecafbaddecafbad', $receipt['authority_lineage_proof']['authority_ref']);
        $this->assertSame(str_repeat('cd', 32), $receipt['authority_lineage_proof']['authority_hash']);
        $this->assertTrue($receipt['authority_lineage_proof']['derived'] ?? false);
        $this->assertNotNull($receipt['provider_spawn_proof']);
        $this->assertTrue($receipt['provider_spawn_proof']['spawned'] ?? false);
    }

    public function test_certify_read_only_boundary(): void
    {
        $ok = AaeosP4RealOperationGauntlet::certifyReadOnlyBoundary([
            'profile' => 'p4',
            'append_ledger' => false,
            'spawn_provider' => false,
        ]);
        $this->assertTrue($ok['ok']);

        $bad = AaeosP4RealOperationGauntlet::certifyReadOnlyBoundary([
            'profile' => 'p4',
            'append_ledger' => true,
            'spawn_provider' => true,
        ]);
        $this->assertFalse($bad['ok']);
        $this->assertContains('certify_must_not_append_ledger', $bad['blockers']);
        $this->assertContains('certify_must_not_spawn_provider', $bad['blockers']);
    }

    public function test_freeze_binds_three_mode_manifests(): void
    {
        $dev = AaeosP4RealOperationGauntlet::journeyReceipt('dev', [
            'exit_code' => 0, 'stdout' => 'd', 'command' => 'bin/atlas dev --plan-only',
        ], ['plan_only' => true, 'env' => []]);
        $forge = AaeosP4RealOperationGauntlet::journeyReceipt('forge', [
            'exit_code' => 0, 'stdout' => 'f', 'command' => 'bin/atlas forge --plan-only',
        ], ['plan_only' => true, 'env' => []]);
        $auto = AaeosP4RealOperationGauntlet::journeyReceipt('autonomos', [
            'exit_code' => 0, 'stdout' => 'a', 'command' => 'php artisan atlas:self-construction:runtime-daemon --once',
        ], ['plan_only' => true, 'aaeos_initiated' => false, 'env' => []]);

        $freeze = AaeosP4RealOperationGauntlet::freeze([
            'dev' => $dev,
            'forge' => $forge,
            'autonomos' => $auto,
        ], codeSha: str_repeat('ab', 20));

        $this->assertTrue($freeze['all_three_modes_bound']);
        $this->assertFalse($freeze['full_real_operation_done']);
        $this->assertSame(str_repeat('ab', 20), $freeze['code_sha']);
    }

    public function test_autonomos_rejects_aaeos_initiated_flag(): void
    {
        $receipt = AaeosP4RealOperationGauntlet::journeyReceipt('autonomos', [
            'exit_code' => 0,
            'stdout' => 'x',
            'command' => 'daemon',
        ], [
            'plan_only' => false,
            'aaeos_initiated' => true,
            'env' => [
                'ATLAS_P4_PG_PRODUCER_URL' => 'pgsql://atlas_p4_producer@localhost/atlas_p4',
                'ATLAS_P4_PG_VERIFIER_URL' => 'pgsql://atlas_p4_verifier@localhost/atlas_p4',
            ],
            'provider_spawn_proof' => [
                'provider' => 'hermes',
                'provider_receipt_hash' => str_repeat('11', 32),
                'spawned' => true,
            ],
            'authority_lineage_proof' => [
                'authority_ref' => 'mandate-a',
                'authority_hash' => str_repeat('22', 32),
                'authority_revision' => 2,
            ],
        ]);
        $this->assertFalse($receipt['real_operation_qualified']);
        $this->assertContains('autonomos_must_be_direct_daemon_first', $receipt['blockers']);
    }
}
