<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskFabric;

use App\Services\Ai\SelfConstruction\AtlasTaskPacketQualityInspector;
use App\Services\Ai\SelfConstruction\TaskQuality\AtlasTaskWorkerInstructionLint;

/**
 * Task Fabric PREFLIGHT — validates draft packet specs BEFORE they reach the existing
 * {@see AtlasTaskPacketQualityInspector}. Returns FACTS only — no queue mutation.
 *
 * REQUIRED SPEC FIELDS:
 *   { objective, allowed_files:list<string>, scope_in:list<string>, acceptance_criteria:list<string>,
 *     required_evidence:list<string>, rollback_hint:string, workspace_policy:array, simplicity_contract:string }
 *
 * BLOCKER FAMILIES:
 *   - missing_objective / missing_acceptance_criteria / missing_required_evidence / missing_rollback_hint
 *   - allowed_files:broad_directory:<path>   (entries without basename '.')
 *   - workspace_policy:non_shared_main       (workspace_policy.execution_topology must be
 *                                             'shared_local_main_with_scope_lock')
 *   - ownership:non_atlas_native             (simplicity_contract must be 'atlas_native')
 *   - missing_gates                          (no acceptance_criteria entry mentions a gate)
 *
 * INVARIANTS:
 *   - DETERMINISTIC: identical input ⇒ byte-identical envelope.
 *   - NEVER mutates the queue. Optionally DELEGATES to AtlasTaskPacketQualityInspector::inspect()
 *     when a full packet shape is supplied (containing 'task_packet_id') — the inspector is read-only.
 */
final class AtlasTaskFabricPacketSpecValidator
{
    public const SCHEMA = 'atlas.taskfabric.packet_spec_validation.v1';

    public const REQUIRED_TOPOLOGY = 'shared_local_main_with_scope_lock';

    public const REQUIRED_SIMPLICITY = 'atlas_native';

    /** @param null|AtlasTaskPacketQualityInspector|object{inspect:callable} $inspector */
    public function __construct(private readonly ?object $inspector = null) {}

