<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure deterministic assembler for scaffolded-model task-origination prompts.
 * Combines evidence intake, ranking policy, live-queue snapshot, anti-duplication
 * checks, forbidden output shapes, acceptance floor, budget guard, and output
 * contract into one structured prompt without calling providers.
 *
 * Fail-closed when:
 *   - allow_direct_final_answer = true  (free-form answer must never be emitted)
 *   - evidence_intake is empty          (scaffold needs evidence to reason over)
 *   - queued_targets key absent         (dedup state unknown; cannot prevent queue collisions)
 *
 * Prompt section order (deterministic):
 *   1  evidence_intake
 *   2  live_queue_snapshot
 *   3  ranking_order
 *   4  reasoning_scaffold
 *   5  anti_duplication_check
 *   6  forbidden_output_shapes
 *   7  acceptance_floor
 *   8  budget_guard
 *   9  output_contract
 *
 * Pure / deterministic / no I/O.
 */
final class AtlasExternalBrainScaffoldedPromptAssembler
{
    public const SCHEMA_VERSION = 'atlas.external_brain.scaffolded_prompt_assembler.v1';

    private const DEFAULT_CONTEXT_BUDGET_CHARS = 8_000;

    private const SECTION_EVIDENCE         = 'evidence_intake';
    private const SECTION_QUEUE_SNAPSHOT   = 'live_queue_snapshot';
    private const SECTION_RANKING          = 'ranking_order';
    private const SECTION_SCAFFOLD         = 'reasoning_scaffold';
    private const SECTION_DEDUP            = 'anti_duplication_check';
    private const SECTION_FORBIDDEN        = 'forbidden_output_shapes';
    private const SECTION_ACCEPTANCE_FLOOR = 'acceptance_floor';
    private const SECTION_BUDGET_GUARD     = 'budget_guard';
    private const SECTION_NO_WAIT_POLICY   = 'no_wait_policy';
    private const SECTION_NO_COMFORTABLE_QUEUE_STOP = 'no_comfortable_queue_stop';
    private const SECTION_OUTPUT_CONTRACT  = 'output_contract';

    private const REQUIRED_ARTIFACTS = [
        'task_spec_with_allowed_files',
        'acceptance_criteria_runnable',
        'implementation_notes',
    ];

    private const REQUIRED_ARTIFACTS_UNEXPLORED = [
        'candidate_batch_or_exhausted_surface_proof',
    ];

    /**
     * @param  array{
     *   model_tier?: string,
     *   evidence_intake?: list<array<string,mixed>>,
     *   queued_targets?: list<string>,
     *   allow_direct_final_answer?: bool,
     *   context_budget_chars?: int,
     * }  $input
     * @return array<string,mixed>
     */
    public function assemble(array $input): array
    {
        $allowDirectAnswer = (bool)  ($input['allow_direct_final_answer'] ?? false);
        $evidenceIntake    = (array) ($input['evidence_intake']           ?? []);
        $dedupProvided     = array_key_exists('queued_targets', $input);
        $queuedTargets     = $dedupProvided ? $this->dedupTargets((array) $input['queued_targets']) : [];
        $budgetChars       = max(1, (int) ($input['context_budget_chars'] ?? self::DEFAULT_CONTEXT_BUDGET_CHARS));
        $unexploredSurfaces = (array) ($input['unexplored_surfaces'] ?? []);

        $failureReason = $this->failCloseReason($allowDirectAnswer, $evidenceIntake, $dedupProvided);
        if ($failureReason !== null) {
            return $this->failed($failureReason, $budgetChars);
        }

        $requiredArtifacts = self::REQUIRED_ARTIFACTS;
        if ($dedupProvided && $queuedTargets !== [] && $unexploredSurfaces !== []) {
            $requiredArtifacts = array_merge($requiredArtifacts, self::REQUIRED_ARTIFACTS_UNEXPLORED);
        }

        $sections = [
            ['section' => self::SECTION_EVIDENCE,         'content' => $this->renderEvidence($evidenceIntake, $budgetChars)],
            ['section' => self::SECTION_QUEUE_SNAPSHOT,   'content' => $this->renderQueueSnapshot($queuedTargets)],
            ['section' => self::SECTION_RANKING,          'content' => $this->renderRanking()],
            ['section' => self::SECTION_SCAFFOLD,         'content' => $this->renderScaffold()],
            ['section' => self::SECTION_DEDUP,            'content' => $this->renderDedup($queuedTargets)],
            ['section' => self::SECTION_FORBIDDEN,        'content' => $this->renderForbidden()],
            ['section' => self::SECTION_ACCEPTANCE_FLOOR, 'content' => $this->renderAcceptanceFloor()],
            ['section' => self::SECTION_BUDGET_GUARD,     'content' => $this->renderBudgetGuard($budgetChars)],
            ['section' => self::SECTION_NO_WAIT_POLICY,   'content' => $this->renderNoWaitPolicy()],
            ['section' => self::SECTION_NO_COMFORTABLE_QUEUE_STOP, 'content' => $this->renderNoComfortableQueueStop()],
            ['section' => self::SECTION_OUTPUT_CONTRACT,  'content' => $this->renderOutputContract($requiredArtifacts)],
        ];

        return [
            'schema_version'           => self::SCHEMA_VERSION,
            'assembled'                => true,
            'failure_reason'           => null,
            'prompt_sections'          => $sections,
            'required_artifacts'       => $requiredArtifacts,
            'anti_duplication_checks'  => array_values($queuedTargets),
            'max_context_budget_chars' => $budgetChars,
        ];
    }

