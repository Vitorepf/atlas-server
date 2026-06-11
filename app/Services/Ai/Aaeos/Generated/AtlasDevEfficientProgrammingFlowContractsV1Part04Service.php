<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Support\AtlasAaeosValueNormalizer;

/**
 * Atlas Dev Efficient Programming Flow Contracts v1 · Parte 4 — executable
 * invariant validator for the two contracts carved into this doc recorte:
 *
 *   - 4.4 LightTaskContract  (execution contract: what the agent may do,
 *     where, with which tools, and how it recovers on failure).
 *   - 5.1 ContextRetrievalPlan (deterministic output of DocContextTierSelector:
 *     which tiers + budget + required/optional/excluded sources).
 *
 * The repo already carries the *DTOs* for both shapes
 * ({@see \App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract} and
 * {@see \App\Services\Ai\Programming\AtlasDev\Schemas\ContextRetrievalPlan}),
 * but those are pure data carriers + hashers — they do NOT enforce the doc's
 * numbered invariants. This service is the missing enforcement layer: pure,
 * deterministic, no DB. It mirrors the validation contract used by the sibling
 * {@see AtlasDevEfficientProgrammingFlowContractsPart02Service} (docs 4.1-4.3)
 * and continues it at 4.4 + 5.1.
 *
 * Documented LightTaskContract invariants this code enforces (doc 4.4):
 *   I4  — watched_files must be disjoint from allowed_files and forbidden_files.
 *   I5  — max_files_changed <= 6 on the fast path; above that the task is R4.
 *   I7  — provider_lock.fallback_allowed must be false (locked 2026-05-16).
 *   I8  — blocked_actions must include at least the baseline four:
 *         production_write, migration_apply, secret_access, broad_refactor.
 *   I9  — any allowed_tool that writes must also appear as a granted permission.
 *   I10 — policy_profile.autonomy_level=auto requires environment in
 *         (dev, staging); production forces at least auto_with_confirmation.
 *   I11 — policy_profile.privacy_class=restricted forbids raw excerpts to the
 *         provider (refs-by-hash only).
 *   I12 — policy_profile.sandbox_required=true is mandatory for R3+ tasks.
 *   I13 — decision_mode=auto_best_available is out of scope for this phase.
 *   Plus the doc's "Exemplos Invalidos" table (max_files_changed:12 @ R2, etc.).
 *
 * Documented ContextRetrievalPlan invariants this code enforces (doc 5.1):
 *   J2  — core must be selected unless task_kind = question.
 *   J3  — code_intelligence must be selected when workspace_resolved = true.
 *   J4  — forge selected implies risk_level >= R4.
 *   J5  — required_sources may be empty only for a read-only question.
 *   J6  — budget.reserved_for_core + reserved_for_code_intelligence
 *         <= budget.max_chars.
 *   Plus: every selected tier is inside the canonical tier set.
 *
 * Non-goals (read-only validator): it does NOT build envelopes, does NOT
 * mutate files, does NOT call providers, and does NOT decide code correctness.
 * It only classifies a candidate contract/plan against the doc and emits
 * machine-readable violations.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1-part-04.md
 */
final class AtlasDevEfficientProgrammingFlowContractsV1Part04Service
{
    /** Evidence schema id this validator emits. */
    public const SCHEMA = 'atlas.aaeos.dev_flow_contracts_v1_part04.v1';

    /** Doc 4.4 schema_version. */
    public const LIGHT_TASK_CONTRACT = 'atlas.dev.light_task_contract.v1';

    /** Doc 5.1 schema_version. */
    public const CONTEXT_RETRIEVAL_PLAN = 'atlas.dev.context_retrieval_plan.v1';

    /** Doc 4.4 invariant 5: fast-path file cap. Above it the task is R4. */
    public const FAST_PATH_MAX_FILES = 6;

