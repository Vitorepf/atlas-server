<?php

declare(strict_types=1);

namespace App\Services\Ai\LongHorizon;

use App\Support\CanonicalValue;
use App\Models\AtlasLongHorizonCompactionReceipt;
use App\Models\AtlasLongHorizonContinuationPack;
use App\Models\AtlasLongHorizonReplayManifest;
use App\Services\Ai\LongHorizon\Gate\LongHorizonContextFreshnessGate;
use App\Services\Ai\LongHorizon\Gate\LongHorizonContextFreshnessGateResult;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * TEOS-I2 M10 · Long-Horizon Continuity Certification (full surface).
 *
 * TEOS-I1 readiness ({@see AtlasTeosReadinessCertificationService}) validates
 * that the primitives (models, services, migrations) are wired. This service
 * goes one layer up: given a concrete (scope_type, scope_id), can the Atlas
 * actually resume safely RIGHT NOW?
 *
 * It composes the existing primitives without duplicating their logic:
 *
 *   - {@see AtlasLongHorizonContinuationPack}    — must exist + context_pack_hash present
 *   - {@see AtlasLongHorizonCompactionReceipt}   — coverage == 1.0 when compaction was used
 *   - {@see LongHorizonContextFreshnessGate}     — freshness pass/warn/blocked
 *   - {@see LongHorizonRecoveryPlannerService}   — recovery plan when freshness blocks
 *   - {@see AtlasLongHorizonReplayManifest}      — replay manifest available (TEOS-I2 partial)
 *
 * Hard rules:
 *   - read-only, side-effect-free;
 *   - never fakes `ready` when replay manifest / freshness / evidence are missing;
 *   - never returns raw operator_input / response_text — only hashes, ids, counts;
 *   - `certification_hash` is deterministic over canonical content (excludes generated_at).
 *
 * Schema: `atlas.teos.continuity_certification.v1`.
 */
class LongHorizonContinuityCertificationService
{
    public const SCHEMA_VERSION = 'atlas.teos.continuity_certification.v1';

    public const STATUS_READY = 'ready';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_BLOCKED = 'blocked';

    public const SEVERITY_P0 = 'P0';

    public const SEVERITY_P1 = 'P1';

    public const SEVERITY_P2 = 'P2';

    public function __construct(
        private readonly LongHorizonContextFreshnessGate $freshnessGate,
        private readonly LongHorizonRecoveryPlannerService $recoveryPlanner,
    ) {}

