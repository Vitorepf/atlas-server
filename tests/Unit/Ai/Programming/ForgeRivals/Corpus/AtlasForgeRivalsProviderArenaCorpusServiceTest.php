<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\ForgeRivals\Corpus;

use App\Services\Ai\Programming\ForgeRivals\Corpus\AtlasForgeRivalsProviderArenaCorpusService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Atlas Forge Rivals · Provider Arena Corpus Service — unit tests.
 *
 * Cobre os campos canon do Release v1, as 8 categorias primárias, os case sets
 * release/industrial, o validador de manifest, o snapshot e o content hash.
 * Nada de artisan, DB ou provider.
 */
final class AtlasForgeRivalsProviderArenaCorpusServiceTest extends TestCase
{
    private AtlasForgeRivalsProviderArenaCorpusService $corpus;

    protected function setUp(): void
    {
        parent::setUp();
        $this->corpus = new AtlasForgeRivalsProviderArenaCorpusService;
    }

    public function test_release_matrix_corpus_has_exactly_forty_cases(): void
    {
        $this->assertCount(40, $this->corpus->cases(), 'Release Matrix v1 deve ter exatamente 40 casos (8x5)');
    }

    public function test_release_matrix_has_one_case_per_cell_8x5(): void
    {
        $matrix = [];
        foreach ($this->corpus->cases() as $case) {
            $matrix[(string) $case['category']][(string) $case['difficulty_level']]
                = ($matrix[(string) $case['category']][(string) $case['difficulty_level']] ?? 0) + 1;
        }
        foreach (AtlasForgeRivalsProviderArenaCorpusService::TASK_CATEGORIES as $cat) {
            foreach (['L1', 'L2', 'L3', 'L4', 'L5'] as $lvl) {
                $this->assertSame(
                    1,
                    $matrix[$cat][$lvl] ?? 0,
                    "Célula da matriz vazia ou duplicada: {$cat}/{$lvl}",
                );
            }
        }
    }

    public function test_all_case_ids_are_unique(): void
    {
        $ids = $this->corpus->caseIds();
        $this->assertSame($ids, array_values(array_unique($ids)), 'case_ids precisam ser únicos');
    }

    public function test_every_case_passes_the_29_field_validator(): void
    {
        foreach ($this->corpus->cases() as $case) {
            $invalid = $this->corpus->validateManifest($case);
            $this->assertSame(
                [],
                $invalid,
                "Caso {$case['case_id']} possui campos inválidos: ".implode(', ', $invalid),
            );
            foreach (AtlasForgeRivalsProviderArenaCorpusService::REQUIRED_FIELDS as $field) {
                $this->assertArrayHasKey($field, $case, "Caso {$case['case_id']} sem campo '{$field}'.");
            }
        }
    }

    public function test_every_case_uses_a_canonical_primary_category(): void
    {
        foreach ($this->corpus->cases() as $case) {
            $this->assertContains(
                $case['category'],
                AtlasForgeRivalsProviderArenaCorpusService::TASK_CATEGORIES,
                "Caso {$case['case_id']} tem category fora das 8 canon: {$case['category']}",
            );
        }
    }

    public function test_every_case_declares_objective_business_rule_and_acceptance(): void
    {
        foreach ($this->corpus->cases() as $case) {
            $this->assertNotSame('', trim((string) $case['objective']), "Caso {$case['case_id']} sem objective.");
            $this->assertNotSame('', trim((string) $case['business_rule']), "Caso {$case['case_id']} sem business_rule.");
            $this->assertIsArray($case['acceptance_criteria']);
            $this->assertNotEmpty($case['acceptance_criteria'], "Caso {$case['case_id']} sem acceptance_criteria.");
        }
    }

    public function test_every_case_has_allowed_and_forbidden_files_scope(): void
    {
        foreach ($this->corpus->cases() as $case) {
            $this->assertIsArray($case['allowed_files_scope']);
            $this->assertNotEmpty($case['allowed_files_scope'], "Caso {$case['case_id']} sem allowed_files_scope.");
            $this->assertIsArray($case['forbidden_files_scope']);
            $this->assertNotEmpty($case['forbidden_files_scope'], "Caso {$case['case_id']} sem forbidden_files_scope.");
        }
    }

