<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Pattern;

use InvalidArgumentException;

/**
 * LOOP-PATTERN-REGISTRY · Slice 1 — the deterministic repertoire of governed loop execution patterns.
 *
 * The registry is the read-model the selector ranks over. It is PURE and in-memory: the same seed set
 * always yields the same ordering, the same find() result, the same selectable() set — so the loop's
 * pattern choice is reproducible and testable, never a function of wall-clock or hidden state.
 *
 * Governance lanes (status, from {@see AtlasLoopPatternSpec}):
 *   - default / ready  -> SELECTABLE (the operator-seeded Atlas-native champions for THIS slice)
 *   - candidate        -> proposed, not yet selectable (awaits ChampionGate)
 *   - source_material  -> external inspiration in quarantine, never selectable
 *   - deprecated       -> tombstoned (e.g. a cosmetic behaviour-preserving pattern, kept as a no-go marker)
 *
 * Invariants:
 *   - A pattern that fails {@see AtlasLoopPatternSpec} validation can never enter the registry (fromArray throws).
 *   - External-provenance material can never be born selectable (the Spec quarantines it to candidate;
 *     the registry additionally refuses to mark any external source default/ready). Promotion is the
 *     {@see AtlasLoopPatternChampionGate}'s job, not the registry's.
 */
final class AtlasLoopPatternRegistry
{
    /** @var array<string, AtlasLoopPatternSpec> keyed by "id@version" for deterministic lookup. */
    private array $patterns = [];

    /**
     * @param  list<AtlasLoopPatternSpec>|null  $specs  override the seed set (tests); null = the Atlas seeds.
     */
    public function __construct(?array $specs = null)
    {
        foreach ($specs ?? self::seedSpecs() as $spec) {
            $this->register($spec);
        }
    }

    /**
     * Add or replace a pattern. Only an already-validated {@see AtlasLoopPatternSpec} can be registered
     * (the type enforces structural soundness). External provenance is additionally forced out of the
     * selectable lanes here — defence in depth over the Spec's own quarantine.
     */
    public function register(AtlasLoopPatternSpec $spec): void
    {
        if ($spec->isExternalSource() && $spec->isSelectable()) {
            // Should be impossible (Spec quarantines), but the registry refuses to be the hole.
            throw new InvalidArgumentException(
                "Refusing to register external-source pattern '{$spec->id}' as selectable; external ".
                'material must remain source_material/candidate until ChampionGate promotion.'
            );
        }

        $this->patterns[$spec->id.'@'.$spec->version] = $spec;
    }

    /** @return list<AtlasLoopPatternSpec> all registered patterns, deterministic order. */
    public function all(): array
    {
        $all = array_values($this->patterns);
        usort($all, static fn (AtlasLoopPatternSpec $a, AtlasLoopPatternSpec $b): int => [$a->id, $a->version] <=> [$b->id, $b->version]);

        return $all;
    }

    /** @return list<AtlasLoopPatternSpec> only ready/default patterns — what the selector may pick. */
    public function selectable(): array
    {
        return array_values(array_filter($this->all(), static fn (AtlasLoopPatternSpec $s): bool => $s->isSelectable()));
    }

    /** @return list<AtlasLoopPatternSpec> patterns in a given governance lane. */
    public function byStatus(string $status): array
    {
        return array_values(array_filter($this->all(), static fn (AtlasLoopPatternSpec $s): bool => $s->status === $status));
    }

    /**
     * Find a pattern by id (latest registered version) or by exact id+version.
     */
    public function find(string $id, ?string $version = null): ?AtlasLoopPatternSpec
    {
        if ($version !== null) {
            return $this->patterns[$id.'@'.$version] ?? null;
        }

        $matches = array_values(array_filter($this->all(), static fn (AtlasLoopPatternSpec $s): bool => $s->id === $id));

        return $matches === [] ? null : $matches[array_key_last($matches)];
    }

    public function has(string $id): bool
    {
        return $this->find($id) !== null;
    }

    /** @return list<string> the distinct pattern ids, deterministic order. */
    public function ids(): array
    {
        $ids = array_values(array_unique(array_map(static fn (AtlasLoopPatternSpec $s): string => $s->id, $this->all())));
        sort($ids);

        return $ids;
    }

    public function count(): int
    {
        return count($this->patterns);
    }

    /** @return list<AtlasLoopPatternSpec> the Atlas seed patterns, built fail-closed via the Spec factory. */
    public static function seedSpecs(): array
    {
        return array_map(
            static fn (array $def): AtlasLoopPatternSpec => AtlasLoopPatternSpec::fromArray($def),
            self::seedDefinitions()
        );
    }

