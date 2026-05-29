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
            'status', 'collect-evidence', 'evidence', 'evidence-bundle', 'evidence-bundle-verify',
            'replay', 'verify-evidence', 'battery-evidence', 'battery-verify-evidence',
            'adjudicate', 'report', 'reset',
            'full-smoke', 'run-battery', 'run-arena', 'arms', 'models',
            'arena-readiness', 'industrial-suite', 'industrial-execution',
            'deepswe', 'deepswe-import', 'deepswe-ingest', 'deepswe-batch-ingest', 'cases',
            'runs', 'trusted-signal', 'external-evidence-readiness', 'external-execution-preflight', 'external-execution-plan',
            'external-runbook-validate', 'external-learning-gap',
            'ledger', 'ledger-record', 'statistical-repeat-plan', 'statistical-repeat-dry-run',
            'decide-signal', 'decide-map', 'decide-learning',
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
        foreach (['mode', 'atlas-model', 'rival', 'preset', 'source-ref', 'run-id', 'plan-manifest', 'confirm-runbook-reviewed', 'confirm-provider-cost', 'confirm-real-provider-call', 'json', 'strict'] as $flag) {
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
        $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);
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

    public function test_decide_learning_action_emits_advisory_packet_without_provider_call(): void
    {
        $exit = Artisan::call('atlas:forge:rivals', [
            'action' => 'decide-learning',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame(0, $exit);
        $this->assertSame('atlas.forge.rivals.atlas_decide_learning_packet.v1', $payload['schema_version']);
        $this->assertSame('decide-learning', $payload['action']);
        $this->assertSame('ok', $payload['status']);
        $this->assertArrayHasKey('learning_status', $payload);
        $this->assertArrayHasKey('model_fit_matrix', $payload);
        $this->assertArrayHasKey('category_fit_summary', $payload);
        $this->assertArrayHasKey('evidence_collection_plan', $payload);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $payload['learning_packet_hash']);
        $this->assertArrayHasKey('evidence_provenance', $payload);
        $this->assertFalse($payload['external_provider_call']);
        $this->assertFalse($payload['provider_tokens_spent']);
        $this->assertFalse($payload['score_or_claim_allowed']);
        $this->assertFalse($payload['should_update_provider_topology']);
        $this->assertSame('atlas_decide', $payload['owner_of_model_routing']);
        $this->assertSame('none', $payload['routing_effect']);
    }

    public function test_external_execution_preflight_checks_provider_binary_without_provider_call(): void
    {
        $binary = storage_path('framework/testing/rivals-fake-claude');
        if (! is_dir(dirname($binary))) {
            mkdir(dirname($binary), 0777, true);
        }
        file_put_contents($binary, "#!/bin/sh\nexit 0\n");
        chmod($binary, 0755);
        config(['atlas.ai.providers.claude_cli.binary' => $binary]);

        $exit = Artisan::call('atlas:forge:rivals', [
            'action' => 'external-execution-preflight',
            '--provider' => 'claude',
            '--case-set' => 'quick',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame(0, $exit);
        $this->assertSame('external-execution-preflight', $payload['action']);
        $this->assertSame('atlas.forge.rivals.external_execution_preflight.v1', $payload['schema_version']);
        $this->assertSame('ok', $payload['status']);
        $this->assertTrue($payload['provider_binary_checks']['claude']['ok']);
        $this->assertSame($binary, $payload['provider_binary_checks']['claude']['resolved_binary_path']);
        $this->assertSame('atlas.forge.rivals.external_runner_bridge_contract.v1', $payload['runner_bridge_contract']['schema_version']);
        $this->assertSame('cli_runner', $payload['runner_bridge_contract']['provider_interfaces']['claude']['execution_interface']);
        $this->assertSame('AtlasForgeRivalsArmCommandBuilderService::claudeCommand', $payload['runner_bridge_contract']['provider_interfaces']['claude']['command_builder_owner']);
        $this->assertFalse($payload['runner_bridge_contract']['provider_interfaces']['claude']['rivals_spawns_provider_in_preflight']);
        $this->assertSame('not_required_by_rivals', $payload['runner_bridge_contract']['deep_swe_api_only_assumption']);
        $this->assertContains('provider_receipt', $payload['runner_bridge_contract']['evidence_required_before_score']);
        $this->assertContains('replay_green', $payload['runner_bridge_contract']['evidence_required_before_score']);
        $this->assertTrue($payload['ready_for_real_execution_with_confirmations']);
        $this->assertFalse($payload['real_execution_allowed_by_this_preflight']);
        $this->assertFalse($payload['external_provider_call']);
        $this->assertFalse($payload['provider_tokens_spent']);
        $this->assertFalse($payload['score_or_claim_allowed']);
        $this->assertTrue($payload['advisory_only']);
        $this->assertFalse($payload['should_update_provider_topology']);
        $this->assertSame('atlas_decide', $payload['owner_of_model_routing']);
        $this->assertSame('none', $payload['routing_effect']);
    }

    public function test_external_execution_preflight_blocks_missing_provider_binary(): void
    {
        config(['atlas.ai.providers.claude_cli.binary' => '/definitely/missing/atlas-rivals-claude']);

        $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);
        $payload = $dispatcher->dispatch('external-execution-preflight', [
            'provider' => 'claude',
            'case_set' => 'quick',
            'confirmations' => ['runbook_reviewed' => false, 'provider_cost' => false, 'real_provider_call' => false],
        ]);

        $this->assertSame('external-execution-preflight', $payload['action']);
        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('atlas.forge.rivals.external_execution_preflight.v1', $payload['schema_version']);
        $this->assertContains('provider_binary_not_found:claude:/definitely/missing/atlas-rivals-claude', $payload['blockers']);
        $this->assertFalse($payload['provider_binary_checks']['claude']['ok']);
        $this->assertFalse($payload['ready_for_real_execution_with_confirmations']);
        $this->assertFalse($payload['external_provider_call']);
        $this->assertFalse($payload['provider_tokens_spent']);
        $this->assertFalse($payload['score_or_claim_allowed']);
        $this->assertTrue($payload['advisory_only']);
        $this->assertFalse($payload['should_update_provider_topology']);
        $this->assertSame('none', $payload['routing_effect']);
    }

    public function test_external_execution_plan_emits_runner_model_matrix_without_provider_call(): void
    {
        $binary = storage_path('framework/testing/rivals-fake-claude-plan');
        if (! is_dir(dirname($binary))) {
            mkdir(dirname($binary), 0777, true);
        }
        file_put_contents($binary, "#!/bin/sh\nexit 0\n");
        chmod($binary, 0755);
        config(['atlas.ai.providers.claude_cli.binary' => $binary]);
        $outputPath = storage_path('framework/testing/rivals-external-plan/runbook.json');
        @unlink($outputPath);

        $exit = Artisan::call('atlas:forge:rivals', [
            'action' => 'external-execution-plan',
            '--provider' => 'claude',
            '--model' => 'opus',
            '--task-category' => 'bugfix',
            '--difficulty' => 'L5',
            '--case-set' => 'quick',
            '--output-path' => $outputPath,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame(0, $exit);
        $this->assertSame('external-execution-plan', $payload['action']);
        $this->assertSame('atlas.forge.rivals.external_execution_plan.v1', $payload['schema_version']);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame('ready_for_operator_review_before_paid_external_runs', $payload['plan_status']);
        $this->assertSame('atlas.forge.rivals.external_execution_matrix_summary.v1', $payload['execution_matrix_summary']['schema_version']);
        $this->assertContains('claude', $payload['execution_matrix_summary']['providers']);
        $this->assertSame(['claude_opus'], $payload['execution_matrix_summary']['models']);
        $this->assertContains('bugfix', $payload['execution_matrix_summary']['task_categories']);
        $this->assertContains('L5', $payload['execution_matrix_summary']['difficulty_levels']);
        $this->assertSame(3, $payload['planned_missing_external_run_count']);
        $this->assertSame('atlas.forge.rivals.external_execution_model_gap_summary.v1', $payload['model_gap_summary']['schema_version']);
        $this->assertSame('needs_external_runs', $payload['model_gap_summary']['status']);
        $this->assertSame(1, $payload['model_gap_summary']['model_count']);
        $this->assertSame(1, $payload['model_gap_summary']['models_missing_evidence_count']);
        $this->assertSame(3, $payload['model_gap_summary']['total_missing_external_run_count']);
        $this->assertSame('claude_opus', $payload['model_gap_summary']['rows'][0]['model']);
        $this->assertSame(3, $payload['model_gap_summary']['rows'][0]['missing_external_run_count']);
        $this->assertFalse($payload['model_gap_summary']['external_provider_call']);
        $this->assertFalse($payload['model_gap_summary']['provider_tokens_spent']);
        $this->assertSame('none', $payload['model_gap_summary']['routing_effect']);
        $this->assertNotEmpty($payload['execution_batches_preview']);
        $this->assertNotEmpty($payload['execution_batches_preview'][0]['dry_run_commands_preview']);
        $this->assertNotEmpty($payload['execution_batches_preview'][0]['real_execution_command_templates_preview']);
        $this->assertStringContainsString('--case-set=quick', $payload['execution_batches_preview'][0]['dry_run_commands_preview'][0]);
        $this->assertStringContainsString('--dry-run', $payload['execution_batches_preview'][0]['dry_run_commands_preview'][0]);
        $this->assertStringContainsString('--case-set=quick', $payload['execution_batches_preview'][0]['real_execution_command_templates_preview'][0]);
        $this->assertStringContainsString('--confirm-runbook-reviewed', $payload['execution_batches_preview'][0]['real_execution_command_templates_preview'][0]);
        $this->assertStringContainsString('--confirm-provider-cost', $payload['execution_batches_preview'][0]['real_execution_command_templates_preview'][0]);
        $this->assertStringContainsString('--confirm-real-provider-call', $payload['execution_batches_preview'][0]['real_execution_command_templates_preview'][0]);
        $this->assertSame('atlas.forge.rivals.external_runner_bridge_contract.v1', $payload['runner_bridge_contract']['schema_version']);
        $this->assertSame('blocked_until_real_reproducible_evidence_complete', $payload['strong_claim_gate']['status']);
        $this->assertContains('statistical_repeat_confidence_ready', $payload['strong_claim_gate']['requires']);
        $this->assertSame('atlas.forge.rivals.external_execution_runbook_manifest.v1', $payload['external_execution_runbook_manifest']['schema_version']);
        $this->assertSame($payload['model_gap_summary'], $payload['external_execution_runbook_manifest']['model_gap_summary']);
        $this->assertSame('quick', $payload['external_execution_runbook_manifest']['missing_buckets'][0]['case_set']);
        $this->assertStringContainsString('--dry-run', $payload['external_execution_runbook_manifest']['missing_buckets'][0]['dry_run_command']);
        $this->assertStringContainsString('--confirm-real-provider-call', $payload['external_execution_runbook_manifest']['missing_buckets'][0]['real_execution_command_template']);
        $this->assertSame([
            'confirm_runbook_reviewed',
            'confirm_provider_cost',
            'confirm_real_provider_call',
        ], $payload['external_execution_runbook_manifest']['missing_buckets'][0]['required_confirmations_before_real_execution']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $payload['external_execution_runbook_manifest']['plan_fingerprint']);
        $this->assertSame($outputPath, $payload['external_execution_runbook_manifest_path']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $payload['external_execution_runbook_manifest_hash']);
        $this->assertFileExists($outputPath);
        $written = json_decode((string) file_get_contents($outputPath), true);
        $this->assertIsArray($written);
        $this->assertSame($payload['external_execution_runbook_manifest']['plan_fingerprint'], $written['plan_fingerprint']);
        $this->assertSame('plan_only_requires_operator_review', $written['status']);
        $this->assertFalse($written['external_provider_call']);
        $this->assertFalse($written['provider_tokens_spent']);
        $this->assertFalse($written['should_update_provider_topology']);
        $this->assertFalse($payload['real_execution_allowed_by_this_plan']);
        $this->assertFalse($payload['external_provider_call']);
        $this->assertFalse($payload['provider_tokens_spent']);
        $this->assertFalse($payload['score_or_claim_allowed']);
        $this->assertTrue($payload['advisory_only']);
        $this->assertFalse($payload['should_update_provider_topology']);
        $this->assertSame('atlas_decide', $payload['owner_of_model_routing']);
        $this->assertSame('none', $payload['routing_effect']);
    }

    public function test_external_execution_plan_blocks_when_preflight_blocks(): void
    {
        config(['atlas.ai.providers.claude_cli.binary' => '/definitely/missing/atlas-rivals-claude-plan']);

        $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);
        $payload = $dispatcher->dispatch('external-execution-plan', [
            'provider' => 'claude',
            'case_set' => 'quick',
            'confirmations' => ['runbook_reviewed' => false, 'provider_cost' => false, 'real_provider_call' => false],
        ]);

        $this->assertSame('external-execution-plan', $payload['action']);
        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('blocked_before_operator_review', $payload['plan_status']);
        $this->assertContains('provider_binary_not_found:claude:/definitely/missing/atlas-rivals-claude-plan', $payload['blockers']);
        $this->assertFalse($payload['real_execution_allowed_by_this_plan']);
        $this->assertFalse($payload['external_provider_call']);
        $this->assertFalse($payload['provider_tokens_spent']);
        $this->assertFalse($payload['score_or_claim_allowed']);
        $this->assertFalse($payload['should_update_provider_topology']);
        $this->assertSame('none', $payload['routing_effect']);
    }

    public function test_external_runbook_validate_accepts_plan_manifest_without_provider_call(): void
    {
        $binary = storage_path('framework/testing/rivals-fake-claude-runbook-validate');
        if (! is_dir(dirname($binary))) {
            mkdir(dirname($binary), 0777, true);
        }
        file_put_contents($binary, "#!/bin/sh\nexit 0\n");
        chmod($binary, 0755);
        config(['atlas.ai.providers.claude_cli.binary' => $binary]);
        $outputPath = storage_path('framework/testing/rivals-external-plan/runbook-validate.json');
        @unlink($outputPath);

        Artisan::call('atlas:forge:rivals', [
            'action' => 'external-execution-plan',
            '--provider' => 'claude',
            '--model' => 'opus',
            '--task-category' => 'bugfix',
            '--difficulty' => 'L5',
            '--case-set' => 'quick',
            '--output-path' => $outputPath,
            '--json' => true,
        ]);

        $exit = Artisan::call('atlas:forge:rivals', [
            'action' => 'external-runbook-validate',
            '--plan-manifest' => $outputPath,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame(0, $exit);
        $this->assertSame('external-runbook-validate', $payload['action']);
        $this->assertSame('atlas.forge.rivals.external_runbook_manifest_validation.v1', $payload['schema_version']);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame('ready_for_human_review_before_real_provider_confirmation', $payload['validation_status']);
        $this->assertSame($outputPath, $payload['plan_manifest_path']);
        $this->assertSame('atlas.forge.rivals.external_execution_runbook_manifest.v1', $payload['manifest_schema_version']);
        $this->assertSame($payload['plan_fingerprint'], $payload['computed_plan_fingerprint']);
        $this->assertSame('quick', $payload['case_set']);
        $this->assertSame(1, $payload['missing_bucket_count']);
        $this->assertSame('needs_external_runs', $payload['model_gap_status']);
        $this->assertSame('blocked_until_real_reproducible_evidence_complete', $payload['strong_claim_gate_status']);
        $this->assertSame([
            'confirm_runbook_reviewed',
            'confirm_provider_cost',
            'confirm_real_provider_call',
        ], $payload['required_confirmations_before_any_real_command']);
        $this->assertSame([], $payload['blockers']);
        $this->assertFalse($payload['real_execution_allowed_by_this_validation']);
        $this->assertFalse($payload['external_provider_call']);
        $this->assertFalse($payload['provider_tokens_spent']);
        $this->assertFalse($payload['score_or_claim_allowed']);
        $this->assertTrue($payload['advisory_only']);
        $this->assertFalse($payload['should_update_provider_topology']);
        $this->assertSame('atlas_decide', $payload['owner_of_model_routing']);
        $this->assertSame('none', $payload['routing_effect']);
    }

    public function test_external_runbook_validate_blocks_tampered_manifest_before_provider_call(): void
    {
        $path = storage_path('framework/testing/rivals-external-plan/tampered-runbook.json');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, json_encode([
            'schema_version' => 'atlas.forge.rivals.external_execution_runbook_manifest.v1',
            'status' => 'plan_only_requires_operator_review',
            'plan_fingerprint' => str_repeat('0', 64),
            'missing_buckets' => [[
                'dry_run_command' => 'php artisan atlas:forge:rivals run-battery --dry-run --json',
                'real_execution_command_template' => 'php artisan atlas:forge:rivals run-battery --json',
            ]],
            'model_gap_summary' => [
                'schema_version' => 'atlas.forge.rivals.external_execution_model_gap_summary.v1',
                'status' => 'needs_external_runs',
            ],
            'strong_claim_gate' => [
                'status' => 'blocked_until_real_reproducible_evidence_complete',
            ],
            'required_confirmations_before_any_real_command' => [
                'confirm_runbook_reviewed',
                'confirm_provider_cost',
                'confirm_real_provider_call',
            ],
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $exit = Artisan::call('atlas:forge:rivals', [
            'action' => 'external-runbook-validate',
            '--plan-manifest' => $path,
            '--json' => true,
            '--strict' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame(1, $exit);
        $this->assertSame('external-runbook-validate', $payload['action']);
        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('blocked_before_any_provider_execution', $payload['validation_status']);
        $this->assertContains('plan_manifest_fingerprint_mismatch', $payload['blockers']);
        $this->assertContains('missing_bucket_real_command_missing_confirmation:0:confirm_runbook_reviewed', $payload['blockers']);
        $this->assertContains('missing_bucket_real_command_missing_confirmation:0:confirm_provider_cost', $payload['blockers']);
        $this->assertContains('missing_bucket_real_command_missing_confirmation:0:confirm_real_provider_call', $payload['blockers']);
        $this->assertFalse($payload['real_execution_allowed_by_this_validation']);
        $this->assertFalse($payload['external_provider_call']);
        $this->assertFalse($payload['provider_tokens_spent']);
        $this->assertFalse($payload['score_or_claim_allowed']);
        $this->assertFalse($payload['should_update_provider_topology']);
        $this->assertSame('none', $payload['routing_effect']);
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
