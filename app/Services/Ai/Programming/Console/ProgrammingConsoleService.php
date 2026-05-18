<?php

namespace App\Services\Ai\Programming\Console;

use App\Models\AtlasLongHorizonCompactionReceipt;
use App\Models\AtlasLongHorizonContinuationPack;
use App\Services\Ai\AiCompactionService;
use App\Services\Ai\LongHorizon\AtlasLongHorizonCanon;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\Forge\ForgeIntakeCanon;
use App\Services\Ai\Programming\Forge\ForgeIntakeService;
use App\Services\Ai\Programming\ProgrammingResumeService;
use App\Services\Ai\ProgrammingRuntime\ControlPlane\ProgrammingRuntimeControlPlaneCanon;
use App\Services\Ai\ProgrammingRuntime\ControlPlane\ProgrammingRuntimeControlPlaneService;
use App\Services\Ai\ProgrammingRuntime\ProgrammingRuntimeReadinessCanon;
use App\Services\Ai\ProgrammingRuntime\ProgrammingRuntimeReadinessService;
use App\Services\Ai\ProgrammingRuntime\Telemetry\ProgrammingRuntimeTelemetryAggregator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Thin polish layer over the existing programming-runtime services.
 *
 * Every public method returns a normalized envelope ({@see ProgrammingConsoleCanon::ENVELOPE_KEYS})
 * carrying status / ids / selected_core / flow / evidence_refs / blockers /
 * next_actions / certification_status / claim_policy.
 *
 * Hard contract:
 *  - read-only operations stay read-only;
 *  - write operations (forge:intake) ONLY touch DB rows via existing canonical
 *    services that never invoke rival providers;
 *  - benchmark is never executed; `claim_policy.benchmark_not_run = true` on
 *    every envelope.
 */