    /**
     * @param  array<string,mixed>  $spec
     * @return array{schema:string, self_sufficient:bool, blockers:list<string>, delegated_inspector:array<string,mixed>|null, worker_instruction_lint:array{schema:string, accepted:bool, findings:list<string>}}
     */
    public function validate(array $spec): array
    {
        $blockers = [];

        if (trim((string) ($spec['objective'] ?? '')) === '') {
            $blockers[] = 'missing_objective';
        }

        $allowed = is_array($spec['allowed_files'] ?? null) ? array_values($spec['allowed_files']) : null;
        if ($allowed === null || $allowed === []) {
            $blockers[] = 'missing_allowed_files';
        } else {
            foreach ($allowed as $path) {
                $p = (string) $path;
                if ($p === '' || str_ends_with($p, '/') || ! str_contains(basename($p), '.')) {
                    $blockers[] = 'allowed_files:broad_directory:'.$p;
                }
            }
        }

        $scopeIn = is_array($spec['scope_in'] ?? null) ? array_values($spec['scope_in']) : null;
        if ($scopeIn === null || $scopeIn === []) {
            $blockers[] = 'missing_scope_in';
        }

        $acceptance = is_array($spec['acceptance_criteria'] ?? null) ? array_values(array_map('strval', $spec['acceptance_criteria'])) : null;
        if ($acceptance === null || $acceptance === []) {
            $blockers[] = 'missing_acceptance_criteria';
        } elseif (! $this->mentionsGate($acceptance)) {
            $blockers[] = 'missing_gates';
        } elseif (! $this->hasAcceptanceStrengthFloor($acceptance)) {
            // A gate keyword is present ("phpunit", "test"...) but every criterion is a bare, vague
            // green-check phrase — that proves the pipeline ran, never what BEHAVIOR it proved.
            $blockers[] = 'weak_acceptance_strength';
        }

        $evidence = is_array($spec['required_evidence'] ?? null) ? array_values($spec['required_evidence']) : null;
        if ($evidence === null || $evidence === []) {
            $blockers[] = 'missing_required_evidence';
        }

        if (trim((string) ($spec['rollback_hint'] ?? '')) === '') {
            $blockers[] = 'missing_rollback_hint';
        }

        $wp = is_array($spec['workspace_policy'] ?? null) ? $spec['workspace_policy'] : null;
        if ($wp === null) {
            $blockers[] = 'missing_workspace_policy';
        } elseif ((string) ($wp['execution_topology'] ?? '') !== self::REQUIRED_TOPOLOGY) {
            $blockers[] = 'workspace_policy:non_shared_main';
        }

        $simplicity = (string) ($spec['simplicity_contract'] ?? '');
        if ($simplicity !== self::REQUIRED_SIMPLICITY) {
            $blockers[] = 'ownership:non_atlas_native';
        }

        // Implementation + test spec: allowed_files must contain both kinds.
        if ($allowed !== null && $allowed !== []) {
            $hasImpl = false;
            $hasTest = false;
            foreach ($allowed as $path) {
                $p = (string) $path;
                if ($this->isTestFile($p)) {
                    $hasTest = true;
                } else {
                    $hasImpl = true;
                }
            }
            if (! $hasImpl) {
                $blockers[] = 'missing_implementation_spec';
            }
            if (! $hasTest) {
                $blockers[] = 'missing_test_spec';
            }
        }

        // Template-farm detection: curly-brace placeholders in objective.
        $objective = trim((string) ($spec['objective'] ?? ''));
        if (preg_match('/\{[^}]+\}/', $objective)) {
            $blockers[] = 'template_farm_objective';
        }

        // Cosmetic-only detection: objective describes a zero-behavior change.
        if (preg_match('/\b(fix[_ -]whitespace|reformat[_ -]only|cosmetic[_ -]only|fix[_ -]indentation|cleanup[_ -]trailing|no[_ -]behavior[_ -]change)\b/i', $objective)) {
            $blockers[] = 'cosmetic_only_objective';
        }

        // Duplicate-target check: the spec must declare whether this objective already exists
        // as a capability, else the originator can flood the queue with re-implementations.
        if (! array_key_exists('duplicate_target_check', $spec) || trim((string) ($spec['duplicate_target_check'] ?? '')) === '') {
            $blockers[] = 'missing_duplicate_target_check';
        }

        // Steady-state autonomy constraint: the spec must explicitly declare it needs no human
        // or external provider dependency to complete, else it cannot run in 24/7 autonomy.
        if (! array_key_exists('steady_state_constraint', $spec) || (string) ($spec['steady_state_constraint'] ?? '') !== 'no_human_or_provider_dependency') {
            $blockers[] = 'missing_steady_state_constraint';
        }

        $delegated = null;
        if ($this->inspector !== null && isset($spec['task_packet_id'])) {
            // Delegate to the existing inspector for the full quality check. Read-only.
            $delegated = $this->inspector->inspect($spec);
        }

        // ADVISORY worker-instruction lint: wire the pure AtlasTaskWorkerInstructionLint into the preflight so
        // worker-facing instruction poison (run_git_manually, edit_outside_allowed_files,
        // ask_human_for_normal_progress, ignore_give_back_when_capability_exists) is caught here — neither the
        // structural blockers nor the delegated inspector flag it today. NON-BLOCKING, exactly like
        // delegated_inspector: a surfaced fact only, never pushed into blockers, never flips self_sufficient.
        $workerInstructionLint = (new AtlasTaskWorkerInstructionLint)->lint($spec);

        sort($blockers, SORT_STRING);

        return [
            'schema' => self::SCHEMA,
            'self_sufficient' => $blockers === [],
            'blockers' => $blockers,
            'blocking_deficiencies' => $blockers,
            'repair_hints' => array_map($this->repairHint(...), $blockers),
            'delegated_inspector' => $delegated,
            'worker_instruction_lint' => $workerInstructionLint,
        ];
    }

