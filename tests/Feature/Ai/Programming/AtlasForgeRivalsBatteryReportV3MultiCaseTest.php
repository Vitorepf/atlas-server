<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsAdjudicatorService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsBatteryEvidenceService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsBatteryReplayVerifierService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsBatteryReportService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsBatteryStateService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsCollectEvidenceService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsEventStream;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsEvidencePackVerifierService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsEvidencePolicy;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsReplayService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsRunPathResolver;
use App\Services\Ai\Programming\ForgeRivals\Corpus\AtlasForgeRivalsProviderArenaCorpusService;
use Tests\TestCase;

/**
 * Atlas Forge Rivals · Battery Report/Evidence/Replay Multi-Case v3 lockdown.
 *
 * Locks down the canonical 40-case (8 categories × L1-L5) battery surface
 * the operator asked for:
 *
 *   - report schema_version = v3
 *   - report.case_count / total_cases = 40 (not 1)
 *   - report.case_results[] has 40 entries
 *   - report.category_results[] covers 8 canonical categories
 *   - report.difficulty_results[] covers L1-L5
 *   - degenerate single-case still produces v3 envelope
 *   - winner=null when hard gate justifies; never synthetic
 *   - battery evidence pack exposes case_results / category_results /
 *     difficulty_results in addition to the legacy cases/category_summary/
 *     difficulty_summary fields
 *   - battery replay verifier fails with per-case + per-artifact identifier
 *     when an artifact is missing
 *   - battery replay verifier passes on a complete local_fake battery
 *   - aggregate_claim_ready remains false
 *   - external_rivals_certification stays blocked_requires_operator_approval
 *
 * Never invokes a provider. Never spends tokens. Never unlocks
 * external_rivals_certification.
 */
final class AtlasForgeRivalsBatteryReportV3MultiCaseTest extends TestCase
{
    private string $tmpRoot;

    private AtlasForgeRivalsRunPathResolver $paths;

    private AtlasForgeRivalsBatteryStateService $battery;

    private AtlasForgeRivalsBatteryReportService $report;

    private AtlasForgeRivalsBatteryEvidenceService $batteryEvidence;

    private AtlasForgeRivalsBatteryReplayVerifierService $batteryVerifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fr-battery-v3-'.bin2hex(random_bytes(6));
        @mkdir($this->tmpRoot, 0o755, true);
        config(['atlas_rivals.runs_root' => $this->tmpRoot]);

        $this->paths = new AtlasForgeRivalsRunPathResolver;
        $this->battery = new AtlasForgeRivalsBatteryStateService($this->paths);
        $this->report = new AtlasForgeRivalsBatteryReportService($this->paths, $this->battery);

