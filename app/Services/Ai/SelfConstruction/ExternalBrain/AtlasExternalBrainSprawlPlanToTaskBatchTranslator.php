<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Turns organ sprawl-reduction plans into concrete simplification task specs.
 * PURE / DETERMINISTIC. Never deletes code or enqueues tasks.
 *
 * SUPPORTED ACTION TYPES: merge | retire | simplify
 *
 * REFUSAL RULES (any match → refused entry, action skipped from task_specs):
 *   1. type=merge AND replacement_owner is missing/empty
 *   2. behavior_preservation_tests is missing or empty list
 *   3. allowed_files is missing or empty list
 *
 * DEPENDENCY ORDER (stable, lowest first):
 *   merge=0 → simplify=1 → retire=2
 *   retire actions for an organ also depend on any merge that lists the same organ.
 *
 * INPUT:
 *   {
 *     actions: list<{
 *       type:                        string  (merge|retire|simplify)
 *       organ:                       string
 *       replacement_owner?:          string  (required for merge)
 *       behavior_preservation_tests: list<string>
 *       allowed_files:               list<string>
 *     }>
 *   }
 *
 * OUTPUT:
 *   {
 *     schema,
 *     task_specs:  list<{ action_type, organ, implementation_file, test_file,
 *                         acceptance_criteria, required_evidence, depends_on }>,
 *     refused:     list<{ organ, action_type, reason }>
 *   }
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

        // Track which organs are targets of merge (for retire dependency resolution).
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

            $type   = trim(strtolower((string) ($action['type'] ?? '')));
            $organ  = trim((string) ($action['organ'] ?? ''));
            $owner  = trim((string) ($action['replacement_owner'] ?? ''));
            $tests  = is_array($action['behavior_preservation_tests'] ?? null)
                        ? array_filter(array_map('strval', $action['behavior_preservation_tests']))
                        : [];
            $files  = is_array($action['allowed_files'] ?? null)
                        ? array_filter(array_map('strval', $action['allowed_files']))
                        : [];

            // Validate.
            $refusalReason = null;
            if ($type === 'merge' && $owner === '') {
                $refusalReason = 'merge_requires_replacement_owner';
            } elseif (empty($tests)) {
                $refusalReason = 'behavior_preservation_tests_required';
            } elseif (empty($files)) {
                $refusalReason = 'allowed_files_required';
            }

            if ($refusalReason !== null) {
                $refused[] = [
                    'organ'       => $organ,
                    'action_type' => $type,
                    'reason'      => $refusalReason,
                ];
                continue;
            }

            $files = array_values($files);
            [$implFile, $testFile] = $this->splitImplAndTest($files, $organ, $type);

            $taskSpecs[] = [
                'action_type'         => $type,
                'organ'               => $organ,
                'implementation_file' => $implFile,
                'test_file'           => $testFile,
                'acceptance_criteria' => $this->buildAcceptance($type, $organ, $owner, array_values($tests)),
                'required_evidence'   => $this->buildEvidence($type, $organ, $owner),
                'depends_on'          => $this->buildDeps($type, $organ, $mergeOrgans),
            ];
        }

        // Sort task_specs by action order (stable: merge → simplify → retire).
        usort($taskSpecs, fn(array $a, array $b): int =>
            (self::ACTION_ORDER[$a['action_type']] ?? 99) <=> (self::ACTION_ORDER[$b['action_type']] ?? 99)
        );

        return [
            'schema'     => self::SCHEMA,
            'task_specs' => $taskSpecs,
            'refused'    => $refused,
        ];
    }

    /** @return array{string,string} [implFile, testFile] */
    private function splitImplAndTest(array $files, string $organ, string $type): array
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

        // Fallback derivation from organ name.
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

        $criteria[] = './vendor/bin/phpunit exits 0 with all behavior-preservation tests green.';

        foreach ($tests as $t) {
            $criteria[] = 'Behavior preserved: '.$t;
        }

        return $criteria;
    }

    private function buildEvidence(string $type, string $organ, string $owner): array
    {
        $evidence = [
            'Confirm allowed_files contains exactly the implementation + test file pair.',
            'Confirm all behavior_preservation_tests are included in the test file.',
        ];

        if ($type === 'merge') {
            $evidence[] = 'Confirm '.$owner.' absorbs every public method and behavior of '.$organ.'.';
        }

        if ($type === 'retire') {
            $evidence[] = 'Confirm no remaining consumers reference '.$organ.' after retirement.';
        }

        return $evidence;
    }

    /** @param list<string> $mergeOrgans lowercase organ names being merged */
    private function buildDeps(string $type, string $organ, array $mergeOrgans): array
    {
        if ($type !== 'retire') {
            return [];
        }

        // A retire depends on any merge that affects the same organ.
        if (in_array(strtolower($organ), $mergeOrgans, true)) {
            return ['merge:'.$organ];
        }

        return [];
    }
}