    private function repairHint(string $blocker): string
    {
        return match (true) {
            $blocker === 'missing_objective' => 'add a concrete objective describing the real change',
            $blocker === 'missing_allowed_files' => 'add at least one concrete implementation file to allowed_files',
            str_starts_with($blocker, 'allowed_files:broad_directory:') => 'replace the broad directory entry with concrete file paths',
            $blocker === 'missing_scope_in' => 'add at least one concrete path to scope_in',
            $blocker === 'missing_acceptance_criteria' => 'add a runnable acceptance criterion (e.g. a phpunit/pint command)',
            $blocker === 'missing_gates' => 'add an acceptance criterion that references a runnable gate (test/phpunit/pint/verify)',
            $blocker === 'weak_acceptance_strength' => 'name the specific behavior invariant being proven (e.g. which class/method and what it must do), not just "phpunit green" or "tests pass"',
            $blocker === 'missing_required_evidence' => 'add at least one required_evidence entry (e.g. test_run_id)',
            $blocker === 'missing_rollback_hint' => 'add a rollback_hint describing how to revert this change',
            $blocker === 'missing_workspace_policy' => 'add workspace_policy.execution_topology=shared_local_main_with_scope_lock',
            $blocker === 'workspace_policy:non_shared_main' => 'set workspace_policy.execution_topology to shared_local_main_with_scope_lock',
            $blocker === 'ownership:non_atlas_native' => 'set simplicity_contract to atlas_native',
            $blocker === 'missing_implementation_spec' => 'add a concrete implementation file (not just a test file) to allowed_files',
            $blocker === 'missing_test_spec' => 'add a concrete test file to allowed_files',
            $blocker === 'template_farm_objective' => 'replace the curly-brace placeholder in objective with a concrete description',
            $blocker === 'cosmetic_only_objective' => 'describe a real behavior change instead of a cosmetic-only edit',
            $blocker === 'missing_duplicate_target_check' => 'set duplicate_target_check to a non-empty statement of whether this capability already exists',
            $blocker === 'missing_steady_state_constraint' => 'set steady_state_constraint to no_human_or_provider_dependency',
            default => 'resolve: '.$blocker,
        };
    }

    private function isTestFile(string $path): bool
    {
        return str_starts_with($path, 'tests/')
            || str_contains($path, '/tests/')
            || str_ends_with($path, 'Test.php')
            || str_ends_with($path, 'Spec.php');
    }

    /**
     * @param  list<string>  $acceptance
     */
    private function mentionsGate(array $acceptance): bool
    {
        foreach ($acceptance as $line) {
            if (preg_match('/phpunit|pint|pest|test|gate|verify|assert/i', $line)) {
                return true;
            }
        }

        return false;
    }

    /** Bare "it ran" phrases that prove NOTHING about behavior — vague-only when this is ALL a spec says. */
    private const VAGUE_ACCEPTANCE_PHRASES = [
        'phpunit green', 'phpunit passes', 'phpunit pass', 'test passes', 'tests pass', 'tests passes',
        'all tests pass', 'test green', 'tests green', 'green', 'ci green', 'gate passes', 'gate green',
    ];

    /**
     * True when at least one acceptance criterion carries a behavior-specific assertion signal:
     * a concrete class/method/path reference, or a wording that names WHAT is being proven — never
     * satisfied by a bare "it ran green" phrase alone.
     *
     * @param  list<string>  $acceptance
     */
    private function hasAcceptanceStrengthFloor(array $acceptance): bool
    {
        foreach ($acceptance as $line) {
            $normalized = trim(preg_replace('/\s+/', ' ', strtolower($line)) ?? '');
            $normalized = rtrim($normalized, '.!');
            if (! in_array($normalized, self::VAGUE_ACCEPTANCE_PHRASES, true)) {
                return true;
            }
        }

        return false;
    }
}
