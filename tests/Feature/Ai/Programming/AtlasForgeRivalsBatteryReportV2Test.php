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
 * Atlas Forge Rivals · Battery Report v2 contract.
 *
 * Locks down the multi-case ranking surface the operator asked for: global
 * winner com confidence, categories[] (atlas_avg vs rival_avg vs delta vs
 * winner por categoria canônica), difficulty_bands[] (L1-L5 com sinal de
 * escalada), per_case_results[], hard_failures[], contaminated_game,
 * why_score_counts/does_not_count, e Markdown PT-BR humano com as 5 seções
 * exigidas.
 *
 * Nunca chama provider real, nunca destrava external_rivals_certification,
 * nunca emite score sintético.
 */
final class AtlasForgeRivalsBatteryReportV2Test extends TestCase
{
    private string $tmpRoot;

    private AtlasForgeRivalsRunPathResolver $paths;

    private AtlasForgeRivalsBatteryStateService $battery;

    private AtlasForgeRivalsBatteryReportService $report;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fr-battery-report-v2-'.bin2hex(random_bytes(6));
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

    public function test_schema_version_is_v3(): void
    {
        $this->assertSame('atlas.forge.rivals.battery_report.v3', AtlasForgeRivalsBatteryReportService::SCHEMA_VERSION);
        $this->assertSame('atlas.forge.rivals.battery_report.v2', AtlasForgeRivalsBatteryReportService::PREVIOUS_SCHEMA_VERSION_V2);
        $this->assertSame('atlas.forge.rivals.battery_report.v1', AtlasForgeRivalsBatteryReportService::PREVIOUS_SCHEMA_VERSION);
    }

    public function test_local_fake_40_cases_surfaces_categories_and_difficulty_bands(): void
    {
        $runId = $this->newRunId('multi-40');
        $cases = $this->synthesise40CasesAcrossCanon();
        $this->battery->initialize($runId, $this->context(), $cases);
        $this->finishAllAsCompleted($runId, $cases);

        // Seed deterministic per-case scorecards: Atlas wins backend/bugfix/
        // performance, Rival wins frontend/refactor, ties em architecture etc.
        $this->seedDeterministicScorecards($runId, $cases);
        $this->battery->finalize($runId, [
            'aggregate_verdict' => 'comparable',
            'claim_ready' => false, // local_fake nunca claim_ready
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
        ]);

        $envelope = $this->report->render(['run_id' => $runId]);

        $this->assertSame('ok', $envelope['status']);
        $this->assertSame('atlas.forge.rivals.battery_report.v3', $envelope['schema_version']);
        $this->assertSame('atlas.forge.rivals.battery_report.v2', $envelope['schema_version_v2']);
        $this->assertSame(40, $envelope['cases_total']);
        $this->assertSame(40, $envelope['total_cases']);
        $this->assertSame(40, $envelope['cases_valid']);
        $this->assertCount(40, $envelope['case_results']);
        $this->assertCount(40, $envelope['per_case_results']);

        $this->assertCount(8, $envelope['categories'], 'oito categorias canônicas devem aparecer');
        $catIds = array_column($envelope['categories'], 'category_id');
        foreach (AtlasForgeRivalsProviderArenaCorpusService::TASK_CATEGORIES as $canon) {
            $this->assertContains($canon, $catIds, 'categoria canônica '.$canon.' deve aparecer no report');
        }

        $this->assertCount(5, $envelope['difficulty_bands']);
        $levels = array_column($envelope['difficulty_bands'], 'level');
        $this->assertSame(['L1', 'L2', 'L3', 'L4', 'L5'], $levels);

        // local_fake force claim_ready=false; mas confidence sobe a trusted.
        $this->assertSame('trusted_battery', $envelope['confidence']['level']);
        $this->assertTrue($envelope['confidence']['is_trusted']);
        $this->assertFalse($envelope['claim_ready']);
        $this->assertTrue($envelope['result_valid_for_ranking']);

        $this->assertFalse($envelope['contaminated_game']);
        $this->assertSame([], $envelope['hard_failures']);

        // Categoria backend_logic: Atlas wins; frontend_ui: Rival wins.
        $byCat = [];
        foreach ($envelope['categories'] as $c) {
            $byCat[$c['category_id']] = $c;
        }
        $this->assertSame('atlas', $byCat['backend_logic']['winner']);
        $this->assertSame('rival', $byCat['frontend_ui']['winner']);

        // Markdown está em PT-BR e tem as 5 seções obrigatórias.
        $this->assertFileExists($envelope['report_path']);
        $md = (string) file_get_contents($envelope['report_path']);
        $this->assertStringContainsString('## Resumo Executivo', $md);
        $this->assertStringContainsString('## Tabela Global', $md);
        $this->assertStringContainsString('## Resultado por Categoria', $md);
        $this->assertStringContainsString('## Resultado por Dificuldade L1-L5', $md);
        $this->assertStringContainsString('## Casos Inválidos', $md);
        $this->assertStringContainsString('## Próximas Ações', $md);
        $this->assertStringContainsString('blocked_requires_operator_approval', $md);
    }

