<?php

namespace App\Services\Ai\SelfConstruction\ControlPlane;

use App\Services\Ai\SelfConstruction\Replenishment\AgentControlPlaneCompletionAuditReader;
use App\Services\Ai\SelfConstruction\Replenishment\AgentControlPlaneReplenishmentStableHasher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use App\Services\Ai\SelfConstruction\Concerns\RecursivelyKsortsArrays;

/**
 * Replenishes the persistent Agent Control Plane task queue from governed
 * sources. It creates only local task packets; workers still need an explicit
 * claim/lease before doing any work.
 *
 * Runtime-safe: never starts processes, never calls Codex CLI/app, never
 * spawns subprocesses, never invokes adapters, never dispatches work,
 * never spends tokens, never advances the next required slice, never
 * enables self-programming and never writes the evidence ledger.
 */
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueOrchestrator;

final class AgentControlPlaneTaskAutoReplenishmentService
{
    use RecursivelyKsortsArrays;
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_task_auto_replenishment.v1';

    public const MODE = 'persistent_local_agent_control_plane_task_auto_replenishment';

    /** Terminal statuses that block a seed from being reissued unless allow_terminal_reissue=true. */
    private const TERMINAL_BLOCKING_STATUSES = ['completed_dry_run', 'cancelled'];

    public function __construct(
        private readonly AgentControlPlaneTaskQueueOrchestrator $orchestrator,
        private readonly AgentControlPlaneTaskPacketQueueRepository $queue,
        // ITEM8 — optional constructor-injected hasher (defaulted to null so the existing 2-arg
        // call signature is preserved byte-for-byte). When null, the service lazy-instantiates a
        // fresh hasher on first use. This is the thread-safe default-to-fresh-instance pattern the
        // task requires.
        private readonly ?AgentControlPlaneReplenishmentStableHasher $stableHasher = null,
        // ITEM8 — optional constructor-injected completion-audit reader (same default-to-fresh pattern).
        private readonly ?AgentControlPlaneCompletionAuditReader $completionAuditReader = null,
    ) {}

    /**
     * ITEM8 — lazy hasher accessor: returns the constructor-injected hasher, or instantiates a
     * fresh one on first use when none was supplied. Keeps the byte-identical call-site contract
     * at lines 153, 160, 321, 591 (and any private callers) untouched.
     */
    private function stableHasher(): AgentControlPlaneReplenishmentStableHasher
    {
        return $this->stableHasher ?? new AgentControlPlaneReplenishmentStableHasher;
    }

    /**
     * ITEM8 — lazy completion-audit reader accessor: same default-to-fresh pattern. Keeps the
     * byte-identical call-site contract at lines 386, 398, 417, 422, 423, 512, 524, 656 untouched.
     */
    private function completionAuditReader(): AgentControlPlaneCompletionAuditReader
    {
        return $this->completionAuditReader ?? new AgentControlPlaneCompletionAuditReader;
    }

    public const DEFAULT_WORKER_FEED_RISK_MIN_CLAIMABLE_PER_WORKER = 2.0;

    public const DEFAULT_WORKER_FEED_RISK_BATCH_CAP = 10;

    public const REASON_WORKER_FEED_RISK = 'worker_feed_risk';

    public const FEED_RISK_REASON_CLAIMABLE_PER_WORKER_BELOW_FLOOR = 'claimable_per_worker_below_floor';

    public const FEED_RISK_REASON_REPLENISH_RECOMMENDATION_SOON = 'replenish_recommendation_soon';

