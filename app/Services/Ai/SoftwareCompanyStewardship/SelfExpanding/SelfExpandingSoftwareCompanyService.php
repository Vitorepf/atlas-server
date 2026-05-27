<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\SelfExpanding;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipEvolutionReadModelService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Self-Expanding Software Company v0 (AP-738).
 *
 * Composes AP-737 into the top-level proposal-only company expansion view. This
 * is the ceiling of the Software Company Stewardship Stack, not an executor and
 * not a new OS. It can classify and prepare expansion proposals for review; it
 * cannot create areas, domains, departments or work branches.
 */
final class SelfExpandingSoftwareCompanyService
{
    public const REPORT_SCHEMA = 'atlas.software_company.self_expanding.v0';

    public const INBOX_ITEM_SCHEMA = 'atlas.software_company.self_expanding.operator_inbox_item.v1';

    public const STATUS_READY = 'ready_proposal_only';

    public const STATUS_BLOCKED = 'blocked';

    public function __construct(
        private readonly NewAreaProposalGateService $proposalGate,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function project(array $input = []): array
    {
        $gate = is_array($input['proposal_gate'] ?? null)
            ? $input['proposal_gate']
            : $this->proposalGate->evaluate($input);

        if (($gate['status'] ?? '') === NewAreaProposalGateService::STATUS_BLOCKED) {
            return $this->finalize([
                'schema_version' => self::REPORT_SCHEMA,
                'status' => self::STATUS_BLOCKED,
                'reason' => 'new_area_proposal_gate_blocked',
                'ap_contract' => 'AP-738',
                'area_id' => (string) ($gate['area_id'] ?? StewardshipEvolutionReadModelService::DEFAULT_AREA_ID),
                'portfolio_id' => (string) ($gate['portfolio_id'] ?? 'atlas_software_company'),
                'proposal_only' => true,
                'blockers' => ['AP-737 New Area Proposal Gate is blocked.'],
                'claim_policy' => $this->claimPolicy(),
            ]);
        }

        $items = array_values(array_filter((array) ($gate['gate_items'] ?? []), 'is_array'));
        $classified = $this->classify($items);
        $inbox = array_map(fn (array $item): array => $this->inboxItem($item), $items);

        return $this->finalize([
            'schema_version' => self::REPORT_SCHEMA,
            'status' => self::STATUS_READY,
            'ap_contract' => 'AP-738',
            'area_id' => (string) ($gate['area_id'] ?? StewardshipEvolutionReadModelService::DEFAULT_AREA_ID),
            'portfolio_id' => (string) ($gate['portfolio_id'] ?? 'atlas_software_company'),
            'stack' => 'Atlas Software Company Stewardship Stack',
            'layer' => 'Self-Expanding Software Company',
            'mode' => 'v0_proposal_only',
            'ceiling_scope' => 'software_company_stewardship_only',
            'not_a_new_os' => true,
            'source_ap_contracts' => ['AP-730', 'AP-731', 'AP-737'],
            'proposal_gate_status' => (string) ($gate['status'] ?? 'unknown'),
            'proposal_gate_hash' => (string) ($gate['gate_hash'] ?? ''),
            'expansion_summary' => [
                'total_candidates' => count($items),
                'new_domain_candidates' => count($classified['new_domain_candidates']),
                'existing_capability_handoffs' => count($classified['existing_capability_handoffs']),
                'sensitive_candidates' => count($classified['sensitive_candidates']),
                'ready_for_domain_runtime_creation_gate' => count($classified['ready_for_domain_runtime_creation_gate']),
                'blocked_or_needs_review' => count($classified['blocked_or_needs_review']),
            ],
            'new_domain_candidates' => $classified['new_domain_candidates'],
            'existing_capability_handoffs' => $classified['existing_capability_handoffs'],
            'sensitive_candidates' => $classified['sensitive_candidates'],
            'ready_for_domain_runtime_creation_gate' => $classified['ready_for_domain_runtime_creation_gate'],
            'operator_inbox' => [
                'schema_version' => 'atlas.software_company.self_expanding.operator_inbox.v1',
                'item_count' => count($inbox),
                'items' => $inbox,
                'decision_command' => 'php artisan atlas:software-company-stewardship new-area-proposal-decision --proposal-id=<proposal> --actor=<operator> --decision=<decision> --rationale=<why>',
            ],
            'expansion_loop' => [
                'detect_portfolio_gap',
                'dedupe_existing_capability_owner',
                'draft_new_area_proposal',
                'apply_ap737_new_area_proposal_gate',
                'collect_ap731_operator_decision',
                'handoff_to_domain_runtime_creation_gate_or_area_stewardship',
                'record_evidence_before_any_promotion',
            ],
            'promotion_boundary' => [
                'self_expansion_can_propose' => true,
                'self_expansion_can_create_area' => false,
                'self_expansion_can_promote_domain' => false,
                'operator_approval_required' => true,
                'domain_runtime_creation_gate_required' => true,
                'area_stewardship_handoff_required_for_existing_capabilities' => true,
            ],
            'claim_policy' => $this->claimPolicy(),
        ]);
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @return array<string,list<array<string,mixed>>>
     */
    private function classify(array $items): array
    {
        $out = [
            'new_domain_candidates' => [],
            'existing_capability_handoffs' => [],
            'sensitive_candidates' => [],
            'ready_for_domain_runtime_creation_gate' => [],
            'blocked_or_needs_review' => [],
        ];

        foreach ($items as $item) {
            $draft = is_array($item['domain_creation_proposal_draft'] ?? null) ? $item['domain_creation_proposal_draft'] : [];
            if (is_array($item['known_existing_owner'] ?? null)) {
                $out['existing_capability_handoffs'][] = $this->summary($item);
            } elseif (($draft['creation_recommended'] ?? true) === true) {
                $out['new_domain_candidates'][] = $this->summary($item);
            }

            if ((bool) data_get($draft, 'safety_sovereignty_block.required', false)) {
                $out['sensitive_candidates'][] = $this->summary($item);
            }

            if (($item['gate_status'] ?? '') === 'accepted_for_domain_runtime_creation_gate') {
                $out['ready_for_domain_runtime_creation_gate'][] = $this->summary($item);
            } else {
                $out['blocked_or_needs_review'][] = $this->summary($item);
            }
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>
     */
    private function inboxItem(array $item): array
    {
        $proposalId = (string) ($item['proposal_id'] ?? '');
        $candidate = (string) ($item['candidate_area'] ?? '');
        $status = (string) ($item['gate_status'] ?? 'awaiting_operator_review');

        return [
            'schema_version' => self::INBOX_ITEM_SCHEMA,
            'inbox_item_id' => 'seci_'.substr(MissionCanonicalHash::sha256([$proposalId, $candidate, $status]), 0, 16),
            'proposal_id' => $proposalId,
            'candidate_area' => $candidate,
            'gate_status' => $status,
            'review_route' => is_array($item['known_existing_owner'] ?? null)
                ? 'area_stewardship_existing_capability_handoff'
                : 'domain_runtime_creation_gate',
            'decision_anchor' => $item['operator_decision_anchor'] ?? [],
            'recommended_operator_action' => $this->recommendedAction($item),
            'irreversible_action_allowed' => false,
            'autoimplementation_allowed' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $item
     */
    private function recommendedAction(array $item): string
    {
        $status = (string) ($item['gate_status'] ?? '');
        if ($status === 'accepted_for_domain_runtime_creation_gate') {
            return 'prepare_domain_runtime_creation_gate_review_packet';
        }
        if (is_array($item['known_existing_owner'] ?? null)) {
            return 'route_existing_capability_to_area_stewardship_review';
        }
        if ((array) ($item['blockers'] ?? []) !== []) {
            return 'resolve_gate_blockers_before_accept';
        }

        return 'operator_review_accept_reject_defer_or_request_changes';
    }

    /**
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>
     */
    private function summary(array $item): array
    {
        return [
            'proposal_id' => (string) ($item['proposal_id'] ?? ''),
            'candidate_area' => (string) ($item['candidate_area'] ?? ''),
            'gate_status' => (string) ($item['gate_status'] ?? ''),
            'risk_level' => (string) ($item['risk_level'] ?? ''),
            'target_hash' => (string) data_get($item, 'operator_decision_anchor.target_hash', ''),
            'known_existing_owner' => $item['known_existing_owner'] ?? null,
            'blockers' => array_values(array_filter((array) ($item['blockers'] ?? []), 'is_string')),
        ];
    }

    /**
     * @return array<string,bool|string>
     */
    private function claimPolicy(): array
    {
        return [
            'proposal_only' => true,
            'read_only_over_repo' => true,
            'writes_local_state' => false,
            'provider_invoked' => false,
            'dev_invoked' => false,
            'forge_invoked' => false,
            'branch_created' => false,
            'worktree_created' => false,
            'domain_runtime_created' => false,
            'department_created' => false,
            'new_os_created' => false,
            'parallel_runtime_created' => false,
            'merge_without_operator' => false,
            'deploy_without_operator' => false,
            'secret_access' => false,
            'autoapproval_allowed' => false,
            'autoimplementation_allowed' => false,
            'auto_promotion' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function finalize(array $payload): array
    {
        $payload['report_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->stablePayload($payload));
        $payload['generated_at'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function stablePayload(array $payload): array
    {
        unset($payload['generated_at'], $payload['report_hash']);
        ksort($payload);

        return $payload;
    }
}
