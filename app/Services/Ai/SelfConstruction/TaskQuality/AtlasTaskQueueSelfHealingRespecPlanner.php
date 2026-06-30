<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskQuality;

/**
 * Detects malformed or poison-prone task packets before muscles waste tokens, and emits
 * safe respec proposals. NEVER mutates queue state.
 *
 * DETECTION CHECKS (all applied; every match adds an issue):
 *
 *   scope_removes_implementation
 *     — allowed_files after scope-repair contains NO implementation file
 *       but the original packet had one (or scope_repair_removed_impl=true is set)
 *
 *   contradictory_acceptance
 *     — acceptance_criteria contains any criterion that starts with "must not" AND another
 *       criterion that contradicts it (starts with "must" + same verb/subject), OR
 *       has_contradictory_acceptance=true is set explicitly
 *
 *   forbidden_target
 *     — target matches any entry in forbidden_targets list
 *
 *   missing_test_path
 *     — allowed_files has at least one impl file but NO test file
 *
 *   test_only_packet
 *     — allowed_files has ONLY test files (no impl file at all)
 *
 * RESPEC ACTIONS emitted per issue:
 *   scope_removes_implementation  → add_implementation_file
 *   contradictory_acceptance      → revise_acceptance_criteria
 *   forbidden_target              → replace_target
 *   missing_test_path             → add_test_file
 *   test_only_packet              → add_implementation_file
 *
 * EVIDENCE REQUIREMENTS are always emitted when respec is required.
 *
 * OUTPUT:
 *   { schema, respec_required, issues, respec_actions, evidence_requirements }
 *
 * PURE / DETERMINISTIC / NO I/O.
 */
final class AtlasTaskQueueSelfHealingRespecPlanner
{
    public const SCHEMA = 'atlas.task_queue.self_healing_respec_planner.v1';

    private const IMPL_SUFFIXES = [
        'Service.php', 'Command.php', 'Controller.php', 'Repository.php',
        'Handler.php', 'Listener.php', 'Job.php', 'Policy.php', 'Provider.php',
        'Planner.php', 'Gate.php', 'Compiler.php', 'Court.php', 'Ledger.php',
    ];
    private const IMPL_PATH_SEGMENTS = [
        '/Services/', '/Commands/', '/Controllers/', '/Repositories/', '/TaskFabric/',
        '/TaskQuality/', '/ExternalBrain/',
    ];
    private const TEST_SUFFIX = 'Test.php';

    /**
     * @param  array<string,mixed>  $packet
     * @return array<string,mixed>
     */
    public function plan(array $packet): array
    {
        $allowedFiles     = is_array($packet['allowed_files'] ?? null)
            ? array_map('strval', $packet['allowed_files'])
            : [];
        $acceptance       = is_array($packet['acceptance_criteria'] ?? null)
            ? array_map('strval', $packet['acceptance_criteria'])
            : [];
        $target           = trim((string) ($packet['target'] ?? ''));
        $forbiddenTargets = is_array($packet['forbidden_targets'] ?? null)
            ? array_map('strtolower', array_map('trim', array_map('strval', $packet['forbidden_targets'])))
            : [];
        $scopeRepairRemovedImpl = (bool) ($packet['scope_repair_removed_impl'] ?? false);
        $hasContradictory       = (bool) ($packet['has_contradictory_acceptance'] ?? false);

        [$hasImpl, $hasTest] = $this->classifyFiles($allowedFiles);

        $issues        = [];
        $respecActions = [];

        // 1. Scope removes implementation.
        if ($scopeRepairRemovedImpl || ($allowedFiles !== [] && ! $hasImpl && ! $hasTest)) {
            $issues[]        = ['type' => 'scope_removes_implementation', 'detail' => 'Scope repair left no implementation file in allowed_files.'];
            $respecActions[] = ['action' => 'add_implementation_file', 'rationale' => 'Re-include or add an implementation file to allowed_files.'];
        }

        // 2. Contradictory acceptance.
        if ($hasContradictory || $this->hasContradiction($acceptance)) {
            $issues[]        = ['type' => 'contradictory_acceptance', 'detail' => 'Acceptance criteria contain contradictory requirements.'];
            $respecActions[] = ['action' => 'revise_acceptance_criteria', 'rationale' => 'Remove or reconcile contradictory criteria.'];
        }

        // 3. Forbidden target.
        if ($target !== '' && in_array(strtolower($target), $forbiddenTargets, true)) {
            $issues[]        = ['type' => 'forbidden_target', 'detail' => 'Target "'.$target.'" is in the forbidden list.', 'target' => $target];
            $respecActions[] = ['action' => 'replace_target', 'target' => $target, 'rationale' => 'Choose a different, permitted target.'];
        }

        // 4. Missing test path.
        if ($allowedFiles !== [] && $hasImpl && ! $hasTest) {
            $issues[]        = ['type' => 'missing_test_path', 'detail' => 'Implementation file present but no test file in allowed_files.'];
            $respecActions[] = ['action' => 'add_test_file', 'rationale' => 'Add a matching *Test.php to allowed_files.'];
        }

        // 5. Test-only packet.
        if ($allowedFiles !== [] && ! $hasImpl && $hasTest) {
            $issues[]        = ['type' => 'test_only_packet', 'detail' => 'allowed_files contains only test files; no implementation.'];
            $respecActions[] = ['action' => 'add_implementation_file', 'rationale' => 'Add the corresponding implementation file to allowed_files.'];
        }

        $respecRequired = $issues !== [];
        $evidenceReqs   = $respecRequired
            ? [
                'Confirm allowed_files contains exactly the implementation + test file pair.',
                'Verify acceptance_criteria are non-contradictory and non-empty.',
                'Confirm target is not in the forbidden list.',
            ]
            : [];

        return [
            'schema'                => self::SCHEMA,
            'respec_required'       => $respecRequired,
            'issues'                => $issues,
            'respec_actions'        => $respecActions,
            'evidence_requirements' => $evidenceReqs,
        ];
    }

    /** @return array{bool,bool} [hasImpl, hasTest] */
    private function classifyFiles(array $files): array
    {
        $hasImpl = false;
        $hasTest = false;

        foreach ($files as $file) {
            if (str_ends_with($file, self::TEST_SUFFIX)) {
                $hasTest = true;
                continue;
            }
            foreach (self::IMPL_SUFFIXES as $suffix) {
                if (str_ends_with($file, $suffix)) {
                    $hasImpl = true;
                    break;
                }
            }
            if (! $hasImpl) {
                foreach (self::IMPL_PATH_SEGMENTS as $segment) {
                    if (str_contains($file, $segment) && str_ends_with($file, '.php')) {
                        $hasImpl = true;
                        break;
                    }
                }
            }
        }

        return [$hasImpl, $hasTest];
    }

    /** @param list<string> $criteria */
    private function hasContradiction(array $criteria): bool
    {
        $mustNot = [];
        $must    = [];

        foreach ($criteria as $c) {
            $lc = strtolower(trim($c));
            if (str_starts_with($lc, 'must not ')) {
                $mustNot[] = substr($lc, 9);
            } elseif (str_starts_with($lc, 'must ')) {
                $must[] = substr($lc, 5);
            }
        }

        foreach ($mustNot as $neg) {
            foreach ($must as $pos) {
                // Overlap: the positive clause starts with or contains the same subject/verb.
                $negWords = explode(' ', $neg);
                $posWords = explode(' ', $pos);
                $overlap  = count(array_intersect($negWords, $posWords));
                if ($overlap >= 2) {
                    return true;
                }
            }
        }

        return false;
    }
}
