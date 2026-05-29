<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution;

use App\Models\AiInboxItem;
use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Mobile\ProposalInboxEmitter;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusDevForgeReleaseService;
use App\Services\Ai\SoftwareCompanyStewardship\PortfolioStewardship\PortfolioStewardshipHealthModelService;
use App\Services\Ai\SoftwareCompanyStewardship\SelfExpanding\SelfExpandingSoftwareCompanyService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\Schema;

/**
 * AP-740 · Stewardship outcomes bridge.
 *
 * Reuses the canonical Evidence Ledger and ProposalInboxEmitter to turn
 * AP-731/AP-738/AP-747 outcomes into durable evidence and Morning Inbox
 * proposals. It does not create a new ledger, inbox, runtime, branch or domain.
 */
final class StewardshipOutcomeEvidenceBridgeService implements \App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\StewardshipOutcomeProjector
{
    public const REPORT_SCHEMA = 'atlas.software_company.stewardship_outcome_bridge.v1';

    public const EVIDENCE_SCHEMA = 'atlas.software_company.stewardship_outcome_evidence.v1';

    public const MORNING_INBOX_SCHEMA = 'atlas.software_company.stewardship_morning_inbox_item.v1';

    public const STATUS_READY = 'ready';

    public const STATUS_BLOCKED = 'blocked';