    /**
     * Doc 4.4 invariant 8: blocked_actions must contain at least these four.
     *
     * @var list<string>
     */
    public const REQUIRED_BLOCKED_ACTIONS = [
        'production_write',
        'migration_apply',
        'secret_access',
        'broad_refactor',
    ];

    /**
     * Doc 4.4: allowed_tools that imply a write capability and therefore need a
     * matching granted permission (invariant 9).
     *
     * @var list<string>
     */
    private const WRITE_TOOLS = ['write', 'edit', 'apply_patch'];

    /** Doc 4.4 policy_profile.autonomy_level enumeration. */
    public const AUTONOMY_ASSIST = 'assist';
    public const AUTONOMY_AUTO_CONFIRM = 'auto_with_confirmation';
    public const AUTONOMY_AUTO = 'auto';

    /**
     * Doc 4.4 invariant 10: the only environments where autonomy_level=auto is
     * legal. Anything else (notably production) forces a confirmation step.
     *
     * @var list<string>
     */
    private const AUTO_ALLOWED_ENVIRONMENTS = ['dev', 'staging'];

    /** Doc 4.4 invariant 11: privacy class that forbids raw excerpts. */
    public const PRIVACY_RESTRICTED = 'restricted';

    /**
     * Doc 4.4 invariant 13: decision_mode enumeration. auto_best_available is
     * explicitly "fora do escopo desta fase".
     */
    public const DECISION_MODE_MANUAL = 'manual_override';
    public const DECISION_MODE_AUTO_BEST_ALLOWED = 'auto_best_allowed';
    public const DECISION_MODE_AUTO_BEST_AVAILABLE = 'auto_best_available';

    /**
     * Doc 5.1: the canonical tier set; selected_tiers is a subset of this.
     *
     * @var list<string>
     */
    public const CONTEXT_TIERS = ['core', 'code_intelligence', 'sdd', 'interface', 'forge', 'obras'];

    /** Doc 4.2/5.1: risk levels, ordered so R4 == index 4. */
    private const RISK_LEVELS = ['R0', 'R1', 'R2', 'R3', 'R4', 'R5'];

    /** Doc 5.1 invariant 4: forge tier requires at least this risk index. */
    private const FORGE_MIN_RISK_INDEX = 4; // R4

    // ---------------------------------------------------------------------
    // 4.4 LightTaskContract
    // ---------------------------------------------------------------------