    /**
     * Pure worker-feed-risk evaluator: decides whether automatic replenishment should start
     * BEFORE the queue actually hits no_claimable_task, using leading indicators — active_leases,
     * claimable_depth, claimable_per_active_worker, replenish_recommendation — rather than waiting
     * for the lagging dry_queue signal. Does not touch the queue, the DB, or any I/O; this is a
     * standalone bounded-plan computation a caller can act on before invoking replenish().
     *
     * Triggers (either is sufficient) when active_leases > 0:
     *   - claimable_per_active_worker <= min_claimable_per_worker (default 2.0), explicit or
     *     derived as claimable_depth / active_leases when not supplied;
     *   - replenish_recommendation === 'replenish_soon' (explicit leading-indicator signal).
     *
     * With no active workers (active_leases === 0) or a comfortable buffer (neither trigger
     * fires), this is a no-op — preserving existing dry_queue-only behavior for those cases.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function evaluateWorkerFeedRisk(array $context = []): array
    {
        $activeLeases = max(0, (int) ($context['active_leases'] ?? 0));
        $claimableDepth = max(0, (int) ($context['claimable_depth'] ?? 0));
        $minClaimablePerWorker = (float) ($context['min_claimable_per_worker'] ?? self::DEFAULT_WORKER_FEED_RISK_MIN_CLAIMABLE_PER_WORKER);
        $batchCap = max(1, (int) ($context['batch_cap'] ?? self::DEFAULT_WORKER_FEED_RISK_BATCH_CAP));
        $recommendation = (string) ($context['replenish_recommendation'] ?? '');

        $claimablePerActiveWorker = array_key_exists('claimable_per_active_worker', $context) && $context['claimable_per_active_worker'] !== null
            ? (float) $context['claimable_per_active_worker']
            : ($activeLeases > 0 ? (float) $claimableDepth / $activeLeases : null);

        $belowFloor = $activeLeases > 0 && $claimablePerActiveWorker !== null && $claimablePerActiveWorker <= $minClaimablePerWorker;
        $recommendationSoon = $activeLeases > 0 && $recommendation === 'replenish_soon';
        $triggered = $belowFloor || $recommendationSoon;

        // feed_risk_reasons is the granular, possibly-multi-cause breakdown of WHY top-up fired
        // (a single evaluation can trip both triggers at once); `reason` stays the existing
        // single constant for backward compatibility with callers pinned to it.
        $feedRiskReasons = array_values(array_filter([
            $belowFloor ? self::FEED_RISK_REASON_CLAIMABLE_PER_WORKER_BELOW_FLOOR : null,
            $recommendationSoon ? self::FEED_RISK_REASON_REPLENISH_RECOMMENDATION_SOON : null,
        ]));

        if (! $triggered) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'top_up_required' => false,
                'target_new_packets' => 0,
                'reason' => null,
                'feed_risk_reasons' => [],
                'active_leases' => $activeLeases,
                'claimable_depth' => $claimableDepth,
                'claimable_per_active_worker' => $claimablePerActiveWorker,
            ];
        }

        // Plan enough packets to restore the minimum claimable-per-active-worker buffer —
        // not a token 1-packet top-up. A claimable_depth that already exceeds active_leases
        // (e.g. depth=20 with 7 leases) can still be a worker-feed risk when the buffer per
        // worker is thin (claimable_per_active_worker <= min_claimable_per_worker); the gap
        // to close is against the FLOOR (min_claimable_per_worker * active_leases), not
        // against raw lease count.
        $desiredClaimable = (int) ceil($minClaimablePerWorker * $activeLeases);
        $need = max(1, $desiredClaimable - $claimableDepth);
        $targetNewPackets = min($batchCap, $need);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'top_up_required' => true,
            'target_new_packets' => $targetNewPackets,
            'reason' => self::REASON_WORKER_FEED_RISK,
            'feed_risk_reasons' => $feedRiskReasons,
            'active_leases' => $activeLeases,
            'claimable_depth' => $claimableDepth,
            'claimable_per_active_worker' => $claimablePerActiveWorker,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function replenish(array $context = [], array $options = []): array
    {
        $targetMinClaimable = max(1, min(25, (int) ($options['target_min_claimable_tasks'] ?? 3)));
        $maxNewTasks = max(0, min(25, (int) ($options['max_new_tasks'] ?? $targetMinClaimable)));
        $actor = trim((string) ($options['actor'] ?? 'agent-control-plane-auto-replenishment'));
        $reason = trim((string) ($options['reason'] ?? 'claimable_queue_below_target'));
        $queueTags = $this->stringList((array) ($options['queue_tags'] ?? []));

        $registryBefore = $this->queue->registry();
        $claimableBefore = $queueTags === []
            ? (int) data_get($registryBefore, 'status_counts.claimable', 0)
            : $this->countClaimableWithTags($queueTags);
        $totalBefore = (int) data_get($registryBefore, 'total_count', 0);
        $existingSeedIndex = $this->existingAutoReplenishmentSeedIndex($queueTags);
        $allowTerminalReissue = (bool) ($options['allow_terminal_reissue'] ?? false);

        $sources = $this->sources($context);
        $rawPlan = $this->plan($sources, $targetMinClaimable, $maxNewTasks, $claimableBefore, $totalBefore);
        $planEvaluation = $this->evaluatePlan($rawPlan, $existingSeedIndex, $allowTerminalReissue);
        $planEvaluation = $this->withCompletionOperatorHandoffSeeds($planEvaluation, $sources);
        $plan = (array) $planEvaluation['accepted_seeds'];
        $operatorHandoffSeeds = (array) $planEvaluation['operator_handoff_seeds'];
        $generated = [];
        $skipped = [];

        foreach ($plan as $seed) {
            $taskPacket = $this->taskPacketFromSeed($seed, $actor);
            $result = $this->orchestrator->prepareAndEnqueue([
                'task_packet' => $taskPacket,
                'queue' => [
                    'priority' => (int) ($seed['priority'] ?? 5),
                    'tags' => array_values(array_unique(array_merge([
                        'atlas_self_construction_os',
                        'agent_control_plane',
                        'auto_replenished',
                    ], (array) ($seed['tags'] ?? []), $queueTags))),
                    'metadata' => [
                        'auto_replenishment_reason' => $reason,
                        'auto_replenishment_source' => (string) ($seed['source'] ?? 'unknown'),
                    ],
                ],
            ]);

            $queueEvent = (string) data_get($result, 'queue_entry.event', '');
            $entry = [
                'seed_key' => (string) ($seed['seed_key'] ?? ''),
                'task_packet_id' => (string) data_get($result, 'task_packet.task_packet_id', $taskPacket['task_packet_id']),
                'task_packet_hash' => (string) data_get($result, 'task_packet.task_packet_hash', ''),
                'event' => (string) ($result['event'] ?? 'unknown'),
                'queue_event' => $queueEvent,
                'source' => (string) ($seed['source'] ?? 'unknown'),
                'reference' => (string) ($seed['reference'] ?? ''),
            ];

            if ((string) ($result['event'] ?? '') === 'prepared_and_enqueued' && $queueEvent === 'enqueued') {
                $generated[] = $entry;
            } else {
                $skipped[] = $entry;
            }
        }

        $registryAfter = $this->queue->registry();
        $claimableAfter = $queueTags === []
            ? (int) data_get($registryAfter, 'status_counts.claimable', 0)
            : $this->countClaimableWithTags($queueTags);
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'event' => 'auto_replenishment_completed',
            'status' => $claimableAfter >= $targetMinClaimable ? 'available' : ($generated !== [] ? 'available' : 'blocked'),
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'actor' => $actor,
            'reason' => $reason,
            'target_min_claimable_tasks' => $targetMinClaimable,
            'max_new_tasks' => $maxNewTasks,
            'queue_tags' => $queueTags,
            'claimable_task_count_before' => $claimableBefore,
            'claimable_task_count_after' => $claimableAfter,
            'generated_task_count' => count($generated),
            'skipped_existing_task_count' => count($skipped),
            'skipped_duplicate_seed_count' => (int) $planEvaluation['skipped_duplicate_seed_count'],
            'operator_handoff_seed_count' => count($operatorHandoffSeeds),
            'active_seed_count' => count((array) $existingSeedIndex['active_seed_keys']),
            'sources' => $sources,
            'source_catalog' => $this->sourceCatalog(),
            'replenishment_loop_contract' => $this->replenishmentLoopContract($targetMinClaimable, $maxNewTasks),
            'plan_evaluation' => $planEvaluation,
            'generated_tasks' => $generated,
            'skipped_tasks' => $skipped,
            'operator_handoff_tasks' => $operatorHandoffSeeds,
            'blockers' => $this->blockers(
                $claimableAfter,
                $targetMinClaimable,
                $maxNewTasks,
                (int) $planEvaluation['skipped_duplicate_seed_count'],
                $rawPlan !== [],
                $plan === [],
            ),
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
            'completion_real_allowed' => false,
            'non_execution_guarantees' => [
                'task_auto_replenishment_does_not_start_codex',
                'task_auto_replenishment_does_not_call_codex_cli_or_app',
                'task_auto_replenishment_does_not_spawn_subprocess',
                'task_auto_replenishment_does_not_invoke_adapter',
                'task_auto_replenishment_does_not_call_provider',
                'task_auto_replenishment_does_not_dispatch_work',
                'task_auto_replenishment_does_not_spend_tokens',
                'task_auto_replenishment_does_not_enable_self_programming',
                'task_auto_replenishment_does_not_write_ledger',
                'task_auto_replenishment_does_not_mutate_pointer',
                'task_auto_replenishment_does_not_mark_real_completion',
                'task_auto_replenishment_does_not_assign_operator_only_blockers_to_workers',
            ],
        ];
        $payload['replenishment_plan_hash'] = $this->stableHash([
            'sources' => $sources,
            'plan' => $plan,
            'plan_evaluation' => $planEvaluation,
            'target_min_claimable_tasks' => $targetMinClaimable,
            'max_new_tasks' => $maxNewTasks,
        ]);
        $payload['auto_replenishment_hash'] = $this->stableHash($this->normalizeForHash($payload));

        return $payload;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sourceCatalog(): array
    {
        return [
            [
                'source' => 'terminal_bootstrap_probe',
                'priority' => 1,
                'purpose' => 'keep terminal worker bootstrap probes lane-isolated and claimable for multi-agent loop certification',
                'stop_when' => 'probe target is satisfied or every probe seed is already active in the selected queue lane',
            ],
            [
                'source' => 'current_pointer',
                'priority' => 1,
                'purpose' => 'turn persistent_runtime.next_required_slice into the next governed implementation packet',
                'stop_when' => 'the pointer seed is already active or the pointer is missing',
            ],
            [
                'source' => 'completion_audit',
                'priority' => 2,
                'purpose' => 'turn explicit completion audit blockers into scoped closure packets',
                'stop_when' => 'blocker seeds are already active, closed by evidence, or absent from the supplied audit context',
            ],
            [
                'source' => 'not_yet_runtime_capable',
                'priority' => 3,
                'purpose' => 'turn control-plane runtime capability gaps into bounded graduation or closure packets',
                'stop_when' => 'runtime gap seeds are already active or no runtime gaps are exposed by the control plane',
            ],
            [
                'source' => 'chain_integrity',
                'priority' => 2,
                'purpose' => 'turn structural chain violations into repair packets',
                'stop_when' => 'chain integrity is clean or the first violation seed is already active',
            ],
            [
                'source' => 'canonical_contract',
                'priority' => 4,
                'purpose' => 'ensure contract/docs guardrails are available when implementation changes behavior or CLI surface',
                'stop_when' => 'docs guardrail seed is already active',
            ],
            [
                'source' => 'test_guardrail',
                'priority' => 4,
                'purpose' => 'ensure focused regression tests remain represented in the queue',
                'stop_when' => 'test guardrail seed is already active',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function replenishmentLoopContract(int $targetMinClaimable, int $maxNewTasks): array
    {
        return [
            'schema_version' => 'atlas.self_construction.agent_control_plane_task_auto_replenishment_loop_contract.v1',
            'target_min_claimable_tasks' => $targetMinClaimable,
            'max_new_tasks_per_replenishment' => $maxNewTasks,
            'dedupe_key' => 'task_packet.continuation_context.auto_replenishment_seed_key scoped by queue_tags/lane',
            'active_statuses_blocking_duplicate_seed' => $this->activeSeedStatuses(),
            'terminal_statuses_not_blocking_future_replenishment' => ['completed_dry_run', 'released', 'cancelled'],
            'claim_before_work_required' => true,
            'completion_evidence_required' => true,
            'lease_renewal_required_for_long_running_work' => true,
            'operator_next_actions' => [
                'if_claimable_below_target_and_generated_task_count_positive' => 'run terminal worker bootstrap or claim-next for each worker lane',
                'if_claimable_below_target_and_all_candidate_seeds_active' => 'continue active leases or recover stale leases before replenishing again',
                'if_blocker_claimable_queue_below_target_after_replenishment' => 'inspect plan_evaluation and queue registry before adding new canonical sources',
                'if_completion_evidence_validation_fails' => 'do not complete the packet; repair evidence JSON and rerun complete-dry-run',
            ],
            'stop_conditions' => [
                'target_min_claimable_tasks_met',
                'max_new_tasks_zero',
                'all_candidate_replenishment_seeds_already_active',
                'no_governed_source_available',
                'scope_validation_blocks_candidate_packet',
                'operator_requests_stop',
            ],
            'non_execution_guarantee' => 'auto-replenishment only creates local task packets; it never claims, dispatches, starts providers, spends tokens or marks real OS completion',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $plan
     * @param  array<string, mixed>  $existingSeedIndex
     * @return array<string, mixed>
     */
    private function evaluatePlan(array $plan, array $existingSeedIndex, bool $allowTerminalReissue = false): array
    {
        $activeSeedKeys = (array) ($existingSeedIndex['active_seed_keys'] ?? []);
        $terminalSeedKeys = (array) ($existingSeedIndex['terminal_seed_keys'] ?? []);
        $accepted = [];
        $operatorHandoff = [];
        $skipped = [];
        $seen = [];

        foreach ($plan as $seed) {
            $seedKey = (string) ($seed['seed_key'] ?? '');
            if ((bool) ($seed['worker_executable'] ?? true) === false) {
                $operatorHandoff[] = $this->operatorHandoffSeed($seed);

                continue;
            }
            if ($seedKey === '') {
                $accepted[] = $seed;

                continue;
            }

            if (isset($seen[$seedKey])) {
                $skipped[] = [
                    'seed_key' => $seedKey,
                    'reason' => 'duplicate_seed_inside_plan',
                    'existing_task_packet_id' => '',
                    'existing_status' => '',
                ];

                continue;
            }
            $seen[$seedKey] = true;

            if (isset($activeSeedKeys[$seedKey])) {
                $skipped[] = [
                    'seed_key' => $seedKey,
                    'reason' => 'auto_replenishment_seed_already_active',
                    'existing_task_packet_id' => (string) data_get($activeSeedKeys, $seedKey.'.task_packet_id', ''),
                    'existing_status' => (string) data_get($activeSeedKeys, $seedKey.'.status', ''),
                ];

                continue;
            }

            // 'released' is deliberately excluded — a released lease must mint a fresh task_packet_id
            // so the work is retried, never silently starved (see test_released_auto_replenishment_
            // seed_does_not_starve_future_supply). Only successful-terminal statuses block reissue.
            if (! $allowTerminalReissue
                && isset($terminalSeedKeys[$seedKey])
                && in_array((string) data_get($terminalSeedKeys, $seedKey.'.status', ''), self::TERMINAL_BLOCKING_STATUSES, true)
            ) {
                $skipped[] = [
                    'seed_key' => $seedKey,
                    'reason' => 'auto_replenishment_seed_already_terminal',
                    'existing_task_packet_id' => (string) data_get($terminalSeedKeys, $seedKey.'.task_packet_id', ''),
                    'existing_status' => (string) data_get($terminalSeedKeys, $seedKey.'.status', ''),
                ];

                continue;
            }

            $accepted[] = $seed;
        }

        return [
            'status' => match (true) {
                $accepted !== [] || $plan === [] => 'evaluated',
                $operatorHandoff !== [] && $skipped === [] => 'operator_handoff_required',
                default => 'all_candidate_seeds_already_active',
            },
            'candidate_seed_count' => count($plan),
            'accepted_seed_count' => count($accepted),
            'skipped_duplicate_seed_count' => count($skipped),
            'accepted_seed_keys' => array_values(array_map(
                static fn (array $seed): string => (string) ($seed['seed_key'] ?? ''),
                $accepted,
            )),
            'operator_handoff_seed_count' => count($operatorHandoff),
            'operator_handoff_seed_keys' => array_values(array_map(
                static fn (array $seed): string => (string) ($seed['seed_key'] ?? ''),
                $operatorHandoff,
            )),
            'operator_handoff_seeds' => $operatorHandoff,
            'skipped_duplicate_seeds' => $skipped,
            'active_seed_index_hash' => $this->stableHash($existingSeedIndex),
            'accepted_seeds' => $accepted,
        ];
    }

