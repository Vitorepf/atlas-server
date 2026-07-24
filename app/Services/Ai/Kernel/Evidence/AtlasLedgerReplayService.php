<?php

namespace App\Services\Ai\Kernel\Evidence;

use App\Models\AiInboxItem;
use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Decision\DecisionReceiptHash;
use App\Services\Ai\Kernel\Evidence\LedgerReplay\LedgerReplaySupport;
use App\Services\Ai\Support\AiStringListNormalizer;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

class AtlasLedgerReplayService
{
    private const CUTOFF_SCHEMA = 'atlas.ledger_cutoff.v2';

    private const JOURNEY_MANIFEST_SCHEMA = 'atlas.aaeos.journey_manifest.v2';

    private const HMAC_ALGORITHM = 'sha256';

    public function __construct(
        private readonly AtlasEvidenceLedger $ledger,
        private readonly LedgerReplaySupport $support,
    ) {}

    /**
     * @return array<int,array<string,mixed>>
     */
    public function eventsForEnvelope(string $envelopeId, ?string $tenantId = null): array
    {
        $tenantId = $this->proofTenant($tenantId);
        if ($tenantId === null || ! $this->tableAvailable()) {
            if ($tenantId !== null || ! $this->tableAvailable()) {
                return [];
            }

            $tenantIds = $this->ledgerQuery()
                ->where('envelope_id', $envelopeId)
                ->distinct()
                ->pluck('tenant_id')
                ->filter(fn (mixed $value): bool => trim((string) $value) !== '')
                ->values();
            if ($tenantIds->count() !== 1) {
                return [];
            }

            $tenantId = (string) $tenantIds->first();
        }

        return $this->ledgerQuery()
            ->where('tenant_id', $tenantId)
            ->where('envelope_id', $envelopeId)
            ->orderBy('occurred_at')
            ->orderBy('event_id')
            ->get()
            ->map(fn (AtlasLedgerEvent $event): array => $event->toArray())
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>|null
     */
    public function authenticatedCutoffForTenantChain(string $tenantId, string $chainKeyHash): ?array
    {
        $events = $this->v2ChainQuery($tenantId, $chainKeyHash)->get();
        if ($events->isEmpty()) {
            return null;
        }

        /** @var AtlasLedgerEvent $anchor */
        $anchor = $events->first();
        /** @var AtlasLedgerEvent $head */
        $head = $events->last();

        $core = [
            'schema' => self::CUTOFF_SCHEMA,
            'tenant_id' => $tenantId,
            'chain_key_hash' => $chainKeyHash,
            'anchor_position' => (int) $anchor->chain_position,
            'anchor_event_hash' => (string) $anchor->event_hash,
            'head_position' => (int) $head->chain_position,
            'head_event_hash' => (string) $head->event_hash,
            'event_count' => $events->count(),
        ];

        $authenticated = [
            ...$core,
            'authentication' => $this->authenticationDescriptor(),
        ];

        return [
            ...$authenticated,
            'authentication_tag' => $this->artifactSignature('atlas.ledger.cutoff.v2', $authenticated),
        ];
    }

    /**
     * Verify one exact, independently supplied tenant cutoff. A shorter valid
     * prefix is never accepted as the whole history.
     *
     * @param  array<string,mixed>  $cutoff
     * @return array<string,mixed>
     */
    public function verifyTenantChain(string $tenantId, string $chainKeyHash, array $cutoff): array
    {
        if (($cutoff['schema'] ?? null) !== self::CUTOFF_SCHEMA) {
            return $this->chainFailure('cutoff_unauthenticated');
        }

        $cutoffBody = $cutoff;
        $authenticationTag = (string) ($cutoffBody['authentication_tag'] ?? '');
        unset($cutoffBody['authentication_tag']);
        if (preg_match('/^[a-f0-9]{64}$/', $authenticationTag) !== 1) {
            return $this->chainFailure('cutoff_authentication_invalid');
        }
        try {
            if (! hash_equals(
                $this->artifactSignature('atlas.ledger.cutoff.v2', $cutoffBody),
                $authenticationTag,
            )) {
                return $this->chainFailure('cutoff_authentication_invalid');
            }
        } catch (\LogicException) {
            return $this->chainFailure('cutoff_authentication_unavailable');
        }

        if (($cutoff['tenant_id'] ?? null) !== $tenantId
            || ($cutoff['chain_key_hash'] ?? null) !== $chainKeyHash) {
            return $this->chainFailure('cutoff_scope_mismatch');
        }

        $anchorPosition = filter_var($cutoff['anchor_position'] ?? null, FILTER_VALIDATE_INT);
        $headPosition = filter_var($cutoff['head_position'] ?? null, FILTER_VALIDATE_INT);
        $expectedCount = filter_var($cutoff['event_count'] ?? null, FILTER_VALIDATE_INT);
        $anchorHash = (string) ($cutoff['anchor_event_hash'] ?? '');
        $headHash = (string) ($cutoff['head_event_hash'] ?? '');
        if ($anchorPosition === false || $headPosition === false || $expectedCount === false
            || $anchorPosition < 1 || $headPosition < $anchorPosition || $expectedCount < 1
            || preg_match('/^[a-f0-9]{64}$/', $anchorHash) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $headHash) !== 1) {
            return $this->chainFailure('cutoff_invalid');
        }

        $all = $this->v2ChainQuery($tenantId, $chainKeyHash)->get();
        if ($all->isEmpty()) {
            return $this->chainFailure('ledger_chain_empty');
        }

        /** @var AtlasLedgerEvent $actualAnchor */
        $actualAnchor = $all->first();
        /** @var AtlasLedgerEvent $actualHead */
        $actualHead = $all->last();
        if ((int) $actualAnchor->chain_position > $anchorPosition
            || ! $all->contains(fn (AtlasLedgerEvent $event): bool => (string) $event->event_hash === $anchorHash)) {
            return $this->chainFailure('cutoff_prefix_truncated');
        }
        if ((int) $actualHead->chain_position < $headPosition
            || ! $all->contains(fn (AtlasLedgerEvent $event): bool => (string) $event->event_hash === $headHash)) {
            return $this->chainFailure('cutoff_suffix_truncated');
        }
        if ((int) $actualHead->chain_position > $headPosition) {
            return $this->chainFailure('cutoff_stale');
        }

        $events = $all
            ->filter(fn (AtlasLedgerEvent $event): bool => (int) $event->chain_position >= $anchorPosition
                && (int) $event->chain_position <= $headPosition)
            ->values();
        if ($events->count() !== $expectedCount) {
            return $this->chainFailure('cutoff_event_count_mismatch', [
                'expected_event_count' => $expectedCount,
                'actual_event_count' => $events->count(),
            ]);
        }

        $expectedPosition = $anchorPosition;
        $previousHash = null;
        foreach ($events as $index => $event) {
            if ((int) $event->chain_position !== $expectedPosition) {
                return $this->chainFailure('cutoff_position_gap', [
                    'expected_position' => $expectedPosition,
                    'actual_position' => (int) $event->chain_position,
                ]);
            }
            if ($this->ledger->eventIntegrityStatus($event) !== 'verified') {
                return $this->chainFailure($this->ledger->eventIntegrityStatus($event), [
                    'position' => (int) $event->chain_position,
                ]);
            }
            if ($index === 0) {
                if ((string) $event->event_hash !== $anchorHash) {
                    return $this->chainFailure('cutoff_anchor_hash_mismatch');
                }
                if ($anchorPosition === 1 && $event->prev_event_hash !== null) {
                    return $this->chainFailure('unexpected_anchor_predecessor');
                }
            } elseif (! hash_equals((string) $event->prev_event_hash, (string) $previousHash)) {
                return $this->chainFailure('predecessor_mismatch', [
                    'position' => (int) $event->chain_position,
                ]);
            }

            $previousHash = (string) $event->event_hash;
            $expectedPosition++;
        }

        if (! hash_equals($headHash, (string) $previousHash)) {
            return $this->chainFailure('cutoff_head_hash_mismatch');
        }

        return [
            'schema' => 'atlas.ledger_chain_verification.v1',
            'status' => 'verified',
            'valid' => true,
            'failure_reason' => null,
            'tenant_id' => $tenantId,
            'chain_key_hash' => $chainKeyHash,
            'event_count' => $events->count(),
            'head_position' => $headPosition,
            'head_event_hash' => $headHash,
        ];
    }

