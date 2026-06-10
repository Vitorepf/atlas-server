<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\SelfExpanding;

use App\Models\AiDomainManifest;
use App\Services\Ai\DomainRuntime\DomainManifestRegistryService;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompanyStewardship\PortfolioStewardship\PortfolioStewardshipHealthModelService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOutcomeEvidenceBridgeService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipStringListNormalizer;
use App\Services\Ai\Support\AppendOnlyJsonlStore;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\Schema;

/**
 * AP-741 · Self-Expanding -> Domain Runtime Creation Gate handoff.
 *
 * Builds a durable, operator-reviewable handoff packet for AP-738 proposals that
 * were accepted through AP-731/AP-737 and proven through AP-740. It never
 * creates a domain, department, manifest, branch, provider run or execution job.
 */
final class SelfExpandingDomainRuntimeCreationHandoffService
{
    public const REPORT_SCHEMA = 'atlas.software_company.self_expanding.domain_runtime_creation_handoff.v1';

    public const PACKET_SCHEMA = 'atlas.domain.creation_handoff_packet.v1';

    public const STATUS_READY = 'ready';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_NO_READY_PROPOSALS = 'no_ready_proposals';

    private ?string $storageRootOverride = null;

    public function __construct(
        private readonly NewAreaProposalGateService $proposalGate,
        private readonly SelfExpandingSoftwareCompanyService $selfExpanding,
        private readonly StewardshipOutcomeEvidenceBridgeService $outcomeBridge,
        private readonly DomainManifestRegistryService $domainRegistry,
    ) {}

    public function setStorageRootForTesting(?string $dir): void
    {
        $this->storageRootOverride = $dir;
    }

    public function storageDir(): string
    {
        if ($this->storageRootOverride !== null) {
            return $this->storageRootOverride;
        }

        return function_exists('storage_path')
            ? storage_path('atlas/software_company_stewardship/domain_runtime_creation_handoffs')
            : sys_get_temp_dir().'/atlas/software_company_stewardship/domain_runtime_creation_handoffs';
    }

