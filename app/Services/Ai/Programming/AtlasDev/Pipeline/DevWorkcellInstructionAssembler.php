<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Pipeline;

/**
 * Pure, deterministic renderer: assembles everything the fast-path pipeline already produced
 * (a decomposed workcell, the budget-distilled context, the mini spec, and green-run exemplars)
 * into ONE short, imperative operational instruction a model actually executes well.
 *
 * RENDER ORDER (fixed, sections with nothing to say are simply omitted):
 *   1. objective slice        — workcell.objective_slice
 *   2. allowed files          — workcell.allowed_files
 *   3. design path + reason   — spec.canonical_context entry where kind === 'design_path'
 *   4. acceptance criteria    — workcell.acceptance_criteria, rendered as runnable commands
 *      (falls back to workcell.verification_command when no criterion carries its own command)
 *   5. distilled context      — distilledContext.sections, in distilledContext.included_labels order
 *   6. green-run exemplars    — up to the exemplars the caller supplies
 *
 * Rendering only: zero provider calls, zero I/O, zero side effects. Identical input always
 * produces byte-identical output.
 */
final class DevWorkcellInstructionAssembler
{
    public const SCHEMA = 'atlas.dev.workcell_instruction_assembler.v1';

    /**
     * @param  array<string,mixed>  $workcell  one entry from DevWorkcellDecomposer::decompose()['workcells']
     * @param  array<string,mixed>  $distilledContext  DevContextBudgetDistiller::distill() output
     * @param  array<string,mixed>  $spec  the mini spec canonical array (MiniProgrammingSpec::toCanonicalArray())
     * @param  list<array<string,mixed>>  $exemplars  DevGreenRunExemplarRetriever::retrieve() output
     * @return array{instruction_text:string, sections:list<string>, char_count:int}
     */
    public function assemble(array $workcell, array $distilledContext, array $spec, array $exemplars): array
    {
        $blocks = [];

        $blocks['objective'] = $this->renderObjective($workcell);
        $blocks['allowed_files'] = $this->renderAllowedFiles($workcell);
        $blocks['design_path'] = $this->renderDesignPath($spec);
        $blocks['acceptance_criteria'] = $this->renderAcceptanceCriteria($workcell);
        $blocks['rubric'] = $this->renderRubric($workcell);
        foreach ($this->renderDistilledContext($distilledContext) as $label => $text) {
            $blocks['context:'.$label] = $text;
        }
        $blocks['exemplars'] = $this->renderExemplars($exemplars);

        // Drop sections with nothing to render while preserving fixed order.
        $blocks = array_filter($blocks, static fn (string $b): bool => trim($b) !== '');

        $instructionText = implode("\n\n", $blocks);

        return [
            'instruction_text' => $instructionText,
            'sections' => array_values(array_keys($blocks)),
            'char_count' => mb_strlen($instructionText),
        ];
    }

    /** @param  array<string,mixed>  $workcell */
    private function renderObjective(array $workcell): string
    {
        $objective = trim((string) ($workcell['objective_slice'] ?? ''));

        return $objective === '' ? '' : "OBJECTIVE\n{$objective}";
    }

    /** @param  array<string,mixed>  $workcell */
    private function renderAllowedFiles(array $workcell): string
    {
        $files = array_values(array_filter(array_map('strval', (array) ($workcell['allowed_files'] ?? [])), static fn (string $f): bool => $f !== ''));
        if ($files === []) {
            return '';
        }

        return "ALLOWED FILES\n".implode("\n", array_map(static fn (string $f): string => "- {$f}", $files));
    }

    /** @param  array<string,mixed>  $spec */
    private function renderDesignPath(array $spec): string
    {
        $entries = (array) ($spec['canonical_context'] ?? []);
        foreach ($entries as $entry) {
            if (! is_array($entry) || (string) ($entry['kind'] ?? '') !== 'design_path') {
                continue;
            }
            $ref = trim((string) ($entry['ref'] ?? ''));
            if ($ref === '') {
                return '';
            }
            $reason = trim((string) ($entry['reason'] ?? ''));
            $text = "DESIGN PATH\nFollow: {$ref}";

            return $reason !== '' ? "{$text}\nReason: {$reason}" : $text;
        }

        return '';
    }

