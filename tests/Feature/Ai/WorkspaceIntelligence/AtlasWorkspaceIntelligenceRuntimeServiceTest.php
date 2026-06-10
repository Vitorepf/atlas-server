<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\WorkspaceIntelligence;

use App\Models\AtlasAemorExecutionEpisode;
use App\Models\AtlasAemorMemoryCandidate;
use App\Models\AtlasAemorOutcome;
use App\Models\AtlasDevOutcomeMemory;
use App\Models\AtlasDevTaskPacket;
use App\Models\AtlasEngineeringRun;
use App\Models\AtlasEngineeringTestRun;
use App\Models\AtlasWorkspaceArtifactGraphSnapshot;
use App\Models\AtlasWorkspaceArtifactLakeEntry;
use App\Models\AtlasWorkspaceArtifactRetirementProposal;
use App\Models\AtlasWorkspaceArtifactTimelineEvent;
use App\Models\AtlasWorkspaceIntelligenceSnapshot;
use App\Models\AtlasWorkspaceRuntimeProjectionSnapshot;
use App\Services\Ai\Programming\AtlasDevRuntimeService;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceExecutionBoundaryAuditService;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceHandoffPackService;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceExecutionGateService;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceRuntimeService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesAemorTables;
use Tests\TestCase;

