<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasEngineeringBenchmarkResult;
use App\Models\AtlasEngineeringBenchmarkRun;
use App\Services\Ai\Router\AtlasAiHyperflowRivalsBatteryService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasAiHyperflowCertificationApiTest extends TestCase
{
    private array $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
        $this->bootBenchmarkSchema();
        $this->seedPassingHyperflowBattery();
    }

    public function test_api_exposes_hyperflow_certification_without_false_ready_claim(): void
    {
        $response = $this->getJson('/ai/hyperflow/certification', $this->headers)
            ->assertStatus(503)
            ->assertJsonPath('schema_version', 'atlas.ai.hyperflow_certification.v1')
            ->assertJsonPath('status', 'blocked')
            ->assertJsonPath('surfaces.certification', '/ai/hyperflow/certification')
            ->assertJsonPath('surfaces.rivals_battery', '/ai/hyperflow/rivals-battery')
            ->assertJsonPath('surfaces.rivals_battery_run', '/ai/hyperflow/rivals-battery/run')
            ->assertJsonPath('surfaces.rivals_battery_external_evidence', '/ai/hyperflow/rivals-battery/external-evidence')
            ->assertJsonPath('surfaces.rivals_battery_external_evidence_template', '/ai/hyperflow/rivals-battery/external-evidence/template')
            ->assertJsonPath('surfaces.rivals_battery_external_evidence_candidates', '/ai/hyperflow/rivals-battery/external-evidence/candidates')
            ->assertJsonPath('surfaces.rivals_battery_external_evidence_runbook', '/ai/hyperflow/rivals-battery/external-evidence/runbook')
            ->assertJsonPath('surfaces.rivals_battery_external_evidence_preflight', '/ai/hyperflow/rivals-battery/external-evidence/preflight')
            ->assertJsonPath('surfaces.rivals_battery_external_evidence_export', '/ai/hyperflow/rivals-battery/external-evidence/export')
            ->assertJsonPath('surfaces.rivals_battery_external_evidence_import', '/ai/hyperflow/rivals-battery/external-evidence/import')
            ->assertJsonPath('surfaces.router_readiness', '/ai/router-runtime/readiness')
            ->assertJsonPath('claim_policy.ready_to_replace_claude_code_codex', false)
            ->assertJsonPath('claim_policy.declare_100x_allowed', false)
            ->assertJsonPath('claim_policy.requires_verifiable_benchmark', true)
            ->assertJsonPath('completion_audit.schema_version', 'atlas.ai.hyperflow_completion_audit.v1')
            ->assertJsonPath('completion_audit.status', 'blocked')
            ->assertJsonPath('completion_audit.summary.requirements_total', 10)
            ->assertJsonPath('completion_audit.summary.ready_criteria_total', 7)
            ->assertJsonPath('external_evidence_gate.schema_version', 'atlas.ai.hyperflow_external_evidence_gate.v1')
            ->assertJsonPath('external_evidence_gate.status', 'blocked')
            ->assertJsonPath('external_evidence_gate.check_id', 'rivals_battery.external_provider_execution')
            ->assertJsonPath('external_evidence_gate.blocking_reason', 'external_claude_code_codex_evidence_required')
            ->assertJsonPath('external_evidence_gate.accepted_surfaces.manual_record_api', '/ai/hyperflow/rivals-battery/external-evidence')
            ->assertJsonPath('external_evidence_gate.accepted_surfaces.evidence_pack_template_api', '/ai/hyperflow/rivals-battery/external-evidence/template')
            ->assertJsonPath('external_evidence_gate.accepted_surfaces.evidence_candidates_api', '/ai/hyperflow/rivals-battery/external-evidence/candidates')
            ->assertJsonPath('external_evidence_gate.accepted_surfaces.evidence_runbook_api', '/ai/hyperflow/rivals-battery/external-evidence/runbook')
            ->assertJsonPath('external_evidence_gate.accepted_surfaces.evidence_preflight_api', '/ai/hyperflow/rivals-battery/external-evidence/preflight')
            ->assertJsonPath('external_evidence_gate.accepted_surfaces.evidence_pack_export_api', '/ai/hyperflow/rivals-battery/external-evidence/export')
            ->assertJsonPath('external_evidence_gate.accepted_surfaces.evidence_pack_import_api', '/ai/hyperflow/rivals-battery/external-evidence/import')
            ->assertJsonPath('external_evidence_gate.accepted_surfaces.cli_template', 'php artisan atlas:ai:hyperflow evidence-template --json')
            ->assertJsonPath('external_evidence_gate.accepted_surfaces.cli_candidates', 'php artisan atlas:ai:hyperflow evidence-candidates --provider=codex_cli --json')
            ->assertJsonPath('external_evidence_gate.accepted_surfaces.cli_runbook', 'php artisan atlas:ai:hyperflow evidence-runbook --json')
            ->assertJsonPath('external_evidence_gate.accepted_surfaces.cli_preflight', 'php artisan atlas:ai:hyperflow evidence-preflight --forge-run-ids=<claude_run>,<codex_run> --json')
            ->assertJsonPath('external_evidence_gate.required_manual_payload.external_provider_call', true)
            ->assertJsonPath('external_evidence_gate.required_evidence_pack_fields.hyperflow_external_rivals_certification_eligible', true)
            ->assertJsonPath('writes', false);

        $checks = $response->json('checks');
        $this->assertIsArray($checks);
        $this->assertContains('router_runtime_readiness', array_column($checks, 'id'));
        $this->assertContains('specialist_flows.deep_contracts', array_column($checks, 'id'));
        $this->assertContains('delegation.dev_forge_boundaries', array_column($checks, 'id'));
        $this->assertContains('audit.receipts_persistence_telemetry', array_column($checks, 'id'));
        $this->assertContains('docs.hyperflow_canonical_contracts', array_column($checks, 'id'));
        $this->assertContains('rivals_battery.claude_code_codex', array_column($checks, 'id'));
        $this->assertContains('rivals_battery.external_provider_execution', array_column($checks, 'id'));
        $this->assertNotContains('rivals_battery.claude_code_codex', $response->json('remaining_blockers'));
        $this->assertContains('rivals_battery.external_provider_execution', $response->json('remaining_blockers'));

        $delegationCheck = collect($checks)->firstWhere('id', 'delegation.dev_forge_boundaries');
        $this->assertSame('passed', $delegationCheck['status']);
        $this->assertSame('delegate_to_other_flow', data_get($delegationCheck, 'evidence.debug_workspace_delegates_to_dev.runtime.delegation.status'));
        $this->assertSame('atlas_dev', data_get($delegationCheck, 'evidence.debug_workspace_delegates_to_dev.runtime.delegation.target_flow_id'));
        $this->assertSame('delegated', data_get($delegationCheck, 'evidence.debug_workspace_delegates_to_dev.execution.status'));
        $this->assertSame('delegate_to_other_flow', data_get($delegationCheck, 'evidence.review_workspace_delegates_to_dev.runtime.delegation.status'));
        $this->assertSame('atlas_dev', data_get($delegationCheck, 'evidence.review_workspace_delegates_to_dev.runtime.delegation.target_flow_id'));
        $this->assertSame('delegated', data_get($delegationCheck, 'evidence.review_workspace_delegates_to_dev.execution.status'));

        $docsCheck = collect($checks)->firstWhere('id', 'docs.hyperflow_canonical_contracts');
        $this->assertSame('passed', $docsCheck['status']);
        $this->assertSame('docs/engineering-knowledge-base/atlas-hyperflow-certification-runbook-v1.md', data_get($docsCheck, 'evidence.certification_runbook_doc'));
        $this->assertSame(true, data_get($docsCheck, 'evidence.certification_runbook_doc_present'));

        $requirements = $response->json('completion_audit.requirements');
        $readyCriteria = $response->json('completion_audit.ready_criteria');
        $this->assertCount(10, $requirements);
        $this->assertCount(7, $readyCriteria);

        $rivalsRequirement = collect($requirements)->firstWhere('id', 'rivals_battery_claude_code_codex');
        $this->assertSame('blocked', $rivalsRequirement['status']);
        $this->assertContains('rivals_battery.external_provider_execution', $rivalsRequirement['blockers']);
        $this->assertContains('api:/ai/hyperflow/rivals-battery/external-evidence', $rivalsRequirement['artifact_refs']);
        $this->assertContains('api:/ai/hyperflow/rivals-battery/external-evidence/export', $rivalsRequirement['artifact_refs']);
        $this->assertContains('api:/ai/hyperflow/rivals-battery/external-evidence/import', $rivalsRequirement['artifact_refs']);

        $canonicalDocsRequirement = collect($requirements)->firstWhere('id', 'canonical_docs');
        $this->assertSame('passed', $canonicalDocsRequirement['status']);
        $this->assertContains('docs/engineering-knowledge-base/atlas-hyperflow-completion-audit-v1.md', $canonicalDocsRequirement['artifact_refs']);
        $this->assertContains('docs/engineering-knowledge-base/atlas-hyperflow-certification-runbook-v1.md', $canonicalDocsRequirement['artifact_refs']);

        $noFalseCompletionCriterion = collect($readyCriteria)->firstWhere('id', 'no_completion_without_verifiable_evidence');
        $this->assertSame('blocked', $noFalseCompletionCriterion['status']);
        $this->assertSame(['rivals_battery.external_provider_execution'], $noFalseCompletionCriterion['blockers']);
    }

    public function test_api_exposes_hyperflow_rivals_battery_contract_replay(): void
    {
        $response = $this->getJson('/ai/hyperflow/rivals-battery', $this->headers)
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.ai.hyperflow_rivals_battery.v1')
            ->assertJsonPath('status', 'passed')
            ->assertJsonPath('ready', true)
            ->assertJsonPath('suite_slug', 'atlas-hyperflow-rivals-v1')
            ->assertJsonPath('rivals.0', 'claude_code')
            ->assertJsonPath('rivals.1', 'codex')
            ->assertJsonPath('claim_policy.no_100x_claim_without_passing_run', true)
            ->assertJsonPath('claim_policy.requires_paired_or_recorded_baseline', true)
            ->assertJsonPath('latest_run.status', 'passed')
            ->assertJsonPath('latest_run.pass_rate', 100)
            ->assertJsonPath('latest_run.average_score', 100)
            ->assertJsonPath('latest_run.release_gate_status', 'passed')
            ->assertJsonPath('latest_run.external_provider_execution.status', 'blocked')
            ->assertJsonPath('latest_run.external_provider_execution.checks.external_provider_call', false);

        $this->assertGreaterThanOrEqual(10, $response->json('required_case_count'));
        $this->assertSame($response->json('required_case_count'), $response->json('active_canonical_case_count'));
    }

    public function test_api_exposes_external_evidence_pack_template(): void
    {
        $this->getJson('/ai/hyperflow/rivals-battery/external-evidence/template', $this->headers)
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.ai.hyperflow_external_evidence_pack_template.v1')
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('writes', false)
            ->assertJsonPath('template.hyperflow_external_rivals_certification_eligible', true)
            ->assertJsonPath('template.external_provider_call', true)
            ->assertJsonPath('template.provider_results.claude_code.status', 'passed')
            ->assertJsonPath('template.provider_results.codex_cli.status', 'passed')
            ->assertJsonPath('import.api', '/ai/hyperflow/rivals-battery/external-evidence/import')
            ->assertJsonPath('validation_contract.rejects_local_fake_or_replay_only_packs', true);
    }

    public function test_api_runs_and_persists_hyperflow_rivals_battery_case_results(): void
    {
        AtlasEngineeringBenchmarkResult::query()->delete();
        AtlasEngineeringBenchmarkRun::query()->delete();

        $response = $this->postJson('/ai/hyperflow/rivals-battery/run', [
            'confirm_run' => true,
            'triggered_by' => 'feature-test',
        ], $this->headers)
            ->assertCreated()
            ->assertJsonPath('schema_version', 'atlas.ai.hyperflow_rivals_battery.v1')
            ->assertJsonPath('status', 'passed')
            ->assertJsonPath('ready', true)
            ->assertJsonPath('latest_run.status', 'passed')
            ->assertJsonPath('latest_run.pass_rate', 100)
            ->assertJsonPath('latest_run.average_score', 100)
            ->assertJsonPath('receipt.schema_version', 'atlas.ai.hyperflow_rivals_battery_receipt.v1')
            ->assertJsonPath('writes', true);

        $this->assertSame($response->json('required_case_count'), AtlasEngineeringBenchmarkResult::query()->count());
        $this->assertSame($response->json('required_case_count'), AtlasEngineeringBenchmarkResult::query()->where('passed', true)->count());
    }

    public function test_manual_external_rivals_evidence_records_but_does_not_certify_replacement(): void
    {
        $this->postJson('/ai/hyperflow/rivals-battery/external-evidence', [
            'confirm_external_evidence' => true,
            'external_provider_call' => true,
            'approved_by' => 'operator',
            'provider_tokens_spent' => 12000,
            'protocol_valid' => true,
            'comparable' => true,
            'operator_approved' => true,
            'evidence_receipt_hash' => str_repeat('e', 64),
            'provider_results' => [
                'claude_code' => [
                    'status' => 'passed',
                    'score' => 94,
                    'duration_ms' => 180000,
                    'evidence_hash' => str_repeat('c', 64),
                    'receipt_ref' => 'claude-code-run-1',
                ],
                'codex' => [
                    'status' => 'passed',
                    'score' => 96,
                    'duration_ms' => 170000,
                    'evidence_hash' => str_repeat('d', 64),
                    'receipt_ref' => 'codex-run-1',
                ],
            ],
        ], $this->headers)
            ->assertCreated()
            ->assertJsonPath('status', 'external_evidence_recorded')
            ->assertJsonPath('ready', true)
            ->assertJsonPath('external_evidence.status', 'blocked')
            ->assertJsonPath('external_evidence.checks.external_provider_call', true)
            ->assertJsonPath('external_evidence.checks.claude_code_baseline_present', true)
            ->assertJsonPath('external_evidence.checks.codex_baseline_present', true)
            ->assertJsonPath('external_evidence.checks.evidence_source_present', false)
            ->assertJsonPath('receipt.evidence_receipt_hash', str_repeat('e', 64))
            ->assertJsonPath('receipt.schema_version', 'atlas.ai.hyperflow_external_rivals_evidence_receipt.v1');

        $this->getJson('/ai/hyperflow/certification', $this->headers)
            ->assertStatus(503)
            ->assertJsonPath('status', 'blocked')
            ->assertJsonPath('external_evidence_gate.status', 'blocked')
            ->assertJsonPath('completion_audit.status', 'blocked')
            ->assertJsonPath('claim_policy.ready_to_replace_claude_code_codex', false)
            ->assertJsonPath('claim_policy.declare_100x_allowed', false)
            ->assertJsonPath('summary.failed', 1);
    }

    public function test_external_rivals_evidence_requires_real_provider_receipts(): void
    {
        $this->postJson('/ai/hyperflow/rivals-battery/external-evidence', [
            'confirm_external_evidence' => true,
            'external_provider_call' => true,
            'approved_by' => 'operator',
            'protocol_valid' => true,
            'comparable' => true,
            'operator_approved' => true,
            'evidence_receipt_hash' => str_repeat('e', 64),
            'provider_results' => [
                'claude_code' => [
                    'status' => 'passed',
                    'score' => 94,
                    'evidence_hash' => 'not-a-hash',
                ],
                'codex' => [
                    'status' => 'failed',
                    'score' => 96,
                    'evidence_hash' => str_repeat('d', 64),
                ],
            ],
        ], $this->headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'provider_results.claude_code.evidence_hash',
                'provider_results.codex.status',
            ]);
    }

    public function test_external_rivals_evidence_import_rejects_local_or_ineligible_pack(): void
    {
        $path = $this->writeEvidencePack([
            'schema_version' => 'atlas.forge.rivals.evidence_pack.v2',
            'run_id' => 'local-fake-pack',
            'hyperflow_external_rivals_certification_eligible' => false,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'is_comparable_real_run' => false,
            'claim_ready' => false,
            'provider_results' => [],
        ]);

        $this->postJson('/ai/hyperflow/rivals-battery/external-evidence/import', [
            'confirm_external_evidence_import' => true,
            'operator_approved' => true,
            'approved_by' => 'operator',
            'evidence_pack_path' => $path,
        ], $this->headers)
            ->assertStatus(422)
            ->assertJsonPath('status', 'external_evidence_import_blocked')
            ->assertJsonPath('blocking_reason', 'evidence_pack_not_eligible_for_hyperflow_certification');
    }

    public function test_external_rivals_evidence_import_records_approved_pack_and_certifies(): void
    {
        $path = $this->writeEvidencePack($this->validExternalEvidencePack());

        $this->postJson('/ai/hyperflow/rivals-battery/external-evidence/import', [
            'confirm_external_evidence_import' => true,
            'operator_approved' => true,
            'approved_by' => 'operator',
            'evidence_pack_path' => $path,
        ], $this->headers)
            ->assertCreated()
            ->assertJsonPath('status', 'external_evidence_recorded')
            ->assertJsonPath('external_evidence.status', 'passed')
            ->assertJsonPath('imported_evidence_pack.path', $path);

        $this->getJson('/ai/hyperflow/certification', $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'passed')
            ->assertJsonPath('summary.failed', 0);
    }

    public function test_api_exports_real_forge_runs_into_importable_external_evidence_pack(): void
    {
        $runsRoot = storage_path('framework/testing/hyperflow-forge-rivals-api/runs');
        config()->set('atlas_rivals.runs_root', $runsRoot);
        $this->writeForgeRivalsRunFixture('hf-api-real-claude', 'claude_sonnet');
        $this->writeForgeRivalsRunFixture('hf-api-real-codex', 'codex');
        $outputPath = storage_path('framework/testing/hyperflow-forge-rivals-api/hyperflow-export.json');

        $this->postJson('/ai/hyperflow/rivals-battery/external-evidence/export', [
            'confirm_external_evidence_export' => true,
            'operator_approved' => true,
            'approved_by' => 'operator',
            'forge_run_ids' => ['hf-api-real-claude', 'hf-api-real-codex'],
            'output_path' => $outputPath,
        ], $this->headers)
            ->assertCreated()
            ->assertJsonPath('status', 'external_evidence_exported')
            ->assertJsonPath('ready', true)
            ->assertJsonPath('evidence_pack.path', $outputPath)
            ->assertJsonPath('next_action', 'import_with: php artisan atlas:ai:hyperflow import-evidence --evidence-pack='.$outputPath.' --operator-approved --approved-by=operator --json');

        $this->assertFileExists($outputPath);
        $pack = json_decode((string) file_get_contents($outputPath), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($pack['hyperflow_external_rivals_certification_eligible']);
        $this->assertArrayHasKey('claude_code', $pack['provider_results']);
        $this->assertArrayHasKey('codex_cli', $pack['provider_results']);
        $this->assertSame([], $pack['blockers']);
    }

    public function test_external_evidence_preflight_reports_export_readiness_without_writes(): void
    {
        $runsRoot = storage_path('framework/testing/hyperflow-forge-rivals-preflight/runs');
        config()->set('atlas_rivals.runs_root', $runsRoot);
        $this->writeForgeRivalsRunFixture('hf-preflight-claude', 'claude_sonnet');
        $this->writeForgeRivalsRunFixture('hf-preflight-codex', 'codex');

        $this->postJson('/ai/hyperflow/rivals-battery/external-evidence/preflight', [
            'forge_run_ids' => ['hf-preflight-claude', 'hf-preflight-codex'],
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.ai.hyperflow_external_evidence_preflight.v1')
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('certification_ready', false)
            ->assertJsonPath('candidate_export_ready', true)
            ->assertJsonPath('candidate_provider_process_count', 2)
            ->assertJsonPath('candidate_provider_results.claude_code.status', 'passed')
            ->assertJsonPath('candidate_provider_results.codex_cli.status', 'passed')
            ->assertJsonPath('external_evidence_blockers', [])
            ->assertJsonPath('safety.writes', false)
            ->assertJsonPath('writes', false);
    }

    public function test_external_evidence_candidates_lists_ready_runs_and_missing_pair(): void
    {
        $runsRoot = storage_path('framework/testing/hyperflow-forge-rivals-candidates/runs');
        config()->set('atlas_rivals.runs_root', $runsRoot);
        $this->writeForgeRivalsRunFixture('hf-candidates-claude', 'claude_sonnet');

        $response = $this->getJson('/ai/hyperflow/rivals-battery/external-evidence/candidates?limit=10', $this->headers)
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.ai.hyperflow_external_evidence_candidates.v1')
            ->assertJsonPath('status', 'blocked')
            ->assertJsonPath('candidate_count', 1)
            ->assertJsonPath('provider_counts.claude_code', 1)
            ->assertJsonPath('ready_provider_counts.claude_code', 1)
            ->assertJsonPath('suggested_pair', [])
            ->assertJsonPath('writes', false);

        $this->assertContains('codex_cli', $response->json('missing_pair_providers'));
        $this->assertSame('hf-candidates-claude', $response->json('candidates.0.run_id'));
        $this->assertSame(true, $response->json('candidates.0.export_ready'));
    }

    public function test_external_evidence_runbook_returns_missing_provider_commands_without_writes(): void
    {
        $runsRoot = storage_path('framework/testing/hyperflow-forge-rivals-runbook/runs');
        config()->set('atlas_rivals.runs_root', $runsRoot);
        $this->writeForgeRivalsRunFixture('hf-runbook-claude', 'claude_sonnet');

        $response = $this->getJson('/ai/hyperflow/rivals-battery/external-evidence/runbook?limit=10&approved_by=operator', $this->headers)
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.ai.hyperflow_external_evidence_runbook.v1')
            ->assertJsonPath('status', 'blocked')
            ->assertJsonPath('candidate_summary.candidate_count', 1)
            ->assertJsonPath('commands.run_missing_providers.codex_cli.provider', 'codex_cli')
            ->assertJsonPath('commands.run_missing_providers.codex_cli.rival_model', 'codex')
            ->assertJsonPath('commands.run_missing_providers.codex_cli.mode', 'full_power')
            ->assertJsonPath('commands.preflight_codex_cli', 'php artisan atlas:forge:rivals preflight --run-id=<codex_cli_setup_run_id> --mode=full_power --atlas-model=claude_sonnet --rival=codex --prompt-mode=messy-real --case-set=quick --json')
            ->assertJsonPath('operator_action_packet.schema_version', 'atlas.ai.hyperflow_external_provider_operator_action_packet.v1')
            ->assertJsonPath('operator_action_packet.status', 'awaiting_real_provider_runs')
            ->assertJsonPath('operator_action_packet.current_blocker', 'rivals_battery.external_provider_execution')
            ->assertJsonPath('operator_action_packet.requires_operator_cost_approval', true)
            ->assertJsonPath('operator_action_packet.safety.does_not_execute_provider', true)
            ->assertJsonPath('safety.writes', false)
            ->assertJsonPath('writes', false);

        $this->assertStringContainsString('atlas:forge:rivals run-real', $response->json('commands.run_missing_providers.codex_cli.command'));
        $this->assertStringContainsString('atlas:forge:rivals run-real', $response->json('operator_action_packet.missing_provider_run_commands.codex_cli.command'));
        $this->assertStringContainsString('--run-id=<codex_cli_setup_run_id>', $response->json('commands.run_missing_providers.codex_cli.command'));
        $this->assertStringContainsString('--mode=full_power', $response->json('commands.run_missing_providers.codex_cli.command'));
        $this->assertStringContainsString('--confirm-real-provider-call', $response->json('commands.run_missing_providers.codex_cli.command'));
    }

    public function test_external_evidence_preflight_blocks_missing_provider_score(): void
    {
        $runsRoot = storage_path('framework/testing/hyperflow-forge-rivals-preflight-score/runs');
        config()->set('atlas_rivals.runs_root', $runsRoot);
        $this->writeForgeRivalsRunFixture('hf-preflight-score-claude', 'claude_sonnet', rivalScore: 0);
        $this->writeForgeRivalsRunFixture('hf-preflight-score-codex', 'codex');

        $response = $this->postJson('/ai/hyperflow/rivals-battery/external-evidence/preflight', [
            'forge_run_ids' => ['hf-preflight-score-claude', 'hf-preflight-score-codex'],
        ], $this->headers)
            ->assertStatus(503)
            ->assertJsonPath('schema_version', 'atlas.ai.hyperflow_external_evidence_preflight.v1')
            ->assertJsonPath('status', 'blocked')
            ->assertJsonPath('candidate_export_ready', false);

        $this->assertContains('claude_code_score_missing', $response->json('external_evidence_blockers'));
    }

    public function test_hyperflow_command_preflights_external_evidence_candidates(): void
    {
        $runsRoot = storage_path('framework/testing/hyperflow-forge-rivals-cli-preflight/runs');
        config()->set('atlas_rivals.runs_root', $runsRoot);
        $this->writeForgeRivalsRunFixture('hf-cli-preflight-claude', 'claude_sonnet');
        $this->writeForgeRivalsRunFixture('hf-cli-preflight-codex', 'codex');

        $exit = Artisan::call('atlas:ai:hyperflow', [
            'action' => 'evidence-preflight',
            '--forge-run-ids' => 'hf-cli-preflight-claude,hf-cli-preflight-codex',
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('atlas.ai.hyperflow_command.v1', $payload['schema_version']);
        $this->assertSame('evidence-preflight', $payload['action']);
        $this->assertSame('ready', data_get($payload, 'external_evidence_preflight.status'));
        $this->assertSame(false, data_get($payload, 'external_evidence_preflight.certification_ready'));
        $this->assertSame(true, data_get($payload, 'external_evidence_preflight.candidate_export_ready'));
        $this->assertSame(false, data_get($payload, 'external_evidence_preflight.writes'));
    }

    public function test_hyperflow_command_lists_external_evidence_candidates(): void
    {
        $runsRoot = storage_path('framework/testing/hyperflow-forge-rivals-cli-candidates/runs');
        config()->set('atlas_rivals.runs_root', $runsRoot);
        $this->writeForgeRivalsRunFixture('hf-cli-candidates-codex', 'codex');

        $exit = Artisan::call('atlas:ai:hyperflow', [
            'action' => 'evidence-candidates',
            '--provider' => 'codex_cli',
            '--limit' => 10,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('atlas.ai.hyperflow_command.v1', $payload['schema_version']);
        $this->assertSame('evidence-candidates', $payload['action']);
        $this->assertSame('atlas.ai.hyperflow_external_evidence_candidates.v1', data_get($payload, 'external_evidence_candidates.schema_version'));
        $this->assertSame(1, data_get($payload, 'external_evidence_candidates.candidate_count'));
        $this->assertSame('hf-cli-candidates-codex', data_get($payload, 'external_evidence_candidates.candidates.0.run_id'));
        $this->assertSame('codex_cli', data_get($payload, 'external_evidence_candidates.candidates.0.provider'));
        $this->assertSame(false, data_get($payload, 'external_evidence_candidates.writes'));
    }

    public function test_hyperflow_command_returns_external_evidence_runbook(): void
    {
        $runsRoot = storage_path('framework/testing/hyperflow-forge-rivals-cli-runbook/runs');
        config()->set('atlas_rivals.runs_root', $runsRoot);
        $this->writeForgeRivalsRunFixture('hf-cli-runbook-codex', 'codex');

        $exit = Artisan::call('atlas:ai:hyperflow', [
            'action' => 'evidence-runbook',
            '--limit' => 10,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('atlas.ai.hyperflow_command.v1', $payload['schema_version']);
        $this->assertSame('evidence-runbook', $payload['action']);
        $this->assertSame('atlas.ai.hyperflow_external_evidence_runbook.v1', data_get($payload, 'external_evidence_runbook.schema_version'));
        $this->assertSame('claude_code', data_get($payload, 'external_evidence_runbook.commands.run_missing_providers.claude_code.provider'));
        $this->assertSame('fair', data_get($payload, 'external_evidence_runbook.commands.run_missing_providers.claude_code.mode'));
        $this->assertSame(false, data_get($payload, 'external_evidence_runbook.writes'));
    }

    public function test_external_evidence_runbook_uses_latest_setup_run_when_available(): void
    {
        $runsRoot = storage_path('framework/testing/hyperflow-forge-rivals-runbook-setup/runs');
        config()->set('atlas_rivals.runs_root', $runsRoot);
        $this->writeSetupRunFixture($runsRoot, 'fr2-test-setup-run');

        $this->getJson('/ai/hyperflow/rivals-battery/external-evidence/runbook?limit=10', $this->headers)
            ->assertOk()
            ->assertJsonPath('candidate_summary.latest_setup_run.run_id', 'fr2-test-setup-run')
            ->assertJsonPath('candidate_summary.latest_setup_run.status', 'setup_ready')
            ->assertJsonPath('candidate_summary.latest_setup_runs.0.run_id', 'fr2-test-setup-run')
            ->assertJsonPath('candidate_summary.setup_run_allocation.claude_code.setup_run_id', 'fr2-test-setup-run')
            ->assertJsonPath('candidate_summary.setup_run_allocation.claude_code.requires_new_setup', false)
            ->assertJsonPath('candidate_summary.setup_run_allocation.codex_cli.setup_run_id', null)
            ->assertJsonPath('candidate_summary.setup_run_allocation.codex_cli.placeholder', '<codex_cli_setup_run_id>')
            ->assertJsonPath('candidate_summary.setup_run_allocation.codex_cli.requires_new_setup', true)
            ->assertJsonPath('safety.does_not_reuse_same_setup_run_for_multiple_missing_providers', true)
            ->assertJsonPath('commands.preflight_claude_code', 'php artisan atlas:forge:rivals preflight --run-id=fr2-test-setup-run --mode=fair --atlas-model=claude_sonnet --rival=claude_sonnet --prompt-mode=messy-real --case-set=quick --json')
            ->assertJsonPath('commands.preflight_codex_cli', 'php artisan atlas:forge:rivals preflight --run-id=<codex_cli_setup_run_id> --mode=full_power --atlas-model=claude_sonnet --rival=codex --prompt-mode=messy-real --case-set=quick --json')
            ->assertJsonPath('commands.run_missing_providers.claude_code.command', 'php artisan atlas:forge:rivals run-real --run-id=fr2-test-setup-run --mode=fair --atlas-model=claude_sonnet --rival=claude_sonnet --prompt-mode=messy-real --case-set=quick --confirm-runbook-reviewed --confirm-provider-cost --confirm-real-provider-call --json')
            ->assertJsonPath('commands.run_missing_providers.codex_cli.command', 'php artisan atlas:forge:rivals run-real --run-id=<codex_cli_setup_run_id> --mode=full_power --atlas-model=claude_sonnet --rival=codex --prompt-mode=messy-real --case-set=quick --confirm-runbook-reviewed --confirm-provider-cost --confirm-real-provider-call --json')
            ->assertJsonPath('operator_action_packet.after_real_provider_runs.certify', 'php artisan atlas:ai:hyperflow certify --json')
            ->assertJsonPath('operator_action_packet.completion_condition', 'certification.status=passed and remaining_blockers=[]');
    }

    public function test_api_export_rejects_local_fake_forge_run(): void
    {
        $runsRoot = storage_path('framework/testing/hyperflow-forge-rivals-api-fake/runs');
        config()->set('atlas_rivals.runs_root', $runsRoot);
        $this->writeForgeRivalsRunFixture('hf-api-local-fake', 'codex', localFake: true);

        $this->postJson('/ai/hyperflow/rivals-battery/external-evidence/export', [
            'confirm_external_evidence_export' => true,
            'operator_approved' => true,
            'approved_by' => 'operator',
            'forge_run_id' => 'hf-api-local-fake',
        ], $this->headers)
            ->assertStatus(422)
            ->assertJsonPath('status', 'external_evidence_export_blocked')
            ->assertJsonPath('ready', false)
            ->assertJsonPath('evidence_pack', null)
            ->assertJsonPath('writes', false);
    }

    public function test_hyperflow_command_fails_closed_until_external_evidence_is_recorded(): void
    {
        $exit = Artisan::call('atlas:ai:hyperflow', [
            'action' => 'certify',
            '--json' => true,
        ]);

        $this->assertSame(1, $exit);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('atlas.ai.hyperflow_command.v1', $payload['schema_version']);
        $this->assertSame('blocked', data_get($payload, 'certification.status'));
        $this->assertContains('rivals_battery.external_provider_execution', data_get($payload, 'certification.remaining_blockers'));
        $this->assertSame('atlas.ai.hyperflow_external_evidence_gate.v1', data_get($payload, 'certification.external_evidence_gate.schema_version'));
        $this->assertSame('blocked', data_get($payload, 'certification.external_evidence_gate.status'));
        $this->assertSame('/ai/hyperflow/rivals-battery/external-evidence/import', data_get($payload, 'certification.external_evidence_gate.accepted_surfaces.evidence_pack_import_api'));
        $this->assertSame('sha256:64_hex_chars', data_get($payload, 'certification.external_evidence_gate.required_manual_payload.evidence_receipt_hash'));
    }

    public function test_hyperflow_command_runs_and_persists_internal_rivals_battery(): void
    {
        $exit = Artisan::call('atlas:ai:hyperflow', [
            'action' => 'run',
            '--prepare' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('atlas.ai.hyperflow_command.v1', $payload['schema_version']);
        $this->assertSame('run', $payload['action']);
        $this->assertSame('passed', data_get($payload, 'rivals_battery_run.status'));
        $this->assertSame(true, data_get($payload, 'rivals_battery_run.ready'));
        $this->assertSame('record_external_rivals_evidence', data_get($payload, 'rivals_battery_run.next_action'));
        $this->assertSame('atlas.ai.hyperflow_rivals_battery_receipt.v1', data_get($payload, 'rivals_battery_run.receipt.schema_version'));
        $this->assertGreaterThanOrEqual(12, DB::table('atlas_engineering_benchmark_results')->count());
    }

    public function test_hyperflow_command_imports_operator_approved_external_evidence_pack(): void
    {
        $path = $this->writeEvidencePack($this->validExternalEvidencePack());
        $exit = Artisan::call('atlas:ai:hyperflow', [
            'action' => 'import-evidence',
            '--evidence-pack' => $path,
            '--operator-approved' => true,
            '--approved-by' => 'operator',
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('atlas.ai.hyperflow_command.v1', $payload['schema_version']);
        $this->assertSame('external_evidence_recorded', data_get($payload, 'external_evidence_import.status'));
        $this->assertSame('passed', data_get($payload, 'external_evidence_import.external_evidence.status'));
    }

    public function test_hyperflow_command_exports_real_forge_runs_into_importable_external_evidence_pack(): void
    {
        $runsRoot = storage_path('framework/testing/hyperflow-forge-rivals/runs');
        config()->set('atlas_rivals.runs_root', $runsRoot);
        $this->writeForgeRivalsRunFixture('hf-real-claude', 'claude_sonnet');
        $this->writeForgeRivalsRunFixture('hf-real-codex', 'codex');
        $outputPath = storage_path('framework/testing/hyperflow-forge-rivals/hyperflow-export.json');

        $exit = Artisan::call('atlas:ai:hyperflow', [
            'action' => 'export-evidence',
            '--forge-run-ids' => 'hf-real-claude,hf-real-codex',
            '--output-path' => $outputPath,
            '--operator-approved' => true,
            '--approved-by' => 'operator',
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('external_evidence_exported', data_get($payload, 'external_evidence_export.status'));
        $this->assertSame(true, data_get($payload, 'external_evidence_export.ready'));
        $this->assertSame($outputPath, data_get($payload, 'external_evidence_export.evidence_pack.path'));
        $this->assertFileExists($outputPath);

        $pack = json_decode((string) file_get_contents($outputPath), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($pack['hyperflow_external_rivals_certification_eligible']);
        $this->assertArrayHasKey('claude_code', $pack['provider_results']);
        $this->assertArrayHasKey('codex_cli', $pack['provider_results']);
        $this->assertSame([], $pack['blockers']);

        $importExit = Artisan::call('atlas:ai:hyperflow', [
            'action' => 'import-evidence',
            '--evidence-pack' => $outputPath,
            '--operator-approved' => true,
            '--approved-by' => 'operator',
            '--json' => true,
        ]);

        $this->assertSame(0, $importExit);
        $importPayload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('external_evidence_recorded', data_get($importPayload, 'external_evidence_import.status'));
        $this->assertSame('passed', data_get($importPayload, 'external_evidence_import.external_evidence.status'));
    }

    public function test_hyperflow_command_export_rejects_local_fake_forge_run(): void
    {
        $runsRoot = storage_path('framework/testing/hyperflow-forge-rivals-fake/runs');
        config()->set('atlas_rivals.runs_root', $runsRoot);
        $this->writeForgeRivalsRunFixture('hf-local-fake', 'codex', localFake: true);
        $outputPath = storage_path('framework/testing/hyperflow-forge-rivals-fake/hyperflow-export.json');
        if (file_exists($outputPath)) {
            unlink($outputPath);
        }

        $exit = Artisan::call('atlas:ai:hyperflow', [
            'action' => 'export-evidence',
            '--forge-run-id' => 'hf-local-fake',
            '--output-path' => $outputPath,
            '--operator-approved' => true,
            '--approved-by' => 'operator',
            '--json' => true,
        ]);

        $this->assertSame(1, $exit);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('external_evidence_export_blocked', data_get($payload, 'external_evidence_export.status'));
        $this->assertSame(false, data_get($payload, 'external_evidence_export.writes'));
        $this->assertNull(data_get($payload, 'external_evidence_export.evidence_pack'));
        $this->assertSame($outputPath, data_get($payload, 'external_evidence_export.rejected_evidence_pack.output_path_not_written'));
        $this->assertContains('forge_pack_not_real_run:hf-local-fake', data_get($payload, 'external_evidence_export.external_evidence_blockers'));
        $this->assertContains('missing_provider_baseline:claude_code', data_get($payload, 'external_evidence_export.external_evidence_blockers'));
        $this->assertFileDoesNotExist($outputPath);
    }

    public function test_hyperflow_command_exposes_external_evidence_pack_template(): void
    {
        $exit = Artisan::call('atlas:ai:hyperflow', [
            'action' => 'evidence-template',
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('atlas.ai.hyperflow_command.v1', $payload['schema_version']);
        $this->assertSame('evidence-template', $payload['action']);
        $this->assertSame('atlas.ai.hyperflow_external_evidence_pack_template.v1', data_get($payload, 'external_evidence_template.schema_version'));
        $this->assertSame(true, data_get($payload, 'external_evidence_template.template.hyperflow_external_rivals_certification_eligible'));
        $this->assertSame('/ai/hyperflow/rivals-battery/external-evidence/import', data_get($payload, 'external_evidence_template.import.api'));
    }

    public function test_hyperflow_command_writes_external_evidence_pack_template_to_path(): void
    {
        $outputPath = storage_path('framework/testing/hyperflow-template/external-evidence-template.json');
        @unlink($outputPath);

        $exit = Artisan::call('atlas:ai:hyperflow', [
            'action' => 'evidence-template',
            '--output-path' => $outputPath,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($outputPath, data_get($payload, 'template_written.path'));
        $this->assertSame(false, data_get($payload, 'template_written.certification_ready'));
        $this->assertSame(true, data_get($payload, 'writes'));
        $this->assertFileExists($outputPath);

        $content = (string) file_get_contents($outputPath);
        $pack = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(hash('sha256', $content), data_get($payload, 'template_written.sha256'));
        $this->assertSame('atlas.hyperflow.external_rivals_evidence_pack.v1', $pack['schema_version']);
        $this->assertTrue($pack['hyperflow_external_rivals_certification_eligible']);
        $this->assertArrayHasKey('claude_code', $pack['provider_results']);
        $this->assertArrayHasKey('codex_cli', $pack['provider_results']);
    }

    public function test_hyperflow_command_passes_after_operator_approved_external_evidence(): void
    {
        $path = $this->writeEvidencePack($this->validExternalEvidencePack());
        $this->postJson('/ai/hyperflow/rivals-battery/external-evidence/import', [
            'confirm_external_evidence_import' => true,
            'operator_approved' => true,
            'approved_by' => 'operator',
            'evidence_pack_path' => $path,
        ], $this->headers)->assertCreated();

        $exit = Artisan::call('atlas:ai:hyperflow', [
            'action' => 'certify',
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('atlas.ai.hyperflow_command.v1', $payload['schema_version']);
        $this->assertSame('passed', data_get($payload, 'certification.status'));
        $this->assertSame('passed', data_get($payload, 'certification.external_evidence_gate.status'));
        $this->assertSame(true, data_get($payload, 'certification.claim_policy.ready_to_replace_claude_code_codex'));
    }

    public function test_api_requires_atlas_token(): void
    {
        $this->getJson('/ai/hyperflow/certification')
            ->assertUnauthorized();
    }

    public function test_hyperflow_auxiliary_apis_require_atlas_token(): void
    {
        $this->getJson('/ai/hyperflow/rivals-battery')
            ->assertUnauthorized();
        $this->getJson('/ai/hyperflow/rivals-battery/external-evidence/template')
            ->assertUnauthorized();
        $this->postJson('/ai/hyperflow/rivals-battery/prepare', ['confirm_prepare' => true])
            ->assertUnauthorized();
        $this->postJson('/ai/hyperflow/rivals-battery/run', ['confirm_run' => true])
            ->assertUnauthorized();
        $this->postJson('/ai/hyperflow/rivals-battery/external-evidence', $this->validExternalEvidencePayload())
            ->assertUnauthorized();
        $this->getJson('/ai/hyperflow/rivals-battery/external-evidence/candidates')
            ->assertUnauthorized();
        $this->getJson('/ai/hyperflow/rivals-battery/external-evidence/runbook')
            ->assertUnauthorized();
        $this->postJson('/ai/hyperflow/rivals-battery/external-evidence/preflight', [
            'forge_run_ids' => ['run-a', 'run-b'],
        ])->assertUnauthorized();
        $this->postJson('/ai/hyperflow/rivals-battery/external-evidence/export', [
            'confirm_external_evidence_export' => true,
            'operator_approved' => true,
            'approved_by' => 'operator',
            'forge_run_ids' => ['run-a', 'run-b'],
        ])->assertUnauthorized();
        $this->postJson('/ai/hyperflow/rivals-battery/external-evidence/import', [
            'confirm_external_evidence_import' => true,
            'operator_approved' => true,
            'approved_by' => 'operator',
            'evidence_pack_path' => '/tmp/missing.json',
        ])->assertUnauthorized();
    }

    /**
     * @return array<string,mixed>
     */
    private function validExternalEvidencePayload(): array
    {
        return [
            'confirm_external_evidence' => true,
            'external_provider_call' => true,
            'approved_by' => 'operator',
            'provider_tokens_spent' => 12000,
            'protocol_valid' => true,
            'comparable' => true,
            'operator_approved' => true,
            'evidence_receipt_hash' => str_repeat('e', 64),
            'provider_results' => [
                'claude_code' => [
                    'status' => 'passed',
                    'score' => 94,
                    'duration_ms' => 180000,
                    'evidence_hash' => str_repeat('c', 64),
                    'receipt_ref' => 'claude-code-run-1',
                ],
                'codex' => [
                    'status' => 'passed',
                    'score' => 96,
                    'duration_ms' => 170000,
                    'evidence_hash' => str_repeat('d', 64),
                    'receipt_ref' => 'codex-run-1',
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function validExternalEvidencePack(): array
    {
        return [
            'schema_version' => 'atlas.hyperflow.external_rivals_evidence_pack.v1',
            'run_id' => 'approved-real-pack',
            'hyperflow_external_rivals_certification_eligible' => true,
            'external_provider_call' => true,
            'provider_tokens_spent' => true,
            'provider_tokens_spent_count' => 12000,
            'is_comparable_real_run' => true,
            'protocol_valid' => true,
            'claim_ready' => true,
            'provider_results' => [
                'claude_code' => [
                    'status' => 'passed',
                    'score' => 94,
                    'duration_ms' => 180000,
                    'evidence_hash' => str_repeat('c', 64),
                    'receipt_ref' => 'claude-code-run-1',
                ],
                'codex_cli' => [
                    'status' => 'passed',
                    'score' => 96,
                    'duration_ms' => 170000,
                    'evidence_hash' => str_repeat('d', 64),
                    'receipt_ref' => 'codex-run-1',
                ],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $pack
     */
    private function writeEvidencePack(array $pack): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hyperflow-pack-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($path, json_encode($pack, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return $path;
    }

    private function writeForgeRivalsRunFixture(string $runId, string $rivalModel, bool $localFake = false, int $rivalScore = 92): void
    {
        $runsRoot = (string) config('atlas_rivals.runs_root');
        $base = $runsRoot.DIRECTORY_SEPARATOR.$runId;
        $evidence = $base.DIRECTORY_SEPARATOR.'evidence';
        if (is_dir($base)) {
            $this->removeDirectory($base);
        }
        mkdir($evidence, 0777, true);

        $mode = $localFake ? 'local_fake' : 'fair';
        $realRun = ! $localFake;
        $started = now()->subSeconds(5)->toIso8601String();
        $finished = now()->toIso8601String();
        $atlasReceipt = $this->forgeReceipt('atlas', 'claude_sonnet', $mode, $started, $finished, $localFake);
        $rivalReceipt = $this->forgeReceipt('rival', $rivalModel, $mode, $started, $finished, $localFake);
        file_put_contents($evidence.'/atlas_receipt.json', json_encode($atlasReceipt, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        file_put_contents($evidence.'/rival_receipt.json', json_encode($rivalReceipt, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        file_put_contents($evidence.'/workspace_hashes.json', json_encode([
            'before' => ['atlas' => str_repeat('1', 64), 'rival' => str_repeat('2', 64)],
            'after' => ['atlas' => str_repeat('3', 64), 'rival' => str_repeat('4', 64)],
            'dirty_after_run' => false,
            'workspace_blockers' => [],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        file_put_contents($base.'/events.jsonl', json_encode(['event' => 'provider_finished'], JSON_THROW_ON_ERROR).PHP_EOL);

        $manifest = [
            'schema_version' => 'atlas.forge.rivals.run_real.v1',
            'run_id' => $runId,
            'mode' => $mode,
            'atlas_model' => 'claude_sonnet',
            'rival_model' => $rivalModel,
            'verdict' => $realRun ? 'comparable' : 'local_fake',
            'claim_ready' => $realRun,
            'run_duration_ms' => 5000,
            'external_provider_call' => $realRun,
            'provider_tokens_spent' => $realRun,
            'atlas_receipt_hash' => hash_file('sha256', $evidence.'/atlas_receipt.json'),
            'rival_receipt_hash' => hash_file('sha256', $evidence.'/rival_receipt.json'),
        ];
        file_put_contents($evidence.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        file_put_contents($evidence.'/scorecard.json', json_encode([
            'schema_version' => 'atlas.forge.rivals.scorecard.v1',
            'claim_ready' => $realRun,
            'arms' => ['rival' => ['score' => $rivalScore]],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        $pack = [
            'schema_version' => 'atlas.forge.rivals.evidence_pack.v2',
            'evidence_stage' => 'final',
            'mode_for_evidence' => $realRun ? 'real_run' : 'fake_run',
            'run_id' => $runId,
            'missing_required' => [],
            'claim_ready' => $realRun,
            'is_comparable_real_run' => $realRun,
            'external_provider_call' => $realRun,
            'provider_tokens_spent' => $realRun,
            'after_clean_check' => ['ran' => true, 'clean' => true],
            'artifacts' => [
                'rival_receipt' => [
                    'present' => true,
                    'sha256' => hash_file('sha256', $evidence.'/rival_receipt.json'),
                    'path' => $evidence.'/rival_receipt.json',
                ],
            ],
            'provider_receipts' => [
                'atlas' => $this->summarizeForgeFixtureReceipt($atlasReceipt, $localFake),
                'rival' => $this->summarizeForgeFixtureReceipt($rivalReceipt, $localFake),
                'count_present' => 2,
            ],
        ];
        file_put_contents($evidence.'/evidence_pack.json', json_encode($pack, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    private function writeSetupRunFixture(string $runsRoot, string $runId): void
    {
        $base = $runsRoot.DIRECTORY_SEPARATOR.$runId;
        $rivalsRoot = dirname($runsRoot);
        $atlasWorkspace = $rivalsRoot.DIRECTORY_SEPARATOR.'arms'.DIRECTORY_SEPARATOR.$runId.'-atlas'.DIRECTORY_SEPARATOR.'workspace';
        $rivalWorkspace = $rivalsRoot.DIRECTORY_SEPARATOR.'arms'.DIRECTORY_SEPARATOR.$runId.'-rival'.DIRECTORY_SEPARATOR.'workspace';
        foreach ([$base.DIRECTORY_SEPARATOR.'evidence', $atlasWorkspace, $rivalWorkspace] as $path) {
            if (! is_dir($path)) {
                mkdir($path, 0777, true);
            }
        }
        touch($base, time() + 5);
    }

    /**
     * @return array<string,mixed>
     */
    private function forgeReceipt(string $arm, string $model, string $mode, string $started, string $finished, bool $fake): array
    {
        return [
            'arm' => $arm,
            'mode' => $mode,
            'model' => $model,
            'exit_code' => 0,
            'test_exit_code' => 0,
            'killed' => false,
            'fake' => $fake,
            'command_hash' => hash('sha256', $arm.'|command'),
            'prompt_hash' => hash('sha256', $arm.'|prompt'),
            'stdout_hash' => hash('sha256', $arm.'|stdout'),
            'stderr_hash' => hash('sha256', $arm.'|stderr'),
            'test_log_hash' => hash('sha256', $arm.'|test'),
            'patch_diff_hash' => hash('sha256', $arm.'|patch'),
            'patch_diff_bytes' => 120,
            'started_at' => $started,
            'finished_at' => $finished,
            'workspace_blockers' => [],
            'workspace_has_blocking_changes' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $receipt
     * @return array<string,mixed>
     */
    private function summarizeForgeFixtureReceipt(array $receipt, bool $fake): array
    {
        return [
            'present' => true,
            'test_mode' => $fake,
            'fake' => $fake,
            'exit_code' => 0,
            'test_exit_code' => 0,
            'killed' => false,
            'stdout_hash' => $receipt['stdout_hash'],
            'stderr_hash' => $receipt['stderr_hash'],
            'command_hash' => $receipt['command_hash'],
            'prompt_hash' => $receipt['prompt_hash'],
            'test_log_hash' => $receipt['test_log_hash'],
            'patch_diff_hash' => $receipt['patch_diff_hash'],
        ];
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        $items = scandir($path);
        foreach ($items === false ? [] : $items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $child = $path.DIRECTORY_SEPARATOR.$item;
            is_dir($child) ? $this->removeDirectory($child) : unlink($child);
        }
        rmdir($path);
    }

    private function seedPassingHyperflowBattery(): void
    {
        app(AtlasAiHyperflowRivalsBatteryService::class)->prepare();
        app(AtlasAiHyperflowRivalsBatteryService::class)->runAndPersist(['triggered_by' => 'test_seed']);
    }

    private function bootBenchmarkSchema(): void
    {
        Schema::dropIfExists('atlas_engineering_benchmark_results');
        Schema::dropIfExists('atlas_engineering_benchmark_runs');
        Schema::dropIfExists('atlas_engineering_benchmark_cases');
        Schema::dropIfExists('atlas_engineering_benchmark_suites');

        Schema::create('atlas_engineering_benchmark_suites', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('slug', 120)->unique();
            $table->string('name', 180);
            $table->text('description')->nullable();
            $table->string('status', 32)->default('active')->index();
            $table->json('default_runner_options_json')->default('{}');
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });

        Schema::create('atlas_engineering_benchmark_cases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('suite_id')->index();
            $table->uuid('task_id')->nullable()->index();
            $table->string('case_code', 120);
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->string('workspace_path_hash', 64)->nullable()->index();
            $table->json('task_contract_json')->default('{}');
            $table->json('runner_options_json')->default('{}');
            $table->string('expected_decision', 32)->nullable();
            $table->unsignedSmallInteger('min_score')->default(85);
            $table->string('corpus_tier', 40)->nullable();
            $table->string('domain_slug', 80)->nullable();
            $table->string('risk_profile', 40)->nullable();
            $table->string('curation_status', 40)->default('candidate');
            $table->unsignedSmallInteger('curation_score')->nullable();
            $table->string('corpus_fingerprint', 64)->nullable();
            $table->timestamp('curated_at')->nullable();
            $table->json('tags_json')->default('[]');
            $table->string('status', 32)->default('active')->index();
            $table->json('metadata')->default('{}');
            $table->timestamps();
            $table->unique(['suite_id', 'case_code'], 'idx_hyperflow_bench_cases_suite_code');
        });

        Schema::create('atlas_engineering_benchmark_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('suite_id')->index();
            $table->string('benchmark_key', 180)->nullable()->index();
            $table->string('provider', 80)->nullable()->index();
            $table->string('model', 120)->nullable()->index();
            $table->string('mode', 40)->nullable()->index();
            $table->string('case_set_hash', 64)->nullable()->index();
            $table->string('status', 32)->default('running')->index();
            $table->unsignedInteger('total_cases')->default(0);
            $table->unsignedInteger('passed_cases')->default(0);
            $table->unsignedInteger('failed_cases')->default(0);
            $table->uuid('baseline_run_id')->nullable()->index();
            $table->decimal('pass_rate', 5, 2)->nullable();
            $table->decimal('pass_rate_delta', 6, 2)->nullable();
            $table->decimal('average_score', 6, 2)->nullable();
            $table->decimal('average_score_delta', 6, 2)->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->string('trend_status', 32)->nullable()->index();
            $table->json('runner_options_json')->default('{}');
            $table->json('summary_json')->default('{}');
            $table->string('release_gate_status', 32)->nullable();
            $table->string('release_gate_profile', 64)->nullable();
            $table->json('release_gate_policy_json')->default('{}');
            $table->json('release_gate_failures_json')->default('[]');
            $table->json('release_gate_warnings_json')->default('[]');
            $table->string('outcome_status', 40)->nullable();
            $table->unsignedSmallInteger('outcome_score')->nullable();
            $table->json('outcome_json')->default('{}');
            $table->timestamp('outcome_recorded_at')->nullable();
            $table->string('outcome_recorded_by', 120)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });

        Schema::create('atlas_engineering_benchmark_results', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('benchmark_run_id')->index();
            $table->uuid('suite_id')->index();
            $table->uuid('case_id')->index();
            $table->uuid('engineering_run_id')->nullable()->index();
            $table->uuid('task_id')->nullable()->index();
            $table->string('status', 32)->default('failed')->index();
            $table->string('decision', 32)->nullable()->index();
            $table->unsignedSmallInteger('score')->nullable();
            $table->boolean('passed')->default(false)->index();
            $table->integer('duration_ms')->default(0);
            $table->json('expectation_json')->default('{}');
            $table->json('observed_json')->default('{}');
            $table->text('failure_summary')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
            $table->unique(['benchmark_run_id', 'case_id'], 'idx_hyperflow_bench_results_run_case');
        });
    }
}
