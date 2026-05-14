<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfImprovement;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Atlas Self-Improvement Proposal Packet.
 *
 * Materializes the canonical proposal contract every Atlas self-improvement
 * cycle must emit BEFORE Forge can implement anything. The packet is a
 * read-model: it normalises operator input, declares risk classification +
 * provider topology recommendation + test/rollback strategy + Rivals plan, and
 * computes a deterministic readiness status (`draft`/`ready`/`blocked`).
 *
 * Hard rules:
 *   - NEVER calls an external provider;
 *   - NEVER spends a token;
 *   - NEVER promotes a Forge run by itself — the packet only feeds the Power
 *     Gate, which is the next step in the ladder;
 *   - NEVER permits autopromotion for critical paths (provider invocation,
 *     auth, billing, tokens, migrations, policy/profile/decision-receipt/
 *     ledger, completion gate, Rivals claim, fallback policy, security/
 *     privacy, data deletion).
 *
 * Schema: atlas.self_improvement.proposal_packet.v1
 * Doc: docs/engineering-knowledge-base/atlas-self-improvement-governance-ladder.md
 */
class AtlasSelfImprovementProposalPacketService
{
    public const SCHEMA_VERSION = 'atlas.self_improvement.proposal_packet.v1';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_READY = 'ready';
    public const STATUS_BLOCKED = 'blocked';

    public const RISK_LEVELS = ['low', 'medium', 'high', 'critical'];

    /** @var list<string> Paths whose changes are NEVER eligible for autopromotion. */
    public const AUTOPROMOTION_FORBIDDEN_PATTERNS = [
        'provider', 'auth', 'billing', 'token', 'migration',
        'policy', 'profile', 'decision-receipt', 'ledger',
        'completion-gate', 'rivals', 'fallback', 'security', 'privacy',
    ];

