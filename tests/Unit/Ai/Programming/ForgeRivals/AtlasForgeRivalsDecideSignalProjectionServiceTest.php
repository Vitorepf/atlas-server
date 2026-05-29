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

        $backend = $this->segmentByKey($map['segments'], 'backend|L5|builder');
        $this->assertSame('anthropic_claude', $backend['top_measured_provider']);
        $this->assertSame('claude_opus', $backend['top_measured_model']);
        $this->assertSame(92.0, $backend['top_average_score']);
        $this->assertSame('material_advantage', $backend['advantage_band']);
        $this->assertSame('gpt-5.5', $backend['runner_up']['model']);
        $this->assertFalse($backend['should_update_provider_topology']);
        $this->assertSame('none', $backend['routing_effect']);

        $frontend = $this->segmentByKey($map['segments'], 'frontend|L2|builder');
        $this->assertSame('openai_codex', $frontend['top_measured_provider']);
        $this->assertSame('codex', $frontend['top_measured_model']);
        $this->assertSame(89.0, $frontend['top_average_score']);
        $this->assertSame('material_advantage', $frontend['advantage_band']);
        $this->assertSame('claude_sonnet', $frontend['runner_up']['model']);
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