    /**
     * Keep final operator/provider blockers visible even when the claimable
     * queue is already full and the worker replenishment plan is empty.
     *
     * @param  array<string, mixed>  $planEvaluation
     * @param  list<array<string, mixed>>  $sources
     * @return array<string, mixed>
     */
    private function withCompletionOperatorHandoffSeeds(array $planEvaluation, array $sources): array
    {
        $operatorHandoff = (array) ($planEvaluation['operator_handoff_seeds'] ?? []);
        $seen = array_fill_keys(array_map(
            static fn (array $seed): string => (string) ($seed['seed_key'] ?? ''),
            $operatorHandoff,
        ), true);

        foreach ($this->completionOperatorHandoffSeedsFromSources($sources) as $seed) {
            $seedKey = (string) ($seed['seed_key'] ?? '');
            if ($seedKey !== '' && isset($seen[$seedKey])) {
                continue;
            }

            $operatorHandoff[] = $this->operatorHandoffSeed($seed);
            if ($seedKey !== '') {
                $seen[$seedKey] = true;
            }
        }

        $planEvaluation['operator_handoff_seed_count'] = count($operatorHandoff);
        $planEvaluation['operator_handoff_seed_keys'] = array_values(array_map(
            static fn (array $seed): string => (string) ($seed['seed_key'] ?? ''),
            $operatorHandoff,
        ));
        $planEvaluation['operator_handoff_seeds'] = $operatorHandoff;

        if (
            $operatorHandoff !== []
            && (int) ($planEvaluation['accepted_seed_count'] ?? 0) === 0
            && (int) ($planEvaluation['skipped_duplicate_seed_count'] ?? 0) === 0
        ) {
            $planEvaluation['status'] = 'operator_handoff_required';
        }

        return $planEvaluation;
    }

