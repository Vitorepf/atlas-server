<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Turns organ sprawl-reduction plans into graph-safe simplification task batches.
 * PURE / DETERMINISTIC. Never deletes code or enqueues tasks.
 *
 * SUPPORTED ACTION TYPES: merge | retire | simplify
 *
 * REFUSAL RULES (first match → refused, action skipped):
 *   1. type=merge AND replacement_owner is missing/empty
 *   2. behavior_preservation_tests is missing or empty
 *   3. allowed_files is missing or empty
 *   4. allowed_files contains ONLY test files (no implementation file)
 *   5. allowed_files contains NO test file (implementation-only)
 *
 * DEPENDENCY ORDER (stable, lowest index first):
 *   merge=0 → simplify=1 → retire=2
 *   retire for an organ also depends on any merge listing the same organ.
 *
 * OUTPUT task_spec shape (per action):
 *   action_type, organ, implementation_file, test_file, allowed_files,
 *   acceptance_criteria (runnable), required_evidence,
 *   behavior_preservation_gates, depends_on
 *
 * Pure / deterministic / no I/O.
 */
final class AtlasExternalBrainSprawlPlanToTaskBatchTranslator
{
    public const SCHEMA = 'atlas.external_brain.sprawl_plan_to_task_batch_translator.v1';

    private const ACTION_ORDER = ['merge' => 0, 'simplify' => 1, 'retire' => 2];

    /**
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed>
     */
    public function translate(array $plan): array
    {
        $rawActions = is_array($plan['actions'] ?? null) ? $plan['actions'] : [];

        $taskSpecs = [];
        $refused   = [];

        $mergeOrgans = [];
        foreach ($rawActions as $action) {
            if (is_array($action) && ($action['type'] ?? '') === 'merge') {
                $mergeOrgans[] = strtolower(trim((string) ($action['organ'] ?? '')));
            }
        }

        foreach ($rawActions as $action) {
            if (! is_array($action)) {
                continue;
            }

            $type  = trim(strtolower((string) ($action['type']  ?? '')));
            $organ = trim((string) ($action['organ'] ?? ''));
            $owner = trim((string) ($action['replacement_owner'] ?? ''));
            $tests = is_array($action['behavior_preservation_tests'] ?? null)
                        ? array_values(array_filter(array_map('strval', $action['behavior_preservation_tests'])))
                        : [];
            $files = is_array($action['allowed_files'] ?? null)
                        ? array_values(array_filter(array_map('strval', $action['allowed_files'])))
                        : [];

            $refusalReason = $this->refusalReason($type, $owner, $tests, $files);
            if ($refusalReason !== null) {
                $refused[] = ['organ' => $organ, 'action_type' => $type, 'reason' => $refusalReason];
                continue;
            }

            [$implFile, $testFile] = $this->splitImplAndTest($files, $organ);

            $taskSpecs[] = [
                'action_type'                => $type,
                'organ'                      => $organ,
                'implementation_file'        => $implFile,
                'test_file'                  => $testFile,
                'allowed_files'              => $files,
                'acceptance_criteria'        => $this->buildAcceptance($type, $organ, $owner, $tests),
                'required_evidence'          => $this->buildEvidence($type, $organ, $owner),
                'behavior_preservation_gates' => $this->buildGates($tests, $organ),
                'depends_on'                 => $this->buildDeps($type, $organ, $mergeOrgans),
            ];
        }

        usort($taskSpecs, fn(array $a, array $b): int =>
            (self::ACTION_ORDER[$a['action_type']] ?? 99) <=> (self::ACTION_ORDER[$b['action_type']] ?? 99)
        );

        return [
            'schema'     => self::SCHEMA,
            'task_specs' => $taskSpecs,
            'refused'    => $refused,
        ];
    }

    private function refusalReason(string $type, string $owner, array $tests, array $files): ?string
    {
        if ($type === 'merge' && $owner === '') {
            return 'merge_requires_replacement_owner';
        }
        if ($tests === []) {
            return 'behavior_preservation_tests_required';
        }
        if ($files === []) {
            return 'allowed_files_required';
        }
        $hasImpl = (bool) array_filter($files, fn(string $f): bool => ! str_ends_with($f, 'Test.php'));
        $hasTest = (bool) array_filter($files, fn(string $f): bool => str_ends_with($f, 'Test.php'));
        if (! $hasImpl) {
            return 'test_only_allowed_files_refused';
        }
        if (! $hasTest) {
            return 'implementation_only_allowed_files_refused';
        }
        return null;
    }

    /** @return array{string,string} [implFile, testFile] */
    private function splitImplAndTest(array $files, string $organ): array
    {
        $implFile = '';
        $testFile = '';
        foreach ($files as $f) {
            if (str_ends_with($f, 'Test.php')) {
                $testFile = $f;
            } else {
                $implFile = $f;
            }
        }
        if ($implFile === '' && $organ !== '') {
            $implFile = 'app/Services/Ai/SelfConstruction/'.$organ.'.php';
        }
        if ($testFile === '' && $organ !== '') {
            $testFile = 'tests/Unit/Ai/SelfConstruction/'.$organ.'Test.php';
        }
        return [$implFile, $testFile];
    }

    /** @param list<string> $tests */
    private function buildAcceptance(string $type, string $organ, string $owner, array $tests): array
    {
        $criteria = [];

        match ($type) {
            'merge'    => $criteria[] = 'Merge '.$organ.' into '.$owner.'; '.$owner.' absorbs all behaviors.',
            'retire'   => $criteria[] = 'Retire '.$organ.'; no consumer references it after retirement.',
            'simplify' => $criteria[] = 'Simplify '.$organ.' without altering externally observable behavior.',
            default    => $criteria[] = ucfirst($type).' '.$organ.'.',
        };

        $criteria[] = 'Run /opt/homebrew/bin/php artisan test with all behavior-preservation gates green.';

        foreach ($tests as $t) {
            $criteria[] = 'Behavior preserved: '.$t;
        }

        return $criteria;
    }

    private function buildEvidence(string $type, string $organ, string $owner): array
    {
        $evidence = [
            'tests_or_gates_result',
            'implementation_notes',
        ];

        if ($type === 'merge') {
            $evidence[] = 'Confirm '.$owner.' absorbs every public method and behavior of '.$organ.'.';
        }

        if ($type === 'retire') {
            $evidence[] = 'Confirm no remaining consumers reference '.$organ.' after retirement.';
        }

        return $evidence;
    }

    /** @param list<string> $tests */
    private function buildGates(array $tests, string $organ): array
    {
        return array_map(static fn(string $t): array => [
            'description' => $t,
            'command'     => '/opt/homebrew/bin/php artisan test --filter='.escapeshellarg($t),
        ], $tests);
    }

    /** @param list<string> $mergeOrgans */
    private function buildDeps(string $type, string $organ, array $mergeOrgans): array
    {
        if ($type !== 'retire') {
            return [];
        }
        if (in_array(strtolower($organ), $mergeOrgans, true)) {
            return ['merge:'.$organ];
        }
        return [];
    }
}
