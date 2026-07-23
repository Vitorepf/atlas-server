<?php

namespace App\Services\Ai\SelfConstruction\Readiness;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Models\AtlasSelfConstructionAgentWakeupItem;
use Illuminate\Support\Facades\Schema;

/**
 * GOD-DEBULK extracted stateful agent-liveness family from AtlasSelfConstructionReadinessService.
 * Heartbeat / run-liveness / run-sync / wakeup-write / wakeup-scheduler write+read paths.
 * The mother is bound via setMother(); undefined calls (private mother helpers such as
 * stableHash / reservationActor / syncAgentRunFromReservation / agentControlPlaneRuntimeSchemaReady)
 * bridge back through __call -> ReflectionMethod so the bodies stay byte-identical.
 */
final class ReadinessProjectionAgentLivenessSection
{
    private ?AtlasSelfConstructionReadinessService $mother = null;

    public function setMother(AtlasSelfConstructionReadinessService $mother): self
    {
        $this->mother = $mother;

        return $this;
    }

    public function __call(string $name, array $arguments): mixed
    {
        if ($this->mother === null) {
            throw new \RuntimeException('ReadinessProjectionAgentLivenessSection mother not bound for '.$name);
        }
        $method = new \ReflectionMethod($this->mother, $name);

        return $method->invokeArgs($this->mother, $arguments);
    }

    public function agentRunSync(array $options = []): array
    {
        if (! $this->agentControlPlaneRuntimeSchemaReady()) {
            $sync = [
                'status' => 'blocked',
                'blocking_reasons' => ['agent_control_plane_runtime_schema_missing'],
                'required_migration' => 'database/migrations/2026_05_12_010000_create_atlas_self_construction_agent_control_plane_tables.php',
                'agent_runs_table_ready' => Schema::hasTable('atlas_self_construction_agent_runs'),
                'heartbeats_table_ready' => Schema::hasTable('atlas_self_construction_agent_heartbeats'),
            ];

            return [
                'schema_version' => 'atlas.self_construction_agent_run_sync.v1',
                'status' => 'blocked',
                'mode' => 'controlled_agent_run_sync',
                'execution_allowed' => false,
                'dispatch_allowed' => false,
                'ledger_write_allowed' => false,
                'runtime_write_allowed' => false,
                'sync' => $sync,
                'sync_hash' => $this->stableHash($sync),
                'human_summary' => 'Agent run sync is blocked until the Agent Control Plane runtime tables exist.',
            ];
        }

        $reservationStatus = $this->reservationStatus($options);
        $reservations = array_merge(
            (array) data_get($reservationStatus, 'ledger.active_reservations', []),
            (array) data_get($reservationStatus, 'ledger.completed_reservations', []),
        );

        $synced = array_map(fn (array $reservation): array => $this->syncAgentRunFromReservation($reservation), $reservations);
        $sync = [
            'status' => 'synced',
            'source_ledger_hash' => data_get($reservationStatus, 'ledger_hash'),
            'source_reservation_count' => count($reservations),
            'synced_run_count' => count($synced),
            'created_count' => count(array_filter($synced, fn (array $run): bool => (bool) ($run['created'] ?? false))),
            'updated_count' => count(array_filter($synced, fn (array $run): bool => ! (bool) ($run['created'] ?? false))),
            'runs' => $synced,
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_run_sync.v1',
            'status' => 'agent_run_sync_ready',
            'mode' => 'controlled_agent_run_sync',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => true,
            'sync' => $sync,
            'sync_hash' => $this->stableHash($sync),
            'non_execution_guarantees' => [
                'agent_run_sync_does_not_start_providers',
                'agent_run_sync_does_not_claim_packets',
                'agent_run_sync_does_not_dispatch_work',
                'agent_run_sync_does_not_enable_self_programming',
            ],
            'human_summary' => 'Agent run sync completed: reservation state was materialized into Agent Control Plane runtime runs without dispatching providers.',
        ];
    }