    /**
     * Build a canonical proposal packet from operator input.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function build(array $payload): array
    {
        $title = $this->stringOrNull($payload['title'] ?? null);
        $problemStatement = $this->stringOrNull($payload['problem_statement'] ?? null);
        $businessRule = $this->stringOrNull($payload['business_rule'] ?? null);
        $targetCapability = $this->stringOrNull($payload['target_capability'] ?? null);
        $whyNow = $this->stringOrNull($payload['why_now'] ?? null);
        $expectedPowerGain = $this->stringOrNull($payload['expected_power_gain'] ?? null);
        $canonicalDocs = $this->normalizeStringList($payload['canonical_docs'] ?? []);
        $allowedPaths = $this->normalizeStringList($payload['allowed_paths'] ?? []);
        $forbiddenPaths = $this->normalizeStringList($payload['forbidden_paths'] ?? []);
        $successMetrics = $this->normalizeStringList($payload['success_metrics'] ?? []);
        $acceptanceGates = $this->normalizeStringList($payload['acceptance_gates'] ?? []);
        $riskLevel = $this->normalizeRiskLevel($payload['risk_level'] ?? null);
        $autopromotionRequested = (bool) ($payload['autopromotion_requested'] ?? false);
        $humanReviewRequired = (bool) ($payload['human_review_required'] ?? true);
        $maxFilesChanged = $this->positiveIntOrNull($payload['max_files_changed'] ?? null);

        $proposalId = $this->stringOrNull($payload['proposal_id'] ?? null) ?? 'prop_'.(string) Str::ulid();

        $autopromotionAllowed = $this->resolveAutopromotionAllowed(
            $autopromotionRequested,
            $riskLevel,
            $allowedPaths,
        );

        $beforeSnapshotPlan = $this->buildBeforeSnapshotPlan($payload, $allowedPaths);
        $riskClassification = $this->buildRiskClassification($riskLevel, $payload);
        $providerTopologyRecommendation = $this->buildProviderTopologyRecommendation($payload, $riskLevel);
        $testStrategy = $this->buildTestStrategy($payload, $allowedPaths);
        $rollbackStrategy = $this->buildRollbackStrategy($payload, $riskLevel);
        $rivalsPlan = $this->buildRivalsPlan($payload, $expectedPowerGain);

        $blockers = $this->collectBlockers(
            $title,
            $problemStatement,
            $businessRule,
            $targetCapability,
            $whyNow,
            $expectedPowerGain,
            $successMetrics,
            $acceptanceGates,
            $canonicalDocs,
            $allowedPaths,
            $forbiddenPaths,
            $rollbackStrategy,
            $testStrategy,
            $autopromotionRequested,
            $autopromotionAllowed,
            $riskLevel,
        );

        $status = $this->resolveStatus($blockers, $payload);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'proposal_id' => $proposalId,
            'generated_at' => Carbon::now()->toIso8601String(),
            'title' => $title,
            'problem_statement' => $problemStatement,
            'business_rule' => $businessRule,
            'target_capability' => $targetCapability,
            'why_now' => $whyNow,
            'expected_power_gain' => $expectedPowerGain,
            'before_snapshot_plan' => $beforeSnapshotPlan,
            'success_metrics' => $successMetrics,
            'acceptance_gates' => $acceptanceGates,
            'canonical_docs' => $canonicalDocs,
            'allowed_paths' => $allowedPaths,
            'forbidden_paths' => $forbiddenPaths,
            'max_files_changed' => $maxFilesChanged,
            'risk_classification' => $riskClassification,
            'provider_topology_recommendation' => $providerTopologyRecommendation,
            'test_strategy' => $testStrategy,
            'rollback_strategy' => $rollbackStrategy,
            'rivals_evaluation_plan' => $rivalsPlan,
            'human_review_required' => $humanReviewRequired || ! $autopromotionAllowed,
            'autopromotion_requested' => $autopromotionRequested,
            'autopromotion_allowed' => $autopromotionAllowed,
            'blockers' => $blockers,
            'evidence_refs' => $this->resolveEvidenceRefs($canonicalDocs, $allowedPaths),
            'next_action' => $this->resolveNextAction($status, $blockers, $autopromotionAllowed),
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'separated_from' => 'external_rivals_certification',
            'is_read_model' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  list<string>  $allowedPaths
     * @return array<string,mixed>
     */
    private function buildBeforeSnapshotPlan(array $payload, array $allowedPaths): array
    {
        $explicit = $payload['before_snapshot_plan'] ?? null;
        if (is_array($explicit) && $explicit !== []) {
            return $explicit;
        }

        return [
            'capture_audit_blocks' => true,
            'capture_test_counts' => true,
            'capture_docs_health' => true,
            'capture_architecture_validate' => true,
            'capture_completion_audit' => true,
            'capture_provider_capacity' => true,
            'capture_continuum_certification' => true,
            'allowed_paths_scope' => $allowedPaths,
            'workspace_hash_required' => true,
            'before_after_comparable' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function buildRiskClassification(string $riskLevel, array $payload): array
    {
        $requiresMigrationReview = (bool) ($payload['requires_migration_review'] ?? false);
        $requiresSecurityReview = (bool) ($payload['requires_security_review'] ?? false);
        $requiresProviderCostApproval = (bool) ($payload['requires_provider_cost_approval'] ?? false);
        $rollbackRequired = (bool) ($payload['rollback_required'] ?? in_array($riskLevel, ['high', 'critical'], true));

        return [
            'risk_level' => $riskLevel,
            'requires_migration_review' => $requiresMigrationReview,
            'requires_security_review' => $requiresSecurityReview,
            'requires_provider_cost_approval' => $requiresProviderCostApproval,
            'rollback_required' => $rollbackRequired,
            'criticality_lock_active' => $riskLevel === 'critical',
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function buildProviderTopologyRecommendation(array $payload, string $riskLevel): array
    {
        $explicit = $payload['provider_topology_recommendation'] ?? null;
        if (is_array($explicit) && $explicit !== []) {
            return $explicit;
        }

        return [
            'preferred_primary_runtime' => 'claude_cli',
            'preferred_reviewer_runtime' => 'codex_cli',
            'preferred_context_runtime' => 'gemini_cli',
            'local_runtime' => 'atlas-local',
            'requires_human_review' => in_array($riskLevel, ['high', 'critical'], true),
            'capacity_aware' => true,
            'fallback_governed' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  list<string>  $allowedPaths
     * @return array<string,mixed>
     */
    private function buildTestStrategy(array $payload, array $allowedPaths): array
    {
        $explicit = $payload['test_strategy'] ?? null;
        if (is_array($explicit) && $explicit !== []) {
            return $explicit;
        }

        return [
            'unit_tests_required' => true,
            'feature_tests_required' => true,
            'docs_health_required' => true,
            'architecture_validate_required' => true,
            'completion_audit_required' => true,
            'lint_required' => true,
            'cargo_check_required' => $this->touchesRustWorkspace($allowedPaths),
            'desktop_build_required' => $this->touchesDesktopWorkspace($allowedPaths),
            'rivals_separated_from_claim' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function buildRollbackStrategy(array $payload, string $riskLevel): array
    {
        $explicit = $payload['rollback_strategy'] ?? null;
        if (is_array($explicit) && $explicit !== []) {
            return $explicit;
        }

        return [
            'rollback_kind' => $riskLevel === 'critical' ? 'governed_promotion_rollback_required' : 'workspace_revert_or_governed_promotion',
            'checkpoint_required' => in_array($riskLevel, ['high', 'critical'], true),
            'forge_workspace_required' => true,
            'evidence_refs_required' => true,
            'human_approval_for_rollback' => $riskLevel === 'critical',
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function buildRivalsPlan(array $payload, ?string $expectedPowerGain): array
    {
        $explicit = $payload['rivals_evaluation_plan'] ?? null;
        if (is_array($explicit) && $explicit !== []) {
            return $explicit;
        }

        return [
            'mode' => 'diagnostic_local_only',
            'one_shot_evaluator_required' => $expectedPowerGain !== null,
            'evidence_pack_required' => $expectedPowerGain !== null,
            'forge_native_rivals_required' => false,
            'external_rivals_required_for_claim' => false,
            'separated_from_external_rivals_certification' => true,
            'never_promotes_external_rivals_claim' => true,
        ];
    }

    /**
     * @param  list<string>  $allowedPaths
     */
    private function resolveAutopromotionAllowed(
        bool $autopromotionRequested,
        string $riskLevel,
        array $allowedPaths,
    ): bool {
        if (! $autopromotionRequested) {
            return false;
        }
        if (in_array($riskLevel, ['high', 'critical'], true)) {
            return false;
        }
        // Allowed paths must not touch any forbidden critical surface.
        foreach ($allowedPaths as $path) {
            $lower = strtolower($path);
            foreach (self::AUTOPROMOTION_FORBIDDEN_PATTERNS as $pattern) {
                if (str_contains($lower, $pattern)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $successMetrics
     * @param  list<string>  $acceptanceGates
     * @param  list<string>  $canonicalDocs
     * @param  list<string>  $allowedPaths
     * @param  list<string>  $forbiddenPaths
     * @return list<string>
     */
    private function collectBlockers(
        ?string $title,
        ?string $problemStatement,
        ?string $businessRule,
        ?string $targetCapability,
        ?string $whyNow,
        ?string $expectedPowerGain,
        array $successMetrics,
        array $acceptanceGates,
        array $canonicalDocs,
        array $allowedPaths,
        array $forbiddenPaths,
        array $rollbackStrategy,
        array $testStrategy,
        bool $autopromotionRequested,
        bool $autopromotionAllowed,
        string $riskLevel,
    ): array {
        $blockers = [];
        if ($title === null) {
            $blockers[] = 'missing_title';
        }
        if ($problemStatement === null) {
            $blockers[] = 'missing_problem_statement';
        }
        if ($businessRule === null) {
            $blockers[] = 'missing_business_rule';
        }
        if ($targetCapability === null) {
            $blockers[] = 'missing_target_capability';
        }
        if ($whyNow === null) {
            $blockers[] = 'missing_why_now';
        }
        if ($expectedPowerGain === null) {
            $blockers[] = 'missing_expected_power_gain';
        }
        if ($successMetrics === []) {
            $blockers[] = 'missing_success_metrics';
        }
        if ($acceptanceGates === []) {
            $blockers[] = 'missing_acceptance_gates';
        }
        if ($canonicalDocs === []) {
            $blockers[] = 'missing_canonical_docs';
        }
        if ($allowedPaths === []) {
            $blockers[] = 'missing_allowed_paths';
        }
        if ($forbiddenPaths === []) {
            $blockers[] = 'missing_forbidden_paths';
        }
        if (empty($rollbackStrategy)) {
            $blockers[] = 'missing_rollback_strategy';
        }
        if (empty($testStrategy)) {
            $blockers[] = 'missing_test_strategy';
        }
        if ($autopromotionRequested && ! $autopromotionAllowed) {
            $blockers[] = 'autopromotion_requested_but_blocked_by_policy';
        }
        if ($riskLevel === 'critical' && $autopromotionRequested) {
            $blockers[] = 'critical_risk_cannot_be_autopromoted';
        }

        return $blockers;
    }

    /**
     * @param  list<string>  $blockers
     * @param  array<string,mixed>  $payload
     */
    private function resolveStatus(array $blockers, array $payload): string
    {
        if ($blockers !== []) {
            $isDraft = (bool) ($payload['draft'] ?? false);

            return $isDraft ? self::STATUS_DRAFT : self::STATUS_BLOCKED;
        }

        return self::STATUS_READY;
    }

    /**
     * @param  list<string>  $canonicalDocs
     * @param  list<string>  $allowedPaths
     * @return list<string>
     */
    private function resolveEvidenceRefs(array $canonicalDocs, array $allowedPaths): array
    {
        $refs = [
            'docs/engineering-knowledge-base/atlas-self-improvement-governance-ladder.md',
            'app/Services/Ai/SelfImprovement/AtlasSelfImprovementProposalPacketService.php',
        ];
        foreach ($canonicalDocs as $doc) {
            $refs[] = $doc;
        }
        foreach ($allowedPaths as $path) {
            $refs[] = 'allowed_path:'.$path;
        }

        return array_values(array_unique($refs));
    }

    /**
     * @param  list<string>  $blockers
     */
    private function resolveNextAction(string $status, array $blockers, bool $autopromotionAllowed): string
    {
        if ($status === self::STATUS_READY) {
            return 'run_proposal_power_gate';
        }
        if ($status === self::STATUS_DRAFT) {
            return 'fill_draft_fields_and_resubmit:'.implode(',', array_slice($blockers, 0, 3));
        }

        return 'resolve_blockers_then_resubmit:'.implode(',', array_slice($blockers, 0, 3));
    }

    /**
     * @param  list<string>  $allowedPaths
     */
    private function touchesRustWorkspace(array $allowedPaths): bool
    {
        foreach ($allowedPaths as $path) {
            if (str_contains($path, 'crates/') || str_contains($path, '.rs')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $allowedPaths
     */
    private function touchesDesktopWorkspace(array $allowedPaths): bool
    {
        foreach ($allowedPaths as $path) {
            if (str_contains($path, 'apps/desktop') || str_contains($path, 'packages/atlas-')) {
                return true;
            }
        }

        return false;
    }

    private function normalizeRiskLevel(mixed $value): string
    {
        $str = $this->stringOrNull($value);
        if ($str === null) {
            return 'medium';
        }
        $lower = strtolower($str);

        return in_array($lower, self::RISK_LEVELS, true) ? $lower : 'medium';
    }

    /**
     * @return list<string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $item) {
            $str = $this->stringOrNull($item);
            if ($str !== null) {
                $result[] = $str;
            }
        }

        return array_values(array_unique($result));
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }
        $int = (int) $value;

        return $int > 0 ? $int : null;
    }
}
