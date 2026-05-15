<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\ForgeRivals;

use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsDecideSignalProjectionService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsProviderPerformanceLedgerService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsRunPathResolver;
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

    public function test_record_accepts_valid_scorecard_and_persists_two_entries(): void
    {
        $runId = $this->seedRun('happy-path', winner: 'atlas');

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
            $this->assertSame('builder', $entry['role']);
            $this->assertSame('react', $entry['framework']);
            $this->assertTrue($entry['valid_for_ranking']);
            $this->assertNotNull($entry['score_total']);
        }

        $entries = $this->ledger->loadEntries();
        $this->assertCount(2, $entries);
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
        $this->assertNull($signal['recommended_provider']);
        $this->assertNull($signal['recommended_model']);
        $this->assertSame('insufficient_evidence', $signal['confidence']);
        $this->assertTrue($signal['advisory_only']);
        $this->assertTrue($signal['separated_from_external_rivals_certification']);
        $this->assertFalse($signal['external_provider_call']);
    }

    public function test_decide_signal_blocks_when_task_category_or_role_missing(): void
    {
        $signal = $this->signal->project([]);

        $this->assertSame('insufficient_evidence', $signal['signal']);
        $this->assertContains('task_category_and_role_required', $signal['reason']);
    }

    public function test_decide_signal_recommends_provider_with_higher_score(): void
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
        $this->assertNotNull($signal['recommended_provider']);
        $this->assertNotNull($signal['recommended_model']);
        $this->assertGreaterThanOrEqual(1, $signal['evidence_count']);
        $this->assertTrue($signal['advisory_only']);
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
    ): string {
        $runId = 'ledger-test-'.bin2hex(random_bytes(4)).'-'.$suffix;
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

        $atlasScore = $hardFail ? null : ($winner === 'atlas' ? 84.0 : ($winner === 'rival' ? 64.0 : 70.0));
        $rivalScore = $hardFail ? null : ($winner === 'rival' ? 84.0 : ($winner === 'atlas' ? 64.0 : 70.0));
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
