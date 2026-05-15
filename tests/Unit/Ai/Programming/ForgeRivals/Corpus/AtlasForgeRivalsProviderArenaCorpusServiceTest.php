<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\ForgeRivals\Corpus;

use App\Services\Ai\Programming\ForgeRivals\Corpus\AtlasForgeRivalsProviderArenaCorpusService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Atlas Forge Rivals · Provider Arena Corpus Service — unit tests.
 *
 * Asserts the canonical 12-case corpus, the six case sets, the validator
 * contract and the snapshot shape. No artisan, no DB, no provider.
 */
final class AtlasForgeRivalsProviderArenaCorpusServiceTest extends TestCase
{
    private AtlasForgeRivalsProviderArenaCorpusService $corpus;

    protected function setUp(): void
    {
        parent::setUp();
        $this->corpus = new AtlasForgeRivalsProviderArenaCorpusService;
    }

    public function test_corpus_exposes_at_least_twelve_cases(): void
    {
        $cases = $this->corpus->cases();
        $this->assertGreaterThanOrEqual(12, count($cases), 'corpus deve declarar >= 12 casos canon');
    }

    public function test_every_case_has_all_sixteen_required_fields(): void
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

    public function test_every_case_has_non_empty_quick_test_command(): void
    {
        foreach ($this->corpus->cases() as $case) {
            $cmd = trim((string) ($case['quick_test_command'] ?? ''));
            $this->assertNotSame('', $cmd, "Caso {$case['case_id']} sem quick_test_command.");
        }
    }

    public function test_every_case_declares_quality_gates_weights(): void
    {
        foreach ($this->corpus->cases() as $case) {
            $this->assertArrayHasKey('quality_gates', $case, "Caso {$case['case_id']} sem quality_gates.");
            $gates = $case['quality_gates'];
            $this->assertIsArray($gates);
            $this->assertArrayHasKey('dimensions', $gates);
            $this->assertArrayHasKey('weights', $gates);
            $this->assertNotEmpty($gates['dimensions']);
            $sum = array_sum(array_map(static fn ($w): float => (float) $w, $gates['weights']));
            $this->assertEqualsWithDelta(1.0, $sum, 0.01, "Caso {$case['case_id']} weights não somam 1.0 (somam {$sum}).");
        }
    }

    public function test_quick_case_set_resolves_to_three_or_four_cases(): void
    {
        $quick = $this->corpus->casesForCaseSet('quick');
        $count = count($quick);
        $this->assertGreaterThanOrEqual(3, $count);
        $this->assertLessThanOrEqual(4, $count);
    }

    public function test_release_case_set_includes_every_case(): void
    {
        $release = $this->corpus->casesForCaseSet('release');
        $this->assertSame(count($this->corpus->cases()), count($release));
    }

    public function test_frontend_case_set_returns_only_frontend_cases(): void
    {
        $frontend = $this->corpus->casesForCaseSet('frontend');
        $this->assertNotEmpty($frontend);
        foreach ($frontend as $c) {
            $this->assertSame('frontend', $c['task_category']);
        }
    }

    public function test_backend_case_set_returns_only_backend_cases(): void
    {
        $backend = $this->corpus->casesForCaseSet('backend');
        $this->assertNotEmpty($backend);
        foreach ($backend as $c) {
            $this->assertSame('backend', $c['task_category']);
        }
    }

    public function test_bugfix_case_set_returns_only_bugfix_cases(): void
    {
        $bugfix = $this->corpus->casesForCaseSet('bugfix');
        $this->assertNotEmpty($bugfix);
        foreach ($bugfix as $c) {
            $this->assertSame('bugfix', $c['task_category']);
        }
    }

    public function test_architecture_case_set_includes_architecture_refactor_and_docs(): void
    {
        $arch = $this->corpus->casesForCaseSet('architecture');
        $this->assertNotEmpty($arch);
        $cats = [];
        foreach ($arch as $c) {
            $cats[(string) $c['task_category']] = true;
        }
        $this->assertTrue(isset($cats['architecture']));
        $this->assertTrue(isset($cats['refactor']));
        $this->assertTrue(isset($cats['docs']));
        foreach (array_keys($cats) as $cat) {
            $this->assertContains($cat, ['architecture', 'refactor', 'docs']);
        }
    }

    public function test_unknown_case_set_raises_invalid_argument(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/unknown_case_set/');
        $this->corpus->casesForCaseSet('not-a-real-set');
    }

    public function test_unknown_case_id_raises_invalid_argument(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/unknown_case_id/');
        $this->corpus->case('arena-does-not-exist');
    }

    public function test_snapshot_reports_correct_by_category_counts(): void
    {
        $snap = $this->corpus->snapshot();
        $this->assertSame(12, $snap['count']);
        $this->assertSame(2, $snap['by_task_category']['frontend']);
        $this->assertSame(2, $snap['by_task_category']['backend']);
        $this->assertSame(2, $snap['by_task_category']['bugfix']);
        $this->assertSame(2, $snap['by_task_category']['tests']);
        $this->assertSame(1, $snap['by_task_category']['refactor']);
        $this->assertSame(1, $snap['by_task_category']['architecture']);
        $this->assertSame(1, $snap['by_task_category']['docs']);
        $this->assertSame(1, $snap['by_task_category']['security']);
    }

    public function test_validator_rejects_case_missing_required_field(): void
    {
        $bad = $this->corpus->case('arena-frontend-button-loading-state');
        unset($bad['invalid_if']);
        $errors = $this->corpus->validateManifest($bad);
        $this->assertContains('missing_field:invalid_if', $errors);
    }

    public function test_validator_rejects_weights_that_do_not_sum_to_one(): void
    {
        $bad = $this->corpus->case('arena-frontend-button-loading-state');
        $bad['quality_gates']['weights'] = ['a' => 0.5, 'b' => 0.2];
        $errors = $this->corpus->validateManifest($bad);
        $this->assertContains('quality_gates_weights_do_not_sum_to_one', $errors);
    }

    public function test_validator_rejects_scope_that_touches_voice(): void
    {
        $bad = $this->corpus->case('arena-frontend-button-loading-state');
        $bad['allowed_files_scope'][] = 'atlas-desktop/src/voice/CaptureVoice.tsx';
        $errors = $this->corpus->validateManifest($bad);
        $this->assertContains('allowed_scope_touches_voice_or_cartografia', $errors);
    }
}