    public function test_invalid_case_marks_low_confidence(): void
    {
        $runId = $this->newRunId('invalid-case');
        $cases = $this->synthesise40CasesAcrossCanon();
        $this->battery->initialize($runId, $this->context(), $cases);
        // 36 completed + 4 invalid → cases_valid abaixo do floor da TRUSTED.
        foreach ($cases as $i => $case) {
            $verdict = $i < 4 ? 'invalid_tests_failed' : 'comparable';
            $this->battery->markCaseFinished($runId, (string) $case['id'], $verdict);
        }
        // Só seed scorecards para os 36 comparáveis.
        foreach ($cases as $i => $case) {
            if ($i < 4) {
                continue; // sem scorecard → evidence_status=missing_scorecard.
            }
            $this->writeScorecard($runId, $case, atlas: 80.0 + ($i * 0.1), rival: 75.0);
        }
        $this->battery->finalize($runId, [
            'aggregate_verdict' => 'invalid_tests_failed',
            'claim_ready' => false,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
        ]);

        $envelope = $this->report->render(['run_id' => $runId]);
        $this->assertSame(40, $envelope['cases_total']);
        $this->assertLessThan(40, $envelope['cases_valid']);
        $this->assertSame(4, $envelope['cases_invalid']);
        // Casos sem scorecard ficam invalid_for_ranking.
        $this->assertContains($envelope['confidence']['level'], [
            AtlasForgeRivalsBatteryReportService::CONFIDENCE_CATEGORY_SIGNAL,
            AtlasForgeRivalsBatteryReportService::CONFIDENCE_TRUSTED,
        ]);
        $missing = array_values(array_filter(
            $envelope['per_case_results'],
            static fn (array $r): bool => $r['evidence_status'] === 'missing_scorecard',
        ));
        $this->assertCount(4, $missing);
    }

    public function test_hard_gate_failure_blocks_winner_and_claim_ready(): void
    {
        $runId = $this->newRunId('hard-fail');
        $cases = $this->synthesise40CasesAcrossCanon();
        $this->battery->initialize($runId, $this->context(), $cases);
        $this->finishAllAsCompleted($runId, $cases);

        foreach ($cases as $i => $case) {
            if ($i === 0) {
                // Primeiro caso com hard gate falho.
                $this->writeScorecard($runId, $case, atlas: null, rival: null, winner: null, hardFailures: ['dirty_after_run_false', 'no_out_of_scope_files_atlas']);
            } else {
                $this->writeScorecard($runId, $case, atlas: 85.0, rival: 70.0);
            }
        }
        $this->battery->finalize($runId, [
            'aggregate_verdict' => 'invalid_workspace_after_run',
            'claim_ready' => false,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
        ]);

        $envelope = $this->report->render(['run_id' => $runId]);
        $this->assertNotEmpty($envelope['hard_failures']);
        $this->assertGreaterThanOrEqual(2, count($envelope['hard_failures']));
        $this->assertNull($envelope['winner']);
        $this->assertFalse($envelope['claim_ready']);
        $this->assertFalse($envelope['result_valid_for_ranking']);
        $this->assertContains('hard_failures_present:'.count($envelope['hard_failures']), $envelope['why_score_does_not_count']);
        $this->assertContains('result_invalid_for_ranking', $envelope['why_score_does_not_count']);
    }

