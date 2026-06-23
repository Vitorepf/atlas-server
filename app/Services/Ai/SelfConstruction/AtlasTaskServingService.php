<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;

/**
 * PART 2 · A7 — THE CONTRACT (the heart): "the Atlas OFFERS the tasks".
 *
 * Two verbs, ONE fixed JSON schema, platform-FREE. Any AI with a meta/loop calls `next` to PULL a
 * self-sufficient task packet and `report` to hand back the result; the Atlas plans, the harness implements.
 *
 * HARNESS-AGNOSTIC BY CONSTRUCTION (not a grep-test): the only client parameter is an OPAQUE `client_id`
 * string. It is forwarded verbatim as the claim agent id and ECHOED back — never parsed, never branched on a
 * platform (no `if cursor/codex/claude`). Inbound filters are WHITELISTED to a neutral set, so a client can
 * never smuggle an engine-typed field that changes serving. The type signature IS the proof.
 *
 * MASTER-SWITCH GATED: with the loop master switch OFF, `next`/`report` are inert (a `disabled` envelope) —
 * the serving surface never dispatches while the loop is off, and the loop never reanimates itself.
 *
 * It builds on the HARDENED canonical Stack A ({@see AgentControlPlaneTaskQueueOrchestrator}): the claim is
 * flock-atomic + CAS single-winner (A1/A2), conflict-free prefix-aware (A4/A5), with dead-agent pre-sweep
 * (A3). So two distinct clients calling `next` concurrently receive DISJOINT packets.
 */
final class AtlasTaskServingService
{
    public const ENVELOPE_SCHEMA = 'atlas.task_serving.envelope.v1';

    public const REPORT_SCHEMA = 'atlas.task_serving.report.v1';

    public const DEFAULT_RETRY_AFTER_SECONDS = 30;

    /** The ONLY client-supplied filters honoured — neutral, never engine-typed. */
    private const ALLOWED_FILTER_KEYS = ['tags', 'ttl_seconds'];

    public function __construct(private readonly AgentControlPlaneTaskQueueOrchestrator $orchestrator) {}

    /**
     * PULL the next claimable task for an opaque client. Atomic claim on the canonical stack; a self-sufficient
     * TaskEnvelope when served, an honest `no_claimable_task` (with escalation) when the queue is dry.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function next(string $clientId, array $filters = []): array
    {
        if (! AtlasLoopMasterSwitch::enabled()) {
            return $this->envelope('disabled', $clientId, null, ['reason' => 'loop_master_switch_off']);
        }
        $clientId = trim($clientId);
        if ($clientId === '') {
            return $this->envelope('invalid_client', '', null, ['reason' => 'client_id_required']);
        }

        $claim = $this->orchestrator->claimNext($clientId, $this->safeFilters($filters));

        if ((string) ($claim['event'] ?? '') !== 'claimed') {
            // Honest empty: NOT an error. The queue is dry; the brain must originate (model-bound — see R1).
            return $this->envelope('no_claimable_task', $clientId, null, [
                'retry_after_seconds' => self::DEFAULT_RETRY_AFTER_SECONDS,
                'escalation' => 'needs_brain_origination',
                'candidate_count' => (int) ($claim['candidate_count'] ?? 0),
            ]);
        }

        return $this->envelope('served', $clientId, $this->projectTask($claim), []);
    }

    /**
     * REPORT the outcome of a served task and close/release the lease. `outcome=success` runs the dry-run
     * completion gate (evidence-validated); `failed`/`give_back` releases the lease so the task returns to
     * claimable. Real merge is NOT performed here (gated, separate obra — see B3).
     *
     * @param  array<string, mixed>  $payload  {outcome?:success|failed|give_back, evidence?:array}
     * @return array<string, mixed>
     */
    public function report(string $clientId, string $taskPacketId, string $leaseId, array $payload = []): array
    {
        if (! AtlasLoopMasterSwitch::enabled()) {
            return $this->reportEnvelope('disabled', $clientId, ['reason' => 'loop_master_switch_off']);
        }
        $clientId = trim($clientId);
        if ($clientId === '' || $taskPacketId === '' || $leaseId === '') {
            return $this->reportEnvelope('invalid_report', $clientId, ['reason' => 'client_id_task_packet_id_and_lease_id_required']);
        }

        $outcome = (string) ($payload['outcome'] ?? 'success');

        if ($outcome === 'success') {
            $result = $this->orchestrator->completeDryRun($taskPacketId, $leaseId, (array) ($payload['evidence'] ?? []));
            $event = (string) ($result['event'] ?? '');
            $closed = str_contains($event, 'completed') && ! str_contains($event, 'blocked');

            return $this->reportEnvelope('reported', $clientId, [
                'outcome' => 'success',
                'lease_closed' => $closed,
                'task_packet_id' => $taskPacketId,
                'lease_id' => $leaseId,
                'orchestrator_event' => $event,
                'result' => $result,
            ]);
        }

        // failed / give_back => release the lease; the reaper/CAS guarantees the task is reclaimable.
        $result = $this->orchestrator->releaseLease($leaseId, $clientId, ['reason' => 'client_reported_'.$outcome]);

        return $this->reportEnvelope('reported', $clientId, [
            'outcome' => $outcome,
            'lease_released' => (string) data_get($result, 'release.status', '') === 'ok',
            'task_packet_id' => $taskPacketId,
            'lease_id' => $leaseId,
            'result' => $result,
        ]);
    }