    public function test_every_case_has_quick_and_full_test_command(): void
    {
        foreach ($this->corpus->cases() as $case) {
            $this->assertNotSame('', trim((string) $case['quick_test_command']), "Caso {$case['case_id']} sem quick_test_command.");
            $this->assertNotSame('', trim((string) $case['full_test_command']), "Caso {$case['case_id']} sem full_test_command.");
        }
    }

    public function test_default_canonical_test_command_uses_hermetic_quick_command(): void
    {
        foreach ($this->corpus->cases() as $case) {
            $this->assertNotSame('', trim((string) $case['test_command']), "Caso {$case['case_id']} sem test_command.");
            $this->assertSame(
                $case['quick_test_command'],
                $case['test_command'],
                "Caso {$case['case_id']} deve validar o desafio com quick_test_command, não com full_test_command amplo.",
            );
        }
    }

    public function test_every_case_quality_weights_sum_to_one(): void
    {
        foreach ($this->corpus->cases() as $case) {
            $weights = $case['quality_weights']['weights'] ?? [];
            $sum = array_sum(array_map(static fn ($w): float => (float) $w, $weights));
            $this->assertEqualsWithDelta(1.0, $sum, 0.01, "Caso {$case['case_id']} weights somam {$sum}, esperado 1.0.");
        }
    }

    public function test_every_case_invalid_if_carries_canonical_hard_gates(): void
    {
        foreach ($this->corpus->cases() as $case) {
            foreach (AtlasForgeRivalsProviderArenaCorpusService::REQUIRED_INVALID_IF_HARD_GATES as $gate) {
                $this->assertContains(
                    $gate,
                    $case['invalid_if'],
                    "Caso {$case['case_id']} não inclui hard gate {$gate} em invalid_if.",
                );
            }
        }
    }

    public function test_every_case_fixture_seed_path_exists_under_canonical_root(): void
    {
        $repoRoot = $this->repoRoot();
        foreach ($this->corpus->cases() as $case) {
            $path = (string) $case['fixture_seed_path'];
            $this->assertStringStartsWith('storage/forge-rivals-corpus/', $path);
            $this->assertDirectoryExists(
                rtrim($repoRoot, '/').'/'.$path,
                "fixture_seed_path do caso {$case['case_id']} não existe: {$path}",
            );
        }
    }

    public function test_no_case_admits_synthetic_score(): void
    {
        foreach ($this->corpus->cases() as $case) {
            $this->assertContains(
                'synthetic_score_admitted',
                $case['invalid_if'],
                "Caso {$case['case_id']} sem synthetic_score_admitted em invalid_if.",
            );
        }
    }

    public function test_no_case_unlocks_external_rivals_certification(): void
    {
        foreach ($this->corpus->cases() as $case) {
            $this->assertContains('external_rivals_unlock_attempted', $case['invalid_if']);
            $this->assertSame(
                AtlasForgeRivalsProviderArenaCorpusService::CLAIM_LEVEL_CASE_RESULT_ONLY,
                $case['claim_level'],
            );
            $blob = strtolower((string) json_encode($case, JSON_UNESCAPED_SLASHES));
            $this->assertStringNotContainsString('unlocks_external_rivals_certification', $blob);
        }
    }

    public function test_release_covers_all_eight_canonical_categories(): void
    {
        $coverage = [];
        foreach ($this->corpus->cases() as $case) {
            $coverage[(string) $case['category']] = true;
        }
        foreach (AtlasForgeRivalsProviderArenaCorpusService::TASK_CATEGORIES as $cat) {
            $this->assertArrayHasKey($cat, $coverage, "Release v1 não cobre a categoria canônica: {$cat}");
        }
    }

    public function test_frontend_case_set_returns_only_frontend_ui_cases(): void
    {
        $frontend = $this->corpus->casesForCaseSet('frontend');
        $this->assertNotEmpty($frontend);
        foreach ($frontend as $c) {
            $this->assertSame('frontend_ui', $c['category']);
            $this->assertStringStartsWith('frontend-', (string) $c['case_id']);
        }
    }

    public function test_backend_case_set_returns_only_backend_or_integration_performance_cases(): void
    {
        $backend = $this->corpus->casesForCaseSet('backend');
        $this->assertNotEmpty($backend);
        foreach ($backend as $c) {
            $this->assertContains(
                (string) $c['category'],
                ['backend_logic', 'integration_performance'],
                "Case {$c['case_id']} no backend set tem categoria inesperada {$c['category']}",
            );
        }
    }

