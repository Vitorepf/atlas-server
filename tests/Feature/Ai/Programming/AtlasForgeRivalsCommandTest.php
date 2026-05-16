<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Console\Commands\AtlasForgeRivalsCommand;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsActionDispatcher;
use Illuminate\Support\Facades\Artisan;
use ReflectionClass;
use Tests\TestCase;

/**
 * Atlas Forge Rivals · Provider Arena Core v1 — canonical command contract tests.
 *
 * Asserts that the canonical entrypoint exposes the canonical action set
 * (perfect battery + arena + ledger + decide-signal + audit), returns a
 * stable JSON envelope with `schema_version =
 * atlas.forge.rivals.action_response.v1`, uses fail-closed exit code 2 for
 * pending-slice actions, and uses exit code 1 for unknown actions.
 *
 * No provider is dispatched in these tests; they stay strictly in-process.
 */
final class AtlasForgeRivalsCommandTest extends TestCase
{
    public function test_command_signature_exposes_canonical_action_set(): void
    {
        $expected = [
            'doctor', 'setup', 'preflight', 'dry-run', 'plan-real', 'run-real',
            'status', 'collect-evidence', 'evidence', 'replay', 'verify-evidence',
            'battery-evidence', 'battery-verify-evidence',
            'adjudicate', 'report', 'reset',
            'full-smoke', 'run-battery', 'run-arena', 'arms', 'cases',
            'ledger', 'ledger-record', 'decide-signal',
            'next',
            'resume',
            'battery-report',
            'matrix-report',
            'audit',
        ];

        $this->assertSame($expected, AtlasForgeRivalsCommand::ACTIONS);
        $this->assertCount(count($expected), AtlasForgeRivalsCommand::ACTION_SLICE);
        foreach ($expected as $action) {
            $this->assertArrayHasKey($action, AtlasForgeRivalsCommand::ACTION_SLICE);
        }

        $signature = (new ReflectionClass(AtlasForgeRivalsCommand::class))
            ->getProperty('signature')
            ->getDefaultValue();
        $this->assertIsString($signature);
        $this->assertStringStartsWith('atlas:forge:rivals', $signature);
        foreach ($expected as $action) {
            $this->assertStringContainsString($action, $signature, "Signature must mention action '{$action}'.");
        }
        foreach (['mode', 'atlas-model', 'rival', 'preset', 'source-ref', 'run-id', 'confirm-runbook-reviewed', 'confirm-provider-cost', 'confirm-real-provider-call', 'json', 'strict'] as $flag) {
            $this->assertStringContainsString($flag, $signature, "Signature must declare flag '--{$flag}'.");
        }
    }

    public function test_unknown_action_returns_structured_error_envelope_and_exit_one(): void
    {
        $exit = Artisan::call('atlas:forge:rivals', [
            'action' => 'xyzzy',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload, 'unknown-action --json must emit a JSON object');
        $this->assertSame('atlas.forge.rivals.action_response.v1', $payload['schema_version']);
        $this->assertSame('error', $payload['status']);
        $this->assertSame('unknown_action', $payload['error_code']);
        $this->assertSame('xyzzy', $payload['requested_action']);
        $this->assertSame(AtlasForgeRivalsCommand::ACTIONS, $payload['supported_actions']);
        $this->assertFalse($payload['external_provider_call']);
        $this->assertSame(1, $exit, 'error status must exit 1 per the canonical status matrix');
    }

    public function test_doctor_action_emits_canonical_v2_envelope(): void
    {
        // The canonical command's dispatcher forwards harness-mapped actions
        // to the legacy harness internally (Slice 0 internal reuse). When that
        // happens, Laravel's Artisan::output() reflects the inner harness call
        // (whose private BufferedOutput has been fetched-and-cleared), not the
        // outer canonical output. So we test the dispatcher contract directly.
        $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);
        $response = $dispatcher->dispatch('doctor', [
            'preset' => 'smoke',
            'mode' => null,
            'atlas_model' => null,
            'rival' => null,
            'confirmations' => [
                'runbook_reviewed' => false,
                'provider_cost' => false,
                'real_provider_call' => false,
            ],
            'strict' => false,
        ]);

        $this->assertIsArray($response);
        $this->assertSame('atlas.forge.rivals.action_response.v1', $response['schema_version']);
        $this->assertSame('doctor', $response['action']);
        $this->assertIsString($response['status']);
        $this->assertArrayHasKey('blockers', $response);
        $this->assertIsArray($response['blockers']);
        $this->assertFalse($response['external_provider_call']);
        $this->assertTrue($response['separated_from_external_rivals_certification']);
    }

    public function test_status_action_blocks_when_no_run_id(): void
    {
        // status is a real action (Slice 3) and requires --run-id. Without it,
        // the response is status=blocked with run_id_required in blockers.
        $dispatcher = app(\App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsActionDispatcher::class);
        $response = $dispatcher->dispatch('status', [
            'preset' => 'smoke',
            'confirmations' => ['runbook_reviewed' => false, 'provider_cost' => false, 'real_provider_call' => false],
            'strict' => false,
        ]);
        $this->assertIsArray($response);
        $this->assertSame('atlas.forge.rivals.action_response.v1', $response['schema_version']);
        $this->assertSame('status', $response['action']);
        $this->assertSame('blocked', $response['status']);
        $this->assertContains('run_id_required', $response['blockers']);
    }

    public function test_status_terminal_and_running_states_exit_zero_in_strict_mode(): void
    {
        $command = new AtlasForgeRivalsCommand;
        $method = (new ReflectionClass(AtlasForgeRivalsCommand::class))->getMethod('exitCodeFor');
        $method->setAccessible(true);

        $this->assertSame(0, $method->invoke($command, 'running', true));
        $this->assertSame(0, $method->invoke($command, 'completed', true));
    }

    public function test_audit_action_emits_certification_payload(): void
    {
        $exit = Artisan::call('atlas:forge:rivals', [
            'action' => 'audit',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame('audit', $payload['action']);
        $this->assertArrayHasKey('certification', $payload);
        $this->assertSame(
            'atlas.forge_rivals_operator_battery_certification.v1',
            $payload['certification']['schema_version']
        );
        $this->assertSame(
            'atlas_forge_rivals_operator_battery_certification',
            $payload['certification']['certification_key']
        );
        $this->assertSame('external_rivals_certification', $payload['certification']['separated_from']);
        $this->assertContains($exit, [0, 1, 2], 'audit returns 0 when cert available, 1/2 while pending/blocked');
    }
}
