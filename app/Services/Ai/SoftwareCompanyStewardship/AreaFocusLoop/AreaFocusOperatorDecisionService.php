<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Area Focus Loop · Operator Decision Inbox Receipts (AP-724).
 *
 * Atlas Software Company Stewardship Stack é stack/capability family dentro do
 * Atlas Autonomous Software Company Runtime, não OS novo.
 *
 * Captures an explicit operator decision (accept | reject | defer |
 * request_changes) on an Area Focus inbox item (finding / spec / work order /
 * evidence pack) as a deterministic operator decision receipt. It mirrors the
 * established SelfDirectedEvolutionCurationInboxService::buildOperatorCuration
 * Receipt() pattern: operator-owned, never auto-decided.
 *
 * Hard guarantees: it NEVER auto-approves, NEVER auto-implements, NEVER executes
 * a branch/fix, NEVER mutates the target repo, NEVER invokes a provider and
 * creates NO parallel registry. An `accept` only declares the next allowed
 * action; a future slice acts on it under explicit operator review.
 */
class AreaFocusOperatorDecisionService
{
    public const RECEIPT_SCHEMA = 'atlas.software_company_stewardship.area_focus_operator_decision_receipt.v1';

    public const DECISION_ACCEPT = 'accept';

    public const DECISION_REJECT = 'reject';

    public const DECISION_DEFER = 'defer';

    public const DECISION_REQUEST_CHANGES = 'request_changes';

    /** @var list<string> */
    public const DECISIONS = [
        self::DECISION_ACCEPT,
        self::DECISION_REJECT,
        self::DECISION_DEFER,
        self::DECISION_REQUEST_CHANGES,
    ];

    /** Risk bands that require an explicit rationale before an accept. */
    private const HIGH_RISK_BANDS = ['high', 'critical'];

    public const BLOCK_ACTOR_REQUIRED = 'operator_actor_required';

    public const BLOCK_INVALID_DECISION = 'invalid_decision';

    public const BLOCK_ITEM_WITHOUT_HASH = 'item_without_hash';

    public const BLOCK_HIGH_RISK_ACCEPT_RATIONALE = 'rationale_required_for_high_risk_accept';

    /**
     * Build an operator decision receipt from an EXPLICIT operator decision.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     *
     * @throws InvalidArgumentException on any blocking rule.
     */
    public function decide(array $input): array
    {
        $actor = trim((string) ($input['operator_actor'] ?? ''));
        if ($actor === '') {
            throw new InvalidArgumentException(self::BLOCK_ACTOR_REQUIRED.': operator_actor is required (the operator must own the decision).');
        }

        $decision = strtolower(trim((string) ($input['decision'] ?? '')));
        if (! in_array($decision, self::DECISIONS, true)) {
            throw new InvalidArgumentException(self::BLOCK_INVALID_DECISION.': decision must be one of '.implode(', ', self::DECISIONS).'.');
        }

        $findingHash = trim((string) ($input['finding_hash'] ?? ''));
        if ($findingHash === '') {
            throw new InvalidArgumentException(self::BLOCK_ITEM_WITHOUT_HASH.': finding_hash is required as the deterministic decision anchor.');
        }

        $rationale = trim((string) ($input['rationale'] ?? ''));
        $risk = $this->normalizeRisk($input['risk'] ?? ($input['risk_level'] ?? null));
        if ($decision === self::DECISION_ACCEPT && in_array($risk, self::HIGH_RISK_BANDS, true) && $rationale === '') {
            throw new InvalidArgumentException(self::BLOCK_HIGH_RISK_ACCEPT_RATIONALE.": a high-risk accept ({$risk}) requires an explicit rationale.");
        }

        $inboxItemId = trim((string) ($input['inbox_item_id'] ?? ''));
        $workOrderId = isset($input['work_order_id']) && trim((string) $input['work_order_id']) !== ''
            ? trim((string) $input['work_order_id'])
            : null;
        $evidencePackHash = isset($input['evidence_pack_hash']) && trim((string) $input['evidence_pack_hash']) !== ''
            ? trim((string) $input['evidence_pack_hash'])
            : null;
        $areaId = (string) ($input['area_id'] ?? 'agentic_engineering_os');

        $decisionId = 'afod_'.substr(hash('sha256', implode('|', [
            'area_focus_operator_decision',
            $areaId,
            $inboxItemId,
            $findingHash,
            $workOrderId ?? '',
            $evidencePackHash ?? '',
            $decision,
            $actor,
        ])), 0, 16);

        $receipt = [
            'schema_version' => self::RECEIPT_SCHEMA,
            'ap_contract' => 'AP-724',
            'decision_id' => $decisionId,
            'area_id' => $areaId,
            'inbox_item_id' => $inboxItemId !== '' ? $inboxItemId : null,
            'finding_hash' => $findingHash,
            'work_order_id' => $workOrderId,
            'evidence_pack_hash' => $evidencePackHash,
            'operator_actor' => $actor,
            'decision' => $decision,
            'rationale' => $rationale,
            'risk_level' => $risk,
            'next_allowed_action' => $this->nextAllowedAction($decision),
            'routes_to_owner' => $this->routesToOwner($decision),
            // Hard guarantees — an accept unlocks the next stage, it never executes.
            'requires_owner_execution' => $decision === self::DECISION_ACCEPT,
            'executed' => false,
            'atlas_auto_decided' => false,
            'autoapproval_allowed' => false,
            'autoimplementation_allowed' => false,
            'branch_created' => false,
            'provider_invoked' => false,
            'mutates_target_repo' => false,
            'parallel_registry_created' => false,
            'operator_owned' => true,
        ];
        $receipt['decision_hash'] = 'sha256:'.MissionCanonicalHash::sha256($receipt);
        $receipt['decided_at'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->format(DateTimeInterface::ATOM);

        return $receipt;
    }

    /**
     * The stage a decision unlocks. None of these execute here; they are
     * declarations consumed by future slices under operator review.
     */
    private function nextAllowedAction(string $decision): string
    {
        return match ($decision) {
            self::DECISION_ACCEPT => 'release_to_owner_execution_under_operator_review',
            self::DECISION_REJECT => 'close_item_no_action',
            self::DECISION_DEFER => 're_review_next_area_focus_cycle',
            self::DECISION_REQUEST_CHANGES => 'return_to_spec_draft_revision',
            default => 'operator_review',
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function routesToOwner(string $decision): array
    {
        return match ($decision) {
            self::DECISION_ACCEPT => [
                'owner' => 'area_focus_loop',
                'note' => 'Operator-initiated execution is routed to Atlas Dev/Forge in a future slice; nothing executes here.',
            ],
            self::DECISION_REQUEST_CHANGES => [
                'owner' => 'self_directed_evolution',
                'note' => 'Spec draft revision belongs to the Self-Directed Evolution spec proposal adapter (AP-718).',
            ],
            default => [
                'owner' => 'area_focus_loop',
                'note' => 'No downstream owner action required for this decision.',
            ],
        };
    }

    private function normalizeRisk(mixed $value): string
    {
        if (! is_string($value)) {
            return 'medium';
        }
        $value = strtolower(trim($value));

        return in_array($value, ['critical', 'high', 'medium', 'low'], true) ? $value : 'medium';
    }
}
