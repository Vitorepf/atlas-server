<?php

declare(strict_types=1);

namespace App\Services\Ai\LongHorizon\Gate;

use App\Models\AtlasLongHorizonContinuationPack;
use App\Services\Ai\LongHorizon\AtlasLongHorizonCanon;
use App\Services\Ai\Programming\AtlasDev\Gate\MandatoryRagGate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * TEOS-I1 M6: Long-Horizon Context Freshness Gate.
 *
 * This gate sits one layer ABOVE {@see MandatoryRagGate}
 * and is responsible for a different question:
 *
 *   - Mandatory RAG Gate asks: "do we have enough context for THIS run?"
 *   - Freshness Gate asks:     "is the long-horizon continuation context
 *                               still TRUE / FRESH / SAFE enough to resume?"
 *
 * The two gates compose: a run starts with RAG gate (single-run scope) and
 * a resume / Obra advance starts with Freshness gate (multi-session scope).
 * Neither replaces the other.
 *
 * The gate is intentionally PURE: no provider call, no benchmark, no UX,
 * no Recovery Planner. It reads a `atlas.long_horizon.continuation_pack.v2`
 * row (or payload) + optional compaction receipt + optional context
 * manifest items and emits a deterministic
 * {@see LongHorizonContextFreshnessGateResult}.
 *
 * Inputs (see {@see evaluate()}):
 *  - `scope_type`              (required, must be in canon).
 *  - `scope_id`                (optional).
 *  - `continuation_pack_id`    (optional uuid; resolves the model).
 *  - `continuation_pack_payload` (optional inline; mirrors the model fields).
 *  - `context_manifest`        (list of refs with stale_after / valid_until /
 *    verified_at / source_hash_expected / source_hash_actual / required /
 *    superseded_by / kind).
 *  - `compaction_receipt_payload` (optional inline with `must_keep_coverage`
 *    and `unresolved_loss`).
 *  - `required_evidence_kinds` (list<string> — evidence kinds that must
 *    appear in the pack's `evidence_refs` for the `execute` intent).
 *  - `intended_mode`           (default `execute`; one of the canonical
 *    {@see AtlasLongHorizonCanon::ALLOWED_SAFE_RESUME_MODES}).
 *  - `strict`                  (bool; default false; when true, any warn
 *    is escalated to blocked).
 *  - `now`                     (optional Carbon, for testing).
 *
 * Output: {@see LongHorizonContextFreshnessGateResult}.
 */
final class LongHorizonContextFreshnessGate
{
    public const DEFAULT_INTENDED_MODE = AtlasLongHorizonCanon::SAFE_RESUME_EXECUTE;

    public const REMEDIATION_RUN_RECOVERY_PLANNER = 'run_recovery_planner';

    public const REMEDIATION_REHYDRATE_STALE_REFS = 'rehydrate_stale_refs_before_execute';

    public const REMEDIATION_ATTACH_REQUIRED_REFS = 'attach_required_refs_to_continuation_pack';

    public const REMEDIATION_RESOLVE_SUPERSEDED_DECISIONS = 'resolve_superseded_decisions';

    public const REMEDIATION_REPLAY_COMPACTION_RECOVERY_QUERIES = 'replay_compaction_recovery_queries';

    public const REMEDIATION_REQUEST_OPERATOR_DECISION = 'request_operator_decision';

    public const REMEDIATION_DOWNGRADE_TO_READ_ONLY = 'downgrade_intended_mode_to_read_only';

    /**
     * @param  array<string,mixed>  $input
     */
    public function evaluate(array $input): LongHorizonContextFreshnessGateResult
    {
        $scopeType = $this->requireScopeType($input);
        $scopeId = $this->stringOrNull($input['scope_id'] ?? null);
        $intendedMode = (string) ($input['intended_mode'] ?? self::DEFAULT_INTENDED_MODE);
        if (! in_array($intendedMode, AtlasLongHorizonCanon::ALLOWED_SAFE_RESUME_MODES, true)) {
            throw new InvalidArgumentException(
                'LongHorizonContextFreshnessGate intended_mode must be one of ['
                .implode(',', AtlasLongHorizonCanon::ALLOWED_SAFE_RESUME_MODES)."]; got '{$intendedMode}'."
            );
        }
        $strict = (bool) ($input['strict'] ?? false);
        $now = $input['now'] instanceof Carbon ? $input['now'] : Carbon::now();
        $evaluatedAt = $now->toIso8601String();

        $blockingReasons = [];
        $warnings = [];
        $remediation = [];

        $pack = $this->resolvePack($input);
        if ($pack === null) {
            return $this->emit(
                scopeType: $scopeType,
                scopeId: $scopeId,
                intendedMode: $intendedMode,
                strict: $strict,
                status: LongHorizonContextFreshnessGateResult::STATUS_BLOCKED,
                blockingReasons: [LongHorizonContextFreshnessGateResult::REASON_CONTINUATION_PACK_MISSING],
                warnings: [],
                staleRefs: [],
                supersededDecisions: [],
                expiredMemoryRefs: [],
                sourceHashMismatches: [],
                missingRequiredRefs: [],
                compactionLoss: null,
                mustKeepCoverage: null,
                remediation: [self::REMEDIATION_RUN_RECOVERY_PLANNER, self::REMEDIATION_REQUEST_OPERATOR_DECISION],
                evaluatedAt: $evaluatedAt,
            );
        }

        // Pack-scope cross-check: caller's scope must match pack's scope.
        if ((string) $pack['scope_type'] !== $scopeType) {
            $blockingReasons[] = LongHorizonContextFreshnessGateResult::REASON_SCOPE_MISMATCH;
            $remediation[] = self::REMEDIATION_REQUEST_OPERATOR_DECISION;
        }
        if ($scopeId !== null && $pack['scope_id'] !== null && (string) $pack['scope_id'] !== $scopeId) {
            $blockingReasons[] = LongHorizonContextFreshnessGateResult::REASON_SCOPE_MISMATCH;
            $remediation[] = self::REMEDIATION_REQUEST_OPERATOR_DECISION;
        }

        // Pack-level stale_after.
        $packStaleAfter = $this->parseTime($pack['stale_after'] ?? null);
        if ($packStaleAfter !== null && $packStaleAfter->lessThan($now)) {
            $blockingReasons[] = LongHorizonContextFreshnessGateResult::REASON_PACK_STALE_AFTER_EXPIRED;
            $remediation[] = self::REMEDIATION_RUN_RECOVERY_PLANNER;
        }

        // Per-ref freshness from caller-supplied manifest + pack's manifest.
        $staleRefs = [];
        $sourceHashMismatches = [];
        $expiredMemoryRefs = [];
        $missingRequiredRefs = [];

        $manifest = $this->mergeManifests(
            callerManifest: (array) ($input['context_manifest'] ?? []),
            packManifest: (array) ($pack['context_manifest'] ?? []),
        );
        foreach ($manifest as $ref) {
            if (! is_array($ref)) {
                continue;
            }
            $refId = (string) ($ref['ref'] ?? '');
            $required = (bool) ($ref['required'] ?? false);

            // Required + missing flag set by caller.
            if ($required && (bool) ($ref['missing'] ?? false)) {
                $missingRequiredRefs[] = $refId !== '' ? $refId : 'unnamed_required_ref';

                continue;
            }

            // Source-hash drift.
            $expectedHash = $this->stringOrNull($ref['source_hash_expected'] ?? null);
            $actualHash = $this->stringOrNull($ref['source_hash_actual'] ?? null);
            if ($expectedHash !== null && $actualHash !== null && $expectedHash !== $actualHash) {
                $sourceHashMismatches[] = [
                    'ref' => $refId,
                    'kind' => (string) ($ref['kind'] ?? 'unknown'),
                    'expected' => $expectedHash,
                    'actual' => $actualHash,
                ];

                continue;
            }

            // valid_until / stale_after on the ref.
            $validUntil = $this->parseTime($ref['valid_until'] ?? null);
            $staleAfter = $this->parseTime($ref['stale_after'] ?? null);
            if ($validUntil !== null && $validUntil->lessThan($now)) {
                $entry = $this->staleEntry($refId, $ref, 'valid_until_passed');
                if (((string) ($ref['kind'] ?? '')) === 'memory') {
                    $expiredMemoryRefs[] = $entry;
                } else {
                    $staleRefs[] = $entry;
                }

                continue;
            }
            if ($staleAfter !== null && $staleAfter->lessThan($now)) {
                $entry = $this->staleEntry($refId, $ref, 'stale_after_passed');
                if (((string) ($ref['kind'] ?? '')) === 'memory') {
                    $expiredMemoryRefs[] = $entry;
                } else {
                    $staleRefs[] = $entry;
                }
            }
        }

        // Required evidence kinds (caller-declared) absent from pack.evidence_refs.
        $requiredEvidenceKinds = $this->normaliseStringList(
            (array) ($input['required_evidence_kinds'] ?? []),
        );
        $presentEvidenceKinds = $this->collectEvidenceKinds((array) ($pack['evidence_refs'] ?? []));
        $missingCriticalEvidence = [];
        foreach ($requiredEvidenceKinds as $kind) {
            if (! in_array($kind, $presentEvidenceKinds, true)) {
                $missingCriticalEvidence[] = 'evidence_kind:'.$kind;
            }
        }
        if ($missingCriticalEvidence !== []) {
            $missingRequiredRefs = array_values(array_unique(array_merge(
                $missingRequiredRefs,
                $missingCriticalEvidence,
            )));
        }

        // Superseded decisions inside the pack.
        $supersededDecisions = $this->collectSupersededDecisions(
            (array) ($pack['decisions'] ?? []),
            (array) ($pack['superseded_decisions'] ?? []),
        );

        // Compaction loss (latest receipt for scope, if supplied).
        $compactionLoss = null;
        $mustKeepCoverage = null;
        $receiptPayload = $input['compaction_receipt_payload'] ?? null;
        if (is_array($receiptPayload)) {
            $mustKeepCoverage = isset($receiptPayload['must_keep_coverage'])
                ? (float) $receiptPayload['must_keep_coverage']
                : null;
            $unresolvedLoss = array_values((array) ($receiptPayload['unresolved_loss'] ?? []));

            if ($mustKeepCoverage !== null && $mustKeepCoverage < 1.0) {
                $blockingReasons[] = LongHorizonContextFreshnessGateResult::REASON_COMPACTION_COVERAGE_BELOW_ONE;
                $remediation[] = self::REMEDIATION_REPLAY_COMPACTION_RECOVERY_QUERIES;
            }
            if ($unresolvedLoss !== []) {
                $blockingReasons[] = LongHorizonContextFreshnessGateResult::REASON_COMPACTION_UNRESOLVED_LOSS;
                $remediation[] = self::REMEDIATION_REPLAY_COMPACTION_RECOVERY_QUERIES;
            }
            $compactionLoss = [
                'must_keep_coverage' => $mustKeepCoverage,
                'unresolved_loss' => $unresolvedLoss,
                'loss_risk' => (string) ($receiptPayload['loss_risk'] ?? AtlasLongHorizonCanon::LOSS_RISK_LOW),
            ];
        }

        // Aggregate blockers / warnings.
        if ($missingRequiredRefs !== []) {
            $blockingReasons[] = LongHorizonContextFreshnessGateResult::REASON_MISSING_REQUIRED_REFS;
            $remediation[] = self::REMEDIATION_ATTACH_REQUIRED_REFS;
            if ($missingCriticalEvidence !== []) {
                $blockingReasons[] = LongHorizonContextFreshnessGateResult::REASON_MISSING_CRITICAL_EVIDENCE;
            }
        }
        if ($sourceHashMismatches !== []) {
            $blockingReasons[] = LongHorizonContextFreshnessGateResult::REASON_SOURCE_HASH_MISMATCH;
            $remediation[] = self::REMEDIATION_REHYDRATE_STALE_REFS;
        }
        if ($staleRefs !== []) {
            $warnings[] = LongHorizonContextFreshnessGateResult::REASON_STALE_REFS;
            $remediation[] = self::REMEDIATION_REHYDRATE_STALE_REFS;
        }
        if ($expiredMemoryRefs !== []) {
            $warnings[] = LongHorizonContextFreshnessGateResult::REASON_EXPIRED_MEMORY;
            $remediation[] = self::REMEDIATION_REHYDRATE_STALE_REFS;
        }
        if ($supersededDecisions !== []) {
            $warnings[] = LongHorizonContextFreshnessGateResult::REASON_SUPERSEDED_DECISIONS;
            $remediation[] = self::REMEDIATION_RESOLVE_SUPERSEDED_DECISIONS;
        }

        $blockingReasons = array_values(array_unique($blockingReasons));
        $warnings = array_values(array_unique($warnings));

        // Status derivation.
        $status = $blockingReasons !== []
            ? LongHorizonContextFreshnessGateResult::STATUS_BLOCKED
            : ($warnings !== []
                ? LongHorizonContextFreshnessGateResult::STATUS_WARN
                : LongHorizonContextFreshnessGateResult::STATUS_PASS);

        if ($strict && $status === LongHorizonContextFreshnessGateResult::STATUS_WARN) {
            $blockingReasons[] = LongHorizonContextFreshnessGateResult::REASON_STRICT_MODE_ESCALATED_WARN;
            $status = LongHorizonContextFreshnessGateResult::STATUS_BLOCKED;
        }

        return $this->emit(
            scopeType: $scopeType,
            scopeId: $scopeId,
            intendedMode: $intendedMode,
            strict: $strict,
            status: $status,
            blockingReasons: $blockingReasons,
            warnings: $warnings,
            staleRefs: $staleRefs,
            supersededDecisions: $supersededDecisions,
            expiredMemoryRefs: $expiredMemoryRefs,
            sourceHashMismatches: $sourceHashMismatches,
            missingRequiredRefs: $missingRequiredRefs,
            compactionLoss: $compactionLoss,
            mustKeepCoverage: $mustKeepCoverage,
            remediation: array_values(array_unique($remediation)),
            evaluatedAt: $evaluatedAt,
        );
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function requireScopeType(array $input): string
    {
        if (! isset($input['scope_type']) || ! is_string($input['scope_type'])) {
            throw new InvalidArgumentException('LongHorizonContextFreshnessGate scope_type must be a string.');
        }
        $scope = trim($input['scope_type']);
        if (! in_array($scope, AtlasLongHorizonCanon::ALLOWED_SCOPE_TYPES, true)) {
            throw new InvalidArgumentException(
                'LongHorizonContextFreshnessGate scope_type must be one of ['
                .implode(',', AtlasLongHorizonCanon::ALLOWED_SCOPE_TYPES)."]; got '{$scope}'."
            );
        }

        return $scope;
    }

    /**
     * Resolve the continuation pack from inline payload or from DB by id/uuid.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>|null normalised pack array, or null when neither was supplied.
     */
    private function resolvePack(array $input): ?array
    {
        if (isset($input['continuation_pack_payload']) && is_array($input['continuation_pack_payload'])) {
            return $this->normalisePackArray($input['continuation_pack_payload']);
        }

        $id = $this->stringOrNull($input['continuation_pack_id'] ?? null);
        if ($id === null) {
            return null;
        }

        $query = AtlasLongHorizonContinuationPack::query()->where('uuid', $id);
        if (Str::isUuid($id)) {
            $query->orWhere('id', $id);
        }

        $row = $query->first();
        if ($row === null) {
            return null;
        }

        return $this->normalisePackArray($row->toArray());
    }

    /**
     * @param  array<string,mixed>  $pack
     * @return array<string,mixed>
     */
    private function normalisePackArray(array $pack): array
    {
        return [
            'scope_type' => (string) ($pack['scope_type'] ?? ''),
            'scope_id' => isset($pack['scope_id']) ? (string) $pack['scope_id'] : null,
            'stale_after' => $pack['stale_after'] ?? null,
            'context_manifest' => (array) ($pack['context_manifest'] ?? []),
            'evidence_refs' => (array) ($pack['evidence_refs'] ?? []),
            'decisions' => (array) ($pack['decisions'] ?? []),
            'superseded_decisions' => (array) ($pack['superseded_decisions'] ?? []),
            'pack_hash' => isset($pack['pack_hash']) ? (string) $pack['pack_hash'] : null,
        ];
    }

    /**
     * @param  array<int,mixed>  $callerManifest
     * @param  array<int,mixed>  $packManifest
     * @return list<array<string,mixed>>
     */
    private function mergeManifests(array $callerManifest, array $packManifest): array
    {
        $out = [];
        $seen = [];
        foreach ([$callerManifest, $packManifest] as $source) {
            foreach ($source as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $key = (string) ($entry['ref'] ?? spl_object_hash((object) $entry));
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $ref
     * @return array<string,mixed>
     */
    private function staleEntry(string $refId, array $ref, string $reason): array
    {
        return [
            'ref' => $refId !== '' ? $refId : 'unnamed_ref',
            'kind' => (string) ($ref['kind'] ?? 'unknown'),
            'reason' => $reason,
            'stale_after' => $ref['stale_after'] ?? null,
            'valid_until' => $ref['valid_until'] ?? null,
            'verified_at' => $ref['verified_at'] ?? null,
            'source_hash' => $ref['source_hash_actual'] ?? $ref['source_hash_expected'] ?? null,
        ];
    }

    /**
     * @param  array<int,mixed>  $decisions
     * @param  array<int,mixed>  $supersededList
     * @return list<array<string,mixed>>
     */
    private function collectSupersededDecisions(array $decisions, array $supersededList): array
    {
        $out = [];

        foreach ($decisions as $d) {
            if (! is_array($d)) {
                continue;
            }
            $superseder = $this->stringOrNull($d['superseded_by'] ?? null);
            if ($superseder !== null) {
                $out[] = [
                    'decision_id' => (string) ($d['decision_id'] ?? $d['id'] ?? 'unnamed'),
                    'superseded_by' => $superseder,
                    'text' => isset($d['text']) && is_string($d['text']) ? $d['text'] : null,
                ];
            }
        }
        foreach ($supersededList as $d) {
            if (! is_array($d)) {
                continue;
            }
            $out[] = [
                'decision_id' => (string) ($d['decision_id'] ?? $d['id'] ?? 'unnamed'),
                'superseded_by' => $this->stringOrNull($d['superseded_by'] ?? null),
                'text' => isset($d['text']) && is_string($d['text']) ? $d['text'] : null,
            ];
        }

        // Dedup by decision_id.
        $byId = [];
        foreach ($out as $entry) {
            $byId[(string) $entry['decision_id']] = $entry;
        }

        return array_values($byId);
    }

    /**
     * @param  array<int,mixed>  $evidenceRefs
     * @return list<string>
     */
    private function collectEvidenceKinds(array $evidenceRefs): array
    {
        $kinds = [];
        foreach ($evidenceRefs as $ref) {
            if (is_array($ref) && isset($ref['kind']) && is_string($ref['kind']) && $ref['kind'] !== '') {
                $kinds[] = $ref['kind'];
            } elseif (is_string($ref) && str_contains($ref, ':')) {
                [$kind] = explode(':', $ref, 2);
                if ($kind !== '') {
                    $kinds[] = $kind;
                }
            }
        }

        return array_values(array_unique($kinds));
    }

    /**
     * @param  array<int,mixed>  $list
     * @return list<string>
     */
    private function normaliseStringList(array $list): array
    {
        $out = [];
        foreach ($list as $v) {
            if (is_string($v) && trim($v) !== '') {
                $out[] = trim($v);
            }
        }

        return array_values(array_unique($out));
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function parseTime(mixed $value): ?Carbon
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof Carbon) {
            return $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }
        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * Compose the final result with deterministic mode + write_allowed +
     * freshness_hash.
     *
     * @param  list<string>  $blockingReasons
     * @param  list<string>  $warnings
     * @param  list<array<string,mixed>>  $staleRefs
     * @param  list<array<string,mixed>>  $supersededDecisions
     * @param  list<array<string,mixed>>  $expiredMemoryRefs
     * @param  list<array<string,mixed>>  $sourceHashMismatches
     * @param  list<string>  $missingRequiredRefs
     * @param  array<string,mixed>|null  $compactionLoss
     * @param  list<string>  $remediation
     */
    private function emit(
        string $scopeType,
        ?string $scopeId,
        string $intendedMode,
        bool $strict,
        string $status,
        array $blockingReasons,
        array $warnings,
        array $staleRefs,
        array $supersededDecisions,
        array $expiredMemoryRefs,
        array $sourceHashMismatches,
        array $missingRequiredRefs,
        ?array $compactionLoss,
        ?float $mustKeepCoverage,
        array $remediation,
        string $evaluatedAt,
    ): LongHorizonContextFreshnessGateResult {
        [$recommendedMode, $writeAllowed, $modeRemediation] = $this->deriveRecommendedMode(
            status: $status,
            intendedMode: $intendedMode,
            blockingReasons: $blockingReasons,
        );
        $remediation = array_values(array_unique(array_merge($remediation, $modeRemediation)));

        $base = [
            'blocking_reasons' => array_values($blockingReasons),
            'compaction_loss' => $compactionLoss,
            'expired_memory_refs' => array_values($expiredMemoryRefs),
            'intended_mode' => $intendedMode,
            'missing_required_refs' => array_values($missingRequiredRefs),
            'must_keep_coverage' => $mustKeepCoverage,
            'recommended_safe_resume_mode' => $recommendedMode,
            'remediation' => array_values($remediation),
            'schema_version' => LongHorizonContextFreshnessGateResult::SCHEMA_VERSION,
            'scope_id' => $scopeId,
            'scope_type' => $scopeType,
            'source_hash_mismatches' => array_values($sourceHashMismatches),
            'stale_refs' => array_values($staleRefs),
            'status' => $status,
            'strict' => $strict,
            'superseded_decisions' => array_values($supersededDecisions),
            'warnings' => array_values($warnings),
            'write_allowed' => $writeAllowed,
        ];
        $freshnessHash = LongHorizonContextFreshnessGateResult::canonicalFreshnessHash($base);

        return new LongHorizonContextFreshnessGateResult(
            scopeType: $scopeType,
            scopeId: $scopeId,
            intendedMode: $intendedMode,
            strict: $strict,
            status: $status,
            blockingReasons: array_values($blockingReasons),
            warnings: array_values($warnings),
            staleRefs: array_values($staleRefs),
            supersededDecisions: array_values($supersededDecisions),
            expiredMemoryRefs: array_values($expiredMemoryRefs),
            sourceHashMismatches: array_values($sourceHashMismatches),
            missingRequiredRefs: array_values($missingRequiredRefs),
            compactionLoss: $compactionLoss,
            mustKeepCoverage: $mustKeepCoverage,
            recommendedSafeResumeMode: $recommendedMode,
            writeAllowed: $writeAllowed,
            remediation: array_values($remediation),
            freshnessHash: $freshnessHash,
            evaluatedAt: $evaluatedAt,
        );
    }

    /**
     * Compute the recommended resume mode + write_allowed flag from the raw
     * status and the caller's intended mode.
     *
     * Rules (per TEOS-I1 brief):
     *  - status=blocked + execute  → blocked (write_allowed=false).
     *  - status=blocked + read_only/review → read_only iff no
     *    missing_critical_evidence / compaction_loss; else blocked.
     *  - status=warn + execute     → blocked (escalates; execute demands clean).
     *  - status=warn + read_only/review → read_only/review (write_allowed=false).
     *  - status=pass + execute     → execute (write_allowed=true).
     *
     * @param  list<string>  $blockingReasons
     * @return array{0:string,1:bool,2:list<string>}
     */
    private function deriveRecommendedMode(string $status, string $intendedMode, array $blockingReasons): array
    {
        $criticalBlockers = [
            LongHorizonContextFreshnessGateResult::REASON_MISSING_CRITICAL_EVIDENCE,
            LongHorizonContextFreshnessGateResult::REASON_COMPACTION_UNRESOLVED_LOSS,
            LongHorizonContextFreshnessGateResult::REASON_COMPACTION_COVERAGE_BELOW_ONE,
            LongHorizonContextFreshnessGateResult::REASON_CONTINUATION_PACK_MISSING,
            LongHorizonContextFreshnessGateResult::REASON_SCOPE_MISMATCH,
            LongHorizonContextFreshnessGateResult::REASON_SOURCE_HASH_MISMATCH,
        ];
        $hasCriticalBlocker = array_intersect($blockingReasons, $criticalBlockers) !== [];

        if ($status === LongHorizonContextFreshnessGateResult::STATUS_PASS) {
            return [$intendedMode, $intendedMode === AtlasLongHorizonCanon::SAFE_RESUME_EXECUTE, []];
        }

        if ($status === LongHorizonContextFreshnessGateResult::STATUS_WARN) {
            if ($intendedMode === AtlasLongHorizonCanon::SAFE_RESUME_EXECUTE) {
                return [
                    AtlasLongHorizonCanon::SAFE_RESUME_READ_ONLY,
                    false,
                    [self::REMEDIATION_DOWNGRADE_TO_READ_ONLY],
                ];
            }

            return [$intendedMode, false, []];
        }

        // status === blocked
        if ($hasCriticalBlocker) {
            return [
                AtlasLongHorizonCanon::SAFE_RESUME_BLOCKED,
                false,
                [self::REMEDIATION_RUN_RECOVERY_PLANNER, self::REMEDIATION_REQUEST_OPERATOR_DECISION],
            ];
        }

        // Non-critical blockers (e.g. only stale_refs escalated by strict): a
        // read_only / review intent can still produce a useful read-only resume.
        if (in_array($intendedMode, [
            AtlasLongHorizonCanon::SAFE_RESUME_READ_ONLY,
            AtlasLongHorizonCanon::SAFE_RESUME_REVIEW,
        ], true)) {
            return [$intendedMode, false, [self::REMEDIATION_RUN_RECOVERY_PLANNER]];
        }

        return [
            AtlasLongHorizonCanon::SAFE_RESUME_BLOCKED,
            false,
            [self::REMEDIATION_RUN_RECOVERY_PLANNER, self::REMEDIATION_DOWNGRADE_TO_READ_ONLY],
        ];
    }
}
