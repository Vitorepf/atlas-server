<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Pure, deterministic decider for the consolidated telemetry / evidence /
 * performance contract.
 *
 * This is NOT the runtime telemetry pipeline (that lives in
 * App\Services\Ai\Telemetry\* and is bound to Eloquent traces, jobs and
 * summaries). This service answers the contract questions the doc states as
 * "Invariantes" — over plain typed arrays, with no database, no models and no
 * side effects — so the rules can be pinned and reused independently of the
 * pipeline.
 *
 * Rules implemented (mapped to the doc "Invariantes" / "aggregator_version" /
 * "Reports E Inbox" sections):
 *
 *   1. Cost classification for an `ai_traces` row. A trace projected from the
 *      Evidence Ledger (schema_version = atlas.ledger_projection.metadata.v1,
 *      projection_id = ai_traces) WITHOUT a provider/model identity is NOT a
 *      provider execution. It must report cost_confidence=estimated,
 *      cost_source=provider_not_applicable, cost_mode=not_applicable. A real
 *      CLI/provider trace with no active rate is cost_confidence=unknown and
 *      actionable as missing_active_cost_rate.
 *
 *   2. Missing-cost-rate report inclusion. The report must EXCLUDE a
 *      projection-without-provider trace "mesmo quando houver summary antigo
 *      ainda nao recomputado" — i.e. a stale recomputed summary does not put it
 *      back on the report. A real provider trace with no active rate IS included.
 *
 *   3. aggregator_version comparability gate. Trend / anomaly / comparison
 *      claims may NOT cross a different aggregator_version "sem reprocessamento
 *      ou nota explicita". Mixed versions in a window suppress trends unless an
 *      explicit reprocess/note is supplied.
 *
 *   4. Health gate severity floor. "Baixa amostra gera `watch`, nao falso
 *      `critical`." When the window sample is below the minimum, a would-be
 *      critical/warning is clamped down to `watch`.
 *
 *   5. Notification receipt authority. The
 *      atlas.telemetry_health.notification_receipt.v1 "nunca autoriza provider
 *      call, runtime execution, policy patch ou escrita de memoria". Every such
 *      authority is denied; the receipt is replay/audit only.
 *
 *   6. Estimated cost stays estimated. "Custo estimado deve permanecer marcado
 *      como estimado; o Atlas nao converte estimativa operacional em cobranca
 *      real." A request to bill/charge an estimated cost is refused.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-telemetry-evidence-performance.md
 */
final class AtlasAiTelemetryEvidencePerformanceService
{
    /** Stable schema id this service stamps on every verdict. */
    public const RECEIPT_SCHEMA = 'atlas.telemetry_evidence_performance.contract.v1';

    /** Notification receipt schema named in "Reports E Inbox". */
    public const NOTIFICATION_RECEIPT_SCHEMA = 'atlas.telemetry_health.notification_receipt.v1';

    /** Ledger-projection marker for an `ai_traces` row. */
    public const PROJECTION_SCHEMA_VERSION = 'atlas.ledger_projection.metadata.v1';
    public const PROJECTION_ID = 'ai_traces';

    /** cost_confidence vocabulary (matches the runtime estimator). */
    public const COST_CONFIDENCE_METERED = 'metered';
    public const COST_CONFIDENCE_ESTIMATED = 'estimated';
    public const COST_CONFIDENCE_UNKNOWN = 'unknown';

    /** cost_source / cost_mode values for a non-provider projection. */
    public const COST_SOURCE_NOT_APPLICABLE = 'provider_not_applicable';
    public const COST_MODE_NOT_APPLICABLE = 'not_applicable';

    /** Health gate statuses, ordered by severity (low -> high). */
    public const STATUS_OK = 'ok';
    public const STATUS_WATCH = 'watch';
    public const STATUS_WARNING = 'warning';
    public const STATUS_CRITICAL = 'critical';

    /** Default minimum traces before warning/critical may be asserted. */
    public const DEFAULT_MIN_SAMPLE = 10;