    /**
     * Validate a candidate LightTaskContract against the doc 4.4 invariants.
     *
     * @param  array<string,mixed>  $contract
     * @return array{
     *     schema:string,
     *     contract:string,
     *     valid:bool,
     *     blocked:bool,
     *     can_dispatch:bool,
     *     violations:list<array{invariant:string,rule:string}>,
     *     violated_invariants:list<string>
     * }
     */
    public function validateLightTaskContract(array $contract): array
    {
        $violations = [];

        $allowedFiles = AtlasAaeosValueNormalizer::castStringList($contract['allowed_files'] ?? []);
        $watchedFiles = AtlasAaeosValueNormalizer::castStringList($contract['watched_files'] ?? []);
        $forbiddenFiles = AtlasAaeosValueNormalizer::castStringList($contract['forbidden_files'] ?? []);
        $allowedTools = AtlasAaeosValueNormalizer::castStringList($contract['allowed_tools'] ?? []);
        $blockedActions = AtlasAaeosValueNormalizer::castStringList($contract['blocked_actions'] ?? []);
        $grantedPermissions = AtlasAaeosValueNormalizer::castStringList($contract['granted_permissions'] ?? []);
        $maxFiles = (int) ($contract['max_files_changed'] ?? 0);
        $riskLevel = (string) ($contract['risk_level'] ?? 'R0');
        $riskIndex = $this->riskIndex($riskLevel);

        // I4: watched_files disjoint from allowed_files and forbidden_files.
        if (array_intersect($watchedFiles, $allowedFiles) !== []
            || array_intersect($watchedFiles, $forbiddenFiles) !== []
        ) {
            $violations[] = ['invariant' => 'LTC-4', 'rule' => 'watched_files_must_be_disjoint_from_allowed_and_forbidden'];
        }

        // I5: max_files_changed <= 6 on the fast path; above it the task is R4.
        // This also encodes the "Exemplos Invalidos" row (max_files_changed:12 @ R2).
        if ($maxFiles > self::FAST_PATH_MAX_FILES && $riskIndex < self::FORGE_MIN_RISK_INDEX) {
            $violations[] = ['invariant' => 'LTC-5', 'rule' => 'max_files_changed_over_6_requires_r4'];
        }

        // I7: provider_lock.fallback_allowed must be false.
        if ((bool) data_get($contract, 'provider_lock.fallback_allowed', false) === true) {
            $violations[] = ['invariant' => 'LTC-7', 'rule' => 'provider_lock_fallback_must_be_false'];
        }

        // I8: blocked_actions must include the baseline four.
        foreach (self::REQUIRED_BLOCKED_ACTIONS as $required) {
            if (! in_array($required, $blockedActions, true)) {
                $violations[] = ['invariant' => 'LTC-8', 'rule' => 'blocked_actions_must_include_'.$required];
            }
        }

        // I9: any write tool needs a matching granted permission.
        foreach ($allowedTools as $tool) {
            if (in_array($tool, self::WRITE_TOOLS, true) && ! in_array($tool, $grantedPermissions, true)) {
                $violations[] = ['invariant' => 'LTC-9', 'rule' => 'write_tool_requires_matching_permission'];
            }
        }

        // I10: autonomy_level=auto requires environment in (dev, staging).
        $autonomy = (string) data_get($contract, 'policy_profile.autonomy_level', self::AUTONOMY_ASSIST);
        $environment = (string) ($contract['environment'] ?? data_get($contract, 'business_context.environment', ''));
        if ($autonomy === self::AUTONOMY_AUTO && ! in_array($environment, self::AUTO_ALLOWED_ENVIRONMENTS, true)) {
            $violations[] = ['invariant' => 'LTC-10', 'rule' => 'autonomy_auto_requires_dev_or_staging'];
        }

        // I11: privacy_class=restricted forbids raw excerpts (refs-by-hash only).
        $privacyClass = (string) data_get($contract, 'policy_profile.privacy_class', '');
        $sendsRawExcerpts = (bool) data_get($contract, 'policy_profile.sends_raw_excerpts', false);
        if ($privacyClass === self::PRIVACY_RESTRICTED && $sendsRawExcerpts) {
            $violations[] = ['invariant' => 'LTC-11', 'rule' => 'restricted_privacy_forbids_raw_excerpts'];
        }

        // I12: sandbox_required must be true for R3+ tasks.
        $sandboxRequired = (bool) data_get($contract, 'policy_profile.sandbox_required', false);
        if ($riskIndex >= 3 && ! $sandboxRequired) {
            $violations[] = ['invariant' => 'LTC-12', 'rule' => 'r3_plus_requires_sandbox'];
        }

        // I13: decision_mode=auto_best_available is out of scope for this phase.
        $decisionMode = (string) data_get($contract, 'policy_profile.decision_mode', self::DECISION_MODE_MANUAL);
        if ($decisionMode === self::DECISION_MODE_AUTO_BEST_AVAILABLE) {
            $violations[] = ['invariant' => 'LTC-13', 'rule' => 'decision_mode_auto_best_available_out_of_scope'];
        }

        $valid = $violations === [];

        return [
            'schema' => self::SCHEMA,
            'contract' => self::LIGHT_TASK_CONTRACT,
            'valid' => $valid,
            'blocked' => ! $valid,
            'can_dispatch' => $valid,
            'violations' => array_values($violations),
            'violated_invariants' => $this->uniqueInvariants($violations),
        ];
    }