    public function packetFilePath(string $areaId): string
    {
        return $this->storageDir().DIRECTORY_SEPARATOR.$this->slug($areaId).'.jsonl';
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function project(array $input = []): array
    {
        $areaId = $this->areaId($input);
        $portfolioId = $this->portfolioId($input);
        $proposalId = trim((string) ($input['proposal_id'] ?? ''));
        $recordHandoff = (bool) ($input['record_handoff'] ?? false);
        $requireRecordedEvidence = array_key_exists('require_recorded_evidence', $input)
            ? (bool) $input['require_recorded_evidence']
            : true;

        $gate = is_array($input['proposal_gate'] ?? null)
            ? $input['proposal_gate']
            : $this->proposalGate->evaluate($input + [
                'area_id' => $areaId,
                'portfolio_id' => $portfolioId,
            ]);

        $selfExpanding = is_array($input['self_expanding'] ?? null)
            ? $input['self_expanding']
            : $this->selfExpanding->project($input + [
                'area_id' => $areaId,
                'portfolio_id' => $portfolioId,
            ]);

        $outcomes = is_array($input['outcome_bridge'] ?? null)
            ? $input['outcome_bridge']
            : $this->outcomeBridge->project([
                'area_id' => $areaId,
                'portfolio_id' => $portfolioId,
                'proposal_id' => $proposalId,
            ]);

        $items = array_values(array_filter((array) ($gate['gate_items'] ?? []), 'is_array'));
        if ($proposalId !== '') {
            $items = array_values(array_filter(
                $items,
                static fn (array $item): bool => (string) ($item['proposal_id'] ?? '') === $proposalId,
            ));
        }

        $packets = [];
        foreach ($items as $item) {
            if ((string) ($item['gate_status'] ?? '') !== 'accepted_for_domain_runtime_creation_gate') {
                continue;
            }

            $packet = $this->handoffPacket($areaId, $portfolioId, $item, $selfExpanding, $outcomes, $requireRecordedEvidence);
            if ($recordHandoff && $packet['handoff_status'] === 'ready_for_domain_runtime_creation_gate') {
                $packet = $this->recordPacket($areaId, $packet);
            }
            $packets[] = $packet;
        }

        $ready = count(array_filter($packets, static fn (array $packet): bool => (string) ($packet['handoff_status'] ?? '') === 'ready_for_domain_runtime_creation_gate'));
        $blocked = count(array_filter($packets, static fn (array $packet): bool => (array) ($packet['blockers'] ?? []) !== []));

        $status = $ready > 0
            ? self::STATUS_READY
            : ($packets === [] ? self::STATUS_NO_READY_PROPOSALS : self::STATUS_BLOCKED);

        return $this->finalize([
            'schema_version' => self::REPORT_SCHEMA,
            'status' => $status,
            'ap_contract' => 'AP-741',
            'area_id' => $areaId,
            'portfolio_id' => $portfolioId,
            'stack' => 'Atlas Software Company Stewardship Stack',
            'layer' => 'Self-Expanding Software Company',
            'source_ap_contracts' => ['AP-731', 'AP-737', 'AP-738', 'AP-740'],
            'target_owner' => 'Atlas Domain Runtime Creation Gate',
            'target_owner_doc' => 'docs/engineering-knowledge-base/atlas-domain-runtime-creation-gate.md',
            'record_handoff_requested' => $recordHandoff,
            'require_recorded_evidence' => $requireRecordedEvidence,
            'accepted_candidate_count' => count($packets),
            'ready_handoff_count' => $ready,
            'blocked_handoff_count' => $blocked,
            'handoff_packets' => $packets,
            'next_actions' => $this->nextActions($status, $ready, $blocked),
            'claim_policy' => $this->claimPolicy($recordHandoff),
        ]);
    }

    /**
     * @param  array<string,mixed>  $item
     * @param  array<string,mixed>  $selfExpanding
     * @param  array<string,mixed>  $outcomes
     * @return array<string,mixed>
     */
    private function handoffPacket(
        string $areaId,
        string $portfolioId,
        array $item,
        array $selfExpanding,
        array $outcomes,
        bool $requireRecordedEvidence,
    ): array {
        $proposalId = (string) ($item['proposal_id'] ?? '');
        $candidate = $this->slug((string) ($item['candidate_area'] ?? ''));
        $proposalHash = (string) ($item['proposal_hash'] ?? '');
        $draft = is_array($item['domain_creation_proposal_draft'] ?? null) ? $item['domain_creation_proposal_draft'] : [];
        $evidence = $this->evidenceForProposal($proposalId, $proposalHash, $outcomes);
        $registry = $this->domainRegistryCheck($candidate);

        $blockers = array_values(array_filter((array) ($item['blockers'] ?? []), 'is_string'));
        if (is_array($item['known_existing_owner'] ?? null)) {
            $blockers[] = 'known_existing_owner_requires_area_stewardship_not_domain_creation';
        }
        if (($draft['creation_recommended'] ?? true) !== true) {
            $blockers[] = 'domain_creation_not_recommended_by_ap737';
        }
        if (($registry['exists'] ?? false) === true) {
            $blockers[] = 'domain_manifest_already_exists';
        }
        if ($requireRecordedEvidence && $evidence['recorded_ready'] !== true) {
            $blockers[] = 'ap740_recorded_evidence_required_before_handoff';
        }

        $packet = [
            'schema_version' => self::PACKET_SCHEMA,
            'handoff_packet_id' => 'drch_'.substr(MissionCanonicalHash::sha256([$areaId, $portfolioId, $proposalId, $proposalHash]), 0, 22),
            'handoff_status' => $blockers === [] ? 'ready_for_domain_runtime_creation_gate' : 'blocked',
            'handoff_storage_status' => 'projected',
            'area_id' => $areaId,
            'portfolio_id' => $portfolioId,
            'proposal_id' => $proposalId,
            'candidate_area' => $candidate,
            'proposal_hash' => $proposalHash,
            'source_gate_item_id' => (string) ($item['gate_item_id'] ?? ''),
            'source_self_expanding_report_hash' => (string) ($selfExpanding['report_hash'] ?? ''),
            'source_outcome_bridge_hash' => (string) ($outcomes['bridge_hash'] ?? ''),
            'operator_decision' => $item['operator_decision'] ?? [],
            'domain_creation_proposal_envelope' => $draft,
            'evidence_gate' => $evidence,
            'domain_registry_check' => $registry,
            'required_gate_sequence' => [
                'proposal_envelope_valid',
                'operator_accept_receipt_present',
                'ap740_evidence_registered',
                'domain_runtime_creation_gate_review',
                'sandbox_min_7_days',
                'shadow_min_14_days',
                'l0_operator_plus_one_architect_signature',
                'l1_operator_plus_two_architect_signatures',
                'replay_50_intents_minimum_or_100_if_sensitive',
                'creation_receipt_to_evidence_and_trust_ledgers',
            ],
            'target_gate' => [
                'owner' => 'domain_runtime_creation_gate',
                'owner_doc' => 'docs/engineering-knowledge-base/atlas-domain-runtime-creation-gate.md',
                'schema_required' => NewAreaProposalGateService::DOMAIN_PROPOSAL_SCHEMA,
                'operator_approval_required' => true,
                'dual_signature_required' => true,
                'auto_promotion_allowed' => false,
            ],
            'blockers' => StewardshipStringListNormalizer::uniqueStrings($blockers),
            'claim_policy' => $this->packetClaimPolicy(),
        ];

        $packet['packet_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->stablePacket($packet));

        return $packet;
    }

    /**
     * @param  array<string,mixed>  $outcomes
     * @return array<string,mixed>
     */
    private function evidenceForProposal(string $proposalId, string $proposalHash, array $outcomes): array
    {
        $refs = [];
        $recordedRefs = [];
        $hasDecisionEvidence = false;
        $hasSelfExpandingEvidence = false;

        foreach ((array) ($outcomes['evidence_items'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $sourceKind = (string) ($item['source_kind'] ?? '');
            $payload = is_array($item['payload'] ?? null) ? $item['payload'] : [];
            $status = (string) ($item['ledger_status'] ?? 'projected');
            $eventId = (string) ($item['ledger_event_id'] ?? $item['event_id'] ?? '');
            $isRecorded = in_array($status, ['recorded', 'existing'], true);
            $matchesDecision = $sourceKind === 'ap731_operator_decision'
                && (string) ($payload['target_type'] ?? '') === 'new_area_proposal'
                && (
                    (string) ($payload['target_id'] ?? '') === $proposalId
                    || (string) ($payload['target_hash'] ?? '') === $proposalHash
                )
                && (string) ($payload['decision'] ?? '') === 'accept';
            $matchesSelfExpanding = $sourceKind === 'ap738_self_expanding_report';

            if (! $matchesDecision && ! $matchesSelfExpanding) {
                continue;
            }

            $ref = ($isRecorded ? 'ledger:' : 'projected:').$eventId;
            $refs[] = $ref;
            if ($isRecorded) {
                $recordedRefs[] = $ref;
                $hasDecisionEvidence = $hasDecisionEvidence || $matchesDecision;
                $hasSelfExpandingEvidence = $hasSelfExpandingEvidence || $matchesSelfExpanding;
            }
        }

        return [
            'schema_version' => 'atlas.software_company.domain_handoff_evidence_gate.v1',
            'recorded_ready' => $hasDecisionEvidence && $hasSelfExpandingEvidence,
            'has_recorded_operator_accept_decision' => $hasDecisionEvidence,
            'has_recorded_self_expanding_report' => $hasSelfExpandingEvidence,
            'evidence_refs' => StewardshipStringListNormalizer::uniqueStrings($refs),
            'recorded_evidence_refs' => StewardshipStringListNormalizer::uniqueStrings($recordedRefs),
            'source_bridge_status' => (string) ($outcomes['status'] ?? 'unknown'),
            'source_bridge_hash' => (string) ($outcomes['bridge_hash'] ?? ''),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function domainRegistryCheck(string $candidate): array
    {
        if (! Schema::hasTable('ai_domain_manifests')) {
            return [
                'status' => 'not_checked_missing_ai_domain_manifests_table',
                'exists' => false,
                'domain_id' => $candidate,
            ];
        }

        $manifest = $this->domainRegistry->findByDomainId($candidate);

        return [
            'status' => $manifest instanceof AiDomainManifest ? 'exists' : 'available',
            'exists' => $manifest instanceof AiDomainManifest,
            'domain_id' => $candidate,
            'manifest_status' => $manifest?->status,
            'manifest_hash' => $manifest?->manifest_hash,
        ];
    }

    /**
     * @param  array<string,mixed>  $packet
     * @return array<string,mixed>
     */
    private function recordPacket(string $areaId, array $packet): array
    {
        $path = $this->packetFilePath($areaId);
        $existing = $this->findPacket($path, (string) ($packet['handoff_packet_id'] ?? ''));
        if ($existing !== null) {
            return array_merge($existing, ['handoff_storage_status' => 'existing']);
        }

        $record = array_merge($packet, [
            'handoff_storage_status' => 'recorded',
            'recorded_at' => $this->now(),
        ]);

        AppendOnlyJsonlStore::append($path, $record);

        return $record;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findPacket(string $path, string $packetId): ?array
    {
        if (! is_file($path) || $packetId === '') {
            return null;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded) && (string) ($decoded['handoff_packet_id'] ?? '') === $packetId) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function nextActions(string $status, int $ready, int $blocked): array
    {
        if ($status === self::STATUS_NO_READY_PROPOSALS) {
            return [
                'collect_or_accept_an_AP737_new_area_proposal_first',
                'run_AP740_outcome_evidence_after_operator_acceptance',
            ];
        }

        if ($ready > 0) {
            return [
                'submit_recorded_packet_to_Atlas_Domain_Runtime_Creation_Gate_review',
                'assign_operator_and_architect_reviewers',
                'prepare_sandbox_shadow_and_replay_plan_without_creating_domain',
            ];
        }

        return [
            'resolve_packet_blockers_before_domain_runtime_creation_gate_handoff',
            $blocked > 0 ? 'record_AP740_evidence_or_fix_AP737_blockers' : 'review_handoff_report',
        ];
    }

    /**
     * @return array<string,bool|string>
     */
    private function claimPolicy(bool $recordHandoff): array
    {
        return [
            'records_handoff_packet_when_requested' => $recordHandoff,
            'persistence' => 'jsonl_append_only',
            'read_only_over_repo' => true,
            'mutates_target_repo' => false,
            'provider_invoked' => false,
            'dev_invoked' => false,
            'forge_invoked' => false,
            'opens_branch' => false,
            'creates_domain_runtime' => false,
            'registers_domain_manifest' => false,
            'creates_department' => false,
            'creates_new_os' => false,
            'parallel_runtime_created' => false,
            'merges' => false,
            'deploys' => false,
            'touches_secrets' => false,
            'auto_promotion' => false,
            'operator_review_required' => true,
            'domain_runtime_creation_gate_required' => true,
        ];
    }

    /**
     * @return array<string,bool|string>
     */
    private function packetClaimPolicy(): array
    {
        return [
            'handoff_packet_only' => true,
            'proposal_only_until_domain_creation_gate_promotes' => true,
            'creates_domain_runtime' => false,
            'registers_domain_manifest' => false,
            'creates_department' => false,
            'auto_promotion' => false,
            'operator_review_required' => true,
            'dual_signature_required' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function finalize(array $payload): array
    {
        $hashPayload = $payload;
        unset($hashPayload['generated_at'], $hashPayload['handoff_hash']);

        $payload['handoff_hash'] = 'sha256:'.MissionCanonicalHash::sha256($hashPayload);
        $payload['generated_at'] = $this->now();

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $packet
     * @return array<string,mixed>
     */
    private function stablePacket(array $packet): array
    {
        unset($packet['packet_hash'], $packet['recorded_at'], $packet['handoff_storage_status']);
        ksort($packet);

        return $packet;
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function areaId(array $input): string
    {
        $value = trim((string) ($input['area_id'] ?? $input['area'] ?? ''));

        return $value !== '' ? $this->slug($value) : 'agentic_engineering_os';
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function portfolioId(array $input): string
    {
        $value = trim((string) ($input['portfolio_id'] ?? $input['portfolio'] ?? ''));

        return $value !== '' ? $this->slug($value) : PortfolioStewardshipHealthModelService::DEFAULT_PORTFOLIO_ID;
    }

    private function slug(string $value): string
    {
        $slug = preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim($value))) ?? '';
        $slug = trim($slug, '_');

        return $slug !== '' ? $slug : 'unknown';
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }
}