    /** Severity rank used to clamp the health status. */
    private const STATUS_RANK = [
        self::STATUS_OK => 0,
        self::STATUS_WATCH => 1,
        self::STATUS_WARNING => 2,
        self::STATUS_CRITICAL => 3,
    ];

    /**
     * Authorities a notification receipt may be asked for — all denied.
     *
     * @var list<string>
     */
    private const RECEIPT_DENIED_AUTHORITIES = [
        'provider_call',
        'runtime_execution',
        'policy_patch',
        'memory_write',
    ];

    /**
     * Classify the cost attribution of a single trace.
     *
     * @param  array<string,mixed>  $trace
     *         provider          : ?string — provider identity (empty/absent = none).
     *         model             : ?string — model identity (empty/absent = none).
     *         schema_version    : ?string — set to PROJECTION_SCHEMA_VERSION for a ledger projection.
     *         projection_id     : ?string — set to PROJECTION_ID for a ledger projection.
     *         has_active_rate   : bool    — does an active cost rate exist for this provider/model?
     *
     * @return array{schema:string,cost_confidence:string,cost_source:string,cost_mode:string,provider_execution:bool,actionable:bool,action:?string,reason:string}
     */
    public function classifyTraceCost(array $trace): array
    {
        $hasProviderIdentity = $this->hasProviderIdentity($trace);

        // Invariante: a ledger projection without provider/model is NOT a
        // provider execution. It is "not applicable" cost, always estimated.
        if ($this->isLedgerProjection($trace) && ! $hasProviderIdentity) {
            return [
                'schema' => self::RECEIPT_SCHEMA,
                'cost_confidence' => self::COST_CONFIDENCE_ESTIMATED,
                'cost_source' => self::COST_SOURCE_NOT_APPLICABLE,
                'cost_mode' => self::COST_MODE_NOT_APPLICABLE,
                'provider_execution' => false,
                'actionable' => false,
                'action' => null,
                'reason' => 'ledger_projection_without_provider',
            ];
        }

        // Any trace with no provider identity at all (and not a projection) has
        // no provider to price; cost is genuinely unknown but not actionable as a
        // missing rate, because there is no provider/model to fill a rate for.
        if (! $hasProviderIdentity) {
            return [
                'schema' => self::RECEIPT_SCHEMA,
                'cost_confidence' => self::COST_CONFIDENCE_UNKNOWN,
                'cost_source' => self::COST_SOURCE_NOT_APPLICABLE,
                'cost_mode' => 'unknown',
                'provider_execution' => false,
                'actionable' => false,
                'action' => null,
                'reason' => 'no_provider_identity',
            ];
        }

        // Real provider/model present. If a rate is active, cost is metered and
        // not actionable. If not, cost is unknown AND actionable: the operator
        // must fill the current rate; the Atlas must not infer prices.
        $hasActiveRate = (bool) ($trace['has_active_rate'] ?? false);
        if ($hasActiveRate) {
            return [
                'schema' => self::RECEIPT_SCHEMA,
                'cost_confidence' => self::COST_CONFIDENCE_METERED,
                'cost_source' => 'active_cost_rate',
                'cost_mode' => 'metered',
                'provider_execution' => true,
                'actionable' => false,
                'action' => null,
                'reason' => 'metered_by_active_rate',
            ];
        }

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'cost_confidence' => self::COST_CONFIDENCE_UNKNOWN,
            'cost_source' => 'no_active_cost_rate',
            'cost_mode' => 'unknown',
            'provider_execution' => true,
            'actionable' => true,
            'action' => 'missing_active_cost_rate',
            'reason' => 'provider_without_active_rate',
        ];
    }

    /**
     * Decide whether a trace belongs on the missing-cost-rates report.
     *
     * Invariante: a projection-without-provider trace is EXCLUDED even when a
     * stale (not-yet-recomputed) summary still exists for it. Only a real
     * provider/model trace with no active rate is included.
     *
     * @param  array<string,mixed>  $trace  same shape as classifyTraceCost().
     *         May also carry has_stale_summary : bool (ignored for projections).
     *
     * @return array{schema:string,include:bool,reason:string,action:?string}
     */
    public function includeInMissingCostReport(array $trace): array
    {
        $classification = $this->classifyTraceCost($trace);

        // Projection-without-provider: never on the report, regardless of a
        // lingering stale summary.
        if ($classification['reason'] === 'ledger_projection_without_provider') {
            return [
                'schema' => self::RECEIPT_SCHEMA,
                'include' => false,
                'reason' => 'excluded_projection_not_applicable',
                'action' => null,
            ];
        }

        if ($classification['actionable'] === true && $classification['action'] === 'missing_active_cost_rate') {
            return [
                'schema' => self::RECEIPT_SCHEMA,
                'include' => true,
                'reason' => 'real_provider_missing_active_rate',
                'action' => 'missing_active_cost_rate',
            ];
        }

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'include' => false,
            'reason' => 'not_actionable',
            'action' => null,
        ];
    }

    /**
     * Gate a trend/anomaly/comparison claim against aggregator_version.
     *
     * Invariante: trend / anomaly / comparison claims may not cross a different
     * aggregator_version without reprocessing or an explicit note. If the
     * window mixes versions, the claim is suppressed unless the caller supplies
     * an explicit reprocess flag or note.
     *
     * @param  list<string|int>  $versions  aggregator_versions present in the window.
     * @param  array<string,mixed>  $options
     *         reprocessed     : bool   — window was reprocessed to one version.
     *         explicit_note   : ?string — operator note acknowledging the mix.
     *
     * @return array{schema:string,allow_trend:bool,mixed_versions:bool,distinct_versions:list<string>,reason:string}
     */
    public function gateAggregatorComparability(array $versions, array $options = []): array
    {
        $distinct = array_values(array_unique(array_map(
            static fn ($v): string => (string) $v,
            array_filter($versions, static fn ($v): bool => $v !== null && $v !== '')
        )));

        $mixed = count($distinct) > 1;

        if (! $mixed) {
            return [
                'schema' => self::RECEIPT_SCHEMA,
                'allow_trend' => true,
                'mixed_versions' => false,
                'distinct_versions' => $distinct,
                'reason' => count($distinct) === 1 ? 'single_version' : 'no_versions',
            ];
        }

        $reprocessed = (bool) ($options['reprocessed'] ?? false);
        $note = $options['explicit_note'] ?? null;
        $hasNote = is_string($note) && trim($note) !== '';

        if ($reprocessed) {
            return [
                'schema' => self::RECEIPT_SCHEMA,
                'allow_trend' => true,
                'mixed_versions' => true,
                'distinct_versions' => $distinct,
                'reason' => 'reprocessed_to_single_version',
            ];
        }

        if ($hasNote) {
            return [
                'schema' => self::RECEIPT_SCHEMA,
                'allow_trend' => true,
                'mixed_versions' => true,
                'distinct_versions' => $distinct,
                'reason' => 'explicit_note_acknowledged',
            ];
        }

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'allow_trend' => false,
            'mixed_versions' => true,
            'distinct_versions' => $distinct,
            'reason' => 'mixed_aggregator_versions',
        ];
    }

    /**
     * Resolve a health gate status under the low-sample floor.
     *
     * Invariante: "Baixa amostra gera `watch`, nao falso `critical`." When the
     * window has fewer than the minimum sample, any proposed warning/critical is
     * clamped down to `watch`; ok stays ok.
     *
     * @param  string  $proposedStatus  one of ok|watch|warning|critical.
     * @param  int  $sampleSize         number of traces in the window.
     * @param  int  $minSample          minimum sample to assert warning/critical.
     *
     * @return array{schema:string,status:string,proposed_status:string,clamped:bool,sample_size:int,min_sample:int,reason:string}
     */
    public function resolveHealthStatus(string $proposedStatus, int $sampleSize, int $minSample = self::DEFAULT_MIN_SAMPLE): array
    {
        $proposed = $this->normalizeStatus($proposedStatus);
        $lowSample = $sampleSize < $minSample;

        // Only clamp when the proposed status is more severe than `watch`.
        if ($lowSample && self::STATUS_RANK[$proposed] > self::STATUS_RANK[self::STATUS_WATCH]) {
            return [
                'schema' => self::RECEIPT_SCHEMA,
                'status' => self::STATUS_WATCH,
                'proposed_status' => $proposed,
                'clamped' => true,
                'sample_size' => $sampleSize,
                'min_sample' => $minSample,
                'reason' => 'low_sample_clamped_to_watch',
            ];
        }

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'status' => $proposed,
            'proposed_status' => $proposed,
            'clamped' => false,
            'sample_size' => $sampleSize,
            'min_sample' => $minSample,
            'reason' => $lowSample ? 'low_sample_below_watch' : 'sufficient_sample',
        ];
    }

    /**
     * Decide what a telemetry-health notification receipt authorizes.
     *
     * Invariante: the receipt "nunca autoriza provider call, runtime execution,
     * policy patch ou escrita de memoria; ele existe para replay/auditoria do
     * alerta proativo." Every authority request is denied.
     *
     * @param  string  $requestedAuthority  e.g. provider_call|runtime_execution|policy_patch|memory_write|replay
     *
     * @return array{schema:string,authorized:bool,requested:string,reason:string,replay_only:bool}
     */
    public function receiptAuthorizes(string $requestedAuthority): array
    {
        $requested = strtolower(trim($requestedAuthority));

        if (in_array($requested, self::RECEIPT_DENIED_AUTHORITIES, true)) {
            return [
                'schema' => self::NOTIFICATION_RECEIPT_SCHEMA,
                'authorized' => false,
                'requested' => $requested,
                'reason' => 'receipt_is_replay_audit_only',
                'replay_only' => true,
            ];
        }

        // Replay / audit reads are the receipt's only purpose; anything else is
        // likewise not an authorization to act.
        if ($requested === 'replay' || $requested === 'audit') {
            return [
                'schema' => self::NOTIFICATION_RECEIPT_SCHEMA,
                'authorized' => true,
                'requested' => $requested,
                'reason' => 'replay_audit_is_the_only_purpose',
                'replay_only' => true,
            ];
        }

        return [
            'schema' => self::NOTIFICATION_RECEIPT_SCHEMA,
            'authorized' => false,
            'requested' => $requested,
            'reason' => 'unknown_authority_denied',
            'replay_only' => true,
        ];
    }

    /**
     * Gate a request to bill/charge an estimated cost.
     *
     * Invariante: "Custo estimado deve permanecer marcado como estimado; o Atlas
     * nao converte estimativa operacional em cobranca real." Only a metered cost
     * may be turned into a real charge; estimated/unknown costs are refused.
     *
     * @param  string  $costConfidence  metered|estimated|unknown
     *
     * @return array{schema:string,may_charge:bool,cost_confidence:string,reason:string}
     */
    public function assertChargeable(string $costConfidence): array
    {
        $confidence = strtolower(trim($costConfidence));

        if ($confidence === self::COST_CONFIDENCE_METERED) {
            return [
                'schema' => self::RECEIPT_SCHEMA,
                'may_charge' => true,
                'cost_confidence' => $confidence,
                'reason' => 'metered_cost_is_real',
            ];
        }

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'may_charge' => false,
            'cost_confidence' => $confidence === '' ? self::COST_CONFIDENCE_UNKNOWN : $confidence,
            'reason' => 'estimate_stays_estimate',
        ];
    }

    /**
     * @param  array<string,mixed>  $trace
     */
    private function isLedgerProjection(array $trace): bool
    {
        $schemaVersion = $this->stringOrNull($trace['schema_version'] ?? null);
        $projectionId = $this->stringOrNull($trace['projection_id'] ?? null);

        return $schemaVersion === self::PROJECTION_SCHEMA_VERSION
            && $projectionId === self::PROJECTION_ID;
    }

    /**
     * @param  array<string,mixed>  $trace
     */
    private function hasProviderIdentity(array $trace): bool
    {
        return $this->stringOrNull($trace['provider'] ?? null) !== null
            || $this->stringOrNull($trace['model'] ?? null) !== null;
    }

    private function normalizeStatus(string $status): string
    {
        $normalized = strtolower(trim($status));

        return array_key_exists($normalized, self::STATUS_RANK) ? $normalized : self::STATUS_OK;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