    /** Whitelist filters to a neutral set so a client can never inject an engine-typed/platform field. */
    private function safeFilters(array $filters): array
    {
        $safe = [];
        foreach (self::ALLOWED_FILTER_KEYS as $key) {
            if (array_key_exists($key, $filters)) {
                $safe[$key] = $filters[$key];
            }
        }

        return $safe;
    }

    /**
     * Project the claimed orchestrator result into the FIXED, self-sufficient task block.
     *
     * @param  array<string, mixed>  $claim
     * @return array<string, mixed>
     */
    private function projectTask(array $claim): array
    {
        $packet = (array) data_get($claim, 'queue_entry.task_packet', []);

        return [
            'task_packet_id' => (string) ($claim['task_packet_id'] ?? data_get($packet, 'task_packet_id', '')),
            'lease_id' => (string) ($claim['lease_id'] ?? ''),
            'lease_expires_at' => (string) data_get($claim, 'lease.expires_at', ''),
            'objective' => (string) data_get($packet, 'objective', ''),
            'allowed_files' => array_values((array) data_get($packet, 'normalized_scope.allowed_files', data_get($packet, 'allowed_files', []))),
            'forbidden_files' => array_values((array) data_get($packet, 'normalized_scope.forbidden_files', data_get($packet, 'forbidden_files', []))),
            'scope_in' => array_values((array) data_get($packet, 'normalized_scope.scope_in', data_get($packet, 'scope_in', []))),
            'acceptance_criteria' => array_values((array) data_get($packet, 'acceptance_criteria', [])),
            'required_evidence' => array_values((array) data_get($packet, 'required_evidence', [])),
            'risk_level' => (string) data_get($packet, 'risk_level', 'unspecified'),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $task
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function envelope(string $status, string $clientId, ?array $task, array $extra): array
    {
        return array_merge([
            'schema' => self::ENVELOPE_SCHEMA,
            'status' => $status,                 // served | no_claimable_task | disabled | invalid_client
            'client_id' => $clientId,            // echoed verbatim — NEVER interpreted
            'task' => $task,
            'retry_after_seconds' => 0,
            'escalation' => null,
        ], $extra);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function reportEnvelope(string $status, string $clientId, array $extra): array
    {
        return array_merge([
            'schema' => self::REPORT_SCHEMA,
            'status' => $status,                 // reported | disabled | invalid_report
            'client_id' => $clientId,
        ], $extra);
    }
}
