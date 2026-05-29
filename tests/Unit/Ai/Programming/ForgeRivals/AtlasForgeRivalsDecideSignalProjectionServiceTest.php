<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\ForgeRivals;

use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsDecideSignalProjectionService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsProviderPerformanceLedgerService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsRunPathResolver;
use Tests\TestCase;

/**
 * Focused contract tests for AtlasForgeRivalsDecideSignalProjectionService.
 *
 * The ledger integration suite covers recording; this file pins the advisory
 * decide-signal envelope and decision rules on the projection service itself.
 */
final class AtlasForgeRivalsDecideSignalProjectionServiceTest extends TestCase
{
    private string $tmpRunsRoot;

    private string $tmpLedgerRoot;

    private AtlasForgeRivalsRunPathResolver $paths;

    private AtlasForgeRivalsProviderPerformanceLedgerService $ledger;

    private AtlasForgeRivalsDecideSignalProjectionService $projection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRunsRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fr-signal-runs-'.bin2hex(random_bytes(6));
        $this->tmpLedgerRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fr-signal-ledger-'.bin2hex(random_bytes(6));
        @mkdir($this->tmpRunsRoot, 0o755, true);
        @mkdir($this->tmpLedgerRoot, 0o755, true);

        config([
            'atlas_rivals.runs_root' => $this->tmpRunsRoot,
            'atlas_rivals.ledger_root' => $this->tmpLedgerRoot,
        ]);

