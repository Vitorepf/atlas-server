<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas Programming Governance System — pure, deterministic index decider.
 *
 * This service encodes the distinctive, decidable contract of the index doc
 * (NOT the operational lifecycle, which lives in
 * {@see \App\Services\Ai\Programming\Governance\ProgrammingGovernanceService};
 * NOT the P1–P17 invariants, which live in {@see AtlasDevPolicyService}). The
 * index doc's own load-bearing content is:
 *
 *   1. The canonical governed flow (doc "Fluxo"): a fixed, ordered pipeline
 *      intake -> placement -> code intelligence -> spec/delta -> task contract
 *      -> agent role -> execution -> acceptance/tests -> evidence ->
 *      docs/index/cartography -> learning -> completion gate.
 *   2. The governance invariants (doc "Contratos"): six laws every governed
 *      change obeys.
 *   3. The Atlas Dev Fast Lane mapping (doc "Atlas Dev Fast Lane"): each
 *      universal governance gate has a compact Atlas Dev projection, plus three
 *      Dev-only gates that operationalise the fast path without contradicting
 *      governance.
 *   4. The non-relaxation law (doc, same section): "O fast path pode reduzir
 *      payload e custo por R-level, mas nao pode relaxar uma lei de governanca:
 *      write sem spec, sem contrato, sem escopo, sem verification/evidence ou
 *      sem completion state continua invalido."
 *   5. Scope choice (doc "Exemplos"): full governance for multi-file,
 *      architecture, schema, provider/runtime, security/privacy,
 *      self-construction or cartography work; compact governance for a small
 *      patch, typo, local adjustment or focused test.
 *
 * Everything here is in-memory and side-effect free. The service judges and
 * maps; it never executes, never touches a database, never calls a provider.
 *
 * @see docs/engineering-knowledge-base/atlas-programming-governance-system.md
 * @see docs/engineering-knowledge-base/atlas-programming-governance-system-contracts.md
 */
final class AtlasProgrammingGovernanceSystemService
{
    /** Stable verdict kind this decider emits. */
    public const VERDICT_KIND = 'atlas.programming.governance';

    /** Scope ceremony levels (doc "Exemplos"). */
    public const SCOPE_FULL = 'full';
    public const SCOPE_COMPACT = 'compact';

    /** Fast-path legality verdicts. */
    public const FAST_PATH_OK = 'allowed';
    public const FAST_PATH_INVALID = 'invalid';

    /**
     * The canonical governed flow, in order (doc "Fluxo"). This sequence is the
     * contract: a governed change advances through these stages and never skips
     * a structural gate.
     *
     * @var list<string>
     */
    public const FLOW = [
        'intake',
        'placement',
        'code_intelligence',
        'spec_delta',
        'task_contract',
        'agent_role',
        'execution',
        'acceptance_tests',
        'evidence',
        'docs_index_cartography',
        'learning',
        'completion_gate',
    ];

    /**
     * The six governance invariants every governed change obeys (doc
     * "Contratos" -> "Invariantes").
     *
     * @var list<string>
     */
    public const INVARIANTS = [
        'structural_feature_passes_placement',
        'spec_before_code_when_structural_risk',
        'task_contract_limits_files_risk_tests_rollback_evidence',
        'code_intelligence_guides_real_context',
        'evidence_separates_implementation_from_narrative',
        'learning_returns_to_docs_specs_prompts_gates_cartography',
    ];

    /**
     * Atlas Dev Fast Lane mapping (doc table). Each universal Programming
     * Governance gate projects to exactly one Atlas Dev gate. A projection is a
     * compact, proportional form of the universal gate — never a parallel or
     * contradicting system.
     *
     * @var array<string,string>
     */
    public const FAST_LANE_MAP = [
        'placement' => 'intake_risk_gate',
        'code_intelligence' => 'context_budget_gate',
        'spec_before_code' => 'mini_spec_before_code_gate',
        'scope_guard' => 'scope_guard_light',
        'evidence' => 'receipt_gate',
        'completion' => 'completion_state_gate',
    ];

    /**
     * The Code Intelligence gate also projects an artifact alongside its gate
     * (doc table: "context_budget_gate + CodeDiscoveryManifest").
     */
    public const CODE_INTELLIGENCE_ARTIFACT = 'CodeDiscoveryManifest';

    /**
     * Dev-only gates that operationalise the fast path (doc: "Gates Dev-only
     * justificados"). They ADD operational contract, focused verification and a
     * safe stop for Forge preview; they may never contradict a universal gate.
     *
     * @var list<string>
     */
    public const DEV_ONLY_GATES = [
        'light_task_contract_gate',
        'verification_gate',
        'forge_escalation_gate',
    ];

