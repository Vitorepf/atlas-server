<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Turns {@see AtlasExternalBrainResearchToTaskDigestor} from a dead-end report
 * into a steady-state intake circuit: digestor-promoted candidates are adapted
 * into packet-ready intents (target path, allowed_files, runnable acceptance,
 * anti-Goodhart risk preserved); hype-only or provider-steady-state-dependent
 * items never reach the intent feed because the digestor already rejects them
 * before this loop builds anything from them.
 *
 * Pure: no I/O, no side effects — delegates rejection judgment entirely to the
 * digestor so there is exactly one place that decides what is grounded.
 *
 * AC4 (additive): provider-sensitive or copy-paste external content must never reach a task
 * intent verbatim. providerSafeText() redacts secret-like key/value pairs and, past a fixed
 * length ceiling, condenses long copy-pasted text into a short provider-safe design-intent
 * summary rather than emitting the raw blob. Applied to `objective` and `source_evidence` only —
 * every other intent field is untouched.
 */
final class AtlasExternalBrainResearchToTaskIntakeLoop
{
    public const SCHEMA = 'atlas.external_brain.research_to_task_intake_loop.v1';

    private const VERBATIM_MAX_CHARS = 220;

    private const SUMMARY_KEEP_CHARS = 160;

    public function __construct(
        private readonly AtlasExternalBrainResearchToTaskDigestor $digestor = new AtlasExternalBrainResearchToTaskDigestor,
    ) {
    }

    /**
     * @param  array{research_items?: list<array<string,mixed>>}  $input
     * @return array{schema:string, intents:list<array<string,mixed>>, rejected:list<array<string,mixed>>, intake_count:int, rejected_count:int}
     */
    public function intake(array $input): array
    {
        $digest = $this->digestor->digest($input);

        $intents = array_map(
            fn (array $candidate): array => $this->buildIntent($candidate),
            $digest['promoted'],
        );

        return [
            'schema' => self::SCHEMA,
            'intents' => $intents,
            'rejected' => $digest['rejected'],
            'intake_count' => count($intents),
            'rejected_count' => $digest['rejected_count'],
        ];
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @return array<string,mixed>
     */
    private function buildIntent(array $candidate): array
    {
        $targetPath = (string) ($candidate['target_path'] ?? '');
        $allowedFiles = (array) ($candidate['allowed_files'] ?? []);
        $runnableAcceptance = (string) ($candidate['runnable_acceptance'] ?? '');
        $antiGoodhartRisks = (array) ($candidate['anti_goodhart_risks'] ?? []);
        $testPath = (string) ($candidate['test_path'] ?? '');

        // AC2: allowed_files_closure — prove the intent is packet-ready.
        $hasImpl = false;
        $hasTest = false;
        foreach ($allowedFiles as $file) {
            $file = (string) $file;
            if (str_ends_with($file, 'Test.php')) {
                $hasTest = true;
            } else {
                $hasImpl = true;
            }
        }
        // Also flag test present when test_path was provided outside allowed_files.
        if ($testPath !== '') {
            $hasTest = true;
        }

        $targetCovered = $targetPath === '' || $this->isCoveredByAllowedFiles($targetPath, $allowedFiles);
        $runnableAcceptancePresent = $runnableAcceptance !== '';

        $closureGapReasons = [];
        if (! $hasImpl) {
            $closureGapReasons[] = 'missing_implementation_in_allowed_files';
        }
        if (! $hasTest) {
            $closureGapReasons[] = 'missing_test_in_allowed_files_or_test_path';
        }
        if (! $targetCovered) {
            $closureGapReasons[] = 'target_not_covered_by_allowed_files';
        }
        if (! $runnableAcceptancePresent) {
            $closureGapReasons[] = 'missing_runnable_acceptance';
        }

        return [
            'task_packet_id' => 'research-intake-'.substr(hash('sha256', $targetPath.'|'.($candidate['source'] ?? '')), 0, 16),
            'objective' => $this->providerSafeText((string) ($candidate['leverage_claim'] ?? $candidate['pattern_summary'] ?? '')),
            'target_path' => $targetPath,
            'allowed_files' => $allowedFiles,
            'scope_in' => $allowedFiles,
            'acceptance_criteria' => array_values(array_filter([$runnableAcceptance])),
            'anti_goodhart_risks' => $antiGoodhartRisks,
            'source_type' => (string) ($candidate['source_type'] ?? ''),
            'source_evidence' => $this->providerSafeText((string) ($candidate['source_evidence'] ?? '')),
            'task_family' => (string) ($candidate['task_family'] ?? ''),
            'allowed_files_closure' => [
                'implementation_present'      => $hasImpl,
                'test_present'                => $hasTest,
                'target_covered'              => $targetCovered,
                'runnable_acceptance_present' => $runnableAcceptancePresent,
            ],
            'closure_gap_reason' => $closureGapReasons !== [] ? implode('; ', $closureGapReasons) : null,
        ];
    }

    /**
     * @param  list<string>  $allowedFiles
     */
    private function isCoveredByAllowedFiles(string $targetPath, array $allowedFiles): bool
    {
        $normalised = str_replace('\\', '/', $targetPath);
        $dirname = dirname($normalised);

        foreach ($allowedFiles as $file) {
            $f = str_replace('\\', '/', (string) $file);
            // Direct match or the target directory is a prefix of the allowed file.
            if ($f === $normalised || str_starts_with($f, $dirname)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Redacts secret-like key/value pairs and condenses text past VERBATIM_MAX_CHARS into a
     * short provider-safe design-intent summary, so raw copy-pasted external content or
     * provider-sensitive strings never reach a task intent verbatim (AC4).
     */
    private function providerSafeText(string $text): string
    {
        $redacted = (string) preg_replace(
            '/\b(SECRET|TOKEN|API_KEY|PASSWORD)([A-Z0-9_]*)\s*[:=]\s*\S+/i',
            '$1$2=[REDACTED]',
            $text,
        );

        if (mb_strlen($redacted) <= self::VERBATIM_MAX_CHARS) {
            return $redacted;
        }

        $omitted = mb_strlen($redacted) - self::SUMMARY_KEEP_CHARS;

        return mb_substr($redacted, 0, self::SUMMARY_KEEP_CHARS)
            ." ... [summarized design intent: {$omitted} chars of external content condensed for provider-safe transfer]";
    }
}
