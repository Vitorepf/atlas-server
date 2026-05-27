<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfDirectedEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasAutonomousEvolutionLoopService;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionSubsystemBuilderService;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalBacklogService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Self-Directed Evolution · Operator Curation Inbox (v0.2).
 *
 * Read-only projection (AP-707, AP-709) over the v0.1 gap read model. It turns
 * `atlas.evolution.gap_candidate.v1` candidates into deterministic curation
 * items the operator reviews, approves or vetoes. It persists nothing, invokes
 * no provider, writes no canonical doc and never auto-approves. Promotion of any
 * item is routed back to the canonical owner; this layer is not an authority.
 */
class SelfDirectedEvolutionCurationInboxService
{
    public const INBOX_SCHEMA = 'atlas.self_directed_evolution.curation_inbox.v1';

    public const ITEM_SCHEMA = 'atlas.self_directed_evolution.curation_item.v1';

    public const RECEIPT_SCHEMA = 'atlas.self_directed_evolution.operator_curation_receipt.v1';

    public const STATUS_PENDING_OPERATOR_REVIEW = 'pending_operator_review';

    public const DECISION_APPROVE = 'approve';

    public const DECISION_VETO = 'veto';

    public const DECISION_NEEDS_REVISION = 'needs_revision';

    /** @var list<string> */
    public const OPERATOR_ACTIONS = [
        self::DECISION_APPROVE,
        self::DECISION_VETO,
        self::DECISION_NEEDS_REVISION,
    ];

    public function __construct(
        private readonly SelfDirectedEvolutionGapReadModelService $gapReadModel,
    ) {}

