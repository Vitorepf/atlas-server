<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsAdjudicatorService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsBatteryReportService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsBatteryStateService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsModeRegistry;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsRunPathResolver;
use App\Services\Ai\Programming\ForgeRivals\Corpus\AtlasForgeRivalsProviderArenaCorpusService;
use Tests\TestCase;

/**
 * Atlas Forge Rivals · Battery Report — release_trusted v1.
 *
 * Locks the strictest battery-level promotion in the v1 Scoring Sanity,
 * Fairness & Confidence ladder:
 *   - confidence_level_v1 = release_trusted
 *   - release_trusted = true
 *   - release_category_coverage.complete = true (8 mandatory categories)
 *   - release_difficulty_coverage.complete = true (L1..L5)
 *   - release_sanity_gates all green
 *
 * release_trusted requires every gate green and a real provider mode
 * (fair / full_power). local_fake mode never reaches release_trusted.
 *
 * Never destrava external_rivals_certification.
 */
final class AtlasForgeRivalsBatteryReportReleaseTrustedV1Test extends TestCase
{
    private string $tmpRoot;

    private AtlasForgeRivalsRunPathResolver $paths;

    private AtlasForgeRivalsBatteryStateService $battery;

    private AtlasForgeRivalsBatteryReportService $report;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fr-release-trusted-'.bin2hex(random_bytes(6));
        @mkdir($this->tmpRoot, 0o755, true);
        config(['atlas_rivals.runs_root' => $this->tmpRoot]);

