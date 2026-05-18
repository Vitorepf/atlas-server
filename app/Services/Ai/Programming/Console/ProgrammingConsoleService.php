<?php

namespace App\Services\Ai\Programming\Console;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\Forge\ForgeIntakeCanon;
use App\Services\Ai\Programming\Forge\ForgeIntakeService;
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
}