    /**
     * Project the operator curation inbox from the gap read model.
     *
     * `$input` accepts a `gap_read_model` override (a full read-model report) so
     * the inbox can be projected deterministically without re-running the read
     * model; otherwise the remaining `$input` keys are forwarded to the read
     * model (`hours`, `limit`, `gaps`, `self_improvement_backlog`,
     * `aael_control_plane`, ...).
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function project(array $input = []): array
    {
        $report = array_key_exists('gap_read_model', $input) && is_array($input['gap_read_model'])
            ? $input['gap_read_model']
            : $this->gapReadModel->project($input);

        $candidates = is_array($report['candidates'] ?? null) ? $report['candidates'] : [];

        $items = [];
        $seen = [];
        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            $hash = (string) ($candidate['candidate_hash'] ?? '');
            if ($hash !== '' && isset($seen[$hash])) {
                continue;
            }
            $seen[$hash] = true;
            $items[] = $this->curationItem($candidate);
        }

        $items = $this->sortItems($items);

        $payload = [
            'schema_version' => self::INBOX_SCHEMA,
            'status' => (string) ($report['status'] ?? SelfDirectedEvolutionGapReadModelService::STATUS_READY),
            'source_read_model' => [
                'schema_version' => (string) ($report['schema_version'] ?? SelfDirectedEvolutionGapReadModelService::REPORT_SCHEMA),
                'status' => (string) ($report['status'] ?? 'unknown'),
                'report_hash' => (string) ($report['report_hash'] ?? ''),
            ],
            'item_count' => count($items),
            'counts' => $this->counts($items),
            'items' => $items,
            'blockers' => is_array($report['blockers'] ?? null) ? $report['blockers'] : [],
            'claim_policy' => $this->claimPolicy(),
        ];
        $payload['inbox_hash'] = 'sha256:'.MissionCanonicalHash::sha256($payload);
        $payload['generated_at'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->format(DateTimeInterface::ATOM);

        return $payload;
    }

    /**
     * Build an operator curation receipt from an EXPLICIT operator decision.
     *
     * This never auto-decides: the caller must supply a valid `decision`. The
     * receipt is not persisted and not executed; it records the decision and the
     * canonical owner the operator must route execution to.
     *
     * @param  array<string,mixed>  $decision
     * @return array<string,mixed>
     */
    public function buildOperatorCurationReceipt(array $decision): array
    {
        $action = (string) ($decision['decision'] ?? '');
        if (! in_array($action, self::OPERATOR_ACTIONS, true)) {
            throw new InvalidArgumentException(
                "decision must be one of: ".implode(', ', self::OPERATOR_ACTIONS)
            );
        }
        $candidateHash = (string) ($decision['candidate_hash'] ?? '');
        if ($candidateHash === '') {
            throw new InvalidArgumentException('candidate_hash is required.');
        }
        $actor = trim((string) ($decision['actor'] ?? ''));
        if ($actor === '') {
            throw new InvalidArgumentException('actor is required (the operator must own the decision).');
        }

        $sourceOwner = (string) ($decision['source_owner'] ?? '');
        $receipt = [
            'schema_version' => self::RECEIPT_SCHEMA,
            'candidate_hash' => $candidateHash,
            'decision' => $action,
            'actor' => $actor,
            'rationale' => (string) ($decision['rationale'] ?? ''),
            'routes_to_owner' => $sourceOwner !== '' ? $this->routesToOwner($sourceOwner) : null,
            'atlas_auto_decided' => false,
            'autoapproval' => false,
            'executed' => false,
            'requires_owner_execution' => true,
            'canonical_doc_write_allowed' => false,
            'autoimplementation_allowed' => false,
        ];
        $receipt['receipt_hash'] = 'sha256:'.MissionCanonicalHash::sha256($receipt);
        $receipt['decided_at'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->format(DateTimeInterface::ATOM);

        return $receipt;
    }

    // ---------- item normalization ----------

    /**
     * @param  array<string,mixed>  $candidate
     * @return array<string,mixed>
     */
    private function curationItem(array $candidate): array
    {
        $hash = (string) ($candidate['candidate_hash'] ?? '');
        $sourceOwner = (string) ($candidate['source_owner'] ?? 'unknown');
        $risk = (string) ($candidate['risk_level'] ?? 'medium');
        $priority = (int) ($candidate['priority_score'] ?? 0);

        return [
            'schema_version' => self::ITEM_SCHEMA,
            'item_id' => 'curi_'.substr(hash('sha256', 'curation|'.$hash), 0, 16),
            'candidate_hash' => $hash,
            'candidate_id' => (string) ($candidate['candidate_id'] ?? ''),
            'source_owner' => $sourceOwner,
            'source_schema_version' => (string) ($candidate['source_schema_version'] ?? ''),
            'gap_kind' => (string) ($candidate['gap_kind'] ?? ''),
            'title' => (string) ($candidate['title'] ?? ''),
            'rationale' => (string) ($candidate['rationale'] ?? ''),
            'capability' => $candidate['capability'] ?? null,
            'risk_level' => $risk,
            'priority_score' => $priority,
            'status' => self::STATUS_PENDING_OPERATOR_REVIEW,
            'classification' => [
                'source_owner' => $sourceOwner,
                'risk_band' => $risk,
                'priority_band' => $this->priorityBand($priority),
            ],
            'evidence_refs' => array_values((array) ($candidate['evidence_refs'] ?? [])),
            'owner_doc_refs' => array_values((array) ($candidate['owner_doc_refs'] ?? [])),
            'proposed_next_action' => (string) ($candidate['proposed_next_action'] ?? ''),
            'operator_actions' => self::OPERATOR_ACTIONS,
            'routes_to_owner' => $this->routesToOwner($sourceOwner),
            'spec_draftable' => true,
            'requires_operator_review' => true,
            'autoapproval_allowed' => false,
            'external_side_effect_allowed' => false,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @return list<array<string,mixed>>
     */
    private function sortItems(array $items): array
    {
        usort($items, static function (array $a, array $b): int {
            return (($b['priority_score'] ?? 0) <=> ($a['priority_score'] ?? 0))
                ?: (((string) ($a['source_owner'] ?? '')) <=> ((string) ($b['source_owner'] ?? '')))
                ?: (((string) ($a['candidate_hash'] ?? '')) <=> ((string) ($b['candidate_hash'] ?? '')));
        });

        return array_values($items);
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @return array<string,mixed>
     */
    private function counts(array $items): array
    {
        $bySource = [];
        $byRisk = [];
        foreach ($items as $item) {
            $source = (string) ($item['source_owner'] ?? 'unknown');
            $risk = (string) ($item['risk_level'] ?? 'unknown');
            $bySource[$source] = ($bySource[$source] ?? 0) + 1;
            $byRisk[$risk] = ($byRisk[$risk] ?? 0) + 1;
        }
        ksort($bySource);
        ksort($byRisk);

        return [
            'total' => count($items),
            'pending_operator_review' => count($items),
            'by_source_owner' => $bySource,
            'by_risk' => $byRisk,
        ];
    }

    private function priorityBand(int $priority): string
    {
        return match (true) {
            $priority >= 300 => 'high',
            $priority >= 200 => 'medium',
            default => 'low',
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function routesToOwner(string $sourceOwner): array
    {
        return match ($sourceOwner) {
            SelfDirectedEvolutionGapReadModelService::SOURCE_SELF_CONSTRUCTION => [
                'owner' => $sourceOwner,
                'owner_service' => AtlasSelfConstructionSubsystemBuilderService::class,
                'operator_execution_methods' => ['propose', 'approve'],
            ],
            SelfDirectedEvolutionGapReadModelService::SOURCE_SELF_IMPROVEMENT => [
                'owner' => $sourceOwner,
                'owner_service' => AtlasSelfImprovementProposalBacklogService::class,
                'operator_execution_methods' => ['createProposal', 'evaluateProposal', 'prioritize'],
            ],
            SelfDirectedEvolutionGapReadModelService::SOURCE_AAEL => [
                'owner' => $sourceOwner,
                'owner_service' => AtlasAutonomousEvolutionLoopService::class,
                'operator_execution_methods' => ['runCycle', 'observeOpportunities'],
            ],
            default => [
                'owner' => $sourceOwner,
                'owner_service' => null,
                'operator_execution_methods' => [],
            ],
        };
    }

    /**
     * @return array<string,bool>
     */
    private function claimPolicy(): array
    {
        return [
            'read_only' => true,
            'writes_state' => false,
            'persists_registry' => false,
            'invokes_propose' => false,
            'invokes_approve' => false,
            'creates_backlog_proposal' => false,
            'creates_aael_cycle' => false,
            'provider_invoked' => false,
            'canonical_doc_write_allowed' => false,
            'autoapproval_allowed' => false,
            'autoimplementation_allowed' => false,
            'external_side_effect_allowed' => false,
            'parallel_authority_created' => false,
            'operator_curation_required' => true,
        ];
    }
}