    /**
     * @param  list<array<string, mixed>>  $sources
     * @return list<array<string, mixed>>
     */
    private function completionOperatorHandoffSeedsFromSources(array $sources): array
    {
        $sourceMap = [];
        foreach ($sources as $source) {
            $sourceMap[(string) $source['source']] = $source;
        }

        $seeds = [];
        foreach ((array) data_get($sourceMap, 'completion_audit_failed_criteria.value', []) as $criterion) {
            $criterion = (string) $criterion;
            if (! $this->completionAuditCriterionRequiresOperator($criterion)) {
                continue;
            }

            $detail = (array) data_get($sourceMap, 'completion_audit_failed_criteria.details_by_id.'.$criterion, []);
            $seeds[] = $this->seed('completion_audit_'.$this->slug($criterion), 'Fechar critério falho do completion audit: '.$criterion, [
                'source' => 'completion_audit',
                'reference' => $criterion,
                'priority' => 2,
                'tags' => ['completion_audit', 'operator_handoff_required'],
                'worker_executable' => false,
                'operator_handoff_required' => true,
                'operator_handoff_reason' => $this->completionAuditOperatorHandoffReason($criterion),
                'blocker_type' => (string) ($detail['blocker_type'] ?? ''),
                'expected_receipt_schema' => (string) ($detail['expected_receipt_schema'] ?? ''),
                'remediation_command' => (string) ($detail['remediation_command'] ?? ''),
                'why_blocking' => (string) ($detail['why_blocking'] ?? ''),
                'current_evidence_context' => (array) ($detail['current_evidence_context'] ?? data_get($detail, 'evidence.current_evidence_context', [])),
            ]);
        }

        return $seeds;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<array<string, mixed>>
     */
    private function sources(array $context): array
    {
        $controlPlane = (array) ($context['control_plane'] ?? []);
        $completionAudit = $this->completionAuditPayload((array) ($context['completion_audit'] ?? []));
        $chainIntegrity = (array) ($context['chain_integrity'] ?? []);
        $terminalBootstrapProbe = (array) ($context['terminal_bootstrap_probe'] ?? []);
        $nextRequiredSlice = (string) data_get($controlPlane, 'control_plane.persistent_runtime.next_required_slice', data_get($context, 'next_required_slice', ''));
        $notYetRuntimeCapable = (array) data_get($controlPlane, 'control_plane.not_yet_runtime_capable', []);
        $failedCriteria = $this->completionAuditFailedCriteria($completionAudit);
        $failedCriterionDetails = $this->completionAuditFailedCriterionDetails($completionAudit);
        $chainViolations = array_values((array) data_get($chainIntegrity, 'violations', []));
        $terminalBootstrapProbeEnabled = (bool) ($terminalBootstrapProbe['enabled'] ?? false);

        return [
            [
                'source' => 'terminal_bootstrap_probe',
                'value' => [
                    'namespace' => (string) ($terminalBootstrapProbe['namespace'] ?? 'terminal_worker_bootstrap_probe'),
                    'target_task_count' => (int) ($terminalBootstrapProbe['target_task_count'] ?? 0),
                ],
                'available' => $terminalBootstrapProbeEnabled,
            ],
            [
                'source' => 'current_pointer',
                'value' => $nextRequiredSlice,
                'available' => $nextRequiredSlice !== '',
            ],
            [
                'source' => 'not_yet_runtime_capable',
                'value' => array_values($notYetRuntimeCapable),
                'available' => $notYetRuntimeCapable !== [],
            ],
            [
                'source' => 'completion_audit_failed_criteria',
                'value' => $failedCriteria,
                'details_by_id' => $failedCriterionDetails,
                'available' => $failedCriteria !== [],
            ],
            [
                'source' => 'chain_integrity_violations',
                'value' => $chainViolations,
                'available' => $chainViolations !== [],
            ],
            [
                'source' => 'canonical_contract',
                'value' => 'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
                'available' => true,
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $sources
     * @return list<array<string, mixed>>
     */
    private function plan(array $sources, int $targetMinClaimable, int $maxNewTasks, int $claimableBefore, int $totalBefore): array
    {
        if ($claimableBefore >= $targetMinClaimable || $maxNewTasks === 0) {
            return [];
        }

        $needed = min($maxNewTasks, $targetMinClaimable - $claimableBefore);
        $sourceMap = [];
        foreach ($sources as $source) {
            $sourceMap[(string) $source['source']] = $source;
        }

        $seeds = [];
        if ((bool) data_get($sourceMap, 'terminal_bootstrap_probe.available', false)) {
            $namespace = $this->slug((string) data_get($sourceMap, 'terminal_bootstrap_probe.value.namespace', 'terminal_worker_bootstrap_probe'));
            for ($i = 0; $i < $needed; $i++) {
                $seeds[] = $this->seed('terminal_bootstrap_probe_'.$namespace.'_'.$i, 'Certificar bootstrap terminal worker isolado #'.($i + 1), [
                    'source' => 'terminal_bootstrap_probe',
                    'reference' => $namespace.'/worker_'.$i,
                    'priority' => 1,
                    'tags' => ['terminal_bootstrap_probe'],
                ]);
            }

            return array_slice(array_map(function (array $seed, int $index) use ($totalBefore): array {
                $seed['task_packet_id'] = $this->nextAvailableTaskPacketId($seed, $totalBefore, $index);

                return $seed;
            }, $seeds, array_keys($seeds)), 0, $needed);
        }

        $next = (string) data_get($sourceMap, 'current_pointer.value', '');
        if ($next !== '') {
            $seeds[] = $this->seed('current_pointer_'.$this->slug($next), 'Implementar o próximo slice canônico do Agent Control Plane: '.$next, [
                'source' => 'current_pointer',
                'reference' => $next,
                'priority' => 1,
                'tags' => ['next_required_slice'],
            ]);
        }

        foreach (array_slice((array) data_get($sourceMap, 'completion_audit_failed_criteria.value', []), 0, 2) as $criterion) {
            $criterion = (string) $criterion;
            $operatorOnly = $this->completionAuditCriterionRequiresOperator($criterion);
            $detail = (array) data_get($sourceMap, 'completion_audit_failed_criteria.details_by_id.'.$criterion, []);
            $seeds[] = $this->seed('completion_audit_'.$this->slug($criterion), 'Fechar critério falho do completion audit: '.$criterion, [
                'source' => 'completion_audit',
                'reference' => $criterion,
                'priority' => 2,
                'tags' => array_values(array_filter([
                    'completion_audit',
                    $operatorOnly ? 'operator_handoff_required' : '',
                ])),
                'worker_executable' => ! $operatorOnly,
                'operator_handoff_required' => $operatorOnly,
                'operator_handoff_reason' => $this->completionAuditOperatorHandoffReason($criterion),
                'blocker_type' => (string) ($detail['blocker_type'] ?? ''),
                'expected_receipt_schema' => (string) ($detail['expected_receipt_schema'] ?? ''),
                'remediation_command' => (string) ($detail['remediation_command'] ?? ''),
                'why_blocking' => (string) ($detail['why_blocking'] ?? ''),
                'current_evidence_context' => (array) ($detail['current_evidence_context'] ?? data_get($detail, 'evidence.current_evidence_context', [])),
            ]);
        }

        foreach (array_slice((array) data_get($sourceMap, 'not_yet_runtime_capable.value', []), 0, 3) as $runtimeGap) {
            $runtimeGap = (string) $runtimeGap;
            if ($runtimeGap === '') {
                continue;
            }
            $runtimeGapWords = str_replace('_', ' ', $runtimeGap);
            $seeds[] = $this->seed('not_yet_runtime_capable_'.$this->slug($runtimeGap), 'Close '.$runtimeGapWords.' capability gap with a dedicated control-plane proof.', [
                'source' => 'not_yet_runtime_capable',
                'reference' => $runtimeGap,
                'priority' => 3,
                'tags' => ['runtime_gap', 'not_yet_runtime_capable'],
                'acceptance_criteria' => ['runtime_gap_'.$this->slug($runtimeGap).'_focused_tests_pass'],
            ]);
        }

        if ((array) data_get($sourceMap, 'chain_integrity_violations.value', []) !== []) {
            $seeds[] = $this->seed('chain_integrity_first_violation', 'Corrigir primeira violação da Chain Integrity Certification.', [
                'source' => 'chain_integrity',
                'reference' => 'first_violation',
                'priority' => 2,
                'tags' => ['chain_integrity'],
            ]);
        }

        $seeds[] = $this->seed('contract_docs_guardrail', 'Atualizar documentação e guardrails do Agent Control Plane quando contrato/comando/comportamento mudar.', [
            'source' => 'canonical_contract',
            'reference' => 'agent-control-plane-contract.md',
            'priority' => 4,
            'tags' => ['docs', 'guardrail'],
        ]);
        $seeds[] = $this->seed('focused_regression_tests', 'Adicionar ou reforçar testes focados para o slice em andamento do loop multiagente.', [
            'source' => 'test_guardrail',
            'reference' => 'focused_tests',
            'priority' => 4,
            'tags' => ['tests', 'guardrail'],
        ]);

        // Partition: handoff seeds (operator-only) pass through unconditionally;
        // worker-executable seeds are sliced to fit $needed so they fill the target.
        $floorExecutable = array_values(array_filter($seeds, static fn (array $s): bool => (bool) ($s['worker_executable'] ?? true)));
        $handoff = array_values(array_filter($seeds, static fn (array $s): bool => ! (bool) ($s['worker_executable'] ?? true)));
        $trimmedExecutable = array_slice($floorExecutable, 0, $needed);
        $seeds = array_merge($trimmedExecutable, $handoff);

        return array_map(function (array $seed, int $index) use ($totalBefore): array {
            $seed['task_packet_id'] = $this->nextAvailableTaskPacketId($seed, $totalBefore, $index);

            return $seed;
        }, $seeds, array_keys($seeds));
    }

    /**
     * The registry is capped, but a long-running loop can still have old task
     * packet files beyond that cap. Never reuse an occupied id; otherwise a
     * new seed may become a hash-conflict instead of a claimable packet.
     *
     * @param  array<string, mixed>  $seed
     */
    private function nextAvailableTaskPacketId(array $seed, int $totalBefore, int $index): string
    {
        $sequence = str_pad((string) ($totalBefore + $index + 1), 4, '0', STR_PAD_LEFT);
        $base = 'acp-auto-'.$sequence.'-'.$this->slug((string) $seed['seed_key']);

        if ($this->queue->get($base) === null) {
            return $base;
        }

        $fingerprint = substr($this->stableHash([
            'seed_key' => (string) ($seed['seed_key'] ?? ''),
            'source' => (string) ($seed['source'] ?? ''),
            'reference' => (string) ($seed['reference'] ?? ''),
            'tags' => (array) ($seed['tags'] ?? []),
        ]), 0, 10);

        for ($attempt = 1; $attempt <= 50; $attempt++) {
            $candidate = $base.'-'.$fingerprint.'-r'.str_pad((string) $attempt, 2, '0', STR_PAD_LEFT);
            if ($this->queue->get($candidate) === null) {
                return $candidate;
            }
        }

        return $base.'-'.$fingerprint.'-overflow';
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function seed(string $key, string $objective, array $extra = []): array
    {
        return array_merge([
            'seed_key' => $key,
            'objective' => $objective,
            'source' => 'unknown',
            'reference' => '',
            'priority' => 5,
            'tags' => [],
            'worker_executable' => true,
            'operator_handoff_required' => false,
            'operator_handoff_reason' => '',
            'blocker_type' => '',
            'expected_receipt_schema' => '',
            'remediation_command' => '',
            'why_blocking' => '',
            'current_evidence_context' => [],
        ], $extra);
    }

    /**
     * @param  array<string, mixed>  $seed
     * @return array<string, mixed>
     */
    private function operatorHandoffSeed(array $seed): array
    {
        return [
            'schema_version' => 'atlas.self_construction.agent_control_plane_operator_handoff_seed.v1',
            'seed_key' => (string) ($seed['seed_key'] ?? ''),
            'source' => (string) ($seed['source'] ?? ''),
            'reference' => (string) ($seed['reference'] ?? ''),
            'objective' => (string) ($seed['objective'] ?? ''),
            'priority' => (int) ($seed['priority'] ?? 5),
            'tags' => (array) ($seed['tags'] ?? []),
            'worker_executable' => false,
            'operator_handoff_required' => true,
            'operator_handoff_reason' => (string) ($seed['operator_handoff_reason'] ?? 'requires_operator_action_before_worker_execution'),
            'blocker_type' => (string) ($seed['blocker_type'] ?? ''),
            'expected_receipt_schema' => (string) ($seed['expected_receipt_schema'] ?? ''),
            'remediation_command' => (string) ($seed['remediation_command'] ?? ''),
            'why_blocking' => (string) ($seed['why_blocking'] ?? ''),
            'current_evidence_context' => (array) ($seed['current_evidence_context'] ?? []),
            'claimable_task_created' => false,
            'why_not_claimable' => 'operator_or_real_provider_evidence_required; auto-replenishment must not assign this blocker to Codex/Claude workers',
            'next_action' => $this->operatorHandoffNextAction((string) ($seed['reference'] ?? '')),
        ];
    }

    /**
     * @param  array<string, mixed>  $completionAudit
     * @return array<string, mixed>
     */
    private function completionAuditPayload(array $completionAudit): array
    {
        return $this->completionAuditReader()->completionAuditPayload($completionAudit);
    }

    /**
     * @param  array<string, mixed>  $completionAudit
     * @return list<string>
     */
    private function completionAuditFailedCriteria(array $completionAudit): array
    {
        return $this->completionAuditReader()->completionAuditFailedCriteria($completionAudit);
    }

    /**
     * @param  array<string, mixed>  $completionAudit
     * @return array<string, array<string, mixed>>
     */
    private function completionAuditFailedCriterionDetails(array $completionAudit): array
    {
        return $this->completionAuditReader()->completionAuditFailedCriterionDetails($completionAudit);
    }

    private function completionAuditCriterionRequiresOperator(string $criterion): bool
    {
        return $this->completionAuditReader()->completionAuditCriterionRequiresOperator($criterion);
    }

    private function completionAuditOperatorHandoffReason(string $criterion): string
    {
        return $this->completionAuditReader()->completionAuditOperatorHandoffReason($criterion);
    }

    private function operatorHandoffNextAction(string $criterion): string
    {
        return $this->completionAuditReader()->operatorHandoffNextAction($criterion);
    }

    /**
     * @param  array<string, mixed>  $seed
     * @return array<string, mixed>
     */
    private function taskPacketFromSeed(array $seed, string $actor): array
    {
        $scope = $this->scopeForSeed($seed);

        return [
            'task_packet_id' => (string) $seed['task_packet_id'],
            'objective' => (string) $seed['objective'],
            'source' => 'agent_control_plane_auto_replenishment:'.(string) $seed['source'],
            'operator_id' => $actor,
            'parent_run_id' => 'AGENT-CONTROL-PLANE-AUTO-REPLENISHMENT-0001',
            'allowed_files' => $scope['allowed_files'],
            'forbidden_files' => [
                'routes/api.php',
                'app/Services/Ai/SelfImprovement/',
                'app/Services/Ai/Programming/',
                'atlas-desktop/',
            ],
            'scope_in' => $scope['scope_in'],
            'scope_out' => [
                'app/Services/Ai/SelfImprovement/',
                'app/Services/Ai/Programming/',
                'routes/api.php',
                'atlas-desktop/',
            ],
            'acceptance_criteria' => (array) ($seed['acceptance_criteria'] ?? [
                'implementation_matches_canonical_contract',
                'scope_is_limited_to_agent_control_plane',
                'focused_tests_pass',
                'docs_updated_if_contract_or_cli_changes',
                'runtime_flags_remain_false',
                'git_status_preserves_unrelated_changes',
            ]),
            'required_evidence' => [
                'task_packet_created',
                'lease_claim_required',
                'focused_tests_output',
                'docs_health_output',
                'architecture_validate_output',
                'git_diff_check_output',
                'continuation_summary',
            ],
            'risk_level' => 'low',
            'max_runtime_seconds' => 10800,
            'max_token_budget' => 0,
            'workspace_policy' => [
                'isolation' => 'shared_worktree_with_explicit_scope_lock',
                'auto_apply' => false,
            ],
            'continuation_context' => [
                'auto_replenishment_seed_key' => (string) $seed['seed_key'],
                'auto_replenishment_source' => (string) $seed['source'],
                'auto_replenishment_reference' => (string) $seed['reference'],
                'worker_executable' => (bool) ($seed['worker_executable'] ?? true),
                'operator_handoff_required' => (bool) ($seed['operator_handoff_required'] ?? false),
                'operator_handoff_reason' => (string) ($seed['operator_handoff_reason'] ?? ''),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $seed
     * @return array{allowed_files: list<string>, scope_in: list<string>}
     */
    private function scopeForSeed(array $seed): array
    {
        $source = (string) ($seed['source'] ?? '');
        $reference = (string) ($seed['reference'] ?? '');
        $seedKey = (string) ($seed['seed_key'] ?? '');

        if ($source === 'current_pointer' && $reference !== '') {
            $slice = str_replace('activate_signed_one_shot_scheduler_tick_', '', $reference);
            $studly = str_replace(' ', '', ucwords(str_replace('_', ' ', $slice)));
            $implementation = 'app/Services/Ai/SelfConstruction/AgentAutomaticDispatchSchedulerOneShotTick'.$studly.'Invoker.php';
            $test = 'tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTick'.$studly.'InvokerTest.php';

            return [
                'allowed_files' => [$implementation, $test],
                'scope_in' => [
                    $implementation,
                    $test,
                    'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
                ],
            ];
        }

        if ($source === 'completion_audit') {
            $lane = match ($reference) {
                'runtime_gap_matrix_all_runtime_y' => 'AtlasSelfConstructionRuntimePromotion',
                'human_signed_os_complete_receipt_present' => 'AtlasSelfConstructionHumanCompletionReceipt',
                'end_to_end_real_provider_smoke_green' => 'AtlasSelfConstructionRealProviderSmoke',
                default => 'AtlasSelfConstructionCompletionEvidence',
            };

            return [
                'allowed_files' => [
                    'app/Services/Ai/SelfConstruction/'.$lane,
                    'tests/Feature/Ai/SelfConstruction/'.$lane,
                ],
                'scope_in' => [
                    'app/Services/Ai/SelfConstruction/',
                    'tests/Feature/Ai/SelfConstruction/',
                    'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
                ],
            ];
        }

        if ($source === 'not_yet_runtime_capable') {
            [$implementation, $test] = match ($reference) {
                'adapter_execution_runtime' => [
                    'app/Services/Ai/SelfConstruction/NativeImplementation/AtlasSelfConstructionAdapterExecutionRuntimeGraduationService.php',
                    'tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionAdapterExecutionRuntimeGraduationServiceTest.php',
                ],
                'automatic_cost_import_runtime' => [
                    'app/Services/Ai/SelfConstruction/NativeImplementation/AtlasSelfConstructionAutomaticCostImportRuntimeGraduationService.php',
                    'tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionAutomaticCostImportRuntimeGraduationServiceTest.php',
                ],
                'automatic_work_product_collection_runtime' => [
                    'app/Services/Ai/SelfConstruction/NativeImplementation/AtlasSelfConstructionAutomaticWorkProductCollectionRuntimeGraduationService.php',
                    'tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionAutomaticWorkProductCollectionRuntimeGraduationServiceTest.php',
                ],
                default => [
                    'app/Services/Ai/SelfConstruction/NativeImplementation/AtlasSelfConstructionRuntimeGapMatrixService.php',
                    'tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionRuntimeGapMatrixTest.php',
                ],
            };

            return [
                'allowed_files' => [$implementation, $test],
                'scope_in' => [
                    $implementation,
                    $test,
                    'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
                ],
            ];
        }

        if ($source === 'chain_integrity') {
            return [
                'allowed_files' => [
                    'app/Services/Ai/SelfConstruction/AgentControlPlaneChainIntegrityAuditService.php',
                    'tests/Feature/Ai/AtlasAiSelfConstructionAgentControlPlaneChainIntegrityAuditTest.php',
                ],
                'scope_in' => [
                    'app/Services/Ai/SelfConstruction/AgentControlPlaneChainIntegrityAuditService.php',
                    'tests/Feature/Ai/AtlasAiSelfConstructionAgentControlPlaneChainIntegrityAuditTest.php',
                    'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
                ],
            ];
        }

        if ($source === 'terminal_bootstrap_probe') {
            $reference = $this->slug($reference === '' ? 'worker' : $reference);

            return [
                'allowed_files' => [
                    'app/Services/Ai/SelfConstruction/__terminal_worker_bootstrap_probe__/'.$reference.'.php',
                ],
                'scope_in' => [
                    'app/Services/Ai/SelfConstruction/AgentControlPlaneTerminalWorkerBootstrapService.php',
                    'app/Services/Ai/SelfConstruction/AgentControlPlaneTaskAutoReplenishmentService.php',
                    'app/Services/Ai/SelfConstruction/AgentControlPlaneMultiAgentLoopCertificationService.php',
                    'tests/Feature/Ai/AtlasAiSelfConstructionAgentControlPlaneMultiAgentLoopCertificationTest.php',
                    'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
                ],
            ];
        }

        if ($source === 'canonical_contract') {
            return [
                'allowed_files' => ['docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md'],
                'scope_in' => ['docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md'],
            ];
        }

        if ($source === 'test_guardrail' || str_contains($seedKey, 'focused_regression_tests')) {
            return [
                'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentControlPlaneTerminalWorkerBootstrapTest.php'],
                'scope_in' => [
                    'tests/Feature/Ai/',
                    'app/Services/Ai/SelfConstruction/',
                    'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
                ],
            ];
        }

        return [
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/',
                'tests/Feature/Ai/',
            ],
            'scope_in' => [
                'app/Services/Ai/SelfConstruction/',
                'tests/Feature/Ai/',
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function blockers(
        int $claimableAfter,
        int $targetMinClaimable,
        int $maxNewTasks,
        int $skippedDuplicateSeedCount,
        bool $hadCandidateSeeds,
        bool $allCandidateSeedsFiltered,
    ): array {
        if ($claimableAfter >= $targetMinClaimable) {
            return [];
        }
        if ($maxNewTasks === 0) {
            return ['max_new_tasks_zero'];
        }
        if ($hadCandidateSeeds && $allCandidateSeedsFiltered && $skippedDuplicateSeedCount > 0) {
            return ['all_candidate_replenishment_seeds_already_active'];
        }

        return ['claimable_queue_below_target_after_replenishment'];
    }

    /**
     * @param  list<string>  $tags
     * @return array<string, mixed>
     */
    private function existingAutoReplenishmentSeedIndex(array $tags): array
    {
        $records = (array) $this->queue->list();
        $activeSeedKeys = [];
        $terminalSeedKeys = [];

        foreach ($records as $record) {
            if (! $this->recordHasTags($record, $tags)) {
                continue;
            }

            $seedKey = (string) data_get($record, 'task_packet.continuation_context.auto_replenishment_seed_key', '');
            if ($seedKey === '') {
                continue;
            }

            $entry = [
                'task_packet_id' => (string) ($record['task_packet_id'] ?? ''),
                'status' => (string) ($record['status'] ?? ''),
                'updated_at' => (string) ($record['updated_at'] ?? ''),
            ];

            if (in_array((string) ($record['status'] ?? ''), $this->activeSeedStatuses(), true)) {
                $activeSeedKeys[$seedKey] = $entry;
            } else {
                $terminalSeedKeys[$seedKey] = $entry;
            }
        }

        ksort($activeSeedKeys);
        ksort($terminalSeedKeys);

        return [
            'schema_version' => 'atlas.self_construction.agent_control_plane_task_auto_replenishment_seed_index.v1',
            'queue_tags' => $tags,
            'active_seed_statuses' => $this->activeSeedStatuses(),
            'active_seed_keys' => $activeSeedKeys,
            'terminal_seed_keys' => $terminalSeedKeys,
        ];
    }

    /**
     * @return list<string>
     */
    private function activeSeedStatuses(): array
    {
        return ['queued', 'claimable', 'claimed', 'lease_expired', 'blocked'];
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  list<string>  $tags
     */
    private function recordHasTags(array $record, array $tags): bool
    {
        if ($tags === []) {
            return true;
        }

        $recordTags = array_map('strval', (array) ($record['tags'] ?? []));
        foreach ($tags as $tag) {
            if (! in_array($tag, $recordTags, true)) {
                return false;
            }
        }

        return true;
    }

    private function slug(string $value): string
    {
        $slug = Str::slug($value, '_');

        return $slug === '' ? 'task' : substr($slug, 0, 80);
    }

    /**
     * @param  list<string>  $tags
     */
    private function countClaimableWithTags(array $tags): int
    {
        $records = (array) $this->queue->list(['status' => 'claimable']);

        return count(array_filter($records, static function (array $record) use ($tags): bool {
            if ((bool) data_get($record, 'task_packet.continuation_context.worker_executable', true) === false) {
                return false;
            }
            if ((bool) data_get($record, 'task_packet.continuation_context.operator_handoff_required', false)) {
                return false;
            }
            $recordTags = array_map('strval', (array) ($record['tags'] ?? []));
            foreach ($tags as $tag) {
                if (! in_array($tag, $recordTags, true)) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private function stringList(array $values): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $values,
        ), static fn (string $value): bool => $value !== ''));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeForHash(array $payload): array
    {
        return $this->stableHasher()->normalizeForHash($payload);
    }


    /**
     * @param  array<mixed, mixed>  $payload
     */
    private function stableHash(array $payload): string
    {
        return $this->stableHasher()->stableHash($payload);
    }
}