    public function agentHeartbeat(array $options = []): array
    {
        if (! $this->agentControlPlaneRuntimeSchemaReady()) {
            $heartbeat = [
                'status' => 'blocked',
                'blocking_reasons' => ['agent_control_plane_runtime_schema_missing'],
                'required_migration' => 'database/migrations/2026_05_12_010000_create_atlas_self_construction_agent_control_plane_tables.php',
            ];

            return [
                'schema_version' => 'atlas.self_construction_agent_heartbeat.v1',
                'status' => 'blocked',
                'mode' => 'controlled_agent_heartbeat',
                'execution_allowed' => false,
                'dispatch_allowed' => false,
                'ledger_write_allowed' => false,
                'runtime_write_allowed' => false,
                'heartbeat' => $heartbeat,
                'heartbeat_hash' => $this->stableHash($heartbeat),
                'human_summary' => 'Agent heartbeat is blocked until the Agent Control Plane runtime tables exist.',
            ];
        }

        $this->agentRunSync($options);

        $actor = $this->reservationActor($options);
        $session = $this->reservationSession($options);
        $packetId = trim((string) ($options['packet'] ?? ''));
        $query = AtlasSelfConstructionAgentRun::query()
            ->where('actor', $actor)
            ->where('session_id', $session);

        if ($packetId !== '') {
            $query->where('packet_id', $packetId);
        }

        $run = $query->latest('updated_at')->first();
        if (! $run instanceof AtlasSelfConstructionAgentRun) {
            $heartbeat = [
                'status' => 'blocked',
                'blocking_reasons' => ['agent_run_not_found_for_actor_session'],
                'actor' => $actor,
                'session' => $session,
                'packet_id' => $packetId ?: null,
            ];

            return [
                'schema_version' => 'atlas.self_construction_agent_heartbeat.v1',
                'status' => 'blocked',
                'mode' => 'controlled_agent_heartbeat',
                'execution_allowed' => false,
                'dispatch_allowed' => false,
                'ledger_write_allowed' => false,
                'runtime_write_allowed' => false,
                'heartbeat' => $heartbeat,
                'heartbeat_hash' => $this->stableHash($heartbeat),
                'human_summary' => 'Agent heartbeat is blocked because no synced run exists for this actor/session.',
            ];
        }

        $sequence = ((int) $run->heartbeats()->max('sequence')) + 1;
        $occurredAt = now();
        $heartbeatModel = $run->heartbeats()->create([
            'heartbeat_key' => 'HB-'.strtoupper(substr(hash('sha256', $run->id.'|'.$sequence.'|'.$occurredAt->toIso8601String()), 0, 24)),
            'sequence' => $sequence,
            'status' => 'alive',
            'signal' => (string) ($options['reason'] ?? 'heartbeat'),
            'occurred_at' => $occurredAt,
            'metadata' => [
                'packet_id' => $run->packet_id,
                'actor' => $run->actor,
                'session' => $run->session_id,
                'provider' => $run->provider,
            ],
        ]);
        $run->forceFill([
            'last_heartbeat_at' => $occurredAt,
            'liveness' => 'active_heartbeat',
            'status' => $run->status === 'queued' ? 'running' : $run->status,
        ])->save();

        $heartbeat = [
            'status' => 'recorded',
            'run_id' => $run->id,
            'run_key' => $run->run_key,
            'packet_id' => $run->packet_id,
            'actor' => $run->actor,
            'provider' => $run->provider,
            'session' => $run->session_id,
            'heartbeat_id' => $heartbeatModel->id,
            'heartbeat_key' => $heartbeatModel->heartbeat_key,
            'sequence' => $heartbeatModel->sequence,
            'occurred_at' => $heartbeatModel->occurred_at?->toIso8601String(),
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_heartbeat.v1',
            'status' => 'agent_heartbeat_recorded',
            'mode' => 'controlled_agent_heartbeat',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => true,
            'heartbeat' => $heartbeat,
            'heartbeat_hash' => $this->stableHash($heartbeat),
            'non_execution_guarantees' => [
                'agent_heartbeat_does_not_start_providers',
                'agent_heartbeat_does_not_claim_packets',
                'agent_heartbeat_does_not_dispatch_work',
            ],
            'human_summary' => 'Agent heartbeat was recorded for an existing synced run without dispatching or executing provider work.',
        ];
    }

