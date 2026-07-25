<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Support;

use App\Services\Ai\SelfConstruction\Support\TaskFabricTemplateFarmSignalSupport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pure-unit lock for {@see TaskFabricTemplateFarmSignalSupport}
 * (string/array-only; no FS / DI / I/O).
 */
final class TaskFabricTemplateFarmSignalSupportTest extends TestCase
{
    #[Test]
    public function extract_stem_strips_class_names_and_caps_word_count(): void
    {
        // CLASS_NAME_PATTERN also strips CapWords ≥5 chars (e.g. "Implement"), so the
        // remaining stem is the lower-signal body after those tokens are removed.
        $stem = TaskFabricTemplateFarmSignalSupport::extractStem(
            'Implement AtlasFooBar to compute a score and return a result for the task pipeline',
        );

        $this->assertStringNotContainsString('atlasfoobar', strtolower($stem));
        $this->assertStringNotContainsString('implement', $stem);
        $this->assertStringContainsString('compute', $stem);
        $this->assertStringContainsString('score', $stem);
        $words = $stem === '' ? [] : explode(' ', $stem);
        $this->assertLessThanOrEqual(TaskFabricTemplateFarmSignalSupport::STEM_WORD_COUNT, count($words));
        $this->assertSame(
            $stem,
            TaskFabricTemplateFarmSignalSupport::extractStem(
                'Implement AtlasBazQux to compute a score and return a result for the task pipeline',
            ),
            'Different class names with the same body collapse to the same stem',
        );
    }

    #[Test]
    public function extract_fragment_normalizes_and_truncates(): void
    {
        $frag = TaskFabricTemplateFarmSignalSupport::extractFragment(
            'The AtlasFooService MUST reject packets that fail validation.',
        );

        $this->assertStringNotContainsString('AtlasFooService', $frag);
        $this->assertStringContainsString('must reject', $frag);
        $this->assertLessThanOrEqual(60, strlen($frag));
    }

    #[Test]
    public function extract_allowed_files_shape_strips_basename_keeps_dir_ext(): void
    {
        $shape = TaskFabricTemplateFarmSignalSupport::extractAllowedFilesShape([
            'app/Services/Ai/SelfConstruction/AtlasVariant0.php',
            'tests/Unit/Ai/SelfConstruction/AtlasVariant0Test.php',
        ]);

        $this->assertNotNull($shape);
        $this->assertStringContainsString('app/Services/Ai/SelfConstruction:php', $shape);
        $this->assertStringContainsString('tests/Unit/Ai/SelfConstruction:php', $shape);
        $this->assertStringNotContainsString('AtlasVariant0', $shape);
    }

    #[Test]
    public function extract_allowed_files_shape_returns_null_for_empty_or_blank_entries(): void
    {
        $this->assertNull(TaskFabricTemplateFarmSignalSupport::extractAllowedFilesShape([]));
        $this->assertNull(TaskFabricTemplateFarmSignalSupport::extractAllowedFilesShape(['']));
    }

    #[Test]
    public function extract_proof_paths_redacts_filter_token_and_normalizes(): void
    {
        $paths = TaskFabricTemplateFarmSignalSupport::extractProofPaths([
            'Running php artisan test --filter=AtlasVariant0Test exits 0.',
            'given a missing owner the system produces an unblock action',
        ]);

        $this->assertCount(1, $paths);
        $this->assertStringContainsString('--filter=', $paths[0]);
        $this->assertStringNotContainsString('AtlasVariant0Test', $paths[0]);
        $this->assertStringContainsString('php artisan test', $paths[0]);
    }

    #[Test]
    public function extract_acceptance_verbs_pulls_modal_followers(): void
    {
        $verbs = TaskFabricTemplateFarmSignalSupport::extractAcceptanceVerbs([
            'the gate must reject packets that fail validation',
            'the compressor shall reduce size by thirty percent',
            'no modal here at all',
        ]);

        $this->assertSame(['reject', 'reduce'], $verbs);
    }

    #[Test]
    public function extract_template_signatures_masks_each_word_for_stems_of_three_plus(): void
    {
        $sigs = TaskFabricTemplateFarmSignalSupport::extractTemplateSignatures(
            'implement service to validate user accounts thoroughly',
        );

        $this->assertNotEmpty($sigs);
        $this->assertContains('* service to validate user accounts thoroughly', $sigs);
        $this->assertContains('implement service to validate * accounts thoroughly', $sigs);
        $this->assertSame([], TaskFabricTemplateFarmSignalSupport::extractTemplateSignatures(''));
        $this->assertSame([], TaskFabricTemplateFarmSignalSupport::extractTemplateSignatures('too short'));
    }

    #[Test]
    public function mechanism_hash_is_stable_for_same_stem_and_sorted_proof_paths(): void
    {
        $a = TaskFabricTemplateFarmSignalSupport::mechanismHash('implement to compute score', ['path-b', 'path-a']);
        $b = TaskFabricTemplateFarmSignalSupport::mechanismHash('implement to compute score', ['path-a', 'path-b']);
        $c = TaskFabricTemplateFarmSignalSupport::mechanismHash('different stem', ['path-a', 'path-b']);

        $this->assertSame($a, $b);
        $this->assertNotSame($a, $c);
        $this->assertSame(64, strlen($a));
    }

    #[Test]
    public function repeated_signal_ratio_ignores_null_and_empty(): void
    {
        [$repeated, $count] = TaskFabricTemplateFarmSignalSupport::repeatedSignalRatio([
            'shape-a',
            null,
            '',
            'shape-a',
            'shape-b',
        ]);

        $this->assertSame(['shape-a'], $repeated);
        $this->assertSame(2, $count);
    }

    #[Test]
    public function repeated_multi_signal_ratio_counts_packets_with_any_shared_value(): void
    {
        [$repeated, $count] = TaskFabricTemplateFarmSignalSupport::repeatedMultiSignalRatio([
            ['must', 'reject'],
            ['must', 'emit'],
            ['emit'],
            ['unique-only'],
        ]);

        $this->assertContains('must', $repeated);
        $this->assertContains('emit', $repeated);
        $this->assertNotContains('unique-only', $repeated);
        // packets 0,1,2 each share at least one repeated value
        $this->assertSame(3, $count);
    }

    #[Test]
    public function replacement_hint_mentions_mechanism_when_hashes_present(): void
    {
        $withHash = TaskFabricTemplateFarmSignalSupport::replacementHint(['abc123']);
        $without = TaskFabricTemplateFarmSignalSupport::replacementHint([]);

        $this->assertStringContainsString('mechanism hash', $withHash);
        $this->assertStringContainsString('disguised template farm', $without);
        $this->assertStringContainsString('different leverage mechanism', $withHash);
        $this->assertStringContainsString('different leverage mechanism', $without);
    }

    #[Test]
    public function extract_proof_path_fragment_redacts_filter_before_fragment_norm(): void
    {
        $frag = TaskFabricTemplateFarmSignalSupport::extractProofPathFragment(
            'php artisan test --filter=AtlasVariant0Test exits 0',
        );

        $this->assertStringContainsString('--filter=', $frag);
        $this->assertStringNotContainsString('AtlasVariant0Test', $frag);
        $this->assertStringNotContainsString('Variant0', $frag);
    }
}