    /**
     * Doc 4.4 invariant 5 helper: classify how a file count maps to a path.
     * Returns the fast-path file cap decision so the caller (and tests) can
     * assert the exact threshold semantics.
     *
     * @return array{max_files:int,fast_path_allowed:bool,requires_r4:bool,cap:int}
     */
    public function fastPathFileDecision(int $maxFilesChanged): array
    {
        $overCap = $maxFilesChanged > self::FAST_PATH_MAX_FILES;

        return [
            'max_files' => $maxFilesChanged,
            'fast_path_allowed' => ! $overCap,
            'requires_r4' => $overCap,
            'cap' => self::FAST_PATH_MAX_FILES,
        ];
    }

    // ---------------------------------------------------------------------
    // 5.1 ContextRetrievalPlan
    // ---------------------------------------------------------------------

    /**
     * Validate a candidate ContextRetrievalPlan against the doc 5.1 invariants.
     *
     * @param  array<string,mixed>  $plan
     * @return array{
     *     schema:string,
     *     contract:string,
     *     valid:bool,
     *     blocked:bool,
     *     violations:list<array{invariant:string,rule:string}>,
     *     violated_invariants:list<string>
     * }
     */
    public function validateContextRetrievalPlan(array $plan): array
    {
        $violations = [];

        $tiers = AtlasAaeosValueNormalizer::castStringList($plan['tiers_selected'] ?? []);
        $requiredSources = AtlasAaeosValueNormalizer::castStringList($plan['required_sources'] ?? []);
        $taskKind = (string) ($plan['task_kind'] ?? '');
        $workspaceResolved = (bool) ($plan['workspace_resolved'] ?? false);
        $riskLevel = (string) ($plan['risk_level'] ?? 'R0');
        $riskIndex = $this->riskIndex($riskLevel);
        $readOnly = (bool) ($plan['read_only'] ?? false);

        $maxChars = (int) data_get($plan, 'budget.max_chars', 0);
        $reservedCore = (int) data_get($plan, 'budget.reserved_for_core', 0);
        $reservedCodeIntel = (int) data_get($plan, 'budget.reserved_for_code_intelligence', 0);

        // Tier-set guard: every selected tier must be in the canonical set.
        foreach ($tiers as $tier) {
            if (! in_array($tier, self::CONTEXT_TIERS, true)) {
                $violations[] = ['invariant' => 'CRP-1', 'rule' => 'tier_outside_canonical_set'];
            }
        }

        // J2: core selected unless task_kind = question.
        if ($taskKind !== 'question' && ! in_array('core', $tiers, true)) {
            $violations[] = ['invariant' => 'CRP-2', 'rule' => 'core_required_unless_task_kind_question'];
        }

        // J3: code_intelligence selected when workspace_resolved = true.
        if ($workspaceResolved && ! in_array('code_intelligence', $tiers, true)) {
            $violations[] = ['invariant' => 'CRP-3', 'rule' => 'code_intelligence_required_when_workspace_resolved'];
        }

        // J4: forge tier implies risk_level >= R4.
        if (in_array('forge', $tiers, true) && $riskIndex < self::FORGE_MIN_RISK_INDEX) {
            $violations[] = ['invariant' => 'CRP-4', 'rule' => 'forge_tier_requires_r4_or_above'];
        }

        // J5: required_sources empty only for a read-only question.
        if ($requiredSources === [] && ! ($taskKind === 'question' && $readOnly)) {
            $violations[] = ['invariant' => 'CRP-5', 'rule' => 'required_sources_empty_only_for_read_only_question'];
        }

        // J6: reserved_for_core + reserved_for_code_intelligence <= max_chars.
        if (($reservedCore + $reservedCodeIntel) > $maxChars) {
            $violations[] = ['invariant' => 'CRP-6', 'rule' => 'reserved_budget_cannot_exceed_max_chars'];
        }

        $valid = $violations === [];

        return [
            'schema' => self::SCHEMA,
            'contract' => self::CONTEXT_RETRIEVAL_PLAN,
            'valid' => $valid,
            'blocked' => ! $valid,
            'violations' => array_values($violations),
            'violated_invariants' => $this->uniqueInvariants($violations),
        ];
    }

