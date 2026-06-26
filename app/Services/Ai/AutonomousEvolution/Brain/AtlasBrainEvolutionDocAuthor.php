<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\Verify\AtlasLoopComprehensionGroundingGate;

/**
 * Appends a dated cycle section to the loop-evolution journal, then re-runs the
 * comprehension grounding gate against the cited_symbols.
 *
 * Mirrors the structured-envelope-then-write-file pattern of
 * {@see \App\Services\Ai\AutonomousEvolution\AtlasLoopAutoArchitectureProposalService::writeReceipt()}.
 *
 * The journal file lives at docs/loop-evolution-journal/<scopeSlug>.md and is
 * appended-to (created if missing). Each section records the cycle fields:
 * {cycle_n, objective, how, why, value, class, magnitude, cited_symbols,
 * evidence, seeded_packet_ids}. After writing, the grounding gate is re-run
 * on the cited_symbols against the optional inventory (or empty set).
 */
final class AtlasBrainEvolutionDocAuthor
{
    public const SCHEMA_VERSION = 'atlas.brain.evolution_doc_author.v1';

    public function __construct(
        private readonly ?AtlasLoopComprehensionGroundingGate $groundingGate = null,
    ) {}

    /**
     * @param  array<string,mixed>  $cycle  {cycle_n, objective, how, why, value, class, magnitude, cited_symbols, evidence, seeded_packet_ids}
     * @param  list<array{fqcn:string,rel_path:string}>  $inventory  optional inventory for grounding
     * @param  string  $journalRoot  override the journal directory (for testing)
     * @return string  the journal file path
     */
    public function append(string $scopeSlug, array $cycle, array $inventory = [], string $journalRoot = 'docs/loop-evolution-journal'): string
    {
        $scopeSlug = trim($scopeSlug);
        if ($scopeSlug === '') {
            $scopeSlug = 'default';
        }

        $filePath = rtrim($journalRoot, '/').'/'.$scopeSlug.'.md';
        $this->ensureDirectory(dirname($filePath));

        $section = $this->renderSection($cycle);
        $existing = is_file($filePath) ? (string) @file_get_contents($filePath) : '';
        $content = $existing;
        if ($content !== '' && ! str_ends_with($content, "\n")) {
            $content .= "\n";
        }
        $content .= $section;

        @file_put_contents($filePath, $content);

        // Re-run the comprehension grounding gate against the cited_symbols.
        $citedSymbols = (array) ($cycle['cited_symbols'] ?? []);
        $gate = $this->groundingGate ?? new AtlasLoopComprehensionGroundingGate;
        $grounding = $gate->groundAgainstInventory(
            (string) ($cycle['objective'] ?? ''),
            $citedSymbols,
            $inventory,
        );

        // Annotate the section with the grounding result.
        $refuted = $grounding['refuted'] ?? [];
        if ($refuted !== [] || ($grounding['grounded'] ?? true) === false) {
            $flagged = $refuted !== [] ? $refuted : ['grounding_failed'];
            $annotation = "\n<!-- grounding: ".implode(', ', array_map('strval', $flagged))." ungrounded -->\n";
            @file_put_contents($filePath, $annotation, FILE_APPEND);
        }

        return $filePath;
    }

    /**
     * @param  array<string,mixed>  $cycle
     */
    private function renderSection(array $cycle): string
    {
        $date = date('Y-m-d');
        $cycleN = (int) ($cycle['cycle_n'] ?? 0);
        $objective = (string) ($cycle['objective'] ?? '');
        $how = (string) ($cycle['how'] ?? '');
        $why = (string) ($cycle['why'] ?? '');
        $value = (string) ($cycle['value'] ?? '');
        $class = (string) ($cycle['class'] ?? '');
        $magnitude = (string) ($cycle['magnitude'] ?? '');
        $citedSymbols = array_values(array_filter(
            (array) ($cycle['cited_symbols'] ?? []),
            static fn ($s): bool => is_string($s) && trim($s) !== '',
        ));
        $evidence = array_values(array_filter(
            (array) ($cycle['evidence'] ?? []),
            static fn ($e): bool => is_string($e) && trim($e) !== '',
        ));
        $seededPacketIds = array_values(array_filter(
            (array) ($cycle['seeded_packet_ids'] ?? []),
            static fn ($p): bool => is_string($p) && trim($p) !== '',
        ));

        $lines = [];
        $lines[] = '## Cycle '.$cycleN.' — '.$date;
        $lines[] = '';
        $lines[] = '**Objective:** '.$objective;
        if ($how !== '') {
            $lines[] = '';
            $lines[] = '**How:** '.$how;
        }
        if ($why !== '') {
            $lines[] = '';
            $lines[] = '**Why:** '.$why;
        }
        if ($value !== '') {
            $lines[] = '';
            $lines[] = '**Value:** '.$value;
        }
        if ($class !== '') {
            $lines[] = '';
            $lines[] = '**Class:** '.$class;
        }
        if ($magnitude !== '') {
            $lines[] = '';
            $lines[] = '**Magnitude:** '.$magnitude;
        }
        if ($citedSymbols !== []) {
            $lines[] = '';
            $lines[] = '**Cited symbols:** '.implode(', ', $citedSymbols);
        }
        if ($evidence !== []) {
            $lines[] = '';
            $lines[] = '**Evidence:**';
            foreach ($evidence as $e) {
                $lines[] = '  - '.$e;
            }
        }
        if ($seededPacketIds !== []) {
            $lines[] = '';
            $lines[] = '**Seeded packet IDs:**';
            foreach ($seededPacketIds as $pid) {
                $lines[] = '  - '.$pid;
            }
        }
        $lines[] = '';
        $lines[] = '---';
        $lines[] = '';

        return implode("\n", $lines);
    }

    private function ensureDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }
}