    /**
     * Case-insensitive dedup of the live-queue snapshot, preserving the first occurrence's
     * casing and original order — a weak model must never see the same target twice (it
     * would read as two distinct anti-duplication entries and waste budget/attention).
     *
     * @param  list<mixed>  $targets
     * @return list<string>
     */
    private function dedupTargets(array $targets): array
    {
        $seen = [];
        $deduped = [];
        foreach ($targets as $target) {
            $target = (string) $target;
            $key = strtolower($target);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $deduped[] = $target;
        }

        return $deduped;
    }

    private function failCloseReason(bool $allowDirectAnswer, array $evidence, bool $dedupProvided): ?string
    {
        if ($allowDirectAnswer) {
            return 'fail_closed:allow_direct_final_answer_is_true';
        }
        if ($evidence === []) {
            return 'fail_closed:evidence_intake_empty';
        }
        if (! $dedupProvided) {
            return 'fail_closed:queued_target_dedup_missing';
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
        $ceiling = (int) ($budget * 0.5);

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

    private function renderQueueSnapshot(array $queuedTargets): string
    {
        if ($queuedTargets === []) {
            return '[LIVE QUEUE SNAPSHOT — queue is currently empty; originate fresh tasks]';
        }
        $lines = ['[LIVE QUEUE SNAPSHOT — tasks already queued]'];
        foreach ($queuedTargets as $target) {
            $lines[] = '- ' . $target;
        }
        return implode("\n", $lines);
    }

    private function renderRanking(): string
    {
        return implode("\n", [
            '[RANKING ORDER — apply this priority when selecting what to build next]',
            '1. Tasks that unblock other queued work (dependency chains).',
            '2. Tasks that add provable test coverage to uncovered branches.',
            '3. Tasks that wire orphan organs (classes with zero consumers).',
            '4. Tasks with the highest cyclomatic-complexity reduction potential.',
            '5. Tasks that close a known gate weakness in the spec-mutation suite.',
        ]);
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

    private function renderForbidden(): string
    {
        return implode("\n", [
            '[FORBIDDEN OUTPUT SHAPES — violating any of these is an automatic rejection]',
            '- Do NOT emit an objective using only generic phrases ("the service", "the case", "handle correctly", "make it work").',
            '- Do NOT emit acceptance criteria that do not name the exact class or method under test.',
            '- Do NOT emit a task that duplicates any entry in the LIVE QUEUE SNAPSHOT.',
            '- Do NOT emit a task with fewer than 2 runnable acceptance criteria.',
            '- Do NOT emit template-farm specs: every task must be unique and grounded in the evidence above.',
        ]);
    }

    private function renderAcceptanceFloor(): string
    {
        return implode("\n", [
            '[ACCEPTANCE FLOOR — every task spec you emit must clear all of these minimums]',
            '- allowed_files must contain at least 1 non-test implementation file.',
            '- At least 1 acceptance criterion must include a runnable command (/opt/homebrew/bin/php or vendor/bin/).',
            '- required_evidence must include "tests_or_gates_result" or "implementation_notes".',
            '- objective must name at least one PascalCase class (e.g. AtlasFooService).',
        ]);
    }

    private function renderBudgetGuard(int $budgetChars): string
    {
        $evidenceCeiling = (int) ($budgetChars * 0.5);
        return implode("\n", [
            sprintf('[BUDGET GUARD — context budget: %d chars]', $budgetChars),
            sprintf('- Evidence intake is capped at %d chars (50%% of budget).', $evidenceCeiling),
            '- If you are near the budget ceiling, prioritize the highest-leverage task over completeness.',
            '- Do not hallucinate facts to fill budget; truncate evidence instead.',
        ]);
    }

    private function renderNoWaitPolicy(): string
    {
        return implode("\n", [
            '[NO-WAIT POLICY — read before deciding whether to originate]',
            '- "servable_now is healthy" (sufficient queue depth) is NEVER a reason to stop originating.',
            '- The only valid stop condition is "no valuable task exists" — a claim you must prove, not assume.',
            '- If unexplored surfaces remain, either produce a candidate batch or a structured exhausted_surface_proof.',
            '- Queue depth measures throughput capacity, not the existence of remaining value; do not conflate the two.',
        ]);
    }

    private function renderNoComfortableQueueStop(): string
    {
        return implode("\n", [
            '[NO COMFORTABLE QUEUE STOP — read before deciding whether to stop originating]',
            '- Healthy or sufficient queue depth changes batch sizing (how many tasks to originate now), but it does NOT permit stopping.',
            '- A comfortable queue is never itself a stop condition; it must pivot the search to deeper leverage, not end it.',
            '- Continue searching for the highest-leverage gap even when the queue looks well-stocked.',
        ]);
    }

    /** @param  list<string>  $requiredArtifacts */
    private function renderOutputContract(array $requiredArtifacts): string
    {
        return implode("\n", array_merge(
            ['[OUTPUT CONTRACT — your response must include ALL of the following]'],
            array_map(static fn ($a) => '- ' . $a, $requiredArtifacts),
        ));
    }
}