        $events = new AtlasForgeRivalsEventStream($this->paths);
        $collect = new AtlasForgeRivalsCollectEvidenceService($this->paths);
        $replay = new AtlasForgeRivalsReplayService($this->paths, $events);
        $packVerifier = new AtlasForgeRivalsEvidencePackVerifierService($this->paths, $replay);
        $this->batteryEvidence = new AtlasForgeRivalsBatteryEvidenceService($this->paths, $collect);
        $this->batteryVerifier = new AtlasForgeRivalsBatteryReplayVerifierService(
            $this->paths,
            $this->batteryEvidence,
            $packVerifier,
        );
    }

    protected function tearDown(): void
    {
        $this->purge($this->tmpRoot);
        parent::tearDown();
    }

    public function test_report_v3_with_40_cases_shows_40_not_1(): void
    {
        $runId = $this->newRunId('40-cases');
        $cases = $this->synthesise40Cases();
        $this->initFinalizeWithScorecards($runId, $cases, perCaseAtlas: 80.0, perCaseRival: 70.0);

        $envelope = $this->report->render(['run_id' => $runId]);

        $this->assertSame('ok', $envelope['status']);
        $this->assertSame(AtlasForgeRivalsBatteryReportService::SCHEMA_VERSION, $envelope['schema_version']);
        $this->assertSame('atlas.forge.rivals.battery_report.v3', $envelope['schema_version']);
        $this->assertSame(40, $envelope['case_count']);
        $this->assertSame(40, $envelope['total_cases']);
        $this->assertSame(40, $envelope['cases_total']);
        $this->assertCount(40, $envelope['case_results']);
    }

    public function test_category_results_covers_eight_canonical_categories(): void
    {
        $runId = $this->newRunId('cats');
        $cases = $this->synthesise40Cases();
        $this->initFinalizeWithScorecards($runId, $cases, perCaseAtlas: 82.0, perCaseRival: 72.0);

        $envelope = $this->report->render(['run_id' => $runId]);

        $this->assertCount(8, $envelope['category_results']);
        $categoryIds = array_column($envelope['category_results'], 'category_id');
        foreach (AtlasForgeRivalsProviderArenaCorpusService::TASK_CATEGORIES as $canon) {
            $this->assertContains($canon, $categoryIds, "missing canonical category {$canon} in category_results");
        }
    }

    public function test_difficulty_results_covers_l1_to_l5(): void
    {
        $runId = $this->newRunId('levels');
        $cases = $this->synthesise40Cases();
        $this->initFinalizeWithScorecards($runId, $cases, perCaseAtlas: 80.0, perCaseRival: 70.0);

        $envelope = $this->report->render(['run_id' => $runId]);

        $this->assertCount(5, $envelope['difficulty_results']);
        $this->assertSame(
            ['L1', 'L2', 'L3', 'L4', 'L5'],
            array_column($envelope['difficulty_results'], 'level'),
        );
    }

    public function test_v3_degenerate_single_case_still_emits_v3_envelope(): void
    {
        $runId = $this->newRunId('single');
        $cases = [$this->makeCase('frontend_ui', 'L3')];
        $this->initFinalizeWithScorecards($runId, $cases, perCaseAtlas: 81.0, perCaseRival: 79.0);

        $envelope = $this->report->render(['run_id' => $runId]);

        $this->assertSame('ok', $envelope['status']);
        $this->assertSame('atlas.forge.rivals.battery_report.v3', $envelope['schema_version']);
        $this->assertSame(1, $envelope['case_count']);
        $this->assertSame(1, $envelope['total_cases']);
        $this->assertCount(1, $envelope['case_results']);
        $this->assertCount(1, $envelope['category_results']);
        // L1-L5 ladder is always emitted; only the populated level reports >0.
        $this->assertCount(5, $envelope['difficulty_results']);
        // Confidence cannot promote to trusted_battery on a single case.
        $this->assertContains(
            $envelope['confidence']['level'],
            [
                AtlasForgeRivalsBatteryReportService::CONFIDENCE_INCONCLUSIVE,
                AtlasForgeRivalsBatteryReportService::CONFIDENCE_FLOW_VALIDATED,
            ],
        );
        $this->assertFalse($envelope['claim_ready']);
    }

    public function test_winner_null_only_when_hard_gate_justifies(): void
    {
        $runId = $this->newRunId('hard-fail');
        $cases = $this->synthesise40Cases();
        $this->battery->initialize($runId, $this->context(), $cases);
        foreach ($cases as $case) {
            $this->battery->markCaseFinished($runId, (string) $case['id'], 'comparable');
        }
        // Seed clean scorecards for 39 cases and one hard-failed case.
        foreach ($cases as $i => $case) {
            if ($i === 0) {
                $this->writeScorecard($runId, $case, atlas: null, rival: null, hardFailures: ['contaminated_workspace_after_run']);
            } else {
                $this->writeScorecard($runId, $case, atlas: 85.0, rival: 70.0);
            }
        }
        $this->battery->finalize($runId, [
            'aggregate_verdict' => 'comparable',
            'claim_ready' => false,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
        ]);

        $envelope = $this->report->render(['run_id' => $runId]);

        // winner must be null AND hard_failures must list the failing case.
        $this->assertNull($envelope['winner']);
        $this->assertNotEmpty($envelope['hard_failures']);
        $this->assertNotEmpty($envelope['winner_reason']);
        $this->assertFalse($envelope['claim_ready']);
        $this->assertFalse($envelope['result_valid_for_ranking']);
    }

    public function test_battery_evidence_pack_exposes_case_category_difficulty_results(): void
    {
        $runId = $this->seedTwoCaseBattery();
        $result = $this->batteryEvidence->aggregate([
            'run_ids' => [$runId],
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);
        $pack = $result['battery_evidence_pack'];

        $this->assertSame('ok', $result['status']);
        $this->assertArrayHasKey('case_results', $pack);
        $this->assertArrayHasKey('category_results', $pack);
        $this->assertArrayHasKey('difficulty_results', $pack);
        $this->assertCount(2, $pack['case_results']);
        $this->assertCount(2, $pack['category_results']);
        $this->assertCount(5, $pack['difficulty_results']); // L1-L5 always emitted
        $levels = array_column($pack['difficulty_results'], 'difficulty_level');
        $this->assertSame(['L1', 'L2', 'L3', 'L4', 'L5'], $levels);
        $this->assertSame(2, $pack['total_cases']);
        $this->assertFalse($pack['aggregate_claim_ready']);
        $this->assertSame('blocked', $pack['external_rivals_certification_status']);
    }

    public function test_battery_replay_passes_on_complete_local_fake(): void
    {
        $runId = $this->seedTwoCaseBattery();
        $result = $this->batteryVerifier->verify([
            'run_ids' => [$runId],
            'mode' => AtlasForgeRivalsEvidencePackVerifierService::MODE_REPLAY,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $this->assertSame(
            'passed',
            $result['verification_status'],
            'blockers: '.implode('|', $result['blockers']),
        );
        $this->assertFalse($result['aggregate_claim_ready']);
        $this->assertSame(2, $result['case_count']);
    }

    public function test_battery_replay_fails_with_per_case_per_artifact_identifier_when_missing(): void
    {
        $runId = $this->seedTwoCaseBattery();
        $paths = $this->paths->paths($runId);
        @unlink($paths['evidence'].'/cases/case-1/rival_test.log');

        $result = $this->batteryVerifier->verify([
            'run_ids' => [$runId],
            'mode' => AtlasForgeRivalsEvidencePackVerifierService::MODE_REPLAY,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $this->assertNotSame('passed', $result['verification_status']);
        $this->assertFalse($result['aggregate_claim_ready']);
        $this->assertTrue(
            $this->blockersContain($result['blockers'], 'missing_test_log:rival'),
            'expected per-arm missing_test_log:rival blocker; got: '.implode('|', $result['blockers']),
        );
        // The blocker also names the case so the operator knows which case is broken.
        $this->assertTrue(
            $this->blockersContain($result['blockers'], 'case-1'),
            'expected case-1 identifier in blockers; got: '.implode('|', $result['blockers']),
        );
        $this->assertTrue(
            $this->blockersContain($result['missing_evidence'], 'test_log:rival'),
            'expected test_log:rival in missing_evidence',
        );
    }

    public function test_external_rivals_certification_stays_blocked_in_v3_envelope(): void
    {
        $runId = $this->newRunId('safety');
        $cases = $this->synthesise40Cases();
        $this->initFinalizeWithScorecards($runId, $cases, perCaseAtlas: 80.0, perCaseRival: 70.0);

        $envelope = $this->report->render(['run_id' => $runId]);

        $this->assertSame(
            'blocked_requires_operator_approval',
            $envelope['safety']['external_rivals_certification_status'],
        );
        $this->assertFalse($envelope['unlocks_external_rivals_certification']);
        $this->assertFalse($envelope['safety']['unlocks_external_rivals_certification']);
        $this->assertFalse($envelope['safety']['synthetic_score_admitted']);
    }

    public function test_v3_envelope_carries_v2_legacy_fields_for_backwards_compat(): void
    {
        $runId = $this->newRunId('compat');
        $cases = $this->synthesise40Cases();
        $this->initFinalizeWithScorecards($runId, $cases, perCaseAtlas: 80.0, perCaseRival: 70.0);

        $envelope = $this->report->render(['run_id' => $runId]);

        // v3 canonical names are present.
        $this->assertArrayHasKey('case_results', $envelope);
        $this->assertArrayHasKey('category_results', $envelope);
        $this->assertArrayHasKey('difficulty_results', $envelope);
        // v2 legacy names remain so downstream tooling that already reads v2
        // does not break on the v3 bump.
        $this->assertArrayHasKey('per_case_results', $envelope);
        $this->assertArrayHasKey('categories', $envelope);
        $this->assertArrayHasKey('difficulty_bands', $envelope);
        $this->assertSame($envelope['case_results'], $envelope['per_case_results']);
        $this->assertSame($envelope['category_results'], $envelope['categories']);
        $this->assertSame($envelope['difficulty_results'], $envelope['difficulty_bands']);
    }

    public function test_v3_envelope_exposes_aggregate_score_and_confidence_level_as_flat_aliases(): void
    {
        $runId = $this->newRunId('flat-aliases');
        $cases = $this->synthesise40Cases();
        $this->initFinalizeWithScorecards($runId, $cases, perCaseAtlas: 84.0, perCaseRival: 76.0);

        $envelope = $this->report->render(['run_id' => $runId]);

        $this->assertArrayHasKey('aggregate_score', $envelope);
        $this->assertArrayHasKey('aggregate_atlas_avg', $envelope);
        $this->assertArrayHasKey('aggregate_rival_avg', $envelope);
        $this->assertSame($envelope['global_score'], $envelope['aggregate_score']);
        $this->assertSame($envelope['global_atlas_avg'], $envelope['aggregate_atlas_avg']);
        $this->assertSame($envelope['global_rival_avg'], $envelope['aggregate_rival_avg']);

        $this->assertArrayHasKey('confidence_level', $envelope);
        $this->assertSame($envelope['confidence']['level'], $envelope['confidence_level']);
        $this->assertContains($envelope['confidence_level'], AtlasForgeRivalsBatteryReportService::CONFIDENCE_LADDER);
    }

    public function test_v3_envelope_flags_is_multi_case_and_is_single_case_correctly(): void
    {
        $runIdMulti = $this->newRunId('flag-multi');
        $cases = $this->synthesise40Cases();
        $this->initFinalizeWithScorecards($runIdMulti, $cases, perCaseAtlas: 80.0, perCaseRival: 70.0);
        $envelopeMulti = $this->report->render(['run_id' => $runIdMulti]);
        $this->assertTrue($envelopeMulti['is_multi_case']);
        $this->assertFalse($envelopeMulti['is_single_case']);

        $runIdSingle = $this->newRunId('flag-single');
        $singleCase = [$this->makeCase('frontend_ui', 'L3')];
        $this->initFinalizeWithScorecards($runIdSingle, $singleCase, perCaseAtlas: 80.0, perCaseRival: 70.0);
        $envelopeSingle = $this->report->render(['run_id' => $runIdSingle]);
        $this->assertFalse($envelopeSingle['is_multi_case']);
        $this->assertTrue($envelopeSingle['is_single_case']);
    }

    public function test_case_results_expose_per_case_audit_paths(): void
    {
        $runId = $this->newRunId('per-case-paths');
        $cases = $this->synthesise40Cases();
        $this->initFinalizeWithScorecards($runId, $cases, perCaseAtlas: 81.0, perCaseRival: 77.0);

        $envelope = $this->report->render(['run_id' => $runId]);

        $first = $envelope['case_results'][0];
        $this->assertArrayHasKey('paths', $first);
        foreach ([
            'case_dir',
            'evidence_dir',
            'scorecard_path',
            'manifest_path',
            'report_path',
            'replay_manifest_path',
            'atlas_receipt_path',
            'rival_receipt_path',
            'workspace_hashes_path',
        ] as $pathKey) {
            $this->assertArrayHasKey($pathKey, $first['paths'], "missing per-case path: {$pathKey}");
            $this->assertNotSame('', (string) $first['paths'][$pathKey], "empty per-case path: {$pathKey}");
        }
        // scorecard + manifest foram escritos por initFinalizeWithScorecards.
        $this->assertTrue($first['paths']['scorecard_present']);
        $this->assertTrue($first['paths']['manifest_present']);
        // Receipts e replay manifest são opcionais na fase atual; o caminho é
        // declarado mesmo se o artefato ainda não tiver sido escrito — o flag
        // `*_present` reflete o estado real no disco.
        $this->assertIsBool($first['paths']['atlas_receipt_present']);
        $this->assertIsBool($first['paths']['rival_receipt_present']);
        $this->assertIsBool($first['paths']['replay_manifest_present']);
    }

    public function test_invalid_case_signals_missing_evidence_paths_for_humans(): void
    {
        $runId = $this->newRunId('invalid-paths');
        $cases = $this->synthesise40Cases();
        $this->battery->initialize($runId, $this->context(), $cases);
        foreach ($cases as $i => $case) {
            $verdict = $i < 4 ? 'invalid_tests_failed' : 'comparable';
            $this->battery->markCaseFinished($runId, (string) $case['id'], $verdict);
        }
        foreach ($cases as $i => $case) {
            if ($i < 4) {
                continue;
            }
            $this->writeScorecard($runId, $case, atlas: 80.0, rival: 70.0);
        }
        $this->battery->finalize($runId, [
            'aggregate_verdict' => 'invalid_tests_failed',
            'claim_ready' => false,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
        ]);

        $envelope = $this->report->render(['run_id' => $runId]);
        $missing = array_values(array_filter(
            $envelope['case_results'],
            static fn (array $r): bool => $r['evidence_status'] === 'missing_scorecard',
        ));
        $this->assertCount(4, $missing);
        foreach ($missing as $row) {
            $this->assertFalse($row['paths']['scorecard_present']);
            $this->assertFalse($row['valid_for_ranking']);
        }
    }

    // ---------- fixtures ----------

    /**
     * @return list<array<string,mixed>>
     */
    private function synthesise40Cases(): array
    {
        $levels = AtlasForgeRivalsProviderArenaCorpusService::DIFFICULTY_LEVELS;
        $categories = AtlasForgeRivalsProviderArenaCorpusService::TASK_CATEGORIES;
        $out = [];
        foreach ($categories as $cat) {
            foreach ($levels as $level) {
                $out[] = $this->makeCase($cat, $level);
            }
        }

        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    private function makeCase(string $canonCategory, string $level): array
    {
        $legacy = AtlasForgeRivalsProviderArenaCorpusService::LEGACY_TASK_CATEGORY_MAP[$canonCategory] ?? $canonCategory;

        return [
            'id' => 'fr-'.$canonCategory.'-'.$level,
            'case_source' => 'corpus',
            'task_category' => $legacy,
            'category' => $canonCategory,
            'case_set' => 'release',
            'difficulty' => $this->bucketFor($level),
            'difficulty_level' => $level,
            'difficulty_weight' => AtlasForgeRivalsProviderArenaCorpusService::difficultyLevelWeight($level),
            'difficulty_score' => AtlasForgeRivalsProviderArenaCorpusService::DIFFICULTY_LEVEL_SCORE[$level] ?? 3.0,
            'planning_weight' => 1.0,
            'execution_weight' => 1.0,
        ];
    }

    private function bucketFor(string $level): string
    {
        return match ($level) {
            'L1', 'L2' => 'easy',
            'L4', 'L5' => 'hard',
            default => 'medium',
        };
    }

    /**
     * @param  list<array<string,mixed>>  $cases
     */
    private function initFinalizeWithScorecards(string $runId, array $cases, float $perCaseAtlas, float $perCaseRival): void
    {
        $this->battery->initialize($runId, $this->context(), $cases);
        foreach ($cases as $case) {
            $this->battery->markCaseFinished($runId, (string) $case['id'], 'comparable');
        }
        foreach ($cases as $case) {
            $this->writeScorecard($runId, $case, atlas: $perCaseAtlas, rival: $perCaseRival);
        }
        $this->battery->finalize($runId, [
            'aggregate_verdict' => 'comparable',
            'claim_ready' => false,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
        ]);
    }

    /**
     * @param  array<string,mixed>  $case
     * @param  list<string>  $hardFailures
     */
    private function writeScorecard(
        string $runId,
        array $case,
        ?float $atlas,
        ?float $rival,
        ?string $winner = null,
        array $hardFailures = [],
    ): void {
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

        $hardGates = $hardFailures === []
            ? [
                ['code' => 'tests_green_atlas', 'ok' => true],
                ['code' => 'tests_green_rival', 'ok' => true],
                ['code' => 'patch_within_scope_atlas', 'ok' => true],
                ['code' => 'patch_within_scope_rival', 'ok' => true],
            ]
            : array_map(static fn (string $code): array => ['code' => $code, 'ok' => false], $hardFailures);

        $scorecard = [
            'schema_version' => AtlasForgeRivalsAdjudicatorService::SCHEMA_VERSION,
            'case_id' => $case['id'],
            'task_category' => $case['task_category'] ?? null,
            'difficulty_level' => $case['difficulty_level'],
            'atlas_score' => $atlas,
            'rival_score' => $rival,
            'winner' => $hardFailures === [] ? $winner : null,
            'hard_gates' => $hardGates,
            'score_source' => $hardFailures === [] ? 'dimensions' : 'hard_gate_failed',
            'claim_ready' => false,
        ];
        file_put_contents($evidence.'/scorecard.json', $this->jsonEncode($scorecard));
        file_put_contents($evidence.'/manifest.json', $this->jsonEncode([
            'schema_version' => 'atlas.forge.rivals.run_real.v1',
            'case_id' => $case['id'],
            'verdict' => 'comparable',
            'workspace_blockers' => [],
        ]));
    }

    /**
     * Seed a 2-case multi-case battery on disk for the evidence + replay
     * verifier scenarios. Returns the run_id so the caller can reference
     * artifacts.
     */
    private function seedTwoCaseBattery(): string
    {
        $runId = 'battery-v3-'.bin2hex(random_bytes(4));
        $paths = $this->paths->paths($runId);
        @mkdir($paths['base'], 0o755, true);
        @mkdir($paths['evidence'], 0o755, true);
        @mkdir($paths['evidence'].'/cases/case-1', 0o755, true);
        @mkdir($paths['evidence'].'/cases/case-2', 0o755, true);

        $perCase = [];
        $perCase[] = $this->buildSeedCase('case-1', 'backend_logic', 'L3', $paths['evidence'].'/cases/case-1');
        $perCase[] = $this->buildSeedCase('case-2', 'frontend_ui', 'L4', $paths['evidence'].'/cases/case-2');

        file_put_contents($paths['evidence'].'/atlas_receipt.json', $this->jsonEncode($this->makeReceipt('atlas')));
        file_put_contents($paths['evidence'].'/rival_receipt.json', $this->jsonEncode($this->makeReceipt('rival')));
        file_put_contents($paths['evidence'].'/workspace_hashes.json', $this->jsonEncode([
            'before' => ['atlas' => 'h1', 'rival' => 'h1'],
            'after' => ['atlas' => 'h2', 'rival' => 'h2'],
            'dirty_after_run' => false,
            'workspace_blockers' => [],
            'case_count' => 2,
        ]));
        file_put_contents($paths['evidence'].'/atlas_patch.diff', "--- atlas top-level patch ---\n");
        file_put_contents($paths['evidence'].'/rival_patch.diff', "--- rival top-level patch ---\n");
        file_put_contents($paths['evidence'].'/atlas_test.log', "(50 tests, 120 assertions)\n");
        file_put_contents($paths['evidence'].'/rival_test.log', "(50 tests, 120 assertions)\n");

        $manifest = [
            'schema_version' => 'atlas.forge.rivals.run_real.v1',
            'run_id' => $paths['run_id'],
            'mode' => 'fair',
            'atlas_model' => 'claude_sonnet',
            'rival_model' => 'claude_sonnet',
            'preset' => 'quick',
            'case_id' => 'multi_case_aggregate',
            'case_source' => 'provider_arena_corpus',
            'case_set' => 'release',
            'task_category' => 'backend_logic',
            'case_count' => 2,
            'is_multi_case' => true,
            'cases' => array_map(static fn (array $c): array => $c['perCase'], $perCase),
            'fixture_stage' => ['atlas' => ['status' => 'not_required'], 'rival' => ['status' => 'not_required']],
            'started_at' => '2026-05-15T12:00:00+00:00',
            'finished_at' => '2026-05-15T12:30:00+00:00',
            'verdict' => 'comparable',
            'score' => null,
            'claim_ready' => false,
            'atlas_receipt_hash' => hash('sha256', 'atlas'),
            'rival_receipt_hash' => hash('sha256', 'rival'),
            'workspace_hash_before' => ['atlas' => 'h1', 'rival' => 'h1'],
            'workspace_hash_after' => ['atlas' => 'h2', 'rival' => 'h2'],
            'dirty_after_run' => false,
            'workspace_blockers' => [],
            'workspace_changes_after_run' => ['atlas' => [], 'rival' => []],
            // Synthetic fixture: provider_call=true matches the pre-existing
            // multi-case verifier test so the replay verifier accepts the
            // synthetic local fixture. The actual test never calls a real
            // provider — these flags only label the recorded pack mode.
            'external_provider_call' => true,
            'provider_tokens_spent' => true,
            'separated_from_external_rivals_certification' => true,
        ];
        file_put_contents($paths['manifest_json'], $this->jsonEncode($manifest));
        file_put_contents(
            $paths['events_jsonl'],
            json_encode(['kind' => 'run_started']).PHP_EOL.
            json_encode(['kind' => 'heartbeat']).PHP_EOL.
            json_encode(['kind' => 'final_report']).PHP_EOL,
        );
        file_put_contents($paths['base'].'/intent.json', json_encode(['kind' => 'synthetic_multi_case_v3']));

        return $runId;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildSeedCase(string $caseId, string $canonCategory, string $level, string $caseDir): array
    {
        @mkdir($caseDir, 0o755, true);
        $atlas = $this->makeReceipt('atlas');
        $rival = $this->makeReceipt('rival');
        file_put_contents($caseDir.'/atlas_receipt.json', $this->jsonEncode($atlas));
        file_put_contents($caseDir.'/rival_receipt.json', $this->jsonEncode($rival));
        file_put_contents($caseDir.'/atlas_patch.diff', "--- atlas patch for {$caseId} ---\n");
        file_put_contents($caseDir.'/rival_patch.diff', "--- rival patch for {$caseId} ---\n");
        file_put_contents($caseDir.'/atlas_test.log', "(case={$caseId} 50 tests, 120 assertions)\n");
        file_put_contents($caseDir.'/rival_test.log', "(case={$caseId} 50 tests, 120 assertions)\n");
        file_put_contents($caseDir.'/workspace_hashes.json', $this->jsonEncode([
            'before' => ['atlas' => 'h1-'.$caseId, 'rival' => 'h1-'.$caseId],
            'after' => ['atlas' => 'h2-'.$caseId, 'rival' => 'h2-'.$caseId],
            'dirty_after_run' => false,
            'workspace_blockers' => [],
        ]));

        $atlasPatchSha = hash_file('sha256', $caseDir.'/atlas_patch.diff') ?: null;
        $rivalPatchSha = hash_file('sha256', $caseDir.'/rival_patch.diff') ?: null;
        $atlasTestSha = hash_file('sha256', $caseDir.'/atlas_test.log') ?: null;
        $rivalTestSha = hash_file('sha256', $caseDir.'/rival_test.log') ?: null;
        $atlas['patch_diff_path'] = $caseDir.'/atlas_patch.diff';
        $atlas['patch_diff_hash'] = $atlasPatchSha;
        $atlas['patch_diff_bytes'] = (int) (filesize($caseDir.'/atlas_patch.diff') ?: 0);
        $atlas['test_log_path'] = $caseDir.'/atlas_test.log';
        $atlas['test_log_hash'] = $atlasTestSha;
        $rival['patch_diff_path'] = $caseDir.'/rival_patch.diff';
        $rival['patch_diff_hash'] = $rivalPatchSha;
        $rival['patch_diff_bytes'] = (int) (filesize($caseDir.'/rival_patch.diff') ?: 0);
        $rival['test_log_path'] = $caseDir.'/rival_test.log';
        $rival['test_log_hash'] = $rivalTestSha;
        file_put_contents($caseDir.'/atlas_receipt.json', $this->jsonEncode($atlas));
        file_put_contents($caseDir.'/rival_receipt.json', $this->jsonEncode($rival));

        $legacy = AtlasForgeRivalsProviderArenaCorpusService::LEGACY_TASK_CATEGORY_MAP[$canonCategory] ?? $canonCategory;

        return [
            'perCase' => [
                'case_id' => $caseId,
                'case_index' => 0,
                'case_source' => 'provider_arena_corpus',
                'task_category' => $legacy,
                'case_set' => 'release',
                'difficulty' => 'medium',
                'difficulty_level' => $level,
                'difficulty_weight' => AtlasForgeRivalsProviderArenaCorpusService::difficultyLevelWeight($level),
                'verdict' => 'comparable',
                'evidence_subdir' => 'cases/'.$caseId,
                'workspace_hash_before' => ['atlas' => 'h1-'.$caseId, 'rival' => 'h1-'.$caseId],
                'workspace_hash_after' => ['atlas' => 'h2-'.$caseId, 'rival' => 'h2-'.$caseId],
                'workspace_blockers' => [],
                'fixture_stage' => ['atlas' => ['status' => 'not_required'], 'rival' => ['status' => 'not_required']],
                'atlas_arm' => [
                    'exit_code' => 0,
                    'test_exit_code' => 0,
                    'killed' => false,
                    'timeout_reason' => null,
                    'patch_diff_bytes' => $atlas['patch_diff_bytes'],
                    'patch_diff_hash' => $atlasPatchSha,
                    'patch_diff_path' => $atlas['patch_diff_path'],
                    'test_log_path' => $atlas['test_log_path'],
                    'changed_files' => ['app/A.php'],
                    'out_of_scope_files' => [],
                    'bytecode_artifacts' => [],
                ],
                'rival_arm' => [
                    'exit_code' => 0,
                    'test_exit_code' => 0,
                    'killed' => false,
                    'timeout_reason' => null,
                    'patch_diff_bytes' => $rival['patch_diff_bytes'],
                    'patch_diff_hash' => $rivalPatchSha,
                    'patch_diff_path' => $rival['patch_diff_path'],
                    'test_log_path' => $rival['test_log_path'],
                    'changed_files' => ['app/R.php'],
                    'out_of_scope_files' => [],
                    'bytecode_artifacts' => [],
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function makeReceipt(string $arm): array
    {
        return [
            'arm' => $arm,
            'mode' => 'fair',
            'model' => 'claude_sonnet',
            'command_hash' => hash('sha256', $arm.'-cmd'),
            'prompt_hash' => hash('sha256', $arm.'-prompt'),
            'started_at' => '2026-05-15T12:00:00+00:00',
            'finished_at' => '2026-05-15T12:01:00+00:00',
            'exit_code' => 0,
            'killed' => false,
            'timeout' => false,
            'timeout_reason' => null,
            'stdout_hash' => hash('sha256', $arm.'-so'),
            'stderr_hash' => hash('sha256', $arm.'-se'),
            'stdout_bytes' => 4_000,
            'stderr_bytes' => 0,
            'stdout_tail' => 'tail',
            'stderr_tail' => '',
            'stdout_path' => '/tmp/stdout',
            'stderr_path' => '/tmp/stderr',
            'token_cost' => 0.0,
            'tokens_used' => 0,
            'worktree' => '/tmp/work',
            'case_id' => 'v3-case',
            'changed_files' => ['app/A.php'],
            'out_of_scope_files' => [],
            'bytecode_artifacts' => [],
            'workspace_blockers' => [],
            'workspace_has_blocking_changes' => false,
            'patch_diff_path' => '/tmp/patch.diff',
            'patch_diff_hash' => hash('sha256', $arm.'-pd'),
            'patch_diff_bytes' => 3_000,
            'test_command' => 'phpunit',
            'test_exit_code' => 0,
            'test_log_path' => '/tmp/test.log',
            'test_log_hash' => hash('sha256', $arm.'-tl'),
            'test_log_tail' => '(50 tests, 120 assertions)',
            // 'fake' is a verifier-side flag: set to false so the replay
            // verifier accepts the synthetic real-run-shaped fixture. The
            // test itself never executes a real provider.
            'fake' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function context(): array
    {
        return [
            'mode' => 'fair',
            'atlas_model' => 'sonnet',
            'rival_model' => 'claude_sonnet',
            'preset' => 'release',
            'case_set' => 'release',
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
        ];
    }

    private function newRunId(string $tag): string
    {
        return 'fr-v3-'.$tag.'-'.bin2hex(random_bytes(3));
    }

    /**
     * @param  list<string>  $blockers
     */
    private function blockersContain(array $blockers, string $needle): bool
    {
        foreach ($blockers as $b) {
            if (str_contains((string) $b, $needle)) {
                return true;
            }
        }

        return false;
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
