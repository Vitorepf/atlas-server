<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainEvolutionDocAuthor;
use Tests\TestCase;

/**
 * Proves the doc author appends a dated section with all cycle fields to the
 * journal file and re-runs the grounding gate on cited_symbols.
 */
final class AtlasBrainEvolutionDocAuthorTest extends TestCase
{
    private string $tempRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempRoot = sys_get_temp_dir().'/atlas-brain-doc-author-'.uniqid('', true);
        @mkdir($this->tempRoot, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tempRoot);
        parent::tearDown();
    }

    public function test_append_writes_dated_section_with_all_cycle_fields(): void
    {
        $author = new AtlasBrainEvolutionDocAuthor;

        $filePath = $author->append('test-scope', [
            'cycle_n' => 42,
            'objective' => 'Wire the orphan AtlasFooService into BarPipeline',
            'how' => 'Compose the existing organs into a level classifier',
            'why' => 'The supervisor was a god-class; splitting concerns reduces blast radius',
            'value' => 'Maintainability + testability',
            'class' => 'evolucao',
            'magnitude' => '7.5',
            'cited_symbols' => ['App\\Services\\AtlasFooService', 'App\\Services\\BarPipeline'],
            'evidence' => ['frontier_gaps_detected:1', 'doc_stated_gap_match'],
            'seeded_packet_ids' => ['brain:abc123', 'brain:def456'],
        ], [], $this->tempRoot);

        self::assertSame($this->tempRoot.'/test-scope.md', $filePath);
        self::assertFileExists($filePath);

        $content = (string) file_get_contents($filePath);

        // Header with cycle_n and date.
        self::assertStringContainsString('## Cycle 42 —', $content);

        // All cycle fields present.
        self::assertStringContainsString('**Objective:** Wire the orphan AtlasFooService into BarPipeline', $content);
        self::assertStringContainsString('**How:** Compose the existing organs into a level classifier', $content);
        self::assertStringContainsString('**Why:** The supervisor was a god-class; splitting concerns reduces blast radius', $content);
        self::assertStringContainsString('**Value:** Maintainability + testability', $content);
        self::assertStringContainsString('**Class:** evolucao', $content);
        self::assertStringContainsString('**Magnitude:** 7.5', $content);
        self::assertStringContainsString('**Cited symbols:** App\\Services\\AtlasFooService, App\\Services\\BarPipeline', $content);
        self::assertStringContainsString('- frontier_gaps_detected:1', $content);
        self::assertStringContainsString('- doc_stated_gap_match', $content);
        self::assertStringContainsString('- brain:abc123', $content);
        self::assertStringContainsString('- brain:def456', $content);
        self::assertStringContainsString('---', $content);
    }

    public function test_append_is_idempotent_and_appends_multiple_sections(): void
    {
        $author = new AtlasBrainEvolutionDocAuthor;

        $author->append('multi-scope', [
            'cycle_n' => 1,
            'objective' => 'First cycle objective',
        ], [], $this->tempRoot);

        $author->append('multi-scope', [
            'cycle_n' => 2,
            'objective' => 'Second cycle objective',
        ], [], $this->tempRoot);

        $content = (string) file_get_contents($this->tempRoot.'/multi-scope.md');

        self::assertStringContainsString('## Cycle 1 —', $content);
        self::assertStringContainsString('**Objective:** First cycle objective', $content);
        self::assertStringContainsString('## Cycle 2 —', $content);
        self::assertStringContainsString('**Objective:** Second cycle objective', $content);
    }

    public function test_grounding_gate_is_run_on_cited_symbols(): void
    {
        $author = new AtlasBrainEvolutionDocAuthor;

        // Cited symbol that does NOT exist in the inventory should be flagged ungrounded.
        $filePath = $author->append('grounding-scope', [
            'cycle_n' => 1,
            'objective' => 'Test grounding',
            'cited_symbols' => ['App\\TotallyNonExistent\\HallucinatedService'],
        ], [
            ['fqcn' => 'App\\Real\\Service', 'rel_path' => 'app/Real/Service.php'],
        ], $this->tempRoot);

        $content = (string) file_get_contents($filePath);
        // The grounding gate flagged the ungrounded symbol.
        self::assertStringContainsString('grounding:', $content);
        self::assertStringContainsString('HallucinatedService', $content);
    }

    public function test_grounding_passes_when_cited_symbol_in_inventory(): void
    {
        $author = new AtlasBrainEvolutionDocAuthor;

        $filePath = $author->append('grounded-scope', [
            'cycle_n' => 1,
            'objective' => 'Test grounded cite',
            'cited_symbols' => ['App\\Real\\Service'],
        ], [
            ['fqcn' => 'App\\Real\\Service', 'rel_path' => 'app/Real/Service.php'],
        ], $this->tempRoot);

        $content = (string) file_get_contents($filePath);
        // No grounding annotation (all symbols resolved).
        self::assertStringNotContainsString('grounding:', $content);
    }

    public function test_append_returns_journal_file_path(): void
    {
        $author = new AtlasBrainEvolutionDocAuthor;

        $path = $author->append('path-test', [
            'cycle_n' => 1,
            'objective' => 'x',
        ], [], $this->tempRoot);

        self::assertSame($this->tempRoot.'/path-test.md', $path);
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir.'/'.$entry;
            if (is_dir($full)) {
                $this->rrmdir($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($dir);
    }
}