    /**
     * The five governance laws the fast path may NEVER relax (doc: "nao pode
     * relaxar uma lei de governanca: write sem spec, sem contrato, sem escopo,
     * sem verification/evidence ou sem completion state continua invalido").
     *
     * The fast path may reduce payload/cost per R-level, but a write that drops
     * any of these is invalid regardless of how cheap the lane is.
     *
     * @var list<string>
     */
    public const NON_RELAXABLE_LAWS = [
        'spec',
        'task_contract',
        'scope',
        'verification_evidence',
        'completion_state',
    ];

    /**
     * Triggers that force FULL governance (doc "Exemplos": mudanca multiarquivo,
     * arquitetura, schema, provider/runtime, security/privacy,
     * self-construction ou cartografia).
     *
     * @var list<string>
     */
    public const FULL_GOVERNANCE_TRIGGERS = [
        'multi_file',
        'architecture',
        'schema',
        'provider_runtime',
        'security_privacy',
        'self_construction',
        'cartography',
    ];

    /**
     * Return the canonical governed flow in order.
     *
     * @return list<string>
     */
    public function flow(): array
    {
        return self::FLOW;
    }

    /**
     * Return the governance invariants.
     *
     * @return list<string>
     */
    public function invariants(): array
    {
        return self::INVARIANTS;
    }

    /**
     * Choose the scope ceremony for a change (doc "Exemplos").
     *
     * FULL governance is mandatory when ANY full-governance trigger is present
     * (multi-file, architecture, schema, provider/runtime, security/privacy,
     * self-construction, cartography). Otherwise the change is eligible for
     * COMPACT governance, which still preserves ownership, proof and a scope
     * limit — it is lighter ceremony, never "no governance".
     *
     * @param  array<string,mixed>  $change
     * @return array<string,mixed>
     */
    public function classifyScope(array $change): array
    {
        $present = [];
        foreach (self::FULL_GOVERNANCE_TRIGGERS as $trigger) {
            if (($change[$trigger] ?? false) === true) {
                $present[] = $trigger;
            }
        }

        // A declared file_count > 1 is itself a multi-file trigger.
        if (! in_array('multi_file', $present, true) && (int) ($change['file_count'] ?? 0) > 1) {
            $present[] = 'multi_file';
        }

        $isFull = $present !== [];

        return [
            'kind' => self::VERDICT_KIND,
            'scope' => $isFull ? self::SCOPE_FULL : self::SCOPE_COMPACT,
            'triggers' => array_values($present),
            'required_gates' => $this->requiredGates($isFull ? self::SCOPE_FULL : self::SCOPE_COMPACT),
            'reason' => $isFull
                ? 'Full governance: structural trigger present ('.implode(', ', $present).') (doc "Exemplos").'
                : 'Compact governance eligible: small/local change. Ownership, proof and scope limit still apply.',
        ];
    }

    /**
     * The gates a scope ceremony must satisfy. FULL runs the whole universal
     * gate set; COMPACT runs the proportional subset (placement stays
     * informational, but evidence + scope + completion remain mandatory — those
     * are non-relaxable laws).
     *
     * @return list<string>
     */
    public function requiredGates(string $scope): array
    {
        if ($scope === self::SCOPE_COMPACT) {
            return ['scope_guard', 'evidence', 'completion'];
        }

        return [
            'placement',
            'code_intelligence',
            'spec_before_code',
            'scope_guard',
            'evidence',
            'completion',
        ];
    }

    /**
     * Project a universal Programming Governance gate onto its Atlas Dev Fast
     * Lane gate (doc table). Returns the Dev gate, whether it is a direct
     * projection or a Dev-only gate, and any extra artifact the projection
     * carries (Code Intelligence carries the CodeDiscoveryManifest).
     *
     * @return array<string,mixed>
     */
    public function projectGate(string $governanceGate): array
    {
        $gate = $this->normalizeGate($governanceGate);

        if (isset(self::FAST_LANE_MAP[$gate])) {
            $dev = self::FAST_LANE_MAP[$gate];
            $artifacts = $gate === 'code_intelligence' ? [self::CODE_INTELLIGENCE_ARTIFACT] : [];

            return [
                'kind' => self::VERDICT_KIND,
                'governance_gate' => $gate,
                'dev_gate' => $dev,
                'relationship' => 'projection',
                'artifacts' => $artifacts,
                'reason' => "Atlas Dev gate '{$dev}' is the compact projection of governance gate '{$gate}'. Not a parallel system.",
            ];
        }

        if (in_array($gate, self::DEV_ONLY_GATES, true)) {
            return [
                'kind' => self::VERDICT_KIND,
                'governance_gate' => null,
                'dev_gate' => $gate,
                'relationship' => 'dev_only',
                'artifacts' => [],
                'reason' => "'{$gate}' is a justified Dev-only gate (operational contract / focused verification / safe Forge stop). It may not contradict governance.",
            ];
        }

        return [
            'kind' => self::VERDICT_KIND,
            'governance_gate' => $gate,
            'dev_gate' => null,
            'relationship' => 'unmapped',
            'artifacts' => [],
            'reason' => "No Fast Lane projection is registered for '{$gate}'.",
        ];
    }