    public function __construct(
        private readonly StewardshipEvolutionDecisionLedgerService $decisionLedger,
        private readonly SelfExpandingSoftwareCompanyService $selfExpanding,
        private readonly AtlasEvidenceLedger $evidenceLedger,
        private readonly ProposalInboxEmitter $proposalInbox,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function project(array $input = []): array
    {
        $areaId = $this->areaId($input);
        $portfolioId = $this->portfolioId($input);
        $recordEvidence = (bool) ($input['record_evidence'] ?? false);
        $emitInbox = (bool) ($input['emit_inbox'] ?? false);

        $decisions = is_array($input['decision_ledger'] ?? null)
            ? $input['decision_ledger']
            : $this->decisionLedger->listDecisions($areaId);

        $selfExpanding = is_array($input['self_expanding'] ?? null)
            ? $input['self_expanding']
            : $this->selfExpanding->project($input + [
                'area_id' => $areaId,
                'portfolio_id' => $portfolioId,
            ]);
        $releaseReports = $this->releaseReports($input);

        if (($selfExpanding['status'] ?? '') === SelfExpandingSoftwareCompanyService::STATUS_BLOCKED) {
            return $this->finalize([
                'schema_version' => self::REPORT_SCHEMA,
                'status' => self::STATUS_BLOCKED,
                'reason' => 'self_expanding_report_blocked',
                'ap_contract' => 'AP-740',
                'area_id' => $areaId,
                'portfolio_id' => $portfolioId,
                'record_evidence_requested' => $recordEvidence,
                'emit_inbox_requested' => $emitInbox,
                'evidence_items' => [],
                'morning_inbox_items' => [],
                'blockers' => ['AP-738 Self-Expanding Software Company projection is blocked.'],
                'claim_policy' => $this->claimPolicy($recordEvidence, $emitInbox),
            ]);
        }

        $evidenceItems = $this->evidenceItems($areaId, $portfolioId, $decisions, $selfExpanding, $releaseReports);
        $morningInboxItems = $this->morningInboxItems($areaId, $portfolioId, $decisions, $selfExpanding, $releaseReports);
        $releaseSummary = $this->releaseOutcomeSummary($areaId, $portfolioId, $releaseReports);

        if ($recordEvidence) {
            $evidenceItems = array_map(
                fn (array $item): array => $this->recordEvidenceItem($item, (string) ($input['actor'] ?? $input['operator_actor'] ?? 'system')),
                $evidenceItems,
            );
        }

        if ($emitInbox) {
            $morningInboxItems = array_map(
                fn (array $item): array => $this->emitMorningInboxItem($item),
                $morningInboxItems,
            );
        }

        return $this->finalize([
            'schema_version' => self::REPORT_SCHEMA,
            'status' => self::STATUS_READY,
            'ap_contract' => 'AP-740',
            'area_id' => $areaId,
            'portfolio_id' => $portfolioId,
            'stack' => 'Atlas Software Company Stewardship Stack',
            'source_ap_contracts' => $releaseReports === []
                ? ['AP-731', 'AP-738']
                : ['AP-731', 'AP-738', 'AP-747', 'AP-748'],
            'reused_owners' => [
                'decision_ledger' => StewardshipEvolutionDecisionLedgerService::class,
                'self_expanding' => SelfExpandingSoftwareCompanyService::class,
                'dev_forge_release' => AreaFocusDevForgeReleaseService::class,
                'evidence_ledger' => AtlasEvidenceLedger::class,
                'morning_inbox' => ProposalInboxEmitter::class,
            ],
            'record_evidence_requested' => $recordEvidence,
            'emit_inbox_requested' => $emitInbox,
            'decision_count' => (int) ($decisions['decision_count'] ?? count((array) ($decisions['decisions'] ?? []))),
            'self_expanding_status' => (string) ($selfExpanding['status'] ?? 'unknown'),
            'self_expanding_report_hash' => (string) ($selfExpanding['report_hash'] ?? ''),
            'release_outcome_summary' => $releaseSummary,
            'portfolio_feed' => $this->portfolioFeed($areaId, $portfolioId, $releaseSummary),
            'evidence_item_count' => count($evidenceItems),
            'morning_inbox_item_count' => count($morningInboxItems),
            'evidence_items' => $evidenceItems,
            'morning_inbox_items' => $morningInboxItems,
            'next_handoff_boundary' => [
                'ready_for_domain_runtime_creation_gate' => $this->readyForDomainRuntimeCreationGate($selfExpanding),
                'domain_runtime_creation_gate_required' => true,
                'operator_approval_required' => true,
                'auto_promotion_allowed' => false,
            ],
            'claim_policy' => $this->claimPolicy($recordEvidence, $emitInbox),
        ]);
    }

    /**
     * @param  array<string,mixed>  $decisions
     * @param  array<string,mixed>  $selfExpanding
     * @return list<array<string,mixed>>
     */
    private function evidenceItems(string $areaId, string $portfolioId, array $decisions, array $selfExpanding, array $releaseReports = []): array
    {
        $items = [];

        foreach ((array) ($decisions['decisions'] ?? []) as $decision) {
            if (! is_array($decision)) {
                continue;
            }
            $targetId = (string) ($decision['target_id'] ?? '');
            $targetHash = (string) ($decision['target_hash'] ?? '');
            $decisionId = (string) ($decision['decision_id'] ?? '');
            $payload = [
                'schema_version' => self::EVIDENCE_SCHEMA,
                'source_kind' => 'ap731_operator_decision',
                'source_ap_contract' => 'AP-731',
                'bridge_ap_contract' => 'AP-740',
                'area_id' => (string) ($decision['area_id'] ?? $areaId),
                'portfolio_id' => (string) ($decision['portfolio_id'] ?? $portfolioId),
                'decision_id' => $decisionId,
                'target_type' => (string) ($decision['target_type'] ?? ''),
                'target_id' => $targetId,
                'target_hash' => $targetHash,
                'decision' => (string) ($decision['decision'] ?? ''),
                'risk_level' => (string) ($decision['risk_level'] ?? 'medium'),
                'operator_actor' => (string) ($decision['operator_actor'] ?? ''),
                'recorded_at' => (string) ($decision['recorded_at'] ?? ''),
                'decision_hash' => (string) ($decision['decision_hash'] ?? ''),
                'operator_review_required' => true,
                'auto_execution_allowed' => false,
            ];

            $items[] = $this->evidenceItem('ap731_operator_decision', $decisionId, $targetHash, $payload);
        }

        $reportHash = (string) ($selfExpanding['report_hash'] ?? '');
        $payload = [
            'schema_version' => self::EVIDENCE_SCHEMA,
            'source_kind' => 'ap738_self_expanding_report',
            'source_ap_contract' => 'AP-738',
            'bridge_ap_contract' => 'AP-740',
            'area_id' => $areaId,
            'portfolio_id' => $portfolioId,
            'report_hash' => $reportHash,
            'status' => (string) ($selfExpanding['status'] ?? 'unknown'),
            'expansion_summary' => is_array($selfExpanding['expansion_summary'] ?? null) ? $selfExpanding['expansion_summary'] : [],
            'operator_inbox_count' => (int) data_get($selfExpanding, 'operator_inbox.item_count', 0),
            'ready_for_domain_runtime_creation_gate' => $this->readyForDomainRuntimeCreationGate($selfExpanding),
            'operator_review_required' => true,
            'auto_execution_allowed' => false,
        ];

        $items[] = $this->evidenceItem('ap738_self_expanding_report', $reportHash, $reportHash, $payload);

        foreach ($releaseReports as $release) {
            $releaseId = $this->releaseId($release);
            $releaseHash = (string) ($release['release_hash'] ?? $release['bridge_hash'] ?? $releaseId);
            $queueItem = is_array($release['queue_item'] ?? null) ? $release['queue_item'] : [];
            $payload = [
                'schema_version' => self::EVIDENCE_SCHEMA,
                'source_kind' => 'ap747_owner_queue_release',
                'source_ap_contract' => 'AP-747',
                'bridge_ap_contract' => 'AP-748',
                'area_id' => (string) ($release['area_id'] ?? $areaId),
                'portfolio_id' => $portfolioId,
                'release_id' => $releaseId,
                'release_hash' => $releaseHash,
                'release_status' => (string) ($release['status'] ?? 'unknown'),
                'target_owner' => $this->releaseTargetOwner($release),
                'target_runtime_schema' => (string) ($release['target_runtime_schema'] ?? data_get($queueItem, 'target_runtime_schema', '')),
                'queue_item_id' => (string) data_get($queueItem, 'queue_item_id', ''),
                'handoff_hash' => (string) data_get($release, 'source_refs.handoff_hash', data_get($queueItem, 'handoff_hash', '')),
                'work_order_id' => (string) data_get($release, 'source_refs.work_order_id', data_get($queueItem, 'work_order_id', '')),
                'operator_actor' => (string) data_get($release, 'release_receipt.operator_actor', ''),
                'runtime_execution_started' => (bool) data_get($queueItem, 'runtime_execution_started', false),
                'provider_invoked' => (bool) data_get($queueItem, 'provider_invoked', false),
                'operator_review_required' => true,
                'auto_execution_allowed' => false,
                'irreversible_action_allowed' => false,
            ];

            $items[] = $this->evidenceItem('ap747_owner_queue_release', $releaseId, $releaseHash, $payload);
        }

        return $items;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function evidenceItem(string $sourceKind, string $sourceId, string $sourceHash, array $payload): array
    {
        $payloadHash = MissionCanonicalHash::sha256($payload);

        return [
            'schema_version' => self::EVIDENCE_SCHEMA,
            'event_id' => 'scoev_'.substr(MissionCanonicalHash::sha256([$sourceKind, $sourceId, $sourceHash, $payloadHash]), 0, 26),
            'event_type' => LedgerEventType::EvidencePacked->value,
            'source_kind' => $sourceKind,
            'source_id' => $sourceId,
            'source_hash' => $sourceHash,
            'payload_hash' => $payloadHash,
            'ledger_status' => 'projected',
            'payload' => $payload,
        ];
    }

    /**
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>
     */
    private function recordEvidenceItem(array $item, string $operatorId): array
    {
        if (! Schema::hasTable('atlas_ledger_events')) {
            return array_merge($item, ['ledger_status' => 'skipped_missing_atlas_ledger_events_table']);
        }

        $eventId = (string) ($item['event_id'] ?? '');
        if ($eventId !== '' && AtlasLedgerEvent::query()->whereKey($eventId)->exists()) {
            return array_merge($item, ['ledger_status' => 'existing']);
        }

        $payload = is_array($item['payload'] ?? null) ? $item['payload'] : [];
        $areaId = (string) ($payload['area_id'] ?? StewardshipEvolutionReadModelService::DEFAULT_AREA_ID);
        $recorded = $this->evidenceLedger->record(LedgerEventType::EvidencePacked, $payload, [
            'event_id' => $eventId,
            'tenant_id' => 'default',
            'operator_id' => $operatorId !== '' ? $operatorId : 'system',
            'envelope_id' => 'stewardship_outcome:'.$areaId,
            'receipt_id' => $payload['decision_id'] ?? null,
            'correlation_id' => 'AP-740:'.$areaId,
            'scope_type' => 'software_company_stewardship',
            'scope_id' => $areaId,
            'emitter_stage' => 'atlas.software_company_stewardship.ap740',
            'emitter_version' => 'AP-740',
        ]);

        return array_merge($item, [
            'ledger_status' => $recorded instanceof AtlasLedgerEvent ? 'recorded' : 'skipped_by_evidence_ledger',
            'ledger_event_id' => $recorded?->event_id,
        ]);
    }

    /**
     * @param  array<string,mixed>  $decisions
     * @param  array<string,mixed>  $selfExpanding
     * @return list<array<string,mixed>>
     */
    private function morningInboxItems(string $areaId, string $portfolioId, array $decisions, array $selfExpanding, array $releaseReports = []): array
    {
        $items = [];
        $selfExpandingInbox = array_values(array_filter((array) data_get($selfExpanding, 'operator_inbox.items', []), 'is_array'));

        foreach ((array) ($decisions['decisions'] ?? []) as $decision) {
            if (is_array($decision) && $this->decisionNeedsMorningInbox($decision)) {
                $items[] = $this->decisionMorningInboxItem($areaId, $portfolioId, $decision, $selfExpandingInbox);
            }
        }

        foreach ($selfExpandingInbox as $item) {
            $items[] = $this->selfExpandingMorningInboxItem($areaId, $portfolioId, $item);
        }

        foreach ($releaseReports as $release) {
            $items[] = $this->releaseMorningInboxItem($areaId, $portfolioId, $release);
        }

        return $this->dedupeInboxItems($items);
    }

    /**
     * @param  array<string,mixed>  $decision
     */
    private function decisionNeedsMorningInbox(array $decision): bool
    {
        return in_array((string) ($decision['decision'] ?? ''), ['accept', 'defer', 'request_changes'], true);
    }

    /**
     * @param  array<string,mixed>  $decision
     * @param  list<array<string,mixed>>  $selfExpandingInbox
     * @return array<string,mixed>
     */
    private function decisionMorningInboxItem(string $areaId, string $portfolioId, array $decision, array $selfExpandingInbox): array
    {
        $targetType = (string) ($decision['target_type'] ?? '');
        $targetId = (string) ($decision['target_id'] ?? '');
        $decisionValue = (string) ($decision['decision'] ?? '');
        $relatedSelfExpanding = $this->relatedSelfExpandingItem($targetId, $selfExpandingInbox);
        $recommendedAction = $this->decisionRecommendedAction($decision, $relatedSelfExpanding);
        $severity = $decisionValue === 'accept' ? 'high' : 'medium';

        return [
            'schema_version' => self::MORNING_INBOX_SCHEMA,
            'kind' => 'ap731_operator_decision_outcome',
            'source_ap_contract' => 'AP-731',
            'area_id' => $areaId,
            'portfolio_id' => $portfolioId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'target_hash' => (string) ($decision['target_hash'] ?? ''),
            'decision_id' => (string) ($decision['decision_id'] ?? ''),
            'decision' => $decisionValue,
            'title' => 'Stewardship decision needs follow-up: '.$targetId,
            'problem' => 'AP-731 recorded an operator decision that must be visible in the Morning Inbox before any handoff or execution.',
            'solution' => 'Review the outcome, confirm blockers, then route the next governed handoff through the existing owner.',
            'worth_it' => 'This closes the loop from operator review to durable evidence and prevents accepted decisions from becoming invisible local JSONL state.',
            'dedupe_key' => 'stewardship:ap731:'.$targetId.':'.$decisionValue,
            'recommended_action' => $recommendedAction,
            'review_signal' => [
                'status' => 'review_required',
                'severity' => $severity,
                'recommended_action' => $recommendedAction,
                'reason' => 'AP-731 outcome requires governed follow-up.',
            ],
            'source_refs' => [[
                'type' => 'stewardship_decision',
                'id' => (string) ($decision['decision_id'] ?? ''),
            ]],
            'payload' => [
                'decision' => $decision,
                'related_self_expanding_item' => $relatedSelfExpanding,
                'operator_review_required' => true,
                'autoimplementation_allowed' => false,
                'irreversible_action_allowed' => false,
            ],
            'inbox_status' => 'projected',
        ];
    }

    /**
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>
     */
    private function selfExpandingMorningInboxItem(string $areaId, string $portfolioId, array $item): array
    {
        $proposalId = (string) ($item['proposal_id'] ?? '');
        $candidateArea = (string) ($item['candidate_area'] ?? '');
        $gateStatus = (string) ($item['gate_status'] ?? 'awaiting_operator_review');
        $recommendedAction = (string) ($item['recommended_operator_action'] ?? 'operator_review_accept_reject_defer_or_request_changes');

        return [
            'schema_version' => self::MORNING_INBOX_SCHEMA,
            'kind' => 'ap738_self_expanding_review',
            'source_ap_contract' => 'AP-738',
            'area_id' => $areaId,
            'portfolio_id' => $portfolioId,
            'proposal_id' => $proposalId,
            'candidate_area' => $candidateArea,
            'gate_status' => $gateStatus,
            'title' => 'Review self-expanding software company proposal: '.$candidateArea,
            'problem' => 'Atlas detected a capability or area gap that should not remain buried inside the Product Mode cockpit only.',
            'solution' => 'Review the AP-738 proposal, then decide through AP-731/AP-737 before any Domain Runtime Creation Gate handoff.',
            'worth_it' => 'This makes the self-expansion ceiling visible in Morning Inbox while preserving proposal-only governance.',
            'dedupe_key' => 'stewardship:ap738:'.$proposalId.':'.$gateStatus,
            'recommended_action' => $recommendedAction,
            'review_signal' => [
                'status' => 'review_required',
                'severity' => str_contains($gateStatus, 'blocked') ? 'high' : 'medium',
                'recommended_action' => $recommendedAction,
                'reason' => 'AP-738 proposal requires operator review before any promotion.',
            ],
            'source_refs' => [[
                'type' => 'self_expanding_proposal',
                'id' => $proposalId,
            ]],
            'payload' => [
                'self_expanding_item' => $item,
                'operator_review_required' => true,
                'domain_runtime_creation_gate_required' => true,
                'autoimplementation_allowed' => false,
                'irreversible_action_allowed' => false,
            ],
            'inbox_status' => 'projected',
        ];
    }

    /**
     * @param  array<string,mixed>  $release
     * @return array<string,mixed>
     */
    private function releaseMorningInboxItem(string $areaId, string $portfolioId, array $release): array
    {
        $releaseId = $this->releaseId($release);
        $status = (string) ($release['status'] ?? 'unknown');
        $targetOwner = $this->releaseTargetOwner($release);
        $queueItemId = (string) data_get($release, 'queue_item.queue_item_id', '');
        $blocked = $status === AreaFocusDevForgeReleaseService::STATUS_BLOCKED;

        return [
            'schema_version' => self::MORNING_INBOX_SCHEMA,
            'kind' => 'ap747_owner_queue_release_review',
            'source_ap_contract' => 'AP-747',
            'bridge_ap_contract' => 'AP-748',
            'area_id' => (string) ($release['area_id'] ?? $areaId),
            'portfolio_id' => $portfolioId,
            'release_id' => $releaseId,
            'queue_item_id' => $queueItemId,
            'target_owner' => $targetOwner,
            'release_status' => $status,
            'title' => $blocked
                ? 'Resolve blocked AP-747 Dev/Forge release'
                : 'Review AP-747 '.$targetOwner.' queue release before runtime execution',
            'problem' => $blocked
                ? 'An AP-747 release attempt is blocked and must not disappear from the operator loop.'
                : 'A Dev/Forge queue item exists, but runtime execution still requires owner-specific review and evidence.',
            'solution' => $blocked
                ? 'Resolve the AP-747 blockers, then re-run the release with an explicit operator receipt.'
                : 'Review the queue item, confirm branch isolation and evidence requirements, then let the owner-specific Dev/Forge gate consume it.',
            'worth_it' => 'This connects release intent to Evidence, Morning Inbox and Portfolio health without bypassing Dev/Forge ownership.',
            'dedupe_key' => 'stewardship:ap747:'.$releaseId.':'.$status,
            'recommended_action' => $blocked
                ? 'resolve_ap747_release_blockers'
                : 'review_owner_queue_item_before_dev_forge_runtime_execution',
            'review_signal' => [
                'status' => 'review_required',
                'severity' => $blocked ? 'high' : 'medium',
                'recommended_action' => $blocked
                    ? 'resolve_ap747_release_blockers'
                    : 'review_owner_queue_item_before_dev_forge_runtime_execution',
                'reason' => 'AP-747 release outcome requires governed follow-up.',
            ],
            'source_refs' => [[
                'type' => 'area_focus_dev_forge_release',
                'id' => $releaseId,
            ]],
            'payload' => [
                'release_report' => $this->releaseSummaryPayload($release),
                'operator_review_required' => true,
                'owner_specific_execution_gate_required' => true,
                'autoimplementation_allowed' => false,
                'irreversible_action_allowed' => false,
            ],
            'inbox_status' => 'projected',
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @return list<array<string,mixed>>
     */
    private function dedupeInboxItems(array $items): array
    {
        $seen = [];
        $out = [];
        foreach ($items as $item) {
            $key = (string) ($item['dedupe_key'] ?? MissionCanonicalHash::sha256($item));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $item;
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>
     */
    private function emitMorningInboxItem(array $item): array
    {
        /** @var AiInboxItem|null $emitted */
        $emitted = $this->proposalInbox->emit([
            'title' => (string) ($item['title'] ?? 'Review stewardship outcome'),
            'category' => 'software_company_stewardship',
            // Pass an explicit `finding` so the inbox body's "O que encontrei" line
            // does NOT fall back to `problem` (the duplication bug). Each morning
            // item already carries a distinct, target-specific title.
            'finding' => (string) ($item['finding'] ?? $item['title'] ?? 'Stewardship outcome'),
            'problem' => (string) ($item['problem'] ?? 'Stewardship outcome needs operator review.'),
            'solution' => (string) ($item['solution'] ?? 'Review in Morning Inbox.'),
            'worth_it' => (string) ($item['worth_it'] ?? 'Keeps stewardship outcomes visible.'),
            'dedupe_key' => (string) ($item['dedupe_key'] ?? ''),
            'confidence' => 0.91,
            'source_type' => 'software_company_stewardship',
            'source_id' => null,
            'source_refs' => (array) ($item['source_refs'] ?? []),
            'payload' => (array) ($item['payload'] ?? []),
            'metadata' => [
                'schema_version' => self::MORNING_INBOX_SCHEMA,
                'review_signal' => (array) ($item['review_signal'] ?? []),
            ],
        ]);

        return array_merge($item, [
            'inbox_status' => $emitted instanceof AiInboxItem ? 'emitted_or_existing' : 'skipped_missing_inbox_tables',
            'inbox_item_id' => $emitted?->id,
        ]);
    }

    /**
     * @param  array<string,mixed>  $decision
     * @param  array<string,mixed>|null  $relatedSelfExpanding
     */
    private function decisionRecommendedAction(array $decision, ?array $relatedSelfExpanding): string
    {
        $decisionValue = (string) ($decision['decision'] ?? '');
        if ($decisionValue === 'request_changes') {
            return 'prepare_stewardship_revision_request';
        }
        if ($decisionValue === 'defer') {
            return 'schedule_stewardship_revisit';
        }
        if ((string) ($decision['target_type'] ?? '') === 'new_area_proposal') {
            if (($relatedSelfExpanding['gate_status'] ?? '') === 'accepted_for_domain_runtime_creation_gate') {
                return 'prepare_domain_runtime_creation_gate_handoff';
            }

            return 'resolve_new_area_gate_blockers_before_handoff';
        }

        return 'prepare_next_governed_stewardship_slice';
    }

    /**
     * @param  list<array<string,mixed>>  $selfExpandingInbox
     * @return array<string,mixed>|null
     */
    private function relatedSelfExpandingItem(string $targetId, array $selfExpandingInbox): ?array
    {
        foreach ($selfExpandingInbox as $item) {
            if ((string) ($item['proposal_id'] ?? '') === $targetId) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $selfExpanding
     */
    private function readyForDomainRuntimeCreationGate(array $selfExpanding): int
    {
        return (int) data_get($selfExpanding, 'expansion_summary.ready_for_domain_runtime_creation_gate', 0);
    }

    /**
     * @param  array<string,mixed>  $input
     * @return list<array<string,mixed>>
     */
    private function releaseReports(array $input): array
    {
        $raw = [];
        foreach (['release_report', 'dev_forge_release'] as $key) {
            if (is_array($input[$key] ?? null)) {
                $raw[] = $input[$key];
            }
        }
        foreach (['release_reports', 'dev_forge_releases', 'release_records'] as $key) {
            foreach ((array) ($input[$key] ?? []) as $item) {
                if (is_array($item)) {
                    $raw[] = $item;
                }
            }
        }

        $reports = [];
        foreach ($raw as $item) {
            if ($this->isAp747ReleaseReport($item)) {
                $reports[] = $item;
            }
        }

        return $this->dedupeReleaseReports($reports);
    }

    /**
     * @param  array<string,mixed>  $item
     */
    private function isAp747ReleaseReport(array $item): bool
    {
        return (string) ($item['ap_contract'] ?? '') === 'AP-747'
            || in_array((string) ($item['schema_version'] ?? ''), [
                AreaFocusDevForgeReleaseService::REPORT_SCHEMA,
                AreaFocusDevForgeReleaseService::RECORD_SCHEMA,
            ], true);
    }

    /**
     * @param  list<array<string,mixed>>  $reports
     * @return list<array<string,mixed>>
     */
    private function dedupeReleaseReports(array $reports): array
    {
        $seen = [];
        $out = [];
        foreach ($reports as $report) {
            $key = $this->releaseId($report);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $report;
        }

        return $out;
    }

    /**
     * @param  list<array<string,mixed>>  $releases
     * @return array<string,mixed>
     */
    private function releaseOutcomeSummary(string $areaId, string $portfolioId, array $releases): array
    {
        $ownerCounts = [];
        $statusCounts = [];
        $queueItemIds = [];
        foreach ($releases as $release) {
            $owner = $this->releaseTargetOwner($release);
            $status = (string) ($release['status'] ?? 'unknown');
            $ownerCounts[$owner] = ($ownerCounts[$owner] ?? 0) + 1;
            $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
            $queueItemId = (string) data_get($release, 'queue_item.queue_item_id', '');
            if ($queueItemId !== '') {
                $queueItemIds[] = $queueItemId;
            }
        }
        ksort($ownerCounts);
        ksort($statusCounts);

        return [
            'schema_version' => 'atlas.software_company.stewardship_release_outcome_summary.v1',
            'bridge_ap_contract' => 'AP-748',
            'area_id' => $areaId,
            'portfolio_id' => $portfolioId,
            'release_count' => count($releases),
            'owner_counts' => $ownerCounts,
            'status_counts' => $statusCounts,
            'owner_queue_pending_count' => (int) (($statusCounts[AreaFocusDevForgeReleaseService::STATUS_READY] ?? 0) + ($statusCounts[AreaFocusDevForgeReleaseService::STATUS_RECORDED] ?? 0)),
            'blocked_release_count' => (int) ($statusCounts[AreaFocusDevForgeReleaseService::STATUS_BLOCKED] ?? 0),
            'queue_item_ids' => array_values(array_unique($queueItemIds)),
        ];
    }

    /**
     * @param  array<string,mixed>  $summary
     * @return array<string,mixed>
     */
    private function portfolioFeed(string $areaId, string $portfolioId, array $summary): array
    {
        $pending = (int) ($summary['owner_queue_pending_count'] ?? 0);

        return [
            'schema_version' => 'atlas.software_company.stewardship_release_portfolio_feed.v1',
            'source_ap_contracts' => ['AP-747', 'AP-748'],
            'portfolio_id' => $portfolioId,
            'area_id' => $areaId,
            'areas' => $pending > 0 ? [[
                'area_id' => $areaId,
                'owner_queue_pending_count' => $pending,
                'blocked_release_count' => (int) ($summary['blocked_release_count'] ?? 0),
                'target_owners' => array_keys((array) ($summary['owner_counts'] ?? [])),
                'queue_item_ids' => (array) ($summary['queue_item_ids'] ?? []),
                'portfolio_signal' => 'owner_queue_ready_for_review',
                'recommended_portfolio_action' => 'prioritize_owner_queue_review_for_area',
            ]] : [],
            'claim_policy' => [
                'portfolio_input_only' => true,
                'portfolio_decision_executed' => false,
                'dev_or_forge_invoked' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $release
     */
    private function releaseId(array $release): string
    {
        $value = (string) ($release['release_id'] ?? data_get($release, 'queue_item.queue_item_id', ''));
        if ($value !== '') {
            return $value;
        }

        return 'ap747_'.substr(MissionCanonicalHash::sha256($release), 0, 18);
    }

    /**
     * @param  array<string,mixed>  $release
     */
    private function releaseTargetOwner(array $release): string
    {
        $owner = (string) ($release['target_owner'] ?? data_get($release, 'queue_item.target_owner', ''));

        return $owner !== '' ? $owner : 'unknown_owner';
    }

    /**
     * @param  array<string,mixed>  $release
     * @return array<string,mixed>
     */
    private function releaseSummaryPayload(array $release): array
    {
        return [
            'release_id' => $this->releaseId($release),
            'status' => (string) ($release['status'] ?? 'unknown'),
            'area_id' => (string) ($release['area_id'] ?? ''),
            'target_owner' => $this->releaseTargetOwner($release),
            'queue_item_id' => (string) data_get($release, 'queue_item.queue_item_id', ''),
            'handoff_hash' => (string) data_get($release, 'source_refs.handoff_hash', data_get($release, 'queue_item.handoff_hash', '')),
            'release_hash' => (string) ($release['release_hash'] ?? ''),
            'runtime_execution_started' => (bool) data_get($release, 'queue_item.runtime_execution_started', false),
            'provider_invoked' => (bool) data_get($release, 'queue_item.provider_invoked', false),
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function areaId(array $input): string
    {
        $value = trim((string) ($input['area_id'] ?? $input['area'] ?? ''));

        return $value !== '' ? $value : StewardshipEvolutionReadModelService::DEFAULT_AREA_ID;
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function portfolioId(array $input): string
    {
        $value = trim((string) ($input['portfolio_id'] ?? $input['portfolio'] ?? ''));

        return $value !== '' ? $value : PortfolioStewardshipHealthModelService::DEFAULT_PORTFOLIO_ID;
    }

    /**
     * @return array<string,bool|string>
     */
    private function claimPolicy(bool $recordEvidence, bool $emitInbox): array
    {
        return [
            'writes_evidence_ledger_when_requested' => $recordEvidence,
            'emits_morning_inbox_when_requested' => $emitInbox,
            'read_only_over_repo' => true,
            'mutates_target_repo' => false,
            'provider_invoked' => false,
            'dev_invoked' => false,
            'forge_invoked' => false,
            'opens_branch' => false,
            'creates_domain_runtime' => false,
            'creates_department' => false,
            'creates_new_os' => false,
            'parallel_ledger_created' => false,
            'parallel_inbox_created' => false,
            'merges' => false,
            'deploys' => false,
            'touches_secrets' => false,
            'auto_promotion' => false,
            'operator_review_required' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function finalize(array $payload): array
    {
        $hashPayload = $payload;
        unset($hashPayload['generated_at'], $hashPayload['bridge_hash']);

        $payload['bridge_hash'] = 'sha256:'.MissionCanonicalHash::sha256($hashPayload);
        $payload['generated_at'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->format(DateTimeInterface::ATOM);

        return $payload;
    }
}
