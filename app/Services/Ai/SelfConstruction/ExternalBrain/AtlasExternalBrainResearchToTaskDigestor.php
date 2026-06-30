<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure digestor. Converts bounded research notes, external pattern summaries,
 * or architecture observations into Atlas task candidates with:
 *   - explicit adaptation_notes (how the pattern maps to Atlas internals)
 *   - anti-hype rejection for items that lack an Atlas-specific failure mode
 *   - concrete allowed_files and test coverage requirements
 *   - at least one anti-Goodhart risk per promoted candidate
 *
 * Rejection hierarchy (first match wins):
 *   hype_only                — no atlas_failure_mode; item has no Atlas-specific problem statement
 *   no_target_path           — target_path is missing or empty
 *   no_runnable_acceptance   — no runnable acceptance evidence (artisan/phpunit/vendor command)
 *   incomplete_candidate     — missing adaptation_notes, allowed_files, test_path, or anti_goodhart_risks
 *
 * Pure: no I/O, no side effects. Operates on supplied arrays, no network access.
 */
final class AtlasExternalBrainResearchToTaskDigestor
{
    public const SCHEMA = 'atlas.external_brain.research_to_task_digestor.v1';

    public const REJECTION_HYPE_ONLY              = 'hype_only';
    public const REJECTION_NO_TARGET_PATH         = 'no_target_path';
    public const REJECTION_NO_RUNNABLE_ACCEPTANCE = 'no_runnable_acceptance';
    public const REJECTION_INCOMPLETE_CANDIDATE   = 'incomplete_candidate';
    public const REJECTION_PROVIDER_STEADY_STATE_DEPENDENCY = 'provider_steady_state_dependency';
    public const REJECTION_MISSING_ANTI_GOODHART_RISK        = 'missing_anti_goodhart_risk';

    private const RUNNABLE_MARKERS = ['artisan', 'vendor/bin', 'phpunit'];

    private const HYPE_KEYWORDS = [
        'revolutionize', 'breakthrough', 'state-of-the-art', 'game-changer',
        'transformative', 'unprecedented', 'revolutionary', 'paradigm-shift', 'mind-blowing',
    ];

    private const CODE_GROUNDING_SIGNALS = [
        '.php', '()', '::', '->', '_rate', '_score', '_count',
        '_threshold', '_delta', 'artisan', 'exit', 'command',
    ];

    /**
     * @param  array{
     *   research_items?: list<array>,
     * }  $input
     * @return array{schema:string, promoted:list<array>, rejected:list<array>, promoted_count:int, rejected_count:int}
     */
    public function digest(array $input): array
    {
        $items = (array) ($input['research_items'] ?? []);

        $promoted = [];
        $rejected = [];

        foreach ($items as $item) {
            [$accept, $reason] = $this->evaluate($item);
            if ($accept) {
                $promoted[] = $this->buildCandidate($item);
            } else {
                $rejected[] = ['item' => $item, 'rejection_reason' => $reason];
            }
        }

        return [
            'schema'         => self::SCHEMA,
            'promoted'       => $promoted,
            'rejected'       => $rejected,
            'promoted_count' => count($promoted),
            'rejected_count' => count($rejected),
        ];
    }

    /**
     * @return array{0:bool, 1:string|null}
     */
    private function evaluate(mixed $item): array
    {
        if (! is_array($item)) {
            return [false, self::REJECTION_HYPE_ONLY];
        }

        $failureMode       = trim((string) ($item['atlas_failure_mode']    ?? ''));
        $targetPath        = trim((string) ($item['target_path']           ?? ''));
        $adaptationNotes   = trim((string) ($item['adaptation_notes']      ?? ''));
        $testPath          = trim((string) ($item['test_path']             ?? ''));
        $allowedFiles      = array_filter(array_map('trim', (array) ($item['allowed_files']       ?? [])));
        $antiGoodhart      = array_filter(array_map('trim', (array) ($item['anti_goodhart_risks'] ?? [])));
        $runnableAcceptance = trim((string) ($item['runnable_acceptance']  ?? ''));
        $sourceType        = trim((string) ($item['source_type']           ?? ''));

        if ($failureMode === '') {
            return [false, self::REJECTION_HYPE_ONLY];
        }

        if ($this->isHypeOnlyText($failureMode)) {
            return [false, self::REJECTION_HYPE_ONLY];
        }

        if ($targetPath === '') {
            return [false, self::REJECTION_NO_TARGET_PATH];
        }

        if (! $this->hasRunnableCommand($runnableAcceptance)) {
            return [false, self::REJECTION_NO_RUNNABLE_ACCEPTANCE];
        }

        if (($item['requires_provider_steady_state'] ?? false) === true) {
            return [false, self::REJECTION_PROVIDER_STEADY_STATE_DEPENDENCY];
        }

        if ($adaptationNotes === '' || $allowedFiles === [] || $testPath === '' || $sourceType === '') {
            return [false, self::REJECTION_INCOMPLETE_CANDIDATE];
        }

        if ($antiGoodhart === []) {
            return [false, self::REJECTION_MISSING_ANTI_GOODHART_RISK];
        }

        return [true, null];
    }