    /**
     * The full Fast Lane mapping as a list of projection rows, plus the
     * Dev-only gates. Useful for cartography / audit.
     *
     * @return array<string,mixed>
     */
    public function fastLaneMapping(): array
    {
        $rows = [];
        foreach (self::FAST_LANE_MAP as $gov => $dev) {
            $rows[] = $this->projectGate($gov);
        }

        return [
            'kind' => self::VERDICT_KIND,
            'projections' => $rows,
            'dev_only_gates' => self::DEV_ONLY_GATES,
            'non_relaxable_laws' => self::NON_RELAXABLE_LAWS,
        ];
    }

    /**
     * Judge whether a proposed fast-path execution is legal (doc non-relaxation
     * law). The fast path is allowed to drop payload/cost per R-level, but a
     * WRITE that drops any non-relaxable law is invalid.
     *
     * Expected input:
     *   write                bool   — does the run write code?
     *   r_level              string — R0..R5 (informational; lower R may shrink
     *                                 payload but never a law)
     *   has_spec             bool
     *   has_task_contract    bool
     *   has_scope            bool
     *   has_verification_evidence bool
     *   has_completion_state bool
     *   reduced_payload      bool   — fast path trimmed payload/cost (allowed)
     *
     * @param  array<string,mixed>  $run
     * @return array<string,mixed>
     */
    public function evaluateFastPath(array $run): array
    {
        $isWrite = ($run['write'] ?? false) === true;
        $rLevel = strtoupper($this->str($run['r_level'] ?? null) ?? 'R0');

        // A read-only fast lane has no law to relax.
        if (! $isWrite) {
            return $this->fastPathVerdict(self::FAST_PATH_OK, [], $rLevel, $isWrite,
                'Read-only fast path: no write, no governance law engaged.');
        }

        $lawPresence = [
            'spec' => ($run['has_spec'] ?? false) === true,
            'task_contract' => ($run['has_task_contract'] ?? false) === true,
            'scope' => ($run['has_scope'] ?? false) === true,
            'verification_evidence' => ($run['has_verification_evidence'] ?? false) === true,
            'completion_state' => ($run['has_completion_state'] ?? false) === true,
        ];

        $missing = [];
        foreach (self::NON_RELAXABLE_LAWS as $law) {
            if (($lawPresence[$law] ?? false) !== true) {
                $missing[] = $law;
            }
        }

        if ($missing !== []) {
            return $this->fastPathVerdict(self::FAST_PATH_INVALID, $missing, $rLevel, $isWrite,
                'Write on the fast path dropped a governance law ('.implode(', ', $missing).
                '). Cheaper lane never legalises a write without spec/contract/scope/verification-evidence/completion.');
        }

        $note = ($run['reduced_payload'] ?? false) === true
            ? "Fast path reduced payload/cost for {$rLevel} (allowed); all governance laws still present."
            : "Fast path write at {$rLevel}; all governance laws present.";

        return $this->fastPathVerdict(self::FAST_PATH_OK, [], $rLevel, $isWrite, $note);
    }

    /**
     * @param  list<string>  $missing
     * @return array<string,mixed>
     */
    private function fastPathVerdict(string $verdict, array $missing, string $rLevel, bool $isWrite, string $reason): array
    {
        return [
            'kind' => self::VERDICT_KIND,
            'fast_path' => $verdict,
            'valid' => $verdict === self::FAST_PATH_OK,
            'write' => $isWrite,
            'r_level' => $rLevel,
            'missing_laws' => array_values($missing),
            'non_relaxable_laws' => self::NON_RELAXABLE_LAWS,
            'reason' => $reason,
        ];
    }

    private function normalizeGate(string $gate): string
    {
        $g = strtolower(trim($gate));
        $g = str_replace([' ', '-'], '_', $g);

        // Accept a few documented synonyms / aliases for the universal gate names.
        return match ($g) {
            'spec', 'spec_delta' => 'spec_before_code',
            'code_intel', 'code_intelligence_context' => 'code_intelligence',
            'scope' => 'scope_guard',
            default => $g,
        };
    }

    private function str(mixed $v): ?string
    {
        if (is_string($v)) {
            $t = trim($v);

            return $t === '' ? null : $t;
        }

        return null;
    }
}