    /**
     * Certify continuity for a given (scope_type, scope_id).
     *
     * @param  array<string,mixed>  $input  {
     *                                      scope_type: string (required, must be in canon),
     *                                      scope_id: ?string,
     *                                      continuation_pack_id: ?string,
     *                                      required_evidence_kinds: list<string>,
     *                                      intended_mode: ?string (default 'execute'),
     *                                      strict: ?bool (default false),
     *                                      strict_replay_required: ?bool (default false — escalates missing replay to P0),
     *                                      now: ?CarbonImmutable
     *                                      }
     * @return array<string,mixed>
     */
    public function certify(array $input): array
    {
        $scopeType = $this->stringOrNull($input['scope_type'] ?? null);
        if ($scopeType === null) {
            throw new InvalidArgumentException('scope_type is required');
        }
        if (! in_array($scopeType, AtlasLongHorizonCanon::ALLOWED_SCOPE_TYPES, true)) {
            throw new InvalidArgumentException("scope_type [{$scopeType}] not in canon");
        }

        $scopeId = $this->stringOrNull($input['scope_id'] ?? null);
        $intendedMode = $this->stringOrNull($input['intended_mode'] ?? null) ?? AtlasLongHorizonCanon::SAFE_RESUME_EXECUTE;
        $strict = (bool) ($input['strict'] ?? false);
        $strictReplay = (bool) ($input['strict_replay_required'] ?? false);
        $requiredEvidenceKinds = is_array($input['required_evidence_kinds'] ?? null)
            ? array_values(array_filter($input['required_evidence_kinds'], fn ($v): bool => is_string($v) && trim($v) !== ''))
            : [];
        $now = isset($input['now']) && $input['now'] instanceof CarbonImmutable ? $input['now'] : CarbonImmutable::now();

        $pack = $this->resolveContinuationPack($scopeType, $scopeId, $input['continuation_pack_id'] ?? null);
        $compaction = $pack ? $this->resolveLatestCompactionReceipt($pack) : null;
        $replayManifest = $pack ? $this->resolveReplayManifest($pack) : null;

        $checks = [];
        $blockers = [];
        $warnings = [];
        $evidenceRefs = [];

        // Check 1 — continuation pack exists (P0).
        $packCheck = $this->checkContinuationPackExists($pack, $scopeType, $scopeId);
        $checks[] = $packCheck;
        $this->collectFinding($packCheck, $blockers, $warnings);
        if ($pack !== null) {
            $evidenceRefs[] = 'continuation_pack:'.$pack->uuid;
        }

        // Check 2 — context_pack_hash present (P0).
        $contextHashCheck = $this->checkContextPackHash($pack);
        $checks[] = $contextHashCheck;
        $this->collectFinding($contextHashCheck, $blockers, $warnings);

        // Check 3 — compaction coverage (P0 when compaction used, otherwise pass).
        $coverageCheck = $this->checkCompactionCoverage($compaction);
        $checks[] = $coverageCheck;
        $this->collectFinding($coverageCheck, $blockers, $warnings);
        if ($compaction !== null) {
            $evidenceRefs[] = 'compaction_receipt:'.$compaction->uuid;
        }

        // Check 4 — freshness gate (P0 when blocked, warn otherwise).
        [$freshness, $freshnessCheck] = $this->evaluateFreshness(
            $pack, $compaction, $scopeType, $scopeId, $intendedMode, $strict, $requiredEvidenceKinds, $now,
        );
        $checks[] = $freshnessCheck;
        $this->collectFinding($freshnessCheck, $blockers, $warnings);
        if ($freshness !== null) {
            $evidenceRefs[] = 'freshness:'.$freshness->freshnessHash;
        }

        // Check 5 — recovery plan when freshness blocks (P0).
        [$recoveryPlan, $recoveryCheck] = $this->evaluateRecoveryWhenBlocked($freshness, $pack);
        $checks[] = $recoveryCheck;
        $this->collectFinding($recoveryCheck, $blockers, $warnings);
        if ($recoveryPlan !== null) {
            $evidenceRefs[] = 'recovery_plan:'.($recoveryPlan['plan_hash'] ?? 'unknown');
        }

        // Check 6 — replay manifest available (P1 default, P0 when strict_replay_required).
        $replayCheck = $this->checkReplayManifest($replayManifest, $pack, $strictReplay);
        $checks[] = $replayCheck;
        $this->collectFinding($replayCheck, $blockers, $warnings);
        if ($replayManifest !== null) {
            $evidenceRefs[] = 'replay_manifest:'.$replayManifest->uuid;
        }

        // Check 7 — completion claims need evidence (P0).
        $evidenceCheck = $this->checkEvidencePresence($pack, $requiredEvidenceKinds);
        $checks[] = $evidenceCheck;
        $this->collectFinding($evidenceCheck, $blockers, $warnings);

        // Resolve overall status (honest — never `ready` with P0 blockers).
        $status = $this->resolveStatus($checks);

        $safeResumeMode = $this->resolveSafeResumeMode($status, $freshness, $recoveryPlan, $pack);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => $now->toJSON(),
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'intended_mode' => $intendedMode,
            'strict' => $strict,
            'strict_replay_required' => $strictReplay,
            'status' => $status,
            'summary' => $this->summarize($checks),
            'safe_resume_mode' => $safeResumeMode,
            'context_freshness' => $freshness?->toCanonicalArray() ?? ['status' => 'missing'],
            'compaction_coverage' => [
                'must_keep_coverage' => $compaction?->must_keep_coverage,
                'loss_risk' => $compaction?->loss_risk,
                'unresolved_loss_count' => is_array($compaction?->unresolved_loss ?? null) ? count($compaction->unresolved_loss) : 0,
                'receipt_uuid' => $compaction?->uuid,
            ],
            'replay_manifest_status' => $this->replayManifestStatus($replayManifest),
            'recovery_plan_status' => $this->recoveryPlanStatus($freshness, $recoveryPlan),
            'recovery_plan' => $recoveryPlan,
            'checks' => $checks,
            'blockers' => $blockers,
            'warnings' => $warnings,
            'evidence_refs' => array_values(array_unique($evidenceRefs)),
            'claim_policy' => [
                'benchmark_not_run' => true,
                'rivals_compared' => false,
                'allows_external_superiority_claim' => false,
                'declares_teos_complete' => false,
            ],
        ];