final class AtlasWorkspaceIntelligenceRuntimeServiceTest extends TestCase
{
    use CreatesAemorTables;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAemorTables();
        $this->createSnapshotTable();
        $this->createArtifactIntelligenceTables();
        $this->createArtifactOperatingTables();
        $this->createRuntimeProjectionTable();
        $this->createDevRuntimeOutcomeTables();
        $this->createEngineeringRuntimeTables();
    }

    public function test_default_atlas_workspace_certifies_full_awis_family(): void
    {
        $report = app(AtlasWorkspaceIntelligenceRuntimeService::class)->certify(
            workspace: 'atlas',
            task: 'corrigir bug na tela de login',
            conversationTexts: ['decisao: AWIS usa task context pack; blocker antigo foi resolvido'],
        );

        $this->assertSame(AtlasWorkspaceIntelligenceRuntimeService::SCHEMA_VERSION, $report['schema_version']);
        $this->assertSame('ready', $report['status']);
        $this->assertSame('atlas', $report['workspace']['workspace_id']);
        $this->assertSame('ready', $report['workspace']['readiness_status']);
        $this->assertSame('atlas.workspace_repository_inventory.v1', $report['repository_inventory']['schema_version']);
        $this->assertContains($report['repository_inventory']['status'], ['ready', 'limited']);
        $this->assertFalse($report['repository_inventory']['source_policy']['raw_manifest_returned']);
        $this->assertFalse($report['repository_inventory']['source_policy']['script_bodies_returned']);
        $this->assertFalse($report['repository_inventory']['source_policy']['absolute_workspace_path_returned']);
        $this->assertSame(64, strlen((string) $report['repository_inventory']['inventory_hash']));
        $this->assertSame('atlas.awis.workspace_change_memory.v1', $report['workspace_change_memory']['schema_version']);
        $this->assertContains($report['workspace_change_memory']['status'], ['ready', 'limited']);
        $this->assertFalse($report['workspace_change_memory']['source_policy']['raw_diff_returned']);
        $this->assertFalse($report['workspace_change_memory']['source_policy']['absolute_workspace_path_returned']);
        $this->assertSame(64, strlen((string) $report['workspace_change_memory']['change_hash']));
        $this->assertSame('atlas.awis.workspace_focus_map.v1', $report['workspace_focus_map']['schema_version']);
        $this->assertSame('ready', $report['workspace_focus_map']['status']);
        $this->assertFalse($report['workspace_focus_map']['source_policy']['raw_file_content_returned']);
        $this->assertFalse($report['workspace_focus_map']['source_policy']['absolute_workspace_path_returned']);
        $this->assertSame(64, strlen((string) $report['workspace_focus_map']['focus_hash']));
        $this->assertSame('atlas.awis.workspace_live_execution_memory.v1', $report['workspace_live_execution_memory']['schema_version']);
        $this->assertSame('ready', $report['workspace_live_execution_memory']['status']);
        $this->assertContains('workspace_live_execution_memory', $report['workspace_live_execution_memory']['startup_packet']['load_first']);
        $this->assertContains('raw_conversation_replay', $report['workspace_live_execution_memory']['startup_packet']['avoid']);
        $this->assertSame('workspace_runbook.body.live_execution_memory', $report['workspace_live_execution_memory']['persistence_contract']['embedded_in_artifact_lake']);
        $this->assertFalse($report['workspace_live_execution_memory']['source_policy']['raw_file_content_returned']);
        $this->assertFalse($report['workspace_live_execution_memory']['source_policy']['raw_diff_returned']);
        $this->assertFalse($report['workspace_live_execution_memory']['source_policy']['raw_conversation_returned']);
        $this->assertFalse($report['workspace_live_execution_memory']['source_policy']['absolute_workspace_path_returned']);
        $this->assertSame(64, strlen((string) $report['workspace_live_execution_memory']['live_memory_hash']));
        $this->assertSame('atlas.awis.workspace_next_session_brain.v1', $report['workspace_next_session_brain']['schema_version']);
        $this->assertSame('ready', $report['workspace_next_session_brain']['status']);
        $this->assertGreaterThanOrEqual(0.8, $report['workspace_next_session_brain']['readiness_score']);
        $this->assertContains('workspace_live_execution_memory', $report['workspace_next_session_brain']['resume_packet']['load_order']);
        $this->assertContains('workspace_change_memory', $report['workspace_next_session_brain']['resume_packet']['load_order']);
        $this->assertContains('workspace_focus_map', $report['workspace_next_session_brain']['resume_packet']['load_order']);
        $this->assertSame(
            'awis_live_memory:'.$report['workspace_live_execution_memory']['live_memory_hash'],
            $report['workspace_next_session_brain']['resume_packet']['live_execution_memory_ref'],
        );
        $this->assertSame('atlas.awis.context_loading_plan.v1', $report['workspace_next_session_brain']['context_loading_plan']['schema_version']);
        $this->assertSame($report['repository_inventory']['inventory_hash'], $report['workspace_next_session_brain']['context_loading_plan']['repository_inventory_hash']);
        $this->assertSame($report['workspace_live_execution_memory']['live_memory_hash'], $report['workspace_next_session_brain']['context_loading_plan']['live_execution_memory_hash']);
        $this->assertSame($report['workspace_live_execution_memory']['live_memory_hash'], $report['workspace_next_session_brain']['context_loading_plan']['cache_keys']['workspace_live_execution_memory_hash']);
        $this->assertSame('atlas.awis.workspace_working_set.v1', $report['workspace_next_session_brain']['context_loading_plan']['workspace_working_set']['schema_version']);
        $this->assertSame('provider_safe_hot_context_set', $report['workspace_next_session_brain']['context_loading_plan']['workspace_working_set']['mode']);
        $this->assertSame(64, strlen((string) $report['workspace_next_session_brain']['context_loading_plan']['working_set_hash']));
        $this->assertSame(
            $report['workspace_next_session_brain']['context_loading_plan']['working_set_hash'],
            $report['workspace_next_session_brain']['context_loading_plan']['cache_keys']['workspace_working_set_hash'],
        );
        $this->assertSame('atlas.awis.context_delta_plan.v1', $report['workspace_next_session_brain']['context_loading_plan']['context_delta_plan']['schema_version']);
        $this->assertSame('hash_based_incremental_context_resume', $report['workspace_next_session_brain']['context_loading_plan']['context_delta_plan']['mode']);
        $this->assertSame(64, strlen((string) $report['workspace_next_session_brain']['context_loading_plan']['context_delta_plan_hash']));
        $this->assertSame(
            $report['workspace_next_session_brain']['context_loading_plan']['context_delta_plan_hash'],
            $report['workspace_next_session_brain']['context_loading_plan']['cache_keys']['context_delta_plan_hash'],
        );
        $this->assertFalse($report['workspace_next_session_brain']['context_loading_plan']['context_delta_plan']['source_policy']['raw_file_content_returned']);
        $this->assertFalse($report['workspace_next_session_brain']['context_loading_plan']['context_delta_plan']['source_policy']['raw_diff_returned']);
        $this->assertFalse($report['workspace_next_session_brain']['context_loading_plan']['workspace_working_set']['source_policy']['raw_file_content_returned']);
        $this->assertFalse($report['workspace_next_session_brain']['context_loading_plan']['workspace_working_set']['prewarm_plan']['load_raw_file_content']);
        $this->assertTrue($report['workspace_next_session_brain']['performance_budget']['uses_workspace_working_set']);
        $this->assertTrue($report['workspace_next_session_brain']['performance_budget']['uses_context_delta_plan']);
        $this->assertTrue($report['workspace_next_session_brain']['performance_budget']['uses_live_execution_memory']);
        $this->assertFalse($report['workspace_next_session_brain']['context_loading_plan']['provider_policy']['raw_manifest_returned']);
        $this->assertFalse($report['workspace_next_session_brain']['context_loading_plan']['provider_policy']['script_bodies_returned']);
        $this->assertFalse($report['workspace_next_session_brain']['source_policy']['raw_file_content_returned']);
        $this->assertFalse($report['workspace_next_session_brain']['source_policy']['raw_conversation_returned']);
        $this->assertFalse($report['workspace_next_session_brain']['source_policy']['absolute_workspace_path_returned']);
        $this->assertSame(64, strlen((string) $report['workspace_next_session_brain']['brain_hash']));

        foreach (['AWIS', 'AWTR', 'ACIOS', 'AWAF', 'AWAIR', 'AWCO', 'AWEF'] as $acronym) {
            $this->assertArrayHasKey($acronym, $report['family']);
        }

        $this->assertSame('ready', $report['awtr']['status']);
        $this->assertNotEmpty($report['awtr']['twin_hash']);
        $this->assertSame('atlas.workspace_genome.v1', $report['awtr']['genome']['schema_version']);
        $this->assertSame(64, strlen((string) $report['awtr']['genome']['genome_hash']));
        $this->assertSame('atlas.workspace_living_code_map.v1', $report['awtr']['living_code_map']['schema_version']);
        $this->assertSame(64, strlen((string) $report['awtr']['living_code_map']['code_map_hash']));
        $this->assertSame('owner_docs_plus_task_artifacts_plus_focused_tests', $report['awtr']['context_autopilot']['strategy']);
        $this->assertSame('atlas.workspace_command_registry.v1', $report['awtr']['command_registry']['schema_version']);
        $this->assertSame(64, strlen((string) $report['awtr']['command_registry']['command_registry_hash']));
        $this->assertSame(64, strlen((string) $report['awtr']['risk_fragility_map']['risk_map_hash']));
        $this->assertSame(false, $report['acios']['task_context_pack_policy']['uses_raw_conversation']);
        $this->assertSame(10, $report['awaf']['artifact_count']);
        $this->assertSame($report['workspace']['workspace_hash'], $report['awair']['workspace_hash']);
        $artifactTypes = collect($report['awaf']['artifacts'])->pluck('artifact_type')->all();
        $this->assertSame([
            'workspace_brief',
            'task_packet',
            'context_pack',
            'execution_plan',
            'test_plan',
            'risk_sheet',
            'handoff_packet',
            'failure_capsule',
            'outcome_record',
            'workspace_runbook',
        ], $artifactTypes);
        $contextPack = collect($report['awaf']['artifacts'])->firstWhere('artifact_type', 'context_pack');
        $runbook = collect($report['awaf']['artifacts'])->firstWhere('artifact_type', 'workspace_runbook');
        $this->assertSame($report['workspace_live_execution_memory']['live_memory_hash'], data_get($contextPack, 'body.live_execution_memory_hash'));
        $this->assertSame($report['workspace_live_execution_memory']['live_memory_hash'], data_get($runbook, 'body.live_execution_memory.live_memory_hash'));
        $this->assertSame('ready', $report['awair']['status']);
        $this->assertSame('atlas.workspace_artifact_intelligence.v1', $report['awair']['schema_version']);
        $this->assertSame(10, $report['awair']['artifact_lake']['artifact_count']);
        $this->assertNotEmpty($report['awair']['artifact_graph']['nodes']);
        $this->assertNotEmpty($report['awair']['artifact_graph']['edges']);
        $this->assertTrue($report['awair']['artifact_replay']['replay_ready']);
        $this->assertContains('workspace_live_execution_memory', $report['awair']['artifact_replay']['required_inputs']);
        $this->assertContains('workspace_live_execution_memory', $report['awair']['artifact_context_compiler']['context_units']);
        $this->assertSame('ready', $report['awair']['artifact_simulation']['decision']);
        $this->assertFalse($report['awair']['artifact_context_compiler']['raw_conversation_included']);
        $this->assertTrue($report['awair']['artifact_quality_governor']['all_executable_artifacts_ready']);
        $this->assertSame('graph_first', $report['awair']['artifact_cartography_projection']['human_scan_mode']);
        $this->assertSame('patterns_only_no_raw_cross_workspace', $report['awair']['artifact_marketplace']['privacy_policy']);
        $this->assertContains('AEMOR', $report['awair']['artifact_outcome_learning']['feeds']);
        $this->assertSame('ready', $report['awco']['execution_readiness_status']);
        $this->assertSame('atlas.workspace_contract_certification_envelope.v1', $report['awco']['certification_envelope']['schema_version']);
        $this->assertSame(10, $report['awco']['certification_envelope']['certified_count']);
        $this->assertSame('atlas.workspace_contract_invalidation_rules.v1', $report['awco']['invalidation_rules']['schema_version']);
        $this->assertContains('shadow_execution', $report['awco']['orchestration_plan']['required_before_provider']);
        $this->assertSame(64, strlen((string) $report['awco']['contract_hash']));
        $this->assertTrue($report['awef']['privacy_preserving_transfer']);
        $this->assertSame('atlas.workspace_pattern_library.v1', $report['awef']['pattern_library']['schema_version']);
        $this->assertFalse($report['awef']['privacy_transfer_gate']['allows_raw_cross_workspace']);
        $this->assertSame('read_only_shadow', $report['awef']['workspace_benchmark']['mode']);
        $this->assertSame(64, strlen((string) $report['awef']['evolution_hash']));
        $this->assertSame('atlas.awis.workspace_intelligence_loop.v1', $report['awis_learning_loop']['schema_version']);
        $this->assertSame('ready', $report['awis_learning_loop']['status']);
        $this->assertSame('atlas', $report['awis_learning_loop']['workspace_id']);
        $this->assertSame('workspace', $report['awis_learning_loop']['operational_memory']['memory_scope']);
        $this->assertSame('candidate_only_until_evidence_review', $report['awis_learning_loop']['operational_memory']['promotion_policy']);
        $this->assertFalse($report['awis_learning_loop']['operational_memory']['canonical_doc_rewrite_allowed']);
        $this->assertFalse($report['awis_learning_loop']['context_application']['raw_conversation_included']);
        $this->assertTrue($report['awis_learning_loop']['context_application']['provider_prompt_allowed']);
        $this->assertSame('prepare_provider_safe_handoff', $report['awis_learning_loop']['next_action']['action']);
        $this->assertSame(['AEMOR', 'AWEF', 'workspace_runbook', 'context_autopilot'], $report['awis_learning_loop']['evidence_learning']['feedback_targets']);
        $this->assertContains('workspace_change_memory', $report['awis_learning_loop']['operational_memory']['candidate_units']);
        $this->assertContains('workspace_focus_map', $report['awis_learning_loop']['operational_memory']['candidate_units']);
        $this->assertContains('workspace_live_execution_memory', $report['awis_learning_loop']['operational_memory']['candidate_units']);
        $this->assertContains('workspace_next_session_brain', $report['awis_learning_loop']['operational_memory']['candidate_units']);
        $this->assertSame($report['workspace_change_memory']['change_hash'], $report['awis_learning_loop']['context_application']['workspace_change_memory_hash']);
        $this->assertSame($report['workspace_focus_map']['focus_hash'], $report['awis_learning_loop']['context_application']['workspace_focus_hash']);
        $this->assertSame($report['workspace_live_execution_memory']['live_memory_hash'], $report['awis_learning_loop']['context_application']['workspace_live_execution_memory_hash']);
        $this->assertSame($report['workspace_next_session_brain']['brain_hash'], $report['awis_learning_loop']['context_application']['workspace_next_session_brain_hash']);
        $this->assertFalse($report['awis_learning_loop']['context_application']['raw_diff_included']);
        $this->assertTrue($report['awis_learning_loop']['closed_loop']['loop_closed']);
        $this->assertFalse($report['awis_learning_loop']['claim_policy']['raw_diff_returned']);
        $this->assertFalse($report['awis_learning_loop']['claim_policy']['absolute_workspace_path_returned']);
        $this->assertFalse($report['awis_learning_loop']['claim_policy']['auto_promotes_memory']);
        $this->assertFalse($report['awis_learning_loop']['claim_policy']['cross_workspace_learning_allowed']);
        $this->assertSame(64, strlen((string) $report['awis_learning_loop']['loop_hash']));
        $this->assertSame('atlas.awis.workspace_learning_snapshot.v1', $report['workspace_learning_snapshot']['schema_version']);
        $this->assertSame('ready', $report['workspace_learning_snapshot']['status']);
        $this->assertSame('atlas', $report['workspace_learning_snapshot']['workspace_id']);
        $this->assertSame(0.98, $report['workspace_learning_snapshot']['learning_score']);
        $this->assertSame($report['workspace']['workspace_hash'], $report['workspace_learning_snapshot']['workspace_hash']);
        $this->assertSame($report['repository_inventory']['inventory_hash'], $report['workspace_learning_snapshot']['component_hashes']['repository_inventory_hash']);
        $this->assertSame($report['workspace_next_session_brain']['context_loading_plan']['working_set_hash'], $report['workspace_learning_snapshot']['component_hashes']['workspace_working_set_hash']);
        $this->assertSame($report['workspace_next_session_brain']['context_loading_plan']['context_delta_plan_hash'], $report['workspace_learning_snapshot']['component_hashes']['context_delta_plan_hash']);
        $this->assertGreaterThanOrEqual(0, $report['workspace_learning_snapshot']['learned_signal_counts']['working_set_hot_area_count']);
        $this->assertGreaterThanOrEqual(0, $report['workspace_learning_snapshot']['learned_signal_counts']['context_delta_changed_area_count']);
        $this->assertGreaterThanOrEqual(0, $report['workspace_learning_snapshot']['learned_signal_counts']['context_delta_effectiveness_profile_count']);
        $this->assertSame($report['workspace_change_memory']['change_hash'], $report['workspace_learning_snapshot']['component_hashes']['workspace_change_hash']);
        $this->assertSame($report['workspace_focus_map']['focus_hash'], $report['workspace_learning_snapshot']['component_hashes']['workspace_focus_hash']);
        $this->assertSame($report['workspace_live_execution_memory']['live_memory_hash'], $report['workspace_learning_snapshot']['component_hashes']['workspace_live_execution_memory_hash']);
        $this->assertGreaterThanOrEqual(0, $report['workspace_learning_snapshot']['learned_signal_counts']['live_memory_repository_count']);
        $this->assertGreaterThanOrEqual(0, $report['workspace_learning_snapshot']['learned_signal_counts']['live_memory_command_count']);
        $this->assertTrue($report['workspace_learning_snapshot']['workspace_state']['learning_loop_closed']);
        $this->assertFalse($report['workspace_learning_snapshot']['persistence_policy']['auto_promotes_memory']);
        $this->assertFalse($report['workspace_learning_snapshot']['persistence_policy']['cross_workspace_learning_allowed']);
        $this->assertFalse($report['workspace_learning_snapshot']['source_policy']['raw_file_content_returned']);
        $this->assertFalse($report['workspace_learning_snapshot']['source_policy']['raw_diff_returned']);
        $this->assertFalse($report['workspace_learning_snapshot']['source_policy']['raw_log_returned']);
        $this->assertFalse($report['workspace_learning_snapshot']['source_policy']['raw_provider_text_returned']);
        $this->assertSame(64, strlen((string) $report['workspace_learning_snapshot']['snapshot_hash']));
        $this->assertSame(
            $report['workspace_learning_snapshot']['snapshot_hash'],
            $report['workspace_next_session_brain']['context_loading_plan']['learning_snapshot_hash'],
        );
        $this->assertSame(
            $report['workspace_learning_snapshot']['snapshot_hash'],
            $report['workspace_next_session_brain']['context_loading_plan']['cache_keys']['workspace_learning_snapshot_hash'],
        );
        $this->assertContains(
            'workspace_learning_snapshot_hash_changed',
            $report['workspace_next_session_brain']['context_loading_plan']['refresh_triggers'],
        );
        $this->assertSame('ready', $report['execution_boundaries']['status']);
        $this->assertGreaterThanOrEqual(17, $report['execution_boundaries']['guarded_boundaries_total']);
        $this->assertGreaterThanOrEqual(20, $report['execution_boundaries']['process_inventory_total']);
        $this->assertSame(0, $report['execution_boundaries']['process_inventory_unclassified']);
        $this->assertSame('ready', $report['registry_editing']['status']);
        $this->assertContains('archive', $report['registry_editing']['api_actions']);
        $this->assertTrue($report['registry_editing']['requirements']['api_archive_route']);
        $this->assertTrue($report['registry_editing']['requirements']['controller_destroy']);
        $this->assertTrue($report['registry_editing']['requirements']['service_archive']);
        $this->assertTrue($report['claim_policy']['known_execution_boundaries_audited']);
        $this->assertFalse($report['claim_policy']['unclassified_workspace_process_boundaries_allowed']);
        $this->assertTrue($report['claim_policy']['ui_registry_editing_complete']);
        $this->assertTrue($report['claim_policy']['workspace_learning_loop_closed']);
        $this->assertTrue($report['claim_policy']['workspace_change_memory_provider_safe']);
        $this->assertTrue($report['claim_policy']['workspace_focus_map_provider_safe']);
        $this->assertTrue($report['claim_policy']['workspace_live_execution_memory_provider_safe']);
        $this->assertTrue($report['claim_policy']['workspace_next_session_brain_provider_safe']);
        $this->assertTrue($report['claim_policy']['workspace_learning_snapshot_provider_safe']);
        $this->assertSame(64, strlen((string) $report['runtime_hash']));
    }

    public function test_workspace_outcome_memory_ranks_commands_from_real_dev_outcomes(): void
    {
        $preferred = '/opt/homebrew/bin/php artisan atlas:engineering:knowledge docs-health --json';
        $failing = '/opt/homebrew/bin/php artisan test';
        $flaky = 'php artisan test --filter=FlakyArea';
        $slow = 'cd atlas-server && php artisan test --filter=SlowIntegration';
        $policyRef = 'awis_cache:execution_optimization_policy:'.str_repeat('a', 64);
        $workingSetRef = 'awis_cache:workspace_working_set:'.str_repeat('6', 64);
        $contextDeltaRef = 'awis_cache:context_delta_plan:'.str_repeat('5', 64);
        $instantTierRef = 'awis_validation_tier:instant';
        $docsRouteRef = 'area:'.hash('sha256', 'docs/engineering-knowledge-base');
        $routeRef = 'awis_execution_route_command:'.hash('sha256', $preferred).':'.$docsRouteRef;

        $goodPacket = AtlasDevTaskPacket::query()->create([
            'schema_version' => 'atlas.dev.task_packet.v1',
            'uuid' => (string) Str::uuid(),
            'run_id' => 'awis-outcome-good',
            'task_id' => 'task-good',
            'objective' => 'prove docs health command ranking',
            'task_class' => 'verification',
            'risk_band' => 'low',
            'workspace_slug' => 'atlas',
            'allowed_files' => [],
            'forbidden_files' => [],
            'context_refs' => [$policyRef, $workingSetRef, $contextDeltaRef, $routeRef, $instantTierRef],
            'expected_files' => ['docs/engineering-knowledge-base/atlas-workspace-twin-runtime.md'],
            'suggested_tests' => [$preferred],
            'acceptance_criteria' => ['docs health passes'],
            'required_evidence' => ['outcome_memory'],
            'source' => 'test',
            'task_packet_hash' => hash('sha256', 'awis-outcome-good'),
        ]);
        AtlasDevOutcomeMemory::query()->create([
            'schema_version' => 'atlas.dev.outcome_memory.v1',
            'uuid' => (string) Str::uuid(),
            'run_id' => 'awis-outcome-good',
            'task_id' => 'task-good',
            'task_packet_id' => $goodPacket->id,
            'failure_capsule_id' => null,
            'outcome_status' => 'success',
            'evidence_kinds' => ['docs_health'],
            'selected_tests' => [$preferred],
            'changed_files' => ['docs/engineering-knowledge-base/atlas-workspace-twin-runtime.md'],
            'learning_candidates' => [],
            'should_promote_to_aemor' => true,
            'human_review_required' => false,
            'outcome_memory_hash' => hash('sha256', 'awis-outcome-good-memory'),
        ]);

        $badPacket = AtlasDevTaskPacket::query()->create([
            'schema_version' => 'atlas.dev.task_packet.v1',
            'uuid' => (string) Str::uuid(),
            'run_id' => 'awis-outcome-bad',
            'task_id' => 'task-bad',
            'objective' => 'record failing broad test command',
            'task_class' => 'verification',
            'risk_band' => 'medium',
            'workspace_slug' => 'atlas',
            'allowed_files' => [],
            'forbidden_files' => [],
            'context_refs' => [$policyRef, $workingSetRef, $contextDeltaRef, $instantTierRef],
            'expected_files' => ['atlas-server/app/Services/Ai/LegacyBroadTest.php'],
            'suggested_tests' => [$failing],
            'acceptance_criteria' => ['broad test command observed'],
            'required_evidence' => ['outcome_memory'],
            'source' => 'test',
            'task_packet_hash' => hash('sha256', 'awis-outcome-bad'),
        ]);
        AtlasDevOutcomeMemory::query()->create([
            'schema_version' => 'atlas.dev.outcome_memory.v1',
            'uuid' => (string) Str::uuid(),
            'run_id' => 'awis-outcome-bad',
            'task_id' => 'task-bad',
            'task_packet_id' => $badPacket->id,
            'failure_capsule_id' => null,
            'outcome_status' => 'failed',
            'evidence_kinds' => ['test'],
            'selected_tests' => [$failing],
            'changed_files' => ['atlas-server/app/Services/Ai/LegacyBroadTest.php'],
            'learning_candidates' => [],
            'should_promote_to_aemor' => true,
            'human_review_required' => true,
            'outcome_memory_hash' => hash('sha256', 'awis-outcome-bad-memory'),
        ]);

        $flakyPacket = AtlasDevTaskPacket::query()->create([
            'schema_version' => 'atlas.dev.task_packet.v1',
            'uuid' => (string) Str::uuid(),
            'run_id' => 'awis-outcome-flaky',
            'task_id' => 'task-flaky',
            'objective' => 'record mixed command history for area ranking',
            'task_class' => 'verification',
            'risk_band' => 'medium',
            'workspace_slug' => 'atlas',
            'allowed_files' => [],
            'forbidden_files' => [],
            'context_refs' => [],
            'expected_files' => ['docs/engineering-knowledge-base/atlas-workspace-twin-runtime.md'],
            'suggested_tests' => [$flaky],
            'acceptance_criteria' => ['flaky command observed'],
            'required_evidence' => ['outcome_memory'],
            'source' => 'test',
            'task_packet_hash' => hash('sha256', 'awis-outcome-flaky'),
        ]);
        foreach (['success', 'failed'] as $index => $status) {
            AtlasDevOutcomeMemory::query()->create([
                'schema_version' => 'atlas.dev.outcome_memory.v1',
                'uuid' => (string) Str::uuid(),
                'run_id' => 'awis-outcome-flaky-'.$index,
                'task_id' => 'task-flaky-'.$index,
                'task_packet_id' => $flakyPacket->id,
                'failure_capsule_id' => null,
                'outcome_status' => $status,
                'evidence_kinds' => ['test'],
                'selected_tests' => [$flaky],
                'changed_files' => ['docs/engineering-knowledge-base/atlas-workspace-twin-runtime.md'],
                'learning_candidates' => [],
                'should_promote_to_aemor' => true,
                'human_review_required' => $status !== 'success',
                'outcome_memory_hash' => hash('sha256', 'awis-outcome-flaky-memory-'.$index),
            ]);
        }

        $engineeringRun = AtlasEngineeringRun::query()->create([
            'task_id' => (string) Str::uuid(),
            'workspace_path_hash' => hash('sha256', 'atlas-workspace-path'),
            'workspace_label' => 'atlas',
            'provider_strategy_json' => [],
            'context_pack_hash' => hash('sha256', 'awis-context-pack'),
            'status' => 'completed',
            'decision' => 'accepted',
            'max_attempts' => 1,
            'attempt_count' => 1,
            'metadata' => ['workspace_slug' => 'atlas'],
        ]);
        AtlasEngineeringTestRun::query()->create([
            'engineering_run_id' => $engineeringRun->id,
            'command' => $preferred,
            'exit_code' => 0,
            'status' => 'passed',
            'duration_ms' => 7_500,
            'metadata' => [
                'expected_files' => ['docs/engineering-knowledge-base/atlas-workspace-twin-runtime.md'],
                'evidence_hash' => hash('sha256', 'awis-fast-engineering-run'),
            ],
        ]);
        AtlasEngineeringTestRun::query()->create([
            'engineering_run_id' => $engineeringRun->id,
            'command' => $slow,
            'exit_code' => 0,
            'status' => 'passed',
            'duration_ms' => 420_000,
            'metadata' => [
                'expected_files' => ['atlas-server/app/Services/Ai/SlowIntegration.php'],
                'evidence_hash' => hash('sha256', 'awis-slow-engineering-run'),
            ],
        ]);

        $report = app(AtlasWorkspaceIntelligenceRuntimeService::class)->certify(
            workspace: 'atlas',
            task: 'validar docs e testes do AWIS',
        );

        $memory = $report['awtr']['test_command_intelligence']['outcome_memory'];

        $this->assertSame('atlas.workspace_outcome_command_memory.v1', $memory['schema_version']);
        $this->assertSame('ready', $memory['status']);
        $this->assertSame(4, $memory['observed_command_count']);
        $this->assertContains($preferred, $memory['ranked_commands']);
        $this->assertContains($flaky, $memory['flaky_commands']);
        $this->assertContains($slow, $memory['slow_commands']);
        $this->assertContains($failing, $memory['avoid_commands']);
        $this->assertContains($slow, $memory['avoid_commands']);
        $this->assertSame(2, $memory['command_outcome_index'][$preferred]['success_count']);
        $this->assertSame(2, $memory['command_outcome_index'][$preferred]['total_count']);
        $this->assertSame(1, $memory['command_outcome_index'][$failing]['failure_count']);
        $this->assertSame('stable', $memory['command_outcome_index'][$preferred]['stability']);
        $this->assertSame('mixed', $memory['command_outcome_index'][$flaky]['stability']);
        $this->assertSame('fast', $memory['command_outcome_index'][$preferred]['performance_grade']);
        $this->assertSame('slow', $memory['command_outcome_index'][$slow]['performance_grade']);
        $this->assertSame(7500, $memory['command_outcome_index'][$preferred]['duration_ms_avg']);
        $this->assertSame(7500, $memory['command_outcome_index'][$preferred]['duration_ms_p95']);
        $this->assertSame(1, $memory['command_outcome_index'][$preferred]['duration_bucket_counts']['under_10s']);
        $this->assertSame(420000, $memory['command_outcome_index'][$slow]['duration_ms_avg']);
        $this->assertSame(420000, $memory['command_outcome_index'][$slow]['duration_ms_p95']);
        $this->assertSame(1, $memory['command_outcome_index'][$slow]['duration_bucket_counts']['5m_to_15m']);
        $this->assertSame('atlas.workspace_command_performance_histogram.v1', $memory['performance_histogram']['schema_version']);
        $this->assertSame(2, $memory['performance_histogram']['performance_observed_command_count']);
        $this->assertSame(1, $memory['performance_histogram']['bucket_counts']['under_10s']);
        $this->assertSame(1, $memory['performance_histogram']['bucket_counts']['5m_to_15m']);
        $this->assertContains($preferred, $memory['performance_histogram']['fast_commands']);
        $this->assertContains($slow, $memory['performance_histogram']['slow_commands']);
        $this->assertSame('atlas.workspace_execution_policy_effectiveness_index.v1', $memory['execution_policy_effectiveness_index']['schema_version']);
        $this->assertSame(1, $memory['execution_policy_effectiveness_index']['policy_count']);
        $this->assertSame('execution_optimization_policy:'.str_repeat('a', 64), $memory['execution_policy_effectiveness_index']['policies'][0]['policy_ref']);
        $this->assertSame(1, $memory['execution_policy_effectiveness_index']['policies'][0]['success_count']);
        $this->assertSame(1, $memory['execution_policy_effectiveness_index']['policies'][0]['failure_count']);
        $this->assertSame('mixed', $memory['execution_policy_effectiveness_index']['policies'][0]['effectiveness']);
        $this->assertContains($preferred, $memory['execution_policy_effectiveness_index']['policies'][0]['commands']);
        $this->assertContains($failing, $memory['execution_policy_effectiveness_index']['policies'][0]['commands']);
        $this->assertFalse($memory['execution_policy_effectiveness_index']['source_policy']['raw_logs_returned']);
        $this->assertSame(64, strlen((string) $memory['execution_policy_effectiveness_index']['index_hash']));
        $this->assertSame('atlas.workspace_execution_route_effectiveness_index.v1', $memory['execution_route_effectiveness_index']['schema_version']);
        $this->assertSame(1, $memory['execution_route_effectiveness_index']['route_count']);
        $this->assertSame($docsRouteRef, $memory['execution_route_effectiveness_index']['routes'][0]['route_ref']);
        $this->assertSame('effective', $memory['execution_route_effectiveness_index']['routes'][0]['effectiveness']);
        $this->assertContains($preferred, $memory['execution_route_effectiveness_index']['routes'][0]['commands']);
        $this->assertFalse($memory['execution_route_effectiveness_index']['source_policy']['raw_logs_returned']);
        $this->assertSame('atlas.workspace_validation_tier_effectiveness_index.v1', $memory['validation_tier_effectiveness_index']['schema_version']);
        $this->assertSame(1, $memory['validation_tier_effectiveness_index']['tier_count']);
        $this->assertSame('tier:instant', $memory['validation_tier_effectiveness_index']['tiers'][0]['tier_ref']);
        $this->assertSame('mixed', $memory['validation_tier_effectiveness_index']['tiers'][0]['effectiveness']);
        $this->assertSame(1, $memory['validation_tier_effectiveness_index']['tiers'][0]['success_count']);
        $this->assertSame(1, $memory['validation_tier_effectiveness_index']['tiers'][0]['failure_count']);
        $this->assertFalse($memory['validation_tier_effectiveness_index']['source_policy']['raw_logs_returned']);
        $this->assertSame('atlas.workspace_working_set_effectiveness_index.v1', $memory['working_set_effectiveness_index']['schema_version']);
        $this->assertSame(1, $memory['working_set_effectiveness_index']['working_set_count']);
        $this->assertSame('workspace_working_set:'.str_repeat('6', 64), $memory['working_set_effectiveness_index']['working_sets'][0]['working_set_ref']);
        $this->assertSame('mixed', $memory['working_set_effectiveness_index']['working_sets'][0]['effectiveness']);
        $this->assertFalse($memory['working_set_effectiveness_index']['source_policy']['raw_logs_returned']);
        $this->assertSame('atlas.workspace_context_delta_effectiveness_index.v1', $memory['context_delta_effectiveness_index']['schema_version']);
        $this->assertSame(1, $memory['context_delta_effectiveness_index']['context_delta_plan_count']);
        $this->assertSame('context_delta_plan:'.str_repeat('5', 64), $memory['context_delta_effectiveness_index']['context_delta_plans'][0]['context_delta_ref']);
        $this->assertSame('mixed', $memory['context_delta_effectiveness_index']['context_delta_plans'][0]['effectiveness']);
        $this->assertFalse($memory['context_delta_effectiveness_index']['source_policy']['raw_logs_returned']);
        $this->assertSame('atlas.workspace_area_performance_index.v1', $memory['area_performance_index']['schema_version']);
        $this->assertSame('atlas.workspace_stack_performance_index.v1', $memory['stack_performance_index']['schema_version']);
        $this->assertContains('laravel', array_column($memory['stack_performance_index']['profiles'], 'key'));
        $this->assertContains('php', array_column($memory['stack_performance_index']['profiles'], 'key'));
        $this->assertContains('atlas-server/app', array_column($memory['area_performance_index']['profiles'], 'key'));
        $this->assertSame(1, $memory['command_outcome_index'][$slow]['stack_performance']['laravel']['observed_count']);
        $this->assertSame(420000, $memory['command_outcome_index'][$slow]['stack_performance']['laravel']['duration_ms_p95']);
        $this->assertSame(1, $memory['command_outcome_index'][$slow]['area_performance']['atlas-server/app']['observed_count']);
        $this->assertGreaterThan(
            $memory['command_outcome_index'][$preferred]['score'],
            $memory['command_outcome_index'][$preferred]['effective_score'],
        );
        $this->assertLessThan(
            $memory['command_outcome_index'][$slow]['score'],
            $memory['command_outcome_index'][$slow]['effective_score'],
        );
        $this->assertSame(2, $memory['command_outcome_index'][$preferred]['area_affinity']['docs/engineering-knowledge-base']);
        $this->assertSame(2, $memory['command_outcome_index'][$flaky]['area_affinity']['docs/engineering-knowledge-base']);
        $this->assertFalse($memory['source_policy']['raw_log_returned']);
        $this->assertFalse($memory['source_policy']['raw_provider_text_returned']);
        $this->assertSame($preferred, $report['awtr']['test_command_intelligence']['commands'][0]);
        $this->assertSame($preferred, $report['workspace_focus_map']['focused_commands'][0]);
        $this->assertSame($preferred, $report['workspace_next_session_brain']['execution_priority'][0]['command']);
        $this->assertSame($memory['outcome_memory_hash'], $report['workspace_next_session_brain']['context_loading_plan']['outcome_command_memory_hash']);
        $this->assertContains($preferred, $report['workspace_next_session_brain']['context_loading_plan']['outcome_ranked_commands']);
        $this->assertContains($flaky, $report['workspace_next_session_brain']['context_loading_plan']['area_ranked_commands']);
        $this->assertContains($flaky, $report['workspace_next_session_brain']['context_loading_plan']['flaky_commands']);
        $this->assertContains($slow, $report['workspace_next_session_brain']['context_loading_plan']['slow_commands']);
        $this->assertContains($failing, $report['workspace_next_session_brain']['context_loading_plan']['avoid_commands']);
        $this->assertContains($slow, $report['workspace_next_session_brain']['context_loading_plan']['avoid_commands']);
        $this->assertTrue($report['workspace_next_session_brain']['context_loading_plan']['command_performance_policy']['prefer_recent_stable_fast_commands']);
        $this->assertTrue($report['workspace_next_session_brain']['context_loading_plan']['command_performance_policy']['uses_duration_p95']);
        $this->assertTrue($report['workspace_next_session_brain']['context_loading_plan']['command_performance_policy']['uses_duration_buckets']);
        $this->assertSame($memory['performance_histogram']['histogram_hash'], $report['workspace_next_session_brain']['context_loading_plan']['command_performance_histogram_hash']);
        $this->assertSame($memory['area_performance_index']['index_hash'], $report['workspace_next_session_brain']['context_loading_plan']['area_performance_index_hash']);
        $this->assertSame($memory['stack_performance_index']['index_hash'], $report['workspace_next_session_brain']['context_loading_plan']['stack_performance_index_hash']);
        $this->assertSame($memory['execution_policy_effectiveness_index']['index_hash'], $report['workspace_next_session_brain']['context_loading_plan']['execution_policy_effectiveness_index_hash']);
        $this->assertSame($memory['execution_route_effectiveness_index']['index_hash'], $report['workspace_next_session_brain']['context_loading_plan']['execution_route_effectiveness_index_hash']);
        $this->assertSame($memory['validation_tier_effectiveness_index']['index_hash'], $report['workspace_next_session_brain']['context_loading_plan']['validation_tier_effectiveness_index_hash']);
        $this->assertSame($memory['working_set_effectiveness_index']['index_hash'], $report['workspace_next_session_brain']['context_loading_plan']['working_set_effectiveness_index_hash']);
        $this->assertSame($memory['context_delta_effectiveness_index']['index_hash'], $report['workspace_next_session_brain']['context_loading_plan']['context_delta_effectiveness_index_hash']);
        $this->assertSame('mixed', $report['workspace_next_session_brain']['context_loading_plan']['execution_policy_effectiveness_profiles'][0]['effectiveness']);
        $this->assertSame('effective', $report['workspace_next_session_brain']['context_loading_plan']['execution_route_effectiveness_profiles'][0]['effectiveness']);
        $this->assertSame('mixed', $report['workspace_next_session_brain']['context_loading_plan']['validation_tier_effectiveness_profiles'][0]['effectiveness']);
        $this->assertSame('mixed', $report['workspace_next_session_brain']['context_loading_plan']['working_set_effectiveness_profiles'][0]['effectiveness']);
        $this->assertSame('mixed', $report['workspace_next_session_brain']['context_loading_plan']['context_delta_effectiveness_profiles'][0]['effectiveness']);
        $this->assertSame('prefer_partial_refresh_until_delta_stabilizes', $report['workspace_next_session_brain']['context_loading_plan']['context_delta_plan']['feedback']['next_adjustment']);
        $this->assertSame('refresh_hot_areas_and_recompute_command_order', $report['workspace_next_session_brain']['context_loading_plan']['workspace_working_set']['feedback']['next_adjustment']);
        $optimizationPolicy = $report['workspace_next_session_brain']['context_loading_plan']['execution_optimization_policy'];
        $this->assertSame('atlas.awis.execution_optimization_policy.v1', $optimizationPolicy['schema_version']);
        $this->assertSame('prefer_fast_stable_area_relevant_commands', $optimizationPolicy['mode']);
        $this->assertContains($preferred, $optimizationPolicy['preferred_commands']);
        $this->assertContains($slow, $optimizationPolicy['blocked_commands']);
        $this->assertContains($failing, $optimizationPolicy['blocked_commands']);
        $this->assertSame(1, $optimizationPolicy['policy_feedback']['observed_policy_count']);
        $this->assertContains('execution_optimization_policy:'.str_repeat('a', 64), $optimizationPolicy['policy_feedback']['mixed_policy_refs']);
        $this->assertSame('tighten_default_to_preferred_fast_commands', $optimizationPolicy['policy_feedback']['next_adjustment']);
        $this->assertSame(4, $optimizationPolicy['policy_feedback']['standard_command_limit']);
        $this->assertSame('atlas.awis.execution_scope_routing.v1', $optimizationPolicy['scope_routing']['schema_version']);
        $this->assertGreaterThan(0, $optimizationPolicy['scope_routing']['route_count']);
        $this->assertTrue($optimizationPolicy['selection_policy']['scope_routes_override_global_order_when_present']);
        $areaRoutes = array_column($optimizationPolicy['scope_routing']['area_routes'], null, 'key');
        $stackRoutes = array_column($optimizationPolicy['scope_routing']['stack_routes'], null, 'key');
        $this->assertSame($docsRouteRef, $areaRoutes['docs/engineering-knowledge-base']['route_ref']);
        $this->assertSame('effective', $areaRoutes['docs/engineering-knowledge-base']['feedback_effectiveness']);
        $this->assertContains($preferred, $areaRoutes['docs/engineering-knowledge-base']['preferred_commands']);
        $this->assertSame('standard', $areaRoutes['docs/engineering-knowledge-base']['recommended_validation_tier']);
        $this->assertSame('instant_tier_feedback_guarded', $areaRoutes['docs/engineering-knowledge-base']['validation_reason']);
        $this->assertContains($slow, $areaRoutes['atlas-server/app']['blocked_commands']);
        $this->assertSame('deep_validation_only', $areaRoutes['atlas-server/app']['route_mode']);
        $this->assertSame('deep', $areaRoutes['atlas-server/app']['recommended_validation_tier']);
        $this->assertContains($slow, $stackRoutes['laravel']['blocked_commands']);
        $this->assertTrue($optimizationPolicy['selection_policy']['prepend_preferred_commands_to_task_packets']);
        $this->assertTrue($optimizationPolicy['selection_policy']['route_feedback_controls_validation_depth']);
        $this->assertSame('atlas.awis.validation_tier_routing.v1', $optimizationPolicy['validation_tier_routing']['schema_version']);
        $this->assertSame('route_and_risk_aware_validation_depth', $optimizationPolicy['validation_tier_routing']['mode']);
        $this->assertGreaterThanOrEqual(1, $optimizationPolicy['validation_tier_routing']['standard_route_count']);
        $this->assertGreaterThanOrEqual(1, $optimizationPolicy['validation_tier_routing']['deep_route_count']);
        $this->assertSame('mixed', $optimizationPolicy['validation_tier_routing']['tier_feedback']['instant_effectiveness']);
        $this->assertTrue($optimizationPolicy['validation_tier_routing']['tier_feedback']['instant_guarded']);
        $this->assertFalse($optimizationPolicy['validation_tier_routing']['raw_logs_returned']);
        $this->assertFalse($optimizationPolicy['source_policy']['raw_logs_returned']);
        $this->assertSame(64, strlen((string) $optimizationPolicy['policy_hash']));
        $this->assertSame($optimizationPolicy['policy_hash'], $report['workspace_next_session_brain']['context_loading_plan']['execution_optimization_policy_hash']);
        $this->assertSame(1, $report['workspace_next_session_brain']['context_loading_plan']['command_performance_histogram']['bucket_counts']['under_10s']);
        $this->assertSame(1, $report['workspace_next_session_brain']['context_loading_plan']['command_performance_histogram']['bucket_counts']['5m_to_15m']);
        $this->assertContains('atlas-server/app', array_column($report['workspace_next_session_brain']['context_loading_plan']['area_performance_profiles'], 'key'));
        $this->assertContains('laravel', array_column($report['workspace_next_session_brain']['context_loading_plan']['stack_performance_profiles'], 'key'));
        $this->assertSame($memory['outcome_memory_hash'], $report['workspace_next_session_brain']['context_loading_plan']['cache_keys']['outcome_command_memory_hash']);
        $this->assertSame($memory['performance_histogram']['histogram_hash'], $report['workspace_next_session_brain']['context_loading_plan']['cache_keys']['command_performance_histogram_hash']);
        $this->assertSame($memory['area_performance_index']['index_hash'], $report['workspace_next_session_brain']['context_loading_plan']['cache_keys']['area_performance_index_hash']);
        $this->assertSame($memory['stack_performance_index']['index_hash'], $report['workspace_next_session_brain']['context_loading_plan']['cache_keys']['stack_performance_index_hash']);
        $this->assertSame($memory['execution_policy_effectiveness_index']['index_hash'], $report['workspace_next_session_brain']['context_loading_plan']['cache_keys']['execution_policy_effectiveness_index_hash']);
        $this->assertSame($memory['execution_route_effectiveness_index']['index_hash'], $report['workspace_next_session_brain']['context_loading_plan']['cache_keys']['execution_route_effectiveness_index_hash']);
        $this->assertSame($memory['validation_tier_effectiveness_index']['index_hash'], $report['workspace_next_session_brain']['context_loading_plan']['cache_keys']['validation_tier_effectiveness_index_hash']);
        $this->assertSame($memory['working_set_effectiveness_index']['index_hash'], $report['workspace_next_session_brain']['context_loading_plan']['cache_keys']['working_set_effectiveness_index_hash']);
        $this->assertSame($memory['context_delta_effectiveness_index']['index_hash'], $report['workspace_next_session_brain']['context_loading_plan']['cache_keys']['context_delta_effectiveness_index_hash']);
        $this->assertSame($optimizationPolicy['policy_hash'], $report['workspace_next_session_brain']['context_loading_plan']['cache_keys']['execution_optimization_policy_hash']);
        $this->assertTrue($report['workspace_next_session_brain']['performance_budget']['uses_outcome_command_memory']);
        $this->assertTrue($report['workspace_next_session_brain']['performance_budget']['uses_command_performance_memory']);
        $this->assertTrue($report['workspace_next_session_brain']['performance_budget']['uses_execution_optimization_policy']);
        $this->assertSame($optimizationPolicy['policy_hash'], $report['workspace_next_session_brain']['performance_budget']['execution_optimization_policy_hash']);
    }

    public function test_workspace_handoff_pack_projects_provider_safe_context(): void
    {
        $pack = app(AtlasWorkspaceHandoffPackService::class)->build(
            workspace: 'atlas',
            task: 'corrigir bug na tela de login',
            consumer: 'atlas_dev',
        );

        $this->assertSame(AtlasWorkspaceHandoffPackService::SCHEMA_VERSION, $pack['schema_version']);
        $this->assertSame('ready', $pack['status']);
        $this->assertSame('atlas_dev', $pack['consumer']);
        $this->assertSame('atlas', $pack['workspace']['workspace_id']);
        $this->assertSame('workspace', $pack['workspace']['memory_scope']);
        $this->assertTrue($pack['execution_contract']['provider_safe']);
        $this->assertFalse($pack['execution_contract']['raw_conversation_included']);
        $this->assertFalse($pack['execution_contract']['cross_workspace_memory_allowed']);
        $this->assertContains('handoff_packet', $pack['required_artifacts']);
        $this->assertSame([], $pack['missing_artifacts']);
        $this->assertNotEmpty($pack['context_units']);
        $this->assertSame('ready', $pack['next_session_brain']['status']);
        $this->assertNotEmpty($pack['next_session_brain']['load_order']);
        $this->assertSame('atlas.awis.context_loading_plan.v1', $pack['next_session_brain']['context_loading_plan']['schema_version']);
        $this->assertSame(64, strlen((string) $pack['next_session_brain']['context_loading_plan']['repository_inventory_hash']));
        $this->assertFalse($pack['next_session_brain']['context_loading_plan']['provider_policy']['raw_manifest_returned']);
        $this->assertFalse($pack['next_session_brain']['context_loading_plan']['provider_policy']['script_bodies_returned']);
        $this->assertFalse($pack['next_session_brain']['raw_content_returned']);
        $this->assertSame('atlas.awis.workspace_live_execution_memory.v1', $pack['live_execution_memory']['schema_version']);
        $this->assertSame('ready', $pack['live_execution_memory']['status']);
        $this->assertSame(64, strlen((string) $pack['live_execution_memory']['live_memory_hash']));
        $this->assertContains('workspace_live_execution_memory', $pack['live_execution_memory']['startup_packet']['load_first']);
        $this->assertContains('workspace_live_execution_memory_hash', $pack['live_execution_memory']['startup_packet']['validate_before_trust']);
        $this->assertSame(
            $pack['live_execution_memory']['live_memory_hash'],
            $pack['next_session_brain']['context_loading_plan']['live_execution_memory_hash'],
        );
        $this->assertSame(
            $pack['live_execution_memory']['live_memory_hash'],
            $pack['live_execution_memory']['cache_keys']['workspace_live_execution_memory_hash'],
        );
        $this->assertSame('workspace_runbook.body.live_execution_memory', $pack['live_execution_memory']['persistence_contract']['embedded_in_artifact_lake']);
        $this->assertFalse($pack['live_execution_memory']['source_policy']['raw_file_content_returned']);
        $this->assertFalse($pack['live_execution_memory']['source_policy']['raw_diff_returned']);
        $this->assertFalse($pack['live_execution_memory']['source_policy']['raw_conversation_returned']);
        $this->assertFalse($pack['live_execution_memory']['source_policy']['absolute_workspace_path_returned']);
        $this->assertNotEmpty($pack['scope_guard']['owner_docs']);
        $this->assertNotEmpty($pack['test_contract']['focused_tests']);
        $this->assertTrue($pack['claim_policy']['safe_for_provider_prompt']);
        $this->assertTrue($pack['claim_policy']['next_session_brain_provider_safe']);
        $this->assertTrue($pack['claim_policy']['live_execution_memory_provider_safe']);
        $this->assertFalse($pack['claim_policy']['raw_conversation_returned']);
        $this->assertStringNotContainsString('/Users/', json_encode($pack, JSON_THROW_ON_ERROR));
        $this->assertSame(64, strlen((string) $pack['handoff_hash']));
    }

    public function test_workspace_handoff_pack_blocks_unknown_workspace(): void
    {
        $pack = app(AtlasWorkspaceHandoffPackService::class)->build(
            workspace: 'missing-workspace',
            task: 'corrigir bug',
            consumer: 'atlas_forge',
        );

        $this->assertSame('blocked', $pack['status']);
        $this->assertContains('workspace_intelligence_not_ready', $pack['blockers']);
        $this->assertFalse($pack['claim_policy']['safe_for_provider_prompt']);
        $this->assertFalse($pack['claim_policy']['raw_conversation_returned']);
    }

    public function test_workspace_handoff_pack_command_and_api_emit_same_contract(): void
    {
        Artisan::call('atlas:workspace-intelligence', [
            'action' => 'handoff-pack',
            '--workspace' => 'atlas',
            '--task' => 'corrigir bug login',
            '--consumer' => 'atlas_forge',
            '--json' => true,
        ]);

        $cli = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(AtlasWorkspaceHandoffPackService::SCHEMA_VERSION, $cli['schema_version']);
        $this->assertSame('atlas_forge', $cli['consumer']);
        $this->assertContains('execution_plan', $cli['required_artifacts']);

        $response = $this->withHeaders($this->headers())->getJson(
            '/atlas-code/workspace-intelligence/handoff-pack?workspace=atlas&task=corrigir%20bug%20login&consumer=atlas_forge',
        );

        $response
            ->assertOk()
            ->assertJsonPath('schema_version', AtlasWorkspaceHandoffPackService::SCHEMA_VERSION)
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('consumer', 'atlas_forge')
            ->assertJsonPath('execution_contract.raw_conversation_included', false)
            ->assertJsonPath('claim_policy.invokes_provider', false);
    }

    public function test_atlas_server_path_resolves_to_server_workspace(): void
    {
        $report = app(AtlasWorkspaceIntelligenceRuntimeService::class)->certify(
            workspace: base_path(),
            task: 'auditar contexto a partir do atlas-server',
        );
        $slugReport = app(AtlasWorkspaceIntelligenceRuntimeService::class)->certify(
            workspace: 'atlas-server',
            task: 'auditar contexto a partir do atlas-server',
        );

        $this->assertSame('ready', $report['status']);
        $this->assertSame('ready', $report['workspace']['status']);
        $this->assertSame('atlas-server', $report['workspace']['workspace_id']);
        $this->assertSame([], $report['workspace']['blockers']);
        $this->assertFalse($report['claim_policy']['raw_conversation_used_as_prompt']);
        $this->assertSame('ready', $slugReport['status']);
        $this->assertSame('atlas-server', $slugReport['workspace']['workspace_id']);
    }

    public function test_unknown_workspace_blocks_without_fabricating_context(): void
    {
        $report = app(AtlasWorkspaceIntelligenceRuntimeService::class)->certify(
            workspace: 'missing-workspace',
            task: 'qualquer coisa',
        );

        $this->assertSame('blocked', $report['status']);
        $this->assertSame('blocked', $report['workspace']['status']);
        $this->assertSame(['workspace_not_registered'], $report['workspace']['blockers']);
        $this->assertSame('blocked', $report['awair']['status']);
        $this->assertFalse($report['awair']['artifact_replay']['replay_ready']);
        $this->assertSame('blocked', $report['awair']['artifact_simulation']['decision']);
        $this->assertSame('blocked', $report['awco']['execution_readiness_status']);
        $this->assertSame(false, $report['claim_policy']['raw_conversation_used_as_prompt']);
    }

    public function test_long_conversation_is_archived_by_hash_and_not_used_as_prompt(): void
    {
        $raw = str_repeat('contexto bruto sensivel ', 200).'decidido: usar AWIS; blocker: contexto velho';

        $report = app(AtlasWorkspaceIntelligenceRuntimeService::class)->certify(
            workspace: 'atlas',
            task: 'gerar pacote minimo',
            conversationTexts: [$raw],
        );

        $this->assertSame(1, $report['acios']['raw_archive']['conversation_count']);
        $this->assertSame('audit_only', $report['acios']['raw_archive']['access_mode']);
        $this->assertSame(false, $report['acios']['current_truth_pack']['raw_conversation_in_prompt']);
        $this->assertSame(false, $report['awaf']['artifacts'][2]['body']['raw_conversation_included']);

        $encoded = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString(str_repeat('contexto bruto sensivel ', 20), $encoded);
    }

    public function test_command_emits_json_and_strict_ready_for_atlas(): void
    {
        $exit = Artisan::call('atlas:workspace-intelligence', [
            'action' => 'certify',
            '--workspace' => 'atlas',
            '--task' => 'corrigir bug na tela de login',
            '--json' => true,
            '--strict' => true,
        ]);

        $this->assertSame(0, $exit);
        $decoded = json_decode(Artisan::output(), true);
        $this->assertIsArray($decoded);
        $this->assertSame('ready', $decoded['status']);
        $this->assertSame('atlas', $decoded['workspace']['workspace_id']);
        $this->assertSame('atlas.workspace_intelligence.runtime.v1', $decoded['schema_version']);
    }

    public function test_command_strict_fails_for_missing_workspace(): void
    {
        $exit = Artisan::call('atlas:workspace-intelligence', [
            'action' => 'certify',
            '--workspace' => 'missing-workspace',
            '--json' => true,
            '--strict' => true,
        ]);

        $this->assertSame(1, $exit);
        $decoded = json_decode(Artisan::output(), true);
        $this->assertIsArray($decoded);
        $this->assertSame('blocked', $decoded['status']);
    }

    public function test_api_exposes_workspace_intelligence_for_desktop_and_mobile_surfaces(): void
    {
        $response = $this->withHeaders($this->headers())->getJson(
            '/atlas-code/workspace-intelligence?workspace=atlas&task='.urlencode('corrigir bug login'),
        );

        $response
            ->assertOk()
            ->assertJsonPath('schema_version', AtlasWorkspaceIntelligenceRuntimeService::SCHEMA_VERSION)
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('workspace.workspace_id', 'atlas')
            ->assertJsonPath('awaf.artifact_count', 10)
            ->assertJsonPath('awco.execution_readiness_status', 'ready');
    }

    public function test_api_artifacts_endpoint_returns_awaf_projection_only(): void
    {
        $response = $this->withHeaders($this->headers())->getJson(
            '/atlas-code/workspace-intelligence/artifacts?workspace=atlas&task='.urlencode('corrigir bug login'),
        );

        $response
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.workspace_artifact_fabric.v1')
            ->assertJsonPath('workspace_id', 'atlas')
            ->assertJsonPath('artifact_count', 10);
    }

    public function test_api_twin_endpoint_returns_awtr_projection_only(): void
    {
        $response = $this->withHeaders($this->headers())->getJson(
            '/atlas-code/workspace-intelligence/twin?workspace=atlas',
        );

        $response
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.workspace_twin.v1')
            ->assertJsonPath('workspace_id', 'atlas')
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('genome.schema_version', 'atlas.workspace_genome.v1')
            ->assertJsonPath('context_autopilot.raw_conversation_policy', 'hash_and_excerpt_only')
            ->assertJsonMissingPath('awaf')
            ->assertJsonMissingPath('workspace');

        $this->assertSame(64, strlen((string) $response->json('twin_hash')));
        $this->assertSame(64, strlen((string) $response->json('genome.genome_hash')));
        $this->assertSame(64, strlen((string) $response->json('living_code_map.code_map_hash')));
    }

    public function test_command_twin_returns_awtr_projection_only(): void
    {
        $exit = Artisan::call('atlas:workspace-intelligence', [
            'action' => 'twin',
            '--workspace' => 'atlas',
            '--json' => true,
            '--strict' => true,
        ]);

        $this->assertSame(0, $exit);
        $decoded = json_decode(Artisan::output(), true);
        $this->assertIsArray($decoded);
        $this->assertSame('atlas.workspace_twin.v1', $decoded['schema_version']);
        $this->assertSame('ready', $decoded['status']);
        $this->assertSame('atlas', $decoded['workspace_id']);
        $this->assertSame('atlas.workspace_context_autopilot.v1', $decoded['context_autopilot']['schema_version']);
        $this->assertArrayNotHasKey('awaf', $decoded);
        $this->assertArrayNotHasKey('workspace', $decoded);
    }

    public function test_api_contracts_endpoint_returns_awco_projection_only(): void
    {
        $response = $this->withHeaders($this->headers())->getJson(
            '/atlas-code/workspace-intelligence/contracts?workspace=atlas&task='.urlencode('corrigir bug login'),
        );

        $response
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.workspace_contract_orchestrator.v1')
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('execution_readiness_status', 'ready')
            ->assertJsonPath('certification_envelope.artifact_count', 10)
            ->assertJsonPath('certification_envelope.blocked_count', 0)
            ->assertJsonMissingPath('awaf')
            ->assertJsonMissingPath('workspace');

        $this->assertSame(64, strlen((string) $response->json('contract_hash')));
    }

    public function test_command_contracts_returns_awco_projection_only(): void
    {
        $exit = Artisan::call('atlas:workspace-intelligence', [
            'action' => 'contracts',
            '--workspace' => 'atlas',
            '--task' => 'corrigir bug login',
            '--json' => true,
            '--strict' => true,
        ]);

        $this->assertSame(0, $exit);
        $decoded = json_decode(Artisan::output(), true);
        $this->assertIsArray($decoded);
        $this->assertSame('atlas.workspace_contract_orchestrator.v1', $decoded['schema_version']);
        $this->assertSame('ready', $decoded['status']);
        $this->assertSame('all_artifacts_must_have_workspace_and_source_hashes', $decoded['certification_envelope']['quality_policy']);
        $this->assertContains('provider_invocation', $decoded['orchestration_plan']['mutative_consumers']);
        $this->assertArrayNotHasKey('awaf', $decoded);
        $this->assertArrayNotHasKey('workspace', $decoded);
    }

    public function test_api_evolution_endpoint_returns_awef_projection_only(): void
    {
        $response = $this->withHeaders($this->headers())->getJson(
            '/atlas-code/workspace-intelligence/evolution?workspace=atlas',
        );

        $response
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.workspace_evolution_fabric.v1')
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('privacy_preserving_transfer', true)
            ->assertJsonPath('privacy_transfer_gate.allows_raw_cross_workspace', false)
            ->assertJsonPath('workspace_benchmark.mode', 'read_only_shadow')
            ->assertJsonMissingPath('awaf')
            ->assertJsonMissingPath('workspace');

        $this->assertSame(64, strlen((string) $response->json('evolution_hash')));
    }

    public function test_command_evolution_returns_awef_projection_only(): void
    {
        $exit = Artisan::call('atlas:workspace-intelligence', [
            'action' => 'evolution',
            '--workspace' => 'atlas',
            '--json' => true,
            '--strict' => true,
        ]);

        $this->assertSame(0, $exit);
        $decoded = json_decode(Artisan::output(), true);
        $this->assertIsArray($decoded);
        $this->assertSame('atlas.workspace_evolution_fabric.v1', $decoded['schema_version']);
        $this->assertSame('ready', $decoded['status']);
        $this->assertSame('abstracted_only', $decoded['pattern_library']['privacy_level']);
        $this->assertContains('raw_context_present', $decoded['privacy_transfer_gate']['block_when']);
        $this->assertArrayNotHasKey('awaf', $decoded);
        $this->assertArrayNotHasKey('workspace', $decoded);
    }

    public function test_workspace_learning_loop_closes_event_memory_context_action_cycle(): void
    {
        $loop = app(AtlasWorkspaceIntelligenceRuntimeService::class)->learningLoop(
            workspace: 'atlas',
            task: 'corrigir bug login',
            conversationTexts: ['decisao: manter AWIS como fonte de contexto; feito: teste verde'],
        );

        $this->assertSame('atlas.awis.workspace_intelligence_loop.v1', $loop['schema_version']);
        $this->assertSame('ready', $loop['status']);
        $this->assertSame('atlas', $loop['workspace_id']);
        $this->assertGreaterThanOrEqual(4, $loop['event_intake']['event_count']);
        $this->assertContains('current_truth_pack', $loop['operational_memory']['candidate_units']);
        $this->assertContains('workspace_runbook_delta', $loop['operational_memory']['candidate_units']);
        $this->assertContains('workspace_change_memory', $loop['operational_memory']['candidate_units']);
        $this->assertContains('workspace_next_session_brain', $loop['operational_memory']['candidate_units']);
        $this->assertSame(64, strlen((string) $loop['context_application']['workspace_change_memory_hash']));
        $this->assertSame(64, strlen((string) $loop['context_application']['workspace_next_session_brain_hash']));
        $this->assertFalse($loop['context_application']['raw_diff_included']);
        $this->assertSame('regenerate_awis_before_mutative_execution', $loop['context_application']['stale_policy']);
        $this->assertSame('prepare_provider_safe_handoff', $loop['next_action']['action']);
        $this->assertSame([], $loop['evidence_learning']['missing_evidence']);
        $this->assertTrue($loop['closed_loop']['event_to_understanding']);
        $this->assertTrue($loop['closed_loop']['understanding_to_memory']);
        $this->assertTrue($loop['closed_loop']['memory_to_context']);
        $this->assertTrue($loop['closed_loop']['context_to_next_action']);
        $this->assertTrue($loop['closed_loop']['evidence_to_learning']);
        $this->assertTrue($loop['closed_loop']['loop_closed']);
        $this->assertSame(64, strlen((string) $loop['loop_hash']));
    }

    public function test_workspace_change_memory_tracks_dirty_files_without_raw_diff_or_absolute_path(): void
    {
        $workspace = $this->makeGitWorkspace('awis-change-memory');
        @mkdir($workspace.'/atlas-server/app/Services/Ai', 0777, true);
        file_put_contents($workspace.'/atlas-server/app/Services/Ai/ChangedService.php', "<?php\n// changed\n");

        config()->set('atlas_projects.default_slug', 'awis-change-memory');
        config()->set('atlas_projects.profiles', [[
            'id' => 'awis-change-memory',
            'slug' => 'awis-change-memory',
            'name' => 'AWIS Change Memory',
            'kind' => 'product',
            'workspace_path' => $workspace,
            'repo_root' => $workspace,
            'production_status' => 'development',
            'stack_summary' => 'Laravel PHP TypeScript',
            'commands' => ['install' => 'composer install'],
            'test_commands' => ['php artisan test'],
            'build_commands' => ['npm run build'],
            'dev_server_command' => 'php artisan serve',
            'critical_areas' => ['atlas-server/app/Services/Ai', 'docs/engineering-knowledge-base'],
            'docs_status' => 'canonical',
            'default_risk' => 'medium',
            'deployment_notes' => 'test workspace',
            'surfaces_enabled' => ['atlas_ai'],
        ]]);

        $report = app(AtlasWorkspaceIntelligenceRuntimeService::class)->certify(
            workspace: 'awis-change-memory',
            task: 'registrar mudanca provider-safe',
        );

        $changeMemory = $report['workspace_change_memory'];
        $encoded = json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $this->assertContains($report['status'], ['ready', 'blocked']);
        $this->assertSame('ready', $changeMemory['status']);
        $this->assertTrue($changeMemory['is_git']);
        $this->assertTrue($changeMemory['dirty']);
        $this->assertSame(1, $changeMemory['changed_file_count']);
        $this->assertContains('atlas-server/app/Services/Ai/ChangedService.php', $changeMemory['changed_files_preview']);
        $this->assertContains('atlas-server', $changeMemory['top_level_areas']);
        $this->assertContains('atlas-server/app/Services/Ai', $changeMemory['critical_areas_touched']);
        $this->assertFalse($changeMemory['source_policy']['raw_diff_returned']);
        $this->assertFalse($changeMemory['source_policy']['absolute_workspace_path_returned']);
        $this->assertSame($changeMemory['change_hash'], $report['awis_learning_loop']['context_application']['workspace_change_memory_hash']);
        $this->assertTrue($report['awis_learning_loop']['context_application']['critical_changes_require_review']);
        $this->assertContains('workspace_change_memory', $report['awis_learning_loop']['operational_memory']['candidate_units']);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('diff_excerpt', $encoded);
        $this->assertStringNotContainsString($workspace, $encoded);
        $this->assertStringNotContainsString('// changed', $encoded);
    }

    public function test_workspace_change_memory_discovers_nested_git_repositories_for_broad_folder(): void
    {
        $workspace = storage_path('framework/testing/awis-broad-folder-'.bin2hex(random_bytes(4)));
        @mkdir($workspace.'/atlas-server', 0777, true);
        @mkdir($workspace.'/atlas-desktop', 0777, true);
        $this->initGitWorkspace($workspace.'/atlas-server', 'atlas-server');
        $this->initGitWorkspace($workspace.'/atlas-desktop', 'atlas-desktop');
        file_put_contents($workspace.'/atlas-server/artisan', "#!/usr/bin/env php\n");
        file_put_contents($workspace.'/atlas-desktop/package.json', json_encode([
            'scripts' => [
                'build' => 'vite build',
                'typecheck' => 'tsc -b',
            ],
            'dependencies' => [
                'react' => '^19.0.0',
                '@tauri-apps/api' => '^2.0.0',
            ],
            'devDependencies' => [
                'typescript' => '^5.0.0',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        @mkdir($workspace.'/atlas-server/app/Services/Ai', 0777, true);
        file_put_contents($workspace.'/atlas-server/app/Services/Ai/BroadFolderChange.php', "<?php\n// broad folder change\n");

        config()->set('atlas_projects.default_slug', 'awis-broad-folder');
        config()->set('atlas_projects.profiles', [[
            'id' => 'awis-broad-folder',
            'slug' => 'awis-broad-folder',
            'name' => 'AWIS Broad Folder',
            'kind' => 'product',
            'workspace_path' => $workspace,
            'repo_root' => $workspace,
            'production_status' => 'development',
            'stack_summary' => 'Laravel PHP Tauri React TypeScript',
            'commands' => ['install' => 'composer install'],
            'test_commands' => ['php artisan test'],
            'build_commands' => ['npm run build'],
            'dev_server_command' => 'php artisan serve',
            'critical_areas' => ['atlas-server/app/Services/Ai', 'atlas-desktop/apps/desktop/src/surfaces'],
            'docs_status' => 'canonical',
            'default_risk' => 'medium',
            'deployment_notes' => 'test broad workspace',
            'surfaces_enabled' => ['atlas_ai'],
        ]]);

        $report = app(AtlasWorkspaceIntelligenceRuntimeService::class)->certify(
            workspace: 'awis-broad-folder',
            task: 'agregar repos internos',
        );

        $changeMemory = $report['workspace_change_memory'];
        $encoded = json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $this->assertSame('ready', $changeMemory['status']);
        $this->assertTrue($changeMemory['is_git']);
        $this->assertSame(2, $changeMemory['repository_count']);
        $this->assertSame(['atlas-desktop', 'atlas-server'], collect($changeMemory['repositories'])->pluck('repo_key')->all());
        $this->assertTrue($changeMemory['dirty']);
        $this->assertContains('atlas-server/app/Services/Ai/BroadFolderChange.php', $changeMemory['changed_files_preview']);
        $this->assertContains('atlas-server/app/Services/Ai', $changeMemory['critical_areas_touched']);
        $this->assertSame($changeMemory['change_hash'], $report['awis_learning_loop']['context_application']['workspace_change_memory_hash']);
        $this->assertSame('ready', $report['workspace_focus_map']['status']);
        $this->assertContains('atlas-server', collect($report['workspace_focus_map']['focused_repositories'])->pluck('repo_key')->all());
        $this->assertContains('atlas-server/app/Services/Ai', $report['workspace_focus_map']['focused_areas']);
        $this->assertContains('cd atlas-desktop && npm run typecheck', $report['workspace_focus_map']['focused_commands']);
        $this->assertSame($report['workspace_focus_map']['focus_hash'], $report['awis_learning_loop']['context_application']['workspace_focus_hash']);
        $this->assertSame('ready', $report['workspace_next_session_brain']['status']);
        $this->assertContains('repository_inventory', $report['workspace_next_session_brain']['resume_packet']['load_order']);
        $this->assertContains('cd atlas-desktop && npm run typecheck', collect($report['workspace_next_session_brain']['execution_priority'])->pluck('command')->all());
        $this->assertContains('atlas-server', collect($report['workspace_next_session_brain']['resume_packet']['focused_repositories'])->pluck('repo_key')->all());
        $this->assertSame($report['awtr']['repository_inventory']['inventory_hash'], $report['repository_inventory']['inventory_hash']);
        $this->assertSame($report['repository_inventory']['inventory_hash'], $report['workspace_next_session_brain']['context_loading_plan']['repository_inventory_hash']);
        $this->assertGreaterThanOrEqual(2, $report['workspace_next_session_brain']['context_loading_plan']['repository_count']);
        $this->assertContains('typescript', $report['workspace_next_session_brain']['context_loading_plan']['stack_tags']);
        $this->assertContains('cd atlas-desktop && npm run typecheck', $report['workspace_next_session_brain']['context_loading_plan']['command_hints']);
        $this->assertContains('atlas-server', collect($report['workspace_next_session_brain']['context_loading_plan']['focused_manifest_refs'])->pluck('repo_key')->all());
        $this->assertFalse($report['workspace_next_session_brain']['context_loading_plan']['provider_policy']['raw_manifest_returned']);
        $this->assertFalse($report['workspace_next_session_brain']['context_loading_plan']['provider_policy']['script_bodies_returned']);
        $this->assertSame($report['workspace_next_session_brain']['brain_hash'], $report['awis_learning_loop']['context_application']['workspace_next_session_brain_hash']);
        $this->assertContains('repository_inventory', $report['awaf']['artifacts'][2]['body']['context_units']);
        $this->assertContains('cd atlas-desktop && npm run typecheck', $report['awaf']['artifacts'][4]['body']['focused_tests']);
        $this->assertSame('ready', $report['awtr']['repository_inventory']['status']);
        $this->assertGreaterThanOrEqual(2, $report['awtr']['repository_inventory']['repository_count']);
        $this->assertContains('atlas-desktop', collect($report['awtr']['repository_inventory']['repositories'])->pluck('repo_key')->all());
        $this->assertContains('atlas-server', collect($report['awtr']['repository_inventory']['repositories'])->pluck('repo_key')->all());
        $this->assertContains('laravel', $report['awtr']['genome']['stack']);
        $this->assertContains('react', $report['awtr']['genome']['stack']);
        $this->assertContains('tauri', $report['awtr']['genome']['stack']);
        $this->assertContains('typescript', $report['awtr']['genome']['stack']);
        $this->assertContains('cd atlas-desktop && npm run build', $report['awtr']['repository_inventory']['command_hints']);
        $this->assertContains('cd atlas-desktop && npm run typecheck', $report['awtr']['command_registry']['commands']);
        $this->assertFalse($report['awtr']['repository_inventory']['source_policy']['raw_manifest_returned']);
        $this->assertFalse($report['awtr']['repository_inventory']['source_policy']['script_bodies_returned']);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('diff_excerpt', $encoded);
        $this->assertStringNotContainsString($workspace, $encoded);
        $this->assertStringNotContainsString('broad folder change', $encoded);
        $this->assertStringNotContainsString('vite build', $encoded);
    }

    public function test_workspace_learning_loop_command_and_api_emit_same_contract(): void
    {
        $exit = Artisan::call('atlas:workspace-intelligence', [
            'action' => 'learning-loop',
            '--workspace' => 'atlas',
            '--task' => 'corrigir bug login',
            '--conversation' => ['decisao: usar contexto AWIS minimo'],
            '--json' => true,
            '--strict' => true,
        ]);

        $this->assertSame(0, $exit);
        $cli = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('atlas.awis.workspace_intelligence_loop.v1', $cli['schema_version']);
        $this->assertSame('ready', $cli['status']);
        $this->assertTrue($cli['closed_loop']['loop_closed']);
        $this->assertFalse($cli['claim_policy']['provider_calls_made']);

        $response = $this->withHeaders($this->headers())->getJson(
            '/atlas-code/workspace-intelligence/learning-loop?workspace=atlas&task='.urlencode('corrigir bug login'),
        );

        $response
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.awis.workspace_intelligence_loop.v1')
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('workspace_id', 'atlas')
            ->assertJsonPath('context_application.raw_conversation_included', false)
            ->assertJsonPath('closed_loop.loop_closed', true)
            ->assertJsonPath('claim_policy.auto_promotes_memory', false);
    }

    public function test_workspace_next_session_brain_command_and_api_emit_same_contract(): void
    {
        $exit = Artisan::call('atlas:workspace-intelligence', [
            'action' => 'next-session-brain',
            '--workspace' => 'atlas',
            '--task' => 'corrigir bug atlas ai',
            '--json' => true,
            '--strict' => true,
        ]);

        $this->assertSame(0, $exit);
        $cli = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('atlas.awis.workspace_next_session_brain.v1', $cli['schema_version']);
        $this->assertSame('ready', $cli['status']);
        $this->assertSame('atlas', $cli['workspace_id']);
        $this->assertContains('workspace_change_memory', $cli['resume_packet']['load_order']);
        $this->assertContains('workspace_focus_map', $cli['resume_packet']['load_order']);
        $this->assertSame('atlas.awis.context_loading_plan.v1', $cli['context_loading_plan']['schema_version']);
        $this->assertSame(64, strlen((string) $cli['context_loading_plan']['repository_inventory_hash']));
        $this->assertFalse($cli['context_loading_plan']['provider_policy']['raw_manifest_returned']);
        $this->assertFalse($cli['context_loading_plan']['provider_policy']['script_bodies_returned']);
        $this->assertFalse($cli['source_policy']['raw_file_content_returned']);
        $this->assertFalse($cli['source_policy']['raw_conversation_returned']);
        $this->assertSame(64, strlen((string) $cli['brain_hash']));

        $response = $this->withHeaders($this->headers())->getJson(
            '/atlas-code/workspace-intelligence/next-session-brain?workspace=atlas&task='.urlencode('corrigir bug atlas ai'),
        );

        $response
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.awis.workspace_next_session_brain.v1')
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('workspace_id', 'atlas')
            ->assertJsonPath('source_policy.raw_file_content_returned', false)
            ->assertJsonPath('source_policy.raw_conversation_returned', false)
            ->assertJsonMissingPath('awaf')
            ->assertJsonMissingPath('awtr');

        $this->assertSame(64, strlen((string) $response->json('brain_hash')));
    }

    public function test_workspace_live_execution_memory_command_and_api_emit_same_contract(): void
    {
        $exit = Artisan::call('atlas:workspace-intelligence', [
            'action' => 'live-execution-memory',
            '--workspace' => 'atlas',
            '--task' => 'retomar AWIS sem nascer zerado',
            '--json' => true,
            '--strict' => true,
        ]);

        $this->assertSame(0, $exit);
        $cli = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('atlas.awis.workspace_live_execution_memory.v1', $cli['schema_version']);
        $this->assertSame('ready', $cli['status']);
        $this->assertSame('atlas', $cli['workspace_id']);
        $this->assertContains('workspace_live_execution_memory', $cli['startup_packet']['load_first']);
        $this->assertContains('workspace_live_execution_memory_hash', $cli['startup_packet']['validate_before_trust']);
        $this->assertFalse($cli['source_policy']['raw_file_content_returned']);
        $this->assertFalse($cli['source_policy']['raw_diff_returned']);
        $this->assertFalse($cli['source_policy']['raw_conversation_returned']);
        $this->assertFalse($cli['source_policy']['absolute_workspace_path_returned']);
        $this->assertSame($cli['live_memory_hash'], $cli['cache_keys']['workspace_live_execution_memory_hash']);
        $this->assertSame(64, strlen((string) $cli['live_memory_hash']));

        $response = $this->withHeaders($this->headers())->getJson(
            '/atlas-code/workspace-intelligence/live-execution-memory?workspace=atlas&task='.urlencode('retomar AWIS sem nascer zerado'),
        );

        $response
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.awis.workspace_live_execution_memory.v1')
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('workspace_id', 'atlas')
            ->assertJsonPath('source_policy.raw_file_content_returned', false)
            ->assertJsonPath('source_policy.raw_diff_returned', false)
            ->assertJsonPath('source_policy.raw_conversation_returned', false)
            ->assertJsonPath('source_policy.absolute_workspace_path_returned', false)
            ->assertJsonMissingPath('awaf')
            ->assertJsonMissingPath('awtr');

        $this->assertSame(64, strlen((string) $response->json('live_memory_hash')));
        $this->assertSame($response->json('live_memory_hash'), $response->json('cache_keys.workspace_live_execution_memory_hash'));
    }

    public function test_api_artifact_intelligence_endpoint_returns_awair_projection_only(): void
    {
        $response = $this->withHeaders($this->headers())->getJson(
            '/atlas-code/workspace-intelligence/artifact-intelligence?workspace=atlas&task='.urlencode('corrigir bug login'),
        );

        $response
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.workspace_artifact_intelligence.v1')
            ->assertJsonPath('workspace_id', 'atlas')
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('artifact_lake.artifact_count', 10)
            ->assertJsonPath('artifact_replay.replay_ready', true)
            ->assertJsonPath('artifact_context_compiler.raw_conversation_included', false)
            ->assertJsonMissingPath('awaf')
            ->assertJsonMissingPath('workspace');
    }

    public function test_command_artifact_intelligence_returns_awair_projection_only(): void
    {
        $exit = Artisan::call('atlas:workspace-intelligence', [
            'action' => 'artifact-intelligence',
            '--workspace' => 'atlas',
            '--task' => 'corrigir bug login',
            '--json' => true,
            '--strict' => true,
        ]);

        $this->assertSame(0, $exit);
        $decoded = json_decode(Artisan::output(), true);
        $this->assertIsArray($decoded);
        $this->assertSame('atlas.workspace_artifact_intelligence.v1', $decoded['schema_version']);
        $this->assertSame('ready', $decoded['status']);
        $this->assertSame('atlas', $decoded['workspace_id']);
        $this->assertSame(10, $decoded['artifact_lake']['artifact_count']);
        $this->assertTrue($decoded['artifact_replay']['replay_ready']);
        $this->assertArrayNotHasKey('awaf', $decoded);
        $this->assertArrayNotHasKey('workspace', $decoded);
    }

    public function test_dedicated_workspace_artifacts_command_exposes_graph_replay_simulation_and_shadow(): void
    {
        foreach ([
            'graph' => 'atlas.workspace_artifact_graph_projection.v1',
            'replay' => 'atlas.workspace_artifact_replay_projection.v1',
            'simulate' => 'atlas.workspace_artifact_simulation_projection.v1',
            'shadow' => 'atlas.workspace_artifact_shadow_execution.v1',
        ] as $action => $schema) {
            $exit = Artisan::call('atlas:workspace-artifacts', [
                'action' => $action,
                '--workspace' => 'atlas',
                '--task' => 'corrigir bug login',
                '--json' => true,
                '--strict' => true,
            ]);

            $this->assertSame(0, $exit, "workspace-artifacts {$action} should pass strict mode");
            $decoded = json_decode(Artisan::output(), true);
            $this->assertIsArray($decoded);
            $this->assertSame($schema, $decoded['schema_version']);
            $this->assertSame('ready', $decoded['status']);
            $this->assertSame('atlas', $decoded['workspace_id']);
            $this->assertArrayNotHasKey('awaf', $decoded);
            $this->assertArrayNotHasKey('workspace', $decoded);
        }
    }

    public function test_dedicated_workspace_artifacts_workroom_returns_provider_safe_artifact_packet(): void
    {
        $exit = Artisan::call('atlas:workspace-artifacts', [
            'action' => 'workroom',
            '--workspace' => 'atlas',
            '--task' => 'corrigir bug login',
            '--artifact' => 'task_packet',
            '--json' => true,
            '--strict' => true,
        ]);

        $this->assertSame(0, $exit);
        $decoded = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        $this->assertSame('atlas.workspace_artifact_workroom.v1', $decoded['schema_version']);
        $this->assertSame('ready', $decoded['status']);
        $this->assertSame('AWAOL', $decoded['family']);
        $this->assertSame('atlas', $decoded['workspace_id']);
        $this->assertSame('task_packet', $decoded['artifact_type']);
        $this->assertSame('atlas.workspace_artifact_human_packet.v1', $decoded['human_packet']['schema_version']);
        $this->assertSame('atlas.workspace_artifact_agent_packet.v1', $decoded['agent_packet']['schema_version']);
        $this->assertFalse($decoded['agent_packet']['raw_conversation_included']);
        $this->assertFalse($decoded['agent_packet']['artifact_body_included']);
        $this->assertFalse($decoded['source_policy']['raw_conversation_returned']);
        $this->assertFalse($decoded['source_policy']['artifact_body_returned']);
        $this->assertFalse($decoded['claim_policy']['invokes_provider']);
        $this->assertSame('ready', $decoded['replay_point']['status']);
        $this->assertNotEmpty($decoded['timeline']);
        $this->assertNotEmpty($decoded['routes']);
        $this->assertSame(64, strlen((string) $decoded['workroom_hash']));

        $encoded = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('"body"', $encoded);
    }

    public function test_dedicated_workspace_artifacts_command_exposes_awaol_route_diff_replay_point_and_retire(): void
    {
        foreach ([
            'route' => 'atlas.workspace_artifact_route_projection.v1',
            'diff' => 'atlas.workspace_artifact_diff_projection.v1',
            'replay-point' => 'atlas.workspace_artifact_replay_point_projection.v1',
        ] as $action => $schema) {
            $exit = Artisan::call('atlas:workspace-artifacts', [
                'action' => $action,
                '--workspace' => 'atlas',
                '--task' => 'corrigir bug login',
                '--artifact' => 'task_packet',
                '--json' => true,
                '--strict' => true,
            ]);

            $this->assertSame(0, $exit, "workspace-artifacts {$action} should pass strict mode");
            $decoded = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertIsArray($decoded);
            $this->assertSame($schema, $decoded['schema_version']);
            $this->assertSame('ready', $decoded['status']);
            $this->assertSame('atlas', $decoded['workspace_id']);
            $this->assertSame('task_packet', $decoded['artifact_type']);
            $this->assertFalse($decoded['source_policy']['raw_conversation_returned']);
            $this->assertFalse($decoded['source_policy']['artifact_body_returned']);
            $this->assertFalse($decoded['claim_policy']['invokes_provider'] ?? false);

            $encoded = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $this->assertIsString($encoded);
            $this->assertStringNotContainsString('"body"', $encoded);
        }

        $retireExit = Artisan::call('atlas:workspace-artifacts', [
            'action' => 'retire',
            '--workspace' => 'atlas',
            '--task' => 'corrigir bug login',
            '--artifact' => 'task_packet',
            '--reason' => 'stale_context_pack',
            '--json' => true,
            '--strict' => true,
        ]);

        $this->assertSame(0, $retireExit);
        $retire = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('atlas.workspace_artifact_retirement_proposal.v1', $retire['schema_version']);
        $this->assertSame('ready', $retire['status']);
        $this->assertSame('stale_context_pack', $retire['reason']);
        $this->assertTrue($retire['replacement_required']);
        $this->assertFalse($retire['claim_policy']['artifact_deleted']);
        $this->assertNotEmpty($retire['persisted_retirement_proposal_id']);
        $this->assertSame('proposed', $retire['persisted_status']);
    }

    public function test_workspace_artifacts_route_outcome_and_retire_are_persisted_without_body_leak(): void
    {
        $routeExit = Artisan::call('atlas:workspace-artifacts', [
            'action' => 'route',
            '--workspace' => 'atlas',
            '--task' => 'corrigir bug login',
            '--artifact' => 'task_packet',
            '--json' => true,
            '--strict' => true,
        ]);
        $this->assertSame(0, $routeExit);
        $route = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('atlas.workspace_artifact_timeline_event.v1', data_get($route, 'timeline_event.schema_version'));
        $this->assertSame('route_decision', data_get($route, 'timeline_event.event_type'));

        $outcomeExit = Artisan::call('atlas:workspace-artifacts', [
            'action' => 'outcome',
            '--workspace' => 'atlas',
            '--task' => 'corrigir bug login',
            '--artifact' => 'task_packet',
            '--outcome-status' => 'passed',
            '--summary' => 'Focused tests passed',
            '--json' => true,
            '--strict' => true,
        ]);
        $this->assertSame(0, $outcomeExit);
        $outcome = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('atlas.workspace_artifact_outcome_projection.v1', $outcome['schema_version']);
        $this->assertSame('ready', $outcome['status']);
        $this->assertSame('outcome_recorded', data_get($outcome, 'timeline_event.event_type'));
        $this->assertSame('passed', data_get($outcome, 'timeline_event.event_status'));
        $this->assertSame('ready', data_get($outcome, 'aemor_bridge.status'));
        $this->assertNotEmpty(data_get($outcome, 'aemor_bridge.outcome_hash'));
        $this->assertNotEmpty(data_get($outcome, 'aemor_bridge.memory_candidate_id'));

        $timelineExit = Artisan::call('atlas:workspace-artifacts', [
            'action' => 'timeline',
            '--workspace' => 'atlas',
            '--artifact' => 'task_packet',
            '--json' => true,
            '--strict' => true,
        ]);
        $this->assertSame(0, $timelineExit);
        $timeline = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('atlas.workspace_artifact_timeline.v1', $timeline['schema_version']);
        $this->assertSame(2, $timeline['count']);
        $eventTypes = collect($timeline['events'])->pluck('event_type')->sort()->values()->all();
        $this->assertSame(['outcome_recorded', 'route_decision'], $eventTypes);

        $retireExit = Artisan::call('atlas:workspace-artifacts', [
            'action' => 'retire',
            '--workspace' => 'atlas',
            '--artifact' => 'task_packet',
            '--reason' => 'superseded_by_new_task_packet',
            '--json' => true,
            '--strict' => true,
        ]);
        $this->assertSame(0, $retireExit);
        $retire = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertNotEmpty($retire['persisted_retirement_proposal_id']);

        $queueExit = Artisan::call('atlas:workspace-artifacts', [
            'action' => 'retirement-queue',
            '--workspace' => 'atlas',
            '--artifact' => 'task_packet',
            '--json' => true,
            '--strict' => true,
        ]);
        $this->assertSame(0, $queueExit);
        $queue = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('atlas.workspace_artifact_retirement_queue.v1', $queue['schema_version']);
        $this->assertSame(1, $queue['count']);
        $this->assertTrue(data_get($queue, 'proposals.0.replacement_required'));

        $blockedApplyExit = Artisan::call('atlas:workspace-artifacts', [
            'action' => 'retirement-apply',
            '--workspace' => 'atlas',
            '--artifact' => 'task_packet',
            '--json' => true,
            '--strict' => true,
        ]);
        $this->assertSame(1, $blockedApplyExit);
        $blockedApply = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertContains('replacement_artifact_required_before_apply', $blockedApply['blockers']);

        $applyExit = Artisan::call('atlas:workspace-artifacts', [
            'action' => 'retirement-apply',
            '--workspace' => 'atlas',
            '--artifact' => 'task_packet',
            '--replacement-artifact' => 'sha256:replacement-task-packet',
            '--json' => true,
            '--strict' => true,
        ]);
        $this->assertSame(0, $applyExit);
        $apply = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('atlas.workspace_artifact_retirement_apply.v1', $apply['schema_version']);
        $this->assertSame('applied', $apply['retirement_status']);
        $this->assertFalse($apply['claim_policy']['artifact_deleted']);

        $this->assertSame(2, AtlasWorkspaceArtifactTimelineEvent::query()->count());
        $this->assertSame(1, AtlasWorkspaceArtifactRetirementProposal::query()->count());
        $this->assertSame('applied', AtlasWorkspaceArtifactRetirementProposal::query()->firstOrFail()->status);
        $this->assertSame(1, AtlasAemorExecutionEpisode::query()->where('scope_type', 'workspace_artifact')->count());
        $this->assertSame(1, AtlasAemorOutcome::query()->count());
        $this->assertSame(1, AtlasAemorMemoryCandidate::query()->count());

        $encoded = json_encode([$route, $outcome, $timeline, $retire, $queue, $blockedApply, $apply], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('"body"', $encoded);
        $this->assertStringNotContainsString('contexto bruto sensivel', $encoded);
    }

    public function test_dedicated_workspace_artifacts_retire_requires_reason(): void
    {
        $exit = Artisan::call('atlas:workspace-artifacts', [
            'action' => 'retire',
            '--workspace' => 'atlas',
            '--task' => 'corrigir bug login',
            '--artifact' => 'task_packet',
            '--json' => true,
            '--strict' => true,
        ]);

        $this->assertSame(1, $exit);
        $decoded = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('atlas.workspace_artifact_retirement_proposal.v1', $decoded['schema_version']);
        $this->assertSame('blocked', $decoded['status']);
        $this->assertContains('retirement_reason_required', $decoded['blockers']);
        $this->assertFalse($decoded['claim_policy']['artifact_deleted']);
    }

    public function test_api_artifact_workroom_returns_human_and_agent_packets_without_body(): void
    {
        $response = $this->withHeaders($this->headers())->getJson(
            '/atlas-code/workspace-intelligence/artifact-workroom?workspace=atlas&task='.urlencode('corrigir bug login').'&artifact=task_packet',
        );

        $response
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.workspace_artifact_workroom.v1')
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('family', 'AWAOL')
            ->assertJsonPath('artifact_type', 'task_packet')
            ->assertJsonPath('agent_packet.raw_conversation_included', false)
            ->assertJsonPath('agent_packet.artifact_body_included', false)
            ->assertJsonPath('source_policy.raw_conversation_returned', false)
            ->assertJsonPath('source_policy.artifact_body_returned', false)
            ->assertJsonMissingPath('body');

        $this->assertSame(64, strlen((string) $response->json('workroom_hash')));
    }

    public function test_api_artifact_timeline_outcome_and_retirement_are_provider_safe(): void
    {
        $outcome = $this->withHeaders($this->headers())->postJson('/atlas-code/workspace-intelligence/artifact-outcome', [
            'workspace' => 'atlas',
            'task' => 'corrigir bug login',
            'artifact' => 'task_packet',
            'outcome_status' => 'passed',
            'summary' => 'Focused tests passed',
        ]);

        $outcome
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.workspace_artifact_outcome_projection.v1')
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('timeline_event.event_type', 'outcome_recorded')
            ->assertJsonPath('timeline_event.event_status', 'passed')
            ->assertJsonPath('aemor_bridge.status', 'ready')
            ->assertJsonPath('aemor_bridge.writes', true)
            ->assertJsonMissingPath('body');

        $timeline = $this->withHeaders($this->headers())->getJson(
            '/atlas-code/workspace-intelligence/artifact-timeline?workspace=atlas&artifact=task_packet',
        );

        $timeline
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.workspace_artifact_timeline.v1')
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('count', 1)
            ->assertJsonPath('events.0.event_type', 'outcome_recorded')
            ->assertJsonMissingPath('events.0.payload');

        $retirement = $this->withHeaders($this->headers())->postJson('/atlas-code/workspace-intelligence/artifact-retirement', [
            'workspace' => 'atlas',
            'artifact' => 'task_packet',
            'reason' => 'superseded_by_new_task_packet',
        ]);

        $retirement
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.workspace_artifact_retirement_proposal.v1')
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('persisted_status', 'proposed')
            ->assertJsonPath('claim_policy.artifact_deleted', false)
            ->assertJsonMissingPath('body');

        $queue = $this->withHeaders($this->headers())->getJson(
            '/atlas-code/workspace-intelligence/artifact-retirement-queue?workspace=atlas&artifact=task_packet',
        );
        $queue
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.workspace_artifact_retirement_queue.v1')
            ->assertJsonPath('count', 1)
            ->assertJsonMissingPath('proposals.0.payload');

        $apply = $this->withHeaders($this->headers())->postJson('/atlas-code/workspace-intelligence/artifact-retirement-apply', [
            'workspace' => 'atlas',
            'artifact' => 'task_packet',
            'replacement_artifact' => 'sha256:replacement-task-packet',
        ]);
        $apply
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.workspace_artifact_retirement_apply.v1')
            ->assertJsonPath('retirement_status', 'applied')
            ->assertJsonPath('claim_policy.artifact_deleted', false)
            ->assertJsonMissingPath('body');
    }

    public function test_dedicated_workspace_artifacts_latest_replays_only_when_workspace_hash_matches(): void
    {
        $persistExit = Artisan::call('atlas:workspace-artifacts', [
            'action' => 'certify',
            '--workspace' => 'atlas',
            '--task' => 'persistir grafo de artefatos',
            '--json' => true,
            '--persist' => true,
            '--strict' => true,
        ]);
        $this->assertSame(0, $persistExit);
        $persisted = json_decode(Artisan::output(), true);
        $this->assertIsArray($persisted);
        $this->assertSame('ready', $persisted['status']);
        $this->assertNotEmpty($persisted['workspace_hash']);

        $latestExit = Artisan::call('atlas:workspace-artifacts', [
            'action' => 'graph',
            '--workspace' => 'atlas',
            '--latest' => true,
            '--json' => true,
            '--strict' => true,
        ]);
        $this->assertSame(0, $latestExit);
        $latest = json_decode(Artisan::output(), true);
        $this->assertIsArray($latest);
        $this->assertSame('atlas.workspace_artifact_graph_projection.v1', $latest['schema_version']);
        $this->assertSame('ready', $latest['status']);
        $this->assertFalse($latest['stale']);
        $this->assertSame($persisted['artifact_graph']['graph_hash'], data_get($latest, 'artifact_graph.graph_hash'));
    }

    public function test_dedicated_workspace_artifacts_latest_blocks_stale_artifact_graph(): void
    {
        $persistExit = Artisan::call('atlas:workspace-artifacts', [
            'action' => 'certify',
            '--workspace' => 'atlas',
            '--task' => 'persistir grafo stale',
            '--json' => true,
            '--persist' => true,
            '--strict' => true,
        ]);
        $this->assertSame(0, $persistExit);
        $persisted = json_decode(Artisan::output(), true);
        $this->assertIsArray($persisted);

        $snapshot = AtlasWorkspaceArtifactGraphSnapshot::query()
            ->where('artifact_intelligence_hash', $persisted['artifact_intelligence_hash'])
            ->firstOrFail();
        $payload = $snapshot->payload;
        $payload['workspace_hash'] = str_repeat('2', 64);
        $snapshot->forceFill(['payload' => $payload])->save();

        $latestExit = Artisan::call('atlas:workspace-artifacts', [
            'action' => 'graph',
            '--workspace' => 'atlas',
            '--latest' => true,
            '--json' => true,
            '--strict' => true,
        ]);
        $this->assertSame(1, $latestExit);
        $latest = json_decode(Artisan::output(), true);
        $this->assertIsArray($latest);
        $this->assertSame('atlas.workspace_artifact_graph_projection.v1', $latest['schema_version']);
        $this->assertSame('blocked', $latest['status']);
        $this->assertTrue($latest['stale']);
        $this->assertSame('workspace_hash_changed', $latest['reason']);
        $this->assertSame(str_repeat('2', 64), $latest['snapshot_workspace_hash']);
        $this->assertSame($persisted['workspace_hash'], $latest['current_workspace_hash']);
        $this->assertSame(['workspace_artifact_graph_stale'], $latest['blockers']);
    }

    public function test_command_persists_snapshot_when_requested(): void
    {
        $exit = Artisan::call('atlas:workspace-intelligence', [
            'action' => 'certify',
            '--workspace' => 'atlas',
            '--task' => 'persistir snapshot AWIS',
            '--json' => true,
            '--persist' => true,
        ]);

        $this->assertSame(0, $exit);
        $decoded = json_decode(Artisan::output(), true);
        $this->assertIsArray($decoded);
        $this->assertNotEmpty($decoded['persisted_snapshot_id']);
        $this->assertNotEmpty($decoded['persisted_artifact_graph_id']);
        $this->assertCount(5, $decoded['persisted_projection_ids']);
        $this->assertArrayHasKey('AWTR', $decoded['persisted_projection_ids']);
        $this->assertArrayHasKey('AWCO', $decoded['persisted_projection_ids']);
        $this->assertArrayHasKey('AWEF', $decoded['persisted_projection_ids']);
        $this->assertArrayHasKey('AWIL', $decoded['persisted_projection_ids']);
        $this->assertArrayHasKey('AWNSB', $decoded['persisted_projection_ids']);

        $this->assertDatabaseHas('atlas_workspace_intelligence_snapshots', [
            'workspace_id' => 'atlas',
            'runtime_hash' => $decoded['runtime_hash'],
            'status' => 'ready',
            'artifacts_count' => 10,
        ]);
        $this->assertDatabaseHas('atlas_workspace_artifact_graph_snapshots', [
            'workspace_id' => 'atlas',
            'runtime_hash' => $decoded['runtime_hash'],
            'status' => 'ready',
            'artifact_count' => 10,
            'node_count' => 10,
            'replay_ready' => true,
            'simulation_decision' => 'ready',
        ]);
        $this->assertSame(10, AtlasWorkspaceArtifactLakeEntry::query()
            ->where('runtime_hash', $decoded['runtime_hash'])
            ->count());
        $this->assertSame(5, AtlasWorkspaceRuntimeProjectionSnapshot::query()
            ->where('runtime_hash', $decoded['runtime_hash'])
            ->count());

        $snapshot = AtlasWorkspaceIntelligenceSnapshot::query()
            ->where('runtime_hash', $decoded['runtime_hash'])
            ->firstOrFail();
        $this->assertSame('ready', $snapshot->family_status['AWAIR'] ?? null);
        $this->assertSame('atlas.workspace_artifact_intelligence.v1', data_get($snapshot->payload, 'awair.schema_version'));
        $this->assertSame('atlas.awis.workspace_learning_snapshot.v1', data_get($snapshot->payload, 'workspace_learning_snapshot.schema_version'));
        $this->assertSame($decoded['workspace_learning_snapshot']['snapshot_hash'], data_get($snapshot->payload, 'workspace_learning_snapshot.snapshot_hash'));
        $this->assertSame(
            $decoded['workspace_learning_snapshot']['snapshot_hash'],
            data_get($snapshot->payload, 'workspace_next_session_brain.context_loading_plan.cache_keys.workspace_learning_snapshot_hash'),
        );
        $this->assertFalse((bool) data_get($snapshot->payload, 'workspace_learning_snapshot.source_policy.raw_file_content_returned'));
        $this->assertFalse((bool) data_get($snapshot->payload, 'workspace_learning_snapshot.source_policy.raw_diff_returned'));
        $this->assertFalse((bool) data_get($snapshot->payload, 'workspace_learning_snapshot.source_policy.raw_log_returned'));
        $this->assertFalse((bool) data_get($snapshot->payload, 'workspace_learning_snapshot.source_policy.raw_provider_text_returned'));

        $graph = AtlasWorkspaceArtifactGraphSnapshot::query()
            ->where('runtime_hash', $decoded['runtime_hash'])
            ->firstOrFail();
        $this->assertSame($decoded['awair']['artifact_intelligence_hash'], $graph->artifact_intelligence_hash);
        $this->assertSame($decoded['awair']['artifact_graph']['graph_hash'], $graph->graph_hash);

        $twin = AtlasWorkspaceRuntimeProjectionSnapshot::query()
            ->where('runtime_hash', $decoded['runtime_hash'])
            ->where('family', 'AWTR')
            ->firstOrFail();
        $this->assertSame($decoded['awtr']['twin_hash'], data_get($twin->payload, 'twin_hash'));
        $this->assertSame('atlas.awis.runtime_projection_binding.v1', data_get($twin->payload, 'awis_projection.schema_version'));
        $this->assertSame($decoded['workspace']['workspace_hash'], data_get($twin->payload, 'awis_projection.workspace_hash'));
        $this->assertSame('block_latest_replay_when_workspace_hash_differs_or_is_missing', data_get($twin->payload, 'awis_projection.stale_policy'));

        $loop = AtlasWorkspaceRuntimeProjectionSnapshot::query()
            ->where('runtime_hash', $decoded['runtime_hash'])
            ->where('family', 'AWIL')
            ->firstOrFail();
        $this->assertSame($decoded['awis_learning_loop']['loop_hash'], data_get($loop->payload, 'loop_hash'));
        $this->assertTrue((bool) data_get($loop->payload, 'closed_loop.loop_closed'));

        $brain = AtlasWorkspaceRuntimeProjectionSnapshot::query()
            ->where('runtime_hash', $decoded['runtime_hash'])
            ->where('family', 'AWNSB')
            ->firstOrFail();
        $this->assertSame($decoded['workspace_next_session_brain']['brain_hash'], data_get($brain->payload, 'brain_hash'));
        $this->assertSame('atlas.awis.runtime_projection_binding.v1', data_get($brain->payload, 'awis_projection.schema_version'));
        $this->assertSame($decoded['workspace']['workspace_hash'], data_get($brain->payload, 'awis_projection.workspace_hash'));
        $this->assertSame('block_latest_replay_when_workspace_hash_differs_or_is_missing', data_get($brain->payload, 'awis_projection.stale_policy'));
    }

    public function test_api_latest_replays_persisted_snapshot(): void
    {
        $persisted = $this->withHeaders($this->headers())->getJson(
            '/atlas-code/workspace-intelligence?workspace=atlas&task='.urlencode('snapshot replay').'&persist=1',
        );
        $persisted->assertOk();

        $latest = $this->withHeaders($this->headers())->getJson(
            '/atlas-code/workspace-intelligence?workspace=atlas&latest=1',
        );

        $latest
            ->assertOk()
            ->assertJsonPath('runtime_hash', $persisted->json('runtime_hash'))
            ->assertJsonPath('workspace.workspace_id', 'atlas')
            ->assertJsonPath('awaf.artifact_count', 10)
            ->assertJsonPath('awair.artifact_replay.replay_ready', true);

        $latestAwair = $this->withHeaders($this->headers())->getJson(
            '/atlas-code/workspace-intelligence/artifact-intelligence?workspace=atlas&latest=1',
        );

        $latestAwair
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.workspace_artifact_intelligence.v1')
            ->assertJsonPath('artifact_intelligence_hash', $persisted->json('awair.artifact_intelligence_hash'));

        $latestTwin = $this->withHeaders($this->headers())->getJson(
            '/atlas-code/workspace-intelligence/twin?workspace=atlas&latest=1',
        );
        $latestTwin
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.workspace_twin.v1')
            ->assertJsonPath('twin_hash', $persisted->json('awtr.twin_hash'));

        $latestContracts = $this->withHeaders($this->headers())->getJson(
            '/atlas-code/workspace-intelligence/contracts?workspace=atlas&latest=1',
        );
        $latestContracts
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.workspace_contract_orchestrator.v1')
            ->assertJsonPath('contract_hash', $persisted->json('awco.contract_hash'));

        $latestEvolution = $this->withHeaders($this->headers())->getJson(
            '/atlas-code/workspace-intelligence/evolution?workspace=atlas&latest=1',
        );
        $latestEvolution
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.workspace_evolution_fabric.v1')
            ->assertJsonPath('evolution_hash', $persisted->json('awef.evolution_hash'));

        $latestLoop = $this->withHeaders($this->headers())->getJson(
            '/atlas-code/workspace-intelligence/learning-loop?workspace=atlas&latest=1',
        );
        $latestLoop
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.awis.workspace_intelligence_loop.v1')
            ->assertJsonPath('loop_hash', $persisted->json('awis_learning_loop.loop_hash'))
            ->assertJsonPath('closed_loop.loop_closed', true);

        $latestBrain = $this->withHeaders($this->headers())->getJson(
            '/atlas-code/workspace-intelligence/next-session-brain?workspace=atlas&latest=1',
        );
        $latestBrain
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.awis.workspace_next_session_brain.v1')
            ->assertJsonPath('brain_hash', $persisted->json('workspace_next_session_brain.brain_hash'))
            ->assertJsonPath('source_policy.raw_conversation_returned', false);
    }

    public function test_api_latest_blocks_stale_runtime_projection_when_workspace_hash_changes(): void
    {
        $persisted = $this->withHeaders($this->headers())->getJson(
            '/atlas-code/workspace-intelligence?workspace=atlas&task='.urlencode('snapshot stale replay').'&persist=1',
        );
        $persisted->assertOk();

        $snapshot = AtlasWorkspaceRuntimeProjectionSnapshot::query()
            ->where('runtime_hash', $persisted->json('runtime_hash'))
            ->where('family', 'AWCO')
            ->firstOrFail();
        $payload = $snapshot->payload;
        data_set($payload, 'awis_projection.workspace_hash', str_repeat('0', 64));
        $snapshot->forceFill(['payload' => $payload])->save();

        $latestContracts = $this->withHeaders($this->headers())->getJson(
            '/atlas-code/workspace-intelligence/contracts?workspace=atlas&latest=1',
        );

        $latestContracts
            ->assertStatus(409)
            ->assertJsonPath('schema_version', 'atlas.awis.runtime_projection_stale.v1')
            ->assertJsonPath('status', 'blocked')
            ->assertJsonPath('family', 'AWCO')
            ->assertJsonPath('stale', true)
            ->assertJsonPath('reason', 'workspace_hash_changed')
            ->assertJsonPath('snapshot_workspace_hash', str_repeat('0', 64))
            ->assertJsonPath('current_workspace_hash', $persisted->json('workspace.workspace_hash'))
            ->assertJsonPath('blockers.0', 'workspace_runtime_projection_stale');
    }

    public function test_api_latest_blocks_stale_full_awis_snapshot_when_workspace_hash_changes(): void
    {
        $persisted = $this->withHeaders($this->headers())->getJson(
            '/atlas-code/workspace-intelligence?workspace=atlas&task='.urlencode('full snapshot stale replay').'&persist=1',
        );
        $persisted->assertOk();

        $snapshot = AtlasWorkspaceIntelligenceSnapshot::query()
            ->where('runtime_hash', $persisted->json('runtime_hash'))
            ->firstOrFail();
        $snapshot->forceFill(['workspace_hash' => str_repeat('1', 64)])->save();

        $latest = $this->withHeaders($this->headers())->getJson(
            '/atlas-code/workspace-intelligence?workspace=atlas&latest=1',
        );

        $latest
            ->assertStatus(409)
            ->assertJsonPath('schema_version', 'atlas.awis.runtime_snapshot_stale.v1')
            ->assertJsonPath('status', 'blocked')
            ->assertJsonPath('stale', true)
            ->assertJsonPath('reason', 'workspace_hash_changed')
            ->assertJsonPath('snapshot_workspace_hash', str_repeat('1', 64))
            ->assertJsonPath('current_workspace_hash', $persisted->json('workspace.workspace_hash'))
            ->assertJsonPath('blockers.0', 'workspace_intelligence_snapshot_stale');
    }

    public function test_api_artifact_intelligence_persist_writes_lake_and_replays_from_dedicated_graph(): void
    {
        $persisted = $this->withHeaders($this->headers())->getJson(
            '/atlas-code/workspace-intelligence/artifact-intelligence?workspace=atlas&task='.urlencode('artifact graph replay').'&persist=1',
        );
        $persisted->assertOk();

        $this->assertSame(1, AtlasWorkspaceArtifactGraphSnapshot::query()->count());
        $this->assertSame(10, AtlasWorkspaceArtifactLakeEntry::query()->count());

        $latest = $this->withHeaders($this->headers())->getJson(
            '/atlas-code/workspace-intelligence/artifact-intelligence?workspace=atlas&latest=1',
        );

        $latest
            ->assertOk()
            ->assertJsonPath('artifact_intelligence_hash', $persisted->json('artifact_intelligence_hash'))
            ->assertJsonPath('artifact_lake.artifact_count', 10)
            ->assertJsonPath('artifact_graph.graph_hash', $persisted->json('artifact_graph.graph_hash'));
    }

    public function test_api_artifact_intelligence_latest_blocks_stale_dedicated_graph(): void
    {
        $persisted = $this->withHeaders($this->headers())->getJson(
            '/atlas-code/workspace-intelligence/artifact-intelligence?workspace=atlas&task='.urlencode('artifact graph stale replay').'&persist=1',
        );
        $persisted->assertOk();

        $snapshot = AtlasWorkspaceArtifactGraphSnapshot::query()
            ->where('artifact_intelligence_hash', $persisted->json('artifact_intelligence_hash'))
            ->firstOrFail();
        $payload = $snapshot->payload;
        $payload['workspace_hash'] = str_repeat('3', 64);
        $snapshot->forceFill(['payload' => $payload])->save();

        $latest = $this->withHeaders($this->headers())->getJson(
            '/atlas-code/workspace-intelligence/artifact-intelligence?workspace=atlas&latest=1',
        );

        $latest
            ->assertStatus(409)
            ->assertJsonPath('schema_version', 'atlas.awair.artifact_graph_stale.v1')
            ->assertJsonPath('status', 'blocked')
            ->assertJsonPath('family', 'AWAIR')
            ->assertJsonPath('stale', true)
            ->assertJsonPath('reason', 'workspace_hash_changed')
            ->assertJsonPath('snapshot_workspace_hash', str_repeat('3', 64))
            ->assertJsonPath('current_workspace_hash', $persisted->json('workspace_hash'))
            ->assertJsonPath('blockers.0', 'workspace_artifact_graph_stale');
    }

    public function test_persist_is_idempotent_for_same_runtime_hash(): void
    {
        Artisan::call('atlas:workspace-intelligence', [
            'action' => 'certify',
            '--workspace' => 'atlas',
            '--task' => 'mesma tarefa',
            '--json' => true,
            '--persist' => true,
        ]);
        $first = json_decode(Artisan::output(), true);

        Artisan::call('atlas:workspace-intelligence', [
            'action' => 'certify',
            '--workspace' => 'atlas',
            '--task' => 'mesma tarefa',
            '--json' => true,
            '--persist' => true,
        ]);
        $second = json_decode(Artisan::output(), true);

        $this->assertSame($first['runtime_hash'], $second['runtime_hash']);
        $this->assertDatabaseCount('atlas_workspace_intelligence_snapshots', 1);
        $this->assertDatabaseCount('atlas_workspace_artifact_graph_snapshots', 1);
        $this->assertDatabaseCount('atlas_workspace_artifact_lake_entries', 10);
        $this->assertDatabaseCount('atlas_workspace_runtime_projection_snapshots', 5);
    }

    public function test_execution_gate_allows_conversation_without_ready_workspace(): void
    {
        $gate = app(AtlasWorkspaceIntelligenceExecutionGateService::class)->gate(
            workspace: 'missing-workspace',
            mode: 'conversation',
            task: 'conversa livre',
        );

        $this->assertSame(AtlasWorkspaceIntelligenceExecutionGateService::SCHEMA_VERSION, $gate['schema_version']);
        $this->assertTrue($gate['allowed']);
        $this->assertSame('limited', $gate['status']);
        $this->assertSame('conversation', $gate['execution_class']);
        $this->assertSame(['workspace_not_ready_conversation_only'], $gate['warnings']);
        $this->assertSame([], $gate['blockers']);
    }

    public function test_execution_gate_blocks_mutative_dev_without_ready_workspace(): void
    {
        $gate = app(AtlasWorkspaceIntelligenceExecutionGateService::class)->gate(
            workspace: 'missing-workspace',
            mode: 'dev',
            task: 'corrigir bug login',
        );

        $this->assertFalse($gate['allowed']);
        $this->assertSame('blocked', $gate['status']);
        $this->assertSame('mutative', $gate['execution_class']);
        $this->assertContains('workspace_not_ready', $gate['blockers']);
        $this->assertContains('workspace_contracts_not_certified', $gate['blockers']);
        $this->assertContains('artifact_shadow_execution_blocked', $gate['blockers']);
        $this->assertSame('blocked', $gate['artifact_shadow_execution']['status']);
        $this->assertContains('artifact_replay_not_ready', $gate['artifact_shadow_execution']['blockers']);
    }

    public function test_execution_gate_allows_mutative_forge_for_ready_workspace(): void
    {
        $gate = app(AtlasWorkspaceIntelligenceExecutionGateService::class)->gate(
            workspace: 'atlas',
            mode: 'forge',
            task: 'criar ecommerce',
        );

        $this->assertTrue($gate['allowed']);
        $this->assertSame('ready', $gate['status']);
        $this->assertSame('mutative', $gate['execution_class']);
        $this->assertSame('atlas', $gate['workspace_id']);
        $this->assertSame([], $gate['blockers']);
        $this->assertTrue($gate['required_contracts']['awco_execution_readiness']);
        $this->assertTrue($gate['required_contracts']['awair_shadow_execution']);
        $this->assertTrue($gate['required_contracts']['awnsb_next_session_brain']);
        $this->assertTrue($gate['required_contracts']['awnsb_context_loading_plan']);
        $this->assertSame('atlas.workspace_intelligence.execution_context.v1', $gate['execution_context']['schema_version']);
        $this->assertSame(64, strlen((string) $gate['execution_context']['workspace_next_session_brain_hash']));
        $this->assertContains('workspace_change_memory', $gate['execution_context']['load_order']);
        $this->assertContains('workspace_focus_map', $gate['execution_context']['load_order']);
        $this->assertSame('atlas.awis.context_loading_plan.v1', $gate['execution_context']['context_loading_plan']['schema_version']);
        $this->assertSame(64, strlen((string) $gate['execution_context']['context_loading_plan']['repository_inventory_hash']));
        $this->assertFalse($gate['execution_context']['context_loading_plan']['provider_policy']['raw_manifest_returned']);
        $this->assertFalse($gate['execution_context']['context_loading_plan']['provider_policy']['script_bodies_returned']);
        $this->assertTrue($gate['execution_context']['provider_safe']);
        $this->assertFalse($gate['execution_context']['raw_content_returned']);
        $this->assertSame('atlas.workspace_artifact_shadow_execution.v1', $gate['artifact_shadow_execution']['schema_version']);
        $this->assertSame('ready', $gate['artifact_shadow_execution']['status']);
        $this->assertFalse($gate['artifact_shadow_execution']['provider_called']);
        $this->assertFalse($gate['artifact_shadow_execution']['workspace_mutated']);
        $this->assertSame(10, $gate['artifact_shadow_execution']['node_count']);
        $this->assertGreaterThanOrEqual(8, $gate['artifact_shadow_execution']['edge_count']);
        $this->assertSame(64, strlen((string) $gate['gate_hash']));
    }

    public function test_execution_gate_accepts_workspace_path_as_alias_for_profile_slug(): void
    {
        $gate = app(AtlasWorkspaceIntelligenceExecutionGateService::class)->gate(
            workspace: base_path('..'),
            mode: 'dev',
            task: 'corrigir bug login',
        );

        $this->assertTrue($gate['allowed']);
        $this->assertSame('ready', $gate['status']);
        $this->assertSame('atlas', $gate['workspace_id']);
        $this->assertTrue($gate['required_contracts']['awis_workspace_binding']);
        $this->assertTrue($gate['required_contracts']['awnsb_next_session_brain']);
        $this->assertSame(64, strlen((string) $gate['execution_context']['workspace_next_session_brain_hash']));
    }

    public function test_command_gate_blocks_mutative_mode_in_strict_mode(): void
    {
        $exit = Artisan::call('atlas:workspace-intelligence', [
            'action' => 'gate',
            '--workspace' => 'missing-workspace',
            '--mode' => 'index-code',
            '--json' => true,
            '--strict' => true,
        ]);

        $this->assertSame(1, $exit);
        $decoded = json_decode(Artisan::output(), true);
        $this->assertIsArray($decoded);
        $this->assertSame('blocked', $decoded['status']);
        $this->assertFalse($decoded['allowed']);
    }

    public function test_execution_boundary_audit_proves_known_mutative_entrypoints_are_awis_gated(): void
    {
        $report = app(AtlasWorkspaceExecutionBoundaryAuditService::class)->audit();

        $this->assertSame(AtlasWorkspaceExecutionBoundaryAuditService::SCHEMA_VERSION, $report['schema_version']);
        $this->assertSame('ready', $report['status']);
        $this->assertGreaterThanOrEqual(16, $report['summary']['total']);
        $this->assertSame($report['summary']['total'], $report['summary']['passed']);
        $this->assertSame(0, $report['summary']['failed']);
        $this->assertGreaterThanOrEqual(20, $report['summary']['process_inventory_total']);
        $this->assertSame(0, $report['summary']['process_inventory_unclassified']);
        $this->assertTrue(data_get($report, 'claim_policy.mutative_dev_forge_boundaries_require_awis'));
        $this->assertFalse(data_get($report, 'claim_policy.unclassified_workspace_process_boundaries_allowed'));
        $this->assertSame([], collect($report['boundaries'])->pluck('missing_markers')->flatten()->values()->all());
        $this->assertSame([], collect($report['process_inventory']['items'])->pluck('missing_markers')->flatten()->values()->all());
        $this->assertSame(0, $report['process_inventory']['unclassified']);
        $this->assertArrayHasKey('directly_guarded_by_awis', $report['process_inventory']['by_classification']);
        $this->assertArrayHasKey('caller_guarded_by_awis', $report['process_inventory']['by_classification']);
        $this->assertArrayHasKey('read_only_workspace_probe', $report['process_inventory']['by_classification']);
        $this->assertTrue(collect($report['process_inventory']['items'])->contains(
            fn (array $item): bool => $item['id'] === 'engineering_quality_scan'
                && $item['classification'] === 'directly_guarded_by_awis',
        ));
        $this->assertTrue(collect($report['process_inventory']['items'])->contains(
            fn (array $item): bool => $item['id'] === 'atlas_dev_ripgrep_discovery'
                && $item['classification'] === 'read_only_workspace_probe',
        ));
        $this->assertTrue(collect($report['process_inventory']['items'])->contains(
            fn (array $item): bool => $item['id'] === 'atlas_dev_provider_gateway'
                && $item['classification'] === 'caller_guarded_by_awis',
        ));
        $this->assertSame(64, strlen((string) $report['audit_hash']));
    }

    public function test_command_boundary_audit_emits_ready_static_scan(): void
    {
        $exit = Artisan::call('atlas:workspace-intelligence', [
            'action' => 'boundary-audit',
            '--json' => true,
            '--strict' => true,
        ]);

        $this->assertSame(0, $exit);
        $decoded = json_decode(Artisan::output(), true);
        $this->assertIsArray($decoded);
        $this->assertSame(AtlasWorkspaceExecutionBoundaryAuditService::SCHEMA_VERSION, $decoded['schema_version']);
        $this->assertSame('ready', $decoded['status']);
        $this->assertSame(0, $decoded['summary']['failed']);
    }

    public function test_command_registers_persisted_workspace_and_gate_resolves_it(): void
    {
        $this->createWorkspaceProfilesTable();

        $exit = Artisan::call('atlas:workspace-intelligence', [
            'action' => 'register',
            '--workspace' => 'client-y',
            '--name' => 'Client Y',
            '--path' => base_path(),
            '--test-command' => ['php artisan test'],
            '--critical-area' => ['app', 'tests'],
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $decoded = json_decode(Artisan::output(), true);
        $this->assertIsArray($decoded);
        $this->assertSame('ready', $decoded['status']);
        $this->assertSame('client-y', $decoded['workspace']['slug']);
        $this->assertTrue((bool) data_get($decoded, 'meta.execution_allowed'));

        $gate = app(AtlasWorkspaceIntelligenceExecutionGateService::class)->gate(
            workspace: 'client-y',
            mode: 'dev',
            task: 'corrigir bug login',
        );

        $this->assertTrue($gate['allowed']);
        $this->assertSame('client-y', $gate['workspace_id']);
    }

    public function test_api_persists_workspace_profile_but_does_not_auto_allow_missing_path_execution(): void
    {
        $this->createWorkspaceProfilesTable();

        $response = $this->withHeaders($this->headers())->postJson('/atlas-code/projects/workspaces', [
            'slug' => 'paper-project',
            'name' => 'Paper Project',
            'workspace_path' => '/definitely/missing/atlas/project',
            'docs_status' => 'incomplete',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('workspace.slug', 'paper-project')
            ->assertJsonPath('workspace.workspace_path_exists', false)
            ->assertJsonPath('meta.execution_allowed', false);

        $gate = app(AtlasWorkspaceIntelligenceExecutionGateService::class)->gate(
            workspace: 'paper-project',
            mode: 'forge',
            task: 'criar ecommerce',
        );

        $this->assertFalse($gate['allowed']);
        $this->assertContains('workspace_not_ready', $gate['blockers']);
    }

    public function test_api_gate_endpoint_blocks_mutative_mode_without_workspace(): void
    {
        $response = $this->withHeaders($this->headers())->getJson(
            '/atlas-code/workspace-intelligence/gate?workspace=missing-workspace&mode=patch',
        );

        $response
            ->assertStatus(422)
            ->assertJsonPath('schema_version', AtlasWorkspaceIntelligenceExecutionGateService::SCHEMA_VERSION)
            ->assertJsonPath('allowed', false)
            ->assertJsonPath('status', 'blocked');
    }

    public function test_atlas_dev_runtime_resolved_from_container_embeds_awis_execution_gate(): void
    {
        $data = app(AtlasDevRuntimeService::class)->apply([
            'payload' => [
                'surface_id' => 'atlas_desktop_ai',
                'atlas_mode' => 'programming',
                'routing_task' => 'dev',
                'workspace' => base_path('..'),
                'input_text' => 'corrigir bug login',
                'context_refs' => ['docs/engineering-knowledge-base/atlas-workspace-intelligence-system.md'],
                'expected_files' => ['app/Services/Ai/Programming/AtlasDevRuntimeService.php'],
                'suggested_tests' => ['php artisan test --filter=AtlasDevRuntimeServiceTest'],
                'acceptance_criteria' => ['runtime carries AWIS execution gate'],
            ],
        ]);

        $gate = data_get($data, 'payload.atlas_dev_runtime.workspace_execution_gate');

        $this->assertIsArray($gate);
        $this->assertSame(AtlasWorkspaceIntelligenceExecutionGateService::SCHEMA_VERSION, $gate['schema_version']);
        $this->assertSame('dev', $gate['mode']);
        $this->assertTrue($gate['allowed']);
        $this->assertSame('atlas', $gate['workspace_id']);
        $brain = data_get($data, 'payload.atlas_dev_runtime.workspace_next_session_brain');
        $this->assertIsArray($brain);
        $this->assertSame('atlas.dev_runtime.workspace_next_session_brain.v1', $brain['schema_version']);
        $this->assertSame(64, strlen((string) $brain['brain_hash']));
        $this->assertContains('workspace_change_memory', $brain['load_order']);
        $this->assertSame('atlas.awis.context_loading_plan.v1', $brain['context_loading_plan']['schema_version']);
        $this->assertSame(64, strlen((string) $brain['context_loading_plan']['repository_inventory_hash']));
        $this->assertSame(64, strlen((string) $brain['context_loading_plan']['outcome_command_memory_hash']));
        $this->assertSame(64, strlen((string) $brain['context_loading_plan']['command_performance_histogram_hash']));
        $this->assertSame(64, strlen((string) $brain['context_loading_plan']['area_performance_index_hash']));
        $this->assertSame(64, strlen((string) $brain['context_loading_plan']['stack_performance_index_hash']));
        $this->assertSame(64, strlen((string) $brain['context_loading_plan']['working_set_hash']));
        $this->assertSame(64, strlen((string) $brain['context_loading_plan']['context_delta_plan_hash']));
        $this->assertSame('atlas.awis.context_delta_plan.v1', data_get($brain, 'context_loading_plan.context_delta_plan.schema_version'));
        $this->assertFalse(data_get($brain, 'context_loading_plan.context_delta_plan.source_policy.raw_file_content_returned'));
        $this->assertFalse($brain['context_loading_plan']['provider_policy']['raw_manifest_returned']);
        $this->assertFalse($brain['context_loading_plan']['provider_policy']['script_bodies_returned']);
        $this->assertTrue($brain['provider_safe']);
        $this->assertFalse($brain['raw_content_returned']);
        $selection = data_get($data, 'payload.atlas_dev_runtime.workspace_context_selection');
        $this->assertIsArray($selection);
        $this->assertSame('atlas.dev_runtime.awis_context_selection.v1', $selection['schema_version']);
        $this->assertTrue($selection['provider_safe']);
        $this->assertSame(64, strlen((string) $selection['repository_inventory_hash']));
        $this->assertSame(64, strlen((string) $selection['workspace_working_set_hash']));
        $this->assertSame(64, strlen((string) $selection['context_delta_plan_hash']));
        $this->assertSame(64, strlen((string) $selection['outcome_command_memory_hash']));
        $this->assertSame(64, strlen((string) $selection['command_performance_histogram_hash']));
        $this->assertSame(64, strlen((string) $selection['area_performance_index_hash']));
        $this->assertSame(64, strlen((string) $selection['stack_performance_index_hash']));
        $this->assertSame(64, strlen((string) $selection['workspace_learning_snapshot_hash']));
        $this->assertSame(64, strlen((string) $selection['execution_optimization_policy_hash']));
        $this->assertSame(64, strlen((string) $selection['execution_policy_effectiveness_index_hash']));
        $this->assertSame('atlas.awis.validation_depth_decision.v1', data_get($selection, 'validation_depth_decision.schema_version'));
        $this->assertContains(data_get($selection, 'validation_depth_decision.tier'), ['instant', 'standard', 'deep']);
        $this->assertFalse(data_get($selection, 'validation_depth_decision.raw_logs_returned'));
        $this->assertContains('awis_cache:repository_inventory:'.$selection['repository_inventory_hash'], $selection['context_refs']);
        $this->assertContains('awis_cache:workspace_working_set:'.$selection['workspace_working_set_hash'], $selection['context_refs']);
        $this->assertContains('awis_cache:context_delta_plan:'.$selection['context_delta_plan_hash'], $selection['context_refs']);
        $this->assertContains('awis_cache:outcome_command_memory:'.$selection['outcome_command_memory_hash'], $selection['context_refs']);
        $this->assertContains('awis_cache:command_performance_histogram:'.$selection['command_performance_histogram_hash'], $selection['context_refs']);
        $this->assertContains('awis_cache:area_performance_index:'.$selection['area_performance_index_hash'], $selection['context_refs']);
        $this->assertContains('awis_cache:stack_performance_index:'.$selection['stack_performance_index_hash'], $selection['context_refs']);
        $this->assertContains('awis_cache:workspace_learning_snapshot:'.$selection['workspace_learning_snapshot_hash'], $selection['context_refs']);
        $this->assertContains('awis_cache:execution_optimization_policy:'.$selection['execution_optimization_policy_hash'], $selection['context_refs']);
        $this->assertContains('awis_cache:execution_policy_effectiveness:'.$selection['execution_policy_effectiveness_index_hash'], $selection['context_refs']);
        $this->assertContains('awis_validation_tier:'.data_get($selection, 'validation_depth_decision.tier'), $selection['context_refs']);
        $preview = data_get($data, 'payload.atlas_dev_runtime_intelligence');
        $this->assertContains('awis_cache:repository_inventory:'.$selection['repository_inventory_hash'], data_get($preview, 'task_packet.context_refs'));
        $this->assertContains('awis_cache:workspace_working_set:'.$selection['workspace_working_set_hash'], data_get($preview, 'task_packet.context_refs'));
        $this->assertContains('awis_cache:context_delta_plan:'.$selection['context_delta_plan_hash'], data_get($preview, 'task_packet.context_refs'));
        $this->assertContains('awis_cache:outcome_command_memory:'.$selection['outcome_command_memory_hash'], data_get($preview, 'task_packet.context_refs'));
        $this->assertContains('awis_cache:command_performance_histogram:'.$selection['command_performance_histogram_hash'], data_get($preview, 'task_packet.context_refs'));
        $this->assertContains('awis_cache:area_performance_index:'.$selection['area_performance_index_hash'], data_get($preview, 'task_packet.context_refs'));
        $this->assertContains('awis_cache:stack_performance_index:'.$selection['stack_performance_index_hash'], data_get($preview, 'task_packet.context_refs'));
        $this->assertContains('awis_cache:workspace_learning_snapshot:'.$selection['workspace_learning_snapshot_hash'], data_get($preview, 'task_packet.context_refs'));
        $this->assertContains('awis_cache:execution_optimization_policy:'.$selection['execution_optimization_policy_hash'], data_get($preview, 'task_packet.context_refs'));
        $this->assertContains('awis_cache:execution_policy_effectiveness:'.$selection['execution_policy_effectiveness_index_hash'], data_get($preview, 'task_packet.context_refs'));
        $this->assertContains('awis_validation_tier:'.data_get($selection, 'validation_depth_decision.tier'), data_get($preview, 'task_packet.context_refs'));
        foreach ($selection['suggested_tests'] as $suggestedTest) {
            $this->assertContains($suggestedTest, data_get($preview, 'task_packet.suggested_tests'));
        }
        $handoff = data_get($data, 'payload.atlas_dev_runtime.workspace_handoff_pack');
        $this->assertIsArray($handoff);
        $this->assertSame(AtlasWorkspaceHandoffPackService::SCHEMA_VERSION, $handoff['schema_version']);
        $this->assertSame('ready', $handoff['status']);
        $this->assertSame('atlas_dev', $handoff['consumer']);
        $this->assertFalse($handoff['execution_contract']['raw_conversation_included']);
        $this->assertTrue($handoff['claim_policy']['safe_for_provider_prompt']);
        $this->assertTrue(data_get($data, 'payload.atlas_dev_runtime.provider_execution_allowed'));
    }

    public function test_atlas_dev_runtime_consumes_awaol_agent_packet_as_execution_context(): void
    {
        $packet = [
            'schema_version' => 'atlas.workspace_artifact_agent_packet.v1',
            'workspace_id' => 'atlas',
            'consumer' => 'atlas_dev',
            'route_target' => 'dev',
            'artifact_type' => 'task_packet',
            'artifact_hash' => str_repeat('a', 64),
            'allowed_paths' => ['app/Services/Ai/Programming/AtlasDevRuntimeService.php'],
            'forbidden_paths' => ['outside_workspace_root', 'raw_conversation_archive'],
            'must_keep' => ['workspace_id', 'artifact_hash', 'source_hashes'],
            'context_refs' => ['docs/engineering-knowledge-base/atlas-workspace-artifact-operating-layer.md'],
            'test_plan' => ['php artisan test tests/Feature/Ai/WorkspaceIntelligence/AtlasWorkspaceIntelligenceRuntimeServiceTest.php'],
            'done_when' => ['artifact packet consumed by Atlas Dev'],
            'redaction' => 'provider_safe',
            'raw_conversation_included' => false,
            'artifact_body_included' => false,
        ];

        $data = app(AtlasDevRuntimeService::class)->apply([
            'payload' => [
                'surface_id' => 'atlas_desktop_ai',
                'atlas_mode' => 'programming',
                'routing_task' => 'dev',
                'workspace' => 'atlas',
                'input_text' => 'corrigir bug login',
                'workspace_artifact_agent_packet' => $packet,
            ],
        ]);

        $slice = data_get($data, 'payload.atlas_dev_runtime');
        $preview = data_get($data, 'payload.atlas_dev_runtime_intelligence');

        $this->assertSame('atlas.workspace_artifact_agent_packet.v1', data_get($slice, 'artifact_agent_packet.schema_version'));
        $this->assertSame('task_packet', data_get($slice, 'artifact_agent_packet.artifact_type'));
        $this->assertFalse(data_get($slice, 'artifact_agent_packet.raw_conversation_included'));
        $this->assertFalse(data_get($slice, 'artifact_agent_packet.artifact_body_included'));
        $this->assertArrayNotHasKey('artifact_agent_packet_blockers', $slice);
        $this->assertSame('AtlasDevRuntimeService:artifact_agent_packet+awis_context_loading_plan', data_get($preview, 'task_packet.source'));
        foreach ($packet['context_refs'] as $contextRef) {
            $this->assertContains($contextRef, data_get($preview, 'task_packet.context_refs'));
        }
        $selection = data_get($data, 'payload.atlas_dev_runtime.workspace_context_selection');
        $this->assertIsArray($selection);
        $this->assertSame('atlas.dev_runtime.awis_context_selection.v1', $selection['schema_version']);
        $this->assertContains('awis_cache:repository_inventory:'.$selection['repository_inventory_hash'], data_get($preview, 'task_packet.context_refs'));
        $this->assertContains('awis_cache:workspace_learning_snapshot:'.$selection['workspace_learning_snapshot_hash'], data_get($preview, 'task_packet.context_refs'));
        $this->assertContains('awis_cache:execution_optimization_policy:'.$selection['execution_optimization_policy_hash'], data_get($preview, 'task_packet.context_refs'));
        $this->assertContains('awis_cache:execution_policy_effectiveness:'.$selection['execution_policy_effectiveness_index_hash'], data_get($preview, 'task_packet.context_refs'));
        $this->assertContains('aedpds_context:owner_or_relevant_context', data_get($preview, 'task_packet.context_refs'));
        $this->assertContains('aedpds_context:acceptance_criteria', data_get($preview, 'task_packet.context_refs'));
        foreach ($packet['test_plan'] as $testCommand) {
            $this->assertContains($testCommand, data_get($preview, 'task_packet.suggested_tests'));
        }
        $this->assertContains('aedpds_test:focused_test_or_verification_command', data_get($preview, 'task_packet.suggested_tests'));
        $this->assertContains('aedpds_test:security_regression_tests', data_get($preview, 'task_packet.suggested_tests'));
        $this->assertTrue($slice['provider_safe']);
        $this->assertTrue($slice['provider_execution_allowed']);
    }

    public function test_atlas_dev_runtime_blocks_non_dev_awaol_agent_packet_route(): void
    {
        $data = app(AtlasDevRuntimeService::class)->apply([
            'payload' => [
                'surface_id' => 'atlas_desktop_ai',
                'atlas_mode' => 'programming',
                'routing_task' => 'dev',
                'workspace' => 'atlas',
                'input_text' => 'corrigir bug login',
                'workspace_artifact_agent_packet' => [
                    'schema_version' => 'atlas.workspace_artifact_agent_packet.v1',
                    'workspace_id' => 'atlas',
                    'consumer' => 'atlas_forge',
                    'route_target' => 'forge',
                    'artifact_type' => 'execution_plan',
                    'artifact_hash' => str_repeat('b', 64),
                    'raw_conversation_included' => false,
                    'artifact_body_included' => false,
                ],
            ],
        ]);

        $slice = data_get($data, 'payload.atlas_dev_runtime');

        $this->assertFalse($slice['provider_safe']);
        $this->assertFalse($slice['provider_execution_allowed']);
        $this->assertContains('artifact_agent_packet_route_not_dev', $slice['artifact_agent_packet_blockers']);
    }

    /**
     * @return array<string,string>
     */
    private function headers(): array
    {
        return [
            'Accept' => 'application/json',
            'X-Atlas-Token' => 'testing-atlas-token-with-enough-length',
        ];
    }

    private function makeGitWorkspace(string $name): string
    {
        $workspace = storage_path('framework/testing/'.$name.'-'.bin2hex(random_bytes(4)));
        @mkdir($workspace, 0777, true);
        $this->initGitWorkspace($workspace, $name);

        return $workspace;
    }

    private function initGitWorkspace(string $workspace, string $name): void
    {
        @mkdir($workspace, 0777, true);
        file_put_contents($workspace.'/README.md', "# {$name}\n");
        file_put_contents($workspace.'/composer.json', "{}\n");

        $this->runGit($workspace, ['git', 'init']);
        $this->runGit($workspace, ['git', 'config', 'user.email', 'atlas-test@example.test']);
        $this->runGit($workspace, ['git', 'config', 'user.name', 'Atlas Test']);
        $this->runGit($workspace, ['git', 'add', 'README.md', 'composer.json']);
        $this->runGit($workspace, ['git', 'commit', '-m', 'initial']);
    }

    /**
     * @param  array<int,string>  $command
     */
    private function runGit(string $cwd, array $command): void
    {
        $process = new Process($command, $cwd);
        $process->setTimeout(30);
        $process->mustRun();
    }

    private function createSnapshotTable(): void
    {
        if (Schema::hasTable('atlas_workspace_intelligence_snapshots')) {
            return;
        }

        Schema::create('atlas_workspace_intelligence_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->index();
            $table->string('workspace_id', 120)->index();
            $table->string('workspace_hash', 64)->nullable()->index();
            $table->string('runtime_hash', 64)->unique();
            $table->string('status', 40)->index();
            $table->unsignedInteger('checks_total')->default(0);
            $table->unsignedInteger('checks_passed')->default(0);
            $table->unsignedInteger('checks_failed')->default(0);
            $table->unsignedInteger('artifacts_count')->default(0);
            $table->json('family_status');
            $table->json('payload');
            $table->timestamp('captured_at')->index();
            $table->timestamps();
        });
    }

    private function createArtifactIntelligenceTables(): void
    {
        if (! Schema::hasTable('atlas_workspace_artifact_lake_entries')) {
            Schema::create('atlas_workspace_artifact_lake_entries', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('workspace_id', 120)->index();
                $table->string('runtime_hash', 64)->index();
                $table->string('artifact_hash', 64)->index();
                $table->string('artifact_type', 120)->index();
                $table->string('status', 40)->index();
                $table->string('consumer', 120)->nullable()->index();
                $table->json('source_hashes');
                $table->json('body');
                $table->decimal('quality_score', 5, 2)->default(0);
                $table->timestamp('captured_at')->index();
                $table->timestamps();

                $table->unique(['runtime_hash', 'artifact_hash']);
            });
        }

        if (Schema::hasTable('atlas_workspace_artifact_graph_snapshots')) {
            return;
        }

        Schema::create('atlas_workspace_artifact_graph_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('workspace_id', 120)->index();
            $table->string('runtime_hash', 64)->unique();
            $table->string('artifact_intelligence_hash', 64)->unique();
            $table->string('status', 40)->index();
            $table->string('lake_hash', 64)->nullable()->index();
            $table->string('graph_hash', 64)->nullable()->index();
            $table->unsignedInteger('artifact_count')->default(0);
            $table->unsignedInteger('node_count')->default(0);
            $table->unsignedInteger('edge_count')->default(0);
            $table->boolean('replay_ready')->default(false)->index();
            $table->string('simulation_decision', 40)->index();
            $table->json('nodes');
            $table->json('edges');
            $table->json('payload');
            $table->timestamp('captured_at')->index();
            $table->timestamps();
        });
    }

    private function createArtifactOperatingTables(): void
    {
        if (! Schema::hasTable('atlas_workspace_artifact_timeline_events')) {
            Schema::create('atlas_workspace_artifact_timeline_events', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('workspace_id', 120)->index();
                $table->string('artifact_hash', 64)->index();
                $table->string('artifact_type', 120)->index();
                $table->string('event_type', 80)->index();
                $table->string('event_status', 40)->index();
                $table->string('route_target', 80)->nullable()->index();
                $table->string('consumer', 120)->nullable()->index();
                $table->json('payload');
                $table->string('event_hash', 64)->unique();
                $table->timestamp('occurred_at')->index();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('atlas_workspace_artifact_retirement_proposals')) {
            return;
        }

        Schema::create('atlas_workspace_artifact_retirement_proposals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('workspace_id', 120)->index();
            $table->string('artifact_hash', 64)->index();
            $table->string('artifact_type', 120)->index();
            $table->string('reason', 200);
            $table->string('status', 40)->index();
            $table->boolean('replacement_required')->default(false)->index();
            $table->json('payload');
            $table->string('proposal_hash', 64)->unique();
            $table->timestamp('proposed_at')->index();
            $table->timestamps();
        });
    }

    private function createWorkspaceProfilesTable(): void
    {
        if (Schema::hasTable('atlas_workspace_profiles')) {
            return;
        }

        Schema::create('atlas_workspace_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('slug', 120)->unique();
            $table->string('name', 200);
            $table->string('kind', 80)->default('product');
            $table->string('workspace_path', 1000)->nullable();
            $table->string('repo_root', 1000)->nullable();
            $table->string('production_status', 80)->default('development');
            $table->text('stack_summary')->nullable();
            $table->json('commands')->nullable();
            $table->json('test_commands')->nullable();
            $table->json('build_commands')->nullable();
            $table->string('dev_server_command', 1000)->nullable();
            $table->json('critical_areas')->nullable();
            $table->string('docs_status', 80)->default('unknown');
            $table->string('default_risk', 40)->default('medium');
            $table->text('deployment_notes')->nullable();
            $table->json('surfaces_enabled')->nullable();
            $table->string('source', 80)->default('operator');
            $table->string('status', 40)->default('active');
            $table->timestamps();
        });
    }

    private function createRuntimeProjectionTable(): void
    {
        if (Schema::hasTable('atlas_workspace_runtime_projection_snapshots')) {
            return;
        }

        Schema::create('atlas_workspace_runtime_projection_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('workspace_id', 120)->index();
            $table->string('family', 20)->index();
            $table->string('schema_version', 120)->index();
            $table->string('runtime_hash', 64)->index();
            $table->string('projection_hash', 64)->index();
            $table->string('status', 40)->index();
            $table->json('payload');
            $table->timestamp('captured_at')->index();
            $table->timestamps();

            $table->unique(['runtime_hash', 'family']);
        });
    }

    private function createDevRuntimeOutcomeTables(): void
    {
        if (! Schema::hasTable('atlas_dev_task_packets')) {
            Schema::create('atlas_dev_task_packets', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.dev.task_packet.v1');
                $table->string('uuid', 64)->unique();
                $table->string('run_id', 120)->index();
                $table->string('task_id', 120)->index();
                $table->text('objective');
                $table->string('task_class', 80)->index();
                $table->string('risk_band', 40)->index();
                $table->string('workspace_slug', 160)->nullable()->index();
                $table->json('allowed_files');
                $table->json('forbidden_files');
                $table->json('context_refs');
                $table->json('expected_files');
                $table->json('suggested_tests');
                $table->json('acceptance_criteria');
                $table->json('required_evidence');
                $table->string('source', 120)->nullable()->index();
                $table->string('task_packet_hash', 64)->unique();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('atlas_dev_outcome_memories')) {
            return;
        }

        Schema::create('atlas_dev_outcome_memories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.dev.outcome_memory.v1');
            $table->string('uuid', 64)->unique();
            $table->string('run_id', 120)->index();
            $table->string('task_id', 120)->index();
            $table->uuid('task_packet_id')->nullable()->index();
            $table->uuid('failure_capsule_id')->nullable()->index();
            $table->string('outcome_status', 40)->index();
            $table->json('evidence_kinds');
            $table->json('selected_tests');
            $table->json('changed_files');
            $table->json('learning_candidates');
            $table->boolean('should_promote_to_aemor')->default(true)->index();
            $table->boolean('human_review_required')->default(false)->index();
            $table->string('outcome_memory_hash', 64)->unique();
            $table->timestamps();
        });
    }

    private function createEngineeringRuntimeTables(): void
    {
        if (! Schema::hasTable('atlas_engineering_runs')) {
            Schema::create('atlas_engineering_runs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('task_id')->index();
                $table->uuid('project_id')->nullable()->index();
                $table->uuid('project_step_id')->nullable()->index();
                $table->uuid('blueprint_snapshot_id')->nullable()->index();
                $table->string('blueprint_id', 120)->nullable()->index();
                $table->uuid('trace_id')->nullable()->index();
                $table->uuid('context_pack_id')->nullable()->index();
                $table->string('workspace_path_hash', 64);
                $table->string('workspace_label', 180);
                $table->json('provider_strategy_json')->default('{}');
                $table->string('context_pack_hash', 64)->nullable()->index();
                $table->unsignedSmallInteger('harnessability_score')->nullable();
                $table->string('status', 32)->default('queued')->index();
                $table->string('decision', 32)->nullable()->index();
                $table->unsignedSmallInteger('score')->nullable();
                $table->unsignedSmallInteger('max_attempts')->default(1);
                $table->unsignedSmallInteger('attempt_count')->default(0);
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->json('metadata')->default('{}');
                $table->timestamps();
            });
        }

        if (Schema::hasTable('atlas_engineering_test_runs')) {
            return;
        }

        Schema::create('atlas_engineering_test_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('engineering_run_id')->index();
            $table->uuid('attempt_id')->nullable()->index();
            $table->uuid('test_case_id')->nullable()->index();
            $table->string('command', 500)->nullable();
            $table->integer('exit_code')->nullable();
            $table->string('status', 32)->index();
            $table->integer('duration_ms')->default(0);
            $table->text('stdout_excerpt')->nullable();
            $table->text('stderr_excerpt')->nullable();
            $table->text('artifact_path')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });
    }
}