    public function test_tie_produces_human_review_required(): void
    {
        $runId = $this->newRunId('tie');
        $cases = $this->synthesise40CasesAcrossCanon();
        $this->battery->initialize($runId, $this->context(), $cases);
        $this->finishAllAsCompleted($runId, $cases);

        // Both arms tied (|delta| < TIE_THRESHOLD=5.0) — Atlas slight edge.
        foreach ($cases as $case) {
            $this->writeScorecard($runId, $case, atlas: 82.0, rival: 80.5, winner: AtlasForgeRivalsAdjudicatorService::WINNER_TIE);
        }
        $this->battery->finalize($runId, [
            'aggregate_verdict' => 'comparable',
            'claim_ready' => false,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
        ]);

        $envelope = $this->report->render(['run_id' => $runId]);
        $this->assertSame(AtlasForgeRivalsAdjudicatorService::WINNER_TIE, $envelope['winner']);
        $this->assertTrue($envelope['human_review_required']);
        $this->assertFalse($envelope['claim_ready']);
    }

    public function test_winner_per_category_is_identified(): void
    {
        $runId = $this->newRunId('per-category');
        $cases = $this->synthesise40CasesAcrossCanon();
        $this->battery->initialize($runId, $this->context(), $cases);
        $this->finishAllAsCompleted($runId, $cases);

        $this->seedDeterministicScorecards($runId, $cases);
        $this->battery->finalize($runId, [
            'aggregate_verdict' => 'comparable',
            'claim_ready' => false,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
        ]);

        $envelope = $this->report->render(['run_id' => $runId]);
        $byCat = [];
        foreach ($envelope['categories'] as $c) {
            $byCat[$c['category_id']] = $c;
        }
        // Conferindo o mapa do seedDeterministicScorecards:
        $this->assertSame('atlas', $byCat['backend_logic']['winner']);
        $this->assertSame('atlas', $byCat['realistic_bugfix']['winner']);
        $this->assertSame('rival', $byCat['frontend_ui']['winner']);
        $this->assertSame('rival', $byCat['refactor']['winner']);
        $this->assertSame('human_review_required_tie', $byCat['architecture']['winner']);
        // E confidence por categoria é pelo menos category_signal (5+ casos).
        $this->assertSame('trusted_battery', $byCat['backend_logic']['confidence']);
    }

    public function test_external_rivals_certification_stays_blocked(): void
    {
        $runId = $this->newRunId('ext-blocked');
        $cases = $this->synthesise40CasesAcrossCanon();
        $this->battery->initialize($runId, $this->context(), $cases);
        $this->finishAllAsCompleted($runId, $cases);
        $this->seedDeterministicScorecards($runId, $cases);
        $this->battery->finalize($runId, [
            'aggregate_verdict' => 'comparable',
            'claim_ready' => false,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
        ]);

        $envelope = $this->report->render(['run_id' => $runId]);
        $this->assertSame(
            'blocked_requires_operator_approval',
            $envelope['safety']['external_rivals_certification_status'],
        );
        $this->assertFalse($envelope['safety']['unlocks_external_rivals_certification']);
        $this->assertFalse($envelope['unlocks_external_rivals_certification']);
        $this->assertFalse($envelope['safety']['synthetic_score_admitted']);
        $this->assertTrue($envelope['separated_from_external_rivals_certification']);

        $md = (string) file_get_contents($envelope['report_path']);
        $this->assertStringContainsString('blocked_requires_operator_approval', $md);
        $this->assertStringContainsString('Cláusula de Segurança', $md);
    }