    public function agentRunLiveness(array $options = []): array
    {
        if (! $this->agentControlPlaneRuntimeSchemaReady()) {
            $liveness = [
                'status' => 'blocked',
                'blocking_reasons' => ['agent_control_plane_runtime_schema_missing'],
                'required_migration' => 'database/migrations/2026_05_12_010000_create_atlas_self_construction_agent_control_plane_tables.php',
                'agent_runs_table_ready' => Schema::hasTable('atlas_self_construction_agent_runs'),
                'heartbeats_table_ready' => Schema::hasTable('atlas_self_construction_agent_heartbeats'),
            ];

            return [
                'schema_version' => 'atlas.self_construction_agent_run_liveness.v1',
                'status' => 'blocked',
                'mode' => 'read_only_agent_run_liveness_detector',
                'execution_allowed' => false,
                'dispatch_allowed' => false,
                'ledger_write_allowed' => false,
                'runtime_write_allowed' => false,
                'liveness' => $liveness,
                'liveness_hash' => $this->stableHash($liveness),
                'human_summary' => 'Agent run liveness is blocked until the Agent Control Plane runtime tables exist.',
            ];
        }

        $now = now();
        $nowTimestamp = $now->getTimestamp();
        $staleAfterSeconds = 900;
        $runs = AtlasSelfConstructionAgentRun::query()
            ->orderByDesc('updated_at')
            ->get()
            ->map(function (AtlasSelfConstructionAgentRun $run) use ($nowTimestamp, $staleAfterSeconds): array {
                $lastHeartbeatAt = $run->last_heartbeat_at;
                $leaseExpiresAt = $run->lease_expires_at;
                $heartbeatAgeSeconds = $lastHeartbeatAt ? max(0, $nowTimestamp - $lastHeartbeatAt->getTimestamp()) : null;
                $leaseSecondsRemaining = $leaseExpiresAt ? $leaseExpiresAt->getTimestamp() - $nowTimestamp : null;
                $terminal = in_array($run->status, ['succeeded', 'failed', 'cancelled', 'timed_out'], true);
                $derivedLiveness = match (true) {
                    $terminal => 'terminal',
                    $heartbeatAgeSeconds !== null && $heartbeatAgeSeconds <= $staleAfterSeconds => 'active_heartbeat',
                    $heartbeatAgeSeconds !== null => 'stale_heartbeat',
                    $leaseSecondsRemaining !== null && $leaseSecondsRemaining > 0 => 'active_lease_no_heartbeat',
                    $leaseSecondsRemaining !== null => 'expired_lease_no_heartbeat',
                    default => 'unknown_no_heartbeat',
                };
                $attentionNeeded = in_array($derivedLiveness, [
                    'stale_heartbeat',
                    'expired_lease_no_heartbeat',
                    'unknown_no_heartbeat',
                ], true);

                return [
                    'run_id' => $run->id,
                    'run_key' => $run->run_key,
                    'packet_id' => $run->packet_id,
                    'reservation_id' => $run->reservation_id,
                    'actor' => $run->actor,
                    'provider' => $run->provider,
                    'session' => $run->session_id,
                    'status' => $run->status,
                    'stored_liveness' => $run->liveness,
                    'derived_liveness' => $derivedLiveness,
                    'attention_needed' => $attentionNeeded,
                    'last_heartbeat_at' => $lastHeartbeatAt?->toIso8601String(),
                    'heartbeat_age_seconds' => $heartbeatAgeSeconds,
                    'lease_expires_at' => $leaseExpiresAt?->toIso8601String(),
                    'lease_seconds_remaining' => $leaseSecondsRemaining,
                    'next_required_action' => match ($derivedLiveness) {
                        'active_heartbeat', 'active_lease_no_heartbeat' => 'continue_monitoring',
                        'terminal' => 'ready_for_integration_or_archive',
                        'stale_heartbeat' => 'request_agent_status_or_release_packet',
                        'expired_lease_no_heartbeat' => 'release_or_reclaim_packet_after_review',
                        default => 'investigate_missing_runtime_signal',
                    },
                ];
            })
            ->values()
            ->all();

        $liveness = [
            'status' => 'inspected',
            'stale_after_seconds' => $staleAfterSeconds,
            'counts' => [
                'total' => count($runs),
                'active' => count(array_filter($runs, fn (array $run): bool => in_array($run['derived_liveness'], ['active_heartbeat', 'active_lease_no_heartbeat'], true))),
                'terminal' => count(array_filter($runs, fn (array $run): bool => $run['derived_liveness'] === 'terminal')),
                'attention_needed' => count(array_filter($runs, fn (array $run): bool => (bool) $run['attention_needed'])),
            ],
            'runs' => $runs,
            'policy' => [
                'detector_is_read_only' => true,
                'does_not_release_or_reclaim_packets' => true,
                'does_not_start_providers' => true,
                'state_writes_require_future_signed_policy' => true,
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_run_liveness.v1',
            'status' => 'agent_run_liveness_ready',
            'mode' => 'read_only_agent_run_liveness_detector',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'liveness' => $liveness,
            'liveness_hash' => $this->stableHash($liveness),
            'non_execution_guarantees' => [
                'agent_run_liveness_does_not_start_providers',
                'agent_run_liveness_does_not_claim_packets',
                'agent_run_liveness_does_not_release_packets',
                'agent_run_liveness_does_not_mutate_runtime_state',
            ],
            'human_summary' => 'Agent run liveness was inspected without dispatching providers or mutating packet/runtime state.',
        ];
    }

    public function agentRunLivenessWrite(array $options = []): array
    {
        if (! $this->agentControlPlaneRuntimeSchemaReady()) {
            $write = [
                'status' => 'blocked',
                'blocking_reasons' => ['agent_control_plane_runtime_schema_missing'],
                'required_migration' => $this->agentControlPlaneRuntimeSchemaMigration(),
                'tables' => $this->agentControlPlaneRuntimeTables(),
            ];

            return [
                'schema_version' => 'atlas.self_construction_agent_run_liveness_write.v1',
                'status' => 'blocked',
                'mode' => 'controlled_agent_run_liveness_state_writer',
                'execution_allowed' => false,
                'dispatch_allowed' => false,
                'ledger_write_allowed' => false,
                'runtime_write_allowed' => false,
                'liveness_write' => $write,
                'liveness_write_hash' => $this->stableHash($write),
                'human_summary' => 'Agent run liveness writer is blocked until the complete Agent Control Plane runtime schema exists.',
            ];
        }

        $livenessPayload = $this->agentRunLiveness($options);
        $runs = (array) data_get($livenessPayload, 'liveness.runs', []);
        $written = collect($runs)
            ->map(function (array $run): array {
                $runId = (string) data_get($run, 'run_id');
                $stored = (string) data_get($run, 'stored_liveness', 'unknown');
                $derived = (string) data_get($run, 'derived_liveness', 'unknown');
                $updated = false;

                if ($runId !== '' && $stored !== $derived) {
                    $updated = AtlasSelfConstructionAgentRun::query()
                        ->whereKey($runId)
                        ->update(['liveness' => $derived]) === 1;
                }

                return [
                    'run_id' => $runId,
                    'run_key' => data_get($run, 'run_key'),
                    'packet_id' => data_get($run, 'packet_id'),
                    'actor' => data_get($run, 'actor'),
                    'provider' => data_get($run, 'provider'),
                    'session' => data_get($run, 'session'),
                    'previous_liveness' => $stored,
                    'written_liveness' => $derived,
                    'updated' => $updated,
                    'attention_needed' => (bool) data_get($run, 'attention_needed'),
                ];
            })
            ->values()
            ->all();

        $write = [
            'status' => 'written',
            'source_liveness_hash' => data_get($livenessPayload, 'liveness_hash'),
            'counts' => [
                'inspected' => count($written),
                'updated' => count(array_filter($written, fn (array $run): bool => (bool) ($run['updated'] ?? false))),
                'unchanged' => count(array_filter($written, fn (array $run): bool => ! (bool) ($run['updated'] ?? false))),
                'attention_needed' => count(array_filter($written, fn (array $run): bool => (bool) ($run['attention_needed'] ?? false))),
            ],
            'runs' => $written,
            'policy' => [
                'writer_only_updates_agent_run_liveness' => true,
                'does_not_start_providers' => true,
                'does_not_claim_or_release_packets' => true,
                'does_not_write_ledger_events' => true,
                'does_not_mark_dispatch_receipts_used' => true,
                'does_not_enable_self_programming' => true,
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_run_liveness_write.v1',
            'status' => 'agent_run_liveness_write_ready',
            'mode' => 'controlled_agent_run_liveness_state_writer',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => true,
            'liveness_write' => $write,
            'liveness_write_hash' => $this->stableHash($write),
            'non_execution_guarantees' => [
                'agent_run_liveness_write_does_not_start_providers',
                'agent_run_liveness_write_does_not_claim_packets',
                'agent_run_liveness_write_does_not_release_packets',
                'agent_run_liveness_write_does_not_write_ledger',
                'agent_run_liveness_write_does_not_enable_self_programming',
            ],
            'human_summary' => 'Agent run liveness writer materialized derived liveness into Agent Control Plane runtime runs without dispatching providers or mutating packet state.',
        ];
    }

    public function agentWakeupWrite(array $options = []): array
    {
        if (! $this->agentControlPlaneRuntimeSchemaReady()
            || ! Schema::hasTable('atlas_self_construction_agent_wakeup_items')) {
            $write = [
                'status' => 'blocked',
                'blocking_reasons' => ['agent_control_plane_wakeup_runtime_schema_missing'],
                'required_migration' => 'database/migrations/2026_05_12_010000_create_atlas_self_construction_agent_control_plane_tables.php',
                'agent_runs_table_ready' => Schema::hasTable('atlas_self_construction_agent_runs'),
                'heartbeats_table_ready' => Schema::hasTable('atlas_self_construction_agent_heartbeats'),
                'wakeup_items_table_ready' => Schema::hasTable('atlas_self_construction_agent_wakeup_items'),
            ];

            return [
                'schema_version' => 'atlas.self_construction_agent_wakeup_write.v1',
                'status' => 'blocked',
                'mode' => 'controlled_agent_wakeup_writer',
                'execution_allowed' => false,
                'dispatch_allowed' => false,
                'ledger_write_allowed' => false,
                'runtime_write_allowed' => false,
                'wakeup_write' => $write,
                'wakeup_write_hash' => $this->stableHash($write),
                'human_summary' => 'Agent wakeup writer is blocked until Agent Control Plane runtime and wakeup tables exist.',
            ];
        }

        $queuePayload = $this->agentWakeupQueue($options);
        $projectedItems = (array) data_get($queuePayload, 'wakeup_queue.projected_items', []);
        $written = collect($projectedItems)
            ->map(function (array $item): array {
                $runId = (string) data_get($item, 'run_id');
                $reason = (string) data_get($item, 'reason', 'runtime_signal_investigation');
                $wakeupKey = 'WAKEUP-'.strtoupper(substr(hash('sha256', $runId.'|'.$reason), 0, 24));
                $model = AtlasSelfConstructionAgentWakeupItem::query()->updateOrCreate(
                    ['wakeup_key' => $wakeupKey],
                    [
                        'agent_run_id' => $runId ?: null,
                        'packet_id' => data_get($item, 'packet_id'),
                        'actor' => data_get($item, 'actor'),
                        'provider' => data_get($item, 'provider'),
                        'reason' => $reason,
                        'priority' => (string) data_get($item, 'priority', 'normal'),
                        'status' => 'queued',
                        'scheduled_for' => now(),
                        'payload' => [
                            'source' => 'agent_wakeup_queue_projection',
                            'source_liveness' => data_get($item, 'source_liveness'),
                            'next_required_action' => data_get($item, 'next_required_action'),
                            'dispatch_allowed' => false,
                        ],
                    ],
                );

                return [
                    'wakeup_item_id' => $model->id,
                    'wakeup_key' => $model->wakeup_key,
                    'run_id' => $model->agent_run_id,
                    'packet_id' => $model->packet_id,
                    'actor' => $model->actor,
                    'provider' => $model->provider,
                    'reason' => $model->reason,
                    'priority' => $model->priority,
                    'status' => $model->status,
                    'created' => $model->wasRecentlyCreated,
                ];
            })
            ->values()
            ->all();

        $write = [
            'status' => 'written',
            'projected_count' => count($projectedItems),
            'written_count' => count($written),
            'created_count' => count(array_filter($written, fn (array $item): bool => (bool) ($item['created'] ?? false))),
            'updated_count' => count(array_filter($written, fn (array $item): bool => ! (bool) ($item['created'] ?? false))),
            'items' => $written,
            'policy' => [
                'writer_does_not_schedule_jobs' => true,
                'writer_does_not_start_providers' => true,
                'writer_does_not_claim_or_release_packets' => true,
                'scheduler_requires_future_signed_policy' => true,
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_wakeup_write.v1',
            'status' => 'agent_wakeup_write_ready',
            'mode' => 'controlled_agent_wakeup_writer',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => true,
            'wakeup_write' => $write,
            'wakeup_write_hash' => $this->stableHash($write),
            'non_execution_guarantees' => [
                'agent_wakeup_write_does_not_start_providers',
                'agent_wakeup_write_does_not_claim_packets',
                'agent_wakeup_write_does_not_release_packets',
                'agent_wakeup_write_does_not_schedule_jobs',
            ],
            'human_summary' => 'Agent wakeup writer materialized wakeup items from liveness projection without dispatching providers or scheduling jobs.',
        ];
    }

    public function agentWakeupScheduler(array $options = []): array
    {
        if (! Schema::hasTable('atlas_self_construction_agent_wakeup_items')) {
            $scheduler = [
                'status' => 'blocked',
                'blocking_reasons' => ['agent_control_plane_wakeup_queue_schema_missing'],
                'required_migration' => 'database/migrations/2026_05_12_010000_create_atlas_self_construction_agent_control_plane_tables.php',
                'wakeup_items_table_ready' => false,
            ];

            return [
                'schema_version' => 'atlas.self_construction_agent_wakeup_scheduler.v1',
                'status' => 'blocked',
                'mode' => 'read_only_agent_wakeup_scheduler',
                'execution_allowed' => false,
                'dispatch_allowed' => false,
                'ledger_write_allowed' => false,
                'runtime_write_allowed' => false,
                'wakeup_scheduler' => $scheduler,
                'wakeup_scheduler_hash' => $this->stableHash($scheduler),
                'human_summary' => 'Agent wakeup scheduler is blocked until the Agent Control Plane wakeup queue table exists.',
            ];
        }

        $now = now();
        $readyItems = AtlasSelfConstructionAgentWakeupItem::query()
            ->where('status', 'queued')
            ->where(function ($query) use ($now): void {
                $query->whereNull('scheduled_for')
                    ->orWhere('scheduled_for', '<=', $now);
            })
            ->orderByRaw("case priority when 'high' then 0 when 'medium' then 1 when 'normal' then 2 else 3 end")
            ->orderBy('scheduled_for')
            ->limit(10)
            ->get()
            ->map(fn (AtlasSelfConstructionAgentWakeupItem $item): array => [
                'wakeup_item_id' => $item->id,
                'wakeup_key' => $item->wakeup_key,
                'run_id' => $item->agent_run_id,
                'packet_id' => $item->packet_id,
                'actor' => $item->actor,
                'provider' => $item->provider,
                'reason' => $item->reason,
                'priority' => $item->priority,
                'scheduled_for' => $item->scheduled_for?->toIso8601String(),
                'resume_envelope' => [
                    'wakeup_key' => $item->wakeup_key,
                    'run_id' => $item->agent_run_id,
                    'packet_id' => $item->packet_id,
                    'actor' => $item->actor,
                    'provider' => $item->provider,
                    'reason' => $item->reason,
                    'required_first_command' => 'php artisan atlas:ai:self-construction --agent-control-plane --json',
                    'required_follow_up_command' => $item->packet_id
                        ? 'php artisan atlas:ai:self-construction --agent-start-packet --actor='.($item->actor ?: '<actor>').' --session=<session> --packet='.$item->packet_id.' --json'
                        : 'php artisan atlas:ai:self-construction --packet-queue --json',
                ],
            ])
            ->values()
            ->all();

        $scheduler = [
            'status' => 'selected',
            'counts' => [
                'ready_items' => count($readyItems),
            ],
            'ready_items' => $readyItems,
            'policy' => [
                'scheduler_is_read_only' => true,
                'does_not_claim_wakeup_items' => true,
                'does_not_start_providers' => true,
                'does_not_claim_or_release_packets' => true,
                'scheduler_claims_require_future_signed_policy' => true,
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_wakeup_scheduler.v1',
            'status' => 'agent_wakeup_scheduler_ready',
            'mode' => 'read_only_agent_wakeup_scheduler',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'wakeup_scheduler' => $scheduler,
            'wakeup_scheduler_hash' => $this->stableHash($scheduler),
            'non_execution_guarantees' => [
                'agent_wakeup_scheduler_does_not_start_providers',
                'agent_wakeup_scheduler_does_not_claim_wakeup_items',
                'agent_wakeup_scheduler_does_not_claim_packets',
                'agent_wakeup_scheduler_does_not_release_packets',
            ],
            'human_summary' => 'Agent wakeup scheduler selected ready wakeup items without claiming them, dispatching providers or mutating packet state.',
        ];
    }

}
