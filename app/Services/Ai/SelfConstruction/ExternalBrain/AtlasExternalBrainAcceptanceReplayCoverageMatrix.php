<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure coverage auditor. Validates that generated task specs are fit to
 * reach muscles by checking four dimensions before they land in the queue.
 *
 * Blocking rejection dimensions (any one → verdict='rejected'):
 *   no_runnable_command    — no acceptance criterion contains an artisan/phpunit/vendor command
 *   no_impl_file_coverage  — allowed_files has no implementation file (only test files)
 *   no_evidence_refs       — required_evidence is non-empty but evidence_refs is empty
 *
 * Non-blocking warning (noted in coverage_flags but does not reject alone):
 *   is_brittle_proxy       — every acceptance criterion is a `--filter=` single-class pattern
 *                            with nothing broader (proxy test, not evidence of behavior)
 *
 * A command is "runnable" when it contains any of: 'artisan', 'vendor/bin', 'phpunit'.
 * An implementation file is any allowed_file that is NOT under 'tests/' and does NOT
 * end with 'Test.php' or 'Spec.php'.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainAcceptanceReplayCoverageMatrix
{
    public const SCHEMA = 'atlas.external_brain.acceptance_replay_coverage_matrix.v1';

    public const VERDICT_ACCEPTED = 'accepted';
    public const VERDICT_REJECTED = 'rejected';

    public const DIM_RUNNABLE_COMMAND    = 'runnable_command';
    public const DIM_IMPL_FILE_COVERAGE  = 'impl_file_coverage';
    public const DIM_EVIDENCE_REFS       = 'evidence_refs';
    public const DIM_BRITTLE_PROXY       = 'brittle_proxy';
    public const DIM_CLAIMED_LEVERAGE    = 'claimed_leverage_coverage';

    private const RUNNABLE_MARKERS = ['artisan', 'vendor/bin', 'phpunit'];

    private const IMPACT_DIMENSIONS = [
        'multi_component'  => ['multi-component', 'cross-component', 'integration', 'multiple capabilities', 'spans multiple'],
        'learning_loop'    => ['learning-loop', 'learning loop', 'outcome learning', 'closed loop', 'closed-loop'],
        'anti_goodhart'    => ['anti-goodhart', 'anti goodhart', 'goodhart', 'proxy detection'],
        'queue_health'     => ['queue-health', 'queue health', 'queue integrity', 'task fabric', 'origination cycle'],
    ];

    private const DIMENSION_EVIDENCE_KEYS = [
        'multi_component'  => ['integration_test', 'cross_component', 'multi_component_proof', 'e2e_test'],
        'learning_loop'    => ['learning_outcome', 'outcome_learning', 'learning_loop_proof'],
        'anti_goodhart'    => ['anti_goodhart', 'goodhart_proof', 'anti_proxy'],
        'queue_health'     => ['queue_health', 'queue_integrity', 'queue_proof'],
    ];

    /**
     * @param  array{
     *   acceptance_criteria?: list<string>,
     *   allowed_files?: list<string>,
     *   evidence_refs?: list<string>,
     *   required_evidence?: list<string>,
     *   objective?: string,
     * }  $spec
     * @return array{schema:string, verdict:string, coverage_flags:array<string,bool>, rejections:list<array<string,string>>, claimed_leverage_gaps:list<string>}
     */
    public function audit(array $spec): array
    {
        $criteria         = array_values(array_filter(array_map('trim', (array) ($spec['acceptance_criteria'] ?? [])), fn (string $s): bool => $s !== ''));
        $allowedFiles     = array_values(array_filter(array_map('trim', (array) ($spec['allowed_files']       ?? [])), fn (string $s): bool => $s !== ''));
        $evidenceRefs     = array_values(array_filter(array_map('trim', (array) ($spec['evidence_refs']       ?? [])), fn (string $s): bool => $s !== ''));
        $requiredEvidence = array_values(array_filter(array_map('trim', (array) ($spec['required_evidence']   ?? [])), fn (string $s): bool => $s !== ''));
        $objective        = (string) ($spec['objective'] ?? '');

        $hasRunnableCommand  = $this->hasRunnableCommand($criteria);
        $hasImplFileCoverage = $this->hasImplFileCoverage($allowedFiles);
        $hasEvidenceRefs     = $requiredEvidence === [] || $evidenceRefs !== [];
        $isBrittleProxy      = $this->isBrittleProxy($criteria);
        $claimedLeverageGaps = $this->findLeverageGaps($criteria, $objective, $evidenceRefs);

        $rejections = [];
        if (! $hasRunnableCommand) {
            $rejections[] = [
                'reason'    => 'no_runnable_command',
                'dimension' => self::DIM_RUNNABLE_COMMAND,
            ];
        }
        if (! $hasImplFileCoverage) {
            $rejections[] = [
                'reason'    => 'no_impl_file_coverage',
                'dimension' => self::DIM_IMPL_FILE_COVERAGE,
            ];
        }
        if (! $hasEvidenceRefs) {
            $rejections[] = [
                'reason'    => 'no_evidence_refs',
                'dimension' => self::DIM_EVIDENCE_REFS,
            ];
        }

        return [
            'schema'                => self::SCHEMA,
            'verdict'               => $rejections === [] ? self::VERDICT_ACCEPTED : self::VERDICT_REJECTED,
            'coverage_flags'        => [
                'has_runnable_command'        => $hasRunnableCommand,
                'has_impl_file_coverage'      => $hasImplFileCoverage,
                'has_evidence_refs'           => $hasEvidenceRefs,
                'is_brittle_proxy'            => $isBrittleProxy,
                'claimed_leverage_coverage_met' => $claimedLeverageGaps === [],
            ],
            'rejections'            => $rejections,
            'claimed_leverage_gaps' => $claimedLeverageGaps,
        ];
    }

    private function hasRunnableCommand(array $criteria): bool
    {
        foreach ($criteria as $criterion) {
            $lower = strtolower($criterion);
            foreach (self::RUNNABLE_MARKERS as $marker) {
                if (str_contains($lower, $marker)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function hasImplFileCoverage(array $allowedFiles): bool
    {
        foreach ($allowedFiles as $file) {
            if (! $this->isTestPath($file)) {
                return true;
            }
        }

        return false;
    }

    private function isBrittleProxy(array $criteria): bool
    {
        if ($criteria === []) {
            return false;
        }
        // Brittle: ALL criteria are bare --filter=ClassName lines with no broader runnable command.
        // A criterion that contains artisan/phpunit/vendor around the --filter is NOT brittle.
        foreach ($criteria as $c) {
            $lower = strtolower($c);
            $hasRunnable = false;
            foreach (self::RUNNABLE_MARKERS as $marker) {
                if (str_contains($lower, $marker)) {
                    $hasRunnable = true;
                    break;
                }
            }
            if ($hasRunnable || ! preg_match('/--filter=[A-Z][a-zA-Z]+\b/', $c)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> dimension keys whose claimed leverage has no matching evidence ref */
    private function findLeverageGaps(array $criteria, string $objective, array $evidenceRefs): array
    {
        $claimed = $this->detectClaimedDimensions($criteria, $objective);
        if ($claimed === []) {
            return [];
        }

        $refs  = array_map('strtolower', $evidenceRefs);
        $gaps  = [];
        foreach ($claimed as $dim) {
            $covered = false;
            foreach (self::DIMENSION_EVIDENCE_KEYS[$dim] as $key) {
                foreach ($refs as $ref) {
                    if (str_contains($ref, $key)) {
                        $covered = true;
                        break 2;
                    }
                }
            }
            if (! $covered) {
                $gaps[] = $dim;
            }
        }

        return $gaps;
    }

    /** @return list<string> dimension keys whose keywords appear in criteria or objective */
    private function detectClaimedDimensions(array $criteria, string $objective): array
    {
        $haystack = strtolower($objective.' '.implode(' ', $criteria));
        $claimed  = [];
        foreach (self::IMPACT_DIMENSIONS as $dim => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($haystack, $kw)) {
                    $claimed[] = $dim;
                    break;
                }
            }
        }

        return $claimed;
    }

    private function isTestPath(string $path): bool
    {
        return str_starts_with($path, 'tests/')
            || str_ends_with($path, 'Test.php')
            || str_ends_with($path, 'Spec.php');
    }
}