    public function test_architecture_case_set_returns_only_architecture_or_refactor_cases(): void
    {
        $arch = $this->corpus->casesForCaseSet('architecture');
        $this->assertNotEmpty($arch);
        foreach ($arch as $c) {
            $this->assertContains($c['category'], ['architecture', 'refactor']);
        }
    }

    public function test_bugfix_case_set_returns_only_realistic_bugfix_primary_or_secondary(): void
    {
        $bugfix = $this->corpus->casesForCaseSet('bugfix');
        $this->assertNotEmpty($bugfix);
        foreach ($bugfix as $c) {
            $primaryOrSecondary = $c['category'] === 'realistic_bugfix'
                || in_array('realistic_bugfix', (array) $c['secondary_categories'], true);
            $this->assertTrue($primaryOrSecondary, "Caso {$c['case_id']} não é realistic_bugfix em primary nem secondary.");
        }
    }

    public function test_quick_case_set_has_exactly_three_cases_and_no_global_claim(): void
    {
        $quick = $this->corpus->casesForCaseSet('quick');
        $this->assertCount(3, $quick);
        foreach ($quick as $c) {
            $this->assertSame(
                AtlasForgeRivalsProviderArenaCorpusService::CLAIM_LEVEL_CASE_RESULT_ONLY,
                $c['claim_level'],
                "Caso {$c['case_id']} no quick declara claim global.",
            );
        }
    }

    public function test_release_case_set_includes_every_case(): void
    {
        $release = $this->corpus->casesForCaseSet('release');
        $this->assertCount(40, $release);
    }

    public function test_industrial_case_sets_have_required_sizes_and_metadata(): void
    {
        $expected = [
            'industrial-50' => 50,
            'industrial-100' => 100,
            'industrial-200' => 200,
            'ambiguous-bugs' => 50,
            'multi-day-refactors' => 50,
            'incident-response' => 50,
            'product-security-migrations' => 50,
            'statistical-repeat' => 60,
            'meta-provider-stress' => 50,
            'extreme-differentiator' => 80,
            'ceiling-360' => 120,
        ];

        foreach ($expected as $caseSet => $count) {
            $cases = $this->corpus->casesForCaseSet($caseSet);
            $this->assertCount($count, $cases, "{$caseSet} deve resolver para {$count} casos");
            $this->assertSame(
                array_values(array_unique(array_column($cases, 'case_id'))),
                array_values(array_column($cases, 'case_id')),
                "{$caseSet} não pode repetir case_id",
            );
            foreach ($cases as $case) {
                $this->assertSame('atlas-forge-rivals-industrial-benchmark-suite-v1', $case['industrial_suite']);
                $this->assertNotEmpty($case['industrial_domains']);
                $this->assertSame([], $this->corpus->validateManifest($case), "Industrial case inválido: {$case['case_id']}");
            }
        }
    }

    public function test_industrial_thematic_sets_preserve_domain_contract(): void
    {
        foreach ($this->corpus->casesForCaseSet('ambiguous-bugs') as $case) {
            $this->assertContains('ambiguous_bug', $case['industrial_domains']);
        }
        foreach ($this->corpus->casesForCaseSet('multi-day-refactors') as $case) {
            $this->assertContains('multi_day_task', $case['industrial_domains']);
        }
        foreach ($this->corpus->casesForCaseSet('incident-response') as $case) {
            $this->assertContains('incident_rollback', $case['industrial_domains']);
        }
        foreach ($this->corpus->casesForCaseSet('product-security-migrations') as $case) {
            $this->assertNotEmpty(array_intersect(
                ['product', 'security', 'migration'],
                (array) $case['industrial_domains'],
            ));
        }
    }