    /** @param  array<string,mixed>  $workcell */
    private function renderAcceptanceCriteria(array $workcell): string
    {
        $lines = [];
        $hasCommand = false;
        foreach ((array) ($workcell['acceptance_criteria'] ?? []) as $criterion) {
            if (is_array($criterion)) {
                $command = trim((string) ($criterion['verification'] ?? ''));
                $description = trim((string) ($criterion['description'] ?? ''));
                if ($command !== '') {
                    $hasCommand = true;
                    $lines[] = $description !== '' ? "- Run: {$command} ({$description})" : "- Run: {$command}";
                } elseif ($description !== '') {
                    $lines[] = "- {$description}";
                }

                continue;
            }
            $text = trim((string) $criterion);
            if ($text !== '') {
                $lines[] = "- {$text}";
            }
        }

        // No criterion carried its own runnable command -- fall back to the workcell's single
        // verification_command so the instruction never omits how to prove the work.
        $fallbackCommand = trim((string) ($workcell['verification_command'] ?? ''));
        if (! $hasCommand && $fallbackCommand !== '') {
            $lines[] = "- Run: {$fallbackCommand}";
        }

        return $lines === [] ? '' : "ACCEPTANCE CRITERIA\n".implode("\n", $lines);
    }

    /** @param  array<string,mixed>  $workcell */
    private function renderRubric(array $workcell): string
    {
        $renderer = new DevInstructionQualityRubricRenderer;
        $result = $renderer->render($workcell);

        return $result['rubric_text'];
    }

    /**
     * @param  array<string,mixed>  $distilledContext
     * @return array<string,string> label => rendered block, in included_labels order
     */
    private function renderDistilledContext(array $distilledContext): array
    {
        $sections = (array) ($distilledContext['sections'] ?? []);
        $order = array_values(array_map('strval', (array) ($distilledContext['included_labels'] ?? array_keys($sections))));

        $out = [];
        foreach ($order as $label) {
            $content = trim((string) ($sections[$label] ?? ''));
            if ($content === '') {
                continue;
            }
            $heading = strtoupper(str_replace('_', ' ', $label));
            $out[$label] = "{$heading}\n{$content}";
        }

        return $out;
    }

    /** @param  list<array<string,mixed>>  $exemplars */
    private function renderExemplars(array $exemplars): string
    {
        $lines = [];
        foreach ($exemplars as $exemplar) {
            if (! is_array($exemplar)) {
                continue;
            }
            $runId = trim((string) ($exemplar['run_id'] ?? ''));
            if ($runId === '') {
                continue;
            }
            $parts = ["run {$runId}"];
            // The objective is what makes an exemplar teach anything: a
            // model can imitate "did X on files Y verified via Z", not an
            // opaque run id.
            $objective = trim((string) ($exemplar['objective_excerpt'] ?? ''));
            if ($objective !== '') {
                $parts[] = "did \"{$objective}\"";
            }
            $designPath = trim((string) ($exemplar['design_path'] ?? ''));
            if ($designPath !== '') {
                $parts[] = "path={$designPath}";
            }
            $files = array_values(array_filter(array_map(
                static fn (mixed $f): string => trim((string) $f),
                (array) ($exemplar['files_touched'] ?? []),
            )));
            if ($files !== []) {
                $parts[] = 'files='.implode(' ', array_slice($files, 0, 5));
            }
            $command = trim((string) ($exemplar['verification_command'] ?? ''));
            if ($command !== '') {
                $parts[] = "verified via {$command}";
            }
            $lines[] = '- '.implode(', ', $parts);
        }

        return $lines === [] ? '' : "GREEN-RUN EXEMPLARS\n".implode("\n", $lines);
    }
}