class ProgrammingConsoleService
{
    public function __construct(
        private readonly ProgrammingRuntimeControlPlaneService $controlPlane,
        private readonly ProgrammingRuntimeReadinessService $readiness,
        private readonly ProgrammingRuntimeTelemetryAggregator $telemetry,
        private readonly ForgeIntakeService $forgeIntake,
        private readonly ProgrammingResumeService $resume,
        private readonly AiCompactionService $compaction,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function status(): array
    {
        $snapshot = $this->controlPlane->snapshot();

        return $this->envelope(
            action: ProgrammingConsoleCanon::ACTION_STATUS,
            status: $this->mapStatus((string) ($snapshot['runtime_status'] ?? 'partial')),
            payload: [
                'runtime_status' => $snapshot['runtime_status'] ?? null,
                'mission_summary' => $snapshot['active_missions'] ?? [],
                'dev_runs' => $snapshot['dev_runs_summary'] ?? [],
                'forge_obras' => $snapshot['forge_obras_summary'] ?? [],
                'work_packets' => $snapshot['work_packets_summary'] ?? [],
                'rag_gate_summary' => $snapshot['rag_gate_summary'] ?? [],
                'repair_loop_summary' => $snapshot['repair_loop_summary'] ?? [],
            ],
            evidenceRefs: $this->extractEvidenceFromSnapshot($snapshot),
            blockers: $this->normalizeBlockers((array) ($snapshot['blockers'] ?? [])),
            nextActions: $this->normalizeNextActions((array) ($snapshot['next_actions'] ?? [])),
            certificationStatus: $this->deriveCertificationStatus($snapshot['certification_summary'] ?? []),
        );
    }

    /**
     * Plan-only Dev projection. Does NOT call the Dev orchestrator, NOT call
     * any provider, NOT write to disk. It echoes the prompt + a normalized
     * intent + the canonical Dev envelope so operators can preview the
     * canonical shape before committing to a real run.
     *
     * @return array<string,mixed>
     */
    public function devPlan(string $prompt, string $flow = 'atlas_dev'): array
    {
        $prompt = trim($prompt);
        $normalizedIntent = $this->normalizeIntent($prompt);
        $runId = 'apdrun_'.substr(MissionCanonicalHash::sha256([
            'prompt' => $prompt,
            'flow' => $flow,
            'kind' => 'dev_plan_only',
        ]), 0, 24);

        $blockers = [];
        if ($prompt === '') {
            $blockers[] = [
                'source' => 'console',
                'severity' => 'blocker',
                'id' => 'dev_plan:empty_prompt',
                'message' => 'Dev plan requires a non-empty prompt.',
                'evidence_refs' => [],
                'remediation' => 'Pass a real prompt before requesting dev:plan.',
            ];
        }

        $status = $blockers === []
            ? ProgrammingConsoleCanon::STATUS_GREEN
            : ProgrammingConsoleCanon::STATUS_BLOCKED;

        return $this->envelope(
            action: ProgrammingConsoleCanon::ACTION_DEV_PLAN,
            status: $status,
            selectedCore: ProgrammingConsoleCanon::CORE_DEV,
            flow: $flow,
            ids: [
                'run_id' => $runId,
                'prompt_hash' => MissionCanonicalHash::sha256(['prompt' => $prompt]),
            ],
            payload: [
                'plan_only' => true,
                'prompt' => $prompt,
                'normalized_intent' => $normalizedIntent,
                'mode' => 'preview',
                'note' => 'dev:plan never invokes a provider and never writes state. It produces a canonical envelope only.',
            ],
            blockers: $blockers,
            nextActions: $blockers === []
                ? [
                    [
                        'priority' => 'high',
                        'source' => 'dev',
                        'description' => 'Promote prompt to atlas:cli:dev when ready; this preview is read-only.',
                    ],
                ]
                : [
                    [
                        'priority' => 'critical',
                        'source' => 'dev',
                        'description' => 'Provide a non-empty prompt then retry dev:plan.',
                    ],
                ],
            certificationStatus: ProgrammingConsoleCanon::CERTIFICATION_STATUS_NOT_EVALUATED,
        );
    }

    /**
     * Dev runs summary (read only).
     *
     * @return array<string,mixed>
     */
    public function devSummary(): array
    {
        $snapshot = $this->controlPlane->snapshot();
        $devRuns = (array) ($snapshot['dev_runs_summary'] ?? []);

        return $this->envelope(
            action: ProgrammingConsoleCanon::ACTION_DEV_SUMMARY,
            status: $this->summaryStatus($devRuns),
            selectedCore: ProgrammingConsoleCanon::CORE_DEV,
            flow: 'atlas_dev',
            payload: $devRuns,
        );
    }

    /**
     * Create a Forge intake row from a prompt. This is a SAFE write path:
     * `ForgeIntakeService::intakeFromPrompt` only persists DB rows; it never
     * invokes a provider. Use `dry_run=true` to skip the write and return a
     * pure preview.
     *
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function forgeIntake(string $prompt, array $options = []): array
    {
        $prompt = trim($prompt);
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $blockers = [];

        if ($prompt === '') {
            $blockers[] = [
                'source' => 'console',
                'severity' => 'blocker',
                'id' => 'forge_intake:empty_prompt',
                'message' => 'Forge intake requires a non-empty prompt.',
                'evidence_refs' => [],
                'remediation' => 'Provide a prompt with at least one action verb and one object marker.',
            ];

            return $this->envelope(
                action: ProgrammingConsoleCanon::ACTION_FORGE_INTAKE,
                status: ProgrammingConsoleCanon::STATUS_BLOCKED,
                selectedCore: ProgrammingConsoleCanon::CORE_FORGE,
                flow: 'atlas_forge',
                payload: ['attempted' => false, 'dry_run' => $dryRun],
                blockers: $blockers,
                nextActions: [['priority' => 'critical', 'source' => 'forge', 'description' => 'Retry with a meaningful prompt.']],
                certificationStatus: ProgrammingConsoleCanon::CERTIFICATION_STATUS_NOT_EVALUATED,
            );
        }

        if ($dryRun) {
            return $this->envelope(
                action: ProgrammingConsoleCanon::ACTION_FORGE_INTAKE,
                status: ProgrammingConsoleCanon::STATUS_GREEN,
                selectedCore: ProgrammingConsoleCanon::CORE_FORGE,
                flow: 'atlas_forge',
                ids: ['intake_id' => null, 'prompt_hash' => MissionCanonicalHash::sha256(['prompt' => $prompt])],
                payload: [
                    'attempted' => false,
                    'dry_run' => true,
                    'prompt' => $prompt,
                    'note' => 'dry_run=true: no DB row created. Re-run without --dry-run to materialize the intake.',
                ],
                certificationStatus: ProgrammingConsoleCanon::CERTIFICATION_STATUS_NOT_EVALUATED,
            );
        }

        if (! $this->forgeTablesAvailable()) {
            return $this->envelope(
                action: ProgrammingConsoleCanon::ACTION_FORGE_INTAKE,
                status: ProgrammingConsoleCanon::STATUS_BLOCKED,
                selectedCore: ProgrammingConsoleCanon::CORE_FORGE,
                flow: 'atlas_forge',
                payload: ['attempted' => false, 'dry_run' => false],
                blockers: [[
                    'source' => 'forge',
                    'severity' => 'blocker',
                    'id' => 'forge_intake:tables_missing',
                    'message' => 'Forge intake tables are not present in this database.',
                    'evidence_refs' => ['ai_forge_intakes', 'ai_forge_work_packets', 'ai_forge_milestones'],
                    'remediation' => 'Run pending migrations or invoke this command from an environment that has the Forge schema.',
                ]],
                nextActions: [['priority' => 'critical', 'source' => 'forge', 'description' => 'Boot the Forge schema before retrying forge:intake.']],
                certificationStatus: ProgrammingConsoleCanon::CERTIFICATION_STATUS_NOT_EVALUATED,
            );
        }

        try {
            $intake = $this->forgeIntake->intakeFromPrompt($prompt, [
                'workspace_slug' => $options['workspace_slug'] ?? null,
                'actor_type' => $options['actor_type'] ?? 'console_operator',
            ]);
        } catch (Throwable $e) {
            return $this->envelope(
                action: ProgrammingConsoleCanon::ACTION_FORGE_INTAKE,
                status: ProgrammingConsoleCanon::STATUS_BLOCKED,
                selectedCore: ProgrammingConsoleCanon::CORE_FORGE,
                flow: 'atlas_forge',
                payload: ['attempted' => true, 'dry_run' => false, 'exception_class' => $e::class],
                blockers: [[
                    'source' => 'forge',
                    'severity' => 'blocker',
                    'id' => 'forge_intake:exception',
                    'message' => $e->getMessage(),
                    'evidence_refs' => [],
                    'remediation' => 'Inspect logs; adjust the prompt or workspace_slug; retry.',
                ]],
                certificationStatus: ProgrammingConsoleCanon::CERTIFICATION_STATUS_NOT_EVALUATED,
            );
        }

        $status = $intake->status === ForgeIntakeCanon::STATUS_BLOCKED
            ? ProgrammingConsoleCanon::STATUS_BLOCKED
            : ProgrammingConsoleCanon::STATUS_GREEN;
        $blockers = $intake->status === ForgeIntakeCanon::STATUS_BLOCKED
            ? [[
                'source' => 'forge',
                'severity' => 'blocker',
                'id' => 'forge_intake:blocked',
                'message' => (string) ($intake->blocker_reason ?? 'forge_intake_blocked'),
                'evidence_refs' => ['intake:'.$intake->id],
                'remediation' => 'Refine the prompt with explicit action+object markers and retry.',
            ]]
            : [];

        return $this->envelope(
            action: ProgrammingConsoleCanon::ACTION_FORGE_INTAKE,
            status: $status,
            selectedCore: ProgrammingConsoleCanon::CORE_FORGE,
            flow: 'atlas_forge',
            ids: [
                'intake_id' => $intake->id,
                'intake_uuid' => $intake->uuid,
                'intake_hash' => $intake->intake_hash,
                'mission_id' => $intake->mission_id,
            ],
            payload: [
                'attempted' => true,
                'dry_run' => false,
                'origin' => $intake->origin,
                'obra_title' => $intake->obra_title,
                'recommended_forge_mode' => $intake->recommended_forge_mode,
                'risk_band' => $intake->risk_band,
                'definition_of_done' => array_values((array) ($intake->definition_of_done ?? [])),
                'required_evidence' => array_values((array) ($intake->required_evidence ?? [])),
                'work_packet_count' => $intake->workPackets()->count(),
                'milestone_count' => $intake->milestones()->count(),
            ],
            evidenceRefs: ['forge_intake:'.$intake->id],
            blockers: $blockers,
            nextActions: $blockers === []
                ? [['priority' => 'high', 'source' => 'forge', 'description' => 'Initialize the long-horizon state and start the implementation milestone.']]
                : [['priority' => 'critical', 'source' => 'forge', 'description' => 'Resolve intake blocker before downstream Forge work.']],
            certificationStatus: ProgrammingConsoleCanon::CERTIFICATION_STATUS_NOT_EVALUATED,
        );
    }

    /**
     * Forge runtime summary (read only).
     *
     * @return array<string,mixed>
     */
    public function forgeSummary(): array
    {
        $snapshot = $this->controlPlane->snapshot();

        return $this->envelope(
            action: ProgrammingConsoleCanon::ACTION_FORGE_SUMMARY,
            status: $this->summaryStatus((array) ($snapshot['forge_obras_summary'] ?? [])),
            selectedCore: ProgrammingConsoleCanon::CORE_FORGE,
            flow: 'atlas_forge',
            payload: [
                'forge_obras' => $snapshot['forge_obras_summary'] ?? [],
                'work_packets' => $snapshot['work_packets_summary'] ?? [],
            ],
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function blockers(): array
    {
        $snapshot = $this->controlPlane->snapshot();
        $blockers = $this->normalizeBlockers((array) ($snapshot['blockers'] ?? []));
        $status = $blockers === []
            ? ProgrammingConsoleCanon::STATUS_GREEN
            : ProgrammingConsoleCanon::STATUS_BLOCKED;

        return $this->envelope(
            action: ProgrammingConsoleCanon::ACTION_BLOCKERS,
            status: $status,
            payload: [
                'count' => count($blockers),
                'by_source' => $snapshot['blockers']['by_source'] ?? [],
                'by_severity' => $snapshot['blockers']['by_severity'] ?? [],
            ],
            blockers: $blockers,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function nextActions(): array
    {
        $snapshot = $this->controlPlane->snapshot();
        $next = $this->normalizeNextActions((array) ($snapshot['next_actions'] ?? []));

        return $this->envelope(
            action: ProgrammingConsoleCanon::ACTION_NEXT_ACTIONS,
            status: ProgrammingConsoleCanon::STATUS_GREEN,
            payload: ['count' => count($next)],
            nextActions: $next,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function evidence(): array
    {
        $snapshot = $this->controlPlane->snapshot();
        $evidenceCompleteness = (array) ($snapshot['evidence_completeness'] ?? []);
        $evidenceRefs = $this->extractEvidenceFromSnapshot($snapshot);

        return $this->envelope(
            action: ProgrammingConsoleCanon::ACTION_EVIDENCE,
            status: $this->evidenceStatus($evidenceCompleteness),
            payload: $evidenceCompleteness,
            evidenceRefs: $evidenceRefs,
            certificationStatus: $this->deriveCertificationStatus($snapshot['certification_summary'] ?? []),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function certification(): array
    {
        $snapshot = $this->controlPlane->snapshot();
        $cert = (array) ($snapshot['certification_summary'] ?? []);
        $status = $this->deriveCertificationStatus($cert);

        return $this->envelope(
            action: ProgrammingConsoleCanon::ACTION_CERTIFICATION,
            status: match ($status) {
                ProgrammingConsoleCanon::CERTIFICATION_STATUS_PASSED => ProgrammingConsoleCanon::STATUS_GREEN,
                ProgrammingConsoleCanon::CERTIFICATION_STATUS_PARTIAL => ProgrammingConsoleCanon::STATUS_PARTIAL,
                ProgrammingConsoleCanon::CERTIFICATION_STATUS_BLOCKED,
                ProgrammingConsoleCanon::CERTIFICATION_STATUS_FAILED => ProgrammingConsoleCanon::STATUS_BLOCKED,
                default => ProgrammingConsoleCanon::STATUS_PARTIAL,
            },
            payload: $cert,
            certificationStatus: $status,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function telemetrySummary(): array
    {
        $telemetry = $this->telemetry->aggregate();
        $available = ($telemetry['reason'] ?? null) !== 'telemetry_table_missing';

        return $this->envelope(
            action: ProgrammingConsoleCanon::ACTION_TELEMETRY,
            status: $available
                ? ProgrammingConsoleCanon::STATUS_GREEN
                : ProgrammingConsoleCanon::STATUS_PARTIAL,
            payload: [
                'available' => $available,
                'total_events' => (int) ($telemetry['total_events'] ?? 0),
                'distinct_runs' => (int) ($telemetry['distinct_runs'] ?? 0),
                'by_flow' => $telemetry['by_flow'] ?? [],
                'by_core' => $telemetry['by_core'] ?? [],
                'by_execution_status' => $telemetry['by_execution_status'] ?? [],
                'by_certification_status' => $telemetry['by_certification_status'] ?? [],
                'reason' => $telemetry['reason'] ?? null,
            ],
        );
    }

    /**
     * Readiness smoke — wraps the existing readiness report with the canonical
     * envelope.
     *
     * @return array<string,mixed>
     */
    public function smoke(): array
    {
        $report = $this->readiness->report();
        $reportStatus = (string) ($report['status'] ?? 'partial');
        $status = match ($reportStatus) {
            ProgrammingRuntimeReadinessCanon::STATUS_BLOCKED => ProgrammingConsoleCanon::STATUS_BLOCKED,
            'green' => ProgrammingConsoleCanon::STATUS_GREEN,
            default => ProgrammingConsoleCanon::STATUS_PARTIAL,
        };

        return $this->envelope(
            action: ProgrammingConsoleCanon::ACTION_SMOKE,
            status: $status,
            payload: [
                'readiness_status' => $reportStatus,
                'summary' => $report['summary'] ?? [],
                'checks' => $report['checks'] ?? [],
            ],
            blockers: $this->normalizeReadinessBlockers((array) ($report['blockers'] ?? [])),
            nextActions: $this->normalizeReadinessNextActions((array) ($report['next_actions'] ?? [])),
        );
    }

    /**
     * Long-horizon status — counts persisted continuation packs + compaction
     * receipts and projects the most recent ones for the scope hint. Read
     * only; never writes; tolerant when the long-horizon tables are absent.
     *
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function longHorizonStatus(array $options = []): array
    {
        $scopeType = $this->stringOption($options, 'scope_type');
        $scopeId = $this->stringOption($options, 'scope_id');
        $packsAvailable = Schema::hasTable('atlas_long_horizon_continuation_packs');
        $receiptsAvailable = Schema::hasTable('atlas_long_horizon_compaction_receipts');

        $blockers = [];
        if (! $packsAvailable && ! $receiptsAvailable) {
            $blockers[] = [
                'source' => 'long_horizon',
                'severity' => 'blocker',
                'id' => 'long_horizon:tables_missing',
                'message' => 'Continuation pack and compaction receipt tables are not present in this database.',
                'evidence_refs' => ['atlas_long_horizon_continuation_packs', 'atlas_long_horizon_compaction_receipts'],
                'remediation' => 'Run pending migrations or invoke from an environment with the long-horizon schema.',
            ];
        }

        $packs = $this->loadRecentPacks($scopeType, $scopeId, $packsAvailable);
        $receipts = $this->loadRecentReceipts($scopeType, $scopeId, $receiptsAvailable);

        $payload = [
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'continuation_packs' => [
                'available' => $packsAvailable,
                'count' => $packsAvailable ? AtlasLongHorizonContinuationPack::query()->count() : 0,
                'count_for_scope' => $packsAvailable && $scopeType !== null && $scopeId !== null
                    ? $this->countPacksForScope($scopeType, $scopeId)
                    : null,
                'recent' => $packs,
            ],
            'compaction_receipts' => [
                'available' => $receiptsAvailable,
                'count' => $receiptsAvailable ? AtlasLongHorizonCompactionReceipt::query()->count() : 0,
                'count_for_scope' => $receiptsAvailable && $scopeType !== null && $scopeId !== null
                    ? $this->countReceiptsForScope($scopeType, $scopeId)
                    : null,
                'recent' => $receipts,
            ],
            'freshness' => [
                'gate_evaluated' => false,
                'gate_status' => 'not_evaluated',
                'note' => 'LongHorizonContextFreshnessGate not shipped yet; status is structural only.',
            ],
            'recovery' => [
                'planner_available' => false,
                'note' => 'LongHorizonRecoveryPlannerService not shipped yet; surface placeholder only.',
            ],
        ];

        $status = $blockers !== []
            ? ProgrammingConsoleCanon::STATUS_BLOCKED
            : (($packsAvailable && AtlasLongHorizonContinuationPack::query()->exists())
                ? ProgrammingConsoleCanon::STATUS_GREEN
                : ProgrammingConsoleCanon::STATUS_PARTIAL);

        return $this->envelope(
            action: ProgrammingConsoleCanon::ACTION_LONG_HORIZON_STATUS,
            status: $status,
            ids: [
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
            ],
            payload: $payload,
            evidenceRefs: $this->packEvidenceRefs($packs),
            blockers: $blockers,
            nextActions: $blockers === []
                ? [['priority' => 'normal', 'source' => 'long_horizon', 'description' => 'Use long-horizon:compact to checkpoint a scope before context drift; long-horizon:continue to resume safely.']]
                : [['priority' => 'critical', 'source' => 'long_horizon', 'description' => 'Run pending TEOS-I1 migrations before retrying.']],
            certificationStatus: ProgrammingConsoleCanon::CERTIFICATION_STATUS_NOT_EVALUATED,
        );
    }

    /**
     * Long-horizon compact. Wraps `AiCompactionService::compactForScope()` with
     * canonical envelope shaping. NEVER destructive: the underlying service
     * only writes a compaction receipt row; nothing is deleted, no provider
     * is called, no thread is mutated. When `dry_run=true` (default), no row
     * is created and the envelope returns the projected payload only.
     *
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function longHorizonCompact(array $options = []): array
    {
        $scopeType = $this->stringOption($options, 'scope_type');
        $scopeId = $this->stringOption($options, 'scope_id');
        $dryRun = (bool) ($options['dry_run'] ?? true);

        $blockers = [];
        if ($scopeType === null) {
            $blockers[] = [
                'source' => 'long_horizon',
                'severity' => 'blocker',
                'id' => 'long_horizon_compact:missing_scope_type',
                'message' => 'compact requires --scope-type (one of '.implode(',', AtlasLongHorizonCanon::ALLOWED_SCOPE_TYPES).').',
                'evidence_refs' => [],
                'remediation' => 'Pass --scope-type=<canonical scope> before retrying.',
            ];
        } elseif (! in_array($scopeType, AtlasLongHorizonCanon::ALLOWED_SCOPE_TYPES, true)) {
            $blockers[] = [
                'source' => 'long_horizon',
                'severity' => 'blocker',
                'id' => 'long_horizon_compact:invalid_scope_type',
                'message' => 'scope_type ['.$scopeType.'] is not in the canonical taxonomy.',
                'evidence_refs' => [],
                'remediation' => 'Use one of: '.implode(', ', AtlasLongHorizonCanon::ALLOWED_SCOPE_TYPES),
            ];
        }

        if ($blockers === [] && ! Schema::hasTable('atlas_long_horizon_compaction_receipts')) {
            $blockers[] = [
                'source' => 'long_horizon',
                'severity' => 'blocker',
                'id' => 'long_horizon_compact:tables_missing',
                'message' => 'Compaction receipt table is not present in this database.',
                'evidence_refs' => ['atlas_long_horizon_compaction_receipts'],
                'remediation' => 'Run pending TEOS-I1 migrations before retrying.',
            ];
        }

        if ($blockers !== []) {
            return $this->envelope(
                action: ProgrammingConsoleCanon::ACTION_LONG_HORIZON_COMPACT,
                status: ProgrammingConsoleCanon::STATUS_BLOCKED,
                ids: ['scope_type' => $scopeType, 'scope_id' => $scopeId],
                payload: ['attempted' => false, 'dry_run' => $dryRun],
                blockers: $blockers,
                nextActions: [['priority' => 'critical', 'source' => 'long_horizon', 'description' => 'Resolve compact blocker before retrying.']],
                certificationStatus: ProgrammingConsoleCanon::CERTIFICATION_STATUS_NOT_EVALUATED,
            );
        }

        if ($dryRun) {
            return $this->envelope(
                action: ProgrammingConsoleCanon::ACTION_LONG_HORIZON_COMPACT,
                status: ProgrammingConsoleCanon::STATUS_GREEN,
                ids: ['scope_type' => $scopeType, 'scope_id' => $scopeId],
                payload: [
                    'attempted' => false,
                    'dry_run' => true,
                    'note' => 'dry_run=true: no compaction receipt created. Re-run with --no-dry-run to materialise.',
                    'projected_must_keep_items' => array_values((array) ($options['must_keep_items'] ?? [])),
                    'projected_forced_discards' => array_values((array) ($options['forced_discards'] ?? [])),
                ],
                certificationStatus: ProgrammingConsoleCanon::CERTIFICATION_STATUS_NOT_EVALUATED,
            );
        }

        try {
            $result = $this->compaction->compactForScope([
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'must_keep_items' => (array) ($options['must_keep_items'] ?? []),
                'forced_discards' => (array) ($options['forced_discards'] ?? []),
                'source_context_refs' => (array) ($options['source_context_refs'] ?? []),
                'evidence_refs' => (array) ($options['evidence_refs'] ?? []),
                'stale_risks' => (array) ($options['stale_risks'] ?? []),
                'detected_contradictions' => (array) ($options['detected_contradictions'] ?? []),
                'recovery_queries' => (array) ($options['recovery_queries'] ?? []),
                'actor_alias' => 'programming_console',
            ]);
        } catch (Throwable $e) {
            return $this->envelope(
                action: ProgrammingConsoleCanon::ACTION_LONG_HORIZON_COMPACT,
                status: ProgrammingConsoleCanon::STATUS_BLOCKED,
                ids: ['scope_type' => $scopeType, 'scope_id' => $scopeId],
                payload: ['attempted' => true, 'dry_run' => false, 'exception_class' => $e::class],
                blockers: [[
                    'source' => 'long_horizon',
                    'severity' => 'blocker',
                    'id' => 'long_horizon_compact:exception',
                    'message' => $e->getMessage(),
                    'evidence_refs' => [],
                    'remediation' => 'Inspect inputs (scope_type/must_keep_items/forced_discards) and retry.',
                ]],
                certificationStatus: ProgrammingConsoleCanon::CERTIFICATION_STATUS_NOT_EVALUATED,
            );
        }

        $writeAllowed = (bool) ($result['write_allowed'] ?? false);
        $lossRisk = (string) ($result['loss_risk'] ?? AtlasLongHorizonCanon::LOSS_RISK_LOW);
        $status = match (true) {
            $lossRisk === AtlasLongHorizonCanon::LOSS_RISK_HIGH => ProgrammingConsoleCanon::STATUS_BLOCKED,
            ! $writeAllowed => ProgrammingConsoleCanon::STATUS_PARTIAL,
            default => ProgrammingConsoleCanon::STATUS_GREEN,
        };

        return $this->envelope(
            action: ProgrammingConsoleCanon::ACTION_LONG_HORIZON_COMPACT,
            status: $status,
            ids: [
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'compaction_receipt_id' => $result['compaction_receipt_id'] ?? null,
                'receipt_hash' => $result['receipt_hash'] ?? null,
            ],
            payload: [
                'attempted' => true,
                'dry_run' => false,
                'write_allowed' => $writeAllowed,
                'loss_risk' => $lossRisk,
                'must_keep_coverage' => $result['must_keep_coverage'] ?? null,
                'discarded_reason' => $result['discarded_reason'] ?? null,
                'quality_score' => $result['quality_score'] ?? null,
                'summary_hash' => $result['summary_hash'] ?? null,
                'unresolved_loss_count' => count((array) ($result['unresolved_loss'] ?? [])),
                'recovery_query_count' => count((array) ($result['recovery_queries'] ?? [])),
            ],
            evidenceRefs: array_values(array_filter([
                isset($result['compaction_receipt_id']) ? 'compaction_receipt:'.$result['compaction_receipt_id'] : null,
                isset($result['receipt_hash']) ? 'receipt_hash:'.$result['receipt_hash'] : null,
            ])),
            blockers: $lossRisk === AtlasLongHorizonCanon::LOSS_RISK_HIGH
                ? [[
                    'source' => 'long_horizon',
                    'severity' => 'blocker',
                    'id' => 'long_horizon_compact:high_loss_risk',
                    'message' => 'Compaction touched a critical keep kind; loss_risk=high.',
                    'evidence_refs' => isset($result['compaction_receipt_id']) ? ['compaction_receipt:'.$result['compaction_receipt_id']] : [],
                    'remediation' => 'Review unresolved_loss + recovery_queries before any downstream write.',
                ]]
                : [],
            certificationStatus: ProgrammingConsoleCanon::CERTIFICATION_STATUS_NOT_EVALUATED,
        );
    }

    /**
     * Long-horizon continue. Loads (or synthesises) the v2 continuation pack
     * for the requested scope/plan via `ProgrammingResumeService::state()`
     * and surfaces `safe_resume_mode` + next_safe_action without performing
     * any write. Callers must inspect the envelope before acting.
     *
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function longHorizonContinue(array $options = []): array
    {
        $planId = $this->stringOption($options, 'plan_id');
        $parentPlanId = $this->stringOption($options, 'parent_plan_id');
        $scopeType = $this->stringOption($options, 'scope_type');
        $scopeId = $this->stringOption($options, 'scope_id');

        if ($planId === null) {
            return $this->envelope(
                action: ProgrammingConsoleCanon::ACTION_LONG_HORIZON_CONTINUE,
                status: ProgrammingConsoleCanon::STATUS_BLOCKED,
                ids: ['plan_id' => null, 'parent_plan_id' => $parentPlanId],
                payload: ['attempted' => false],
                blockers: [[
                    'source' => 'long_horizon',
                    'severity' => 'blocker',
                    'id' => 'long_horizon_continue:missing_plan_id',
                    'message' => 'continue requires --plan-id (the resuming plan).',
                    'evidence_refs' => [],
                    'remediation' => 'Pass --plan-id=<id> and optionally --parent-plan-id=<id>.',
                ]],
                nextActions: [['priority' => 'critical', 'source' => 'long_horizon', 'description' => 'Provide --plan-id and retry.']],
                certificationStatus: ProgrammingConsoleCanon::CERTIFICATION_STATUS_NOT_EVALUATED,
            );
        }

        $context = [];
        if ($scopeType !== null) {
            $context['scope_type'] = $scopeType;
        }
        if ($scopeId !== null) {
            $context['scope_id'] = $scopeId;
        }
        if (isset($options['missing_required_refs']) && is_array($options['missing_required_refs'])) {
            $context['missing_required_refs'] = $options['missing_required_refs'];
        }
        if (isset($options['stale_refs']) && is_array($options['stale_refs'])) {
            $context['stale_refs'] = $options['stale_refs'];
        }

        $state = $this->resume->state($planId, $parentPlanId, [], $context);
        $pack = (array) ($state['continuation_pack'] ?? []);
        $resumeAllowed = (bool) ($state['resume_allowed'] ?? false);
        $safeResumeMode = (string) ($pack['safe_resume_mode'] ?? AtlasLongHorizonCanon::SAFE_RESUME_ASK_HUMAN);

        $status = match ($safeResumeMode) {
            AtlasLongHorizonCanon::SAFE_RESUME_EXECUTE => ProgrammingConsoleCanon::STATUS_GREEN,
            AtlasLongHorizonCanon::SAFE_RESUME_READ_ONLY,
            AtlasLongHorizonCanon::SAFE_RESUME_REPAIR,
            AtlasLongHorizonCanon::SAFE_RESUME_REVIEW => ProgrammingConsoleCanon::STATUS_PARTIAL,
            default => ProgrammingConsoleCanon::STATUS_BLOCKED,
        };

        $blockers = [];
        if (! $resumeAllowed) {
            $blockers[] = [
                'source' => 'long_horizon',
                'severity' => 'blocker',
                'id' => 'long_horizon_continue:resume_not_allowed',
                'message' => 'Stage receipt timeline validation rejected resume — human review required.',
                'evidence_refs' => $pack['continuation_pack_id'] !== null ? ['continuation_pack:'.$pack['continuation_pack_id']] : [],
                'remediation' => 'Inspect parent timeline + missing_required_refs before retrying.',
            ];
        }

        return $this->envelope(
            action: ProgrammingConsoleCanon::ACTION_LONG_HORIZON_CONTINUE,
            status: $status,
            ids: [
                'plan_id' => $planId,
                'parent_plan_id' => $parentPlanId,
                'scope_type' => (string) ($pack['scope_type'] ?? AtlasLongHorizonCanon::SCOPE_TYPE_DEV_RUN),
                'scope_id' => $pack['scope_id'] ?? null,
                'continuation_pack_id' => $pack['continuation_pack_id'] ?? null,
                'pack_hash' => $pack['pack_hash'] ?? null,
            ],
            selectedCore: ProgrammingConsoleCanon::CORE_DEV,
            flow: 'atlas_dev',
            payload: [
                'attempted' => true,
                'writes' => false,
                'resume_allowed' => $resumeAllowed,
                'safe_resume_mode' => $safeResumeMode,
                'next_safe_action' => $pack['next_safe_action'] ?? null,
                'human_decisions_required' => array_values((array) ($pack['human_decisions_required'] ?? [])),
                'missing_required_refs' => array_values((array) ($pack['missing_required_refs'] ?? [])),
                'stale_refs' => array_values((array) ($pack['stale_refs'] ?? [])),
                'pack_source' => $pack['source'] ?? 'synthesised',
                'pack_hash' => $pack['pack_hash'] ?? null,
                'continuation_pack_id' => $pack['continuation_pack_id'] ?? null,
            ],
            evidenceRefs: array_values(array_filter([
                $pack['continuation_pack_id'] ?? null
                    ? 'continuation_pack:'.$pack['continuation_pack_id']
                    : null,
                $pack['pack_hash'] ?? null
                    ? 'pack_hash:'.$pack['pack_hash']
                    : null,
            ])),
            blockers: $blockers,
            nextActions: $safeResumeMode === AtlasLongHorizonCanon::SAFE_RESUME_EXECUTE
                ? [['priority' => 'normal', 'source' => 'long_horizon', 'description' => 'Resume the plan via atlas:programming:resume.']]
                : [['priority' => 'high', 'source' => 'long_horizon', 'description' => 'safe_resume_mode='.$safeResumeMode.' — review pack before any provider call.']],
            certificationStatus: ProgrammingConsoleCanon::CERTIFICATION_STATUS_NOT_EVALUATED,
        );
    }

    /**
     * Long-horizon certify. Until the full Continuity Certification (TEOS-I1
     * M10) ships, we surface a structural checks block + an explicit blocker
     * stating the certification service is not yet implemented. Always
     * non-destructive.
     *
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function longHorizonCertify(array $options = []): array
    {
        $scopeType = $this->stringOption($options, 'scope_type');
        $scopeId = $this->stringOption($options, 'scope_id');

        $packsAvailable = Schema::hasTable('atlas_long_horizon_continuation_packs');
        $receiptsAvailable = Schema::hasTable('atlas_long_horizon_compaction_receipts');

        $checks = [
            $this->certifyCheck(
                id: 'continuation_pack_table_present',
                ok: $packsAvailable,
                detail: $packsAvailable
                    ? 'atlas_long_horizon_continuation_packs table is present.'
                    : 'atlas_long_horizon_continuation_packs table missing.',
            ),
            $this->certifyCheck(
                id: 'compaction_receipt_table_present',
                ok: $receiptsAvailable,
                detail: $receiptsAvailable
                    ? 'atlas_long_horizon_compaction_receipts table is present.'
                    : 'atlas_long_horizon_compaction_receipts table missing.',
            ),
            $this->certifyCheck(
                id: 'pack_exists_for_scope',
                ok: $packsAvailable && $scopeType !== null && $scopeId !== null
                    ? $this->countPacksForScope($scopeType, $scopeId) > 0
                    : false,
                detail: $scopeType === null || $scopeId === null
                    ? 'scope_type+scope_id not provided; check skipped.'
                    : ($packsAvailable && $this->countPacksForScope($scopeType, $scopeId) > 0
                        ? 'at least one continuation pack exists for the scope.'
                        : 'no continuation pack found for the scope.'),
                severity: 'medium',
            ),
            $this->certifyCheck(
                id: 'continuity_certification_service_shipped',
                ok: false,
                detail: 'AtlasContinuityCertificationService not implemented yet (TEOS-I1 M10 pending).',
                severity: 'low',
            ),
        ];

        $blockerChecks = array_values(array_filter(
            $checks,
            static fn (array $check): bool => $check['status'] === 'blocked' && $check['severity'] === 'high',
        ));
        $status = $blockerChecks !== []
            ? ProgrammingConsoleCanon::STATUS_BLOCKED
            : ProgrammingConsoleCanon::STATUS_PARTIAL;
        $certificationStatus = $blockerChecks !== []
            ? ProgrammingConsoleCanon::CERTIFICATION_STATUS_BLOCKED
            : ProgrammingConsoleCanon::CERTIFICATION_STATUS_NOT_EVALUATED;

        $blockers = [];
        $blockers[] = [
            'source' => 'long_horizon',
            'severity' => 'warn',
            'id' => 'long_horizon_certify:service_not_shipped',
            'message' => 'Continuity Certification not implemented yet. Surface returns structural checks only.',
            'evidence_refs' => [],
            'remediation' => 'Ship AtlasContinuityCertificationService (TEOS-I1 M10) before relying on this surface.',
        ];
        foreach ($blockerChecks as $check) {
            $blockers[] = [
                'source' => 'long_horizon',
                'severity' => 'blocker',
                'id' => 'long_horizon_certify:'.$check['id'],
                'message' => $check['detail'],
                'evidence_refs' => $check['evidence_refs'] ?? [],
                'remediation' => 'Run pending migrations or fix the listed dependency.',
            ];
        }

        return $this->envelope(
            action: ProgrammingConsoleCanon::ACTION_LONG_HORIZON_CERTIFY,
            status: $status,
            ids: ['scope_type' => $scopeType, 'scope_id' => $scopeId],
            payload: [
                'attempted' => true,
                'writes' => false,
                'checks' => $checks,
                'check_count' => count($checks),
                'pass_count' => count(array_filter($checks, static fn (array $c): bool => $c['status'] === 'passed')),
                'continuity_certification_service' => 'not_shipped',
                'note' => 'Structural surface only. Real Continuity Certification arrives in TEOS-I1 M10.',
            ],
            blockers: $blockers,
            nextActions: [['priority' => 'normal', 'source' => 'long_horizon', 'description' => 'Track TEOS-I1 M10 implementation; this surface will tighten once the service ships.']],
            certificationStatus: $certificationStatus,
        );
    }

    /**
     * @param  array<string,mixed>  $ids
     * @param  array<string,mixed>  $payload
     * @param  array<int,string|array<string,mixed>>  $evidenceRefs
     * @param  array<int,array<string,mixed>>  $blockers
     * @param  array<int,array<string,mixed>>  $nextActions
     * @return array<string,mixed>
     */
    private function envelope(
        string $action,
        string $status,
        array $payload = [],
        array $ids = [],
        string $selectedCore = ProgrammingConsoleCanon::CORE_NONE,
        ?string $flow = null,
        array $evidenceRefs = [],
        array $blockers = [],
        array $nextActions = [],
        string $certificationStatus = ProgrammingConsoleCanon::CERTIFICATION_STATUS_NOT_EVALUATED,
    ): array {
        $requestId = 'apcsl_'.substr((string) Str::uuid(), 0, 24);
        $generatedAt = Carbon::now()->toISOString();

        $idsCanonical = array_merge([
            'request_id' => $requestId,
            'generated_at' => $generatedAt,
            'mission_id' => null,
            'intake_id' => null,
            'run_id' => null,
        ], $ids);

        return [
            'schema_version' => ProgrammingConsoleCanon::SCHEMA_VERSION,
            'action' => $action,
            'status' => $status,
            'ids' => $idsCanonical,
            'selected_core' => $selectedCore,
            'flow' => $flow,
            'payload' => $payload,
            'evidence_refs' => array_values($evidenceRefs),
            'blockers' => array_values($blockers),
            'next_actions' => array_values($nextActions),
            'certification_status' => $certificationStatus,
            'claim_policy' => [
                'benchmark_not_run' => true,
                'rivals_compared' => false,
                'rival_provider_invoked' => false,
                'requires_human_authorization_to_run_benchmark' => true,
            ],
            'note' => 'Atlas Programming Console envelope. Read/write SAFE actions only — no provider call, no benchmark execution.',
        ];
    }

    private function mapStatus(string $raw): string
    {
        return match ($raw) {
            ProgrammingRuntimeControlPlaneCanon::STATUS_GREEN => ProgrammingConsoleCanon::STATUS_GREEN,
            ProgrammingRuntimeControlPlaneCanon::STATUS_PARTIAL => ProgrammingConsoleCanon::STATUS_PARTIAL,
            ProgrammingRuntimeControlPlaneCanon::STATUS_BLOCKED => ProgrammingConsoleCanon::STATUS_BLOCKED,
            default => ProgrammingConsoleCanon::STATUS_PARTIAL,
        };
    }

    /**
     * @param  array<int,array<string,mixed>>  $blockers
     * @return array<int,array<string,mixed>>
     */
    private function normalizeBlockers(array $blockers): array
    {
        $items = (array) ($blockers['items'] ?? $blockers);
        $out = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $out[] = [
                'source' => (string) ($item['source'] ?? 'unknown'),
                'severity' => (string) ($item['severity'] ?? 'warn'),
                'id' => (string) ($item['id'] ?? 'unknown'),
                'message' => (string) ($item['message'] ?? ''),
                'evidence_refs' => array_values((array) ($item['evidence_refs'] ?? [])),
                'remediation' => $item['remediation'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * @param  array<int,array<string,mixed>>  $actions
     * @return array<int,array<string,mixed>>
     */
    private function normalizeNextActions(array $actions): array
    {
        $out = [];
        foreach ($actions as $action) {
            if (! is_array($action)) {
                continue;
            }
            $out[] = [
                'priority' => (string) ($action['priority'] ?? 'normal'),
                'source' => (string) ($action['source'] ?? 'console'),
                'description' => (string) ($action['description'] ?? ''),
                'evidence_refs' => array_values((array) ($action['evidence_refs'] ?? [])),
            ];
        }

        return $out;
    }

    /**
     * @param  array<int,array<string,mixed>>  $readinessBlockers
     * @return array<int,array<string,mixed>>
     */
    private function normalizeReadinessBlockers(array $readinessBlockers): array
    {
        $out = [];
        foreach ($readinessBlockers as $blocker) {
            if (! is_array($blocker)) {
                continue;
            }
            $out[] = [
                'source' => 'readiness',
                'severity' => (string) ($blocker['severity'] ?? 'P2'),
                'id' => (string) ($blocker['id'] ?? 'unknown'),
                'message' => (string) ($blocker['detail'] ?? ''),
                'evidence_refs' => array_values((array) ($blocker['evidence'] ?? [])),
                'remediation' => $blocker['remediation'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * @param  array<int,mixed>  $readinessActions
     * @return array<int,array<string,mixed>>
     */
    private function normalizeReadinessNextActions(array $readinessActions): array
    {
        $out = [];
        foreach ($readinessActions as $action) {
            if (! is_string($action) || trim($action) === '') {
                continue;
            }
            $out[] = [
                'priority' => 'normal',
                'source' => 'readiness',
                'description' => trim($action),
                'evidence_refs' => [],
            ];
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @return array<int,string>
     */
    private function extractEvidenceFromSnapshot(array $snapshot): array
    {
        $refs = [];
        foreach ((array) ($snapshot['blockers']['items'] ?? []) as $item) {
            if (is_array($item) && isset($item['evidence_refs']) && is_array($item['evidence_refs'])) {
                foreach ($item['evidence_refs'] as $ref) {
                    if (is_string($ref) && $ref !== '') {
                        $refs[$ref] = true;
                    }
                }
            }
        }
        $cert = (array) ($snapshot['certification_summary'] ?? []);
        if (! empty($cert['temporal_certification']['id'])) {
            $refs['temporal_certification:'.$cert['temporal_certification']['id']] = true;
        }
        $keys = array_keys($refs);
        sort($keys);

        return $keys;
    }

    /**
     * @param  array<string,mixed>  $certification
     */
    private function deriveCertificationStatus(array $certification): string
    {
        $byStatus = (array) ($certification['mission_certifications_by_status'] ?? []);
        if ($byStatus === []) {
            $temporal = (array) ($certification['temporal_certification'] ?? []);
            $temporalStatus = (string) ($temporal['status'] ?? '');
            if ($temporalStatus === 'passed') {
                return ProgrammingConsoleCanon::CERTIFICATION_STATUS_PASSED;
            }
            if ($temporalStatus === 'blocked' || $temporalStatus === 'failed') {
                return ProgrammingConsoleCanon::CERTIFICATION_STATUS_BLOCKED;
            }

            return ProgrammingConsoleCanon::CERTIFICATION_STATUS_NOT_EVALUATED;
        }

        $passed = (int) ($byStatus['passed'] ?? 0);
        $failed = (int) ($byStatus['failed'] ?? 0);
        $blocked = (int) ($byStatus['blocked'] ?? 0);
        $total = (int) array_sum(array_map('intval', $byStatus));

        if ($total === 0) {
            return ProgrammingConsoleCanon::CERTIFICATION_STATUS_NOT_EVALUATED;
        }
        if ($blocked > 0) {
            return ProgrammingConsoleCanon::CERTIFICATION_STATUS_BLOCKED;
        }
        if ($failed > 0) {
            return ProgrammingConsoleCanon::CERTIFICATION_STATUS_FAILED;
        }
        if ($passed > 0 && $passed === $total) {
            return ProgrammingConsoleCanon::CERTIFICATION_STATUS_PASSED;
        }

        return ProgrammingConsoleCanon::CERTIFICATION_STATUS_PARTIAL;
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function summaryStatus(array $summary): string
    {
        $available = (bool) ($summary['available'] ?? false);
        if (! $available) {
            return ProgrammingConsoleCanon::STATUS_PARTIAL;
        }
        $count = (int) ($summary['count'] ?? 0);

        return $count > 0
            ? ProgrammingConsoleCanon::STATUS_GREEN
            : ProgrammingConsoleCanon::STATUS_PARTIAL;
    }

    /**
     * @param  array<string,mixed>  $completeness
     */
    private function evidenceStatus(array $completeness): string
    {
        $ratio = $completeness['evidence_present_ratio'] ?? null;
        if (! is_numeric($ratio)) {
            return ProgrammingConsoleCanon::STATUS_PARTIAL;
        }
        $ratio = (float) $ratio;
        if ($ratio >= 0.85) {
            return ProgrammingConsoleCanon::STATUS_GREEN;
        }

        return $ratio > 0
            ? ProgrammingConsoleCanon::STATUS_PARTIAL
            : ProgrammingConsoleCanon::STATUS_BLOCKED;
    }

    private function normalizeIntent(string $prompt): string
    {
        $lower = mb_strtolower(trim($prompt));
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $lower);
        if ($ascii === false) {
            $ascii = $lower;
        }

        return preg_replace('/\s+/u', ' ', $ascii) ?? $lower;
    }

    private function forgeTablesAvailable(): bool
    {
        try {
            return Schema::hasTable('ai_forge_intakes')
                && Schema::hasTable('ai_forge_work_packets')
                && Schema::hasTable('ai_forge_milestones');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function stringOption(array $options, string $key): ?string
    {
        $value = $options[$key] ?? null;
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function loadRecentPacks(?string $scopeType, ?string $scopeId, bool $available, int $limit = 5): array
    {
        if (! $available) {
            return [];
        }

        $query = AtlasLongHorizonContinuationPack::query()->orderByDesc('created_at')->limit($limit);
        if ($scopeType !== null) {
            $query->where('scope_type', $scopeType);
        }
        if ($scopeId !== null) {
            $query->where('scope_id', $scopeId);
        }

        return $query->get()->map(fn (AtlasLongHorizonContinuationPack $pack): array => [
            'id' => $pack->id,
            'scope_type' => $pack->scope_type,
            'scope_id' => $pack->scope_id,
            'safe_resume_mode' => $pack->safe_resume_mode,
            'pack_hash' => $pack->pack_hash,
            'stale_after' => $pack->stale_after?->toIso8601String(),
            'next_safe_action' => $pack->next_safe_action,
            'created_at' => $pack->created_at?->toIso8601String(),
        ])->all();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function loadRecentReceipts(?string $scopeType, ?string $scopeId, bool $available, int $limit = 5): array
    {
        if (! $available) {
            return [];
        }

        $query = AtlasLongHorizonCompactionReceipt::query()->orderByDesc('created_at')->limit($limit);
        if ($scopeType !== null) {
            $query->where('scope_type', $scopeType);
        }
        if ($scopeId !== null) {
            $query->where('scope_id', $scopeId);
        }

        return $query->get()->map(fn (AtlasLongHorizonCompactionReceipt $r): array => [
            'id' => $r->id,
            'scope_type' => $r->scope_type,
            'scope_id' => $r->scope_id,
            'loss_risk' => $r->loss_risk,
            'discarded_reason' => $r->discarded_reason,
            'must_keep_coverage' => $r->must_keep_coverage,
            'receipt_hash' => $r->receipt_hash,
            'created_at' => $r->created_at?->toIso8601String(),
        ])->all();
    }

    private function countPacksForScope(string $scopeType, string $scopeId): int
    {
        return AtlasLongHorizonContinuationPack::query()
            ->where('scope_type', $scopeType)
            ->where('scope_id', $scopeId)
            ->count();
    }

    private function countReceiptsForScope(string $scopeType, string $scopeId): int
    {
        return AtlasLongHorizonCompactionReceipt::query()
            ->where('scope_type', $scopeType)
            ->where('scope_id', $scopeId)
            ->count();
    }

    /**
     * @param  array<int,array<string,mixed>>  $packs
     * @return list<string>
     */
    private function packEvidenceRefs(array $packs): array
    {
        $refs = [];
        foreach ($packs as $pack) {
            if (! empty($pack['id'])) {
                $refs[] = 'continuation_pack:'.$pack['id'];
            }
        }

        return array_values(array_unique($refs));
    }

    /**
     * @return array<string,mixed>
     */
    private function certifyCheck(string $id, bool $ok, string $detail, string $severity = 'high'): array
    {
        return [
            'id' => $id,
            'status' => $ok ? 'passed' : 'blocked',
            'severity' => $severity,
            'detail' => $detail,
            'evidence_refs' => [],
        ];
    }
}
