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
            'command' => 'bin/atlas forge demo --plan-only',
        ], [
            'plan_only' => false,
            'env' => [
                'ATLAS_P4_PG_PRODUCER_URL' => 'pgsql://atlas_p4_producer@localhost/atlas_p4',
                'ATLAS_P4_PG_VERIFIER_URL' => 'pgsql://atlas_p4_verifier@localhost/atlas_p4',
            ],
            'provider_spawn_attested' => false,
            'authority_lineage_present' => true,
        ]);

        $this->assertFalse($receipt['real_operation_qualified']);
        $this->assertContains('real_operation_predicates_incomplete', $receipt['blockers']);
    }

    public function test_full_predicates_yield_real_operation_completed(): void
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
        ]);

        $this->assertTrue($receipt['real_operation_qualified']);
        $this->assertSame(AaeosP4RealOperationGauntlet::STATUS_REAL_OPERATION_COMPLETED, $receipt['journey_terminal_status']);
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
            'provider_spawn_attested' => true,
            'authority_lineage_present' => true,
        ]);
        $this->assertFalse($receipt['real_operation_qualified']);
        $this->assertContains('autonomos_must_be_direct_daemon_first', $receipt['blockers']);
    }
}