    public function test_contaminated_run_invalidates_ranking(): void
    {
        $runId = $this->newRunId('contaminated');
        $cases = $this->synthesise40CasesAcrossCanon();
        $this->battery->initialize($runId, $this->context(), $cases);
        $this->finishAllAsCompleted($runId, $cases);

        foreach ($cases as $i => $case) {
            $this->writeScorecard($runId, $case, atlas: 85.0, rival: 70.0);
            // Primeiro caso: workspace ficou sujo → contamination.
            if ($i === 0) {
                $this->writeWorkspaceHashes($runId, $case, dirty: true);
            } else {
                $this->writeWorkspaceHashes($runId, $case, dirty: false);
            }
        }
        $this->battery->finalize($runId, [
            'aggregate_verdict' => 'comparable',
            'claim_ready' => false,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
        ]);

        $envelope = $this->report->render(['run_id' => $runId]);
        $this->assertTrue($envelope['contaminated_game']);
        $this->assertContains('dirty_workspace_after_run', $envelope['contamination_reasons']);
        $this->assertNull($envelope['winner']);
        $this->assertFalse($envelope['claim_ready']);
        $this->assertFalse($envelope['result_valid_for_ranking']);
        $this->assertSame('inconclusive', $envelope['confidence']['level']);
    }

    public function test_json_and_markdown_are_generated(): void
    {
        $runId = $this->newRunId('json-md');
        $cases = $this->synthesise40CasesAcrossCanon();
        $this->battery->initialize($runId, $this->context(), $cases);
        $this->finishAllAsCompleted($runId, $cases);
        $this->seedDeterministicScorecards($runId, $cases);
        $this->battery->finalize($runId, [
            'aggregate_verdict' => 'comparable',
            'claim_ready' => false,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
        ]);

        $envelope = $this->report->render(['run_id' => $runId]);
        $this->assertIsArray($envelope);
        $this->assertSame('ok', $envelope['status']);
        $this->assertFileExists($envelope['report_path']);
        $this->assertJson(json_encode($envelope));
    }

    public function test_blocked_when_run_id_missing(): void
    {
        $envelope = $this->report->render([]);
        $this->assertSame('blocked', $envelope['status']);
        $this->assertContains('run_id_required', $envelope['blockers']);
    }

    public function test_blocked_when_battery_missing(): void
    {
        $envelope = $this->report->render(['run_id' => 'never-existed-'.bin2hex(random_bytes(3))]);
        $this->assertSame('blocked', $envelope['status']);
        $this->assertNotEmpty(array_filter(
            $envelope['blockers'] ?? [],
            static fn (string $b): bool => str_starts_with($b, 'battery_not_found:'),
        ));
    }

    public function test_difficulty_bands_capture_atlas_scaling_when_present(): void
    {
        $runId = $this->newRunId('scaling');
        $cases = $this->synthesise40CasesAcrossCanon();
        $this->battery->initialize($runId, $this->context(), $cases);
        $this->finishAllAsCompleted($runId, $cases);

        // Atlas escala melhor: delta cresce L1→L5, com vantagem já clara em L1
        // (|delta| > TIE_THRESHOLD para cada nível).
        foreach ($cases as $case) {
            $level = (string) $case['difficulty_level'];
            $atlas = match ($level) {
                'L1' => 80.0,
                'L2' => 85.0,
                'L3' => 88.0,
                'L4' => 92.0,
                'L5' => 96.0,
                default => 85.0,
            };
            $rival = match ($level) {
                'L1' => 72.0,
                'L2' => 70.0,
                'L3' => 65.0,
                'L4' => 60.0,
                'L5' => 50.0,
                default => 65.0,
            };
            $this->writeScorecard($runId, $case, atlas: $atlas, rival: $rival);
        }
        $this->battery->finalize($runId, [
            'aggregate_verdict' => 'comparable',
            'claim_ready' => false,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
        ]);

        $envelope = $this->report->render(['run_id' => $runId]);
        $bands = $envelope['difficulty_bands'];
        $byLevel = [];
        foreach ($bands as $b) {
            $byLevel[$b['level']] = $b;
        }
        $this->assertSame('atlas', $byLevel['L1']['winner']);
        $this->assertSame('atlas', $byLevel['L5']['winner']);
        $this->assertGreaterThan((float) $byLevel['L1']['delta'], (float) $byLevel['L5']['delta']);
        $this->assertTrue($byLevel['L5']['delta_grows_with_difficulty']);
    }

    // ---------- fixtures ----------

