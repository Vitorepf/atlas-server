<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Services\Ai\Programming\ForgeRivals\DeepSwe\AtlasForgeRivalsDeepSweResultIngestService;
use Illuminate\Support\Facades\Artisan;
use ReflectionMethod;
use Tests\TestCase;

final class AtlasForgeRivalsDeepSweCompatibilityTest extends TestCase
{
    private string $fixtureRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureRoot = storage_path('framework/testing/deepswe-fixture-'.str_replace('.', '', uniqid('', true)));
        @mkdir($this->fixtureRoot, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->fixtureRoot);
        parent::tearDown();
    }

    public function test_deepswe_action_imports_harbor_task_without_provider_call_or_solution_content(): void
    {
        $task = $this->writeTask('task-alpha', solution: 'SECRET_REFERENCE_PATCH_SHOULD_NOT_LEAK');

        $payload = $this->runDeepSwe($task);

        $this->assertSame('ok', $payload['status']);
        $this->assertSame('atlas.forge.rivals.deepswe_compatibility.v1', $payload['schema_version']);
        $this->assertSame('deepswe', $payload['case_source']);
        $this->assertSame('harbor', $payload['task_format']);
        $this->assertSame(1, $payload['task_count']);
        $this->assertSame(1, $payload['valid_task_count']);
        $this->assertFalse($payload['external_provider_call']);
        $this->assertFalse($payload['provider_tokens_spent']);
        $this->assertTrue($payload['advisory_only']);
        $this->assertFalse($payload['should_update_provider_topology']);
        $this->assertTrue($payload['never_changes_atlas_decide_topology']);
        $this->assertSame('atlas_decide', $payload['owner_of_model_routing']);
        $this->assertSame('none', $payload['routing_effect']);
        $this->assertSame('Rivals emits measured evidence; Atlas Decide decides model routing.', $payload['canonical_phrase']);

        $case = $payload['tasks'][0];
        $this->assertSame('task-alpha', $case['task_id']);
        $this->assertSame('python', $case['language']);
        $this->assertTrue($case['solution_reference']['present']);
        $this->assertTrue($case['solution_reference']['forbidden_to_agent']);
        $this->assertFalse($case['solution_reference']['content_exposed']);
        $this->assertFalse($case['agent_visible_inputs']['instruction_content_exposed_in_rivals_json']);
        $this->assertFalse($case['agent_visible_inputs']['solution_content_exposed_in_rivals_json']);
        $this->assertSame('tests/test.sh', $case['verifier']['entrypoint']);
        $this->assertSame('tests/test.patch', $case['verifier']['patch']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $case['manifest_hash']);

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertIsString($json);
        $this->assertStringNotContainsString('SECRET_REFERENCE_PATCH_SHOULD_NOT_LEAK', $json);
        $this->assertStringNotContainsString('Implement the behavior described here', $json);
    }

    public function test_deepswe_action_blocks_task_without_programmatic_verifier(): void
    {
        $task = $this->writeTask('task-no-verifier');
        @unlink($task.'/tests/test.sh');

        $payload = $this->runDeepSwe($task);

        $this->assertSame('blocked', $payload['status']);
        $this->assertSame(1, $payload['task_count']);
        $this->assertSame(0, $payload['valid_task_count']);
        $this->assertContains('task-no-verifier:deepswe_verifier_entrypoint_missing:tests/test.sh', $payload['blockers']);
        $this->assertFalse($payload['external_provider_call']);
        $this->assertFalse($payload['provider_tokens_spent']);
    }

    public function test_deepswe_action_discovers_task_root_and_emits_plan_only_pier_command(): void
    {
        $this->writeTask('task-one');
        $this->writeTask('task-two');

        $payload = $this->runDeepSwe($this->fixtureRoot, [
            '--agent' => 'claude-code',
            '--model' => 'anthropic/claude-opus-4-7',
            '--n-tasks' => '1',
            '--sample-seed' => '7',
        ]);

        $this->assertSame('ok', $payload['status']);
        $this->assertSame(2, $payload['task_count']);
        $this->assertSame('plan_only', $payload['pier_plan']['status']);
        $this->assertFalse($payload['pier_plan']['provider_called']);
        $this->assertFalse($payload['pier_plan']['pier_spawned']);
        $this->assertFalse($payload['pier_plan']['docker_spawned']);
        $this->assertSame([
            'pier',
            'run',
            '-p',
            $this->fixtureRoot,
            '--agent',
            'claude-code',
            '--model',
            'anthropic/claude-opus-4-7',
            '--n-tasks',
            '1',
            '--sample-seed',
            '7',
        ], $payload['pier_plan']['command']);
        $this->assertContains('trajectory', $payload['evidence_contract']['required_before_score']);
        $this->assertTrue($payload['claim_policy']['requires_statistical_repeat_for_routing_confidence']);
        $this->assertFalse($payload['claim_policy']['external_claim_allowed']);
        $this->assertSame('atlas.forge.rivals.deepswe_external_benchmark_runbook.v1', $payload['external_benchmark_runbook']['schema_version']);
        $this->assertSame('blocked_until_minimum_external_task_count', $payload['external_benchmark_runbook']['status']);
        $this->assertSame(2, $payload['external_benchmark_runbook']['valid_task_count']);
        $this->assertSame(1, $payload['external_benchmark_runbook']['planned_task_count']);
        $this->assertSame(50, $payload['external_benchmark_runbook']['minimum_external_task_count_for_strong_claim']);
        $this->assertContains('minimum_50_valid_deepswe_tasks_required', $payload['external_benchmark_runbook']['blockers']);
        $this->assertFalse($payload['external_benchmark_runbook']['external_provider_call']);
        $this->assertFalse($payload['external_benchmark_runbook']['provider_tokens_spent']);
        $this->assertFalse($payload['external_benchmark_runbook']['claim_ready']);
        $this->assertFalse($payload['external_benchmark_runbook']['external_claim_allowed']);
        $this->assertFalse($payload['external_benchmark_runbook']['should_update_provider_topology']);
        $this->assertSame('none', $payload['external_benchmark_runbook']['routing_effect']);
    }

    public function test_deepswe_action_emits_external_benchmark_runbook_for_fifty_valid_tasks_without_running_provider(): void
    {
        for ($i = 1; $i <= 50; $i++) {
            $this->writeTask(sprintf('task-%03d', $i));
        }

        $payload = $this->runDeepSwe($this->fixtureRoot, [
            '--agent' => 'cursor-cli',
            '--model' => 'composer-2.5',
            '--sample-seed' => '42',
        ]);

        $this->assertSame('ok', $payload['status']);
        $this->assertSame(50, $payload['valid_task_count']);
        $runbook = $payload['external_benchmark_runbook'];
        $this->assertSame('atlas.forge.rivals.deepswe_external_benchmark_runbook.v1', $runbook['schema_version']);
        $this->assertSame('ready_for_operator_review', $runbook['status']);
        $this->assertSame(50, $runbook['valid_task_count']);
        $this->assertSame(50, $runbook['planned_task_count']);
        $this->assertSame(50, $runbook['minimum_external_task_count_for_strong_claim']);
        $this->assertSame(3, $runbook['minimum_valid_repetitions_per_bucket']);
        $this->assertSame([], $runbook['blockers']);
        $this->assertContains('external_benchmark_claim_gate', $runbook['post_ingest_required_outputs']);
        $this->assertContains('atlas_decide_external_learning_packet', $runbook['post_ingest_required_outputs']);
        $this->assertContains('minimum_50_successful_external_runs', $runbook['claim_gate_requirements']);
        $this->assertContains('statistical_repeat_confidence_ready', $runbook['claim_gate_requirements']);
        $this->assertFalse($runbook['solution_content_exposed_to_agent']);
        $this->assertTrue($runbook['readiness_only']);
        $this->assertFalse($runbook['rivals_starts_pier_or_provider']);
        $this->assertFalse($runbook['external_provider_call']);
        $this->assertFalse($runbook['provider_tokens_spent']);
        $this->assertTrue($runbook['external_provider_call_if_operator_runs_external_runner']);
        $this->assertTrue($runbook['provider_tokens_spent_if_operator_runs_external_runner']);
        $this->assertFalse($runbook['claim_ready']);
        $this->assertFalse($runbook['external_claim_allowed']);
        $this->assertFalse($runbook['score_or_claim_allowed']);
        $this->assertTrue($runbook['advisory_only']);
        $this->assertFalse($runbook['should_update_provider_topology']);
        $this->assertTrue($runbook['never_changes_atlas_decide_topology']);
        $this->assertSame('atlas_decide', $runbook['owner_of_model_routing']);
        $this->assertSame('none', $runbook['routing_effect']);
        $this->assertStringContainsString('pier', $runbook['commands_preview'][1]['command']);
        $this->assertStringContainsString('deepswe-batch-ingest', $runbook['commands_preview'][2]['command']);
        $this->assertFalse($payload['external_provider_call']);
        $this->assertFalse($payload['provider_tokens_spent']);
    }

    public function test_run_arena_can_plan_deepswe_case_set_without_legacy_corpus_or_provider_call(): void
    {
        $this->writeTask('task-arena');

        Artisan::call('atlas:forge:rivals', [
            'action' => 'run-arena',
            '--arm-a' => 'cursor_cli',
            '--arm-a-model' => 'composer_2_5',
            '--arm-b' => 'codex_cli',
            '--arm-b-model' => 'gpt-5.5',
            '--mode' => 'provider_arena',
            '--task-category' => 'bugfix',
            '--case-set' => 'deepswe',
            '--deepswe-path' => $this->fixtureRoot,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame('arena_plan_ready', $payload['verdict']);
        $this->assertSame('deepswe', $payload['case_set']);
        $this->assertSame(1, $payload['case_count']);
        $this->assertSame('deepswe:task-arena', $payload['cases'][0]['case_id']);
        $this->assertSame('deepswe', $payload['cases'][0]['case_source']);
        $this->assertSame('harbor', $payload['cases'][0]['task_format']);
        $this->assertFalse($payload['external_provider_call']);
        $this->assertFalse($payload['provider_tokens_spent']);
        $this->assertTrue($payload['advisory_only']);

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertIsString($json);
        $this->assertStringNotContainsString('Implement the behavior described here', $json);
    }

    public function test_deepswe_ingest_imports_external_result_into_replayable_evidence_without_provider_call(): void
    {
        $task = $this->writeTask('task-ingest', solution: 'SECRET_INGEST_SOLUTION_SHOULD_NOT_LEAK');
        $resultRoot = $this->writePierResultRoot('task-ingest');
        $runId = 'deepswe-ingest-test-'.str_replace('.', '', uniqid('', true));

        Artisan::call('atlas:forge:rivals', [
            'action' => 'deepswe-ingest',
            '--input' => $resultRoot,
            '--deepswe-path' => $task,
            '--run-id' => $runId,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame('atlas.forge.rivals.deepswe_result_ingest.v1', $payload['schema_version']);
        $this->assertSame($runId, $payload['run_id']);
        $this->assertSame('comparable', $payload['verdict']);
        $this->assertTrue($payload['replay_passes']);
        $this->assertFalse($payload['claim_ready']);
        $this->assertFalse($payload['external_claim_allowed']);
        $this->assertFalse($payload['external_provider_call']);
        $this->assertFalse($payload['provider_tokens_spent']);
        $this->assertTrue($payload['advisory_only']);
        $this->assertFalse($payload['should_update_provider_topology']);
        $this->assertTrue($payload['never_changes_atlas_decide_topology']);
        $this->assertSame('atlas_decide', $payload['owner_of_model_routing']);
        $this->assertSame('none', $payload['routing_effect']);
        $this->assertSame('ok', $payload['external_evidence_bundle_status']);
        $this->assertTrue($payload['external_evidence_bundle_verified']);
        $this->assertSame('ok', $payload['trusted_signal_status']);
        $this->assertTrue($payload['trusted_signal_ready']);
        $this->assertTrue($payload['can_feed_provider_performance_ledger']);
        $this->assertTrue($payload['can_feed_atlas_decide_advisory_signal']);
        $this->assertTrue($payload['ledger_projection_ready']);
        $this->assertIsString($payload['ledger_record_command']);
        $this->assertFileExists((string) $payload['external_evidence_bundle_manifest_path']);
        $this->assertSame('atlas.forge.rivals.deepswe_external_evidence_lifecycle.v1', $payload['external_evidence_lifecycle']['schema_version']);
        $this->assertSame('ok', $payload['external_evidence_lifecycle']['status']);
        $this->assertFalse($payload['external_evidence_lifecycle']['external_provider_call']);
        $this->assertFalse($payload['external_evidence_lifecycle']['provider_tokens_spent']);
        $this->assertTrue($payload['external_evidence_lifecycle']['advisory_only']);

        foreach ([
            'collect-evidence-pre',
            'replay-pre',
            'adjudicate',
            'collect-evidence-final',
            'replay-final',
            'external-evidence-bundle',
            'external-evidence-bundle-verify',
            'trusted-signal',
        ] as $phaseName) {
            $this->assertContains($phaseName, array_column($payload['phases'], 'name'));
        }

        $this->assertFileExists((string) $payload['manifest_path']);
        $this->assertFileExists((string) $payload['evidence_pack_path']);
        $this->assertFileExists((string) $payload['scorecard_path']);

        Artisan::call('atlas:forge:rivals', [
            'action' => 'replay',
            '--run-id' => $runId,
            '--stage' => 'final',
            '--json' => true,
        ]);
        $replay = json_decode(Artisan::output(), true);
        $this->assertIsArray($replay);
        $this->assertSame('ok', $replay['status']);
        $this->assertTrue($replay['replay_passes']);

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertIsString($json);
        $this->assertStringNotContainsString('SECRET_INGEST_SOLUTION_SHOULD_NOT_LEAK', $json);
        $this->assertStringNotContainsString('Implement the behavior described here', $json);
    }

    public function test_deepswe_ingest_binds_external_result_to_exported_execution_plan_manifest(): void
    {
        $task = $this->writeTask('task-plan-bound');
        $fingerprint = hash('sha256', 'task-plan-bound-reviewed-plan');
        $planManifest = $this->writeExternalExecutionPlanManifest($fingerprint);
        $resultRoot = $this->writePierResultRoot('task-plan-bound', planFingerprint: $fingerprint);

        Artisan::call('atlas:forge:rivals', [
            'action' => 'deepswe-ingest',
            '--input' => $resultRoot,
            '--deepswe-path' => $task,
            '--plan-manifest' => $planManifest,
            '--run-id' => 'deepswe-ingest-plan-bound-'.str_replace('.', '', uniqid('', true)),
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame('ok', $payload['external_execution_plan_binding']['status']);
        $this->assertSame($fingerprint, $payload['external_execution_plan_binding']['plan_fingerprint']);
        $this->assertSame($planManifest, $payload['external_execution_plan_binding']['plan_manifest_path']);
        $this->assertFalse($payload['external_execution_plan_binding']['external_provider_call']);
        $this->assertFalse($payload['external_execution_plan_binding']['provider_tokens_spent']);
        $this->assertFalse($payload['external_execution_plan_binding']['should_update_provider_topology']);
        $this->assertSame('none', $payload['external_execution_plan_binding']['routing_effect']);
        $manifest = json_decode((string) file_get_contents((string) $payload['manifest_path']), true);
        $this->assertIsArray($manifest);
        $this->assertSame($fingerprint, $manifest['external_execution_plan_fingerprint']);
        $this->assertSame($planManifest, $manifest['external_execution_plan_manifest_path']);
    }

    public function test_deepswe_ingest_blocks_external_result_with_mismatched_execution_plan_fingerprint(): void
    {
        $task = $this->writeTask('task-plan-mismatch');
        $expected = hash('sha256', 'expected-reviewed-plan');
        $actual = hash('sha256', 'actual-different-plan');
        $planManifest = $this->writeExternalExecutionPlanManifest($expected);
        $resultRoot = $this->writePierResultRoot('task-plan-mismatch', planFingerprint: $actual);

        Artisan::call('atlas:forge:rivals', [
            'action' => 'deepswe-ingest',
            '--input' => $resultRoot,
            '--deepswe-path' => $task,
            '--plan-manifest' => $planManifest,
            '--run-id' => 'deepswe-ingest-plan-mismatch-'.str_replace('.', '', uniqid('', true)),
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('blocked', $payload['external_execution_plan_binding']['status']);
        $this->assertContains('atlas:external_execution_plan_fingerprint_mismatch', $payload['blockers']);
        $this->assertContains('rival:external_execution_plan_fingerprint_mismatch', $payload['blockers']);
        $this->assertFalse($payload['claim_ready']);
        $this->assertFalse($payload['external_claim_allowed']);
        $this->assertFalse($payload['external_provider_call']);
        $this->assertFalse($payload['provider_tokens_spent']);
        $this->assertFalse($payload['should_update_provider_topology']);
        $this->assertSame('none', $payload['routing_effect']);
    }

    public function test_deepswe_batch_ingest_aggregates_runs_into_battery_and_matrix_without_provider_call(): void
    {
        $taskRoot = $this->fixtureRoot.'/batch-tasks';
        @mkdir($taskRoot, 0777, true);
        $this->writeTask('task-batch-one', taskRoot: $taskRoot, category: 'bugfix', difficulty: 'L2');
        $this->writeTask('task-batch-two', taskRoot: $taskRoot, category: 'security', difficulty: 'L4');

        $resultRoot = $this->fixtureRoot.'/batch-results';
        $this->writePierTaskResult($resultRoot.'/task-batch-one', 'task-batch-one');
        $this->writePierTaskResult($resultRoot.'/task-batch-two', 'task-batch-two');
        $batteryId = 'deepswe-batch-test-'.str_replace('.', '', uniqid('', true));

        Artisan::call('atlas:forge:rivals', [
            'action' => 'deepswe-batch-ingest',
            '--input' => $resultRoot,
            '--deepswe-path' => $taskRoot,
            '--battery-id' => $batteryId,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame('atlas.forge.rivals.deepswe_batch_ingest.v1', $payload['schema_version']);
        $this->assertSame($batteryId, $payload['battery_id']);
        $this->assertSame(2, $payload['result_count']);
        $this->assertSame(2, $payload['successful_ingest_count']);
        $this->assertCount(2, $payload['run_ids']);
        $this->assertSame('atlas.forge.rivals.external_execution_plan_batch_binding.v1', $payload['external_execution_plan_binding_summary']['schema_version']);
        $this->assertSame('not_provided', $payload['external_execution_plan_binding_summary']['status']);
        $this->assertFalse($payload['external_execution_plan_binding_summary']['plan_manifest_required']);
        $this->assertSame(2, $payload['external_execution_plan_binding_summary']['not_provided_count']);
        $this->assertFalse($payload['external_execution_plan_binding_summary']['external_provider_call']);
        $this->assertFalse($payload['external_execution_plan_binding_summary']['provider_tokens_spent']);
        $this->assertFalse($payload['external_execution_plan_binding_summary']['should_update_provider_topology']);
        $this->assertSame('none', $payload['external_execution_plan_binding_summary']['routing_effect']);
        $this->assertFalse($payload['claim_ready']);
        $this->assertFalse($payload['external_claim_allowed']);
        $this->assertFalse($payload['external_provider_call']);
        $this->assertFalse($payload['provider_tokens_spent']);
        $this->assertTrue($payload['advisory_only']);
        $this->assertFalse($payload['should_update_provider_topology']);
        $this->assertTrue($payload['never_changes_atlas_decide_topology']);
        $this->assertSame('atlas_decide', $payload['owner_of_model_routing']);
        $this->assertSame('none', $payload['routing_effect']);
        $this->assertSame('Rivals emits measured evidence; Atlas Decide decides model routing.', $payload['canonical_phrase']);

        $this->assertContains($payload['battery_evidence_status'], ['ok', 'invalid_missing_evidence']);
        $this->assertContains($payload['battery_replay_status'], ['ok', 'invalid_missing_evidence', 'blocked']);
        $this->assertSame('ok', $payload['ledger_record_status']);
        $this->assertSame(4, $payload['ledger_entries_recorded']);
        $this->assertSame(2, $payload['external_evidence_lifecycle_summary']['ready_count']);
        $this->assertSame(2, $payload['external_evidence_lifecycle_summary']['total_successful_ingests']);
        $this->assertTrue($payload['external_evidence_lifecycle_summary']['all_successful_ingests_lifecycle_ready']);
        $this->assertTrue($payload['external_evidence_lifecycle_summary']['requires_replay_green']);
        $this->assertTrue($payload['external_evidence_lifecycle_summary']['requires_bundle_verified']);
        $this->assertTrue($payload['external_evidence_lifecycle_summary']['requires_trusted_signal_ready']);
        $this->assertFalse($payload['external_evidence_lifecycle_summary']['external_claim_allowed']);
        $this->assertSame('insufficient_evidence', $payload['statistical_repeat_readiness']['status']);
        $this->assertFalse($payload['statistical_repeat_readiness']['claim_ready']);
        $this->assertNotEmpty($payload['category_difficulty_model_summary']);
        foreach ($payload['category_difficulty_model_summary'] as $row) {
            $this->assertContains($row['task_category'], ['bugfix', 'security']);
            $this->assertContains($row['difficulty_level'], ['L2', 'L4']);
            $this->assertFalse($row['statistical_repeat_ready']);
        }
        $this->assertNotEmpty($payload['decide_signals']);
        foreach ($payload['decide_signals'] as $signal) {
            $this->assertTrue($signal['advisory_only']);
            $this->assertFalse($signal['should_update_provider_topology']);
            $this->assertTrue($signal['never_changes_atlas_decide_topology']);
            $this->assertSame('atlas_decide', $signal['owner_of_model_routing']);
            $this->assertSame('none', $signal['routing_effect']);
            $this->assertContains($signal['task_category'], ['bugfix', 'security']);
            $this->assertContains($signal['difficulty_level'], ['L2', 'L4']);
        }
        $this->assertSame('ok', $payload['decide_model_intelligence_map_status']);
        $this->assertSame(2, $payload['decide_model_intelligence_map_segments']);
        $this->assertIsArray($payload['atlas_decide_external_learning_packet']);
        $this->assertSame('atlas.forge.rivals.atlas_decide_learning_packet.v1', $payload['atlas_decide_external_learning_packet']['schema_version']);
        $this->assertFalse($payload['atlas_decide_external_learning_packet']['score_or_claim_allowed']);
        $this->assertFalse($payload['atlas_decide_external_learning_packet']['should_update_provider_topology']);
        $this->assertSame('none', $payload['atlas_decide_external_learning_packet']['routing_effect']);
        $this->assertTrue($payload['decide_model_intelligence_map']['advisory_only']);
        $this->assertFalse($payload['decide_model_intelligence_map']['should_update_provider_topology']);
        $this->assertTrue($payload['decide_model_intelligence_map']['never_changes_atlas_decide_topology']);
        $this->assertSame('atlas_decide', $payload['decide_model_intelligence_map']['owner_of_model_routing']);
        $this->assertSame('none', $payload['decide_model_intelligence_map']['routing_effect']);
        $this->assertFalse($payload['decide_model_intelligence_map']['external_provider_call']);
        $this->assertFalse($payload['decide_model_intelligence_map']['provider_tokens_spent']);
        foreach ($payload['decide_model_intelligence_map']['segments'] as $segment) {
            $this->assertContains($segment['task_category'], ['bugfix', 'security']);
            $this->assertContains($segment['difficulty_level'], ['L2', 'L4']);
            $this->assertTrue($segment['advisory_only']);
            $this->assertFalse($segment['should_update_provider_topology']);
            $this->assertSame('none', $segment['routing_effect']);
        }
        $this->assertSame('atlas.forge.rivals.external_benchmark_claim_gate.v1', $payload['external_benchmark_claim_gate']['schema_version']);
        $this->assertSame('blocked_until_external_benchmark_evidence_complete', $payload['external_benchmark_claim_gate']['status']);
        $this->assertSame(2, $payload['external_benchmark_claim_gate']['successful_external_run_count']);
        $this->assertSame(50, $payload['external_benchmark_claim_gate']['minimum_successful_external_runs_for_strong_claim']);
        $this->assertFalse($payload['external_benchmark_claim_gate']['requirements']['minimum_50_successful_external_runs']);
        $this->assertFalse($payload['external_benchmark_claim_gate']['requirements']['statistical_repeat_confidence_ready']);
        $this->assertTrue($payload['external_benchmark_claim_gate']['requirements']['human_external_certification_required']);
        $this->assertFalse($payload['external_benchmark_claim_gate']['requirements']['external_rivals_certification_unlocked']);
        $this->assertContains('minimum_50_successful_external_runs', $payload['external_benchmark_claim_gate']['blockers']);
        $this->assertContains('statistical_repeat_confidence_ready', $payload['external_benchmark_claim_gate']['blockers']);
        $this->assertFalse($payload['external_benchmark_claim_gate']['evidence_ready_for_human_certification']);
        $this->assertFalse($payload['external_benchmark_claim_gate']['claim_ready']);
        $this->assertFalse($payload['external_benchmark_claim_gate']['external_claim_allowed']);
        $this->assertFalse($payload['external_benchmark_claim_gate']['score_or_claim_allowed']);
        $this->assertFalse($payload['external_benchmark_claim_gate']['external_provider_call']);
        $this->assertFalse($payload['external_benchmark_claim_gate']['provider_tokens_spent']);
        $this->assertTrue($payload['external_benchmark_claim_gate']['advisory_only']);
        $this->assertFalse($payload['external_benchmark_claim_gate']['should_update_provider_topology']);
        $this->assertTrue($payload['external_benchmark_claim_gate']['never_changes_atlas_decide_topology']);
        $this->assertSame('atlas_decide', $payload['external_benchmark_claim_gate']['owner_of_model_routing']);
        $this->assertSame('none', $payload['external_benchmark_claim_gate']['routing_effect']);
        $this->assertArrayHasKey('atlas_decide_recommendation', $payload['matrix_summary']);

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertIsString($json);
        $this->assertStringNotContainsString('Implement the behavior described here', $json);
    }

    public function test_deepswe_batch_ingest_requires_all_results_to_bind_to_exported_execution_plan_manifest_when_provided(): void
    {
        $taskRoot = $this->fixtureRoot.'/batch-plan-tasks';
        @mkdir($taskRoot, 0777, true);
        $this->writeTask('task-plan-batch-one', taskRoot: $taskRoot, category: 'bugfix', difficulty: 'L2');
        $this->writeTask('task-plan-batch-two', taskRoot: $taskRoot, category: 'security', difficulty: 'L4');

        $fingerprint = 'plan-batch-'.substr(hash('sha256', __METHOD__), 0, 12);
        $planManifest = $this->writeExternalExecutionPlanManifest($fingerprint);
        $resultRoot = $this->fixtureRoot.'/batch-plan-results';
        $this->writePierTaskResult($resultRoot.'/task-plan-batch-one', 'task-plan-batch-one', planFingerprint: $fingerprint);
        $this->writePierTaskResult($resultRoot.'/task-plan-batch-two', 'task-plan-batch-two', planFingerprint: $fingerprint);
        $batteryId = 'deepswe-batch-plan-test-'.str_replace('.', '', uniqid('', true));

        Artisan::call('atlas:forge:rivals', [
            'action' => 'deepswe-batch-ingest',
            '--input' => $resultRoot,
            '--deepswe-path' => $taskRoot,
            '--battery-id' => $batteryId,
            '--plan-manifest' => $planManifest,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame('ok', $payload['status']);
        $summary = $payload['external_execution_plan_binding_summary'];
        $this->assertSame('atlas.forge.rivals.external_execution_plan_batch_binding.v1', $summary['schema_version']);
        $this->assertSame('ok', $summary['status']);
        $this->assertTrue($summary['plan_manifest_required']);
        $this->assertSame($planManifest, $summary['plan_manifest_path']);
        $this->assertSame($fingerprint, $summary['plan_fingerprint']);
        $this->assertSame(2, $summary['result_count']);
        $this->assertSame(2, $summary['bound_count']);
        $this->assertSame(0, $summary['blocked_count']);
        $this->assertSame(0, $summary['not_provided_count']);
        $this->assertTrue($summary['all_successful_ingests_bound_to_same_plan']);
        $this->assertSame([], $summary['blockers']);
        $this->assertFalse($summary['external_provider_call']);
        $this->assertFalse($summary['provider_tokens_spent']);
        $this->assertTrue($summary['advisory_only']);
        $this->assertFalse($summary['should_update_provider_topology']);
        $this->assertTrue($summary['never_changes_atlas_decide_topology']);
        $this->assertSame('atlas_decide', $summary['owner_of_model_routing']);
        $this->assertSame('none', $summary['routing_effect']);

        foreach ($payload['ingests'] as $ingest) {
            $this->assertSame('ok', $ingest['external_execution_plan_binding_status']);
            $this->assertSame($fingerprint, $ingest['external_execution_plan_fingerprint']);
        }
    }

    public function test_deepswe_batch_ingest_blocks_when_any_result_does_not_match_execution_plan_manifest(): void
    {
        $taskRoot = $this->fixtureRoot.'/batch-plan-mismatch-tasks';
        @mkdir($taskRoot, 0777, true);
        $this->writeTask('task-plan-mismatch-one', taskRoot: $taskRoot, category: 'bugfix', difficulty: 'L2');
        $this->writeTask('task-plan-mismatch-two', taskRoot: $taskRoot, category: 'security', difficulty: 'L4');

        $expected = 'expected-'.substr(hash('sha256', __METHOD__), 0, 12);
        $actual = 'actual-'.substr(hash('sha256', __CLASS__), 0, 12);
        $planManifest = $this->writeExternalExecutionPlanManifest($expected);
        $resultRoot = $this->fixtureRoot.'/batch-plan-mismatch-results';
        $this->writePierTaskResult($resultRoot.'/task-plan-mismatch-one', 'task-plan-mismatch-one', planFingerprint: $expected);
        $this->writePierTaskResult($resultRoot.'/task-plan-mismatch-two', 'task-plan-mismatch-two', planFingerprint: $actual);
        $batteryId = 'deepswe-batch-plan-mismatch-test-'.str_replace('.', '', uniqid('', true));

        Artisan::call('atlas:forge:rivals', [
            'action' => 'deepswe-batch-ingest',
            '--input' => $resultRoot,
            '--deepswe-path' => $taskRoot,
            '--battery-id' => $batteryId,
            '--plan-manifest' => $planManifest,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame('blocked', $payload['status']);
        $summary = $payload['external_execution_plan_binding_summary'];
        $this->assertSame('blocked', $summary['status']);
        $this->assertTrue($summary['plan_manifest_required']);
        $this->assertSame(2, $summary['result_count']);
        $this->assertSame(1, $summary['bound_count']);
        $this->assertSame(1, $summary['blocked_count']);
        $this->assertFalse($summary['all_successful_ingests_bound_to_same_plan']);
        $this->assertContains('task-plan-mismatch-two:atlas:external_execution_plan_fingerprint_mismatch', $summary['blockers']);
        $this->assertContains('task-plan-mismatch-two:rival:external_execution_plan_fingerprint_mismatch', $summary['blockers']);
        $this->assertContains('task-plan-mismatch-two:atlas:external_execution_plan_fingerprint_mismatch', $payload['blockers']);
        $this->assertFalse($payload['claim_ready']);
        $this->assertFalse($payload['external_claim_allowed']);
        $this->assertFalse($summary['external_provider_call']);
        $this->assertFalse($summary['provider_tokens_spent']);
        $this->assertFalse($summary['should_update_provider_topology']);
        $this->assertSame('none', $summary['routing_effect']);
    }

    public function test_deepswe_ingest_blocks_missing_trajectory_before_claim_or_score(): void
    {
        $task = $this->writeTask('task-missing-trajectory');
        $resultRoot = $this->writePierResultRoot('task-missing-trajectory', missingTrajectory: true);

        Artisan::call('atlas:forge:rivals', [
            'action' => 'deepswe-ingest',
            '--input' => $resultRoot,
            '--deepswe-path' => $task,
            '--run-id' => 'deepswe-ingest-blocked-'.str_replace('.', '', uniqid('', true)),
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('atlas:deepswe_trajectory_missing', $payload['blockers']);
        $this->assertContains('rival:deepswe_trajectory_missing', $payload['blockers']);
        $this->assertTrue($payload['external_evidence_bundle_verified']);
        $this->assertSame('blocked', $payload['external_evidence_lifecycle']['status']);
        $this->assertFalse($payload['trusted_signal_ready']);
        $this->assertFalse($payload['can_feed_provider_performance_ledger']);
        $this->assertFalse($payload['can_feed_atlas_decide_advisory_signal']);
        $this->assertFalse($payload['ledger_projection_ready']);
        $this->assertFalse($payload['claim_ready']);
        $this->assertFalse($payload['external_claim_allowed']);
        $this->assertFalse($payload['external_provider_call']);
        $this->assertFalse($payload['provider_tokens_spent']);
    }

    public function test_external_benchmark_claim_gate_can_be_ready_for_human_review_without_unlocking_external_claim(): void
    {
        $service = app(AtlasForgeRivalsDeepSweResultIngestService::class);
        $method = new ReflectionMethod($service, 'externalBenchmarkClaimGate');
        $method->setAccessible(true);

        $payload = $method->invoke(
            $service,
            array_map(static fn (int $i): string => 'deepswe-run-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT), range(1, 50)),
            ['status' => 'ok'],
            ['status' => 'ok'],
            ['status' => 'ok', 'blockers' => []],
            ['status' => 'ok', 'entries_recorded' => 100],
            [
                'external_claim_readiness' => ['status' => 'ready_for_human_review'],
                'aggregates' => [
                    'statistical_repeat_readiness' => [
                        'status' => 'confidence_ready',
                        'confidence_ready' => true,
                    ],
                ],
            ],
            [
                'atlas_decide_learning_packet' => [
                    'status' => 'ok',
                    'dimensional_signal_quality' => [
                        'status' => 'complete',
                        'complete_for_policy_review' => true,
                    ],
                ],
            ],
        );

        $this->assertSame('atlas.forge.rivals.external_benchmark_claim_gate.v1', $payload['schema_version']);
        $this->assertSame('ready_for_human_certification_external_claim_still_blocked', $payload['status']);
        $this->assertSame([], $payload['blockers']);
        $this->assertTrue($payload['evidence_ready_for_human_certification']);
        $this->assertTrue($payload['requirements']['human_external_certification_required']);
        $this->assertFalse($payload['requirements']['external_rivals_certification_unlocked']);
        $this->assertFalse($payload['claim_ready']);
        $this->assertFalse($payload['external_claim_allowed']);
        $this->assertFalse($payload['score_or_claim_allowed']);
        $this->assertTrue($payload['advisory_only']);
        $this->assertFalse($payload['should_update_provider_topology']);
        $this->assertSame('none', $payload['routing_effect']);
    }

    /**
     * @param  array<string,string>  $options
     * @return array<string,mixed>
     */
    private function runDeepSwe(string $path, array $options = []): array
    {
        Artisan::call('atlas:forge:rivals', array_merge([
            'action' => 'deepswe',
            '--deepswe-path' => $path,
            '--json' => true,
        ], $options));

        $decoded = json_decode(Artisan::output(), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function writeTask(
        string $id,
        string $solution = 'reference patch',
        ?string $taskRoot = null,
        string $category = 'bugfix',
        string $difficulty = 'L3',
    ): string {
        $dir = ($taskRoot ?? $this->fixtureRoot).'/'.$id;
        @mkdir($dir.'/tests', 0777, true);
        @mkdir($dir.'/environment', 0777, true);
        @mkdir($dir.'/solution', 0777, true);

        file_put_contents($dir.'/task.toml', implode("\n", [
            'id = "'.$id.'"',
            'language = "python"',
            'repository = "example/repo"',
            'base_commit = "abc123"',
            'prebuilt_image = "registry.example/'.$id.':latest"',
            'category = "'.$category.'"',
            'difficulty = "'.$difficulty.'"',
            '',
        ]));
        file_put_contents($dir.'/instruction.md', 'Implement the behavior described here without reading solution.');
        file_put_contents($dir.'/tests/test.sh', "#!/usr/bin/env bash\npython -m pytest tests\n");
        file_put_contents($dir.'/tests/test.patch', "diff --git a/tests/test_example.py b/tests/test_example.py\n");
        file_put_contents($dir.'/environment/Dockerfile', "FROM python:3.12\n");
        file_put_contents($dir.'/solution/reference.patch', $solution);

        return $dir;
    }

    private function writePierResultRoot(string $taskId, bool $missingTrajectory = false, ?string $planFingerprint = null): string
    {
        $root = $this->fixtureRoot.'/pier-results-'.$taskId;
        $this->writePierTaskResult($root, $taskId, $missingTrajectory, $planFingerprint);

        return $root;
    }

    private function writePierTaskResult(string $root, string $taskId, bool $missingTrajectory = false, ?string $planFingerprint = null): void
    {
        $this->writePierArmResult($root.'/atlas', $taskId, 'atlas_forge', 'claude', 'sonnet', $missingTrajectory, $planFingerprint);
        $this->writePierArmResult($root.'/rival', $taskId, 'codex_cli', 'codex', 'gpt-5.5', $missingTrajectory, $planFingerprint);
    }

    private function writePierArmResult(
        string $dir,
        string $taskId,
        string $agent,
        string $provider,
        string $model,
        bool $missingTrajectory,
        ?string $planFingerprint = null,
    ): void {
        @mkdir($dir, 0777, true);
        file_put_contents($dir.'/patch.diff', "diff --git a/app/example.py b/app/example.py\n+print('fixed by {$agent}')\n");
        file_put_contents($dir.'/stdout.log', "provider stdout for {$agent}\n");
        file_put_contents($dir.'/stderr.log', '');
        file_put_contents($dir.'/test.log', "verifier passed for {$agent}\n");
        if (! $missingTrajectory) {
            file_put_contents($dir.'/trajectory.jsonl', json_encode(['event' => 'step', 'agent' => $agent])."\n");
        }
        $result = [
            'task_id' => $taskId,
            'agent' => $agent,
            'provider' => $provider,
            'model' => $model,
            'model_id' => $model,
            'exit_code' => 0,
            'test_exit_code' => 0,
            'patch_path' => 'patch.diff',
            'trajectory_path' => 'trajectory.jsonl',
            'stdout_path' => 'stdout.log',
            'stderr_path' => 'stderr.log',
            'test_log_path' => 'test.log',
            'duration_ms' => 1234,
            'changed_files' => ['app/example.py'],
            'out_of_scope_files' => [],
            'bytecode_artifacts' => [],
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'verifier' => [
                'exit_code' => 0,
            ],
        ];
        if ($planFingerprint !== null) {
            $result['external_execution_plan_fingerprint'] = $planFingerprint;
        }
        file_put_contents($dir.'/result.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function writeExternalExecutionPlanManifest(string $fingerprint): string
    {
        $path = $this->fixtureRoot.'/external-execution-runbook-'.$fingerprint.'.json';
        file_put_contents($path, json_encode([
            'schema_version' => 'atlas.forge.rivals.external_execution_runbook_manifest.v1',
            'status' => 'plan_only_requires_operator_review',
            'plan_fingerprint' => $fingerprint,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo) {
                $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
            }
        }
        @rmdir($dir);
    }
}