    /**
     * @param  array<string,mixed>  $cutoff
     * @return array<string,mixed>
     */
    public function journeyManifestForTenantChain(string $tenantId, string $chainKeyHash, array $cutoff): array
    {
        $verification = $this->verifyTenantChain($tenantId, $chainKeyHash, $cutoff);
        if (! ($verification['valid'] ?? false)) {
            return [
                'schema' => self::JOURNEY_MANIFEST_SCHEMA,
                'status' => 'failed',
                'failure_reason' => $verification['failure_reason'] ?? 'chain_verification_failed',
            ];
        }

        $events = $this->v2ChainQuery($tenantId, $chainKeyHash)
            ->whereBetween('chain_position', [
                (int) $cutoff['anchor_position'],
                (int) $cutoff['head_position'],
            ])
            ->get()
            ->map(fn (AtlasLedgerEvent $event): array => $this->journeyEventRef($event))
            ->values()
            ->all();
        $core = [
            'schema' => self::JOURNEY_MANIFEST_SCHEMA,
            'status' => 'sealed',
            'tenant_id' => $tenantId,
            'chain_key_hash' => $chainKeyHash,
            'cutoff' => $cutoff,
            'event_count' => count($events),
            'events' => $events,
        ];

        $authenticated = [
            ...$core,
            'authentication' => $this->authenticationDescriptor(),
        ];

        $journeyManifestHash = AtlasEvidenceLedger::computeV2EnvelopeHash($authenticated);
        $signedManifest = [
            ...$authenticated,
            'journey_manifest_hash' => $journeyManifestHash,
        ];

        return [
            ...$signedManifest,
            'authentication_tag' => $this->artifactSignature('atlas.aaeos.journey_manifest.v2', $signedManifest),
        ];
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @return array<string,mixed>
     */
    public function verifyJourneyManifest(array $manifest): array
    {
        $providedHash = (string) ($manifest['journey_manifest_hash'] ?? '');
        $authenticationTag = (string) ($manifest['authentication_tag'] ?? '');
        $core = $manifest;
        unset($core['journey_manifest_hash'], $core['authentication_tag']);
        if (($core['schema'] ?? null) !== self::JOURNEY_MANIFEST_SCHEMA) {
            return $this->journeyFailure('journey_manifest_unauthenticated');
        }
        if (preg_match('/^[a-f0-9]{64}$/', $authenticationTag) !== 1) {
            return $this->journeyFailure('journey_manifest_authentication_invalid');
        }
        $authenticated = [
            ...$core,
            'journey_manifest_hash' => $providedHash,
        ];
        try {
            if (! hash_equals(
                $this->artifactSignature('atlas.aaeos.journey_manifest.v2', $authenticated),
                $authenticationTag,
            )) {
                return $this->journeyFailure('journey_manifest_authentication_invalid');
            }
        } catch (\LogicException) {
            return $this->journeyFailure('journey_manifest_authentication_unavailable');
        }
        if (preg_match('/^[a-f0-9]{64}$/', $providedHash) !== 1
            || ! hash_equals(AtlasEvidenceLedger::computeV2EnvelopeHash($core), $providedHash)) {
            return $this->journeyFailure('journey_manifest_hash_mismatch');
        }

        $tenantId = (string) ($core['tenant_id'] ?? '');
        $chainKeyHash = (string) ($core['chain_key_hash'] ?? '');
        $cutoff = is_array($core['cutoff'] ?? null) ? $core['cutoff'] : [];
        $chain = $this->verifyTenantChain($tenantId, $chainKeyHash, $cutoff);
        if (! ($chain['valid'] ?? false)) {
            return $this->journeyFailure((string) ($chain['failure_reason'] ?? 'chain_verification_failed'));
        }

        $actualEvents = $this->v2ChainQuery($tenantId, $chainKeyHash)
            ->whereBetween('chain_position', [
                (int) $cutoff['anchor_position'],
                (int) $cutoff['head_position'],
            ])
            ->get()
            ->map(fn (AtlasLedgerEvent $event): array => $this->journeyEventRef($event))
            ->values()
            ->all();
        if (($core['event_count'] ?? null) !== count($actualEvents)
            || ($core['events'] ?? null) !== $actualEvents) {
            return $this->journeyFailure('journey_manifest_order_mismatch');
        }

        return [
            'schema' => 'atlas.aaeos.journey_manifest_verification.v1',
            'status' => 'verified',
            'valid' => true,
            'failure_reason' => null,
            'journey_manifest_hash' => $providedHash,
            'event_count' => count($actualEvents),
        ];
    }

    /**
     * @return array{
     *     envelope_id:string,
     *     observation_count:int,
     *     status_counts:array<string,int>,
     *     success_count:int,
     *     failure_count:int,
     *     worst_status:string|null,
     *     worst_severity:string|null,
     *     dimensions:array<string,array<string,int>>,
     *     stages:array<string,array<string,mixed>>
     * }
     */
    public function sloReportForEnvelope(string $envelopeId): array
    {
        $observations = collect($this->eventsForEnvelope($envelopeId))
            ->filter(fn (array $event): bool => ($event['event_type'] ?? null) === LedgerEventType::SloObserved->value)
            ->map(fn (array $event): array => $this->support->sloObservationFromEvent($event))
            ->values();

        return [
            'envelope_id' => $envelopeId,
            ...$this->sloObservationSummary($observations),
        ];
    }

    /**
     * @return array{
     *     envelope_id:string,
     *     repair_event_count:int,
     *     initiated_count:int,
     *     completed_count:int,
     *     executed_count:int,
     *     status_counts:array<string,int>,
     *     strategy_counts:array<string,int>,
     *     reason_counts:array<string,int>,
     *     latest_status:string|null,
     *     latest_strategy:string|null,
     *     requires_human_review:bool,
     *     events:array<int,array<string,mixed>>
     * }
     */
    public function repairReportForEnvelope(string $envelopeId): array
    {
        $events = collect($this->eventsForEnvelope($envelopeId))
            ->filter(fn (array $event): bool => in_array($event['event_type'] ?? null, [
                LedgerEventType::RepairInitiated->value,
                LedgerEventType::RepairCompleted->value,
            ], true))
            ->map(fn (array $event): array => $this->support->repairEventFromEvent($event))
            ->values();

        return [
            'envelope_id' => $envelopeId,
            ...$this->repairEventSummary($events),
        ];
    }

    /**
     * @return array{
     *     envelope_id:string,
     *     kernel_pipeline_event_count:int,
     *     accepted_count:int,
     *     rejected_count:int,
     *     status_counts:array<string,int>,
     *     surface_counts:array<string,int>,
     *     emitter_stage_counts:array<string,int>,
     *     flow_counts:array<string,int>,
     *     input_mode_counts:array<string,int>,
     *     violation_counts:array<string,int>,
     *     latest_status:string|null,
     *     has_rejections:bool,
     *     events:array<int,array<string,mixed>>
     * }
     */
    public function kernelPipelineReportForEnvelope(string $envelopeId): array
    {
        $events = collect($this->eventsForEnvelope($envelopeId))
            ->filter(fn (array $event): bool => in_array($event['event_type'] ?? null, [
                LedgerEventType::KernelPipelineAccepted->value,
                LedgerEventType::KernelPipelineRejected->value,
            ], true))
            ->map(fn (array $event): array => $this->support->kernelPipelineEventFromEvent($event))
            ->values();

        return [
            'envelope_id' => $envelopeId,
            ...$this->kernelPipelineEventSummary($events),
        ];
    }

    /**
     * @return array{
     *     envelope_id:string,
     *     decision_event_count:int,
     *     valid_receipt_hash_count:int,
     *     valid_chain_hash_count:int,
     *     invalid_count:int,
     *     latest_receipt_id:string|null,
     *     latest_chain_hash:string|null,
     *     review_signal:array<string,mixed>,
     *     events:array<int,array<string,mixed>>
     * }
     */
    public function decisionReceiptReportForEnvelope(string $envelopeId): array
    {
        $events = collect($this->eventsForEnvelope($envelopeId))
            ->filter(fn (array $event): bool => ($event['event_type'] ?? null) === LedgerEventType::DecisionIssued->value)
            ->map(fn (array $event): array => $this->decisionReceiptEventFromEvent($event))
            ->values();

        return [
            'envelope_id' => $envelopeId,
            ...$this->decisionReceiptEventSummary($events),
        ];
    }

    /**
     * @param  array<string,string|null>  $filters
     * @return array<string,mixed>
     */
    public function kernelPipelineReportForWindow(CarbonInterface $since, ?CarbonInterface $until = null, array $filters = []): array
    {
        $until ??= now();
        $filters = $this->support->normalizedKernelPipelineFilters($filters);

        if (! $this->tableAvailable()) {
            return [
                'available' => false,
                'window' => [
                    'since' => $since->toJSON(),
                    'until' => $until->toJSON(),
                ],
                'filters' => $filters,
                'envelope_count' => 0,
                ...$this->kernelPipelineEventSummary(collect()),
                'recent_events' => [],
            ];
        }

        $events = $this->ledgerQuery()
            ->whereIn('event_type', [
                LedgerEventType::KernelPipelineAccepted->value,
                LedgerEventType::KernelPipelineRejected->value,
            ])
            ->whereBetween('occurred_at', [$since, $until])
            ->orderBy('occurred_at')
            ->orderBy('event_id')
            ->get()
            ->map(fn (AtlasLedgerEvent $event): array => $this->support->kernelPipelineEventFromEvent($event->toArray()))
            ->filter(fn (array $event): bool => $this->support->matchesKernelPipelineFilters($event, $filters))
            ->values();
        $summary = $this->kernelPipelineEventSummary($events);

        return [
            'available' => true,
            'window' => [
                'since' => $since->toJSON(),
                'until' => $until->toJSON(),
            ],
            'filters' => $filters,
            'envelope_count' => $events->pluck('envelope_id')->filter()->unique()->count(),
            ...array_diff_key($summary, ['events' => true]),
            'recent_events' => $events
                ->reverse()
                ->take(10)
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array{
     *     available:bool,
     *     window:array{since:string,until:string},
     *     repair_event_count:int,
     *     envelope_count:int,
     *     initiated_count:int,
     *     completed_count:int,
     *     executed_count:int,
     *     status_counts:array<string,int>,
     *     strategy_counts:array<string,int>,
     *     reason_counts:array<string,int>,
     *     latest_status:string|null,
     *     latest_strategy:string|null,
     *     requires_human_review:bool,
     *     filters:array<string,string>,
     *     recent_events:array<int,array<string,mixed>>
     * }
     */
    /**
     * @param  array<string,string|null>  $filters
     */
    public function repairReportForWindow(CarbonInterface $since, ?CarbonInterface $until = null, array $filters = []): array
    {
        $until ??= now();
        $filters = $this->support->normalizedRepairFilters($filters);

        if (! $this->tableAvailable()) {
            return [
                'available' => false,
                'window' => [
                    'since' => $since->toJSON(),
                    'until' => $until->toJSON(),
                ],
                'filters' => $filters,
                'envelope_count' => 0,
                ...array_diff_key($this->repairEventSummary(collect()), ['events' => true]),
                'recent_events' => [],
            ];
        }

        $events = $this->ledgerQuery()
            ->whereIn('event_type', [
                LedgerEventType::RepairInitiated->value,
                LedgerEventType::RepairCompleted->value,
            ])
            ->whereBetween('occurred_at', [$since, $until])
            ->orderBy('occurred_at')
            ->orderBy('event_id')
            ->get()
            ->map(fn (AtlasLedgerEvent $event): array => $this->support->repairEventFromEvent($event->toArray()))
            ->filter(fn (array $event): bool => $this->support->matchesRepairFilters($event, $filters))
            ->values();
        $summary = $this->repairEventSummary($events);

        return [
            'available' => true,
            'window' => [
                'since' => $since->toJSON(),
                'until' => $until->toJSON(),
            ],
            'filters' => $filters,
            'envelope_count' => $events->pluck('envelope_id')->filter()->unique()->count(),
            ...array_diff_key($summary, ['events' => true]),
            'recent_events' => $events
                ->reverse()
                ->take(10)
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function selfImprovementScheduleReportForWindow(CarbonInterface $since, ?CarbonInterface $until = null): array
    {
        $until ??= now();

        if (! $this->tableAvailable()) {
            return [
                'available' => false,
                'window' => [
                    'since' => $since->toJSON(),
                    'until' => $until->toJSON(),
                ],
                ...array_diff_key($this->selfImprovementScheduleEventSummary(collect()), ['events' => true]),
                'recent_events' => [],
            ];
        }

        $events = $this->ledgerQuery()
            ->where('event_type', LedgerEventType::SelfImprovementScheduleObserved->value)
            ->whereBetween('occurred_at', [$since, $until])
            ->orderBy('occurred_at')
            ->orderBy('event_id')
            ->get()
            ->map(fn (AtlasLedgerEvent $event): array => $this->selfImprovementScheduleEventFromEvent($event->toArray()))
            ->values();
        $completionByEnvelope = $this->selfImprovementCompletionByEnvelope($since, $until);
        $events = $events
            ->map(fn (array $event): array => $this->withSelfImprovementCompletion($event, $completionByEnvelope[$event['envelope_id'] ?? ''] ?? null))
            ->values();
        $inboxItemsById = $this->selfImprovementInboxItemsById($events
            ->pluck('emitted_inbox_item_ids')
            ->flatten()
            ->filter()
            ->unique()
            ->values()
            ->all());
        $inboxHydrationAvailable = DatabaseTableAvailability::has('ai_inbox_items');
        $events = $events
            ->map(fn (array $event): array => $this->withSelfImprovementInboxItems($event, $inboxItemsById, $inboxHydrationAvailable))
            ->values();
        $summary = $this->selfImprovementScheduleEventSummary($events);

        return [
            'available' => true,
            'window' => [
                'since' => $since->toJSON(),
                'until' => $until->toJSON(),
            ],
            ...array_diff_key($summary, ['events' => true]),
            'recent_events' => $events
                ->reverse()
                ->take(10)
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string,string|null>  $filters
     * @return array<string,mixed>
     */
    public function inboxActionReportForWindow(CarbonInterface $since, ?CarbonInterface $until = null, array $filters = []): array
    {
        $until ??= now();
        $filters = $this->support->normalizedInboxActionFilters($filters);

        if (! $this->tableAvailable()) {
            return [
                'available' => false,
                'window' => [
                    'since' => $since->toJSON(),
                    'until' => $until->toJSON(),
                ],
                'filters' => $filters,
                'inbox_action_count' => 0,
                ...array_diff_key($this->inboxActionSummary(collect()), ['events' => true]),
                'recent_events' => [],
            ];
        }

        $events = $this->ledgerQuery()
            ->where('event_type', LedgerEventType::InboxActionRecorded->value)
            ->whereBetween('occurred_at', [$since, $until])
            ->orderBy('occurred_at')
            ->orderBy('event_id')
            ->get()
            ->map(fn (AtlasLedgerEvent $event): array => $this->inboxActionEventFromEvent($event->toArray()))
            ->filter(fn (array $event): bool => $this->support->matchesInboxActionFilters($event, $filters))
            ->values();
        $summary = $this->inboxActionSummary($events);

        return [
            'available' => true,
            'window' => [
                'since' => $since->toJSON(),
                'until' => $until->toJSON(),
            ],
            'filters' => $filters,
            'envelope_count' => $events->pluck('envelope_id')->filter()->unique()->count(),
            ...array_diff_key($summary, ['events' => true]),
            'recent_events' => $events
                ->reverse()
                ->take(10)
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string,string|null>  $filters
     * @return array<string,mixed>
     */
    public function agentBehaviorReportForWindow(CarbonInterface $since, ?CarbonInterface $until = null, array $filters = []): array
    {
        $until ??= now();
        $filters = $this->support->normalizedAgentBehaviorFilters($filters);

        if (! $this->tableAvailable()) {
            return [
                'available' => false,
                'window' => [
                    'since' => $since->toJSON(),
                    'until' => $until->toJSON(),
                ],
                'filters' => $filters,
                'agent_behavior_event_count' => 0,
                ...array_diff_key($this->agentBehaviorSummary(collect()), ['events' => true]),
                'recent_events' => [],
            ];
        }

        $events = $this->ledgerQuery()
            ->where('event_type', LedgerEventType::GateEvaluated->value)
            ->where('emitter_stage', 'atlas.agent_behavior_quality_gate')
            ->whereBetween('occurred_at', [$since, $until])
            ->orderBy('occurred_at')
            ->orderBy('event_id')
            ->get()
            ->map(fn (AtlasLedgerEvent $event): array => $this->support->agentBehaviorEventFromEvent($event->toArray()))
            ->filter(fn (array $event): bool => $this->support->matchesAgentBehaviorFilters($event, $filters))
            ->values();
        $summary = $this->agentBehaviorSummary($events);

        return [
            'available' => true,
            'window' => [
                'since' => $since->toJSON(),
                'until' => $until->toJSON(),
            ],
            'filters' => $filters,
            'envelope_count' => $events->pluck('envelope_id')->filter()->unique()->count(),
            ...array_diff_key($summary, ['events' => true]),
            'recent_events' => $events
                ->reverse()
                ->take(10)
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array{
     *     available:bool,
     *     window:array{since:string,until:string},
     *     observation_count:int,
     *     envelope_count:int,
     *     status_counts:array<string,int>,
     *     success_count:int,
     *     failure_count:int,
     *     worst_status:string|null,
     *     worst_severity:string|null,
     *     dimensions:array<string,array<string,int>>,
     *     stages:array<string,array<string,mixed>>,
     *     recent_breaches:array<int,array<string,mixed>>
     * }
     */
    /**
     * @param  array<string,string|null>  $filters
     */
    public function sloReportForWindow(CarbonInterface $since, ?CarbonInterface $until = null, array $filters = []): array
    {
        $until ??= now();
        $filters = $this->support->normalizedDimensionFilters($filters);

        if (! $this->tableAvailable()) {
            return [
                'available' => false,
                'window' => [
                    'since' => $since->toJSON(),
                    'until' => $until->toJSON(),
                ],
                'filters' => $filters,
                'envelope_count' => 0,
                ...$this->sloObservationSummary(new Collection),
                'recent_breaches' => [],
            ];
        }

        $events = $this->ledgerQuery()
            ->where('event_type', LedgerEventType::SloObserved->value)
            ->whereBetween('occurred_at', [$since, $until])
            ->orderBy('occurred_at')
            ->orderBy('event_id')
            ->get();
        $observations = $events
            ->map(fn (AtlasLedgerEvent $event): array => $this->support->sloObservationFromEvent($event->toArray()))
            ->filter(fn (array $observation): bool => $this->support->matchesDimensionFilters($observation, $filters))
            ->values();
        $summary = $this->sloObservationSummary($observations);

        return [
            'available' => true,
            'window' => [
                'since' => $since->toJSON(),
                'until' => $until->toJSON(),
            ],
            'filters' => $filters,
            'envelope_count' => $observations->pluck('envelope_id')->filter()->unique()->count(),
            ...$summary,
            'recent_breaches' => $observations
                ->filter(fn (array $observation): bool => in_array($observation['status'], ['warning', 'breach'], true) || $observation['success'] === false)
                ->reverse()
                ->take(10)
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $observations
     * @return array{
     *     observation_count:int,
     *     status_counts:array<string,int>,
     *     success_count:int,
     *     failure_count:int,
     *     worst_status:string|null,
     *     worst_severity:string|null,
     *     dimensions:array<string,array<string,int>>,
     *     stages:array<string,array<string,mixed>>
     * }
     */
    private function sloObservationSummary(Collection $observations): array
    {
        $statusCounts = $observations->countBy('status')->all();
        $successCount = $observations->where('success', true)->count();
        $failureCount = $observations->where('success', false)->count();
        $worstStatus = $this->support->worstSloValue($observations->pluck('status')->all(), [
            'breach' => 4,
            'warning' => 3,
            'ok' => 2,
            'unknown' => 1,
        ]);
        $worstSeverity = $this->support->worstSloValue($observations->pluck('severity')->all(), [
            'critical' => 5,
            'high' => 4,
            'medium' => 3,
            'low' => 2,
            'unknown' => 1,
        ]);
        $stages = $observations
            ->groupBy('stage')
            ->map(fn (Collection $stageObservations): array => $this->support->sloStageSummary($stageObservations))
            ->all();
        $reviewSignal = $this->sloReviewSignal($observations, $worstStatus, $worstSeverity, $failureCount, $stages);

        return [
            'observation_count' => $observations->count(),
            'status_counts' => $statusCounts,
            'success_count' => $successCount,
            'failure_count' => $failureCount,
            'worst_status' => $worstStatus,
            'worst_severity' => $worstSeverity,
            'dimensions' => $this->support->dimensionSummary($observations),
            'stages' => $stages,
            'review_signal' => $reviewSignal,
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $observations
     * @param  array<string,array<string,mixed>>  $stages
     * @return array{status:string,severity:string,review_required:bool,reasons:array<int,string>,recommended_action:string}
     */
    private function sloReviewSignal(Collection $observations, ?string $worstStatus, ?string $worstSeverity, int $failureCount, array $stages): array
    {
        $reasons = collect($stages)
            ->pluck('violations')
            ->flatten()
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($observations->isEmpty()) {
            return [
                'status' => 'unknown',
                'severity' => 'low',
                'review_required' => false,
                'reasons' => ['no_slo_observations_in_window'],
                'recommended_action' => 'wait_for_slo_evidence',
            ];
        }

        if ($worstStatus === 'breach' || $failureCount > 0) {
            return [
                'status' => 'breach',
                'severity' => in_array($worstSeverity, ['critical', 'high'], true) ? 'high' : 'medium',
                'review_required' => true,
                'reasons' => array_values(array_unique([...$reasons, 'slo_breach_detected'])),
                'recommended_action' => 'open_reviewable_slo_regression_proposal',
            ];
        }

        if ($worstStatus === 'warning') {
            return [
                'status' => 'warning',
                'severity' => in_array($worstSeverity, ['critical', 'high'], true) ? 'medium' : 'low',
                'review_required' => true,
                'reasons' => array_values(array_unique([...$reasons, 'slo_warning_detected'])),
                'recommended_action' => 'open_reviewable_slo_drift_proposal',
            ];
        }

        return [
            'status' => 'ok',
            'severity' => 'none',
            'review_required' => false,
            'reasons' => [],
            'recommended_action' => 'none',
        ];
    }

    /**
     * @param  array<string,mixed>  $event
     * @return array<string,mixed>
     */
    private function decisionReceiptEventFromEvent(array $event): array
    {
        $payload = (array) ($event['payload'] ?? []);
        $receiptHash = $this->nullableScalar(data_get($payload, 'receipt_hash'));
        $chainHash = $this->nullableScalar(data_get($payload, 'chain_hash'));
        $parentChainHash = $this->nullableScalar(data_get($payload, 'parent_chain_hash'));
        $expectedReceiptHash = $receiptHash !== null ? DecisionReceiptHash::hash([
            'receipt_id' => $this->scalarOrDefault(data_get($payload, 'receipt_id'), ''),
            'envelope_id' => $this->scalarOrDefault(data_get($payload, 'envelope_id'), ''),
            'schema_version' => $this->scalarOrDefault(data_get($payload, 'schema_version'), ''),
            'issued_at' => $this->scalarOrDefault(data_get($payload, 'issued_at'), ''),
            'expires_at' => $this->scalarOrDefault(data_get($payload, 'expires_at'), ''),
            'dry_run' => (bool) data_get($payload, 'dry_run', false),
            'signed_by' => $this->scalarOrDefault(data_get($payload, 'signed_by'), ''),
            'inputs_hash' => $this->scalarOrDefault(data_get($payload, 'inputs_hash'), ''),
            'parent_receipt_id' => $this->nullableScalar(data_get($payload, 'parent_receipt_id')),
        ]) : null;
        $expectedChainHash = ($chainHash !== null && $receiptHash !== null) ? DecisionReceiptHash::hash([
            'parent_chain_hash' => $parentChainHash,
            'receipt_hash' => $receiptHash,
        ]) : null;
        $receiptIntegrityStatus = $receiptHash === null
            ? 'unverifiable'
            : (hash_equals($expectedReceiptHash ?? '', $receiptHash) ? 'ok' : 'mismatch');
        $chainIntegrityStatus = $chainHash === null || $receiptHash === null
            ? 'unverifiable'
            : (hash_equals($expectedChainHash ?? '', $chainHash) ? 'ok' : 'mismatch');

        return [
            'event_id' => $event['event_id'] ?? null,
            'event_type' => $event['event_type'] ?? null,
            'envelope_id' => $event['envelope_id'] ?? null,
            'receipt_id' => $event['receipt_id'] ?? data_get($payload, 'receipt_id'),
            'correlation_id' => $event['correlation_id'] ?? null,
            'emitter_stage' => $event['emitter_stage'] ?? null,
            'payload_hash' => $event['payload_hash'] ?? null,
            'occurred_at' => $event['occurred_at'] ?? null,
            'schema_version' => data_get($payload, 'schema_version'),
            'domain' => data_get($payload, 'domain'),
            'flow' => data_get($payload, 'flow'),
            'risk' => data_get($payload, 'risk'),
            'provider' => data_get($payload, 'provider_selection.primary'),
            'model' => data_get($payload, 'provider_selection.model'),
            'decision_id' => data_get($payload, 'metadata.decision_id', data_get($payload, 'receipt_id')),
            'routing_basis' => data_get($payload, 'metadata.routing_basis'),
            'evidence_refs' => AiStringListNormalizer::truthyTrimmedScalarValues((array) data_get($payload, 'metadata.evidence_refs', [])),
            'kernel_decision' => data_get($payload, 'metadata.kernel_decision'),
            'admission_decision' => data_get($payload, 'metadata.admission_decision'),
            'dry_run' => (bool) data_get($payload, 'dry_run', false),
            'inputs_hash' => data_get($payload, 'inputs_hash'),
            'receipt_hash' => $receiptHash,
            'expected_receipt_hash' => $expectedReceiptHash,
            'receipt_integrity_status' => $receiptIntegrityStatus,
            'parent_receipt_id' => data_get($payload, 'parent_receipt_id'),
            'parent_chain_hash' => $parentChainHash,
            'chain_hash' => $chainHash,
            'expected_chain_hash' => $expectedChainHash,
            'chain_integrity_status' => $chainIntegrityStatus,
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $events
     * @return array<string,mixed>
     */
    private function decisionReceiptEventSummary(Collection $events): array
    {
        $invalidEvents = $events->filter(fn (array $event): bool => in_array('mismatch', [
            $event['receipt_integrity_status'] ?? null,
            $event['chain_integrity_status'] ?? null,
        ], true));
        $latest = $events->last();

        return [
            'decision_event_count' => $events->count(),
            'valid_receipt_hash_count' => $events->where('receipt_integrity_status', 'ok')->count(),
            'valid_chain_hash_count' => $events->where('chain_integrity_status', 'ok')->count(),
            'invalid_count' => $invalidEvents->count(),
            'latest_receipt_id' => is_array($latest) ? ($latest['receipt_id'] ?? null) : null,
            'latest_chain_hash' => is_array($latest) ? ($latest['chain_hash'] ?? null) : null,
            'review_signal' => $this->decisionReceiptReviewSignal($events, $invalidEvents),
            'events' => $events->all(),
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $events
     * @param  Collection<int,array<string,mixed>>  $invalidEvents
     * @return array{status:string,severity:string,review_required:bool,reasons:array<int,string>,recommended_action:string}
     */
    private function decisionReceiptReviewSignal(Collection $events, Collection $invalidEvents): array
    {
        if ($events->isEmpty()) {
            return [
                'status' => 'unknown',
                'severity' => 'low',
                'review_required' => false,
                'reasons' => ['no_decision_receipt_events_for_envelope'],
                'recommended_action' => 'wait_for_decision_receipt_evidence',
            ];
        }

        if ($invalidEvents->isNotEmpty()) {
            return [
                'status' => 'breach',
                'severity' => 'high',
                'review_required' => true,
                'reasons' => array_values(array_unique($invalidEvents
                    ->flatMap(fn (array $event): array => [
                        ($event['receipt_integrity_status'] ?? null) === 'mismatch' ? 'decision_receipt_hash_mismatch' : null,
                        ($event['chain_integrity_status'] ?? null) === 'mismatch' ? 'decision_receipt_chain_hash_mismatch' : null,
                    ])
                    ->filter()
                    ->all())),
                'recommended_action' => 'open_reviewable_decision_receipt_replay_proposal',
            ];
        }

        return [
            'status' => 'ok',
            'severity' => 'none',
            'review_required' => false,
            'reasons' => [],
            'recommended_action' => 'none',
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $events
     * @return array<string,mixed>
     */
    private function agentBehaviorSummary(Collection $events): array
    {
        $findingCodes = $events->pluck('finding_codes')->flatten()->filter()->values();
        $findingSeverities = $events->pluck('finding_severities')->flatten()->filter()->values();
        $scoreAvg = $events->isEmpty()
            ? null
            : round($events->pluck('score')->map(fn (mixed $score): int => (int) $score)->avg(), 2);
        $reviewSignal = $this->agentBehaviorReviewSignal($events, $findingCodes);

        return [
            'agent_behavior_event_count' => $events->count(),
            'finding_count' => $findingCodes->count(),
            'status_counts' => $events->pluck('status')->filter()->countBy()->all(),
            'finding_code_counts' => $findingCodes->countBy()->all(),
            'finding_severity_counts' => $findingSeverities->countBy()->all(),
            'provider_counts' => $events->pluck('provider')->filter()->countBy()->all(),
            'agent_slug_counts' => $events->pluck('agent_slug')->filter()->countBy()->all(),
            'average_score' => $scoreAvg,
            'review_signal' => $reviewSignal,
            'events' => $events->all(),
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $events
     * @param  Collection<int,string>  $findingCodes
     * @return array{status:string,severity:string,review_required:bool,reasons:array<int,string>,recommended_action:string}
     */
    private function agentBehaviorReviewSignal(Collection $events, Collection $findingCodes): array
    {
        if ($events->isEmpty()) {
            return [
                'status' => 'unknown',
                'severity' => 'low',
                'review_required' => false,
                'reasons' => ['no_agent_behavior_gate_events_in_window'],
                'recommended_action' => 'wait_for_agent_behavior_evidence',
            ];
        }

        $recurring = $findingCodes
            ->countBy()
            ->filter(fn (int $count): bool => $count >= 2)
            ->keys()
            ->values()
            ->all();

        if ($recurring !== []) {
            return [
                'status' => 'warning',
                'severity' => 'medium',
                'review_required' => true,
                'reasons' => array_map(fn (string $code): string => 'recurring_agent_behavior_finding:'.$code, $recurring),
                'recommended_action' => 'open_reviewable_agent_behavior_quality_proposal',
            ];
        }

        return [
            'status' => 'ok',
            'severity' => 'none',
            'review_required' => false,
            'reasons' => [],
            'recommended_action' => 'none',
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $events
     * @return array{
     *     repair_event_count:int,
     *     initiated_count:int,
     *     completed_count:int,
     *     executed_count:int,
     *     status_counts:array<string,int>,
     *     strategy_counts:array<string,int>,
     *     reason_counts:array<string,int>,
     *     latest_status:string|null,
     *     latest_strategy:string|null,
     *     requires_human_review:bool,
     *     events:array<int,array<string,mixed>>
     * }
     */
    private function repairEventSummary(Collection $events): array
    {
        $latest = $events->last();
        $statusCounts = $events->pluck('status')->filter()->countBy()->all();
        $strategyCounts = $events->pluck('strategy')->filter()->countBy()->all();
        $reasonCounts = $events->pluck('reasons')->flatten()->filter()->countBy()->all();
        $requiresHumanReview = $events->contains(fn (array $event): bool => ($event['status'] ?? null) === 'needs_human_review' || ($event['strategy'] ?? null) === 'human_review');
        $reviewSignal = $this->repairReviewSignal($events, $statusCounts, $strategyCounts, $reasonCounts, $requiresHumanReview);

        return [
            'repair_event_count' => $events->count(),
            'initiated_count' => $events->where('event_type', LedgerEventType::RepairInitiated->value)->count(),
            'completed_count' => $events->where('event_type', LedgerEventType::RepairCompleted->value)->count(),
            'executed_count' => $events->where('repair_executed', true)->count(),
            'status_counts' => $statusCounts,
            'strategy_counts' => $strategyCounts,
            'reason_counts' => $reasonCounts,
            'latest_status' => is_array($latest) ? ($latest['status'] ?? null) : null,
            'latest_strategy' => is_array($latest) ? ($latest['strategy'] ?? null) : null,
            'requires_human_review' => $requiresHumanReview,
            'review_signal' => $reviewSignal,
            'events' => $events->all(),
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $events
     * @param  array<string,int>  $statusCounts
     * @param  array<string,int>  $strategyCounts
     * @param  array<string,int>  $reasonCounts
     * @return array{status:string,severity:string,review_required:bool,reasons:array<int,string>,recommended_action:string}
     */
    private function repairReviewSignal(Collection $events, array $statusCounts, array $strategyCounts, array $reasonCounts, bool $requiresHumanReview): array
    {
        if ($events->isEmpty()) {
            return [
                'status' => 'unknown',
                'severity' => 'low',
                'review_required' => false,
                'reasons' => ['no_repair_events_in_window'],
                'recommended_action' => 'wait_for_repair_loop_evidence',
            ];
        }

        $blockedCount = (int) ($statusCounts['repair_blocked'] ?? 0) + (int) ($statusCounts['repair_exhausted'] ?? 0);
        $reasons = array_values(array_unique([
            ...array_keys($reasonCounts),
            ...($requiresHumanReview ? ['repair_requires_human_review'] : []),
            ...($blockedCount > 0 ? ['repair_blocked_or_exhausted'] : []),
        ]));

        if ($blockedCount > 0) {
            return [
                'status' => 'breach',
                'severity' => 'high',
                'review_required' => true,
                'reasons' => $reasons,
                'recommended_action' => 'open_reviewable_repair_loop_policy_proposal',
            ];
        }

        if ($requiresHumanReview) {
            return [
                'status' => 'warning',
                'severity' => 'medium',
                'review_required' => true,
                'reasons' => $reasons,
                'recommended_action' => 'open_reviewable_repair_loop_human_review_proposal',
            ];
        }

        $dominantStrategy = collect($strategyCounts)->filter(fn (int $count): bool => $count >= 2)->keys()->first();
        if (is_string($dominantStrategy) && $dominantStrategy !== '') {
            return [
                'status' => 'warning',
                'severity' => 'low',
                'review_required' => true,
                'reasons' => ['recurring_repair_strategy:'.$dominantStrategy],
                'recommended_action' => 'open_reviewable_repair_loop_pattern_proposal',
            ];
        }

        return [
            'status' => 'ok',
            'severity' => 'none',
            'review_required' => false,
            'reasons' => [],
            'recommended_action' => 'none',
        ];
    }

    private function nullableScalar(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function scalarOrDefault(mixed $value, string $default): string
    {
        return $this->nullableScalar($value) ?? $default;
    }

    /**
     * @param  array<string,mixed>  $event
     * @return array<string,mixed>
     */
    private function selfImprovementScheduleEventFromEvent(array $event): array
    {
        $scheduleHealth = (array) data_get($event, 'payload.schedule_health', []);

        return [
            'event_id' => $event['event_id'] ?? null,
            'event_type' => $event['event_type'] ?? null,
            'envelope_id' => $event['envelope_id'] ?? null,
            'correlation_id' => $event['correlation_id'] ?? null,
            'emitter_stage' => $event['emitter_stage'] ?? null,
            'payload_hash' => $event['payload_hash'] ?? null,
            'occurred_at' => $event['occurred_at'] ?? null,
            'flow' => data_get($event, 'payload.flow'),
            'health_status' => (string) ($scheduleHealth['health_status'] ?? 'unknown'),
            'issues' => array_values((array) ($scheduleHealth['issues'] ?? [])),
            'enabled' => (bool) ($scheduleHealth['enabled'] ?? false),
            'schedulable' => (bool) ($scheduleHealth['schedulable'] ?? false),
            'scheduler_status' => (string) data_get($scheduleHealth, 'scheduler_registration.status', 'unknown'),
            'registered_command_count' => (int) data_get($scheduleHealth, 'scheduler_registration.registered_command_count', 0),
            'skipped_reason' => data_get($scheduleHealth, 'scheduler_registration.skipped_reason'),
            'flow_count' => (int) ($scheduleHealth['flow_count'] ?? 0),
            'cadence_counts' => (array) ($scheduleHealth['cadence_counts'] ?? []),
            'invalid_flow_count' => (int) ($scheduleHealth['invalid_flow_count'] ?? 0),
            'defaulted' => (bool) ($scheduleHealth['defaulted'] ?? false),
            'emit' => (bool) ($scheduleHealth['emit'] ?? false),
            'plan_hash' => $scheduleHealth['plan_hash'] ?? null,
            'plan_hash_algorithm' => $scheduleHealth['plan_hash_algorithm'] ?? null,
            'time' => $scheduleHealth['time'] ?? null,
            'timezone' => $scheduleHealth['timezone'] ?? null,
            'next_run_at' => $scheduleHealth['next_run_at'] ?? null,
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function selfImprovementCompletionByEnvelope(CarbonInterface $since, CarbonInterface $until): array
    {
        return $this->ledgerQuery()
            ->where('event_type', LedgerEventType::OperationCompleted->value)
            ->where('emitter_stage', 'atlas.self_improvement')
            ->whereBetween('occurred_at', [$since, $until])
            ->orderBy('occurred_at')
            ->orderBy('event_id')
            ->get()
            ->mapWithKeys(function (AtlasLedgerEvent $event): array {
                $payload = (array) ($event->payload ?? []);

                return [(string) $event->envelope_id => [[
                    'event_id' => $event->event_id,
                    'finding_count' => (int) ($payload['finding_count'] ?? 0),
                    'emitted_count' => (int) ($payload['emitted_count'] ?? 0),
                    'emitted_inbox_item_ids' => array_values((array) ($payload['emitted_inbox_item_ids'] ?? [])),
                    'completed_at' => $event->occurred_at?->toJSON(),
                ]]];
            })
            ->map(fn (array $items): array => end($items) ?: [])
            ->all();
    }

    /**
     * @param  array<string,mixed>|null  $completion
     * @return array<string,mixed>
     */
    private function withSelfImprovementCompletion(array $event, ?array $completion): array
    {
        $completion ??= [];

        return [
            ...$event,
            'completed' => $completion !== [],
            'finding_count' => (int) ($completion['finding_count'] ?? 0),
            'emitted_count' => (int) ($completion['emitted_count'] ?? 0),
            'emitted_inbox_item_ids' => array_values((array) ($completion['emitted_inbox_item_ids'] ?? [])),
            'completed_at' => $completion['completed_at'] ?? null,
        ];
    }

    /**
     * @param  array<int,mixed>  $ids
     * @return array<string,array<string,mixed>>
     */
    private function selfImprovementInboxItemsById(array $ids): array
    {
        $ids = collect($ids)
            ->map(fn (mixed $id): string => trim((string) $id))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($ids === [] || ! DatabaseTableAvailability::has('ai_inbox_items')) {
            return [];
        }

        return AiInboxItem::query()
            ->whereIn('id', $ids)
            ->get()
            ->mapWithKeys(fn (AiInboxItem $item): array => [
                $item->id => $this->selfImprovementInboxItemSummary($item),
            ])
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function selfImprovementInboxItemSummary(AiInboxItem $item): array
    {
        return [
            'id' => $item->id,
            'status' => $item->status,
            'type' => $item->type,
            'category' => $item->category,
            'severity' => $item->severity,
            'title' => $item->title,
            'source_type' => $item->source_type,
            'source_id' => $item->source_id,
            'deep_link' => $item->deep_link,
            'review_signal' => data_get($item->payload, 'proposal_contract.review_signal')
                ?? data_get($item->payload, 'review_signal'),
            'created_at' => $item->created_at?->toJSON(),
            'updated_at' => $item->updated_at?->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $event
     * @param  array<string,array<string,mixed>>  $inboxItemsById
     * @return array<string,mixed>
     */
    private function withSelfImprovementInboxItems(array $event, array $inboxItemsById, bool $hydrationAvailable): array
    {
        $ids = array_values((array) ($event['emitted_inbox_item_ids'] ?? []));
        $missingIds = collect($ids)
            ->map(fn (mixed $id): string => trim((string) $id))
            ->filter()
            ->reject(fn (string $id): bool => array_key_exists($id, $inboxItemsById))
            ->values()
            ->all();

        return [
            ...$event,
            'emitted_inbox_item_hydration_available' => $hydrationAvailable,
            'emitted_inbox_item_missing_ids' => $missingIds,
            'emitted_inbox_items' => collect($ids)
                ->map(fn (mixed $id): ?array => $inboxItemsById[(string) $id] ?? null)
                ->filter()
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $events
     * @return array<string,mixed>
     */
    private function selfImprovementScheduleEventSummary(Collection $events): array
    {
        $latest = $events->last();
        $issueCounts = $events->pluck('issues')->flatten()->filter()->countBy()->all();
        $emittedInboxItemIds = $events
            ->pluck('emitted_inbox_item_ids')
            ->flatten()
            ->filter()
            ->unique()
            ->values()
            ->all();
        $emittedInboxItems = $events
            ->pluck('emitted_inbox_items')
            ->flatten(1)
            ->filter()
            ->unique('id')
            ->values()
            ->all();
        $missingInboxItemIds = $events
            ->pluck('emitted_inbox_item_missing_ids')
            ->flatten()
            ->filter()
            ->unique()
            ->values()
            ->all();
        $warningCount = $events->filter(fn (array $event): bool => in_array($event['health_status'] ?? null, ['warning', 'disabled'], true)
            || ($event['scheduler_status'] ?? null) === 'skipped'
            || (int) ($event['invalid_flow_count'] ?? 0) > 0)->count();
        $reviewSignal = $this->selfImprovementScheduleReviewSignal($events, $warningCount, $issueCounts);

        return [
            'schedule_observation_count' => $events->count(),
            'envelope_count' => $events->pluck('envelope_id')->filter()->unique()->count(),
            'health_status_counts' => $events->pluck('health_status')->filter()->countBy()->all(),
            'scheduler_status_counts' => $events->pluck('scheduler_status')->filter()->countBy()->all(),
            'issue_counts' => $issueCounts,
            'warning_count' => $warningCount,
            'completed_count' => $events->where('completed', true)->count(),
            'emitted_count' => $events->sum(fn (array $event): int => (int) ($event['emitted_count'] ?? 0)),
            'emitted_inbox_item_ids' => $emittedInboxItemIds,
            'emitted_inbox_items' => $emittedInboxItems,
            'emitted_inbox_item_hydration_available' => DatabaseTableAvailability::has('ai_inbox_items'),
            'emitted_inbox_item_missing_ids' => $missingInboxItemIds,
            'latest_health_status' => is_array($latest) ? ($latest['health_status'] ?? null) : null,
            'latest_scheduler_status' => is_array($latest) ? ($latest['scheduler_status'] ?? null) : null,
            'latest_plan_hash' => is_array($latest) ? ($latest['plan_hash'] ?? null) : null,
            'latest_next_run_at' => is_array($latest) ? ($latest['next_run_at'] ?? null) : null,
            'review_required' => $warningCount > 0,
            'health' => [
                'status' => $warningCount > 0 ? 'warning' : ($events->isEmpty() ? 'unknown' : 'ok'),
                'reasons' => $warningCount > 0 ? array_keys($issueCounts + ['self_improvement_schedule_warning_observed' => 1]) : ($events->isEmpty() ? ['no_self_improvement_schedule_observations_in_window'] : []),
            ],
            'review_signal' => $reviewSignal,
            'events' => $events->all(),
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $events
     * @param  array<string,int>  $issueCounts
     * @return array{status:string,severity:string,review_required:bool,reasons:array<int,string>,recommended_action:string}
     */
    private function selfImprovementScheduleReviewSignal(Collection $events, int $warningCount, array $issueCounts): array
    {
        if ($events->isEmpty()) {
            return [
                'status' => 'unknown',
                'severity' => 'low',
                'review_required' => false,
                'reasons' => ['no_self_improvement_schedule_observations_in_window'],
                'recommended_action' => 'wait_for_next_self_improvement_cycle',
            ];
        }

        if ($warningCount === 0) {
            return [
                'status' => 'ok',
                'severity' => 'none',
                'review_required' => false,
                'reasons' => [],
                'recommended_action' => 'none',
            ];
        }

        $reasons = array_keys($issueCounts + ['self_improvement_schedule_warning_observed' => 1]);
        $hasSkippedScheduler = $events->contains(fn (array $event): bool => ($event['scheduler_status'] ?? null) === 'skipped');
        $hasInvalidFlows = $events->contains(fn (array $event): bool => (int) ($event['invalid_flow_count'] ?? 0) > 0);
        $severity = $hasSkippedScheduler ? 'high' : ($hasInvalidFlows || $warningCount > 1 ? 'medium' : 'low');

        return [
            'status' => 'warning',
            'severity' => $severity,
            'review_required' => true,
            'reasons' => $reasons,
            'recommended_action' => 'open_reviewable_self_improvement_schedule_proposal',
        ];
    }

    /**
     * @param  array<string,mixed>  $event
     * @return array<string,mixed>
     */
    private function inboxActionEventFromEvent(array $event): array
    {
        $payload = (array) data_get($event, 'payload', []);
        $inboxItem = (array) data_get($payload, 'inbox_item', []);
        $actor = (array) data_get($payload, 'actor', []);
        $result = (array) data_get($payload, 'result', []);

        return [
            'event_id' => $event['event_id'] ?? null,
            'event_type' => $event['event_type'] ?? null,
            'envelope_id' => $event['envelope_id'] ?? null,
            'correlation_id' => $event['correlation_id'] ?? null,
            'emitter_stage' => $event['emitter_stage'] ?? null,
            'payload_hash' => $event['payload_hash'] ?? null,
            'occurred_at' => $event['occurred_at'] ?? null,
            'schema_version' => data_get($payload, 'schema_version'),
            'action' => data_get($payload, 'action'),
            'idempotency_key' => data_get($payload, 'idempotency_key'),
            'actor_type' => data_get($actor, 'type'),
            'actor_id' => data_get($actor, 'id'),
            'inbox_item_id' => data_get($inboxItem, 'id'),
            'inbox_item_type' => data_get($inboxItem, 'type'),
            'inbox_item_category' => data_get($inboxItem, 'category'),
            'inbox_item_severity' => data_get($inboxItem, 'severity'),
            'inbox_item_status' => data_get($inboxItem, 'status'),
            'source_type' => data_get($inboxItem, 'source_type'),
            'source_id' => data_get($inboxItem, 'source_id'),
            'dedupe_key' => data_get($inboxItem, 'dedupe_key'),
            'recommended_action' => data_get($payload, 'recommended_action'),
            'review_signal_status' => data_get($payload, 'review_signal.status'),
            'review_signal_severity' => data_get($payload, 'review_signal.severity'),
            'result_action' => data_get($result, 'payload.action'),
            'diff_ref_count' => count((array) data_get($result, 'payload.diff_refs', [])),
            'file_ref_count' => count((array) data_get($result, 'payload.file_refs', [])),
            'rivals_review_schema_version' => data_get($result, 'rivals_review_action.schema_version'),
            'rivals_review_id' => data_get($result, 'rivals_review_action.recorded_review_id'),
            'rivals_case_id' => data_get($result, 'rivals_review_action.case_id'),
            'rivals_horizon_days' => data_get($result, 'rivals_review_action.horizon_days'),
            'rivals_regret_score' => data_get($result, 'rivals_review_action.scores.regret'),
            'rivals_alignment_score' => data_get($result, 'rivals_review_action.scores.alignment'),
            'rivals_agency_score' => data_get($result, 'rivals_review_action.scores.agency'),
            'rivals_remaining_due_review_count' => data_get($result, 'rivals_review_action.remaining_due_review_count'),
            'retrieval_regression_review_schema_version' => data_get($result, 'retrieval_regression_review_action.schema_version'),
            'retrieval_regression_decision' => data_get($result, 'retrieval_regression_review_action.decision'),
            'retrieval_regression_reviewed' => data_get($result, 'retrieval_regression_review_action.reviewed'),
            'retrieval_regression_report_hash' => data_get($result, 'retrieval_regression_review_action.report_hash'),
            'retrieval_regression_latest_snapshot_hash' => data_get($result, 'retrieval_regression_review_action.latest_snapshot_hash'),
            'retrieval_regression_previous_snapshot_hash' => data_get($result, 'retrieval_regression_review_action.previous_snapshot_hash'),
            'retrieval_regression_decision_receipt_hash' => data_get($result, 'retrieval_regression_review_action.decision_receipt_hash'),
            'retrieval_regression_receipt_schema_version' => data_get($result, 'retrieval_regression_review_action.decision_receipt.schema_version'),
            'retrieval_regression_memory_write_allowed_now' => data_get($result, 'retrieval_regression_review_action.decision_receipt.memory_write_allowed_now'),
            'retrieval_regression_no_external_action' => data_get($result, 'retrieval_regression_review_action.no_external_action'),
            'retrieval_regression_no_runtime_execution' => data_get($result, 'retrieval_regression_review_action.no_runtime_execution'),
            'retrieval_regression_no_policy_patch' => data_get($result, 'retrieval_regression_review_action.no_policy_patch'),
            'retrieval_shadow_scope_review_schema_version' => data_get($result, 'retrieval_shadow_scope_review_action.schema_version'),
            'retrieval_shadow_scope_decision' => data_get($result, 'retrieval_shadow_scope_review_action.decision'),
            'retrieval_shadow_scope_reviewed' => data_get($result, 'retrieval_shadow_scope_review_action.reviewed'),
            'retrieval_shadow_scope_plan_hash' => data_get($result, 'retrieval_shadow_scope_review_action.plan_hash'),
            'retrieval_shadow_scope_case_contract_hash' => data_get($result, 'retrieval_shadow_scope_review_action.case_contract_hash'),
            'retrieval_shadow_scope_review_ap' => data_get($result, 'retrieval_shadow_scope_review_action.review_ap'),
            'retrieval_shadow_scope_privacy_provider_safety_review_hash' => data_get($result, 'retrieval_shadow_scope_review_action.privacy_provider_safety_review_hash'),
            'retrieval_shadow_scope_privacy_provider_safety_review_status' => data_get($result, 'retrieval_shadow_scope_review_action.privacy_provider_safety_review.status'),
            'retrieval_shadow_scope_privacy_provider_safe_for_review' => data_get($result, 'retrieval_shadow_scope_review_action.privacy_provider_safety_review.provider_safe_for_review'),
            'retrieval_shadow_scope_decision_receipt_hash' => data_get($result, 'retrieval_shadow_scope_review_action.decision_receipt_hash'),
            'retrieval_shadow_scope_receipt_schema_version' => data_get($result, 'retrieval_shadow_scope_review_action.decision_receipt.schema_version'),
            'retrieval_shadow_scope_receipt_case_contract_hash' => data_get($result, 'retrieval_shadow_scope_review_action.decision_receipt.case_contract_hash'),
            'retrieval_shadow_scope_receipt_privacy_provider_safety_review_passed' => data_get($result, 'retrieval_shadow_scope_review_action.decision_receipt.privacy_provider_safety_review_passed'),
            'retrieval_shadow_scope_shadow_execution_allowed_now' => data_get($result, 'retrieval_shadow_scope_review_action.decision_receipt.shadow_execution_allowed_now'),
            'retrieval_shadow_scope_no_external_action' => data_get($result, 'retrieval_shadow_scope_review_action.no_external_action'),
            'retrieval_shadow_scope_no_runtime_execution' => data_get($result, 'retrieval_shadow_scope_review_action.no_runtime_execution'),
            'retrieval_shadow_scope_no_policy_patch' => data_get($result, 'retrieval_shadow_scope_review_action.no_policy_patch'),
            'retrieval_shadow_scope_no_provider_call' => data_get($result, 'retrieval_shadow_scope_review_action.no_provider_call'),
            'external_vector_rag_preflight_review_schema_version' => data_get($result, 'external_vector_rag_preflight_review_action.schema_version'),
            'external_vector_rag_preflight_decision' => data_get($result, 'external_vector_rag_preflight_review_action.decision'),
            'external_vector_rag_preflight_reviewed' => data_get($result, 'external_vector_rag_preflight_review_action.reviewed'),
            'external_vector_rag_preflight_scope_approved' => data_get($result, 'external_vector_rag_preflight_review_action.scope_approved'),
            'external_vector_rag_preflight_hash' => data_get($result, 'external_vector_rag_preflight_review_action.preflight_hash'),
            'external_vector_rag_preflight_safety_review_hash' => data_get($result, 'external_vector_rag_preflight_review_action.safety_review_hash'),
            'external_vector_rag_preflight_safety_review_status' => data_get($result, 'external_vector_rag_preflight_review_action.safety_review.status'),
            'external_vector_rag_preflight_decision_receipt_hash' => data_get($result, 'external_vector_rag_preflight_review_action.decision_receipt_hash'),
            'external_vector_rag_preflight_receipt_schema_version' => data_get($result, 'external_vector_rag_preflight_review_action.decision_receipt.schema_version'),
            'external_vector_rag_preflight_embedding_allowed_now' => data_get($result, 'external_vector_rag_preflight_review_action.decision_receipt.embedding_generation_allowed_now'),
            'external_vector_rag_preflight_vector_write_allowed_now' => data_get($result, 'external_vector_rag_preflight_review_action.decision_receipt.external_vector_store_write_allowed_now'),
            'external_vector_rag_preflight_constellation_allowed_now' => data_get($result, 'external_vector_rag_preflight_review_action.decision_receipt.constellation_promotion_allowed_now'),
            'external_vector_rag_preflight_no_external_action' => data_get($result, 'external_vector_rag_preflight_review_action.no_external_action'),
            'external_vector_rag_preflight_no_runtime_execution' => data_get($result, 'external_vector_rag_preflight_review_action.no_runtime_execution'),
            'external_vector_rag_preflight_no_policy_patch' => data_get($result, 'external_vector_rag_preflight_review_action.no_policy_patch'),
            'external_vector_rag_preflight_no_provider_call' => data_get($result, 'external_vector_rag_preflight_review_action.no_provider_call'),
            'provider_cost_rate_schema_version' => data_get($result, 'provider_cost_rate_action.schema_version'),
            'provider_cost_rate_provider' => data_get($result, 'provider_cost_rate_action.provider'),
            'provider_cost_rate_model' => data_get($result, 'provider_cost_rate_action.model'),
            'provider_cost_rate_input_microusd' => data_get($result, 'provider_cost_rate_action.input_microusd_per_1k'),
            'provider_cost_rate_output_microusd' => data_get($result, 'provider_cost_rate_action.output_microusd_per_1k'),
            'provider_cost_rate_currency' => data_get($result, 'provider_cost_rate_action.currency'),
            'provider_cost_rate_effective_from' => data_get($result, 'provider_cost_rate_action.effective_from'),
            'provider_cost_rate_effective_until' => data_get($result, 'provider_cost_rate_action.effective_until'),
            'provider_cost_rate_applied' => data_get($result, 'provider_cost_rate_action.applied'),
            'provider_cost_rate_id' => data_get($result, 'upserted_rate.id'),
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $events
     * @return array<string,mixed>
     */
    private function inboxActionSummary(Collection $events): array
    {
        $reviewedPatchCount = $events
            ->filter(fn (array $event): bool => ($event['action'] ?? null) === 'review_patch')
            ->count();
        $withDiffRefsCount = $events
            ->filter(fn (array $event): bool => (int) ($event['diff_ref_count'] ?? 0) > 0)
            ->count();
        $rivalsReviewRecordedCount = $events
            ->filter(fn (array $event): bool => ($event['action'] ?? null) === 'record_rivals_review')
            ->count();
        $rivalsReviewWithScoresCount = $events
            ->filter(fn (array $event): bool => ($event['action'] ?? null) === 'record_rivals_review')
            ->filter(fn (array $event): bool => is_numeric($event['rivals_regret_score'] ?? null)
                && is_numeric($event['rivals_alignment_score'] ?? null)
                && is_numeric($event['rivals_agency_score'] ?? null))
            ->count();
        $providerCostRateActionCount = $events
            ->filter(fn (array $event): bool => ($event['action'] ?? null) === 'configure_provider_cost_rates')
            ->count();
        $providerCostRateAppliedCount = $events
            ->filter(fn (array $event): bool => ($event['action'] ?? null) === 'configure_provider_cost_rates')
            ->filter(fn (array $event): bool => (bool) ($event['provider_cost_rate_applied'] ?? false))
            ->count();
        $retrievalRegressionReviewCount = $events
            ->filter(fn (array $event): bool => ($event['action'] ?? null) === 'review_retrieval_regression')
            ->count();
        $retrievalRegressionReviewedCount = $events
            ->filter(fn (array $event): bool => ($event['action'] ?? null) === 'review_retrieval_regression')
            ->filter(fn (array $event): bool => (bool) ($event['retrieval_regression_reviewed'] ?? false))
            ->count();
        $retrievalShadowScopeReviewCount = $events
            ->filter(fn (array $event): bool => ($event['action'] ?? null) === 'review_retrieval_shadow_scope')
            ->count();
        $retrievalShadowScopeReviewedCount = $events
            ->filter(fn (array $event): bool => ($event['action'] ?? null) === 'review_retrieval_shadow_scope')
            ->filter(fn (array $event): bool => (bool) ($event['retrieval_shadow_scope_reviewed'] ?? false))
            ->count();
        $retrievalShadowScopeReceiptCount = $events
            ->filter(fn (array $event): bool => ($event['action'] ?? null) === 'review_retrieval_shadow_scope')
            ->filter(fn (array $event): bool => filled($event['retrieval_shadow_scope_decision_receipt_hash'] ?? null))
            ->count();
        $retrievalShadowScopeRuntimeAllowedCount = $events
            ->filter(fn (array $event): bool => ($event['action'] ?? null) === 'review_retrieval_shadow_scope')
            ->filter(fn (array $event): bool => (bool) ($event['retrieval_shadow_scope_shadow_execution_allowed_now'] ?? false))
            ->count();
        $externalVectorRagPreflightReviewCount = $events
            ->filter(fn (array $event): bool => ($event['action'] ?? null) === 'review_external_vector_rag_preflight')
            ->count();
        $externalVectorRagPreflightReviewedCount = $events
            ->filter(fn (array $event): bool => ($event['action'] ?? null) === 'review_external_vector_rag_preflight')
            ->filter(fn (array $event): bool => (bool) ($event['external_vector_rag_preflight_reviewed'] ?? false))
            ->count();
        $externalVectorRagPreflightReceiptCount = $events
            ->filter(fn (array $event): bool => ($event['action'] ?? null) === 'review_external_vector_rag_preflight')
            ->filter(fn (array $event): bool => filled($event['external_vector_rag_preflight_decision_receipt_hash'] ?? null))
            ->count();
        $externalVectorRagPreflightUnsafeActivationCount = $events
            ->filter(fn (array $event): bool => ($event['action'] ?? null) === 'review_external_vector_rag_preflight')
            ->filter(fn (array $event): bool => (bool) ($event['external_vector_rag_preflight_embedding_allowed_now'] ?? false)
                || (bool) ($event['external_vector_rag_preflight_vector_write_allowed_now'] ?? false)
                || (bool) ($event['external_vector_rag_preflight_constellation_allowed_now'] ?? false))
            ->count();
        $reviewSignal = $this->inboxActionReviewSignal(
            $events,
            $reviewedPatchCount,
            $withDiffRefsCount,
            $rivalsReviewRecordedCount,
            $rivalsReviewWithScoresCount,
            $providerCostRateActionCount,
            $providerCostRateAppliedCount,
            $retrievalRegressionReviewCount,
            $retrievalRegressionReviewedCount,
            $retrievalShadowScopeReviewCount,
            $retrievalShadowScopeReviewedCount,
            $retrievalShadowScopeReceiptCount,
            $retrievalShadowScopeRuntimeAllowedCount,
            $externalVectorRagPreflightReviewCount,
            $externalVectorRagPreflightReviewedCount,
            $externalVectorRagPreflightReceiptCount,
            $externalVectorRagPreflightUnsafeActivationCount,
        );

        return [
            'inbox_action_count' => $events->count(),
            'action_counts' => $events->pluck('action')->filter()->countBy()->all(),
            'actor_type_counts' => $events->pluck('actor_type')->filter()->countBy()->all(),
            'category_counts' => $events->pluck('inbox_item_category')->filter()->countBy()->all(),
            'severity_counts' => $events->pluck('inbox_item_severity')->filter()->countBy()->all(),
            'recommended_action_counts' => $events->pluck('recommended_action')->filter()->countBy()->all(),
            'reviewed_patch_count' => $reviewedPatchCount,
            'with_diff_refs_count' => $withDiffRefsCount,
            'rivals_review_recorded_count' => $rivalsReviewRecordedCount,
            'rivals_review_with_scores_count' => $rivalsReviewWithScoresCount,
            'retrieval_regression_review_count' => $retrievalRegressionReviewCount,
            'retrieval_regression_reviewed_count' => $retrievalRegressionReviewedCount,
            'retrieval_regression_decision_counts' => $events
                ->filter(fn (array $event): bool => ($event['action'] ?? null) === 'review_retrieval_regression')
                ->pluck('retrieval_regression_decision')
                ->filter()
                ->countBy()
                ->all(),
            'retrieval_shadow_scope_review_count' => $retrievalShadowScopeReviewCount,
            'retrieval_shadow_scope_reviewed_count' => $retrievalShadowScopeReviewedCount,
            'retrieval_shadow_scope_decision_receipt_count' => $retrievalShadowScopeReceiptCount,
            'retrieval_shadow_scope_runtime_allowed_count' => $retrievalShadowScopeRuntimeAllowedCount,
            'retrieval_shadow_scope_decision_counts' => $events
                ->filter(fn (array $event): bool => ($event['action'] ?? null) === 'review_retrieval_shadow_scope')
                ->pluck('retrieval_shadow_scope_decision')
                ->filter()
                ->countBy()
                ->all(),
            'external_vector_rag_preflight_review_count' => $externalVectorRagPreflightReviewCount,
            'external_vector_rag_preflight_reviewed_count' => $externalVectorRagPreflightReviewedCount,
            'external_vector_rag_preflight_decision_receipt_count' => $externalVectorRagPreflightReceiptCount,
            'external_vector_rag_preflight_unsafe_activation_count' => $externalVectorRagPreflightUnsafeActivationCount,
            'external_vector_rag_preflight_decision_counts' => $events
                ->filter(fn (array $event): bool => ($event['action'] ?? null) === 'review_external_vector_rag_preflight')
                ->pluck('external_vector_rag_preflight_decision')
                ->filter()
                ->countBy()
                ->all(),
            'provider_cost_rate_action_count' => $providerCostRateActionCount,
            'provider_cost_rate_applied_count' => $providerCostRateAppliedCount,
            'provider_cost_rate_provider_counts' => $events->pluck('provider_cost_rate_provider')->filter()->countBy()->all(),
            'provider_cost_rate_model_counts' => $events
                ->map(fn (array $event): ?string => ($event['provider_cost_rate_provider'] ?? null) && ($event['provider_cost_rate_model'] ?? null)
                    ? $event['provider_cost_rate_provider'].':'.$event['provider_cost_rate_model']
                    : null)
                ->filter()
                ->countBy()
                ->all(),
            'review_signal' => $reviewSignal,
            'events' => $events->all(),
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $events
     * @return array{status:string,severity:string,review_required:bool,reasons:array<int,string>,recommended_action:string}
     */
    private function inboxActionReviewSignal(
        Collection $events,
        int $reviewedPatchCount,
        int $withDiffRefsCount,
        int $rivalsReviewRecordedCount,
        int $rivalsReviewWithScoresCount,
        int $providerCostRateActionCount,
        int $providerCostRateAppliedCount,
        int $retrievalRegressionReviewCount,
        int $retrievalRegressionReviewedCount,
        int $retrievalShadowScopeReviewCount,
        int $retrievalShadowScopeReviewedCount,
        int $retrievalShadowScopeReceiptCount,
        int $retrievalShadowScopeRuntimeAllowedCount,
        int $externalVectorRagPreflightReviewCount,
        int $externalVectorRagPreflightReviewedCount,
        int $externalVectorRagPreflightReceiptCount,
        int $externalVectorRagPreflightUnsafeActivationCount,
    ): array {
        if ($events->isEmpty()) {
            return [
                'status' => 'unknown',
                'severity' => 'low',
                'review_required' => false,
                'reasons' => ['no_inbox_action_events_in_window'],
                'recommended_action' => 'wait_for_inbox_action_evidence',
            ];
        }

        if ($retrievalRegressionReviewCount > 0 && $retrievalRegressionReviewedCount < $retrievalRegressionReviewCount) {
            return [
                'status' => 'warning',
                'severity' => 'medium',
                'review_required' => true,
                'reasons' => ['review_retrieval_regression_action_without_review_marker'],
                'recommended_action' => 'open_memory_retrieval_regression_review',
            ];
        }

        if ($retrievalShadowScopeRuntimeAllowedCount > 0) {
            return [
                'status' => 'warning',
                'severity' => 'high',
                'review_required' => true,
                'reasons' => ['retrieval_shadow_scope_review_allowed_runtime_execution'],
                'recommended_action' => 'review_retrieval_shadow_scope',
            ];
        }

        if ($retrievalShadowScopeReviewCount > 0 && $retrievalShadowScopeReviewedCount < $retrievalShadowScopeReviewCount) {
            return [
                'status' => 'warning',
                'severity' => 'medium',
                'review_required' => true,
                'reasons' => ['review_retrieval_shadow_scope_action_without_review_marker'],
                'recommended_action' => 'review_retrieval_shadow_scope',
            ];
        }

        if ($retrievalShadowScopeReviewCount > 0 && $retrievalShadowScopeReceiptCount < $retrievalShadowScopeReviewCount) {
            return [
                'status' => 'warning',
                'severity' => 'medium',
                'review_required' => true,
                'reasons' => ['review_retrieval_shadow_scope_action_without_decision_receipt'],
                'recommended_action' => 'review_retrieval_shadow_scope',
            ];
        }

        if ($externalVectorRagPreflightUnsafeActivationCount > 0) {
            return [
                'status' => 'warning',
                'severity' => 'high',
                'review_required' => true,
                'reasons' => ['external_vector_rag_preflight_review_allowed_unsafe_activation'],
                'recommended_action' => 'review_external_vector_rag_preflight',
            ];
        }

        if ($externalVectorRagPreflightReviewCount > 0 && $externalVectorRagPreflightReviewedCount < $externalVectorRagPreflightReviewCount) {
            return [
                'status' => 'warning',
                'severity' => 'medium',
                'review_required' => true,
                'reasons' => ['review_external_vector_rag_preflight_action_without_review_marker'],
                'recommended_action' => 'review_external_vector_rag_preflight',
            ];
        }

        if ($externalVectorRagPreflightReviewCount > 0 && $externalVectorRagPreflightReceiptCount < $externalVectorRagPreflightReviewCount) {
            return [
                'status' => 'warning',
                'severity' => 'medium',
                'review_required' => true,
                'reasons' => ['review_external_vector_rag_preflight_action_without_decision_receipt'],
                'recommended_action' => 'review_external_vector_rag_preflight',
            ];
        }

        if ($rivalsReviewRecordedCount > 0 && $rivalsReviewWithScoresCount < $rivalsReviewRecordedCount) {
            return [
                'status' => 'warning',
                'severity' => 'medium',
                'review_required' => true,
                'reasons' => ['record_rivals_review_action_without_scores'],
                'recommended_action' => 'open_reviewable_inbox_action_evidence_proposal',
            ];
        }

        if ($providerCostRateActionCount > 0 && $providerCostRateAppliedCount < $providerCostRateActionCount) {
            return [
                'status' => 'warning',
                'severity' => 'medium',
                'review_required' => true,
                'reasons' => ['configure_provider_cost_rates_action_without_applied_rate'],
                'recommended_action' => 'configure_provider_cost_rates',
            ];
        }

        if ($reviewedPatchCount > 0 && $withDiffRefsCount === 0) {
            return [
                'status' => 'warning',
                'severity' => 'medium',
                'review_required' => true,
                'reasons' => ['review_patch_action_without_diff_refs'],
                'recommended_action' => 'open_reviewable_inbox_action_evidence_proposal',
            ];
        }

        if ($retrievalShadowScopeReviewCount > 0
            && $retrievalShadowScopeReviewedCount === $retrievalShadowScopeReviewCount
            && $retrievalShadowScopeReceiptCount === $retrievalShadowScopeReviewCount
        ) {
            return [
                'status' => 'ok',
                'severity' => 'none',
                'review_required' => false,
                'reasons' => ['memory_retrieval_shadow_scope_review_recorded'],
                'recommended_action' => 'none',
            ];
        }

        if ($retrievalRegressionReviewCount > 0 && $retrievalRegressionReviewedCount === $retrievalRegressionReviewCount) {
            return [
                'status' => 'ok',
                'severity' => 'none',
                'review_required' => false,
                'reasons' => ['memory_retrieval_regression_review_recorded'],
                'recommended_action' => 'none',
            ];
        }

        if ($externalVectorRagPreflightReviewCount > 0
            && $externalVectorRagPreflightReviewedCount === $externalVectorRagPreflightReviewCount
            && $externalVectorRagPreflightReceiptCount === $externalVectorRagPreflightReviewCount
        ) {
            return [
                'status' => 'ok',
                'severity' => 'none',
                'review_required' => false,
                'reasons' => ['external_vector_rag_preflight_review_recorded'],
                'recommended_action' => 'none',
            ];
        }

        if ($rivalsReviewRecordedCount > 0 && $rivalsReviewWithScoresCount === $rivalsReviewRecordedCount) {
            return [
                'status' => 'ok',
                'severity' => 'none',
                'review_required' => false,
                'reasons' => ['rivals_strategy_human_scores_recorded'],
                'recommended_action' => 'none',
            ];
        }

        if ($providerCostRateActionCount > 0 && $providerCostRateAppliedCount === $providerCostRateActionCount) {
            return [
                'status' => 'ok',
                'severity' => 'none',
                'review_required' => false,
                'reasons' => ['provider_cost_rates_configured'],
                'recommended_action' => 'none',
            ];
        }

        if ($reviewedPatchCount > 0 && $withDiffRefsCount > 0) {
            return [
                'status' => 'ok',
                'severity' => 'none',
                'review_required' => false,
                'reasons' => ['human_review_action_with_patch_context_recorded'],
                'recommended_action' => 'none',
            ];
        }

        return [
            'status' => 'ok',
            'severity' => 'none',
            'review_required' => false,
            'reasons' => [],
            'recommended_action' => 'none',
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $events
     * @return array<string,mixed>
     */
    private function kernelPipelineEventSummary(Collection $events): array
    {
        $latest = $events->last();
        $eventCount = $events->count();
        $acceptedCount = $events->where('event_type', LedgerEventType::KernelPipelineAccepted->value)->count();
        $rejectedCount = $events->where('event_type', LedgerEventType::KernelPipelineRejected->value)->count();
        $health = $this->kernelPipelineHealth($eventCount, $acceptedCount, $rejectedCount);
        $reviewSignal = $this->kernelPipelineReviewSignal($health, $events->pluck('violations')->flatten()->filter()->countBy()->all());

        return [
            'kernel_pipeline_event_count' => $eventCount,
            'accepted_count' => $acceptedCount,
            'rejected_count' => $rejectedCount,
            'status_counts' => $events->pluck('status')->filter()->countBy()->all(),
            'surface_counts' => $events->pluck('surface_id')->filter()->countBy()->all(),
            'emitter_stage_counts' => $events->pluck('emitter_stage')->filter()->countBy()->all(),
            'surface_contract_source_counts' => $events->pluck('surface_contract_source')->filter()->countBy()->all(),
            'flow_counts' => $events->pluck('flow')->filter()->countBy()->all(),
            'input_mode_counts' => $events->pluck('input_mode')->filter()->countBy()->all(),
            'violation_counts' => $events->pluck('violations')->flatten()->filter()->countBy()->all(),
            'latest_status' => is_array($latest) ? ($latest['status'] ?? null) : null,
            'has_rejections' => $events->contains(fn (array $event): bool => ($event['status'] ?? null) === 'rejected'),
            'health' => $health,
            'review_signal' => $reviewSignal,
            'events' => $events->all(),
        ];
    }

    /**
     * @return array{
     *     status:string,
     *     rejection_rate:float,
     *     accepted_count:int,
     *     rejected_count:int,
     *     event_count:int,
     *     review_required:bool,
     *     thresholds:array{warning_rejection_rate:float,breach_rejection_rate:float},
     *     reasons:array<int,string>
     * }
     */
    private function kernelPipelineHealth(int $eventCount, int $acceptedCount, int $rejectedCount): array
    {
        $warningThreshold = 0.000001;
        $breachThreshold = 0.05;
        $rejectionRate = $eventCount > 0 ? round($rejectedCount / $eventCount, 6) : 0.0;
        $reasons = [];

        if ($eventCount === 0) {
            $status = 'unknown';
            $reasons[] = 'no_kernel_pipeline_events_in_window';
        } elseif ($rejectionRate >= $breachThreshold) {
            $status = 'breach';
            $reasons[] = 'kernel_pipeline_rejection_rate_above_breach_threshold';
        } elseif ($rejectionRate >= $warningThreshold) {
            $status = 'warning';
            $reasons[] = 'kernel_pipeline_rejections_detected';
        } else {
            $status = 'ok';
        }

        return [
            'status' => $status,
            'rejection_rate' => $rejectionRate,
            'accepted_count' => $acceptedCount,
            'rejected_count' => $rejectedCount,
            'event_count' => $eventCount,
            'review_required' => in_array($status, ['warning', 'breach'], true),
            'thresholds' => [
                'warning_rejection_rate' => $warningThreshold,
                'breach_rejection_rate' => $breachThreshold,
            ],
            'reasons' => $reasons,
        ];
    }

    /**
     * @param  array<string,mixed>  $health
     * @param  array<string,int>  $violationCounts
     * @return array{status:string,severity:string,review_required:bool,reasons:array<int,string>,recommended_action:string}
     */
    private function kernelPipelineReviewSignal(array $health, array $violationCounts): array
    {
        $status = (string) ($health['status'] ?? 'unknown');
        $reasons = array_values((array) ($health['reasons'] ?? []));
        if ($violationCounts !== []) {
            $reasons = array_values(array_unique([...$reasons, ...array_keys($violationCounts)]));
        }

        if ($status === 'unknown') {
            return [
                'status' => 'unknown',
                'severity' => 'low',
                'review_required' => false,
                'reasons' => $reasons === [] ? ['no_kernel_pipeline_events_in_window'] : $reasons,
                'recommended_action' => 'wait_for_kernel_pipeline_evidence',
            ];
        }

        if ($status === 'ok') {
            return [
                'status' => 'ok',
                'severity' => 'none',
                'review_required' => false,
                'reasons' => [],
                'recommended_action' => 'none',
            ];
        }

        return [
            'status' => $status,
            'severity' => $status === 'breach' ? 'high' : 'medium',
            'review_required' => true,
            'reasons' => $reasons === [] ? ['kernel_pipeline_rejections_detected'] : $reasons,
            'recommended_action' => 'open_reviewable_kernel_pipeline_contract_proposal',
        ];
    }

    private function tableAvailable(): bool
    {
        if (! $this->roleEnforcementEnabled()) {
            return DatabaseTableAvailability::has('atlas_ledger_events');
        }

        return $this->ledgerConnection()->getSchemaBuilder()->hasTable('atlas_ledger_events');
    }

    private function ledgerQuery(): Builder
    {
        $connectionName = $this->verifierConnectionName();

        return $connectionName === null
            ? AtlasLedgerEvent::query()
            : AtlasLedgerEvent::on($connectionName);
    }

    private function ledgerConnection(): \Illuminate\Database\ConnectionInterface
    {
        return DB::connection($this->verifierConnectionName());
    }

    private function verifierConnectionName(): ?string
    {
        if (! $this->roleEnforcementEnabled()) {
            return null;
        }

        $connectionName = trim((string) config('database.ledger_roles.verifier_connection', ''));
        $connection = config('database.connections.'.$connectionName);
        if ($connectionName === ''
            || ! is_array($connection)
            || ($connection['driver'] ?? null) !== 'pgsql'
            || trim((string) ($connection['username'] ?? '')) === '') {
            throw new \LogicException('atlas_ledger_verifier_role_configuration_invalid');
        }

        return $connectionName;
    }

    private function roleEnforcementEnabled(): bool
    {
        return filter_var(config('database.ledger_roles.enforced', false), FILTER_VALIDATE_BOOL);
    }

    private function proofTenant(?string $tenantId): ?string
    {
        $tenantId = trim((string) $tenantId);

        return $tenantId !== '' ? $tenantId : null;
    }

    /** @return array{algorithm:string,key_id:string} */
    private function authenticationDescriptor(): array
    {
        return [
            'algorithm' => 'hmac-sha256',
            'key_id' => substr(hash('sha256', $this->artifactKeyMaterial()), 0, 24),
        ];
    }

    /**
     * @param  array<string,mixed>  $body
     */
    private function artifactSignature(string $domain, array $body): string
    {
        return hash_hmac(
            self::HMAC_ALGORITHM,
            $domain.'|'.AtlasEvidenceLedger::canonicalJson($body),
            $this->artifactKeyMaterial(),
        );
    }

    private function artifactKeyMaterial(): string
    {
        $configured = trim((string) config('atlas.ledger.artifact_secret', ''));
        $source = $configured !== '' ? $configured : trim((string) config('app.key', ''));
        if ($source === '') {
            throw new \LogicException('atlas_ledger_artifact_authentication_key_unavailable');
        }

        if (str_starts_with($source, 'base64:')) {
            $decoded = base64_decode(substr($source, 7), true);
            if ($decoded === false || $decoded === '') {
                throw new \LogicException('atlas_ledger_artifact_authentication_key_invalid');
            }
            $source = $decoded;
        }

        return hash_hmac(self::HMAC_ALGORITHM, 'atlas.ledger.artifact.key.v1', $source, true);
    }

    private function v2ChainQuery(string $tenantId, string $chainKeyHash): Builder
    {
        return $this->ledgerQuery()
            ->where('tenant_id', $tenantId)
            ->where('chain_key_hash', $chainKeyHash)
            ->where('schema_version', AtlasEvidenceLedger::SCHEMA_VERSION_V2)
            ->orderBy('chain_position');
    }

    /**
     * @param  array<string,mixed>  $details
     * @return array<string,mixed>
     */
    private function chainFailure(string $reason, array $details = []): array
    {
        return [
            'schema' => 'atlas.ledger_chain_verification.v1',
            'status' => 'failed',
            'valid' => false,
            'failure_reason' => $reason,
            ...$details,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function journeyEventRef(AtlasLedgerEvent $event): array
    {
        return [
            'event_id' => (string) $event->event_id,
            'event_hash' => (string) $event->event_hash,
            'payload_hash' => (string) $event->payload_hash,
            'event_type' => (string) $event->event_type,
            'chain_position' => (int) $event->chain_position,
            'causation_id' => $event->causation_id !== null ? (string) $event->causation_id : null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function journeyFailure(string $reason): array
    {
        return [
            'schema' => 'atlas.aaeos.journey_manifest_verification.v1',
            'status' => 'failed',
            'valid' => false,
            'failure_reason' => $reason,
        ];
    }
}
