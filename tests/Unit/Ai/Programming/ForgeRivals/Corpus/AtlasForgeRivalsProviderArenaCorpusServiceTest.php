<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\ForgeRivals\Corpus;

use App\Services\Ai\Programming\ForgeRivals\Corpus\AtlasForgeRivalsProviderArenaCorpusService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Atlas Forge Rivals · Provider Arena Corpus Service — unit tests.
 *
 * Cobre os 22 campos canon do Release v1, as 8 categorias primárias, os 6
 * case sets, o validador de manifest, o snapshot e o content hash.
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
        $this->assertSame(
            ['quick', 'release', 'frontend', 'backend', 'bugfix', 'architecture'],
            $snap['case_sets'],
        );
        foreach (AtlasForgeRivalsProviderArenaCorpusService::TASK_CATEGORIES as $cat) {
            $this->assertArrayHasKey($cat, $snap['by_task_category']);
            $this->assertSame(5, $snap['by_task_category'][$cat], "Categoria {$cat} deve ter 5 cases (matriz 8x5)");
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
