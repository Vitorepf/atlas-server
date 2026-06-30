<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure deterministic assembler for scaffolded-model task-origination prompts.
 * Combines evidence intake, reasoning scaffold, anti-duplication checks, and
 * output contract into one structured prompt without calling providers.
 *
 * AC3 — fail-closed when:
 *   - allow_direct_final_answer = true  (free-form answer must never be emitted)
 *   - evidence_intake is empty          (scaffold needs evidence to reason over)
 *
 * AC2 — on success, output includes:
 *   schema_version, prompt_sections (ordered), required_artifacts,
 *   anti_duplication_checks, max_context_budget_chars
 *
 * AC4 — pure PHP, no provider calls, no filesystem writes, no Artisan calls,
 *        no operator dependency.
 */
final class AtlasExternalBrainScaffoldedPromptAssembler
{
    public const SCHEMA_VERSION = 'atlas.external_brain.scaffolded_prompt_assembler.v1';

    private const DEFAULT_CONTEXT_BUDGET_CHARS = 8_000;

    // Ordered prompt section names
    private const SECTION_EVIDENCE       = 'evidence_intake';
    private const SECTION_SCAFFOLD       = 'reasoning_scaffold';
    private const SECTION_DEDUP          = 'anti_duplication_check';
    private const SECTION_OUTPUT_CONTRACT = 'output_contract';

    private const REQUIRED_ARTIFACTS = [
        'task_spec_with_allowed_files',
        'acceptance_criteria_runnable',
        'implementation_notes',
    ];

    /**
     * @param  array{
     *   model_tier?: string,
     *   evidence_intake?: list<array<string,mixed>>,
     *   queued_targets?: list<string>,
     *   allow_direct_final_answer?: bool,
     *   context_budget_chars?: int,
     * }  $input
     * @return array{schema_version:string, assembled:bool, failure_reason:string|null, prompt_sections:list<array<string,string>>, required_artifacts:list<string>, anti_duplication_checks:list<string>, max_context_budget_chars:int}
     */
    public function assemble(array $input): array
    {
        $allowDirectAnswer = (bool)  ($input['allow_direct_final_answer'] ?? false);
        $evidenceIntake    = (array) ($input['evidence_intake']           ?? []);
        $queuedTargets     = (array) ($input['queued_targets']            ?? []);
        $budgetChars       = max(1, (int) ($input['context_budget_chars'] ?? self::DEFAULT_CONTEXT_BUDGET_CHARS));

        $failureReason = $this->failCloseReason($allowDirectAnswer, $evidenceIntake);
        if ($failureReason !== null) {
            return $this->failed($failureReason, $budgetChars);
        }

        $sections = [
            [
                'section' => self::SECTION_EVIDENCE,
                'content' => $this->renderEvidence($evidenceIntake, $budgetChars),
            ],
            [
                'section' => self::SECTION_SCAFFOLD,
                'content' => $this->renderScaffold(),
            ],
            [
                'section' => self::SECTION_DEDUP,
                'content' => $this->renderDedup($queuedTargets),
            ],
            [
                'section' => self::SECTION_OUTPUT_CONTRACT,
                'content' => $this->renderOutputContract(),
            ],
        ];

        return [
            'schema_version'           => self::SCHEMA_VERSION,
            'assembled'                => true,
            'failure_reason'           => null,
            'prompt_sections'          => $sections,
            'required_artifacts'       => self::REQUIRED_ARTIFACTS,
            'anti_duplication_checks'  => array_values($queuedTargets),
            'max_context_budget_chars' => $budgetChars,
        ];
    }

    private function failCloseReason(bool $allowDirectAnswer, array $evidence): ?string
    {
        if ($allowDirectAnswer) {
            return 'fail_closed:allow_direct_final_answer_is_true';
        }
        if ($evidence === []) {
            return 'fail_closed:evidence_intake_empty';
        }
        return null;
    }

    private function failed(string $reason, int $budget): array
    {
        return [
            'schema_version'           => self::SCHEMA_VERSION,
            'assembled'                => false,
            'failure_reason'           => $reason,
            'prompt_sections'          => [],
            'required_artifacts'       => [],
            'anti_duplication_checks'  => [],
            'max_context_budget_chars' => $budget,
        ];
    }

    private function renderEvidence(array $evidenceItems, int $budget): string
    {
        $lines   = ['[EVIDENCE INTAKE — use as primary reasoning substrate]'];
        $chars   = 0;
        $ceiling = (int) ($budget * 0.5); // evidence gets at most 50% of budget

        foreach ($evidenceItems as $i => $item) {
            $text = is_string($item) ? $item : (string) ($item['text'] ?? json_encode($item));
            if ($chars + strlen($text) > $ceiling) {
                $lines[] = '[... evidence truncated to context budget ...]';
                break;
            }
            $lines[] = sprintf('[%d] %s', $i + 1, $text);
            $chars  += strlen($text);
        }

        return implode("\n", $lines);
    }

    private function renderScaffold(): string
    {
        return implode("\n", [
            '[REASONING SCAFFOLD — follow each step in order]',
            '1. Identify the highest-leverage gap not yet in the queue.',
            '2. State the concrete improvement over the current codebase.',
            '3. Verify the target is unique vs. the anti-duplication list.',
            '4. Confirm that acceptance criteria are runnable, not vague.',
            '5. Produce a complete task spec including allowed_files.',
        ]);
    }

    private function renderDedup(array $queuedTargets): string
    {
        if ($queuedTargets === []) {
            return '[ANTI-DUPLICATION — no queued targets to check]';
        }
        $lines = ['[ANTI-DUPLICATION — do NOT reproduce any of these targets]'];
        foreach ($queuedTargets as $target) {
            $lines[] = '- ' . $target;
        }
        return implode("\n", $lines);
    }

    private function renderOutputContract(): string
    {
        return implode("\n", array_merge(
            ['[OUTPUT CONTRACT — your response must include ALL of the following]'],
            array_map(static fn ($a) => '- ' . $a, self::REQUIRED_ARTIFACTS),
        ));
    }
}