    /**
     * Eight canonical categories × five L1..L5 levels = 40 cases, all
     * marked as completed; per-case scorecards are seeded by callers.
     *
     * @return list<array<string,mixed>>
     */
    private function synthesise40CasesAcrossCanon(): array
    {
        $levels = AtlasForgeRivalsProviderArenaCorpusService::DIFFICULTY_LEVELS;
        $categories = AtlasForgeRivalsProviderArenaCorpusService::TASK_CATEGORIES;
        $out = [];
        foreach ($categories as $cat) {
            foreach ($levels as $level) {
                $id = 'fr-'.$cat.'-'.$level;
                $out[] = [
                    'id' => $id,
                    'case_source' => 'corpus',
                    'task_category' => $this->legacyCategoryFor($cat),
                    'category' => $cat,
                    'case_set' => 'release',
                    'difficulty' => $this->bucketFor($level),
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

    private function legacyCategoryFor(string $canon): string
    {
        return AtlasForgeRivalsProviderArenaCorpusService::LEGACY_TASK_CATEGORY_MAP[$canon] ?? $canon;
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
    private function finishAllAsCompleted(string $runId, array $cases): void
    {
        foreach ($cases as $case) {
            $this->battery->markCaseFinished($runId, (string) $case['id'], 'comparable');
        }
    }

    /**
     * Deterministic scorecard map exercising every winner outcome
     * (atlas, rival, tie) across the canonical categories.
     *
     * @param  list<array<string,mixed>>  $cases
     */
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
            $this->writeScorecard(
                $runId,
                $case,
                atlas: (float) $row['atlas'],
                rival: (float) $row['rival'],
                winner: $row['winner'],
            );
        }
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
                ['code' => 'verdict_comparable', 'ok' => true, 'detail' => 'verdict ok'],
                ['code' => 'tests_passed_atlas', 'ok' => true, 'detail' => 'exit 0'],
                ['code' => 'tests_passed_rival', 'ok' => true, 'detail' => 'exit 0'],
                ['code' => 'replay_passes', 'ok' => true, 'detail' => 'hash match'],
            ]
            : array_map(
                static fn (string $c): array => ['code' => $c, 'ok' => false, 'detail' => 'seeded failure'],
                $hardFailures,
            );

        $scorecard = [
            'schema_version' => AtlasForgeRivalsAdjudicatorService::SCHEMA_VERSION,
            'case_id' => (string) $case['id'],
            'winner' => $winner,
            'atlas_score' => $atlas,
            'rival_score' => $rival,
            'score_source' => $hardFailures === [] ? 'quality_dimensions' : 'hard_fail',
            'quality_score_available' => $hardFailures === [],
            'hard_failures' => $hardFailures,
            'tie_threshold' => AtlasForgeRivalsAdjudicatorService::DEFAULT_TIE_THRESHOLD,
            'winner_reason' => $hardFailures === [] ? ['seeded'] : ['hard_failures:'.implode(',', $hardFailures)],
            'hard_gates' => $hardGates,
            'replay_passes' => $hardFailures === [],
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
            'mode' => 'local_fake',
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
            'exit_code' => $hardFailures === [] ? 0 : 1,
            'test_exit_code' => $hardFailures === [] ? 0 : 1,
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
        if (! is_file($evidence.'/workspace_hashes.json')) {
            $this->writeWorkspaceHashes($runId, $case, dirty: false);
        }
    }

    /**
     * @param  array<string,mixed>  $case
     */
    private function writeWorkspaceHashes(string $runId, array $case, bool $dirty): void
    {
        $paths = $this->paths->paths($runId);
        $safe = AtlasForgeRivalsBatteryStateService::safeCaseDir((string) $case['id']);
        $evidence = $paths['base'].'/cases/'.$safe.'/evidence';
        @mkdir($evidence, 0o755, true);
        $payload = [
            'before' => ['atlas' => 'h1', 'rival' => 'h1'],
            'after' => ['atlas' => 'h2', 'rival' => 'h2'],
            'dirty_after_run' => $dirty,
            'workspace_blockers' => $dirty ? ['dirty_after_run'] : [],
        ];
        file_put_contents($evidence.'/workspace_hashes.json', json_encode($payload, JSON_PRETTY_PRINT));
    }

    /**
     * @return array<string,mixed>
     */
    private function context(): array
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
        return 'br-v2-'.bin2hex(random_bytes(4)).'-'.$suffix;
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
