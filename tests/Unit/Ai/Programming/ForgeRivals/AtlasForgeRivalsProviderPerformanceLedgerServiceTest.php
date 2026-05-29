<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\ForgeRivals;

use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsArenaRunService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsDecideSignalProjectionService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsExternalLearningGapService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsProviderModelRegistryService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsProviderPerformanceLedgerService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsRunPathResolver;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsStatisticalRepeatDryRunService;
use Tests\TestCase;

/**
 * Atlas Forge Rivals · Provider Performance Ledger v1 contract tests.
 *
 * Validates the append-only ledger + decide-signal projection that turn
 * Rivals scorecards into Atlas-Decide-grade intelligence. The ledger NEVER
 * calls a provider, NEVER promotes a claim, NEVER ranks invalid runs, and
 * NEVER unlocks external_rivals_certification — these tests enforce each
 * invariant directly against seeded synthetic evidence packs on disk.
 */
final class AtlasForgeRivalsProviderPerformanceLedgerServiceTest extends TestCase
{
    private string $tmpRunsRoot;

    private string $tmpLedgerRoot;

    private AtlasForgeRivalsRunPathResolver $paths;

    private AtlasForgeRivalsProviderPerformanceLedgerService $ledger;

    private AtlasForgeRivalsDecideSignalProjectionService $signal;

    private AtlasForgeRivalsExternalLearningGapService $externalLearningGap;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRunsRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fr-ledger-runs-'.bin2hex(random_bytes(6));
        $this->tmpLedgerRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fr-ledger-store-'.bin2hex(random_bytes(6));
        @mkdir($this->tmpRunsRoot, 0o755, true);
        @mkdir($this->tmpLedgerRoot, 0o755, true);

        config([
            'atlas_rivals.runs_root' => $this->tmpRunsRoot,
            'atlas_rivals.ledger_root' => $this->tmpLedgerRoot,
        ]);