    public function test_every_case_exposes_human_ambiguous_prompt_and_context_profile(): void
    {
        foreach (array_merge(
            $this->corpus->casesForCaseSet('release'),
            $this->corpus->casesForCaseSet('meta-provider-stress'),
        ) as $case) {
            $this->assertArrayHasKey('human_prompt', $case);
            $this->assertStringContainsString('ticket real de engenharia', (string) $case['human_prompt']);
            $this->assertStringContainsString('contexto incompleto', (string) $case['human_prompt']);
            $this->assertStringContainsString('Nao toque em:', (string) $case['human_prompt']);
            $contextProfile = (array) ($case['context_profile'] ?? []);
            $this->assertSame('atlas.forge.rivals.context_profile.v1', $contextProfile['schema_version'] ?? null);
            $this->assertTrue((bool) ($contextProfile['requires_assumption_log'] ?? false));
            $this->assertGreaterThanOrEqual(3000, (int) ($contextProfile['estimated_context_tokens'] ?? 0));
            $this->assertGreaterThanOrEqual(2, (int) ($contextProfile['reasoning_depth'] ?? 0));
            $this->assertSame(
                'atlas.forge.rivals.case_complexity_profile.v1',
                $contextProfile['complexity_profile']['schema_version'] ?? null,
            );
            $this->assertContains('replayable_evidence_quality', (array) ($contextProfile['complexity_profile']['measured_dimensions'] ?? []));
            $this->assertContains('long_context', (array) $case['measurement_tags']);
            $this->assertContains('assumption_probe', (array) $case['measurement_tags']);
            $probe = (array) ($case['human_prompt_probe'] ?? []);
            $this->assertSame('atlas.forge.rivals.human_prompt_probe.v1', $probe['schema_version'] ?? null);
            $this->assertGreaterThanOrEqual(520, (int) ($probe['min_prompt_chars'] ?? 0));
            $this->assertGreaterThanOrEqual(3000, (int) ($probe['min_context_tokens'] ?? 0));
            foreach (['facts_observed', 'assumptions', 'reversible_decisions', 'scope_boundaries', 'evidence_plan', 'replay_matrix', 'tradeoffs', 'honest_blockers'] as $section) {
                $this->assertContains($section, (array) ($probe['requires_sections'] ?? []));
            }
        }
    }

    public function test_meta_provider_stress_case_set_targets_cursor_and_composer_surfaces(): void
    {
        $cases = $this->corpus->casesForCaseSet('meta-provider-stress');
        $coverage = $this->corpus->metaProviderStressCoverageSummary();

        $this->assertCount(50, $cases);
        $this->assertSame('atlas.forge.rivals.meta_provider_stress_coverage.v1', $coverage['schema_version']);
        $this->assertSame(50, $coverage['case_count']);
        $this->assertTrue($coverage['coverage_floor_met']);
        $this->assertGreaterThanOrEqual(8, $coverage['domain_count']);
        $this->assertGreaterThanOrEqual(10, $coverage['critical_or_high_risk_cases']);
        $this->assertGreaterThanOrEqual(8, $coverage['high_ambiguity_cases']);
        $this->assertSame(50, $coverage['long_context_cases']);
        $this->assertGreaterThanOrEqual(8, $coverage['rollback_plan_cases']);
        $this->assertSame(50, $coverage['multi_step_plan_cases']);
        $this->assertSame(50, $coverage['evidence_matrix_cases']);
        $this->assertGreaterThanOrEqual(4200, $coverage['min_estimated_context_tokens']);
        $this->assertGreaterThanOrEqual($coverage['min_estimated_context_tokens'], $coverage['max_estimated_context_tokens']);
        foreach (['ambiguous_bug', 'incomplete_requirements', 'multi_day_task', 'incident_rollback', 'security', 'product', 'integration', 'performance'] as $domain) {
            $this->assertArrayHasKey($domain, $coverage['domains']);
        }
        foreach ($cases as $case) {
            $this->assertSame('meta-provider-stress', $case['industrial_case_set']);
            $stress = (array) ($case['meta_provider_stress'] ?? []);
            $this->assertSame('atlas.forge.rivals.meta_provider_stress.v1', $stress['schema_version'] ?? null);
            $this->assertContains('cursor_cli', (array) ($stress['targets'] ?? []));
            $this->assertContains('composer_2_5', (array) ($stress['targets'] ?? []));
            $this->assertSame(
                'atlas.forge.rivals.case_complexity_profile.v1',
                $stress['complexity_profile']['schema_version'] ?? null,
            );
            $this->assertGreaterThanOrEqual(4200, (int) ($stress['measurement_floor']['min_context_tokens'] ?? 0));
            $this->assertGreaterThanOrEqual(3, (int) ($stress['measurement_floor']['min_reasoning_depth'] ?? 0));
            $this->assertTrue((bool) ($stress['measurement_floor']['requires_replay_matrix'] ?? false));
            $this->assertFalse((bool) ($stress['measurement_floor']['synthetic_claim_allowed'] ?? true));
            $this->assertContains('multi_step_reasoning', (array) ($stress['measures'] ?? []));
            $this->assertContains('stream_json_tool_events', (array) ($stress['cursor_meta_provider_expected_receipts'] ?? []));
            $this->assertContains('cursor_meta_provider', (array) $case['measurement_tags']);
            $this->assertSame([], $this->corpus->validateManifest($case), "Meta-provider stress case inválido: {$case['case_id']}");
        }
    }

