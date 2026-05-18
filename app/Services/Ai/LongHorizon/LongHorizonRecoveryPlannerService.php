<?php

declare(strict_types=1);

namespace App\Services\Ai\LongHorizon;

use App\Services\Ai\Mission\MissionCanonicalHash;
use InvalidArgumentException;

/**
 * Long-horizon recovery planner.
 *
 * TEOS-I1 Mission 7 — given a continuation pack v2, a freshness gate result
 * and (optionally) a failure capsule + pending human decisions, decide the
 * canonical `safe_resume_mode` for the next step and emit a deterministic
 * `atlas.long_horizon.recovery_plan.v1` payload.
 *
 * Pure planning service:
 *   - reads inputs as arrays (no DB writes; recovery_plan persistence is a
 *     later TEOS-I1 step);
 *   - emits a stable plan_hash via {@see MissionCanonicalHash::sha256};
 *   - never invokes a provider, never executes commands;
 *   - never silently downgrades a blocker to execute.
 *
 * NOT in scope:
 *   - ReplayManifest emission (separate TEOS-I1 mission).
 *   - Forge execution (this planner only emits the escalate_to_forge signal).
 *   - Re-running the freshness gate (caller is responsible).
 */
final class LongHorizonRecoveryPlannerService
{
    /**
     * Default scope-size budget under which a Dev recovery is still
     * considered Dev-tractable. Crossing this routes to escalate_to_forge.
     */
    public const DEFAULT_DEV_SCOPE_FILE_LIMIT = 12;

    public const DEFAULT_DEV_SCOPE_MODULE_LIMIT = 3;