    /**
     * The seed set. First territory is the Loop itself (per the doc): each pattern maps to a loop
     * objective kind. The 8 named patterns are operator-seeded Atlas-native champions. Two extra entries
     * make the non-selectable lanes REAL and testable: one quarantined external catalog entry, and one
     * deprecated cosmetic tombstone (a behaviour-preserving refactor pattern — explicitly a no-go).
     *
     * @return list<array<string,mixed>>
     */
    public static function seedDefinitions(): array
    {
        $independentLanes = ['lanes' => ['designer', 'implementer', 'verifier', 'critic', 'repair'], 'self_approval' => false, 'verifier_independent' => true];

        return [
            [
                'id' => 'docs_sweep',
                'version' => '1.0.0',
                'name' => 'The docs sweep',
                'description' => 'Update canonical docs AFTER a real code/architecture change; never doc-as-decoration.',
                'intent' => 'docs',
                'trigger_schema' => ['objective_kinds' => ['docs'], 'use_when' => 'code/architecture changed and a canonical doc now lies', 'preconditions' => ['real_code_change']],
                'params_schema' => ['target_docs' => 'list<path>', 'changed_paths' => 'list<path>'],
                'output_schema' => ['updated_docs' => 'list<path>', 'lint_clean' => 'bool', 'references_exist' => 'bool'],
                'success_gates' => ['docs lint passes on every touched doc', 'every code reference in the doc resolves to a real symbol/path'],
                'terminal_states' => ['success', 'clean_no_op', 'blocked'],
                'durability_mode' => 'single_cycle',
                'sandbox_profile' => ['allowed' => ['read_only', 'worktree_write']],
                'agent_lane_policy' => $independentLanes,
                'risk_level' => 'low',
                'source' => 'operator_seed',
                'source_snapshot' => ['inspired_by' => 'Loop Library: The docs sweep', 'captured_at' => '2026-06-19', 'drift_notes' => 'reduced to Atlas docs-as-law vocabulary'],
                'status' => 'default',
            ],
            [
                'id' => 'loop_harness_verification',
                'version' => '1.0.0',
                'name' => 'The Loop Harness verification loop',
                'description' => 'Separate creator and verifier in an isolated worktree so nothing self-certifies.',
                'intent' => 'verification',
                'trigger_schema' => ['objective_kinds' => ['verification', 'refactor'], 'use_when' => 'a change needs an independent green proof', 'preconditions' => ['change_under_test']],
                'params_schema' => ['target_path' => 'path', 'verifier_lane' => 'isolated_worktree'],
                'output_schema' => ['independent_verdict' => 'green|red', 'worktree_receipt' => 'string'],
                'success_gates' => ['an independent verifier (separate lane) runs the suite green in a fresh worktree'],
                'terminal_states' => ['success', 'blocked', 'stagnated'],
                'durability_mode' => 'resumable_state_machine',
                'sandbox_profile' => ['allowed' => ['read_only', 'worktree_write', 'command']],
                'agent_lane_policy' => $independentLanes,
                'risk_level' => 'medium',
                'source' => 'operator_seed',
                'source_snapshot' => ['inspired_by' => 'Loop Library: The Loop Harness verification loop', 'captured_at' => '2026-06-19'],
                'status' => 'default',
            ],
            [
                'id' => 'ticket_to_pr_ready',
                'version' => '1.0.0',
                'name' => 'The ticket-to-PR-ready loop',
                'description' => 'Turn a loose request/bug into a bounded patch with reproducible evidence.',
                'intent' => 'refactor',
                'trigger_schema' => ['objective_kinds' => ['bug', 'refactor', 'feature'], 'use_when' => 'a request must become a bounded, evidenced change', 'preconditions' => ['scoped_request']],
                'params_schema' => ['request' => 'string', 'allowed_globs' => 'list<glob>'],
                'output_schema' => ['bounded_diff' => 'patch', 'evidence' => 'test_receipt'],
                'success_gates' => ['a RED test turns GREEN for the change', 'the diff stays inside the declared allowed scope'],
                'terminal_states' => ['success', 'blocked', 'exhausted'],
                'durability_mode' => 'single_cycle',
                'sandbox_profile' => ['allowed' => ['read_only', 'worktree_write', 'command']],
                'agent_lane_policy' => $independentLanes,
                'risk_level' => 'medium',
                'source' => 'operator_seed',
                'source_snapshot' => ['inspired_by' => 'Loop Library: The ticket-to-PR-ready loop', 'captured_at' => '2026-06-19'],
                'status' => 'default',
            ],
            [
                'id' => 'self_improving_champion',
                'version' => '1.0.0',
                'name' => 'The self-improving champion loop',
                'description' => 'Improve prompts/policies/selectors/patterns WITHOUT Goodhart; champion holds unless beaten fresh.',
                'intent' => 'self_improvement',
                'trigger_schema' => ['objective_kinds' => ['self_improvement'], 'use_when' => 'a loop component can be measurably improved on a fresh battery', 'preconditions' => ['fresh_eval_available']],
                'params_schema' => ['component' => 'string', 'eval_battery' => 'fresh_cases'],
                'output_schema' => ['challenger_delta' => 'float', 'guardrail_regression' => 'bool'],
                'success_gates' => ['challenger beats champion on a FRESH eval battery', 'no guardrail/safety/sovereignty invariant regresses'],
                'terminal_states' => ['success', 'stagnated', 'blocked'],
                'durability_mode' => 'campaign_supervised',
                'sandbox_profile' => ['allowed' => ['read_only', 'worktree_write']],
                'agent_lane_policy' => $independentLanes,
                'risk_level' => 'high',
                'source' => 'operator_seed',
                'source_snapshot' => ['inspired_by' => 'Loop Library: The self-improving champion loop', 'captured_at' => '2026-06-19', 'drift_notes' => 'creator may never approve own challenger'],
                'status' => 'default',
            ],
            [
                'id' => 'fresh_clone',
                'version' => '1.0.0',
                'name' => 'The fresh-clone loop',
                'description' => 'Prove setup, docs and onboarding survive a clean clone with nothing assumed.',
                'intent' => 'verification',
                'trigger_schema' => ['objective_kinds' => ['verification', 'onboarding'], 'use_when' => 'onboarding/setup correctness must be proven from scratch', 'preconditions' => []],
                'params_schema' => ['entry_docs' => 'list<path>'],
                'output_schema' => ['clean_build' => 'bool', 'from_scratch_tests' => 'green|red'],
                'success_gates' => ['a clean clone builds and the documented bootstrap passes with no hidden local state'],
                'terminal_states' => ['success', 'blocked'],
                'durability_mode' => 'single_cycle',
                'sandbox_profile' => ['allowed' => ['read_only', 'command', 'worktree_write']],
                'agent_lane_policy' => $independentLanes,
                'risk_level' => 'low',
                'source' => 'operator_seed',
                'source_snapshot' => ['inspired_by' => 'Loop Library: The fresh-clone loop', 'captured_at' => '2026-06-19'],
                'status' => 'default',
            ],
            [
                'id' => 'production_error_sweep',
                'version' => '1.0.0',
                'name' => 'The production error sweep',
                'description' => 'Real bugs/failures with root cause, fix and reproducible proof — never a guessed patch.',
                'intent' => 'bug',
                'trigger_schema' => ['objective_kinds' => ['bug'], 'use_when' => 'a real failure signal exists', 'preconditions' => ['failure_signal']],
                'params_schema' => ['failure_signature' => 'string', 'repro_command' => 'string'],
                'output_schema' => ['root_cause' => 'string', 'fix_diff' => 'patch', 'repro_green' => 'bool'],
                'success_gates' => ['a failing reproduction is captured, then turns green after the fix', 'the root cause is named, not just the symptom'],
                'terminal_states' => ['success', 'blocked', 'exhausted'],
                'durability_mode' => 'single_cycle',
                'sandbox_profile' => ['allowed' => ['read_only', 'worktree_write', 'command']],
                'agent_lane_policy' => $independentLanes,
                'risk_level' => 'medium',
                'source' => 'operator_seed',
                'source_snapshot' => ['inspired_by' => 'Loop Library: The production error sweep', 'captured_at' => '2026-06-19'],
                'status' => 'default',
            ],
            [
                'id' => 'devils_advocate',
                'version' => '1.0.0',
                'name' => "The devil's-advocate loop",
                'description' => 'Adversarial critique BEFORE a consequent architecture/rollout decision.',
                'intent' => 'verification',
                'trigger_schema' => ['objective_kinds' => ['verification', 'refactor', 'self_improvement'], 'use_when' => 'a decision is consequential and needs refutation first', 'preconditions' => ['proposal_under_review']],
                'params_schema' => ['claim' => 'string', 'refuters' => 'int'],
                'output_schema' => ['refutation_attempts' => 'list<string>', 'verdict' => 'survives|killed'],
                'success_gates' => ['independent refutation attempts are recorded and the survive/kill verdict is justified by evidence'],
                'terminal_states' => ['success', 'blocked', 'stagnated'],
                'durability_mode' => 'single_cycle',
                'sandbox_profile' => ['allowed' => ['read_only']],
                'agent_lane_policy' => $independentLanes,
                'risk_level' => 'low',
                'source' => 'operator_seed',
                'source_snapshot' => ['inspired_by' => "Loop Library: The devil's-advocate loop", 'captured_at' => '2026-06-19'],
                'status' => 'ready',
            ],
            [
                'id' => 'post_release_baseline',
                'version' => '1.0.0',
                'name' => 'The post-release baseline loop',
                'description' => 'Record a reproducible baseline after a delivery so later regression/evolution is measurable.',
                'intent' => 'verification',
                'trigger_schema' => ['objective_kinds' => ['verification'], 'use_when' => 'a delivery just landed and needs a measurable baseline', 'preconditions' => ['delivery_landed']],
                'params_schema' => ['baseline_metrics' => 'list<string>'],
                'output_schema' => ['baseline_snapshot' => 'json', 'reproducible' => 'bool'],
                'success_gates' => ['a baseline snapshot is persisted and is reproducible on re-measure'],
                'terminal_states' => ['success', 'clean_no_op'],
                'durability_mode' => 'single_cycle',
                'sandbox_profile' => ['allowed' => ['read_only', 'command']],
                'agent_lane_policy' => $independentLanes,
                'risk_level' => 'low',
                'source' => 'operator_seed',
                'source_snapshot' => ['inspired_by' => 'Loop Library: The post-release baseline loop', 'captured_at' => '2026-06-19'],
                'status' => 'ready',
            ],

            // --- Non-selectable lanes made REAL (so the registry's distinction is not vacuous) ---
            [
                'id' => 'loop_library_full_product_eval',
                'version' => '0.1.0',
                'name' => 'The full product evaluation loop (external, quarantined)',
                'description' => 'External catalog pattern kept as source_material; broad capability eval with fresh scenarios. Inspiration only until proven on Atlas cases.',
                'intent' => 'verification',
                'trigger_schema' => ['objective_kinds' => ['verification'], 'use_when' => 'broad capability needs a fresh-scenario sweep (NOT YET PROVEN on Atlas)'],
                'params_schema' => ['scenarios' => 'list<string>'],
                'output_schema' => ['capability_grade' => 'float'],
                'success_gates' => ['fresh-scenario sweep produces an honest streak grade (placeholder until Atlas-proven)'],
                'terminal_states' => ['success', 'blocked', 'exhausted'],
                'durability_mode' => 'campaign_supervised',
                'sandbox_profile' => ['allowed' => ['read_only']],
                'agent_lane_policy' => $independentLanes,
                'risk_level' => 'high',
                'source' => 'external_catalog',
                'source_snapshot' => ['source' => 'Loop Library (forwardfuture.ai)', 'hash' => 'unpinned', 'captured_at' => '2026-06-19', 'drift_notes' => 'NOT executed; quarantined as inspiration pending Atlas eval battery'],
                'status' => 'source_material',
            ],
            [
                'id' => 'legacy_blind_refactor',
                'version' => '0.0.1',
                'name' => 'Legacy blind refactor (deprecated — cosmetic)',
                'description' => 'A behaviour-preserving refactor pattern. DEPRECATED: it optimizes a proxy (cyclomatic/landing-rate) and produces ZERO real evolution. Kept only as an explicit no-go marker.',
                'intent' => 'refactor',
                'trigger_schema' => ['objective_kinds' => ['refactor'], 'cosmetic' => true],
                'params_schema' => [],
                'output_schema' => ['behaviour_preserved' => 'bool'],
                'success_gates' => ['suite still green after a behaviour-preserving edit (a PROXY gate — deprecated)'],
                'terminal_states' => ['success', 'clean_no_op'],
                'durability_mode' => 'single_cycle',
                'sandbox_profile' => ['allowed' => ['read_only', 'worktree_write']],
                'agent_lane_policy' => $independentLanes,
                'risk_level' => 'low',
                'source' => 'run_learning',
                'source_snapshot' => ['captured_at' => '2026-06-19', 'drift_notes' => 'deprecated: behaviour-preserving == zero leverage per the canonical Loop definition'],
                'status' => 'deprecated',
            ],
        ];
    }
}