    public function test_industrial_case_id_resolves_from_case_action_surface(): void
    {
        $case = $this->corpus->casesForCaseSet('ambiguous-bugs')[49];
        $resolved = $this->corpus->case((string) $case['case_id']);

        $this->assertSame($case['case_id'], $resolved['case_id']);
        $this->assertSame('ambiguous-bugs', $resolved['industrial_case_set']);
    }

    public function test_unknown_case_set_raises_invalid_argument(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/unknown_case_set/');
        $this->corpus->casesForCaseSet('totally-bogus');
    }

    public function test_individual_case_id_resolves_to_single_manifest(): void
    {
        foreach ($this->corpus->caseIds() as $caseId) {
            $resolved = $this->corpus->case($caseId);
            $this->assertSame($caseId, $resolved['case_id']);
        }
    }

    public function test_unknown_case_id_raises_invalid_argument(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/unknown_case_id/');
        $this->corpus->case('definitely-not-a-real-case');
    }

    public function test_content_hash_is_deterministic_across_calls(): void
    {
        $a = $this->corpus->contentHash();
        $b = $this->corpus->contentHash();
        $this->assertSame($a, $b, 'mesmo corpus precisa produzir o mesmo content hash');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $a);
    }

    public function test_snapshot_reports_correct_by_category_counts(): void
    {
        $snap = $this->corpus->snapshot();
        $this->assertSame(40, $snap['count']);
        $this->assertSame('release_v1', $snap['release_version']);
        $this->assertSame(AtlasForgeRivalsProviderArenaCorpusService::CASE_SETS, $snap['case_sets']);
        $this->assertSame(50, $snap['case_set_counts']['industrial-50']);
        $this->assertSame(100, $snap['case_set_counts']['industrial-100']);
        $this->assertSame(200, $snap['case_set_counts']['industrial-200']);
        $this->assertSame(50, $snap['case_set_counts']['ambiguous-bugs']);
        $this->assertSame(50, $snap['case_set_counts']['multi-day-refactors']);
        $this->assertSame(50, $snap['case_set_counts']['incident-response']);
        $this->assertSame(50, $snap['case_set_counts']['product-security-migrations']);
        $this->assertSame(60, $snap['case_set_counts']['statistical-repeat']);
        $this->assertSame(50, $snap['case_set_counts']['meta-provider-stress']);
        $this->assertSame(80, $snap['case_set_counts']['extreme-differentiator']);
        $this->assertSame(120, $snap['case_set_counts']['ceiling-360']);
        foreach (AtlasForgeRivalsProviderArenaCorpusService::TASK_CATEGORIES as $cat) {
            $this->assertArrayHasKey($cat, $snap['by_task_category']);
            $this->assertSame(5, $snap['by_task_category'][$cat], "Categoria {$cat} deve ter 5 cases (matriz 8x5)");
        }
    }

    public function test_extreme_differentiator_case_set_targets_hard_runner_separation(): void
    {
        $cases = $this->corpus->casesForCaseSet('extreme-differentiator');
        $highAmbiguityCases = 0;
        $capabilityCounts = [];
        $riskCounts = [];

        $this->assertCount(80, $cases);
        foreach ($cases as $case) {
            $this->assertSame('extreme-differentiator', $case['industrial_case_set']);
            $this->assertContains('extreme_differentiator', (array) $case['measurement_tags']);
            $this->assertContains('runner_strength_probe', (array) $case['measurement_tags']);
            $this->assertArrayHasKey('extreme_hardening', $case);
            $this->assertNotEmpty($case['measured_capabilities']);
            $this->assertSame('L5', $case['difficulty_level']);
            $this->assertSame(5.0, (float) $case['difficulty_score']);
            $this->assertSame('high', $case['ambiguity_level']);
            $this->assertContains($case['risk_level'], ['high', 'critical']);
            $this->assertStringContainsString('Ambiguidade percebida: high', (string) $case['human_prompt']);
            $this->assertStringContainsString('risco: '.(string) $case['risk_level'], (string) $case['human_prompt']);
            $this->assertSame('L5', $case['context_profile']['complexity_profile']['difficulty_level'] ?? null);
            $this->assertSame('high', $case['human_prompt_probe']['ambiguity_level'] ?? null);
            $this->assertSame('L5', $case['extreme_hardening']['hardened_difficulty_level'] ?? null);
            $riskCounts[(string) $case['risk_level']] = ($riskCounts[(string) $case['risk_level']] ?? 0) + 1;
            foreach ((array) $case['measured_capabilities'] as $capability) {
                $capabilityCounts[(string) $capability] = ($capabilityCounts[(string) $capability] ?? 0) + 1;
            }
            if (($case['ambiguity_level'] ?? null) === 'high') {
                $highAmbiguityCases++;
            }

            $profile = (array) ($case['extreme_differentiator'] ?? []);
            $this->assertSame('atlas.forge.rivals.extreme_differentiator.v1', $profile['schema_version'] ?? null);
            $this->assertSame('separate runner strengths when broad release batteries produce ties', $profile['purpose'] ?? null);
            $this->assertContains('codex_cli', (array) ($profile['targets'] ?? []));
            $this->assertContains('composer_2_5', (array) ($profile['targets'] ?? []));
            $this->assertContains('claude_code', (array) ($profile['targets'] ?? []));
            $this->assertTrue((bool) ($profile['required_signal']['requires_separation_analysis'] ?? false));
            $this->assertTrue((bool) ($profile['required_signal']['tie_is_diagnostic_not_claim'] ?? false));
            $this->assertSame(['L5'], (array) ($profile['required_signal']['allowed_difficulty_levels'] ?? []));
            $this->assertContains($case['difficulty_level'], (array) ($profile['required_signal']['allowed_difficulty_levels'] ?? []));
            $this->assertGreaterThanOrEqual(5, (int) ($profile['required_signal']['min_reasoning_depth'] ?? 0));
            $this->assertContains('honest_blocker_behavior', (array) ($profile['measures'] ?? []));
            $this->assertSame([], $this->corpus->validateManifest($case), "Extreme differentiator case inválido: {$case['case_id']}");
        }

        $this->assertSame(80, $highAmbiguityCases);
        $this->assertGreaterThanOrEqual(49, ($riskCounts['high'] ?? 0) + ($riskCounts['critical'] ?? 0));
        foreach ([
            'ambiguity_resolution',
            'long_context_retention',
            'multi_step_execution',
            'evidence_replay_completeness',
            'honest_blocker_behavior',
            'scope_boundary_probe',
            'rollback_safety',
            'security_fail_closed',
            'performance_tradeoff_quality',
            'ux_tradeoff_quality',
        ] as $capability) {
            $this->assertGreaterThanOrEqual(
                12,
                $capabilityCounts[$capability] ?? 0,
                "Extreme set precisa medir {$capability} pelo menos 12 vezes.",
            );
        }
    }

    public function test_ceiling_360_case_set_targets_maximum_runner_ceiling_mapping(): void
    {
        $cases = $this->corpus->casesForCaseSet('ceiling-360');
        $required = [
            'long_context_retention',
            'multi_step_reasoning',
            'rollback_safety',
            'scope_boundary_discipline',
            'replayable_evidence_quality',
            'honest_blocker_behavior',
            'ambiguous_human_prompt_handling',
            'adversarial_constraint_handling',
            'non_obvious_regression_detection',
            'uncertainty_boundary_quality',
            'production_invariant_reasoning',
            'capability_separation_signal',
        ];

        $this->assertCount(120, $cases);
        foreach ($cases as $case) {
            $this->assertSame('ceiling-360', $case['industrial_case_set']);
            $this->assertSame('L5', $case['difficulty_level']);
            $this->assertSame('critical', $case['risk_level']);
            $this->assertSame('high', $case['ambiguity_level']);
            $this->assertContains('ceiling_360', (array) $case['measurement_tags']);
            $this->assertContains('runner_ceiling_probe', (array) $case['measurement_tags']);
            $this->assertSame('atlas.forge.rivals.ceiling_360.v1', $case['ceiling_360']['schema_version'] ?? null);
            $this->assertSame(120, $case['ceiling_360']['required_signal']['min_cases'] ?? null);
            $this->assertSame('L5+', $case['ceiling_360']['required_signal']['pressure_level'] ?? null);
            $this->assertGreaterThanOrEqual(12000, (int) ($case['ceiling_360']['required_signal']['min_estimated_context_tokens'] ?? 0));
            $this->assertGreaterThanOrEqual(6, (int) ($case['ceiling_360']['required_signal']['min_reasoning_depth'] ?? 0));
            $this->assertTrue((bool) ($case['ceiling_360']['required_signal']['requires_all_360_capabilities'] ?? false));
            $this->assertTrue((bool) ($case['ceiling_360']['required_signal']['requires_adversarial_constraints'] ?? false));
            $this->assertTrue((bool) ($case['ceiling_360']['required_signal']['requires_non_obvious_regression_probe'] ?? false));
            $this->assertTrue((bool) ($case['ceiling_360']['required_signal']['requires_honest_uncertainty_boundary'] ?? false));
            $this->assertFalse((bool) ($case['ceiling_360']['claim_policy']['external_claim_allowed'] ?? true));
            $this->assertStringContainsString('Pressao L5+', (string) $case['human_prompt']);
            $this->assertStringContainsString('Facts Observed', (string) $case['human_prompt']);
            $this->assertStringContainsString('Replay/Negative Regression Probe', (string) $case['human_prompt']);
            $this->assertSame('atlas.forge.rivals.ceiling_pressure_profile.v1', $case['ceiling_pressure_profile']['schema_version'] ?? null);
            $this->assertSame('L5+', $case['ceiling_pressure_profile']['pressure_level'] ?? null);
            $this->assertTrue((bool) ($case['ceiling_pressure_profile']['advisory_only'] ?? false));
            $this->assertSame('none', $case['ceiling_pressure_profile']['routing_effect'] ?? null);
            foreach ([
                'facts_observed',
                'assumptions',
                'reversible_decisions',
                'tradeoff_matrix',
                'rollback_plan',
                'replay_negative_regression_probe',
                'production_invariants',
                'uncertainty_boundary',
                'capability_specific_evidence',
            ] as $requiredSection) {
                $this->assertContains($requiredSection, (array) ($case['ceiling_pressure_profile']['required_sections'] ?? []));
            }
            foreach ([
                'conflicting_constraints_analysis',
                'non_obvious_regression_probe',
                'production_invariant_reasoning',
                'rollback_and_replay_matrix',
                'honest_uncertainty_boundary',
                'capability_specific_self_evaluation',
            ] as $pressureRequirement) {
                $this->assertContains($pressureRequirement, (array) ($case['ceiling_pressure_profile']['requires'] ?? []));
            }
            foreach ([
                'adversarial_constraint_handling',
                'non_obvious_regression_detection',
                'uncertainty_boundary_quality',
                'production_invariant_reasoning',
                'capability_separation_signal',
            ] as $pressureDimension) {
                $this->assertContains($pressureDimension, (array) ($case['context_profile']['complexity_profile']['measured_dimensions'] ?? []));
            }
            $this->assertGreaterThanOrEqual(12000, (int) ($case['context_profile']['estimated_context_tokens'] ?? 0));
            $this->assertGreaterThanOrEqual(6, (int) ($case['context_profile']['reasoning_depth'] ?? 0));
            $this->assertSame(
                $case['context_profile']['complexity_profile']['scope_surface_count'] ?? null,
                $case['context_profile']['scope_surface_count'] ?? null,
            );
            $this->assertGreaterThanOrEqual(12000, (int) ($case['human_prompt_probe']['min_context_tokens'] ?? 0));
            $this->assertGreaterThanOrEqual(6, (int) ($case['human_prompt_probe']['min_reasoning_depth'] ?? 0));
            foreach ($required as $capability) {
                $this->assertContains($capability, (array) $case['measured_capabilities']);
            }
            $this->assertSame([], $this->corpus->validateManifest($case), "Ceiling-360 case inválido: {$case['case_id']}");
        }
    }

    public function test_validator_rejects_case_missing_required_field(): void
    {
        $bad = $this->corpus->case('backend-pagination-off-by-one');
        unset($bad['title']);
        $errors = $this->corpus->validateManifest($bad);
        $this->assertContains('missing_field:title', $errors);
    }

    public function test_validator_rejects_weights_that_do_not_sum_to_one(): void
    {
        $bad = $this->corpus->case('backend-pagination-off-by-one');
        $bad['quality_weights']['weights'] = ['minimal_diff' => 0.5, 'regression_prevention' => 0.2];
        $errors = $this->corpus->validateManifest($bad);
        $this->assertContains('quality_weights_do_not_sum_to_one', $errors);
    }

    public function test_validator_rejects_scope_that_touches_voice(): void
    {
        $bad = $this->corpus->case('frontend-form-validation-accessibility');
        $bad['allowed_files_scope'][] = 'atlas-desktop/src/voice/CaptureVoice.tsx';
        $errors = $this->corpus->validateManifest($bad);
        $this->assertContains('allowed_scope_touches_voice_or_cartografia', $errors);
    }

    public function test_validator_rejects_command_that_invokes_external_provider(): void
    {
        $bad = $this->corpus->case('backend-pagination-off-by-one');
        $bad['quick_test_command'] = 'curl https://api.anthropic.com/v1/messages';
        $errors = $this->corpus->validateManifest($bad);
        $this->assertNotEmpty(array_filter(
            $errors,
            static fn (string $e): bool => str_contains($e, 'invokes_external_provider'),
        ));
    }

    public function test_validator_rejects_claim_level_other_than_case_result_only(): void
    {
        $bad = $this->corpus->case('backend-pagination-off-by-one');
        $bad['claim_level'] = 'global_claim';
        $errors = $this->corpus->validateManifest($bad);
        $this->assertContains('claim_level_must_be_case_result_only:global_claim', $errors);
    }

    public function test_validator_rejects_invalid_if_missing_hard_gate(): void
    {
        $bad = $this->corpus->case('backend-pagination-off-by-one');
        $bad['invalid_if'] = array_values(array_filter(
            $bad['invalid_if'],
            static fn (string $g): bool => $g !== 'synthetic_score_admitted',
        ));
        $errors = $this->corpus->validateManifest($bad);
        $this->assertContains('invalid_if_missing_hard_gate:synthetic_score_admitted', $errors);
    }

    public function test_validator_rejects_secondary_category_outside_canon(): void
    {
        $bad = $this->corpus->case('backend-pagination-off-by-one');
        $bad['secondary_categories'] = ['nonsense_category'];
        $errors = $this->corpus->validateManifest($bad);
        $this->assertContains('secondary_category_not_in_canon:nonsense_category', $errors);
    }

    public function test_validator_rejects_fixture_seed_path_outside_canon_root(): void
    {
        $bad = $this->corpus->case('backend-pagination-off-by-one');
        $bad['fixture_seed_path'] = 'tmp/seed';
        $errors = $this->corpus->validateManifest($bad);
        $this->assertContains('fixture_seed_path_outside_canonical_root:tmp/seed', $errors);
    }

    public function test_backend_pagination_case_keeps_legacy_task_category_for_back_compat(): void
    {
        $case = $this->corpus->case('backend-pagination-off-by-one');
        $this->assertSame('realistic_bugfix', $case['category']);
        $this->assertSame('bugfix', $case['task_category'], 'legacy task_category preserva o nome antigo para downstream services');
        $this->assertSame('storage/forge-rivals-corpus/backend-pagination-off-by-one/seed', $case['setup_fixture']['seed_dir']);
    }

    private function repoRoot(): string
    {
        $cursor = __DIR__;
        for ($i = 0; $i < 10; $i++) {
            if (is_dir($cursor.'/storage/forge-rivals-corpus')) {
                return rtrim($cursor, '/');
            }
            $parent = dirname($cursor);
            if ($parent === $cursor) {
                break;
            }
            $cursor = $parent;
        }

        return rtrim(dirname(__DIR__, 6), '/');
    }
}