        $payload['certification_hash'] = $this->hashCertification($payload);

        return $payload;
    }

    private function resolveContinuationPack(string $scopeType, ?string $scopeId, mixed $explicitId): ?AtlasLongHorizonContinuationPack
    {
        if (! DatabaseTableAvailability::has('atlas_long_horizon_continuation_packs')) {
            return null;
        }
        $explicit = $this->stringOrNull($explicitId);
        try {
            if ($explicit !== null) {
                return AtlasLongHorizonContinuationPack::query()->where('uuid', $explicit)->first();
            }
            $query = AtlasLongHorizonContinuationPack::query()
                ->where('scope_type', $scopeType)
                ->orderByDesc('created_at');
            if ($scopeId !== null) {
                $query->where('scope_id', $scopeId);
            }

            return $query->first();
        } catch (Throwable) {
            return null;
        }
    }

    private function resolveLatestCompactionReceipt(AtlasLongHorizonContinuationPack $pack): ?AtlasLongHorizonCompactionReceipt
    {
        if (! DatabaseTableAvailability::has('atlas_long_horizon_compaction_receipts')) {
            return null;
        }
        try {
            $query = AtlasLongHorizonCompactionReceipt::query()
                ->where('scope_type', $pack->scope_type)
                ->orderByDesc('created_at');
            if (! empty($pack->scope_id)) {
                $query->where('scope_id', $pack->scope_id);
            }

            return $query->first();
        } catch (Throwable) {
            return null;
        }
    }

    private function resolveReplayManifest(AtlasLongHorizonContinuationPack $pack): ?AtlasLongHorizonReplayManifest
    {
        if (! DatabaseTableAvailability::has('atlas_long_horizon_replay_manifests')) {
            return null;
        }
        try {
            return AtlasLongHorizonReplayManifest::query()
                ->where('continuation_pack_id', $pack->id)
                ->orderByDesc('created_at')
                ->first();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function checkContinuationPackExists(?AtlasLongHorizonContinuationPack $pack, string $scopeType, ?string $scopeId): array
    {
        if ($pack === null) {
            return $this->finding(
                id: 'continuation_pack_exists',
                status: 'fail',
                severity: self::SEVERITY_P0,
                reason: "no continuation pack found for scope_type={$scopeType} scope_id=".($scopeId ?? 'null'),
                remediation: 'build a continuation pack via Forge/Dev builder before resume',
            );
        }

        return $this->finding(
            id: 'continuation_pack_exists',
            status: 'pass',
            severity: self::SEVERITY_P0,
            reason: 'continuation pack persisted',
            evidence_refs: ['pack_hash:'.$pack->pack_hash],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkContextPackHash(?AtlasLongHorizonContinuationPack $pack): array
    {
        if ($pack === null) {
            return $this->finding(
                id: 'context_pack_hash_present',
                status: 'fail',
                severity: self::SEVERITY_P0,
                reason: 'no continuation pack — context_pack_hash cannot be evaluated',
            );
        }
        $hash = $this->stringOrNull($pack->context_pack_hash);
        if ($hash === null) {
            return $this->finding(
                id: 'context_pack_hash_present',
                status: 'fail',
                severity: self::SEVERITY_P0,
                reason: 'continuation pack lacks context_pack_hash — provenance unverifiable',
                remediation: 'rebuild pack with attached context_pack_hash',
            );
        }

        return $this->finding(
            id: 'context_pack_hash_present',
            status: 'pass',
            severity: self::SEVERITY_P0,
            reason: 'context_pack_hash present',
            evidence_refs: ['context_pack_hash:'.$hash],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkCompactionCoverage(?AtlasLongHorizonCompactionReceipt $compaction): array
    {
        if ($compaction === null) {
            return $this->finding(
                id: 'compaction_coverage_full',
                status: 'pass',
                severity: self::SEVERITY_P1,
                reason: 'no compaction was applied — coverage check trivially passes',
            );
        }
        $coverage = (float) ($compaction->must_keep_coverage ?? 0);
        if ($coverage < 1.0) {
            return $this->finding(
                id: 'compaction_coverage_full',
                status: 'fail',
                severity: self::SEVERITY_P0,
                reason: sprintf('must_keep_coverage=%0.3f below 1.0 — compaction discarded critical items', $coverage),
                remediation: 'replay compaction_recovery_queries before resume; restore must_keep items',
                evidence_refs: ['compaction_receipt:'.$compaction->uuid, 'loss_risk:'.$compaction->loss_risk],
            );
        }

        return $this->finding(
            id: 'compaction_coverage_full',
            status: 'pass',
            severity: self::SEVERITY_P0,
            reason: 'must_keep_coverage == 1.0',
            evidence_refs: ['compaction_receipt:'.$compaction->uuid],
        );
    }

    /**
     * @param  list<string>  $requiredEvidenceKinds
     * @return array{0:?LongHorizonContextFreshnessGateResult,1:array<string,mixed>}
     */
    private function evaluateFreshness(
        ?AtlasLongHorizonContinuationPack $pack,
        ?AtlasLongHorizonCompactionReceipt $compaction,
        string $scopeType,
        ?string $scopeId,
        string $intendedMode,
        bool $strict,
        array $requiredEvidenceKinds,
        CarbonImmutable $now,
    ): array {
        if ($pack === null) {
            return [null, $this->finding(
                id: 'freshness_gate',
                status: 'fail',
                severity: self::SEVERITY_P0,
                reason: 'cannot evaluate freshness without continuation pack',
            )];
        }

        try {
            $result = $this->freshnessGate->evaluate([
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'continuation_pack_id' => (string) $pack->id,
                'context_manifest' => is_array($pack->context_manifest) ? $pack->context_manifest : [],
                'compaction_receipt_payload' => $compaction === null ? null : [
                    'must_keep_coverage' => (float) ($compaction->must_keep_coverage ?? 0),
                    'unresolved_loss' => is_array($compaction->unresolved_loss) ? $compaction->unresolved_loss : [],
                    'loss_risk' => $compaction->loss_risk,
                ],
                'required_evidence_kinds' => $requiredEvidenceKinds,
                'intended_mode' => $intendedMode,
                'strict' => $strict,
                'now' => $now,
            ]);
        } catch (Throwable $e) {
            return [null, $this->finding(
                id: 'freshness_gate',
                status: 'fail',
                severity: self::SEVERITY_P0,
                reason: 'freshness gate threw: '.$e::class,
            )];
        }

        $status = $result->status;
        $finding = match ($status) {
            'pass' => $this->finding(
                id: 'freshness_gate',
                status: 'pass',
                severity: self::SEVERITY_P0,
                reason: 'freshness gate: pass',
                evidence_refs: ['freshness_hash:'.$result->freshnessHash],
            ),
            'warn' => $this->finding(
                id: 'freshness_gate',
                status: 'warn',
                severity: self::SEVERITY_P1,
                reason: 'freshness gate: warn — '.implode(',', $result->warnings),
                remediation: 'rehydrate stale refs OR downgrade intended_mode to read_only',
                evidence_refs: ['freshness_hash:'.$result->freshnessHash],
            ),
            default => $this->finding(
                id: 'freshness_gate',
                status: 'fail',
                severity: self::SEVERITY_P0,
                reason: 'freshness gate: blocked — '.implode(',', $result->blockingReasons),
                remediation: 'run recovery planner; restore required refs; resolve must_keep coverage',
                evidence_refs: ['freshness_hash:'.$result->freshnessHash],
            ),
        };

        return [$result, $finding];
    }

    /**
     * @return array{0:?array<string,mixed>,1:array<string,mixed>}
     */
    private function evaluateRecoveryWhenBlocked(
        ?LongHorizonContextFreshnessGateResult $freshness,
        ?AtlasLongHorizonContinuationPack $pack,
    ): array {
        if ($freshness === null || $freshness->status !== 'blocked') {
            return [null, $this->finding(
                id: 'recovery_plan_when_needed',
                status: 'pass',
                severity: self::SEVERITY_P1,
                reason: 'freshness is not blocked — recovery plan not required',
            )];
        }
        if ($pack === null) {
            return [null, $this->finding(
                id: 'recovery_plan_when_needed',
                status: 'fail',
                severity: self::SEVERITY_P0,
                reason: 'freshness blocked but no continuation pack to plan from',
            )];
        }

        try {
            $plan = $this->recoveryPlanner->plan([
                'scope_type' => $pack->scope_type,
                'scope_id' => $pack->scope_id,
                'continuation_pack_payload' => $pack->toArray(),
                'freshness_payload' => $freshness->toCanonicalArray(),
            ]);
        } catch (Throwable $e) {
            return [null, $this->finding(
                id: 'recovery_plan_when_needed',
                status: 'fail',
                severity: self::SEVERITY_P0,
                reason: 'recovery planner threw: '.$e::class,
            )];
        }

        $mode = (string) ($plan['safe_resume_mode'] ?? 'blocked');
        $finding = $this->finding(
            id: 'recovery_plan_when_needed',
            status: 'pass',
            severity: self::SEVERITY_P0,
            reason: 'recovery plan produced with safe_resume_mode='.$mode,
            evidence_refs: ['recovery_plan_hash:'.($plan['plan_hash'] ?? 'unknown')],
        );

        return [$plan, $finding];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkReplayManifest(?AtlasLongHorizonReplayManifest $manifest, ?AtlasLongHorizonContinuationPack $pack, bool $strictReplay): array
    {
        if ($manifest !== null) {
            return $this->finding(
                id: 'replay_manifest_available',
                status: 'pass',
                severity: self::SEVERITY_P1,
                reason: 'replay manifest available',
                evidence_refs: ['replay_manifest:'.$manifest->uuid, 'replay_status:'.$manifest->replay_status],
            );
        }
        if ($pack === null) {
            return $this->finding(
                id: 'replay_manifest_available',
                status: 'fail',
                severity: self::SEVERITY_P0,
                reason: 'no continuation pack — replay manifest cannot be derived',
            );
        }

        $severity = $strictReplay ? self::SEVERITY_P0 : self::SEVERITY_P1;
        $status = $strictReplay ? 'fail' : 'warn';

        return $this->finding(
            id: 'replay_manifest_available',
            status: $status,
            severity: $severity,
            reason: $strictReplay
                ? 'strict_replay_required=true and no replay manifest for this pack'
                : 'no replay manifest for this pack — recommend building one before resume',
            remediation: 'run `php artisan atlas:long-horizon:replay-manifest` for this continuation_pack',
        );
    }

    /**
     * @param  list<string>  $requiredKinds
     * @return array<string,mixed>
     */
    private function checkEvidencePresence(?AtlasLongHorizonContinuationPack $pack, array $requiredKinds): array
    {
        if ($pack === null) {
            return $this->finding(
                id: 'evidence_present_for_completion',
                status: 'fail',
                severity: self::SEVERITY_P0,
                reason: 'no continuation pack — evidence cannot be evaluated',
            );
        }
        $refs = is_array($pack->evidence_refs) ? $pack->evidence_refs : [];
        if ($refs === []) {
            return $this->finding(
                id: 'evidence_present_for_completion',
                status: 'fail',
                severity: self::SEVERITY_P0,
                reason: 'continuation pack has no evidence_refs — completion claim unverifiable',
                remediation: 'attach evidence_refs (artifact, source, command, test) before claiming completion',
            );
        }
        if ($requiredKinds !== []) {
            $kindsPresent = $this->collectKinds($refs);
            $missing = array_values(array_diff($requiredKinds, $kindsPresent));
            if ($missing !== []) {
                return $this->finding(
                    id: 'evidence_present_for_completion',
                    status: 'fail',
                    severity: self::SEVERITY_P0,
                    reason: 'required evidence kinds missing: '.implode(',', $missing),
                    remediation: 'attach evidence refs of kinds: '.implode(',', $missing),
                );
            }
        }

        return $this->finding(
            id: 'evidence_present_for_completion',
            status: 'pass',
            severity: self::SEVERITY_P0,
            reason: 'evidence_refs present in continuation pack',
            evidence_refs: ['evidence_count:'.count($refs)],
        );
    }

    /**
     * @param  array<int,mixed>  $refs
     * @return list<string>
     */
    private function collectKinds(array $refs): array
    {
        $out = [];
        foreach ($refs as $ref) {
            if (is_array($ref)) {
                $kind = $this->stringOrNull($ref['kind'] ?? $ref['type'] ?? null);
                if ($kind !== null) {
                    $out[] = $kind;
                }
            } elseif (is_string($ref) && str_contains($ref, ':')) {
                $out[] = explode(':', $ref, 2)[0];
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  array<int,array<string,mixed>>  $checks
     */
    private function resolveStatus(array $checks): string
    {
        $hasP0Fail = false;
        $hasNonP0Issue = false;
        foreach ($checks as $check) {
            $status = $check['status'] ?? 'pass';
            $severity = $check['severity'] ?? self::SEVERITY_P2;
            if ($status === 'fail' && $severity === self::SEVERITY_P0) {
                $hasP0Fail = true;
            } elseif ($status === 'fail' || $status === 'warn') {
                $hasNonP0Issue = true;
            }
        }
        if ($hasP0Fail) {
            return self::STATUS_BLOCKED;
        }
        if ($hasNonP0Issue) {
            return self::STATUS_PARTIAL;
        }

        return self::STATUS_READY;
    }

    /**
     * @param  array<int,array<string,mixed>>  $checks
     * @return array<string,int>
     */
    private function summarize(array $checks): array
    {
        $summary = ['total' => count($checks), 'pass' => 0, 'warn' => 0, 'fail' => 0, 'p0_blockers' => 0, 'p1_blockers' => 0];
        foreach ($checks as $check) {
            $status = $check['status'] ?? 'pass';
            $severity = $check['severity'] ?? self::SEVERITY_P2;
            if ($status === 'pass') {
                $summary['pass']++;
            } elseif ($status === 'warn') {
                $summary['warn']++;
            } else {
                $summary['fail']++;
                if ($severity === self::SEVERITY_P0) {
                    $summary['p0_blockers']++;
                } elseif ($severity === self::SEVERITY_P1) {
                    $summary['p1_blockers']++;
                }
            }
        }

        return $summary;
    }

    /**
     * @param  array<string,mixed>|null  $recoveryPlan
     */
    private function resolveSafeResumeMode(
        string $status,
        ?LongHorizonContextFreshnessGateResult $freshness,
        ?array $recoveryPlan,
        ?AtlasLongHorizonContinuationPack $pack,
    ): string {
        if ($status === self::STATUS_BLOCKED) {
            return AtlasLongHorizonCanon::SAFE_RESUME_BLOCKED;
        }
        if ($recoveryPlan !== null && isset($recoveryPlan['safe_resume_mode'])) {
            return (string) $recoveryPlan['safe_resume_mode'];
        }
        if ($freshness !== null) {
            return $freshness->recommendedSafeResumeMode;
        }
        $packMode = $pack?->safe_resume_mode;
        if (is_string($packMode) && in_array($packMode, AtlasLongHorizonCanon::ALLOWED_SAFE_RESUME_MODES, true)) {
            return $packMode;
        }

        return AtlasLongHorizonCanon::SAFE_RESUME_EXECUTE;
    }

    private function replayManifestStatus(?AtlasLongHorizonReplayManifest $manifest): string
    {
        if ($manifest === null) {
            return 'missing';
        }

        return (string) ($manifest->replay_status ?? 'unknown');
    }

    /**
     * @param  array<string,mixed>|null  $recoveryPlan
     */
    private function recoveryPlanStatus(?LongHorizonContextFreshnessGateResult $freshness, ?array $recoveryPlan): string
    {
        if ($freshness === null) {
            return 'not_evaluated';
        }
        if ($freshness->status !== 'blocked') {
            return 'not_required';
        }

        return $recoveryPlan === null ? 'missing' : 'available';
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function hashCertification(array $payload): string
    {
        $canonical = $payload;
        unset($canonical['generated_at'], $canonical['certification_hash']);
        $canonical = $this->canonicalize($canonical);

        return 'sha256:'.hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
    }

    private function canonicalize(mixed $value): mixed
    {
        return CanonicalValue::canonicalize($value);
    }

    /**
     * @param  list<string>  $evidence_refs
     * @return array<string,mixed>
     */
    private function finding(
        string $id,
        string $status,
        string $severity,
        string $reason,
        ?string $remediation = null,
        array $evidence_refs = [],
    ): array {
        return [
            'check_id' => $id,
            'status' => $status,
            'severity' => $severity,
            'reason' => $reason,
            'remediation' => $remediation,
            'evidence_refs' => $evidence_refs,
        ];
    }

    /**
     * @param  array<string,mixed>  $finding
     * @param  array<int,array<string,mixed>>  $blockers
     * @param  array<int,array<string,mixed>>  $warnings
     */
    private function collectFinding(array $finding, array &$blockers, array &$warnings): void
    {
        $status = $finding['status'] ?? 'pass';
        if ($status === 'fail') {
            $blockers[] = $finding;
        } elseif ($status === 'warn') {
            $warnings[] = $finding;
        }
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