        $this->paths = new AtlasForgeRivalsRunPathResolver;
        $this->battery = new AtlasForgeRivalsBatteryStateService($this->paths);
        $this->report = new AtlasForgeRivalsBatteryReportService($this->paths, $this->battery);
    }

    protected function tearDown(): void
    {
        $this->purge($this->tmpRoot);
        parent::tearDown();
    }

    public function test_release_trusted_when_fair_mode_full_coverage_and_clean_evidence(): void
    {
        $runId = $this->newRunId('release-trusted-ok');
        $cases = $this->synthesise40CasesAcrossCanon();
        $this->battery->initialize($runId, $this->fairContext(), $cases);
        foreach ($cases as $case) {
            $this->battery->markCaseFinished($runId, (string) $case['id'], 'comparable');
        }
        $this->seedDeterministicScorecards($runId, $cases);
        $this->battery->finalize($runId, [
            'aggregate_verdict' => 'comparable',
            'claim_ready' => false,
            'external_provider_call' => true,
            'provider_tokens_spent' => true,
        ]);

        $envelope = $this->report->render(['run_id' => $runId]);

        $this->assertSame('ok', $envelope['status']);
        $this->assertTrue($envelope['release_trusted']);
        $this->assertSame(
            AtlasForgeRivalsAdjudicatorService::CONFIDENCE_LEVEL_RELEASE_TRUSTED,
            $envelope['confidence_level_v1'],
        );

        $this->assertTrue($envelope['release_category_coverage']['complete']);
        $this->assertSame([], $envelope['release_category_coverage']['missing']);
        $this->assertTrue($envelope['release_difficulty_coverage']['complete']);
        $this->assertSame([], $envelope['release_difficulty_coverage']['missing']);

        $sanity = $envelope['release_sanity_gates'];
        $this->assertTrue($sanity['replay_verified_all']);
        $this->assertTrue($sanity['evidence_complete_all']);
        $this->assertTrue($sanity['workspace_clean_all']);
        $this->assertTrue($sanity['human_intervention_clean']);
        $this->assertTrue($sanity['provider_run_clean']);
        $this->assertTrue($sanity['mode_real_provider']);
        $this->assertTrue($sanity['no_contamination']);
        $this->assertTrue($sanity['no_hard_failures']);

        $this->assertSame('atlas.forge.rivals.battery_report.v3', $envelope['schema_version']);
        $this->assertFalse($envelope['unlocks_external_rivals_certification']);
    }

    public function test_release_trusted_blocked_under_local_fake_even_when_coverage_is_complete(): void
    {
        $runId = $this->newRunId('local-fake-blocked');
        $cases = $this->synthesise40CasesAcrossCanon();
        $this->battery->initialize($runId, $this->localFakeContext(), $cases);
        foreach ($cases as $case) {
            $this->battery->markCaseFinished($runId, (string) $case['id'], 'comparable');
        }
        $this->seedDeterministicScorecards($runId, $cases);
        $this->battery->finalize($runId, [
            'aggregate_verdict' => 'comparable',
            'claim_ready' => false,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
        ]);

        $envelope = $this->report->render(['run_id' => $runId]);

        $this->assertFalse($envelope['release_trusted']);
        $this->assertNotSame(
            AtlasForgeRivalsAdjudicatorService::CONFIDENCE_LEVEL_RELEASE_TRUSTED,
            $envelope['confidence_level_v1'],
        );
        $this->assertFalse($envelope['release_sanity_gates']['mode_real_provider']);
        $this->assertContains(
            'mode_does_not_use_a_real_provider:local_fake',
            $envelope['confidence']['release_trusted_reasons'],
        );
    }

    public function test_release_trusted_blocked_when_a_mandatory_category_is_missing(): void
    {
        $runId = $this->newRunId('missing-category');
        // Drop the entire planning category and run only 7 categories x 5 levels.
        $cases = array_values(array_filter(
            $this->synthesise40CasesAcrossCanon(),
            static fn (array $c): bool => (string) $c['category'] !== 'planning',
        ));
        $this->battery->initialize($runId, $this->fairContext(), $cases);
        foreach ($cases as $case) {
            $this->battery->markCaseFinished($runId, (string) $case['id'], 'comparable');
        }
        $this->seedDeterministicScorecards($runId, $cases);
        $this->battery->finalize($runId, [
            'aggregate_verdict' => 'comparable',
            'claim_ready' => false,
            'external_provider_call' => true,
            'provider_tokens_spent' => true,
        ]);

        $envelope = $this->report->render(['run_id' => $runId]);

        $this->assertFalse($envelope['release_trusted']);
        $this->assertFalse($envelope['release_category_coverage']['complete']);
        $this->assertContains('planning', $envelope['release_category_coverage']['missing']);
        // Should still be high confidence (trusted_battery legacy + sanity) but
        // never release_trusted.
        $this->assertNotSame(
            AtlasForgeRivalsAdjudicatorService::CONFIDENCE_LEVEL_RELEASE_TRUSTED,
            $envelope['confidence_level_v1'],
        );
    }

    public function test_release_trusted_blocked_when_a_difficulty_level_is_missing(): void
    {
        $runId = $this->newRunId('missing-difficulty');
        $cases = array_values(array_filter(
            $this->synthesise40CasesAcrossCanon(),
            static fn (array $c): bool => (string) $c['difficulty_level'] !== 'L5',
        ));
        $this->battery->initialize($runId, $this->fairContext(), $cases);
        foreach ($cases as $case) {
            $this->battery->markCaseFinished($runId, (string) $case['id'], 'comparable');
        }
        $this->seedDeterministicScorecards($runId, $cases);
        $this->battery->finalize($runId, [
            'aggregate_verdict' => 'comparable',
            'claim_ready' => false,
            'external_provider_call' => true,
            'provider_tokens_spent' => true,
        ]);

        $envelope = $this->report->render(['run_id' => $runId]);

        $this->assertFalse($envelope['release_trusted']);
        $this->assertFalse($envelope['release_difficulty_coverage']['complete']);
        $this->assertContains('L5', $envelope['release_difficulty_coverage']['missing']);
    }

    // ---------- fixtures (mirror of BatteryReportV2 helpers, trimmed) ----------

    /** @return list<array<string,mixed>> */
    private function synthesise40CasesAcrossCanon(): array
    {
        $levels = AtlasForgeRivalsProviderArenaCorpusService::DIFFICULTY_LEVELS;
        $categories = AtlasForgeRivalsProviderArenaCorpusService::TASK_CATEGORIES;
        $out = [];
        foreach ($categories as $cat) {
            foreach ($levels as $level) {
                $id = 'rt-'.$cat.'-'.$level;
                $out[] = [
                    'id' => $id,
                    'case_source' => 'corpus',
                    'task_category' => AtlasForgeRivalsProviderArenaCorpusService::LEGACY_TASK_CATEGORY_MAP[$cat] ?? $cat,
                    'category' => $cat,
                    'case_set' => 'release',
                    'difficulty' => match ($level) {
                        'L1', 'L2' => 'easy',
                        'L4', 'L5' => 'hard',
                        default => 'medium',
                    },
                    'difficulty_level' => $level,
                    'difficulty_weight' => AtlasForgeRivalsProviderArenaCorpusService::difficultyLevelWeight($level),
                    'difficulty_score' => AtlasForgeRivalsProviderArenaCorpusService::DIFFICULTY_LEVEL_SCORE[$level] ?? 3.0,
                    'planning_weight' => 1.0,
                    'execution_weight' => 1.0,
                ];
            }
        }

        return $out;
    }

    /** @param list<array<string,mixed>> $cases */
    private function seedDeterministicScorecards(string $runId, array $cases): void
    {
        $map = [
            'backend_logic' => ['atlas' => 90.0, 'rival' => 70.0, 'winner' => AtlasForgeRivalsAdjudicatorService::WINNER_ATLAS],
            'realistic_bugfix' => ['atlas' => 88.0, 'rival' => 72.0, 'winner' => AtlasForgeRivalsAdjudicatorService::WINNER_ATLAS],
            'integration_performance' => ['atlas' => 86.0, 'rival' => 76.0, 'winner' => AtlasForgeRivalsAdjudicatorService::WINNER_ATLAS],
            'test_design' => ['atlas' => 84.0, 'rival' => 78.0, 'winner' => AtlasForgeRivalsAdjudicatorService::WINNER_ATLAS],
            'frontend_ui' => ['atlas' => 70.0, 'rival' => 88.0, 'winner' => AtlasForgeRivalsAdjudicatorService::WINNER_RIVAL],
            'refactor' => ['atlas' => 72.0, 'rival' => 86.0, 'winner' => AtlasForgeRivalsAdjudicatorService::WINNER_RIVAL],
            'planning' => ['atlas' => 74.0, 'rival' => 80.5, 'winner' => AtlasForgeRivalsAdjudicatorService::WINNER_RIVAL],
            'architecture' => ['atlas' => 82.0, 'rival' => 80.5, 'winner' => AtlasForgeRivalsAdjudicatorService::WINNER_TIE],
        ];
        foreach ($cases as $case) {
            $cat = (string) $case['category'];
            $row = $map[$cat] ?? $map['backend_logic'];
            $this->writeScorecard($runId, $case, (float) $row['atlas'], (float) $row['rival'], $row['winner']);
        }
    }

    /** @param array<string,mixed> $case */
    private function writeScorecard(string $runId, array $case, ?float $atlas, ?float $rival, ?string $winner = null): void
    {
        $paths = $this->paths->paths($runId);
        $safe = AtlasForgeRivalsBatteryStateService::safeCaseDir((string) $case['id']);
        $base = $paths['base'].'/cases/'.$safe;
        $evidence = $base.'/evidence';
        @mkdir($evidence, 0o755, true);

        if ($winner === null && $atlas !== null && $rival !== null) {
            $diff = $atlas - $rival;
            $abs = abs($diff);
            $winner = $abs < AtlasForgeRivalsAdjudicatorService::DEFAULT_TIE_THRESHOLD
                ? AtlasForgeRivalsAdjudicatorService::WINNER_TIE
                : ($diff > 0 ? AtlasForgeRivalsAdjudicatorService::WINNER_ATLAS : AtlasForgeRivalsAdjudicatorService::WINNER_RIVAL);
        }

        $hardGates = [
            ['code' => 'verdict_comparable', 'ok' => true, 'detail' => 'verdict ok'],
            ['code' => 'tests_passed_atlas', 'ok' => true, 'detail' => 'exit 0'],
            ['code' => 'tests_passed_rival', 'ok' => true, 'detail' => 'exit 0'],
            ['code' => 'replay_passes', 'ok' => true, 'detail' => 'hash match'],
        ];

        $scorecard = [
            'schema_version' => AtlasForgeRivalsAdjudicatorService::SCHEMA_VERSION,
            'case_id' => (string) $case['id'],
            'winner' => $winner,
            'atlas_score' => $atlas,
            'rival_score' => $rival,
            'score_source' => 'quality_dimensions',
            'quality_score_available' => true,
            'hard_failures' => [],
            'tie_threshold' => AtlasForgeRivalsAdjudicatorService::DEFAULT_TIE_THRESHOLD,
            'winner_reason' => ['seeded_for_release_trusted'],
            'hard_gates' => $hardGates,
            'replay_passes' => true,
            'claim_ready' => false,
            'human_review_required' => $winner === AtlasForgeRivalsAdjudicatorService::WINNER_TIE,
            'separated_from_external_rivals_certification' => true,
            'quality_dimensions' => null,
        ];
        file_put_contents($evidence.'/scorecard.json', json_encode($scorecard, JSON_PRETTY_PRINT));

        $manifest = [
            'schema_version' => 'atlas.forge.rivals.run_real.v1',
            'run_id' => $runId,
            'case_id' => (string) $case['id'],
            'task_category' => (string) $case['task_category'],
            'category' => (string) $case['category'],
            'difficulty' => (string) ($case['difficulty'] ?? 'medium'),
            'difficulty_level' => (string) ($case['difficulty_level'] ?? 'L3'),
            'mode' => 'fair',
            'preset' => 'release',
            'atlas_model' => 'claude_sonnet',
            'rival_model' => 'claude_sonnet',
            'verdict' => 'comparable',
            'separated_from_external_rivals_certification' => true,
        ];
        file_put_contents($evidence.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));

        $receipt = static fn (string $arm): array => [
            'arm' => $arm,
            'case_id' => (string) $case['id'],
            'exit_code' => 0,
            'test_exit_code' => 0,
            'killed' => false,
            'changed_files' => ['app/Synthetic.php'],
            'out_of_scope_files' => [],
            'bytecode_artifacts' => [],
            'workspace_blockers' => [],
            'workspace_has_blocking_changes' => false,
            'patch_diff_bytes' => 256,
            'patch_diff_hash' => hash('sha256', $arm.$case['id']),
        ];
        file_put_contents($evidence.'/atlas_receipt.json', json_encode($receipt('atlas'), JSON_PRETTY_PRINT));
        file_put_contents($evidence.'/rival_receipt.json', json_encode($receipt('rival'), JSON_PRETTY_PRINT));
        file_put_contents($evidence.'/workspace_hashes.json', json_encode([
            'before' => ['atlas' => 'h1', 'rival' => 'h1'],
            'after' => ['atlas' => 'h2', 'rival' => 'h2'],
            'dirty_after_run' => false,
            'workspace_blockers' => [],
        ], JSON_PRETTY_PRINT));
    }

    /** @return array<string,mixed> */
    private function fairContext(): array
    {
        return [
            'preset' => 'release',
            'mode' => 'fair',
            'atlas_model' => 'claude_sonnet',
            'rival_model' => 'claude_sonnet',
            'case_set' => 'release',
        ];
    }

    /** @return array<string,mixed> */
    private function localFakeContext(): array
    {
        return [
            'preset' => 'release',
            'mode' => AtlasForgeRivalsModeRegistry::MODE_LOCAL_FAKE,
            'atlas_model' => 'claude_sonnet',
            'rival_model' => 'claude_sonnet',
            'case_set' => 'release',
        ];
    }

    private function newRunId(string $suffix): string
    {
        return 'rt-v1-'.bin2hex(random_bytes(4)).'-'.$suffix;
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
            $p = $dir.DIRECTORY_SEPARATOR.$item;
            is_dir($p) ? $this->purge($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
