<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskQuality;

/**
 * Hardens task quality checks so runnable acceptance criteria must bind to
 * the implementation or test target they claim to prove.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasTaskQualityAcceptanceCoverageHardener
{
    public const SCHEMA = 'atlas.self_construction.task_quality_acceptance_coverage_hardener.v1';

    public const VERDICT_PASS = 'pass';
    public const VERDICT_FAIL = 'fail';

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function harden(array $input): array
    {
        $allowedFiles = (array) ($input['allowed_files'] ?? []);
        $acceptanceCriteria = is_array($input['acceptance_criteria'] ?? null) ? $input['acceptance_criteria'] : [];

        $findings = [];
        $boundCriteria = [];

        foreach ($acceptanceCriteria as $criterion) {
            $criterion = (string) $criterion;
            $finding = $this->checkCriterion($criterion, $allowedFiles);

            if ($finding['verdict'] === self::VERDICT_PASS) {
                $boundCriteria[] = $criterion;
            } else {
                $findings[] = $finding;
            }
        }

        $verdict = $findings === [] ? self::VERDICT_PASS : self::VERDICT_FAIL;

        return [
            'schema_version' => self::SCHEMA,
            'verdict' => $verdict,
            'bound_criteria' => $boundCriteria,
            'unbound_criteria_findings' => $findings,
            'unbound_count' => count($findings),
            'total_criteria' => count($acceptanceCriteria),
        ];
    }

    /**
     * @param  list<string>  $allowedFiles
     * @return array<string, mixed>
     */
    private function checkCriterion(string $criterion, array $allowedFiles): array
    {
        $lower = strtolower($criterion);

        // Generic phpunit without a filter or path is targetless.
        if (preg_match('/^\s*\/?opt\/homebrew\/bin\/php\s+artisan\s+test\s*$/i', $criterion)) {
            return [
                'criterion' => $criterion,
                'verdict' => self::VERDICT_FAIL,
                'reason' => 'generic_phpunit_without_target',
            ];
        }

        // Extract filter or path from php artisan test commands.
        $target = null;
        if (preg_match('/php\s+artisan\s+test\s+(--filter=)?([A-Za-z0-9_\\\\]+)/i', $criterion, $matches)) {
            $target = $matches[2];
        } elseif (preg_match('/php\s+artisan\s+test\s+(\S+\.php)/i', $criterion, $matches)) {
            $target = $matches[1];
        }

        if ($target === null) {
            return [
                'criterion' => $criterion,
                'verdict' => self::VERDICT_FAIL,
                'reason' => 'targetless_acceptance_criterion',
            ];
        }

        $targetLower = strtolower($target);
        $bound = false;
        foreach ($allowedFiles as $file) {
            $fileLower = strtolower((string) $file);
            $fileName = basename($fileLower);
            if (str_contains($fileLower, $targetLower) || str_contains($targetLower, $fileName)) {
                $bound = true;

                break;
            }
        }

        if (! $bound) {
            return [
                'criterion' => $criterion,
                'verdict' => self::VERDICT_FAIL,
                'reason' => 'unrelated_filter_or_path:'.$target,
            ];
        }

        return [
            'criterion' => $criterion,
            'verdict' => self::VERDICT_PASS,
            'reason' => 'target_bound_to_allowed_files:'.$target,
        ];
    }
}