    /**
     * Whole-doc smoke output: validates a minimal valid LightTaskContract and a
     * minimal valid ContextRetrievalPlan so the command has a deterministic,
     * green default payload.
     *
     * @return array{
     *     schema:string,
     *     contracts:list<string>,
     *     light_task_contract:array<string,mixed>,
     *     context_retrieval_plan:array<string,mixed>,
     *     all_valid:bool
     * }
     */
    public function selfCheck(): array
    {
        $ltc = $this->validateLightTaskContract($this->exampleValidLightTaskContract());
        $crp = $this->validateContextRetrievalPlan($this->exampleValidContextRetrievalPlan());

        return [
            'schema' => self::SCHEMA,
            'contracts' => [self::LIGHT_TASK_CONTRACT, self::CONTEXT_RETRIEVAL_PLAN],
            'light_task_contract' => $ltc,
            'context_retrieval_plan' => $crp,
            'all_valid' => $ltc['valid'] && $crp['valid'],
        ];
    }

    /**
     * Doc 4.4 "Exemplo Valido (Repair R2)" projected to validator inputs.
     *
     * @return array<string,mixed>
     */
    public function exampleValidLightTaskContract(): array
    {
        return [
            'schema_version' => self::LIGHT_TASK_CONTRACT,
            'risk_level' => 'R2',
            'allowed_tools' => ['read', 'write', 'grep', 'run_test'],
            'granted_permissions' => ['read', 'write', 'grep', 'run_test'],
            'blocked_actions' => [
                'production_write', 'migration_apply', 'secret_access',
                'broad_refactor', 'council_invoke', 'forge_invoke_direct',
            ],
            'allowed_files' => ['app/Services/Ai/Cli/AtlasCliDevWorkflowService.php'],
            'watched_files' => [],
            'forbidden_files' => ['vendor/*', 'node_modules/*'],
            'max_files_changed' => 1,
            'environment' => 'dev',
            'provider_lock' => ['fallback_allowed' => false],
            'policy_profile' => [
                'autonomy_level' => self::AUTONOMY_AUTO_CONFIRM,
                'privacy_class' => 'internal',
                'sandbox_required' => false,
                'decision_mode' => self::DECISION_MODE_MANUAL,
                'sends_raw_excerpts' => false,
            ],
        ];
    }

    /**
     * Doc 5.1 "Exemplo Valido" projected to validator inputs.
     *
     * @return array<string,mixed>
     */
    public function exampleValidContextRetrievalPlan(): array
    {
        return [
            'schema_version' => self::CONTEXT_RETRIEVAL_PLAN,
            'task_kind' => 'repair',
            'workspace_resolved' => true,
            'risk_level' => 'R2',
            'read_only' => false,
            'tiers_selected' => ['core', 'code_intelligence', 'sdd'],
            'budget' => [
                'max_chars' => 12000,
                'reserved_for_core' => 3000,
                'reserved_for_code_intelligence' => 6000,
            ],
            'required_sources' => [
                'atlas-dev-efficient-programming-flow-v1.md#section-9',
                'code_intelligence://symbol/AtlasCliDevWorkflowService',
            ],
        ];
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /** Map a risk level label to its ordered index; unknown -> 0 (R0). */
    private function riskIndex(string $riskLevel): int
    {
        $index = array_search($riskLevel, self::RISK_LEVELS, true);

        return $index === false ? 0 : (int) $index;
    }

    /**
     * @param  list<array{invariant:string,rule:string}>  $violations
     * @return list<string>
     */
    private function uniqueInvariants(array $violations): array
    {
        $ids = [];
        foreach ($violations as $violation) {
            $ids[$violation['invariant']] = true;
        }

        return array_keys($ids);
    }
}
