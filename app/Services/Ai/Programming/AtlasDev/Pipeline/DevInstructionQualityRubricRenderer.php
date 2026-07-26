<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Pipeline;

/**
 * Pure, deterministic renderer: produces a short self-check rubric grounded in THIS workcell's
 * facts (allowed files, likely callers, verification command). Each rubric item mirrors one
 * finding id from the diff-quality amplifier gate so the model self-checks against the SAME
 * criteria the gate will score.
 *
 * Finding-id → rubric-item mapping:
 *   diff_minimality        → "Touch only the allowed files; no drive-by refactors or formatting churn."
 *   caller_coverage        → "Verify every caller of the changed symbol still works."
 *   test_relevance         → "Run the verification command; every assertion must be about this change."
 *   scope_respect          → "Do not edit files outside the allowed list."
 *   wip_protection         → "Preserve other workers' WIP; never git add -A / reset / checkout / stash / pull / push."
 *   overengineering_smell  → "Solve the stated problem with the smallest diff; no new abstractions."
 *
 * Rendering only: zero provider calls, zero I/O, zero side effects.
 */
final class DevInstructionQualityRubricRenderer
{
    /** @var list<string> finding ids in fixed order */
    private const FINDING_IDS = [
        'diff_minimality',
        'caller_coverage',
        'test_relevance',
        'scope_respect',
        'wip_protection',
        'overengineering_smell',
    ];

    /**
     * @param  array<string,mixed>  $workcell  one entry from DevWorkcellDecomposer::decompose()['workcells']
     * @return array{rubric_text: string, rubric_items: list<array{finding_id: string, text: string}>}
     */
    public function render(array $workcell): array
    {
        $allowedFiles = array_values(array_filter(array_map('strval', (array) ($workcell['allowed_files'] ?? [])), static fn (string $f): bool => $f !== ''));
        $verificationCommand = trim((string) ($workcell['verification_command'] ?? ''));
        $objective = trim((string) ($workcell['objective_slice'] ?? ''));

        // If the workcell has no facts to ground the rubric, render nothing.
        // This keeps assembler output byte-identical for rubric-less calls.
        if ($allowedFiles === [] && $verificationCommand === '' && $objective === '') {
            return [
                'rubric_text' => '',
                'rubric_items' => [],
            ];
        }

        $items = [];

        // diff_minimality
        $items[] = [
            'finding_id' => 'diff_minimality',
            'text' => $allowedFiles !== []
                ? 'Touch only: '.implode(', ', $allowedFiles).'. No drive-by refactors or formatting churn.'
                : 'Touch only the allowed files; no drive-by refactors or formatting churn.',
        ];

        // caller_coverage
        $items[] = [
            'finding_id' => 'caller_coverage',
            'text' => $allowedFiles !== []
                ? 'Verify every caller of symbols in '.implode(', ', array_slice($allowedFiles, 0, 3)).' still works.'
                : 'Verify every caller of the changed symbol still works.',
        ];

        // test_relevance
        $items[] = [
            'finding_id' => 'test_relevance',
            'text' => $verificationCommand !== ''
                ? "Run: {$verificationCommand}. Every assertion must be about this change."
                : 'Run the verification command; every assertion must be about this change.',
        ];

        // scope_respect
        $items[] = [
            'finding_id' => 'scope_respect',
            'text' => $allowedFiles !== []
                ? 'Do not edit files outside: '.implode(', ', $allowedFiles).'.'
                : 'Do not edit files outside the allowed list.',
        ];

        // wip_protection
        $items[] = [
            'finding_id' => 'wip_protection',
            'text' => 'Preserve other workers\' WIP; never git add -A / reset / checkout / stash / pull / push.',
        ];

        // overengineering_smell
        $items[] = [
            'finding_id' => 'overengineering_smell',
            'text' => $objective !== ''
                ? "Solve \"{$objective}\" with the smallest diff; no new abstractions."
                : 'Solve the stated problem with the smallest diff; no new abstractions.',
        ];

        $rubricText = $items !== []
            ? "SELF-CHECK RUBRIC\n".implode("\n", array_map(static fn (array $item): string => "- {$item['text']}", $items))
            : '';

        return [
            'rubric_text' => $rubricText,
            'rubric_items' => $items,
        ];
    }
}