    private function buildCandidate(array $item): array
    {
        $allowedFiles   = array_values(array_filter(array_map('trim', (array) ($item['allowed_files']       ?? []))));
        $antiGoodhart   = array_values(array_filter(array_map('trim', (array) ($item['anti_goodhart_risks'] ?? []))));
        $testPath       = trim((string) ($item['test_path']          ?? ''));
        $runnableAcc    = trim((string) ($item['runnable_acceptance'] ?? ''));
        $source         = trim((string) ($item['source']             ?? ''));

        return [
            'source'              => $source,
            'source_type'         => trim((string) ($item['source_type'] ?? '')),
            'pattern_summary'     => trim((string) ($item['pattern_summary']    ?? '')),
            'atlas_failure_mode'  => trim((string) ($item['atlas_failure_mode'] ?? '')),
            'target_path'         => trim((string) ($item['target_path']        ?? '')),
            'adaptation_notes'    => trim((string) ($item['adaptation_notes']   ?? '')),
            'allowed_files'       => $allowedFiles,
            'test_path'           => $testPath,
            'anti_goodhart_risks' => $antiGoodhart,
            'runnable_acceptance' => $runnableAcc,
            // AC2 required output fields.
            'implementation_file' => $allowedFiles[0] ?? '',
            'test_file'           => $testPath,
            'leverage_claim'      => trim((string) ($item['leverage_claim'] ?? $this->deriveLeverageClaim($item))),
            'risk'                => trim((string) ($item['risk'] ?? ($antiGoodhart[0] ?? ''))),
            'acceptance_summary'  => trim((string) ($item['acceptance_summary'] ?? $runnableAcc)),
            'source_evidence'     => trim((string) ($item['source_evidence']    ?? $source)),
            'task_family'         => trim((string) ($item['task_family'] ?? '')),
            'expected_compound_lift' => isset($item['expected_compound_lift']) ? (float) $item['expected_compound_lift'] : 0.0,
            'dependency_hints'    => array_values(array_filter(array_map('trim', (array) ($item['dependency_hints'] ?? [])))),
            // Advisory benchmark grounding — present if supplied, null otherwise.
            'benchmark_grounding' => [
                'source_quality'         => isset($item['source_quality'])         ? (float) $item['source_quality']        : null,
                'observed_failure_class' => isset($item['observed_failure_class'])  ? trim((string) $item['observed_failure_class']) : null,
                'atlas_mapping'          => isset($item['atlas_mapping'])           ? trim((string) $item['atlas_mapping'])  : null,
                'expected_quality_delta' => isset($item['expected_quality_delta'])  ? (float) $item['expected_quality_delta'] : null,
            ],
        ];
    }

    private function deriveLeverageClaim(array $item): string
    {
        $summary = trim((string) ($item['pattern_summary']    ?? ''));
        $failure = trim((string) ($item['atlas_failure_mode'] ?? ''));
        if ($summary !== '' && $failure !== '') {
            return "Applying pattern ({$summary}) to resolve: {$failure}";
        }
        return $summary !== '' ? $summary : $failure;
    }

    private function isHypeOnlyText(string $text): bool
    {
        $lower = strtolower($text);

        $hasHype = false;
        foreach (self::HYPE_KEYWORDS as $kw) {
            if (str_contains($lower, $kw)) {
                $hasHype = true;
                break;
            }
        }

        if (! $hasHype) {
            return false;
        }

        foreach (self::CODE_GROUNDING_SIGNALS as $signal) {
            if (str_contains($lower, $signal)) {
                return false;
            }
        }

        return true;
    }

    private function hasRunnableCommand(string $acceptance): bool
    {
        $lower = strtolower($acceptance);
        foreach (self::RUNNABLE_MARKERS as $marker) {
            if (str_contains($lower, $marker)) {
                return true;
            }
        }

        return false;
    }
}