        $this->paths = new AtlasForgeRivalsRunPathResolver;
        $this->ledger = new AtlasForgeRivalsProviderPerformanceLedgerService($this->paths);
        $this->signal = new AtlasForgeRivalsDecideSignalProjectionService($this->ledger);
        $this->externalLearningGap = new AtlasForgeRivalsExternalLearningGapService($this->ledger, new AtlasForgeRivalsProviderModelRegistryService);
    }

    protected function tearDown(): void
    {
        $this->purge($this->tmpRunsRoot);
        $this->purge($this->tmpLedgerRoot);
        parent::tearDown();
    }

    public function test_snapshot_on_empty_ledger_returns_honest_status(): void
    {
        $snapshot = $this->ledger->snapshot();

        $this->assertSame('ok', $snapshot['status']);
        $this->assertSame(0, $snapshot['total_entries']);
        $this->assertSame(0, $snapshot['filtered_entries']);
        $this->assertSame([], $snapshot['aggregates']['by_task_category']);
        $this->assertSame('insufficient_evidence', $snapshot['aggregates']['atlas_forge_vs_raw_provider_delta']['status']);
        $this->assertSame('insufficient_evidence', $snapshot['aggregates']['fair_vs_full_power_delta']['status']);
        $this->assertFalse($snapshot['claim_ready']);
        $this->assertTrue($snapshot['separated_from_external_rivals_certification']);
        $this->assertFalse($snapshot['external_provider_call']);
    }

    public function test_external_learning_gap_reports_missing_model_category_difficulty_buckets_without_provider_call(): void
    {
        $runId = $this->seedRun('gap-one', winner: 'atlas', atlasModel: 'claude_sonnet', rivalModel: 'codex', difficulty: 'L5');
        $this->ledger->record([
            'run_id' => $runId,
            'task_category' => 'bugfix',
            'role' => 'builder',
        ]);

        $gap = $this->externalLearningGap->report([
            'provider' => 'claude,codex',
            'task_category' => 'bugfix',
            'difficulty_level' => 'L5',
            'role' => 'builder',
        ]);

        $this->assertSame('atlas.forge.rivals.external_learning_gap.v1', $gap['schema_version']);
        $this->assertSame('ok', $gap['status']);
        $this->assertSame('needs_more_external_evidence', $gap['learning_gap_status']);
        $this->assertSame(3, $gap['minimum_valid_evidence_per_bucket']);
        $this->assertGreaterThanOrEqual(2, $gap['target_bucket_count']);
        $this->assertGreaterThan(0, $gap['missing_bucket_count']);
        $this->assertNotEmpty($gap['missing_buckets_preview']);
        $claudeGap = collect($gap['rows_preview'])->firstWhere('provider_family', 'claude');
        $this->assertIsArray($claudeGap);
        $this->assertSame('industrial-50', $claudeGap['case_set']);
        $this->assertSame($claudeGap['dry_run_command'], $claudeGap['next_measurement_command']);
        $this->assertStringContainsString('--dry-run', $claudeGap['dry_run_command']);
        $this->assertStringContainsString('--confirm-runbook-reviewed', $claudeGap['real_execution_command_template']);
        $this->assertStringContainsString('--confirm-provider-cost', $claudeGap['real_execution_command_template']);
        $this->assertStringContainsString('--confirm-real-provider-call', $claudeGap['real_execution_command_template']);
        $this->assertStringNotContainsString('--dry-run', $claudeGap['real_execution_command_template']);
        $this->assertSame([
            'confirm_runbook_reviewed',
            'confirm_provider_cost',
            'confirm_real_provider_call',
        ], $claudeGap['required_confirmations_before_real_execution']);
        $this->assertStringContainsString('--arm-a=claude_code', $claudeGap['next_measurement_command']);
        $this->assertStringContainsString('--arm-b=codex_cli', $claudeGap['next_measurement_command']);
        $this->assertStringContainsString('--arm-b-model=gpt-5.5', $claudeGap['next_measurement_command']);

        $codexGap = collect($gap['rows_preview'])->firstWhere('provider_family', 'codex');
        $this->assertIsArray($codexGap);
        $this->assertStringContainsString('--arm-a=codex_cli', $codexGap['next_measurement_command']);
        $this->assertStringContainsString('--arm-b=claude_code', $codexGap['next_measurement_command']);
        $this->assertStringContainsString('--arm-b-model=sonnet', $codexGap['next_measurement_command']);
        $this->assertSame('collect_more_external_evidence_before_model_preference', $gap['atlas_decide_learning_effect']);
        $this->assertFalse($gap['external_provider_call']);
        $this->assertFalse($gap['provider_tokens_spent']);
        $this->assertFalse($gap['score_or_claim_allowed']);
        $this->assertFalse($gap['should_update_provider_topology']);
        $this->assertSame('atlas_decide', $gap['owner_of_model_routing']);
        $this->assertSame('none', $gap['routing_effect']);
    }

    public function test_external_learning_gap_can_target_single_model_alias_and_blocks_invalid_model(): void
    {
        $gap = $this->externalLearningGap->report([
            'provider' => 'claude',
            'model' => 'opus',
            'task_category' => 'bugfix',
            'difficulty_level' => 'L5',
            'role' => 'builder',
        ]);

        $this->assertSame('ok', $gap['status']);
        $this->assertSame(1, $gap['target_bucket_count']);
        $this->assertSame(1, $gap['missing_bucket_count']);
        $row = $gap['rows_preview'][0];
        $this->assertSame('opus', $row['requested_model']);
        $this->assertSame('claude_opus', $row['model']);
        $this->assertSame([], $row['model_resolution_blockers']);
        $this->assertStringContainsString('--arm-a-model=claude_opus', $row['dry_run_command']);
        $this->assertStringContainsString('--confirm-real-provider-call', $row['real_execution_command_template']);
        $this->assertFalse($gap['external_provider_call']);
        $this->assertFalse($gap['provider_tokens_spent']);
        $this->assertSame('none', $gap['routing_effect']);

        $blocked = $this->externalLearningGap->report([
            'provider' => 'claude',
            'model' => 'not-a-real-claude-model',
            'task_category' => 'bugfix',
            'difficulty_level' => 'L5',
            'role' => 'builder',
        ]);

        $this->assertSame('blocked', $blocked['status']);
        $this->assertSame('blocked_invalid_model_filter', $blocked['learning_gap_status']);
        $this->assertContains('provider_model_unknown:claude:not-a-real-claude-model', $blocked['blockers']);
        $this->assertSame('blocked_invalid_model_filter', $blocked['rows_preview'][0]['status']);
        $this->assertSame(['provider_model_unknown:claude:not-a-real-claude-model'], $blocked['rows_preview'][0]['model_resolution_blockers']);
        $this->assertFalse($blocked['external_provider_call']);
        $this->assertFalse($blocked['provider_tokens_spent']);
        $this->assertSame('none', $blocked['routing_effect']);
    }

    public function test_external_learning_gap_marks_bucket_covered_after_required_repetitions(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $runId = $this->seedRun(
                'gap-covered-'.$i,
                winner: 'atlas',
                atlasModel: 'claude_sonnet',
                rivalModel: 'codex',
                difficulty: 'L5',
            );
            $this->ledger->record([
                'run_id' => $runId,
                'task_category' => 'bugfix',
                'role' => 'builder',
            ]);
        }

        $gap = $this->externalLearningGap->report([
            'provider' => 'claude',
            'task_category' => 'bugfix',
            'difficulty_level' => 'L5',
            'role' => 'builder',
        ]);

        $this->assertSame('ok', $gap['status']);
        $this->assertSame('needs_more_external_evidence', $gap['learning_gap_status']);
        $sonnet = collect($gap['rows_preview'])->firstWhere('model', 'claude_sonnet');
        $this->assertIsArray($sonnet);
        $this->assertSame('sufficient_for_statistical_repeat_bucket', $sonnet['status']);
        $this->assertSame(3, $sonnet['valid_evidence_count']);
        $this->assertSame(0, $sonnet['missing_valid_evidence_count']);
        $this->assertFalse($gap['external_provider_call']);
        $this->assertFalse($gap['provider_tokens_spent']);
        $this->assertFalse($gap['external_claim_allowed']);
    }

    public function test_record_blocks_when_run_id_missing(): void
    {
        $result = $this->ledger->record([]);

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('run_id_required', $result['blockers']);
    }

    public function test_record_blocks_when_run_not_found(): void
    {
        $result = $this->ledger->record([
            'run_id' => 'nonexistent-run',
            'task_category' => 'frontend',
            'role' => 'builder',
        ]);

        $this->assertSame('blocked', $result['status']);
        $this->assertNotEmpty(array_filter($result['blockers'], static fn (string $b): bool => str_starts_with($b, 'run_not_found:')));
    }

    public function test_record_blocks_when_task_category_missing(): void
    {
        $runId = $this->seedRun('cat-missing');
        $result = $this->ledger->record(['run_id' => $runId, 'role' => 'builder']);

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('task_category_required', $result['blockers']);
    }

    public function test_record_blocks_when_role_missing(): void
    {
        $runId = $this->seedRun('role-missing');
        $result = $this->ledger->record(['run_id' => $runId, 'task_category' => 'frontend']);

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('role_required', $result['blockers']);
    }

    public function test_record_blocks_when_evidence_hash_cannot_be_computed(): void
    {
        $runId = $this->seedRun('no-hash');
        // Corrupt the evidence pack so every artifact has no sha256.
        $packPath = $this->paths->paths($runId)['evidence'].'/evidence_pack.json';
        $pack = json_decode((string) file_get_contents($packPath), true);
        foreach ($pack['artifacts'] as $k => $row) {
            $pack['artifacts'][$k]['sha256'] = null;
        }
        file_put_contents($packPath, json_encode($pack));

        $result = $this->ledger->record([
            'run_id' => $runId,
            'task_category' => 'frontend',
            'role' => 'builder',
        ]);

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('evidence_hash_required', $result['blockers']);
    }

    public function test_record_blocks_when_scorecard_replay_did_not_pass(): void
    {
        $runId = $this->seedRun('replay-failed');
        $paths = $this->paths->paths($runId);
        $scorecard = json_decode((string) file_get_contents($paths['scorecard_json']), true);
        $scorecard['replay_passes'] = false;
        file_put_contents($paths['scorecard_json'], $this->jsonEncode($scorecard));

        $result = $this->ledger->record([
            'run_id' => $runId,
            'task_category' => 'frontend',
            'role' => 'builder',
        ]);

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('scorecard_replay_passes_required', $result['blockers']);
        $this->assertStringContainsString('replay --run-id='.$runId, $result['next_command']);
        $this->assertSame([], $this->ledger->loadEntries());
    }

    public function test_record_blocks_when_evidence_pack_declares_missing_evidence(): void
    {
        $runId = $this->seedRun('missing-evidence');
        $paths = $this->paths->paths($runId);
        $packPath = $paths['evidence'].'/evidence_pack.json';
        $pack = json_decode((string) file_get_contents($packPath), true);
        $pack['missing_evidence'] = ['rival_receipt'];
        file_put_contents($packPath, $this->jsonEncode($pack));

        $result = $this->ledger->record([
            'run_id' => $runId,
            'task_category' => 'frontend',
            'role' => 'builder',
        ]);

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('evidence_pack_missing_evidence:rival_receipt', $result['blockers']);
        $this->assertSame([], $this->ledger->loadEntries());
    }

    public function test_record_blocks_when_evidence_artifact_hash_changed(): void
    {
        $runId = $this->seedRun('artifact-tampered');
        $paths = $this->paths->paths($runId);
        file_put_contents($paths['evidence'].'/atlas_receipt.json', $this->jsonEncode([
            'arm' => 'atlas',
            'provider' => 'tampered',
        ]));

        $result = $this->ledger->record([
            'run_id' => $runId,
            'task_category' => 'frontend',
            'role' => 'builder',
        ]);

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('evidence_artifact_hash_mismatch_at_ledger:atlas_receipt', $result['blockers']);
        $this->assertSame([], $this->ledger->loadEntries());
    }

    public function test_record_accepts_valid_scorecard_and_persists_two_entries(): void
    {
        $runId = $this->seedRun('happy-path', winner: 'atlas', difficulty: 'L4', promptMode: 'human-normal', runFamily: 'family-alpha');

        $result = $this->ledger->record([
            'run_id' => $runId,
            'task_category' => 'frontend',
            'role' => 'builder',
            'framework' => 'react',
        ]);

        $this->assertSame('ok', $result['status']);
        $this->assertCount(2, $result['entries_recorded']);
        $arms = array_map(static fn (array $e): string => (string) $e['arm'], $result['entries_recorded']);
        $this->assertEqualsCanonicalizing(['atlas', 'rival'], $arms);

        foreach ($result['entries_recorded'] as $entry) {
            $this->assertNotEmpty($entry['evidence_pack_hash']);
            $this->assertFalse($entry['claim_ready']);
            $this->assertTrue($entry['separated_from_external_rivals_certification']);
            $this->assertSame('frontend', $entry['task_category']);
            $this->assertSame('synthetic-case', $entry['case_id']);
            $this->assertSame('synthetic-case', $entry['task_id']);
            $this->assertSame('quick', $entry['case_source']);
            $this->assertSame('L4', $entry['difficulty_level']);
            $this->assertSame(2.5, $entry['difficulty_weight']);
            $this->assertSame('family-alpha', $entry['run_family']);
            $this->assertSame('human-normal', $entry['prompt_mode']);
            $this->assertSame('builder', $entry['role']);
            $this->assertSame('react', $entry['framework']);
            $this->assertTrue($entry['valid_for_ranking']);
            $this->assertTrue($entry['atlas_decide_learning_eligible']);
            $this->assertSame([], $entry['atlas_decide_learning_blockers']);
            $this->assertNotNull($entry['score_total']);
        }

        $entries = $this->ledger->loadEntries();
        $this->assertCount(2, $entries);
        $snapshot = $this->ledger->snapshot();
        $eligibility = $snapshot['aggregates']['atlas_decide_learning_eligibility'];
        $this->assertSame('atlas.forge.rivals.atlas_decide_learning_eligibility.v1', $eligibility['schema_version']);
        $this->assertSame('ok', $eligibility['status']);
        $this->assertSame(2, $eligibility['eligible_entry_count']);
        $this->assertSame(0, $eligibility['ineligible_entry_count']);
    }

    public function test_record_preserves_entry_but_marks_missing_difficulty_ineligible_for_atlas_decide_learning(): void
    {
        $runId = $this->seedRun('missing-difficulty-learning', winner: 'atlas', promptMode: 'human-normal', runFamily: 'family-alpha');

        $result = $this->ledger->record([
            'run_id' => $runId,
            'task_category' => 'frontend',
            'role' => 'builder',
        ]);

        $this->assertSame('ok', $result['status']);
        foreach ($result['entries_recorded'] as $entry) {
            $this->assertTrue($entry['valid_for_ranking']);
            $this->assertFalse($entry['atlas_decide_learning_eligible']);
            $this->assertContains('difficulty_level_required_for_atlas_decide_learning', $entry['atlas_decide_learning_blockers']);
        }

        $eligibility = $this->ledger->snapshot()['aggregates']['atlas_decide_learning_eligibility'];
        $this->assertSame('blocked_for_some_entries', $eligibility['status']);
        $this->assertSame(2, $eligibility['valid_for_ranking_count']);
        $this->assertSame(0, $eligibility['eligible_entry_count']);
        $this->assertSame(2, $eligibility['ineligible_entry_count']);
        $this->assertSame(2, $eligibility['blocker_counts']['difficulty_level_required_for_atlas_decide_learning']);
        $this->assertNotEmpty($eligibility['ineligible_entries_preview']);
        $this->assertFalse($eligibility['should_update_provider_topology']);
        $this->assertSame('none', $eligibility['routing_effect']);
    }

    public function test_snapshot_recomputes_atlas_decide_learning_eligibility_for_legacy_entries(): void
    {
        @mkdir($this->tmpLedgerRoot.'/entries', 0o755, true);
        $legacy = [
            'schema_version' => 'atlas.forge.rivals.provider_performance_ledger_entry.v1',
            'entry_id' => 'legacy-missing-difficulty',
            'recorded_at' => '2026-05-15T12:00:00+00:00',
            'run_id' => 'legacy-run',
            'provider' => 'anthropic_claude',
            'model' => 'claude_sonnet',
            'task_category' => 'backend',
            'difficulty_level' => null,
            'role' => 'builder',
            'valid_for_ranking' => true,
            'score_total' => 82.0,
            'replay_passed' => true,
            'claim_ready' => false,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
        ];
        file_put_contents($this->tmpLedgerRoot.'/entries.jsonl', json_encode($legacy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);

        $snapshot = $this->ledger->snapshot();
        $eligibility = $snapshot['aggregates']['atlas_decide_learning_eligibility'];

        $this->assertSame('blocked_for_some_entries', $eligibility['status']);
        $this->assertSame(1, $eligibility['valid_for_ranking_count']);
        $this->assertSame(0, $eligibility['eligible_entry_count']);
        $this->assertSame(1, $eligibility['ineligible_entry_count']);
        $this->assertSame(1, $eligibility['blocker_counts']['difficulty_level_required_for_atlas_decide_learning']);
        $this->assertSame('legacy-run', $eligibility['ineligible_entries_preview'][0]['run_id']);

        $row = $snapshot['aggregates']['by_provider_model'][0];
        $this->assertSame(0, $row['atlas_decide_learning_eligible_count']);
        $this->assertSame(1, $row['atlas_decide_learning_ineligible_count']);
        $this->assertSame(1, $row['atlas_decide_learning_blockers']['difficulty_level_required_for_atlas_decide_learning']);
    }

    public function test_snapshot_normalizes_legacy_provider_unknown_model_alias_from_registry_without_rewriting_ledger(): void
    {
        @mkdir($this->tmpLedgerRoot.'/entries', 0o755, true);
        $legacy = [
            'schema_version' => 'atlas.forge.rivals.provider_performance_ledger_entry.v1',
            'entry_id' => 'legacy-sonnet-alias',
            'recorded_at' => '2026-05-15T12:00:00+00:00',
            'run_id' => 'legacy-sonnet-run',
            'arm' => 'atlas',
            'mode' => 'provider_arena',
            'provider' => 'unknown',
            'model' => 'sonnet',
            'task_category' => 'bugfix',
            'difficulty_level' => 'L2',
            'role' => 'repair_agent',
            'valid_for_ranking' => true,
            'score_total' => 90.16,
            'replay_passed' => true,
            'atlas_decide_learning_eligible' => false,
            'atlas_decide_learning_blockers' => ['provider_required_for_atlas_decide_learning'],
            'claim_ready' => false,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
        ];
        file_put_contents($this->tmpLedgerRoot.'/entries.jsonl', json_encode($legacy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);

        $entries = $this->ledger->loadEntries();
        $this->assertSame('anthropic_claude', $entries[0]['provider']);
        $this->assertSame('claude_sonnet', $entries[0]['model']);
        $this->assertSame('unknown', $entries[0]['original_provider']);
        $this->assertSame('sonnet', $entries[0]['original_model']);
        $this->assertSame('read_side_registry_alias_normalization', $entries[0]['provider_model_resolution_source']);
        $this->assertSame([], $entries[0]['atlas_decide_learning_blockers']);
        $this->assertTrue($entries[0]['atlas_decide_learning_eligible']);

        $rawLedger = file_get_contents($this->tmpLedgerRoot.'/entries.jsonl');
        $this->assertIsString($rawLedger);
        $this->assertStringContainsString('"provider":"unknown"', $rawLedger);
        $this->assertStringContainsString('"model":"sonnet"', $rawLedger);

        $snapshot = $this->ledger->snapshot();
        $row = $snapshot['aggregates']['by_task_category_difficulty_role_model'][0];
        $this->assertSame('anthropic_claude', $row['provider']);
        $this->assertSame('claude_sonnet', $row['model']);
        $this->assertSame('ok', $snapshot['aggregates']['atlas_decide_learning_eligibility']['status']);
        $this->assertSame(1, $snapshot['aggregates']['atlas_decide_learning_eligibility']['eligible_entry_count']);
    }

    public function test_record_marks_hard_failure_as_invalid_negative_signal(): void
    {
        $runId = $this->seedRun('hard-fail', hardFail: true);

        $result = $this->ledger->record([
            'run_id' => $runId,
            'task_category' => 'frontend',
            'role' => 'builder',
        ]);

        $this->assertSame('ok', $result['status']);
        foreach ($result['entries_recorded'] as $entry) {
            $this->assertSame('invalid', $entry['outcome']);
            $this->assertNull($entry['score_total']);
            $this->assertFalse($entry['valid_for_ranking']);
            $this->assertNotEmpty($entry['hard_failures']);
            $this->assertFalse($entry['claim_ready']);
        }
    }

    public function test_tie_outcome_marks_human_review_required_not_winner(): void
    {
        $runId = $this->seedRun('tie', winner: 'human_review_required_tie');

        $result = $this->ledger->record([
            'run_id' => $runId,
            'task_category' => 'frontend',
            'role' => 'builder',
        ]);

        $this->assertSame('ok', $result['status']);
        foreach ($result['entries_recorded'] as $entry) {
            $this->assertSame('human_review_required', $entry['outcome']);
            $this->assertNotSame('winner', $entry['outcome']);
            $this->assertFalse($entry['claim_ready']);
        }
    }

    public function test_invalid_entries_excluded_from_cost_quality_frontier(): void
    {
        $this->ledger->record($this->seedAndRecordArgs('valid-1', 'frontend', 'builder', winner: 'atlas'));
        $this->ledger->record($this->seedAndRecordArgs('invalid-1', 'frontend', 'builder', hardFail: true));

        $snapshot = $this->ledger->snapshot();
        $frontier = $snapshot['aggregates']['cost_quality_frontier'];

        $this->assertNotEmpty($frontier);
        foreach ($frontier as $row) {
            $this->assertGreaterThan(0, $row['avg_score']); // invalid entries have null score and never enter
            $this->assertNotSame(0.0, $row['avg_score']);
        }
    }

    public function test_decide_signal_insufficient_evidence_when_category_absent(): void
    {
        $signal = $this->signal->project(['task_category' => 'frontend', 'role' => 'builder']);

        $this->assertSame('insufficient_evidence', $signal['signal']);
        $this->assertNull($signal['top_measured_provider']);
        $this->assertNull($signal['top_measured_model']);
        $this->assertSame('insufficient_evidence', $signal['confidence']);
        $this->assertTrue($signal['advisory_only']);
        $this->assertFalse($signal['should_update_provider_topology']);
        $this->assertTrue($signal['never_changes_atlas_decide_topology']);
        $this->assertSame('atlas_decide', $signal['owner_of_model_routing']);
        $this->assertTrue($signal['separated_from_external_rivals_certification']);
        $this->assertFalse($signal['external_provider_call']);
    }

    public function test_decide_signal_blocks_when_task_category_or_role_missing(): void
    {
        $signal = $this->signal->project([]);

        $this->assertSame('insufficient_evidence', $signal['signal']);
        $this->assertContains('task_category_and_role_required', $signal['reason']);
    }

    public function test_decide_signal_emits_top_measured_provider_without_routing(): void
    {
        // Seed several frontend/builder runs: Claude consistently outperforms codex.
        for ($i = 0; $i < 4; $i++) {
            $runId = $this->seedRun(
                'claude-strong-'.$i,
                winner: 'atlas',
                atlasModel: 'claude_sonnet',
                rivalModel: 'codex',
            );
            $this->ledger->record([
                'run_id' => $runId,
                'task_category' => 'frontend',
                'role' => 'builder',
            ]);
        }

        $signal = $this->signal->project(['task_category' => 'frontend', 'role' => 'builder']);

        $this->assertSame('ok', $signal['signal']);
        $this->assertNotNull($signal['top_measured_provider']);
        $this->assertNotNull($signal['top_measured_model']);
        $this->assertGreaterThanOrEqual(1, $signal['evidence_count']);
        $this->assertTrue($signal['advisory_only']);
        $this->assertFalse($signal['should_update_provider_topology']);
        $this->assertSame('none', $signal['routing_effect']);
    }

    public function test_atlas_forge_vs_raw_provider_delta_computed(): void
    {
        $runId = $this->seedRun('delta', winner: 'atlas');
        $this->ledger->record([
            'run_id' => $runId,
            'task_category' => 'frontend',
            'role' => 'builder',
        ]);

        $snapshot = $this->ledger->snapshot();
        $delta = $snapshot['aggregates']['atlas_forge_vs_raw_provider_delta'];

        $this->assertSame('ok', $delta['status']);
        $this->assertNotNull($delta['atlas_forge_avg']);
        $this->assertNotNull($delta['raw_provider_avg']);
        $this->assertNotNull($delta['delta']);
    }

    public function test_fair_vs_full_power_delta_computed_when_both_modes_present(): void
    {
        $r1 = $this->seedRun('fair-1', winner: 'atlas', mode: 'fair');
        $r2 = $this->seedRun('full-1', winner: 'atlas', mode: 'full_power');
        $this->ledger->record([
            'run_id' => $r1, 'task_category' => 'frontend', 'role' => 'builder',
        ]);
        $this->ledger->record([
            'run_id' => $r2, 'task_category' => 'frontend', 'role' => 'builder',
        ]);

        $snapshot = $this->ledger->snapshot();
        $delta = $snapshot['aggregates']['fair_vs_full_power_delta'];

        $this->assertSame('ok', $delta['status']);
        $this->assertNotNull($delta['fair_avg']);
        $this->assertNotNull($delta['full_power_avg']);
        $this->assertNotNull($delta['delta_full_minus_fair']);
    }

    public function test_external_rivals_remains_blocked_in_every_payload(): void
    {
        $snapshot = $this->ledger->snapshot();
        $signal = $this->signal->project(['task_category' => 'frontend', 'role' => 'builder']);

        $this->assertTrue($snapshot['separated_from_external_rivals_certification']);
        $this->assertFalse($snapshot['external_provider_call']);
        $this->assertFalse($snapshot['provider_tokens_spent']);
        $this->assertTrue($signal['separated_from_external_rivals_certification']);
        $this->assertFalse($signal['external_provider_call']);
        $this->assertFalse($signal['provider_tokens_spent']);
    }

    public function test_confidence_thresholds_match_canonical_table(): void
    {
        $this->assertSame('insufficient_evidence', $this->ledger->confidenceFor(0));
        $this->assertSame('low', $this->ledger->confidenceFor(1));
        $this->assertSame('low', $this->ledger->confidenceFor(2));
        $this->assertSame('medium', $this->ledger->confidenceFor(3));
        $this->assertSame('medium', $this->ledger->confidenceFor(5));
        $this->assertSame('high', $this->ledger->confidenceFor(6));
        $this->assertSame('high', $this->ledger->confidenceFor(100));
    }

    public function test_snapshot_exposes_category_difficulty_role_model_statistical_repeat_readiness(): void
    {
        $runIds = [];
        for ($i = 0; $i < 3; $i++) {
            $runId = $this->seedRun(
                'repeat-ready-'.$i,
                winner: 'atlas',
                atlasModel: 'claude_sonnet',
                rivalModel: 'codex',
                difficulty: 'L5',
                runFamily: 'repeat-ready-family',
            );
            $runIds[] = $runId;
            $this->ledger->record([
                'run_id' => $runId,
                'task_category' => 'bugfix',
                'role' => 'repair_agent',
                'framework' => 'laravel',
            ]);
        }

        $snapshot = $this->ledger->snapshot([
            'run_ids' => $runIds,
            'task_category' => 'bugfix',
            'role' => 'repair_agent',
            'difficulty_level' => 'L5',
        ]);

        $this->assertSame(6, $snapshot['filtered_entries']);
        $readiness = $snapshot['aggregates']['statistical_repeat_readiness'];
        $this->assertSame('ok', $readiness['status']);
        $this->assertSame(3, $readiness['minimum_valid_repetitions_per_bucket']);
        $this->assertSame(2, $readiness['ready_bucket_count']);
        $this->assertSame(0, $readiness['not_ready_bucket_count']);
        $this->assertFalse($readiness['claim_ready']);

        $rows = $snapshot['aggregates']['by_task_category_difficulty_role_model'];
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame('bugfix', $row['task_category']);
            $this->assertSame('L5', $row['difficulty_level']);
            $this->assertSame('repair_agent', $row['role']);
            $this->assertTrue($row['statistical_repeat_ready']);
            $this->assertSame(0, $row['missing_valid_repetitions']);
            $this->assertSame(3, $row['valid_count']);
            $this->assertSame(0.0, $row['score_stddev']);
            $this->assertSame('stable', $row['score_stability']);
            $this->assertSame(0.012, $row['average_cost_estimate_valid']);
            $this->assertSame(60000, $row['average_duration_ms_valid']);
            $this->assertSame(1234, $row['average_tokens_used_valid']);
            $this->assertNotNull($row['cost_per_score_point_valid']);
            $this->assertIsArray($row['confidence_interval_95']);
            $this->assertSame(3, $row['confidence_interval_95']['sample_count']);
        }

        $segmentRows = $snapshot['aggregates']['by_run_family_prompt_task_category_difficulty_role_model'];
        $this->assertCount(2, $segmentRows);
        foreach ($segmentRows as $row) {
            $this->assertNotNull($row['run_family']);
            $this->assertNull($row['prompt_mode']);
            $this->assertTrue($row['statistical_repeat_ready']);
        }
    }

    public function test_statistical_repeat_readiness_blocks_unstable_score_segments(): void
    {
        $runIds = [];
        foreach ([60.0, 84.0, 100.0] as $index => $atlasScore) {
            $runId = $this->seedRun(
                'repeat-unstable-'.$index,
                winner: 'atlas',
                atlasModel: 'claude_sonnet',
                rivalModel: 'codex',
                difficulty: 'L5',
                atlasScoreOverride: $atlasScore,
                rivalScoreOverride: 40.0,
                runFamily: 'repeat-unstable-family',
            );
            $runIds[] = $runId;
            $this->ledger->record([
                'run_id' => $runId,
                'task_category' => 'bugfix',
                'role' => 'repair_agent',
            ]);
        }

        $snapshot = $this->ledger->snapshot(['run_ids' => $runIds]);
        $readiness = $snapshot['aggregates']['statistical_repeat_readiness'];

        $this->assertSame('insufficient_evidence', $readiness['status']);
        $this->assertFalse($readiness['confidence_ready']);
        $this->assertSame(1, $readiness['unstable_bucket_count']);
        $this->assertSame('claude_sonnet', $readiness['unstable_buckets_preview'][0]['model']);
        $this->assertGreaterThan(8.0, $readiness['unstable_buckets_preview'][0]['score_stddev']);
    }

    public function test_snapshot_blocks_statistical_repeat_confidence_until_each_bucket_has_repetitions(): void
    {
        $runId = $this->seedRun('repeat-not-ready', winner: 'atlas', atlasModel: 'claude_sonnet', rivalModel: 'codex', difficulty: 'L4');
        $this->ledger->record([
            'run_id' => $runId,
            'task_category' => 'security',
            'role' => 'builder',
        ]);

        $snapshot = $this->ledger->snapshot(['run_ids' => [$runId]]);
        $readiness = $snapshot['aggregates']['statistical_repeat_readiness'];

        $this->assertSame('insufficient_evidence', $readiness['status']);
        $this->assertSame(0, $readiness['ready_bucket_count']);
        $this->assertSame(2, $readiness['not_ready_bucket_count']);
        $this->assertFalse($readiness['claim_ready']);
        $this->assertFalse($readiness['external_claim_allowed']);

        $claim = $snapshot['external_claim_readiness'];
        $this->assertSame('atlas.forge.rivals.external_claim_readiness.v1', $claim['schema_version']);
        $this->assertSame('blocked_until_reproducible_evidence_complete', $claim['status']);
        $this->assertContains('statistical_repeat_repetitions_required', $claim['blockers']);
        $this->assertSame('needs_repetition', $claim['statistical_repeat_measurement_plan']['status']);
        $this->assertFalse($claim['score_or_claim_allowed']);
        $this->assertFalse($claim['external_claim_allowed']);
    }

    public function test_statistical_repeat_operator_plan_exposes_next_runs_without_provider_call(): void
    {
        $runId = $this->seedRun('repeat-plan', winner: 'atlas', atlasModel: 'claude_sonnet', rivalModel: 'codex', difficulty: 'L4');
        $this->ledger->record([
            'run_id' => $runId,
            'task_category' => 'security',
            'role' => 'builder',
        ]);

        $plan = $this->ledger->statisticalRepeatPlan(['run_ids' => [$runId]]);

        $this->assertSame('ok', $plan['status']);
        $this->assertSame('atlas.forge.rivals.statistical_repeat_operator_plan.v1', $plan['schema_version']);
        $this->assertSame('blocked_until_reproducible_evidence_complete', $plan['external_claim_readiness_status']);
        $this->assertSame('needs_repetition', $plan['statistical_repeat_measurement_plan']['status']);
        $this->assertNotEmpty($plan['repeat_targets_preview']);
        $this->assertSame(2, $plan['repeat_target_count']);
        $this->assertSame(count($plan['repeat_targets_preview']), $plan['repeat_target_preview_count']);
        $this->assertSame(0, $plan['unstable_target_count']);
        $this->assertSame('atlas.forge.rivals.statistical_repeat_execution_plan.v1', $plan['execution_plan']['schema_version']);
        $this->assertSame('needs_repetition', $plan['execution_plan']['status']);
        $this->assertSame(2, $plan['execution_plan']['known_not_ready_bucket_count']);
        $this->assertSame(2, $plan['execution_plan']['planned_target_count']);
        $this->assertSame(2, $plan['execution_plan']['preview_target_count']);
        $this->assertFalse($plan['execution_plan']['preview_limited']);
        $this->assertGreaterThanOrEqual(1, $plan['execution_plan']['group_count']);
        $this->assertSame(4, $plan['execution_plan']['total_suggested_minimum_additional_runs']);
        $this->assertSame('atlas.forge.rivals.statistical_repeat_operator_cost_risk_summary.v1', $plan['execution_plan']['operator_cost_risk_summary']['schema_version']);
        $this->assertSame('ready_for_dry_run_review', $plan['execution_plan']['operator_cost_risk_summary']['status']);
        $this->assertSame(4, $plan['execution_plan']['operator_cost_risk_summary']['minimum_additional_runs_estimate']);
        $this->assertFalse($plan['execution_plan']['operator_cost_risk_summary']['cost_estimate_available']);
        $this->assertSame('bounded_repeat_plan', $plan['execution_plan']['operator_cost_risk_summary']['risk_level']);
        $this->assertTrue($plan['execution_plan']['operator_cost_risk_summary']['dry_run_only_until_confirmed']);
        $this->assertContains('confirm_real_provider_call', $plan['execution_plan']['operator_cost_risk_summary']['required_confirmations_before_real_provider']);
        $this->assertFalse($plan['execution_plan']['operator_cost_risk_summary']['external_provider_call']);
        $this->assertFalse($plan['execution_plan']['operator_cost_risk_summary']['provider_tokens_spent']);
        $this->assertSame('none', $plan['execution_plan']['operator_cost_risk_summary']['routing_effect']);
        $this->assertNotEmpty($plan['execution_plan']['execution_batches']);
        $this->assertSame(2, $plan['execution_plan']['execution_batches'][0]['command_count']);
        $this->assertFalse($plan['execution_plan']['execution_batches'][0]['external_provider_call']);
        $this->assertFalse($plan['execution_plan']['execution_batches'][0]['provider_tokens_spent']);
        $this->assertSame('none', $plan['execution_plan']['execution_batches'][0]['routing_effect']);
        $this->assertTrue($plan['execution_plan']['confirmation_required_before_real_provider']);
        $this->assertFalse($plan['execution_plan']['external_provider_call']);
        $this->assertFalse($plan['execution_plan']['provider_tokens_spent']);
        $this->assertFalse($plan['execution_plan']['score_or_claim_allowed']);
        $this->assertSame('none', $plan['execution_plan']['routing_effect']);
        $this->assertStringContainsString('run-arena --case-set=statistical-repeat', $plan['execution_plan']['groups_by_provider_model'][0]['dry_run_commands_preview'][0]);
        $this->assertStringContainsString('--run-family=', $plan['execution_plan']['groups_by_provider_model'][0]['dry_run_commands_preview'][0]);
        $this->assertStringContainsString('--dry-run --json', $plan['execution_plan']['groups_by_provider_model'][0]['dry_run_commands_preview'][0]);
        $this->assertStringContainsString('--preset=statistical-repeat', $plan['repeat_targets_preview'][0]['next_command']);
        $this->assertFalse($plan['external_provider_call']);
        $this->assertFalse($plan['provider_tokens_spent']);
        $this->assertFalse($plan['score_or_claim_allowed']);
        $this->assertSame('none', $plan['routing_effect']);
    }

    public function test_record_resolves_provider_and_canonical_model_from_registry_alias(): void
    {
        $runId = $this->seedRun('repeat-plan-model-alias', winner: 'atlas', atlasModel: 'sonnet', rivalModel: 'codex', difficulty: 'L4');
        $record = $this->ledger->record([
            'run_id' => $runId,
            'task_category' => 'security',
            'role' => 'builder',
        ]);
        $atlasEntry = collect($record['entries_recorded'])->firstWhere('arm', 'atlas');

        $this->assertSame('anthropic_claude', $atlasEntry['provider']);
        $this->assertSame('claude_sonnet', $atlasEntry['model']);
        $this->assertSame([], $atlasEntry['atlas_decide_learning_blockers']);
        $this->assertTrue($atlasEntry['atlas_decide_learning_eligible']);

        $plan = $this->ledger->statisticalRepeatPlan(['run_ids' => [$runId]]);
        $groups = $plan['execution_plan']['groups_by_provider_model'];
        $claudeSonnetGroup = collect($groups)->first(
            static fn (array $group): bool => ($group['provider'] ?? null) === 'anthropic_claude'
                && ($group['model'] ?? null) === 'claude_sonnet'
        );

        $this->assertIsArray($claudeSonnetGroup);
        $this->assertSame('ready_for_dry_run_review', $plan['execution_plan']['operator_cost_risk_summary']['status']);
        $this->assertSame(0, $plan['execution_plan']['operator_cost_risk_summary']['unresolved_provider_model_group_count']);
        $this->assertSame('resolved_for_dry_run_plan', $claudeSonnetGroup['provider_driver_resolution']['status']);
        $this->assertSame('claude_code', $claudeSonnetGroup['provider_driver_resolution']['arm']);
        $this->assertContains('dry_run_only_until_explicit_real_provider_confirmations', $claudeSonnetGroup['provider_driver_resolution']['notes']);
        $this->assertStringContainsString('--arm-a=claude_code', $claudeSonnetGroup['dry_run_commands_preview'][0]);
        $this->assertStringContainsString('--arm-a-model=claude_sonnet', $claudeSonnetGroup['dry_run_commands_preview'][0]);
        $this->assertStringContainsString('--dry-run --json', $claudeSonnetGroup['dry_run_commands_preview'][0]);
        $this->assertFalse($plan['external_provider_call']);
        $this->assertFalse($plan['provider_tokens_spent']);
        $this->assertFalse($plan['score_or_claim_allowed']);
        $this->assertSame('none', $plan['routing_effect']);
    }

    public function test_statistical_repeat_dry_run_validation_executes_arena_plan_without_provider_call(): void
    {
        $runId = $this->seedRun(
            'repeat-dry-run-validation',
            winner: 'atlas',
            atlasModel: 'claude_sonnet',
            rivalModel: 'codex',
            difficulty: 'L4',
            promptMode: 'human-normal',
            runFamily: 'external-repeat-proof',
        );
        $this->ledger->record([
            'run_id' => $runId,
            'task_category' => 'bugfix',
            'role' => 'builder',
        ]);

        $validator = new AtlasForgeRivalsStatisticalRepeatDryRunService(
            $this->ledger,
            app(AtlasForgeRivalsArenaRunService::class),
        );

        $runbookPath = storage_path('framework/testing/statistical-repeat-runbook-'.str_replace('.', '', uniqid('', true)).'.json');
        $validation = $validator->validate([
            'run_ids' => [$runId],
            'output_path' => $runbookPath,
        ]);

        $this->assertSame('ok', $validation['status']);
        $this->assertSame('atlas.forge.rivals.statistical_repeat_dry_run_validation.v1', $validation['schema_version']);
        $this->assertSame(2, $validation['planned_dry_run_count']);
        $this->assertSame(2, $validation['validated_dry_run_count']);
        $this->assertSame(2, $validation['passed_count']);
        $this->assertSame(0, $validation['failed_count']);
        $this->assertTrue($validation['ready_for_operator_real_repeat_review']);
        $this->assertSame($runbookPath, $validation['real_execution_runbook_path']);
        $this->assertFileExists($runbookPath);
        $persistedRunbook = json_decode((string) file_get_contents($runbookPath), true);
        $this->assertIsArray($persistedRunbook);
        $this->assertSame('atlas.forge.rivals.statistical_repeat_real_execution_runbook.v1', $persistedRunbook['schema_version']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $validation['plan_fingerprint']);
        $summary = $validation['operator_runbook_summary'];
        $this->assertSame('atlas.forge.rivals.statistical_repeat_operator_runbook_summary.v1', $summary['schema_version']);
        $this->assertSame('ready_for_human_cost_review', $summary['status']);
        $this->assertSame(2, $summary['planned_dry_run_count']);
        $this->assertSame(2, $summary['validated_dry_run_count']);
        $this->assertSame(0, $summary['failed_dry_run_count']);
        $this->assertFalse($summary['validation_limited']);
        $this->assertSame(2, $summary['real_command_count']);
        $this->assertSame(1, $summary['batch_count']);
        $this->assertSame($runbookPath, $summary['runbook_artifact_path']);
        $this->assertContains('confirm_real_provider_call', $summary['required_confirmations_before_any_real_command']);
        $this->assertContains('statistical_repeat_confidence_ready', $summary['required_before_claim_or_atlas_decide_policy_review']);
        $this->assertStringContainsString('run-arena', $summary['first_real_command']);
        $this->assertStringContainsString('--run-ids=<planned-run-ids>', $summary['final_post_run_commands_preview']['matrix_report']);
        $this->assertFalse($summary['external_provider_call']);
        $this->assertFalse($summary['provider_tokens_spent']);
        $this->assertTrue($summary['external_provider_call_if_operator_runs_real_commands']);
        $this->assertTrue($summary['provider_tokens_spent_if_operator_runs_real_commands']);
        $this->assertFalse($summary['claim_ready']);
        $this->assertFalse($summary['external_claim_allowed']);
        $this->assertFalse($summary['score_or_claim_allowed']);
        $this->assertTrue($summary['advisory_only']);
        $this->assertFalse($summary['should_update_provider_topology']);
        $this->assertSame('none', $summary['routing_effect']);
        $this->assertSame('atlas.forge.rivals.statistical_repeat_real_execution_runbook.v1', $validation['real_execution_runbook']['schema_version']);
        $this->assertSame('ready_for_human_cost_review', $validation['real_execution_runbook']['status']);
        $this->assertSame($validation['plan_fingerprint'], $validation['real_execution_runbook']['plan_fingerprint']);
        $this->assertSame('statrep-'.substr((string) $validation['plan_fingerprint'], 0, 12), $validation['real_execution_runbook']['run_id_prefix']);
        $this->assertSame(2, $validation['real_execution_runbook']['command_count']);
        $this->assertSame(1, $validation['real_execution_runbook']['batch_count']);
        $realCommand = $validation['real_execution_runbook']['batches'][0]['commands'][0];
        $firstEntry = $validation['real_execution_runbook']['batches'][0]['entries'][0];
        $this->assertSame($validation['real_execution_runbook']['run_id_prefix'].'-001', $firstEntry['run_id']);
        $this->assertStringContainsString('run-arena', $realCommand);
        $this->assertStringContainsString('--run-id='.$firstEntry['run_id'], $realCommand);
        $this->assertStringContainsString('--confirm-runbook-reviewed', $realCommand);
        $this->assertStringContainsString('--confirm-provider-cost', $realCommand);
        $this->assertStringContainsString('--confirm-real-provider-call', $realCommand);
        $this->assertStringNotContainsString('--dry-run', $realCommand);
        $this->assertStringContainsString('replay --run-id='.$firstEntry['run_id'], $firstEntry['post_run_commands']['replay_strict']);
        $this->assertStringContainsString('adjudicate --run-id='.$firstEntry['run_id'], $firstEntry['post_run_commands']['adjudicate']);
        $this->assertStringContainsString('ledger-record --run-id='.$firstEntry['run_id'], $firstEntry['post_run_commands']['ledger_record']);
        $this->assertStringContainsString('--task-category=', $firstEntry['post_run_commands']['ledger_record']);
        $batchPostRun = $validation['real_execution_runbook']['batches'][0]['batch_post_run_commands'];
        $finalPostRun = $validation['real_execution_runbook']['final_post_run_commands'];
        $this->assertStringContainsString('battery-evidence --run-ids=', $batchPostRun['battery_evidence']);
        $this->assertStringContainsString($firstEntry['run_id'], $batchPostRun['battery_evidence']);
        $this->assertStringContainsString('battery-verify-evidence --run-ids=', $finalPostRun['battery_verify_evidence']);
        $this->assertStringContainsString('matrix-report --run-ids=', $finalPostRun['matrix_report']);
        $this->assertStringContainsString('--battery-id='.$validation['real_execution_runbook']['run_id_prefix'], $finalPostRun['matrix_report']);
        $this->assertFalse($validation['real_execution_runbook']['external_provider_call']);
        $this->assertFalse($validation['real_execution_runbook']['provider_tokens_spent']);
        $this->assertTrue($validation['real_execution_runbook']['planning_only']);
        $this->assertSame('none', $validation['real_execution_runbook']['routing_effect']);
        $this->assertSame('external-repeat-proof', $validation['results_preview'][0]['input']['run_family']);
        foreach ($validation['results_preview'] as $result) {
            $this->assertNotEmpty($result['cases_preview']);
            $this->assertSame('ok', $result['case_filter_integrity']['status']);
            $this->assertSame(0, $result['case_filter_integrity']['mismatch_count']);
            foreach ($result['cases_preview'] as $case) {
                $this->assertSame($result['input']['difficulty'], $case['difficulty_level']);
                $this->assertTrue($case['requested_task_category_match']['ok']);
            }
        }
        $this->assertFalse($validation['external_provider_call']);
        $this->assertFalse($validation['provider_tokens_spent']);
        $this->assertFalse($validation['score_or_claim_allowed']);
        $this->assertSame('none', $validation['routing_effect']);
    }

    public function test_statistical_repeat_dry_run_exposes_domain_match_when_requested_category_is_industrial_domain(): void
    {
        $runId = $this->seedRun(
            'repeat-dry-run-security-domain',
            winner: 'atlas',
            atlasModel: 'claude_sonnet',
            rivalModel: 'codex',
            difficulty: 'L4',
            promptMode: 'human-normal',
            runFamily: 'external-repeat-security-domain',
        );
        $this->ledger->record([
            'run_id' => $runId,
            'task_category' => 'security',
            'role' => 'builder',
        ]);

        $validator = new AtlasForgeRivalsStatisticalRepeatDryRunService(
            $this->ledger,
            app(AtlasForgeRivalsArenaRunService::class),
        );

        $validation = $validator->validate([
            'run_ids' => [$runId],
            'n_tasks' => 1,
        ]);

        $this->assertSame('ok', $validation['status']);
        $this->assertSame(1, $validation['validated_dry_run_count']);
        $result = $validation['results_preview'][0];
        $this->assertSame('security', $result['input']['task_category']);
        $this->assertSame('ok', $result['case_filter_integrity']['status']);
        $this->assertSame(0, $result['case_filter_integrity']['mismatch_count']);
        $this->assertArrayHasKey('industrial_domain', $result['case_filter_integrity']['matched_by']);

        foreach ($result['cases_preview'] as $case) {
            $this->assertSame('L4', $case['difficulty_level']);
            $this->assertContains('security', $case['industrial_domains']);
            $this->assertSame('industrial_domain', $case['requested_task_category_match']['basis']);
            $this->assertTrue($case['requested_task_category_match']['ok']);
        }
        $this->assertFalse($validation['external_provider_call']);
        $this->assertFalse($validation['provider_tokens_spent']);
        $this->assertFalse($validation['score_or_claim_allowed']);
        $this->assertSame('none', $validation['routing_effect']);
    }

    public function test_external_claim_readiness_can_reach_human_certification_ready_but_never_unlocks_claim(): void
    {
        $runIds = [];
        foreach (['one', 'two', 'three'] as $suffix) {
            $runId = $this->seedRun(
                'repeat-ready-'.$suffix,
                winner: 'atlas',
                atlasModel: 'claude_sonnet',
                rivalModel: 'codex',
                difficulty: 'L4',
                atlasScoreOverride: 86.0,
                rivalScoreOverride: 70.0,
                promptMode: 'human-normal',
                runFamily: 'external-claim-proof',
            );
            $runIds[] = $runId;
            $this->ledger->record([
                'run_id' => $runId,
                'task_category' => 'security',
                'role' => 'builder',
            ]);
        }

        $snapshot = $this->ledger->snapshot(['run_ids' => $runIds]);
        $claim = $snapshot['external_claim_readiness'];

        $this->assertSame('ready_for_human_certification_external_claim_still_blocked', $claim['status']);
        $this->assertTrue($claim['reproducible_evidence_ready_for_human_certification']);
        $this->assertSame('complete', $claim['statistical_repeat_measurement_plan']['status']);
        $this->assertFalse($claim['claim_ready']);
        $this->assertFalse($claim['external_claim_allowed']);
        $this->assertFalse($claim['score_or_claim_allowed']);
        $this->assertTrue($claim['requirements']['human_external_certification_required']);
        $this->assertFalse($claim['requirements']['external_rivals_certification_unlocked']);
    }

    public function test_statistical_repeat_readiness_does_not_mix_prompt_modes_or_run_families(): void
    {
        $runIds = [];
        foreach ([
            ['one', 'human-normal', 'same-family'],
            ['two', 'human-normal', 'same-family'],
            ['three', 'messy-real', 'same-family'],
            ['four', 'human-normal', 'other-family'],
        ] as [$suffix, $promptMode, $runFamily]) {
            $runId = $this->seedRun(
                'segmented-'.$suffix,
                winner: 'atlas',
                atlasModel: 'claude_sonnet',
                rivalModel: 'codex',
                difficulty: 'L5',
                promptMode: $promptMode,
                runFamily: $runFamily,
            );
            $runIds[] = $runId;
            $this->ledger->record([
                'run_id' => $runId,
                'task_category' => 'bugfix',
                'role' => 'repair_agent',
            ]);
        }

        $snapshot = $this->ledger->snapshot(['run_ids' => $runIds]);
        $readiness = $snapshot['aggregates']['statistical_repeat_readiness'];
        $segments = $snapshot['aggregates']['by_run_family_prompt_task_category_difficulty_role_model'];

        $this->assertSame('insufficient_evidence', $readiness['status']);
        $this->assertFalse($readiness['confidence_ready']);
        $this->assertSame(6, $readiness['not_ready_bucket_count']);
        $this->assertCount(6, $segments);
        foreach ($segments as $segment) {
            $this->assertLessThan(3, $segment['valid_count']);
            $this->assertFalse($segment['statistical_repeat_ready']);
        }
    }

    public function test_snapshot_filters_by_run_family_and_prompt_mode(): void
    {
        $human = $this->seedRun('filter-human', winner: 'atlas', difficulty: 'L5', promptMode: 'human-normal', runFamily: 'deep-swe-round-1');
        $messy = $this->seedRun('filter-messy', winner: 'rival', difficulty: 'L5', promptMode: 'messy-real', runFamily: 'deep-swe-round-1');
        $other = $this->seedRun('filter-other', winner: 'rival', difficulty: 'L5', promptMode: 'human-normal', runFamily: 'deep-swe-round-2');

        foreach ([$human, $messy, $other] as $runId) {
            $this->ledger->record([
                'run_id' => $runId,
                'task_category' => 'bugfix',
                'role' => 'repair_agent',
            ]);
        }

        $snapshot = $this->ledger->snapshot([
            'task_category' => 'bugfix',
            'role' => 'repair_agent',
            'difficulty_level' => 'L5',
            'run_family' => 'deep-swe-round-1',
            'prompt_mode' => 'human-normal',
        ]);

        $this->assertSame('deep-swe-round-1', $snapshot['filters']['run_family']);
        $this->assertSame('human-normal', $snapshot['filters']['prompt_mode']);
        $this->assertSame(2, $snapshot['filtered_entries']);
        foreach ($snapshot['entries_preview'] as $entry) {
            $this->assertSame('deep-swe-round-1', $entry['run_family']);
            $this->assertSame('human-normal', $entry['prompt_mode']);
        }
    }

    public function test_decide_signal_can_filter_by_difficulty_level_without_routing_effect(): void
    {
        $l2 = $this->seedRun('difficulty-l2', winner: 'rival', atlasModel: 'claude_sonnet', rivalModel: 'codex', difficulty: 'L2');
        $l5 = $this->seedRun('difficulty-l5', winner: 'atlas', atlasModel: 'claude_sonnet', rivalModel: 'codex', difficulty: 'L5');

        foreach ([$l2, $l5] as $runId) {
            $this->ledger->record([
                'run_id' => $runId,
                'task_category' => 'bugfix',
                'role' => 'repair_agent',
            ]);
        }

        $signal = $this->signal->project([
            'task_category' => 'bugfix',
            'role' => 'repair_agent',
            'difficulty_level' => 'L5',
        ]);

        $this->assertSame('ok', $signal['signal']);
        $this->assertSame('L5', $signal['difficulty_level']);
        $this->assertSame('anthropic_claude', $signal['top_measured_provider']);
        $this->assertSame('claude_sonnet', $signal['top_measured_model']);
        $this->assertSame(84.0, $signal['top_measured_median_score']);
        $this->assertNull($signal['top_confidence_interval_95']);
        $this->assertSame('insufficient_sample', $signal['top_score_stability']);
        $this->assertSame(0.012, $signal['top_average_cost_estimate']);
        $this->assertSame(60000, $signal['top_average_duration_ms']);
        $this->assertSame(1234, $signal['top_average_tokens_used']);
        $this->assertNotNull($signal['top_cost_per_score_point']);
        $this->assertSame(20.0, $signal['top_gap_vs_runner_up']);
        $this->assertSame('material_advantage', $signal['top_advantage_band']);
        $this->assertSame('top_more_cost_efficient', $signal['top_value_band']);
        $this->assertSame('explore_before_prefer', $signal['decision_readiness']);
        $this->assertTrue($signal['should_explore_alternative']);
        $this->assertSame('none', $signal['routing_effect']);
        $this->assertFalse($signal['should_update_provider_topology']);
    }

    public function test_decide_signal_marks_close_race_as_explore_before_prefer(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $runId = $this->seedRun(
                'close-race-'.$i,
                winner: 'atlas',
                atlasModel: 'claude_sonnet',
                rivalModel: 'codex',
                difficulty: 'L5',
                atlasScoreOverride: 84.0,
                rivalScoreOverride: 82.0,
                runFamily: 'close-race-family',
            );
            $this->ledger->record([
                'run_id' => $runId,
                'task_category' => 'bugfix',
                'role' => 'repair_agent',
            ]);
        }

        $signal = $this->signal->project([
            'task_category' => 'bugfix',
            'role' => 'repair_agent',
            'difficulty_level' => 'L5',
            'run_family' => 'close-race-family',
        ]);

        $this->assertSame('ok', $signal['signal']);
        $this->assertSame(2.0, $signal['top_gap_vs_runner_up']);
        $this->assertSame('technical_tie', $signal['top_advantage_band']);
        $this->assertSame('explore_before_prefer', $signal['decision_readiness']);
        $this->assertTrue($signal['should_explore_alternative']);
        $this->assertSame('openai_codex', $signal['alternative_measured_candidate']['provider']);
        $this->assertSame('none', $signal['routing_effect']);
    }

    public function test_decide_signal_explores_cheaper_runner_up_when_quality_gap_is_not_material(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $runId = $this->seedRun(
                'cost-efficient-runner-up-'.$i,
                winner: 'atlas',
                atlasModel: 'claude_sonnet',
                rivalModel: 'codex',
                difficulty: 'L5',
                atlasScoreOverride: 84.0,
                rivalScoreOverride: 78.0,
                runFamily: 'cost-efficient-family',
                atlasCost: 0.06,
                rivalCost: 0.006,
            );
            $this->ledger->record([
                'run_id' => $runId,
                'task_category' => 'backend',
                'role' => 'builder',
            ]);
        }

        $signal = $this->signal->project([
            'task_category' => 'backend',
            'role' => 'builder',
            'difficulty_level' => 'L5',
            'run_family' => 'cost-efficient-family',
        ]);

        $this->assertSame('ok', $signal['signal']);
        $this->assertSame(6.0, $signal['top_gap_vs_runner_up']);
        $this->assertSame('directional_advantage', $signal['top_advantage_band']);
        $this->assertSame('runner_up_more_cost_efficient_without_material_quality_gap', $signal['top_value_band']);
        $this->assertSame('explore_before_prefer', $signal['decision_readiness']);
        $this->assertTrue($signal['should_explore_alternative']);
        $this->assertLessThan($signal['top_cost_per_score_point'], $signal['alternative_measured_candidate']['cost_per_score_point']);
        $this->assertFalse($signal['should_update_provider_topology']);
    }

    public function test_decide_signal_filters_by_run_family_and_prompt_mode_without_routing_effect(): void
    {
        $human = $this->seedRun(
            'signal-human',
            winner: 'atlas',
            atlasModel: 'claude_sonnet',
            rivalModel: 'codex',
            difficulty: 'L5',
            promptMode: 'human-normal',
            runFamily: 'external-round-1',
        );
        $messy = $this->seedRun(
            'signal-messy',
            winner: 'rival',
            atlasModel: 'claude_sonnet',
            rivalModel: 'codex',
            difficulty: 'L5',
            promptMode: 'messy-real',
            runFamily: 'external-round-1',
        );
        $other = $this->seedRun(
            'signal-other-family',
            winner: 'rival',
            atlasModel: 'claude_sonnet',
            rivalModel: 'codex',
            difficulty: 'L5',
            promptMode: 'human-normal',
            runFamily: 'external-round-2',
        );

        foreach ([$human, $messy, $other] as $runId) {
            $this->ledger->record([
                'run_id' => $runId,
                'task_category' => 'bugfix',
                'role' => 'repair_agent',
            ]);
        }

        $signal = $this->signal->project([
            'task_category' => 'bugfix',
            'role' => 'repair_agent',
            'difficulty_level' => 'L5',
            'run_family' => 'external-round-1',
            'prompt_mode' => 'human-normal',
        ]);

        $this->assertSame('ok', $signal['signal']);
        $this->assertSame('external-round-1', $signal['run_family']);
        $this->assertSame('human-normal', $signal['prompt_mode']);
        $this->assertSame('anthropic_claude', $signal['top_measured_provider']);
        $this->assertSame('claude_sonnet', $signal['top_measured_model']);
        $this->assertSame('none', $signal['routing_effect']);
        $this->assertFalse($signal['should_update_provider_topology']);
        $this->assertTrue($signal['advisory_only']);
    }

    /**
     * @return array<string,mixed>
     */
    private function seedAndRecordArgs(string $suffix, string $taskCategory, string $role, bool $hardFail = false, ?string $winner = null): array
    {
        $runId = $this->seedRun($suffix, winner: $winner ?? 'atlas', hardFail: $hardFail);

        return [
            'run_id' => $runId,
            'task_category' => $taskCategory,
            'role' => $role,
        ];
    }

    private function seedRun(
        string $suffix,
        string $winner = 'atlas',
        bool $hardFail = false,
        string $atlasModel = 'claude_sonnet',
        string $rivalModel = 'claude_sonnet',
        string $mode = 'fair',
        ?string $difficulty = null,
        ?float $atlasScoreOverride = null,
        ?float $rivalScoreOverride = null,
        ?string $promptMode = null,
        ?string $runFamily = null,
        ?float $atlasCost = null,
        ?float $rivalCost = null,
    ): string {
        $runId = 'ledger-test-'.bin2hex(random_bytes(4)).'-'.$suffix;
        $paths = $this->paths->paths($runId);
        @mkdir($paths['base'], 0o755, true);
        @mkdir($paths['evidence'], 0o755, true);

        $atlasReceipt = $this->baseReceipt('atlas', $atlasModel);
        $rivalReceipt = $this->baseReceipt('rival', $rivalModel);
        if ($atlasCost !== null) {
            $atlasReceipt['token_cost'] = $atlasCost;
        }
        if ($rivalCost !== null) {
            $rivalReceipt['token_cost'] = $rivalCost;
        }
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
            'run_family' => $runFamily,
            'prompt_mode' => $promptMode,
            'atlas_model' => $atlasModel,
            'rival_model' => $rivalModel,
            'preset' => 'quick',
            'case_id' => 'synthetic-case',
            'task_id' => 'synthetic-case',
            'case_source' => 'quick',
            'difficulty_level' => $difficulty,
            'difficulty' => $difficulty,
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
            'claim_ready' => $hardFail ? false : ($winner !== 'human_review_required_tie'),
            'human_review_required' => $winner === 'human_review_required_tie',
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
