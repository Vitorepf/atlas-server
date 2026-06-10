<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\SelfExpanding;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipStringListNormalizer;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipEvolutionDecisionLedgerService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipEvolutionReadModelService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Self-Expanding Software Company · New Area Proposal Gate (AP-737).
 *
 * Proposal-only gate over AP-730 new-area proposals and AP-731 operator
 * decisions. It validates whether a proposal may be handed to the existing
 * Domain Runtime Creation Gate. It never creates domains, departments,
 * branches, worktrees, runtimes or execution jobs.
 */
final class NewAreaProposalGateService
{
    public const GATE_SCHEMA = 'atlas.software_company.new_area_proposal_gate.v1';

    public const ITEM_SCHEMA = 'atlas.software_company.new_area_proposal_gate.item.v1';

    public const DOMAIN_PROPOSAL_SCHEMA = 'atlas.domain.creation_proposal.v1';

    public const STATUS_READY = 'ready';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_NO_PROPOSALS = 'no_proposals';

    public function __construct(
        private readonly StewardshipEvolutionReadModelService $evolution,
        private readonly StewardshipEvolutionDecisionLedgerService $decisionLedger,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function evaluate(array $input = []): array
    {
        $areaId = $this->areaId($input);
        $report = is_array($input['evolution_report'] ?? null)
            ? $input['evolution_report']
            : $this->evolution->project($input + ['area_id' => $areaId]);

        if (($report['status'] ?? '') === StewardshipEvolutionReadModelService::STATUS_BLOCKED) {
            return $this->finalize([
                'schema_version' => self::GATE_SCHEMA,
                'status' => self::STATUS_BLOCKED,
                'reason' => 'stewardship_evolution_not_ready',
                'ap_contract' => 'AP-737',
                'area_id' => $areaId,
                'portfolio_id' => (string) ($input['portfolio_id'] ?? 'atlas_software_company'),
                'proposal_count' => 0,
                'gate_item_count' => 0,
                'gate_items' => [],
                'blockers' => ['AP-730 stewardship evolution report is blocked.'],
                'claim_policy' => $this->claimPolicy(),
            ]);
        }

        $selfExpanding = is_array($input['self_expanding'] ?? null)
            ? $input['self_expanding']
            : (is_array($report['self_expanding_software_company'] ?? null) ? $report['self_expanding_software_company'] : []);

        $proposals = array_values(array_filter((array) ($selfExpanding['new_area_proposals'] ?? []), 'is_array'));
        $proposalId = trim((string) ($input['proposal_id'] ?? ''));
        if ($proposalId !== '') {
            $proposals = array_values(array_filter(
                $proposals,
                static fn (array $proposal): bool => (string) ($proposal['proposal_id'] ?? '') === $proposalId,
            ));
        }

        $decisions = is_array($input['decision_ledger'] ?? null)
            ? $input['decision_ledger']
            : $this->decisionLedger->listDecisions($areaId);

        $existingAreas = $this->existingAreas($report, $input);
        $items = array_map(
            fn (array $proposal): array => $this->gateItem($proposal, $report, $decisions, $existingAreas),
            $proposals,
        );

        return $this->finalize([
            'schema_version' => self::GATE_SCHEMA,
            'status' => $items === [] ? self::STATUS_NO_PROPOSALS : self::STATUS_READY,
            'ap_contract' => 'AP-737',
            'area_id' => $areaId,
            'portfolio_id' => (string) data_get($report, 'portfolio_stewardship.portfolio_id', $input['portfolio_id'] ?? 'atlas_software_company'),
            'mode' => 'proposal_only',
            'source_ap_contracts' => ['AP-730', 'AP-731'],
            'required_next_owner' => 'Atlas Domain Runtime Creation Gate',
            'required_next_owner_doc' => 'docs/engineering-knowledge-base/atlas-domain-runtime-creation-gate.md',
            'proposal_count' => count($proposals),
            'gate_item_count' => count($items),
            'decision_summary' => $this->decisionSummary($items),
            'gate_items' => $items,
            'operator_controls' => [
                'decision_options' => ['accept', 'reject', 'defer', 'request_changes'],
                'decision_command' => 'php artisan atlas:software-company-stewardship new-area-proposal-decision --proposal-id=<proposal> --actor=<operator> --decision=<decision> --rationale=<why>',
                'accept_means' => 'release proposal to Domain Runtime Creation Gate review; does not create a domain or department',
                'auto_promotion_allowed' => false,
            ],
            'claim_policy' => $this->claimPolicy(),
        ]);
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function decide(array $input): array
    {
        $proposalId = trim((string) ($input['proposal_id'] ?? ''));
        if ($proposalId === '') {
            throw new InvalidArgumentException('proposal_id_required: --proposal-id is required for new area proposal decisions.');
        }

        $gate = $this->evaluate($input);
        $item = $this->findGateItem($gate, $proposalId);
        if ($item === null) {
            throw new InvalidArgumentException('proposal_not_found: proposal_id was not present in the AP-737 gate projection.');
        }

        return $this->decisionLedger->record([
            'area_id' => (string) ($gate['area_id'] ?? StewardshipEvolutionReadModelService::DEFAULT_AREA_ID),
            'portfolio_id' => (string) ($gate['portfolio_id'] ?? 'atlas_software_company'),
            'target_type' => 'new_area_proposal',
            'target_id' => $proposalId,
            'target_hash' => (string) ($item['proposal_hash'] ?? ''),
            'operator_actor' => (string) ($input['operator_actor'] ?? $input['actor'] ?? ''),
            'decision' => (string) ($input['decision'] ?? ''),
            'rationale' => (string) ($input['rationale'] ?? ''),
            'risk' => (string) ($item['risk_level'] ?? $input['risk'] ?? 'medium'),
        ]);
    }

    /**
     * @param  array<string,mixed>  $proposal
     * @param  array<string,mixed>  $report
     * @param  array<string,mixed>  $decisions
     * @param  list<string>  $existingAreas
     * @return array<string,mixed>
     */
    private function gateItem(array $proposal, array $report, array $decisions, array $existingAreas): array
    {
        $proposalId = (string) ($proposal['proposal_id'] ?? 'new_area_'.substr(MissionCanonicalHash::sha256($proposal), 0, 16));
        $candidate = $this->normalizeTechnicalName((string) ($proposal['candidate_area'] ?? $proposalId));
        $proposalHash = 'sha256:'.MissionCanonicalHash::sha256($this->stableProposal($proposal));
        $decision = $this->latestDecision($proposalId, $proposalHash, $decisions);
        $decisionValue = (string) ($decision['decision'] ?? '');
        $sensitive = $this->sensitiveDomainPolicy($candidate);
        $knownOwner = $this->knownCapabilityOwner($candidate);

        $blockers = [];
        if (in_array($candidate, $existingAreas, true)) {
            $blockers[] = 'candidate_area_already_exists';
        }
        if ($knownOwner !== null) {
            $blockers[] = 'existing_capability_requires_area_stewardship_handoff_not_domain_creation';
        }
        if ($sensitive['sensitive'] === true) {
            $blockers[] = 'sensitive_domain_requires_explicit_safety_sovereignty_review';
        }

        $gateStatus = match ($decisionValue) {
            'accept' => $blockers === [] ? 'accepted_for_domain_runtime_creation_gate' : 'accepted_but_blocked_by_gate',
            'reject' => 'rejected',
            'defer' => 'deferred',
            'request_changes' => 'changes_requested',
            default => $blockers === [] ? 'awaiting_operator_review' : 'blocked_awaiting_operator_review',
        };

        return [
            'schema_version' => self::ITEM_SCHEMA,
            'gate_item_id' => 'napg_'.substr(MissionCanonicalHash::sha256([$proposalId, $candidate, $proposalHash]), 0, 16),
            'proposal_id' => $proposalId,
            'candidate_area' => $candidate,
            'proposal_hash' => $proposalHash,
            'gate_status' => $gateStatus,
            'risk_level' => $sensitive['sensitive'] === true ? 'high' : 'medium',
            'gap_detected' => (string) ($proposal['gap_detected'] ?? ''),
            'portfolio_id' => (string) ($proposal['portfolio_id'] ?? data_get($report, 'portfolio_stewardship.portfolio_id', 'atlas_software_company')),
            'operator_decision' => [
                'has_decision' => $decision !== null,
                'decision_id' => (string) ($decision['decision_id'] ?? ''),
                'decision' => $decisionValue,
                'actor' => (string) ($decision['operator_actor'] ?? ''),
                'recorded_at' => (string) ($decision['recorded_at'] ?? ''),
            ],
            'operator_decision_anchor' => [
                'target_type' => 'new_area_proposal',
                'target_id' => $proposalId,
                'target_hash' => $proposalHash,
            ],
            'known_existing_owner' => $knownOwner,
            'domain_creation_proposal_draft' => $this->domainCreationProposalDraft($proposal, $candidate, $proposalHash, $sensitive, $knownOwner),
            'required_next_gate' => [
                'owner' => $knownOwner === null ? 'domain_runtime_creation_gate' : 'area_stewardship_existing_capability_handoff',
                'owner_doc' => $knownOwner === null
                    ? 'docs/engineering-knowledge-base/atlas-domain-runtime-creation-gate.md'
                    : 'docs/engineering-knowledge-base/atlas-area-stewardship-layer.md',
                'schema_required' => self::DOMAIN_PROPOSAL_SCHEMA,
                'operator_approval_required' => true,
                'dual_signature_required' => true,
                'sandbox_required' => true,
                'shadow_mode_required' => true,
                'l0_l1_promotion_required' => true,
            ],
            'blockers' => $blockers,
            'claim_policy' => [
                'proposal_only' => true,
                'creates_area' => false,
                'creates_domain_runtime' => false,
                'creates_department' => false,
                'auto_promotion' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $proposal
     * @param  array<string,mixed>  $sensitive
     * @return array<string,mixed>
     */
    private function domainCreationProposalDraft(array $proposal, string $candidate, string $proposalHash, array $sensitive, ?array $knownOwner): array
    {
        $humanName = trim((string) ($proposal['candidate_area_name'] ?? str_replace('_', ' ', $candidate)));
        $title = $humanName === '' ? $candidate : ucwords($humanName);
        $canonicalName = str_starts_with($title, 'Atlas ')
            ? $title.' Domain Runtime'
            : 'Atlas '.$title.' Domain Runtime';

        return [
            'schema' => self::DOMAIN_PROPOSAL_SCHEMA,
            'draft_only' => true,
            'creation_recommended' => $knownOwner === null,
            'blocked_by_existing_owner' => $knownOwner !== null,
            'source_ap_contract' => 'AP-737',
            'source_proposal_id' => (string) ($proposal['proposal_id'] ?? ''),
            'source_proposal_hash' => $proposalHash,
            'kind' => 'domain_runtime',
            'human_name' => $title,
            'canonical_name' => $canonicalName,
            'technical_name' => $candidate,
            'owner_role' => 'software_company_stewardship',
            'known_existing_owner' => $knownOwner,
            'sovereignty_class' => $sensitive['sovereignty_class'],
            'intent_pattern_evidence' => [
                'gap_detected' => (string) ($proposal['gap_detected'] ?? ''),
                'evidence_refs' => array_values(array_filter((array) ($proposal['evidence_refs'] ?? []), 'is_string')),
            ],
            'proposed_gates' => [
                'domain_creation_gate',
                'operator_approval',
                'dual_signature',
                'sandbox_7d',
                'shadow_14d',
                'replay_50_intents_minimum',
            ],
            'proposed_evidence_sources' => [
                'AP-730 stewardship evolution report',
                'AP-731 operator decision receipt',
                'AP-737 new area proposal gate',
            ],
            'safety_sovereignty_block' => $sensitive['safety_block'],
            'promotion_policy' => [
                'l0_requires_operator_and_one_architect' => true,
                'l1_requires_operator_and_two_architects' => true,
                'replay_minimum_intents' => $sensitive['sensitive'] === true ? 100 : 50,
                'confirmed_rate_minimum' => 0.95,
                'auto_promotion' => false,
            ],
        ];
    }

    /**
     * @return array<string,string>|null
     */
    private function knownCapabilityOwner(string $candidate): ?array
    {
        $owners = [
            'agentic_engineering_os' => 'docs/engineering-knowledge-base/atlas-agentic-engineering-os.md',
            'aaeos' => 'docs/engineering-knowledge-base/atlas-agentic-engineering-os.md',
            'atlas_dev' => 'docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md',
            'atlas_forge' => 'docs/engineering-knowledge-base/atlas-forge-operating-system.md',
            'self_directed_evolution' => 'docs/engineering-knowledge-base/atlas-self-directed-evolution-layer.md',
            'evidence' => 'docs/engineering-knowledge-base/atlas-evidence-certification-runtime.md',
        ];

        if (! isset($owners[$candidate])) {
            return null;
        }

        return [
            'area_id' => $candidate,
            'owner_doc' => $owners[$candidate],
            'required_action' => 'route_to_area_stewardship_or_portfolio_stewardship_instead_of_creating_new_domain',
        ];
    }

    /**
     * @param  array<string,mixed>  $report
     * @param  array<string,mixed>  $input
     * @return list<string>
     */
    private function existingAreas(array $report, array $input): array
    {
        $areas = array_values(array_filter((array) ($input['existing_area_ids'] ?? []), 'is_string'));
        foreach ((array) data_get($report, 'portfolio_stewardship.areas', []) as $area) {
            if (is_array($area)) {
                $areas[] = (string) ($area['area_id'] ?? '');
            }
        }

        return StewardshipStringListNormalizer::uniqueMappedTruthyStringValues(
            $areas,
            fn (mixed $area): string => $this->normalizeTechnicalName((string) $area),
        );
    }

    /**
     * @param  array<string,mixed>  $decisions
     * @return array<string,mixed>|null
     */
    private function latestDecision(string $proposalId, string $proposalHash, array $decisions): ?array
    {
        $matches = [];
        foreach ((array) ($decisions['decisions'] ?? []) as $decision) {
            if (! is_array($decision) || (string) ($decision['target_type'] ?? '') !== 'new_area_proposal') {
                continue;
            }
            $targetId = (string) ($decision['target_id'] ?? '');
            $targetHash = (string) ($decision['target_hash'] ?? '');
            if ($targetId !== $proposalId && $targetHash !== $proposalHash) {
                continue;
            }
            $matches[] = $decision;
        }

        usort($matches, static fn (array $a, array $b): int => strcmp((string) ($b['recorded_at'] ?? ''), (string) ($a['recorded_at'] ?? '')));

        return $matches[0] ?? null;
    }

    /**
     * @param  array<string,mixed>  $gate
     * @return array<string,mixed>|null
     */
    private function findGateItem(array $gate, string $proposalId): ?array
    {
        foreach ((array) ($gate['gate_items'] ?? []) as $item) {
            if (is_array($item) && (string) ($item['proposal_id'] ?? '') === $proposalId) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private function sensitiveDomainPolicy(string $candidate): array
    {
        $isCyber = str_contains($candidate, 'cyber') || str_contains($candidate, 'security') || str_contains($candidate, 'red_team');
        $isSensitive = $isCyber;
        foreach (['legal', 'health', 'healthcare', 'finance', 'financial', 'trading', 'trade', 'medical'] as $term) {
            $isSensitive = $isSensitive || str_contains($candidate, $term);
        }

        return [
            'sensitive' => $isSensitive,
            'sovereignty_class' => $isCyber ? 'cyber' : ($isSensitive ? 'sensitive' : 'ok_to_share'),
            'safety_block' => [
                'required' => $isSensitive,
                'operation_mode' => $isSensitive ? 'assistive_research_draft_analysis_only' : 'standard_proposal_only',
                'licensed_human_review_required' => $isSensitive,
                'no_autonomous_professional_advice' => $isSensitive,
                'mandatory_gates' => $isSensitive ? [
                    'jurisdiction_check',
                    'consent_chain_verified',
                    'audit_trail_complete',
                    'liability_carrier_documented',
                    'policy_gate_passed',
                ] : [],
            ],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @return array<string,int>
     */
    private function decisionSummary(array $items): array
    {
        $summary = [
            'total' => count($items),
            'awaiting_operator_review' => 0,
            'accepted_for_domain_runtime_creation_gate' => 0,
            'accepted_but_blocked_by_gate' => 0,
            'rejected' => 0,
            'deferred' => 0,
            'changes_requested' => 0,
            'blocked_awaiting_operator_review' => 0,
        ];

        foreach ($items as $item) {
            $status = (string) ($item['gate_status'] ?? 'awaiting_operator_review');
            if (array_key_exists($status, $summary)) {
                $summary[$status]++;
            }
        }

        return $summary;
    }

    /**
     * @param  array<string,mixed>  $proposal
     * @return array<string,mixed>
     */
    private function stableProposal(array $proposal): array
    {
        unset($proposal['generated_at'], $proposal['proposal_hash']);
        ksort($proposal);

        return $proposal;
    }

    private function areaId(array $input): string
    {
        $areaId = trim((string) ($input['area_id'] ?? StewardshipEvolutionReadModelService::DEFAULT_AREA_ID));

        return $areaId === '' ? StewardshipEvolutionReadModelService::DEFAULT_AREA_ID : $areaId;
    }

    private function normalizeTechnicalName(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?: 'new_area';
        $value = trim($value, '_');

        return $value === '' ? 'new_area' : $value;
    }

    /**
     * @return array<string,bool|string>
     */
    private function claimPolicy(): array
    {
        return [
            'read_only_over_repo' => true,
            'proposal_only' => true,
            'writes_local_state' => false,
            'records_operator_decisions_only_via_ap731' => true,
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
        $payload['gate_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->stablePayload($payload));
        $payload['generated_at'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function stablePayload(array $payload): array
    {
        unset($payload['generated_at'], $payload['gate_hash']);
        ksort($payload);

        return $payload;
    }
}