        $this->paths = new AtlasForgeRivalsRunPathResolver;
        $this->ledger = new AtlasForgeRivalsProviderPerformanceLedgerService($this->paths);
        $this->projection = new AtlasForgeRivalsDecideSignalProjectionService($this->ledger);
    }

    protected function tearDown(): void
    {
        $this->purge($this->tmpRunsRoot);
        $this->purge($this->tmpLedgerRoot);
        parent::tearDown();
    }

    public function test_project_emits_canonical_schema_and_advisory_contract_on_empty_input(): void
    {
        $signal = $this->projection->project([]);

        $this->assertSame('ok', $signal['status']);
        $this->assertSame(AtlasForgeRivalsDecideSignalProjectionService::SCHEMA_VERSION, $signal['schema_version']);
        $this->assertSame(AtlasForgeRivalsDecideSignalProjectionService::SIGNAL_INSUFFICIENT, $signal['signal']);
        $this->assertContains('task_category_and_role_required', $signal['reason']);
        $this->assertTrue($signal['advisory_only']);
        $this->assertFalse($signal['should_update_provider_topology']);
        $this->assertTrue($signal['never_changes_atlas_decide_topology']);
        $this->assertSame('atlas_decide', $signal['owner_of_model_routing']);
        $this->assertSame('none', $signal['routing_effect']);
        $this->assertTrue($signal['separated_from_external_rivals_certification']);
        $this->assertFalse($signal['external_provider_call']);
        $this->assertFalse($signal['provider_tokens_spent']);
    }

    public function test_project_emits_human_review_when_invalid_entries_exist_without_valid_evidence(): void
    {
        $runId = $this->seedRun('hard-fail-signal', hardFail: true);
        $this->ledger->record([
            'run_id' => $runId,
            'task_category' => 'frontend',
            'role' => 'builder',
        ]);

        $signal = $this->projection->project([
            'task_category' => 'frontend',
            'role' => 'builder',
        ]);

        $this->assertSame(0, $signal['evidence_count']);
        $this->assertGreaterThan(0, $signal['invalid_entries_seen']);
        $this->assertTrue($signal['should_require_human_review']);
        $this->assertSame(
            AtlasForgeRivalsDecideSignalProjectionService::SIGNAL_HUMAN_REVIEW,
            $signal['signal'],
        );
    }

    public function test_project_sets_should_use_full_power_when_fair_vs_full_power_delta_meets_threshold(): void
    {
        $fairRun = $this->seedRun(
            'fair-mode',
            winner: 'atlas',
            mode: 'fair',
            atlasScoreOverride: 80.0,
            rivalScoreOverride: 60.0,
        );
        $fullRun = $this->seedRun(
            'full-mode',
            winner: 'atlas',
            mode: 'full_power',
            atlasScoreOverride: 88.0,
            rivalScoreOverride: 60.0,
        );

        foreach ([$fairRun, $fullRun] as $runId) {
            $this->ledger->record([
                'run_id' => $runId,
                'task_category' => 'frontend',
                'role' => 'builder',
            ]);
        }

        $signal = $this->projection->project([
            'task_category' => 'frontend',
            'role' => 'builder',
        ]);

        $this->assertSame('ok', $signal['signal']);
        $this->assertTrue($signal['should_use_full_power']);
        $this->assertContains(
            'full_power_delta_meets_threshold_'.AtlasForgeRivalsDecideSignalProjectionService::FULL_POWER_WORTH_IT_DELTA,
            $signal['reason'],
        );
        $this->assertFalse($signal['should_update_provider_topology']);
        $this->assertSame('none', $signal['routing_effect']);
    }

    public function test_project_keeps_should_use_full_power_false_when_delta_is_below_threshold(): void
    {
        $fairRun = $this->seedRun(
            'fair-narrow',
            winner: 'atlas',
            mode: 'fair',
            atlasScoreOverride: 80.0,
            rivalScoreOverride: 60.0,
        );
        $fullRun = $this->seedRun(
            'full-narrow',
            winner: 'atlas',
            mode: 'full_power',
            atlasScoreOverride: 81.0,
            rivalScoreOverride: 60.0,
        );

        foreach ([$fairRun, $fullRun] as $runId) {
            $this->ledger->record([
                'run_id' => $runId,
                'task_category' => 'frontend',
                'role' => 'builder',
            ]);
        }

        $signal = $this->projection->project([
            'task_category' => 'frontend',
            'role' => 'builder',
        ]);

        $this->assertFalse($signal['should_use_full_power']);
        $this->assertContains(
            'full_power_not_worth_cost_below_threshold_'.AtlasForgeRivalsDecideSignalProjectionService::FULL_POWER_WORTH_IT_DELTA,
            $signal['reason'],
        );
    }

    public function test_map_emits_segmented_model_intelligence_without_routing_effect(): void
    {
        $backendRun = $this->seedRun(
            'backend-l5',
            winner: 'atlas',
            atlasModel: 'claude_opus',
            rivalModel: 'gpt-5.5',
            atlasScoreOverride: 92.0,
            rivalScoreOverride: 78.0,
            taskCategory: 'backend',
            difficultyLevel: 'L5',
        );
        $frontendRun = $this->seedRun(
            'frontend-l2',
            winner: 'rival',
            atlasModel: 'claude_sonnet',
            rivalModel: 'codex',
            atlasScoreOverride: 70.0,
            rivalScoreOverride: 89.0,
            taskCategory: 'frontend',
            difficultyLevel: 'L2',
        );

        foreach ([
            $backendRun => 'backend',
            $frontendRun => 'frontend',
        ] as $runId => $taskCategory) {
            $this->ledger->record([
                'run_id' => $runId,
                'task_category' => $taskCategory,
                'role' => 'builder',
            ]);
        }

        $map = $this->projection->map([]);

        $this->assertSame('ok', $map['status']);
        $this->assertSame('atlas.forge.rivals.decide_model_intelligence_map.v1', $map['schema_version']);
        $this->assertSame(AtlasForgeRivalsDecideSignalProjectionService::SIGNAL_OK, $map['signal']);
        $this->assertSame(2, $map['segment_count']);
        $this->assertTrue($map['advisory_only']);
        $this->assertFalse($map['should_update_provider_topology']);
        $this->assertTrue($map['never_changes_atlas_decide_topology']);
        $this->assertSame('atlas_decide', $map['owner_of_model_routing']);
        $this->assertSame('none', $map['routing_effect']);
        $this->assertFalse($map['external_provider_call']);
        $this->assertFalse($map['provider_tokens_spent']);
        $this->assertSame('Rivals emits measured evidence; Atlas Decide decides model routing.', $map['canonical_phrase']);
        $this->assertSame('blocked_until_reproducible_evidence_complete', $map['external_claim_readiness']['status']);
        $this->assertSame('needs_repetition', $map['statistical_repeat_measurement_plan']['status']);
        $this->assertFalse($map['score_or_claim_allowed']);
        $this->assertFalse($map['external_claim_allowed']);
        $this->assertSame(2, $map['atlas_decide_segment_advisory_count']);
        $this->assertSame(2, $map['model_profile_count']);

        $learningPacket = $map['atlas_decide_learning_packet'];
        $this->assertSame('atlas.forge.rivals.atlas_decide_learning_packet.v1', $learningPacket['schema_version']);
        $this->assertSame('exploration_only', $learningPacket['status']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $learningPacket['learning_packet_hash']);
        $this->assertSame(4, $learningPacket['evidence_provenance']['filtered_ledger_entries']);
        $this->assertFalse($learningPacket['evidence_provenance']['external_provider_call']);
        $this->assertSame('none', $learningPacket['evidence_provenance']['routing_effect']);
        $this->assertSame(2, $learningPacket['total_segments']);
        $this->assertSame(0, $learningPacket['learnable_segment_count']);
        $this->assertSame(2, $learningPacket['shadow_only_segment_count']);
        $this->assertSame(2, $learningPacket['statistical_repeat_target_count']);
        $this->assertStringContainsString('no model should be preferred', $learningPacket['operator_summary']);
        $this->assertSame('advisory_shadow_signal_only', $learningPacket['allowed_learning_effect']);
        $this->assertContains('provider_topology_update', $learningPacket['forbidden_learning_effects']);
        $this->assertSame([], $learningPacket['preference_candidates']);
        $this->assertSame(0, $learningPacket['preference_candidate_count']);
        $this->assertContains('statistical_repeat_not_ready', $learningPacket['blocked_preference_reasons']);
        $this->assertContains('external_claim_readiness_blocked', $learningPacket['blocked_preference_reasons']);
        $this->assertContains('atlas_decide_policy_review_required', $learningPacket['blocked_preference_reasons']);
        $this->assertContains('only_shadow_candidates_available', $learningPacket['blocked_preference_reasons']);
        $this->assertSame('atlas.forge.rivals.statistical_repeat_gap_summary.v1', $learningPacket['statistical_repeat_gap_summary']['schema_version']);
        $this->assertSame('needs_repetition', $learningPacket['statistical_repeat_gap_summary']['status']);
        $this->assertSame(4, $learningPacket['statistical_repeat_gap_summary']['bucket_count']);
        $this->assertSame(0, $learningPacket['statistical_repeat_gap_summary']['ready_bucket_count']);
        $this->assertSame(4, $learningPacket['statistical_repeat_gap_summary']['not_ready_bucket_count']);
        $this->assertTrue($learningPacket['statistical_repeat_gap_summary']['global_repeat_gap_exists']);
        $this->assertSame(2, $learningPacket['statistical_repeat_gap_summary']['segment_repeat_target_count']);
        $this->assertSame('atlas.forge.rivals.atlas_decide_learning_eligibility.v1', $learningPacket['atlas_decide_learning_eligibility']['schema_version']);
        $this->assertSame('ok', $learningPacket['atlas_decide_learning_eligibility']['status']);
        $this->assertSame(4, $learningPacket['atlas_decide_learning_eligibility']['eligible_entry_count']);
        $this->assertSame(0, $learningPacket['atlas_decide_learning_eligibility']['ineligible_entry_count']);
        $this->assertSame('atlas.forge.rivals.atlas_decide_consumption_summary.v1', $learningPacket['atlas_decide_consumption_summary']['schema_version']);
        $this->assertSame('no_preference_available', $learningPacket['atlas_decide_consumption_summary']['decision']);
        $this->assertSame('advisory_shadow_signal_only', $learningPacket['atlas_decide_consumption_summary']['allowed_learning_effect']);
        $this->assertSame(0, $learningPacket['atlas_decide_consumption_summary']['review_candidate_count']);
        $this->assertSame(2, $learningPacket['atlas_decide_consumption_summary']['shadow_candidate_segment_count']);
        $this->assertSame(2, $learningPacket['atlas_decide_consumption_summary']['repeat_target_count']);
        $this->assertSame(4, $learningPacket['atlas_decide_consumption_summary']['statistical_repeat_not_ready_bucket_count']);
        $this->assertTrue($learningPacket['atlas_decide_consumption_summary']['global_repeat_gap_exists']);
        $this->assertSame('run_statistical_repeat_for_shadow_segments', $learningPacket['atlas_decide_consumption_summary']['next_action']);
        $this->assertFalse($learningPacket['atlas_decide_consumption_summary']['should_update_provider_topology']);
        $this->assertSame('none', $learningPacket['atlas_decide_consumption_summary']['routing_effect']);
        $this->assertSame('atlas.forge.rivals.dimensional_signal_quality.v1', $learningPacket['dimensional_signal_quality']['schema_version']);
        $this->assertSame('ok', $learningPacket['dimensional_signal_quality']['status']);
        $this->assertTrue($learningPacket['dimensional_signal_quality']['complete_for_policy_review']);
        $this->assertSame(0, $learningPacket['dimensional_signal_quality']['missing_difficulty_segment_count']);
        $this->assertSame('atlas.forge.rivals.dimensional_signal_repair_plan.v1', $learningPacket['dimensional_signal_repair_plan']['schema_version']);
        $this->assertSame('no_repair_needed', $learningPacket['dimensional_signal_repair_plan']['status']);
        $this->assertFalse($learningPacket['dimensional_signal_repair_plan']['repair_required']);
        $this->assertFalse($learningPacket['dimensional_signal_repair_plan']['automatic_mutation_allowed']);
        $this->assertSame('none', $learningPacket['dimensional_signal_repair_plan']['routing_effect']);
        $this->assertContains('complete_segment_dimensions', $learningPacket['do_not_prefer_until']['required_before_preference']);
        $this->assertNotEmpty($learningPacket['shadow_policy_candidates']);
        $shadowCandidate = $this->modelProfileByKey($learningPacket['shadow_policy_candidates'], 'anthropic_claude', 'claude_opus');
        $this->assertSame('shadow_observation_only', $shadowCandidate['allowed_learning_effect']);
        $this->assertFalse($shadowCandidate['should_update_provider_topology']);
        $this->assertSame('none', $shadowCandidate['routing_effect']);
        $this->assertFalse($learningPacket['do_not_prefer_until']['statistical_repeat_ready']);
        $this->assertContains('statistical_repeat_complete', $learningPacket['do_not_prefer_until']['required_before_preference']);
        $this->assertNotEmpty($learningPacket['next_segments_to_repeat_preview']);
        $this->assertCount(2, $learningPacket['model_fit_matrix']);
        $backendFit = $this->segmentByKey($learningPacket['model_fit_matrix'], 'backend|L5|builder');
        $this->assertSame('anthropic_claude', $backendFit['candidate_provider']);
        $this->assertSame('claude_opus', $backendFit['candidate_model']);
        $this->assertSame('shadow_or_repeat_before_preference', $backendFit['fit_status']);
        $this->assertSame('none', $backendFit['routing_effect']);
        $this->assertNotEmpty($learningPacket['model_usage_playbook']);
        $claudePlaybook = $this->modelProfileByKey($learningPacket['model_usage_playbook'], 'anthropic_claude', 'claude_opus');
        $this->assertSame('shadow_observation_only', $claudePlaybook['usage_status']);
        $this->assertSame('observe_and_collect_repeats_only', $claudePlaybook['atlas_decide_allowed_action']);
        $this->assertSame([], $claudePlaybook['use_when']);
        $this->assertSame('backend|L5|builder', $claudePlaybook['shadow_when'][0]['segment_key']);
        $this->assertSame('backend|L5|builder', $claudePlaybook['do_not_prefer_when'][0]['segment_key']);
        $this->assertContains('statistical_repeat_required', $claudePlaybook['evidence_gaps']);
        $this->assertStringContainsString('external-learning-gap --provider=claude', $claudePlaybook['next_evidence_commands_preview'][0]['learning_gap_command']);
        $this->assertStringContainsString('--arm-a=claude_code', $claudePlaybook['next_evidence_commands_preview'][0]['arena_dry_run_command']);
        $this->assertStringContainsString('--arm-b=codex_cli', $claudePlaybook['next_evidence_commands_preview'][0]['arena_dry_run_command']);
        $this->assertStringContainsString('--role=builder', $claudePlaybook['next_evidence_commands_preview'][0]['statistical_repeat_command']);
        $this->assertStringContainsString('--dry-run --json', $claudePlaybook['next_evidence_commands_preview'][0]['statistical_repeat_command']);
        $this->assertFalse($claudePlaybook['next_evidence_commands_preview'][0]['external_provider_call']);
        $this->assertFalse($claudePlaybook['next_evidence_commands_preview'][0]['provider_tokens_spent']);
        $this->assertSame('none', $claudePlaybook['next_evidence_commands_preview'][0]['routing_effect']);
        $this->assertFalse($claudePlaybook['score_or_claim_allowed']);
        $this->assertFalse($claudePlaybook['should_update_provider_topology']);
        $this->assertSame('none', $claudePlaybook['routing_effect']);
        $this->assertCount(2, $learningPacket['category_fit_summary']);
        $backendSummary = $this->categorySummaryByName($learningPacket['category_fit_summary'], 'backend');
        $this->assertSame('anthropic_claude', $backendSummary['dominant_candidate']['provider']);
        $this->assertSame('claude_opus', $backendSummary['dominant_candidate']['model']);
        $this->assertSame(['shadow_or_repeat_before_preference'], $backendSummary['dominant_candidate']['fit_statuses']);
        $this->assertSame('none', $backendSummary['routing_effect']);
        $this->assertSame('atlas.forge.rivals.atlas_decide_evidence_collection_plan.v1', $learningPacket['evidence_collection_plan']['schema_version']);
        $this->assertSame('needs_more_reproducible_evidence', $learningPacket['evidence_collection_plan']['status']);
        $this->assertSame(2, $learningPacket['evidence_collection_plan']['repeat_target_count']);
        $this->assertTrue($learningPacket['evidence_collection_plan']['dry_run_first']);
        $this->assertFalse($learningPacket['evidence_collection_plan']['external_provider_call']);
        $this->assertFalse($learningPacket['evidence_collection_plan']['provider_tokens_spent']);
        $this->assertStringContainsString('external-learning-gap --provider=claude', $learningPacket['evidence_collection_plan']['learning_gap_commands_preview'][0]['command']);
        $this->assertStringContainsString('--task-category=backend', $learningPacket['evidence_collection_plan']['learning_gap_commands_preview'][0]['command']);
        $this->assertStringContainsString('--difficulty=L5', $learningPacket['evidence_collection_plan']['learning_gap_commands_preview'][0]['command']);
        $this->assertStringContainsString('external-execution-plan --provider=claude', $learningPacket['evidence_collection_plan']['external_execution_plan_commands_preview'][0]['command']);
        $this->assertStringContainsString('--model=claude_opus', $learningPacket['evidence_collection_plan']['external_execution_plan_commands_preview'][0]['command']);
        $this->assertStringContainsString('--output-path=storage/app/atlas-rivals/external-runbooks/', $learningPacket['evidence_collection_plan']['external_execution_plan_commands_preview'][0]['command']);
        $this->assertSame(
            'storage/app/atlas-rivals/external-runbooks/backend-L5-builder-claude-claude_opus.json',
            $learningPacket['evidence_collection_plan']['external_execution_plan_commands_preview'][0]['runbook_manifest_path'],
        );
        $this->assertStringContainsString(
            '--output-path=storage/app/atlas-rivals/external-runbooks/backend-L5-builder-claude-claude_opus.json',
            $learningPacket['evidence_collection_plan']['external_execution_plan_commands_preview'][0]['command'],
        );
        $this->assertSame('atlas.forge.rivals.external_execution_runbook_manifest.v1', $learningPacket['evidence_collection_plan']['external_execution_plan_commands_preview'][0]['manifest_schema_version']);
        $this->assertTrue($learningPacket['evidence_collection_plan']['external_execution_plan_commands_preview'][0]['writes_plan_manifest_only']);
        $this->assertFalse($learningPacket['evidence_collection_plan']['external_execution_plan_commands_preview'][0]['external_provider_call']);
        $this->assertFalse($learningPacket['evidence_collection_plan']['external_execution_plan_commands_preview'][0]['provider_tokens_spent']);
        $this->assertSame('none', $learningPacket['evidence_collection_plan']['external_execution_plan_commands_preview'][0]['routing_effect']);
        $this->assertStringContainsString('--arm-a=claude_code', $learningPacket['evidence_collection_plan']['arena_repeat_commands_preview'][0]['command']);
        $this->assertStringContainsString('--arm-a-model=claude_opus', $learningPacket['evidence_collection_plan']['arena_repeat_commands_preview'][0]['command']);
        $this->assertStringContainsString('--arm-b=codex_cli', $learningPacket['evidence_collection_plan']['arena_repeat_commands_preview'][0]['command']);
        $this->assertStringContainsString('--arm-b-model=gpt-5.5', $learningPacket['evidence_collection_plan']['arena_repeat_commands_preview'][0]['command']);
        $this->assertStringContainsString('--dry-run --json', $learningPacket['evidence_collection_plan']['commands_preview'][0]['command']);
        $this->assertContains('1_check_external_learning_gap', $learningPacket['evidence_collection_plan']['operator_sequence']);
        $this->assertContains('2_export_external_execution_runbook_manifest', $learningPacket['evidence_collection_plan']['operator_sequence']);
        $this->assertFalse($learningPacket['score_or_claim_allowed']);
        $this->assertFalse($learningPacket['should_update_provider_topology']);
        $this->assertSame('none', $learningPacket['routing_effect']);

        $backend = $this->segmentByKey($map['segments'], 'backend|L5|builder');
        $this->assertSame('anthropic_claude', $backend['top_measured_provider']);
        $this->assertSame('claude_opus', $backend['top_measured_model']);
        $this->assertSame(92.0, $backend['top_average_score']);
        $this->assertSame('material_advantage', $backend['advantage_band']);
        $this->assertSame('gpt-5.5', $backend['runner_up']['model']);
        $this->assertFalse($backend['should_update_provider_topology']);
        $this->assertSame('none', $backend['routing_effect']);

        $backendAdvisory = $this->segmentByKey($map['atlas_decide_segment_advisory'], 'backend|L5|builder');
        $this->assertSame('anthropic_claude', $backendAdvisory['candidate_provider']);
        $this->assertSame('claude_opus', $backendAdvisory['candidate_model']);
        $this->assertSame('atlas_decide_should_explore_or_shadow_before_preference', $backendAdvisory['policy_hint']);
        $this->assertSame('run_statistical_repeat_for_segment', $backendAdvisory['next_action']);
        $this->assertSame('atlas.forge.rivals.segment_supporting_evidence.v1', $backendAdvisory['supporting_evidence']['schema_version']);
        $this->assertSame(1, $backendAdvisory['supporting_evidence']['valid_evidence_count']);
        $this->assertSame(92.0, $backendAdvisory['supporting_evidence']['candidate_average_score']);
        $this->assertSame(78.0, $backendAdvisory['supporting_evidence']['runner_up_average_score']);
        $this->assertSame(14.0, $backendAdvisory['supporting_evidence']['gap_vs_runner_up']);
        $this->assertSame('material_advantage', $backendAdvisory['supporting_evidence']['advantage_band']);
        $this->assertContains($backendRun, $backendAdvisory['supporting_evidence']['latest_run_ids']);
        $this->assertContains('statistical_repeat_complete', $backendAdvisory['supporting_evidence']['evidence_required_before_preference']);
        $this->assertFalse($backendAdvisory['supporting_evidence']['score_or_claim_allowed']);
        $this->assertFalse($backendAdvisory['supporting_evidence']['should_update_provider_topology']);
        $this->assertSame('none', $backendAdvisory['supporting_evidence']['routing_effect']);
        $this->assertFalse($backendAdvisory['score_or_claim_allowed']);
        $this->assertFalse($backendAdvisory['should_update_provider_topology']);
        $this->assertSame('none', $backendAdvisory['routing_effect']);

        $frontend = $this->segmentByKey($map['segments'], 'frontend|L2|builder');
        $this->assertSame('openai_codex', $frontend['top_measured_provider']);
        $this->assertSame('codex', $frontend['top_measured_model']);
        $this->assertSame(89.0, $frontend['top_average_score']);
        $this->assertSame('material_advantage', $frontend['advantage_band']);
        $this->assertSame('claude_sonnet', $frontend['runner_up']['model']);

        $claudeProfile = $this->modelProfileByKey($map['model_profiles'], 'anthropic_claude', 'claude_opus');
        $this->assertSame('exploration_only_insufficient_for_preference', $claudeProfile['decision_posture']);
        $this->assertSame('atlas_decide_should_not_prefer_from_this_signal_yet', $claudeProfile['atlas_decide_policy_hint']);
        $this->assertSame(['backend'], $claudeProfile['categories']);
        $this->assertSame(['L5'], $claudeProfile['difficulties']);
        $this->assertSame('backend|L5|builder', $claudeProfile['caution_segments'][0]['segment_key']);
        $this->assertFalse($claudeProfile['should_update_provider_topology']);
        $this->assertSame('none', $claudeProfile['routing_effect']);

        $codexProfile = $this->modelProfileByKey($map['model_profiles'], 'openai_codex', 'codex');
        $this->assertSame(['frontend'], $codexProfile['categories']);
        $this->assertSame('frontend|L2|builder', $codexProfile['caution_segments'][0]['segment_key']);

        $learningOnly = $this->projection->learningPacket([]);
        $this->assertSame('ok', $learningOnly['status']);
        $this->assertSame('atlas.forge.rivals.atlas_decide_learning_packet.v1', $learningOnly['schema_version']);
        $this->assertSame('exploration_only', $learningOnly['learning_status']);
        $this->assertSame($learningPacket['learning_packet_hash'], $learningOnly['learning_packet_hash']);
        $this->assertSame($learningPacket['evidence_provenance']['filtered_ledger_entries'], $learningOnly['evidence_provenance']['filtered_ledger_entries']);
        $this->assertSame(2, $learningOnly['segment_count']);
        $this->assertSame(2, $learningOnly['statistical_repeat_target_count']);
        $this->assertSame('php artisan atlas:forge:rivals decide-learning --json', $learningOnly['next_command']);
        $this->assertFalse($learningOnly['external_provider_call']);
        $this->assertFalse($learningOnly['provider_tokens_spent']);
        $this->assertFalse($learningOnly['score_or_claim_allowed']);
        $this->assertFalse($learningOnly['should_update_provider_topology']);
        $this->assertSame('none', $learningOnly['routing_effect']);
    }

    public function test_map_promotes_reproducible_directional_segment_to_policy_review_candidate_only(): void
    {
        $runIds = [];
        for ($i = 1; $i <= 3; $i++) {
            $runIds[] = $this->seedRun(
                'backend-repeat-'.$i,
                winner: 'atlas',
                atlasModel: 'claude_opus',
                rivalModel: 'gpt-5.5',
                atlasScoreOverride: 92.0,
                rivalScoreOverride: 78.0,
                taskCategory: 'backend',
                difficultyLevel: 'L5',
                runFamily: 'backend-l5-reproducible-family',
            );
        }

        foreach ($runIds as $runId) {
            $this->ledger->record([
                'run_id' => $runId,
                'task_category' => 'backend',
                'role' => 'builder',
            ]);
        }

        $map = $this->projection->map([]);
        $learningPacket = $map['atlas_decide_learning_packet'];

        $this->assertSame('ready_for_human_certification_external_claim_still_blocked', $map['external_claim_readiness']['status']);
        $this->assertSame('complete', $map['statistical_repeat_measurement_plan']['status']);
        $this->assertSame('has_directional_learning_candidates', $learningPacket['status']);
        $this->assertSame('advisory_policy_review_candidate_only', $learningPacket['allowed_learning_effect']);
        $this->assertSame(1, $learningPacket['learnable_segment_count']);
        $this->assertSame(0, $learningPacket['shadow_only_segment_count']);
        $this->assertSame(0, $learningPacket['statistical_repeat_target_count']);
        $this->assertSame(1, $learningPacket['preference_candidate_count']);
        $this->assertSame(['atlas_decide_policy_review_required'], $learningPacket['blocked_preference_reasons']);
        $this->assertSame('ok', $learningPacket['dimensional_signal_quality']['status']);
        $this->assertTrue($learningPacket['dimensional_signal_quality']['complete_for_policy_review']);
        $this->assertSame('no_repair_needed', $learningPacket['dimensional_signal_repair_plan']['status']);
        $this->assertFalse($learningPacket['dimensional_signal_repair_plan']['repair_required']);
        $this->assertSame('complete', $learningPacket['statistical_repeat_gap_summary']['status']);
        $this->assertSame(2, $learningPacket['statistical_repeat_gap_summary']['bucket_count']);
        $this->assertSame(2, $learningPacket['statistical_repeat_gap_summary']['ready_bucket_count']);
        $this->assertSame(0, $learningPacket['statistical_repeat_gap_summary']['not_ready_bucket_count']);
        $this->assertFalse($learningPacket['statistical_repeat_gap_summary']['global_repeat_gap_exists']);
        $this->assertSame(0, $learningPacket['statistical_repeat_gap_summary']['segment_repeat_target_count']);
        $this->assertSame('policy_review_candidates_available', $learningPacket['atlas_decide_consumption_summary']['decision']);
        $this->assertSame('advisory_policy_review_candidate_only', $learningPacket['atlas_decide_consumption_summary']['allowed_learning_effect']);
        $this->assertSame(1, $learningPacket['atlas_decide_consumption_summary']['review_candidate_count']);
        $this->assertSame(0, $learningPacket['atlas_decide_consumption_summary']['shadow_candidate_segment_count']);
        $this->assertSame(0, $learningPacket['atlas_decide_consumption_summary']['repeat_target_count']);
        $this->assertSame(0, $learningPacket['atlas_decide_consumption_summary']['statistical_repeat_not_ready_bucket_count']);
        $this->assertFalse($learningPacket['atlas_decide_consumption_summary']['global_repeat_gap_exists']);
        $this->assertSame('atlas_decide_policy_review_can_evaluate_candidates_without_topology_mutation', $learningPacket['atlas_decide_consumption_summary']['next_action']);
        $this->assertFalse($learningPacket['atlas_decide_consumption_summary']['score_or_claim_allowed']);
        $this->assertFalse($learningPacket['atlas_decide_consumption_summary']['should_update_provider_topology']);
        $this->assertSame('none', $learningPacket['atlas_decide_consumption_summary']['routing_effect']);

        $candidate = $this->modelProfileByKey($learningPacket['preference_candidates'], 'anthropic_claude', 'claude_opus');
        $this->assertSame('advisory_policy_review_candidate_only', $candidate['allowed_learning_effect']);
        $this->assertSame('atlas_decide_policy_review_required_before_any_preference', $candidate['policy_gate']);
        $this->assertSame('backend|L5|builder', $candidate['segments'][0]['segment_key']);
        $this->assertSame('strong_directional_signal', $candidate['segments'][0]['decision_readiness']);
        $this->assertFalse($candidate['score_or_claim_allowed']);
        $this->assertFalse($candidate['should_update_provider_topology']);
        $this->assertSame('none', $candidate['routing_effect']);

        $fit = $this->segmentByKey($learningPacket['model_fit_matrix'], 'backend|L5|builder');
        $this->assertSame('measured_strong_candidate', $fit['fit_status']);
        $this->assertSame('eligible_for_atlas_decide_shadow_policy_review', $fit['next_action']);
        $this->assertSame(3, $fit['supporting_evidence']['valid_evidence_count']);
        $this->assertSame(92.0, $fit['supporting_evidence']['candidate_average_score']);
        $this->assertContains($runIds[0], $fit['supporting_evidence']['latest_run_ids']);
        $this->assertFalse($fit['supporting_evidence']['should_update_provider_topology']);
        $playbook = $this->modelProfileByKey($learningPacket['model_usage_playbook'], 'anthropic_claude', 'claude_opus');
        $this->assertSame('policy_review_candidate_only', $playbook['usage_status']);
        $this->assertSame('evaluate_in_policy_review_or_shadow_mode_only', $playbook['atlas_decide_allowed_action']);
        $this->assertSame('backend|L5|builder', $playbook['use_when'][0]['segment_key']);
        $this->assertSame(3, $playbook['use_when'][0]['supporting_evidence']['valid_evidence_count']);
        $this->assertSame('none', $playbook['use_when'][0]['supporting_evidence']['routing_effect']);
        $this->assertSame([], $playbook['shadow_when']);
        $this->assertSame([], $playbook['next_evidence_commands_preview']);
        $this->assertSame([], $learningPacket['evidence_collection_plan']['learning_gap_commands_preview']);
        $this->assertSame([], $learningPacket['evidence_collection_plan']['external_execution_plan_commands_preview']);
        $this->assertSame([], $learningPacket['evidence_collection_plan']['arena_repeat_commands_preview']);
        $this->assertSame('atlas_decide_policy_review_required_before_any_preference', $playbook['policy_gate']);
        $this->assertFalse($playbook['score_or_claim_allowed']);
        $this->assertFalse($playbook['should_update_provider_topology']);
        $this->assertSame('none', $playbook['routing_effect']);
        $this->assertFalse($learningPacket['score_or_claim_allowed']);
        $this->assertFalse($learningPacket['external_claim_allowed']);
        $this->assertFalse($learningPacket['should_update_provider_topology']);
        $this->assertSame('none', $learningPacket['routing_effect']);
        $this->assertStringContainsString('policy-review candidate', $learningPacket['operator_summary']);

        $learningOnly = $this->projection->learningPacket([]);
        $this->assertSame('has_directional_learning_candidates', $learningOnly['learning_status']);
        $this->assertSame(1, $learningOnly['preference_candidate_count']);
        $this->assertFalse($learningOnly['score_or_claim_allowed']);
        $this->assertSame('none', $learningOnly['routing_effect']);
    }

    public function test_map_blocks_policy_review_when_segment_dimensions_are_missing(): void
    {
        $runId = $this->seedRun(
            'missing-difficulty',
            winner: 'atlas',
            atlasModel: 'claude_opus',
            rivalModel: 'gpt-5.5',
            atlasScoreOverride: 92.0,
            rivalScoreOverride: 78.0,
            taskCategory: 'backend',
            difficultyLevel: '',
            runFamily: 'missing-dimension-family',
        );
        $this->ledger->record([
            'run_id' => $runId,
            'task_category' => 'backend',
            'role' => 'builder',
        ]);

        $learningPacket = $this->projection->map([])['atlas_decide_learning_packet'];

        $this->assertSame('blocked_missing_required_dimensions', $learningPacket['dimensional_signal_quality']['status']);
        $this->assertFalse($learningPacket['dimensional_signal_quality']['complete_for_policy_review']);
        $this->assertSame(1, $learningPacket['dimensional_signal_quality']['missing_difficulty_segment_count']);
        $this->assertSame(['backend|unknown|builder'], $learningPacket['dimensional_signal_quality']['missing_difficulty_segments_preview']);
        $this->assertContains('difficulty_level_required_for_every_segment', $learningPacket['dimensional_signal_quality']['blockers']);
        $this->assertSame('atlas.forge.rivals.dimensional_signal_repair_plan.v1', $learningPacket['dimensional_signal_repair_plan']['schema_version']);
        $this->assertSame('repair_required_before_policy_review', $learningPacket['dimensional_signal_repair_plan']['status']);
        $this->assertTrue($learningPacket['dimensional_signal_repair_plan']['repair_required']);
        $this->assertSame(1, $learningPacket['dimensional_signal_repair_plan']['repair_target_count']);
        $this->assertSame('difficulty_level', $learningPacket['dimensional_signal_repair_plan']['repair_targets'][0]['dimension']);
        $this->assertSame(['backend|unknown|builder'], $learningPacket['dimensional_signal_repair_plan']['repair_targets'][0]['targets_preview']);
        $this->assertFalse($learningPacket['dimensional_signal_repair_plan']['repair_targets'][0]['automatic_inference_allowed']);
        $this->assertFalse($learningPacket['dimensional_signal_repair_plan']['automatic_mutation_allowed']);
        $this->assertFalse($learningPacket['dimensional_signal_repair_plan']['ledger_rewrite_allowed']);
        $this->assertTrue($learningPacket['dimensional_signal_repair_plan']['requires_human_review']);
        $this->assertContains('difficulty_level_required_for_every_segment', $learningPacket['dimensional_signal_repair_plan']['blockers']);
        $this->assertStringContainsString('statistical-repeat-dry-run --json', $learningPacket['dimensional_signal_repair_plan']['commands_preview'][1]['command']);
        $this->assertFalse($learningPacket['dimensional_signal_repair_plan']['external_claim_allowed']);
        $this->assertFalse($learningPacket['dimensional_signal_repair_plan']['score_or_claim_allowed']);
        $this->assertFalse($learningPacket['dimensional_signal_repair_plan']['should_update_provider_topology']);
        $this->assertSame('none', $learningPacket['dimensional_signal_repair_plan']['routing_effect']);
        $this->assertContains('dimensional_signal_quality_blocked', $learningPacket['blocked_preference_reasons']);
        $this->assertFalse($learningPacket['score_or_claim_allowed']);
        $this->assertFalse($learningPacket['should_update_provider_topology']);
        $this->assertSame('none', $learningPacket['routing_effect']);
    }

    public function test_map_blocks_unresolved_provider_model_from_decide_model_candidates(): void
    {
        $runId = $this->seedRun(
            'unresolved-provider',
            winner: 'atlas',
            atlasModel: 'mystery_model',
            rivalModel: 'gpt-5.5',
            atlasScoreOverride: 92.0,
            rivalScoreOverride: 78.0,
            taskCategory: 'bugfix',
            difficultyLevel: 'L2',
            role: 'repair_agent',
            runFamily: 'bugfix-l2-unresolved-provider',
        );
        $this->ledger->record([
            'run_id' => $runId,
            'task_category' => 'bugfix',
            'role' => 'repair_agent',
        ]);

        $map = $this->projection->map([]);
        $learningPacket = $map['atlas_decide_learning_packet'];

        $this->assertSame(1, $map['segment_count']);
        $this->assertSame(0, $map['model_profile_count']);
        $this->assertSame([], $map['model_profiles']);
        $this->assertSame(0, $learningPacket['model_profile_count']);
        $this->assertSame(0, $learningPacket['learnable_segment_count']);
        $this->assertSame(0, $learningPacket['shadow_only_segment_count']);
        $this->assertSame(1, $learningPacket['blocked_segment_count']);
        $this->assertSame([], $learningPacket['shadow_policy_candidates']);
        $this->assertContains('dimensional_signal_quality_blocked', $learningPacket['blocked_preference_reasons']);
        $this->assertContains('some_segments_blocked_or_require_human_review', $learningPacket['blocked_preference_reasons']);

        $fit = $this->segmentByKey($learningPacket['model_fit_matrix'], 'bugfix|L2|repair_agent');
        $this->assertSame('unknown', $fit['candidate_provider']);
        $this->assertSame('mystery_model', $fit['candidate_model']);
        $this->assertSame('insufficient_evidence', $fit['fit_status']);
        $this->assertSame('blocked_unresolved_provider_model', $fit['candidate_resolution_status']);
        $this->assertContains('provider_required_for_atlas_decide_learning', $fit['candidate_resolution_blockers']);
        $this->assertSame('repair_provider_model_metadata', $fit['next_action']);
        $this->assertSame('blocked_unresolved_provider_model', $fit['supporting_evidence']['candidate_resolution_status']);
        $this->assertContains('provider_required_for_atlas_decide_learning', $fit['supporting_evidence']['candidate_resolution_blockers']);
        $this->assertFalse($fit['supporting_evidence']['score_or_claim_allowed']);
        $this->assertSame('none', $fit['supporting_evidence']['routing_effect']);

        $this->assertSame('blocked_missing_required_dimensions', $learningPacket['dimensional_signal_quality']['status']);
        $this->assertSame(1, $learningPacket['dimensional_signal_quality']['unresolved_provider_model_segment_count']);
        $this->assertContains(
            'provider_model_resolution_required_for_every_candidate_segment',
            $learningPacket['dimensional_signal_quality']['blockers'],
        );
        $this->assertSame('provider_model_resolution', $learningPacket['dimensional_signal_repair_plan']['repair_targets'][0]['dimension']);
        $this->assertFalse($learningPacket['dimensional_signal_repair_plan']['repair_targets'][0]['automatic_inference_allowed']);
        $this->assertFalse($learningPacket['score_or_claim_allowed']);
        $this->assertFalse($learningPacket['should_update_provider_topology']);
        $this->assertSame('none', $learningPacket['routing_effect']);
    }

    private function seedRun(
        string $suffix,
        string $winner = 'atlas',
        bool $hardFail = false,
        string $atlasModel = 'claude_sonnet',
        string $rivalModel = 'codex',
        string $mode = 'fair',
        ?float $atlasScoreOverride = null,
        ?float $rivalScoreOverride = null,
        string $taskCategory = 'frontend',
        string $difficultyLevel = 'L3',
        string $role = 'builder',
        ?string $runFamily = null,
        string $promptMode = 'human-normal',
    ): string {
        $runId = 'signal-test-'.bin2hex(random_bytes(4)).'-'.$suffix;
        $paths = $this->paths->paths($runId);
        @mkdir($paths['base'], 0o755, true);
        @mkdir($paths['evidence'], 0o755, true);

        $atlasReceipt = $this->baseReceipt('atlas', $atlasModel);
        $rivalReceipt = $this->baseReceipt('rival', $rivalModel);
        if ($hardFail) {
            $atlasReceipt['out_of_scope_files'] = ['src/sneaky.php'];
        }

        file_put_contents($paths['evidence'].'/atlas_receipt.json', $this->jsonEncode($atlasReceipt));
        file_put_contents($paths['evidence'].'/rival_receipt.json', $this->jsonEncode($rivalReceipt));
        file_put_contents($paths['evidence'].'/workspace_hashes.json', $this->jsonEncode([
            'before' => ['atlas' => 'h1', 'rival' => 'h1'],
            'after' => ['atlas' => 'h2', 'rival' => 'h2'],
            'dirty_after_run' => false,
            'workspace_blockers' => [],
        ]));

        $manifest = [
            'schema_version' => 'atlas.forge.rivals.run_real.v1',
            'run_id' => $runId,
            'mode' => $mode,
            'atlas_model' => $atlasModel,
            'rival_model' => $rivalModel,
            'preset' => 'quick',
            'case_id' => 'synthetic-case',
            'task_id' => 'synthetic-case',
            'case_source' => 'quick',
            'run_family' => $runFamily ?? $runId,
            'prompt_mode' => $promptMode,
            'task_category' => $taskCategory,
            'difficulty_level' => $difficultyLevel,
            'role' => $role,
            'verdict' => 'comparable',
            'score' => null,
            'claim_ready' => false,
            'workspace_hash_before' => ['atlas' => 'h1', 'rival' => 'h1'],
            'workspace_hash_after' => ['atlas' => 'h2', 'rival' => 'h2'],
            'dirty_after_run' => false,
            'workspace_blockers' => [],
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'separated_from_external_rivals_certification' => true,
        ];
        file_put_contents($paths['manifest_json'], $this->jsonEncode($manifest));

        $atlasScore = $hardFail ? null : ($atlasScoreOverride ?? ($winner === 'atlas' ? 84.0 : ($winner === 'rival' ? 64.0 : 70.0)));
        $rivalScore = $hardFail ? null : ($rivalScoreOverride ?? ($winner === 'rival' ? 84.0 : ($winner === 'atlas' ? 64.0 : 70.0)));
        $hardFailures = $hardFail ? ['no_out_of_scope_files_atlas'] : [];

        $scorecard = [
            'schema_version' => 'atlas.forge.rivals.adjudication.v1',
            'run_id' => $runId,
            'generated_at' => '2026-05-15T12:00:00+00:00',
            'winner' => $hardFail ? null : $winner,
            'atlas_score' => $atlasScore,
            'rival_score' => $rivalScore,
            'tie_threshold' => 5.0,
            'hard_failures' => $hardFailures,
            'quality_dimensions' => $hardFail ? null : [
                'patch_focus' => ['atlas' => 92.0, 'rival' => 60.0, 'explanation' => 'x'],
                'scope_discipline' => ['atlas' => 100.0, 'rival' => 100.0, 'explanation' => 'x'],
            ],
            'replay_passes' => true,
            'claim_ready' => false,
            'human_review_required' => false,
            'separated_from_external_rivals_certification' => true,
        ];
        file_put_contents($paths['scorecard_json'], $this->jsonEncode($scorecard));

        $artifacts = [];
        foreach ([
            'manifest' => $paths['manifest_json'],
            'atlas_receipt' => $paths['evidence'].'/atlas_receipt.json',
            'rival_receipt' => $paths['evidence'].'/rival_receipt.json',
            'workspace_hashes' => $paths['evidence'].'/workspace_hashes.json',
        ] as $key => $path) {
            $artifacts[$key] = [
                'path' => $path,
                'present' => is_file($path),
                'bytes' => is_file($path) ? filesize($path) : 0,
                'sha256' => is_file($path) ? hash_file('sha256', $path) : null,
            ];
        }
        $pack = [
            'schema_version' => 'atlas.forge.rivals.evidence_pack.v1',
            'run_id' => $runId,
            'collected_at' => '2026-05-15T12:00:00+00:00',
            'paths' => $paths,
            'artifacts' => $artifacts,
            'missing_evidence' => [],
            'verdict' => 'comparable',
            'claim_ready' => false,
        ];
        file_put_contents($paths['evidence'].'/evidence_pack.json', $this->jsonEncode($pack));

        return $runId;
    }

    /**
     * @return array<string,mixed>
     */
    private function baseReceipt(string $arm, string $model): array
    {
        return [
            'arm' => $arm,
            'mode' => 'fair',
            'model' => $model,
            'started_at' => '2026-05-15T12:00:00+00:00',
            'finished_at' => '2026-05-15T12:01:00+00:00',
            'exit_code' => 0,
            'test_exit_code' => 0,
            'killed' => false,
            'timeout' => false,
            'tokens_used' => 1234,
            'token_cost' => 0.012,
            'stdout_bytes' => 4_000,
            'stderr_bytes' => 0,
            'changed_files' => ['tests/Feature/Foo.php', 'app/Foo.php'],
            'out_of_scope_files' => [],
            'bytecode_artifacts' => [],
            'patch_diff_bytes' => 3_000,
            'test_log_tail' => '(50 tests, 120 assertions)',
            'intervention_count' => 0,
        ];
    }

    private function jsonEncode(mixed $value): string
    {
        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  list<array<string,mixed>>  $segments
     * @return array<string,mixed>
     */
    private function segmentByKey(array $segments, string $key): array
    {
        foreach ($segments as $segment) {
            if (($segment['segment_key'] ?? null) === $key) {
                return $segment;
            }
        }

        $this->fail("Missing decide-map segment {$key}.");
    }

    /**
     * @param  list<array<string,mixed>>  $profiles
     * @return array<string,mixed>
     */
    private function modelProfileByKey(array $profiles, string $provider, string $model): array
    {
        foreach ($profiles as $profile) {
            if (($profile['provider'] ?? null) === $provider && ($profile['model'] ?? null) === $model) {
                return $profile;
            }
        }

        $this->fail("Missing model profile {$provider}:{$model}.");
    }

    /**
     * @param  list<array<string,mixed>>  $summaries
     * @return array<string,mixed>
     */
    private function categorySummaryByName(array $summaries, string $category): array
    {
        foreach ($summaries as $summary) {
            if (($summary['task_category'] ?? null) === $category) {
                return $summary;
            }
        }

        $this->fail("Missing category summary {$category}.");
    }

    private function purge(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            if (is_dir($path)) {
                $this->purge($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
