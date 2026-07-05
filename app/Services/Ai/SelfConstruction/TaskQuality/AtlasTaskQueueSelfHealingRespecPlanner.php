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
 * IMPLEMENTABILITY / RESIDUAL RISK:
 *   implementability_before — true only when no issue was detected on the input packet.
 *   implementability_after  — true when every detected issue type has a deterministic,
 *     always-resolving respec action (add_implementation_file, add_test_file,
 *     revise_acceptance_criteria); false when a forbidden_target issue is present, since
 *     replacing a forbidden target requires picking a genuinely new, permitted target that
 *     this planner cannot itself invent — that risk is named in residual_risk instead of
 *     silently claimed resolved.
 *   residual_risk            — list of facts about what a respec does NOT guarantee.
 *
 * PROPOSED RESPEC VALIDATION (opt-in via proposed_allowed_files / proposed_acceptance_criteria):
 *   Refuses a caller-supplied candidate respec that (a) adds allowed_files unrelated to the
 *   original target/scope (broad_scope_expansion), or (b) drops an original acceptance
 *   criterion that asserted a real runnable proof gate without replacing it
 *   (removed_meaningful_proof_gate). A minimal, in-scope, proof-preserving respec is valid.
 *
 * OUTPUT:
 *   { schema, respec_required, issues, respec_actions, evidence_requirements,
 *     implementability_before, implementability_after, residual_risk,
 *     proposed_respec_validation }
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
            $issues[]        = [
                'type'       => 'scope_removes_implementation',
                'detail'     => 'Scope repair left no implementation file in allowed_files.',
                'root_cause' => 'scope_repair_removed_impl_file',
            ];
            $respecActions[] = ['action' => 'add_implementation_file', 'rationale' => 'Re-include or add an implementation file to allowed_files.'];
        }

        // 2. Contradictory acceptance.
        $contradictionPair = $this->findContradictionPair($acceptance);
        if ($hasContradictory || $contradictionPair !== null) {
            $issues[]        = [
                'type'       => 'contradictory_acceptance',
                'detail'     => 'Acceptance criteria contain contradictory requirements.',
                'root_cause' => $contradictionPair !== null
                    ? "conflicting_criteria:\"{$contradictionPair[0]}\" vs \"{$contradictionPair[1]}\""
                    : 'explicit_contradictory_acceptance_flag',
            ];
            $respecActions[] = ['action' => 'revise_acceptance_criteria', 'rationale' => 'Remove or reconcile contradictory criteria.'];
        }

        // 3. Forbidden target.
        if ($target !== '' && in_array(strtolower($target), $forbiddenTargets, true)) {
            $issues[]        = [
                'type'       => 'forbidden_target',
                'detail'     => 'Target "'.$target.'" is in the forbidden list.',
                'target'     => $target,
                'root_cause' => 'target_in_forbidden_list',
            ];
            $respecActions[] = ['action' => 'replace_target', 'target' => $target, 'rationale' => 'Choose a different, permitted target.'];
        }

        // 4. Missing test path.
        if ($allowedFiles !== [] && $hasImpl && ! $hasTest) {
            $issues[]        = [
                'type'       => 'missing_test_path',
                'detail'     => 'Implementation file present but no test file in allowed_files.',
                'root_cause' => 'no_test_file_in_allowed_files',
            ];
            $respecActions[] = ['action' => 'add_test_file', 'rationale' => 'Add a matching *Test.php to allowed_files.'];
        }

        // 5. Test-only packet.
        if ($allowedFiles !== [] && ! $hasImpl && $hasTest) {
            $issues[]        = [
                'type'       => 'test_only_packet',
                'detail'     => 'allowed_files contains only test files; no implementation.',
                'root_cause' => 'allowed_files_contains_only_test_files',
            ];
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
        if ($contradictionPair !== null) {
            $evidenceReqs[] = "Reconcile: \"{$contradictionPair[0]}\" vs \"{$contradictionPair[1]}\".";
        }

        // ── runnable_command hint: ensure acceptance requires a real artisan test path ──
        $runnableCommand = null;
        $nonFatalDiscoveryHints = [];
        if ($respecRequired) {
            $runnableCommand = '/opt/homebrew/bin/php artisan test --filter=<TestClass> <test_path>';
            foreach ($issues as $issue) {
                if (in_array($issue['type'], ['missing_test_path', 'test_only_packet'], true)) {
                    $nonFatalDiscoveryHints[] = 'rg no-match or missing test files are expected discovery facts, not task failures — use search_files/grep to locate the class before treating it as missing.';
                }
            }
        }

        $issueTypes = array_column($issues, 'type');
        $hasForbiddenTarget = in_array('forbidden_target', $issueTypes, true);
        $implementabilityBefore = ! $respecRequired;
        $implementabilityAfter = ! $respecRequired || ! $hasForbiddenTarget;
        $residualRisk = [];
        if ($hasForbiddenTarget) {
            $residualRisk[] = 'forbidden_target_replacement_requires_a_new_permitted_target_not_invented_by_this_planner';
        }

        $proposalGiven = array_key_exists('proposed_allowed_files', $packet) || array_key_exists('proposed_acceptance_criteria', $packet);
        $proposedValidation = $proposalGiven
            ? $this->validateProposedRespec($packet, $allowedFiles, $acceptance, $target)
            : null;

        // ── replacement_readiness: are the respec'd files directly claimable? ──
        $replacementReadiness = null;
        if ($respecRequired) {
            $replacementReadiness = [
                'has_impl'               => $hasImpl,
                'has_test'               => $hasTest,
                'has_runnable_command'   => $runnableCommand !== null,
                'claimable_after_respec' => $hasImpl && $hasTest && $implementabilityAfter,
            ];
        }

        return [
            'schema'                  => self::SCHEMA,
            'respec_required'         => $respecRequired,
            'issues'                  => $issues,
            'respec_actions'          => $respecActions,
            'evidence_requirements'   => $evidenceReqs,
            'runnable_command_hint'   => $runnableCommand,
            'non_fatal_discovery_hints' => array_values(array_unique($nonFatalDiscoveryHints)),
            'implementability_before' => $implementabilityBefore,
            'implementability_after'  => $implementabilityAfter,
            'residual_risk'           => $residualRisk,
            'replacement_readiness'   => $replacementReadiness,
            'proposed_respec_validation' => $proposedValidation,
        ];
    }

    /**
     * Refuses a proposed respec that expands scope beyond the original target's directory or
     * silently drops an original acceptance criterion that asserted a real runnable proof gate.
     * A minimal respec never needs to reach outside the target's own directory or shed proof.
     *
     * @param  array<string,mixed>  $packet
     * @param  list<string>  $originalAllowedFiles
     * @param  list<string>  $originalAcceptance
     * @return array<string,mixed>
     */
    private function validateProposedRespec(array $packet, array $originalAllowedFiles, array $originalAcceptance, string $target): array
    {
        $proposedAllowedFiles = is_array($packet['proposed_allowed_files'] ?? null)
            ? array_map('strval', $packet['proposed_allowed_files'])
            : $originalAllowedFiles;
        $proposedAcceptance = is_array($packet['proposed_acceptance_criteria'] ?? null)
            ? array_map('strval', $packet['proposed_acceptance_criteria'])
            : $originalAcceptance;

        $refusalReasons = [];

        $originalDirs = array_unique(array_map(static fn (string $f): string => dirname($f), $originalAllowedFiles));
        $newFiles = array_values(array_diff($proposedAllowedFiles, $originalAllowedFiles));
        foreach ($newFiles as $file) {
            $inScope = false;
            foreach ($originalDirs as $dir) {
                if ($dir !== '.' && ($file === $dir || str_starts_with($file, $dir.'/'))) {
                    $inScope = true;
                    break;
                }
            }
            if (! $inScope && $target !== '' && ! str_contains($file, $target)) {
                $refusalReasons[] = 'broad_scope_expansion:'.$file;
            }
        }

        $droppedCriteria = array_values(array_diff($originalAcceptance, $proposedAcceptance));
        foreach ($droppedCriteria as $criterion) {
            if ($this->isRunnableProofCriterion($criterion)) {
                $refusalReasons[] = 'removed_meaningful_proof_gate:'.$criterion;
            }
        }

        return [
            'valid'            => $refusalReasons === [],
            'refusal_reasons'  => $refusalReasons,
        ];
    }

    private function isRunnableProofCriterion(string $criterion): bool
    {
        $lc = strtolower($criterion);
        foreach (['artisan test', 'phpunit', 'exits 0', 'exit 0', 'exit code 0', 'green output', 'assert'] as $needle) {
            if (str_contains($lc, $needle)) {
                return true;
            }
        }

        return false;
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

    /**
     * @param  list<string>  $criteria
     * @return array{0:string,1:string}|null  the conflicting [must_not_criterion, must_criterion] pair, or null
     */
    private function findContradictionPair(array $criteria): ?array
    {
        $mustNot = [];
        $must    = [];

        foreach ($criteria as $c) {
            $trimmed = trim($c);
            $lc = strtolower($trimmed);
            if (str_starts_with($lc, 'must not ')) {
                $mustNot[] = [substr($lc, 9), $trimmed];
            } elseif (str_starts_with($lc, 'must ')) {
                $must[] = [substr($lc, 5), $trimmed];
            }
        }

        foreach ($mustNot as [$neg, $negOriginal]) {
            foreach ($must as [$pos, $posOriginal]) {
                // Overlap: the positive clause starts with or contains the same subject/verb.
                $negWords = explode(' ', $neg);
                $posWords = explode(' ', $pos);
                $overlap  = count(array_intersect($negWords, $posWords));
                if ($overlap >= 2) {
                    return [$negOriginal, $posOriginal];
                }
            }
        }

        return null;
    }
}