    public function __construct() {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function plan(array $input): array
    {
        $scopeType = $this->requiredScopeType($input);
        $scopeId = $this->requiredString($input, 'scope_id');
        $pack = is_array($input['continuation_pack'] ?? null) ? $input['continuation_pack'] : [];
        $freshness = is_array($input['freshness_result'] ?? null) ? $input['freshness_result'] : [];
        $failureCapsule = is_array($input['failure_capsule'] ?? null) ? $input['failure_capsule'] : null;
        $humanDecisions = $this->normalizeList($input['human_decisions_pending'] ?? null);
        $reviewRequired = (bool) ($input['review_required'] ?? false);
        $scopeLimits = $this->scopeLimits(is_array($input['dev_scope_limits'] ?? null) ? $input['dev_scope_limits'] : []);

        $blockers = [];
        $remediation = [];
        $requiredContextRefresh = [];

        // Always carry pack-level blockers forward — recovery cannot silently
        // erase them.
        foreach ((array) ($pack['blockers'] ?? []) as $blocker) {
            $blockers[] = is_array($blocker) ? $blocker : ['kind' => 'pack_blocker', 'description' => (string) $blocker];
        }

        // Compute decision precedence.
        $decision = $this->resolveMode(
            scopeType: $scopeType,
            pack: $pack,
            freshness: $freshness,
            failureCapsule: $failureCapsule,
            humanDecisions: $humanDecisions,
            reviewRequired: $reviewRequired,
            scopeLimits: $scopeLimits,
        );

        $safeResumeMode = $decision['mode'];
        $reasonTrail = $decision['reasons'];
        $escalateToForge = $safeResumeMode === AtlasLongHorizonCanon::SAFE_RESUME_ESCALATE_TO_FORGE;
        $escalationReason = $escalateToForge ? $decision['escalation_reason'] : null;

        // Aggregate context refresh hints from freshness + pack stale refs.
        $requiredContextRefresh = $this->collectRequiredContextRefresh($freshness, $pack);

        // Aggregate remediation steps based on mode.
        $remediation = $this->remediationSteps($safeResumeMode, $reasonTrail, $failureCapsule);

        // Merge any required_human_decisions from pack into the planner's
        // canonical surface (operator-pending decisions win when present).
        $packHumanDecisions = $this->normalizeList($pack['human_decisions_required'] ?? null);
        $requiredHumanDecisions = $humanDecisions !== [] ? $humanDecisions : $packHumanDecisions;

        $nextSafeAction = $this->nextSafeAction($safeResumeMode);
        $confidence = $this->confidenceFor($safeResumeMode);

        $evidenceRefs = $this->collectEvidenceRefs($scopeType, $scopeId, $pack, $freshness, $failureCapsule);

        $payload = [
            'schema_version' => AtlasLongHorizonCanon::RECOVERY_PLAN_SCHEMA_VERSION,
            'recovery_plan_id' => 'recovery-'.bin2hex(random_bytes(8)),
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'safe_resume_mode' => $safeResumeMode,
            'next_safe_action' => $nextSafeAction,
            'required_context_refresh' => $requiredContextRefresh,
            'required_human_decisions' => $requiredHumanDecisions,
            'blockers' => $blockers,
            'remediation_steps' => $remediation,
            'escalate_to_forge' => $escalateToForge,
            'escalation_reason' => $escalationReason,
            'confidence' => $confidence,
            'evidence_refs' => $evidenceRefs,
            'reasons' => $reasonTrail,
        ];

        $payload['plan_hash'] = self::canonicalPlanHash($payload);

        return $payload;
    }

    /**
     * Recompute the canonical hash from a payload. Volatile fields (id, hash
     * itself) are stripped so replay yields the same hash for identical
     * input state.
     *
     * @param  array<string,mixed>  $payload
     */
    public static function canonicalPlanHash(array $payload): string
    {
        $payload['schema_version'] = AtlasLongHorizonCanon::RECOVERY_PLAN_SCHEMA_VERSION;
        unset($payload['recovery_plan_id'], $payload['plan_hash']);

        return MissionCanonicalHash::sha256($payload);
    }

    /**
     * @param  array<string,mixed>  $pack
     * @param  array<string,mixed>  $freshness
     * @param  array<string,mixed>|null  $failureCapsule
     * @param  list<array<string,mixed>>  $humanDecisions
     * @param  array{file_limit:int,module_limit:int}  $scopeLimits
     * @return array{mode:string,reasons:list<string>,escalation_reason:string|null}
     */
    private function resolveMode(
        string $scopeType,
        array $pack,
        array $freshness,
        ?array $failureCapsule,
        array $humanDecisions,
        bool $reviewRequired,
        array $scopeLimits,
    ): array {
        $reasons = [];
        $escalationReason = null;

        $freshnessStatus = (string) ($freshness['status'] ?? 'fresh');
        if ($freshnessStatus === 'stale_failed_closed') {
            $reasons[] = 'freshness_stale_failed_closed';
            $mode = AtlasLongHorizonCanon::SAFE_RESUME_BLOCKED;

            return ['mode' => $mode, 'reasons' => $reasons, 'escalation_reason' => null];
        }

        $packMode = (string) ($pack['safe_resume_mode'] ?? '');
        if ($packMode === AtlasLongHorizonCanon::SAFE_RESUME_BLOCKED) {
            $reasons[] = 'continuation_pack_marked_blocked';

            return ['mode' => AtlasLongHorizonCanon::SAFE_RESUME_BLOCKED, 'reasons' => $reasons, 'escalation_reason' => null];
        }

        if ($failureCapsule !== null && $this->hasOpenFailure($failureCapsule)) {
            $reasons[] = 'failure_capsule_open_'.(string) ($failureCapsule['gate'] ?? 'unknown_gate');

            return ['mode' => AtlasLongHorizonCanon::SAFE_RESUME_REPAIR, 'reasons' => $reasons, 'escalation_reason' => null];
        }

        if ($humanDecisions !== []) {
            $reasons[] = 'operator_decisions_pending_'.count($humanDecisions);

            return ['mode' => AtlasLongHorizonCanon::SAFE_RESUME_ASK_HUMAN, 'reasons' => $reasons, 'escalation_reason' => null];
        }

        if ($this->packHasCriticalUnresolvedLoss($pack)) {
            $hasRecoveryQueries = ! empty($pack['recovery_queries'] ?? null)
                || ! empty($pack['compaction_receipt']['recovery_queries'] ?? null);
            $reasons[] = $hasRecoveryQueries
                ? 'unresolved_loss_critical_with_recovery_queries'
                : 'unresolved_loss_critical_without_recovery_queries';

            return [
                'mode' => $hasRecoveryQueries
                    ? AtlasLongHorizonCanon::SAFE_RESUME_ASK_HUMAN
                    : AtlasLongHorizonCanon::SAFE_RESUME_BLOCKED,
                'reasons' => $reasons,
                'escalation_reason' => null,
            ];
        }

        if ($this->devScopeExceedsLimit($scopeType, $pack, $scopeLimits, $escalationReason)) {
            $reasons[] = 'dev_scope_exceeded_'.$escalationReason;

            return [
                'mode' => AtlasLongHorizonCanon::SAFE_RESUME_ESCALATE_TO_FORGE,
                'reasons' => $reasons,
                'escalation_reason' => $escalationReason,
            ];
        }

        if ($reviewRequired || $packMode === AtlasLongHorizonCanon::SAFE_RESUME_REVIEW) {
            $reasons[] = 'review_required';

            return ['mode' => AtlasLongHorizonCanon::SAFE_RESUME_REVIEW, 'reasons' => $reasons, 'escalation_reason' => null];
        }

        if ($freshnessStatus === 'stale_advisory') {
            $reasons[] = 'freshness_stale_advisory';

            return ['mode' => AtlasLongHorizonCanon::SAFE_RESUME_READ_ONLY, 'reasons' => $reasons, 'escalation_reason' => null];
        }

        if ($packMode === AtlasLongHorizonCanon::SAFE_RESUME_READ_ONLY) {
            $reasons[] = 'continuation_pack_marked_read_only';

            return ['mode' => AtlasLongHorizonCanon::SAFE_RESUME_READ_ONLY, 'reasons' => $reasons, 'escalation_reason' => null];
        }

        $reasons[] = 'all_signals_healthy';

        return ['mode' => AtlasLongHorizonCanon::SAFE_RESUME_EXECUTE, 'reasons' => $reasons, 'escalation_reason' => null];
    }

    /**
     * @param  array<string,mixed>  $failureCapsule
     */
    private function hasOpenFailure(array $failureCapsule): bool
    {
        $decision = strtolower((string) ($failureCapsule['decision'] ?? ''));
        if (in_array($decision, ['retry', 'stop', 'escalate'], true)) {
            return true;
        }
        $status = strtolower((string) ($failureCapsule['status'] ?? ''));
        if (in_array($status, ['failed', 'blocked', 'scope_violation'], true)) {
            return true;
        }
        $signals = (array) ($failureCapsule['escalation_signal_delta'] ?? []);

        return $signals !== [];
    }

    /**
     * @param  array<string,mixed>  $pack
     */
    private function packHasCriticalUnresolvedLoss(array $pack): bool
    {
        $receipt = (array) ($pack['compaction_receipt'] ?? []);
        if (($receipt['loss_risk'] ?? null) === AtlasLongHorizonCanon::LOSS_RISK_HIGH
            && ! empty($receipt['unresolved_loss'] ?? [])) {
            return true;
        }
        $explicit = (array) ($pack['unresolved_loss'] ?? []);

        return $explicit !== [];
    }

    /**
     * @param  array<string,mixed>  $pack
     * @param  array{file_limit:int,module_limit:int}  $scopeLimits
     */
    private function devScopeExceedsLimit(
        string $scopeType,
        array $pack,
        array $scopeLimits,
        ?string &$escalationReason,
    ): bool {
        if (! str_starts_with($scopeType, 'dev_')) {
            return false;
        }
        $files = $this->extractScopeFiles($pack);
        if (count($files) > $scopeLimits['file_limit']) {
            $escalationReason = 'file_count_'.count($files).'_gt_'.$scopeLimits['file_limit'];

            return true;
        }
        $modules = $this->extractScopeModules($files);
        if (count($modules) > $scopeLimits['module_limit']) {
            $escalationReason = 'modules_'.count($modules).'_gt_'.$scopeLimits['module_limit'];

            return true;
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $pack
     * @return list<string>
     */
    private function extractScopeFiles(array $pack): array
    {
        $files = [];
        foreach ((array) ($pack['context_manifest'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $ref = (string) ($entry['ref'] ?? '');
            if ($ref !== '' && ! str_starts_with($ref, 'stage_receipt:')) {
                $files[] = $ref;
            }
        }
        foreach ((array) ($pack['evidence_refs'] ?? []) as $ref) {
            if (is_string($ref) && str_contains($ref, '/') && ! str_starts_with($ref, 'stage_receipt:')) {
                $files[] = $ref;
            }
        }
        foreach ((array) ($pack['scope_files'] ?? []) as $ref) {
            if (is_string($ref)) {
                $files[] = $ref;
            }
        }

        return array_values(array_unique($files));
    }

    /**
     * @param  list<string>  $files
     * @return list<string>
     */
    private function extractScopeModules(array $files): array
    {
        $modules = [];
        foreach ($files as $file) {
            $dir = dirname($file);
            if ($dir === '.' || $dir === '/' || $dir === '') {
                $modules[] = $file;

                continue;
            }
            $modules[] = $dir;
        }

        return array_values(array_unique($modules));
    }

    /**
     * @param  array<string,mixed>  $freshness
     * @param  array<string,mixed>  $pack
     * @return list<string>
     */
    private function collectRequiredContextRefresh(array $freshness, array $pack): array
    {
        $refresh = [];
        foreach ((array) ($freshness['stale_refs'] ?? []) as $ref) {
            if (is_string($ref) && $ref !== '') {
                $refresh[] = $ref;
            }
        }
        foreach ((array) ($pack['stale_refs'] ?? []) as $ref) {
            if (is_string($ref) && $ref !== '') {
                $refresh[] = $ref;
            }
        }
        foreach ((array) ($pack['missing_required_refs'] ?? []) as $ref) {
            if (is_string($ref) && $ref !== '') {
                $refresh[] = $ref;
            }
        }

        return array_values(array_unique($refresh));
    }

    /**
     * @param  list<string>  $reasons
     * @param  array<string,mixed>|null  $failureCapsule
     * @return list<array<string,mixed>>
     */
    private function remediationSteps(string $mode, array $reasons, ?array $failureCapsule): array
    {
        $steps = [];
        switch ($mode) {
            case AtlasLongHorizonCanon::SAFE_RESUME_EXECUTE:
                break;
            case AtlasLongHorizonCanon::SAFE_RESUME_READ_ONLY:
                $steps[] = [
                    'action' => 'reload_required_context_then_request_replan',
                    'severity' => 'low',
                    'description' => 'Pack stays read-only until stale refs are refreshed.',
                ];
                break;
            case AtlasLongHorizonCanon::SAFE_RESUME_REPAIR:
                $steps[] = [
                    'action' => 'route_to_dev_repair_loop',
                    'severity' => 'high',
                    'description' => 'Open repair loop for the failure capsule referenced in this plan.',
                    'failure_gate' => (string) ($failureCapsule['gate'] ?? 'unknown_gate'),
                ];
                break;
            case AtlasLongHorizonCanon::SAFE_RESUME_REVIEW:
                $steps[] = [
                    'action' => 'open_review_for_latest_artifact',
                    'severity' => 'medium',
                    'description' => 'Caller flagged review_required; route to reviewer surface.',
                ];
                break;
            case AtlasLongHorizonCanon::SAFE_RESUME_ASK_HUMAN:
                $steps[] = [
                    'action' => 'await_human_decision',
                    'severity' => 'high',
                    'description' => 'Decisions are pending — runtime cannot decide for the operator.',
                ];
                break;
            case AtlasLongHorizonCanon::SAFE_RESUME_BLOCKED:
                $steps[] = [
                    'action' => 'resolve_blocker_before_resume',
                    'severity' => 'critical',
                    'description' => 'Hard blocker present; pack cannot advance without operator intervention.',
                ];
                break;
            case AtlasLongHorizonCanon::SAFE_RESUME_ESCALATE_TO_FORGE:
                $steps[] = [
                    'action' => 'emit_dev_to_forge_escalation_packet',
                    'severity' => 'high',
                    'description' => 'Scope crossed the Dev envelope; Forge intake required.',
                ];
                break;
        }
        if ($reasons !== []) {
            $steps[] = [
                'action' => 'attach_reason_trail_to_audit',
                'severity' => 'low',
                'description' => 'Reasons that drove the chosen mode: '.implode(', ', $reasons),
            ];
        }

        return $steps;
    }

    private function nextSafeAction(string $mode): string
    {
        return match ($mode) {
            AtlasLongHorizonCanon::SAFE_RESUME_EXECUTE => 'continue_with_next_stage',
            AtlasLongHorizonCanon::SAFE_RESUME_READ_ONLY => 'consume_pack_in_read_only_mode',
            AtlasLongHorizonCanon::SAFE_RESUME_REPAIR => 'rerun_failed_stage_with_repair_loop',
            AtlasLongHorizonCanon::SAFE_RESUME_REVIEW => 'open_review_for_latest_stage',
            AtlasLongHorizonCanon::SAFE_RESUME_ASK_HUMAN => 'await_human_decision_on_open_questions',
            AtlasLongHorizonCanon::SAFE_RESUME_BLOCKED => 'resolve_blocker_before_resume',
            AtlasLongHorizonCanon::SAFE_RESUME_ESCALATE_TO_FORGE => 'emit_dev_to_forge_escalation_packet',
            default => 'consume_pack_in_read_only_mode',
        };
    }

    private function confidenceFor(string $mode): float
    {
        return match ($mode) {
            AtlasLongHorizonCanon::SAFE_RESUME_EXECUTE => 0.95,
            AtlasLongHorizonCanon::SAFE_RESUME_REVIEW => 0.7,
            AtlasLongHorizonCanon::SAFE_RESUME_READ_ONLY => 0.55,
            AtlasLongHorizonCanon::SAFE_RESUME_REPAIR => 0.4,
            AtlasLongHorizonCanon::SAFE_RESUME_ASK_HUMAN => 0.3,
            AtlasLongHorizonCanon::SAFE_RESUME_ESCALATE_TO_FORGE => 0.2,
            AtlasLongHorizonCanon::SAFE_RESUME_BLOCKED => 0.0,
            default => 0.1,
        };
    }

    /**
     * @param  array<string,mixed>  $pack
     * @param  array<string,mixed>  $freshness
     * @param  array<string,mixed>|null  $failureCapsule
     * @return list<string>
     */
    private function collectEvidenceRefs(
        string $scopeType,
        string $scopeId,
        array $pack,
        array $freshness,
        ?array $failureCapsule,
    ): array {
        $refs = [];
        $refs[] = sprintf('scope:%s:%s', $scopeType, $scopeId);
        if (! empty($pack['pack_hash'])) {
            $refs[] = 'continuation_pack:'.$pack['pack_hash'];
        }
        if (! empty($freshness['status'])) {
            $refs[] = 'freshness:'.(string) $freshness['status'];
        }
        if ($failureCapsule !== null && ! empty($failureCapsule['capsule_hash'])) {
            $refs[] = 'failure_capsule:'.(string) $failureCapsule['capsule_hash'];
        }
        if (! empty($pack['compaction_receipt']['receipt_hash'])) {
            $refs[] = 'compaction_receipt:'.(string) $pack['compaction_receipt']['receipt_hash'];
        }

        return array_values(array_unique($refs));
    }

    /**
     * @param  array<string,mixed>  $limits
     * @return array{file_limit:int,module_limit:int}
     */
    private function scopeLimits(array $limits): array
    {
        return [
            'file_limit' => max(1, (int) ($limits['file_limit'] ?? self::DEFAULT_DEV_SCOPE_FILE_LIMIT)),
            'module_limit' => max(1, (int) ($limits['module_limit'] ?? self::DEFAULT_DEV_SCOPE_MODULE_LIMIT)),
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function requiredScopeType(array $input): string
    {
        $value = (string) ($input['scope_type'] ?? '');
        if ($value === '' || ! in_array($value, AtlasLongHorizonCanon::ALLOWED_SCOPE_TYPES, true)) {
            throw new InvalidArgumentException(
                'LongHorizonRecoveryPlanner: scope_type must be one of ['
                .implode(',', AtlasLongHorizonCanon::ALLOWED_SCOPE_TYPES)."], got '{$value}'.",
            );
        }

        return $value;
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function requiredString(array $input, string $key): string
    {
        $value = $input[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("LongHorizonRecoveryPlanner: {$key} is required.");
        }

        return trim($value);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function normalizeList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $entry) {
            if (is_array($entry)) {
                $out[] = $entry;
            } elseif (is_string($entry) && trim($entry) !== '') {
                $out[] = ['description' => trim($entry)];
            }
        }

        return $out;
    }
}
