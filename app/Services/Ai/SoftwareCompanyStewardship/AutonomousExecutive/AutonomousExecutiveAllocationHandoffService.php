<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AutonomousExecutive;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompanyStewardship\PortfolioStewardship\PortfolioStewardshipHealthModelService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipEvolutionDecisionLedgerService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipEvolutionReadModelService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\File;

/**
 * AP-752 · Autonomous Executive allocation handoff.
 *
 * Converts an accepted AP-735 executive recommendation into a governed handoff
 * packet for the next owner. It does not execute the recommendation, invoke
 * Dev/Forge, call providers, create branches/worktrees, merge, deploy or touch
 * secrets.
 */
final class AutonomousExecutiveAllocationHandoffService
{
    public const REPORT_SCHEMA = 'atlas.autonomous_executive.allocation_handoff.v1';

    public const PACKET_SCHEMA = 'atlas.autonomous_executive.allocation_handoff_packet.v1';

    public const LEDGER_SCHEMA = 'atlas.autonomous_executive.allocation_handoff_ledger.v1';

    public const STATUS_READY = 'ready';

    public const STATUS_AWAITING_OPERATOR_ACCEPTANCE = 'awaiting_operator_acceptance';

    public const STATUS_BLOCKED = 'blocked';

    private ?string $storageRootOverride = null;

    public function __construct(
        private readonly AutonomousExecutiveRecommendationService $recommendations,
        private readonly StewardshipEvolutionDecisionLedgerService $decisionLedger,
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
            ? storage_path('atlas/software_company_stewardship/executive_allocation_handoffs')
            : sys_get_temp_dir().'/atlas/software_company_stewardship/executive_allocation_handoffs';
    }

    public function ledgerFilePath(string $portfolioId): string
    {
        return $this->storageDir().DIRECTORY_SEPARATOR.$this->slug($portfolioId).'.jsonl';
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function project(array $input = []): array
    {
        $recordHandoff = (bool) ($input['record_allocation_handoff'] ?? false);
        $pack = $this->executivePack($input);
        $portfolioId = (string) ($pack['portfolio_id'] ?? $input['portfolio_id'] ?? PortfolioStewardshipHealthModelService::DEFAULT_PORTFOLIO_ID);
        $areaId = (string) ($pack['area_id'] ?? $input['area_id'] ?? StewardshipEvolutionReadModelService::DEFAULT_AREA_ID);

        if ($pack === null || ($pack['status'] ?? '') === AutonomousExecutiveRecommendationService::STATUS_BLOCKED) {
            return $this->finalize([
                'schema_version' => self::REPORT_SCHEMA,
                'status' => self::STATUS_BLOCKED,
                'reason' => 'executive_recommendation_pack_not_ready',
                'ap_contract' => 'AP-752',
                'portfolio_id' => $portfolioId,
                'area_id' => $areaId,
                'source_ap_contracts' => ['AP-731', 'AP-735', 'AP-752'],
                'allocation_handoff_count' => 0,
                'allocation_handoff_packets' => [],
                'blockers' => ['AP-735 recommendation pack is blocked or missing.'],
                'next_actions' => ['Repair or record an AP-735 recommendation pack before preparing allocation handoff.'],
                'claim_policy' => $this->claimPolicy($recordHandoff),
            ]);
        }

        [$recommendation, $recommendationBlocker] = $this->selectedRecommendation($pack, $input);
        if ($recommendation === null) {
            return $this->finalize([
                'schema_version' => self::REPORT_SCHEMA,
                'status' => self::STATUS_BLOCKED,
                'reason' => 'executive_recommendation_not_selected',
                'ap_contract' => 'AP-752',
                'portfolio_id' => $portfolioId,
                'area_id' => $areaId,
                'source_ap_contracts' => $this->sourceApContracts($pack),
                'source_pack_id' => (string) ($pack['pack_id'] ?? ''),
                'source_pack_hash' => (string) ($pack['pack_hash'] ?? ''),
                'allocation_handoff_count' => 0,
                'allocation_handoff_packets' => [],
                'blockers' => [$recommendationBlocker],
                'next_actions' => ['Select exactly one AP-735 recommendation through recommendation_id or a single-recommendation pack.'],
                'claim_policy' => $this->claimPolicy($recordHandoff),
            ]);
        }

        $decisions = is_array($input['decision_ledger'] ?? null)
            ? $input['decision_ledger']
            : $this->decisionLedger->listDecisions($areaId);
        $latestDecision = $this->latestExecutiveDecision($recommendation, $decisions);

        if (($latestDecision['decision'] ?? null) !== 'accept') {
            $decision = (string) ($latestDecision['decision'] ?? '');
            $status = $latestDecision === null
                ? self::STATUS_AWAITING_OPERATOR_ACCEPTANCE
                : self::STATUS_BLOCKED;
            $blocker = $latestDecision === null
                ? 'executive_allocation_requires_operator_accept'
                : 'latest_operator_decision_is_'.$decision;

            return $this->finalize([
                'schema_version' => self::REPORT_SCHEMA,
                'status' => $status,
                'reason' => $blocker,
                'ap_contract' => 'AP-752',
                'portfolio_id' => $portfolioId,
                'area_id' => $areaId,
                'source_ap_contracts' => $this->sourceApContracts($pack),
                'source_pack_id' => (string) ($pack['pack_id'] ?? ''),
                'source_pack_hash' => (string) ($pack['pack_hash'] ?? ''),
                'source_recommendation_id' => (string) ($recommendation['recommendation_id'] ?? ''),
                'target_id' => (string) ($recommendation['target_id'] ?? ''),
                'target_hash' => (string) ($recommendation['target_hash'] ?? ''),
                'latest_decision' => $latestDecision,
                'allocation_handoff_count' => 0,
                'allocation_handoff_packets' => [],
                'blockers' => [$blocker],
                'next_actions' => ['Record AP-731 accept for this AP-735 recommendation before preparing allocation handoff.'],
                'claim_policy' => $this->claimPolicy($recordHandoff),
            ]);
        }

        $packet = $this->handoffPacket($portfolioId, $areaId, $pack, $recommendation, $latestDecision);
        if ($recordHandoff) {
            $packet = $this->recordPacket($portfolioId, $packet);
        }

        return $this->finalize([
            'schema_version' => self::REPORT_SCHEMA,
            'status' => self::STATUS_READY,
            'ap_contract' => 'AP-752',
            'portfolio_id' => $portfolioId,
            'area_id' => $areaId,
            'stack' => 'Atlas Software Company Stewardship Stack',
            'layer' => 'Autonomous Executive Layer',
            'source_ap_contracts' => $this->sourceApContracts($pack),
            'source_pack_id' => (string) ($pack['pack_id'] ?? ''),
            'source_pack_hash' => (string) ($pack['pack_hash'] ?? ''),
            'source_recommendation_id' => (string) ($recommendation['recommendation_id'] ?? ''),
            'source_decision_id' => (string) ($latestDecision['decision_id'] ?? ''),
            'target_id' => (string) ($recommendation['target_id'] ?? ''),
            'target_hash' => (string) ($recommendation['target_hash'] ?? ''),
            'recommended_action' => (string) ($recommendation['recommended_action'] ?? ''),
            'target_area' => (string) ($recommendation['target_area'] ?? ''),
            'record_allocation_handoff_requested' => $recordHandoff,
            'allocation_handoff_count' => 1,
            'allocation_handoff_packets' => [$packet],
            'blockers' => [],
            'next_actions' => $this->nextActions($packet),
            'claim_policy' => $this->claimPolicy($recordHandoff),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function listHandoffs(?string $portfolioId = null): array
    {
        $files = $portfolioId === null || trim($portfolioId) === ''
            ? $this->portfolioFiles()
            : [$this->ledgerFilePath($portfolioId)];

        $records = [];
        $corrupted = 0;
        foreach ($files as $file) {
            [$valid, $bad] = $this->readRecords($file);
            $records = array_merge($records, $valid);
            $corrupted += $bad;
        }

        $summaries = [];
        foreach ($records as $record) {
            $summaries[] = [
                'handoff_packet_id' => (string) ($record['handoff_packet_id'] ?? ''),
                'portfolio_id' => (string) ($record['portfolio_id'] ?? ''),
                'area_id' => (string) ($record['area_id'] ?? ''),
                'target_owner' => (string) ($record['target_owner'] ?? ''),
                'target_area' => (string) ($record['target_area'] ?? ''),
                'recommended_action' => (string) ($record['recommended_action'] ?? ''),
                'handoff_status' => (string) ($record['handoff_status'] ?? ''),
                'recorded_at' => (string) ($record['recorded_at'] ?? ''),
                'packet_hash' => (string) ($record['packet_hash'] ?? ''),
            ];
        }

        usort($summaries, static fn (array $a, array $b): int => strcmp((string) ($a['recorded_at'] ?? ''), (string) ($b['recorded_at'] ?? '')));

        return [
            'schema_version' => self::LEDGER_SCHEMA,
            'ap_contract' => 'AP-752',
            'portfolio_id' => $portfolioId,
            'handoff_count' => count($summaries),
            'corrupted_line_count' => $corrupted,
            'handoffs' => $summaries,
            'claim_policy' => $this->claimPolicy(true),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function replay(string $handoffPacketId): ?array
    {
        foreach ($this->portfolioFiles() as $file) {
            $found = $this->findPacket($file, $handoffPacketId);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>|null
     */
    private function executivePack(array $input): ?array
    {
        if (is_array($input['executive_pack'] ?? null)) {
            return $input['executive_pack'];
        }

        $packId = trim((string) ($input['pack_id'] ?? ''));
        if ($packId !== '') {
            return $this->recommendations->replay($packId);
        }

        return $this->recommendations->project($input);
    }

    /**
     * @param  array<string,mixed>  $pack
     * @param  array<string,mixed>  $input
     * @return array{0:array<string,mixed>|null,1:string}
     */
    private function selectedRecommendation(array $pack, array $input): array
    {
        $recommendations = array_values(array_filter((array) ($pack['recommendations'] ?? []), 'is_array'));
        $recommendationId = trim((string) ($input['recommendation_id'] ?? ''));

        if ($recommendationId === '') {
            if (count($recommendations) === 1) {
                return [$recommendations[0], ''];
            }

            return [null, 'executive_recommendation_required'];
        }

        foreach ($recommendations as $recommendation) {
            if ((string) ($recommendation['recommendation_id'] ?? '') === $recommendationId) {
                return [$recommendation, ''];
            }
        }

        return [null, 'executive_recommendation_not_found'];
    }

    /**
     * @param  array<string,mixed>  $recommendation
     * @param  array<string,mixed>  $decisions
     * @return array<string,mixed>|null
     */
    private function latestExecutiveDecision(array $recommendation, array $decisions): ?array
    {
        $targetId = (string) ($recommendation['target_id'] ?? '');
        $targetHash = (string) ($recommendation['target_hash'] ?? '');
        $matches = [];

        foreach ((array) ($decisions['decisions'] ?? []) as $decision) {
            if (! is_array($decision) || (string) ($decision['target_type'] ?? '') !== 'autonomous_executive') {
                continue;
            }
            if ((string) ($decision['target_id'] ?? '') !== $targetId || (string) ($decision['target_hash'] ?? '') !== $targetHash) {
                continue;
            }
            $matches[] = $decision;
        }

        usort($matches, static fn (array $a, array $b): int => strcmp((string) ($b['recorded_at'] ?? ''), (string) ($a['recorded_at'] ?? '')));

        return $matches[0] ?? null;
    }

    /**
     * @param  array<string,mixed>  $pack
     * @param  array<string,mixed>  $recommendation
     * @param  array<string,mixed>  $decision
     * @return array<string,mixed>
     */
    private function handoffPacket(
        string $portfolioId,
        string $areaId,
        array $pack,
        array $recommendation,
        array $decision,
    ): array {
        $route = $this->routeForRecommendation($recommendation);
        $targetArea = (string) ($recommendation['target_area'] ?? $areaId);
        $recommendedAction = (string) ($recommendation['recommended_action'] ?? 'allocate_next_governed_cycle');
        $targetId = (string) ($recommendation['target_id'] ?? '');
        $targetHash = (string) ($recommendation['target_hash'] ?? '');
        $recommendationId = (string) ($recommendation['recommendation_id'] ?? '');
        $decisionId = (string) ($decision['decision_id'] ?? '');

        $packet = [
            'schema_version' => self::PACKET_SCHEMA,
            'handoff_packet_id' => 'aeah_'.substr(MissionCanonicalHash::sha256([$portfolioId, $areaId, $recommendationId, $targetId, $targetHash, $decisionId]), 0, 22),
            'handoff_status' => 'ready_for_owner_allocation_review',
            'handoff_storage_status' => 'projected',
            'portfolio_id' => $portfolioId,
            'area_id' => $areaId,
            'target_area' => $targetArea,
            'target_owner' => $route['target_owner'],
            'target_owner_doc' => $route['target_owner_doc'],
            'target_owner_contract' => $route['target_owner_contract'],
            'source_pack_id' => (string) ($pack['pack_id'] ?? ''),
            'source_pack_hash' => (string) ($pack['pack_hash'] ?? ''),
            'source_recommendation_id' => $recommendationId,
            'source_decision_id' => $decisionId,
            'source_decision_hash' => (string) ($decision['decision_hash'] ?? ''),
            'target_type' => 'autonomous_executive',
            'target_id' => $targetId,
            'target_hash' => $targetHash,
            'recommended_action' => $recommendedAction,
            'source_ap_contracts' => $this->sourceApContracts($pack),
            'executive_recommendation' => [
                'recommendation_id' => $recommendationId,
                'recommended_action' => $recommendedAction,
                'target_area' => $targetArea,
                'risk_analysis' => is_array($recommendation['risk_analysis'] ?? null) ? $recommendation['risk_analysis'] : [],
                'regret_analysis' => is_array($recommendation['regret_analysis'] ?? null) ? $recommendation['regret_analysis'] : [],
                'capacity_allocation' => is_array($recommendation['capacity_allocation'] ?? null) ? $recommendation['capacity_allocation'] : [],
                'budget_policy' => is_array($recommendation['budget_policy'] ?? null) ? $recommendation['budget_policy'] : [],
                'evidence_refs' => array_values((array) ($recommendation['evidence_refs'] ?? [])),
            ],
            'operator_acceptance' => [
                'source' => 'AP-731',
                'decision_id' => $decisionId,
                'decision' => (string) ($decision['decision'] ?? ''),
                'operator_actor' => (string) ($decision['operator_actor'] ?? ''),
                'recorded_at' => (string) ($decision['recorded_at'] ?? ''),
                'decision_hash' => (string) ($decision['decision_hash'] ?? ''),
            ],
            'required_gate_sequence' => $this->requiredGateSequence($route['route_kind']),
            'allowed_next_actions' => $route['allowed_next_actions'],
            'forbidden_actions' => $this->forbiddenActions(),
            'handoff_boundary' => [
                'handoff_packet_only' => true,
                'target_owner_must_run_own_gate' => true,
                'starts_execution' => false,
                'allocates_budget' => false,
                'creates_branch_or_worktree' => false,
                'invokes_provider' => false,
                'invokes_dev' => false,
                'invokes_forge' => false,
                'mutates_target_repo' => false,
                'operator_review_required_before_execution' => true,
            ],
            'blockers' => [],
            'claim_policy' => $this->packetClaimPolicy(),
        ];

        $packet['packet_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->stablePacket($packet));

        return $packet;
    }

    /**
     * @param  array<string,mixed>  $recommendation
     * @return array<string,mixed>
     */
    private function routeForRecommendation(array $recommendation): array
    {
        return match ((string) ($recommendation['recommended_action'] ?? '')) {
            'review_owner_runtime_result' => [
                'route_kind' => 'owner_runtime_result_review',
                'target_owner' => 'Portfolio/Area Owner Runtime Result Review',
                'target_owner_doc' => 'docs/ap/AP-750-owner-runtime-result-bridge-contract.md',
                'target_owner_contract' => 'AP-750/AP-751',
                'allowed_next_actions' => ['review_owner_runtime_result', 'request_owner_followup', 'defer', 'request_changes'],
            ],
            'route_owner_runtime_followup' => [
                'route_kind' => 'owner_runtime_followup',
                'target_owner' => 'Area Stewardship Owner Runtime Follow-up',
                'target_owner_doc' => 'docs/engineering-knowledge-base/atlas-area-stewardship-layer.md',
                'target_owner_contract' => 'AP-748/AP-749/AP-750/AP-751',
                'allowed_next_actions' => ['prepare_area_followup_review', 'prepare_owner_queue_gate', 'defer', 'request_changes'],
            ],
            'allocate_next_governed_cycle' => [
                'route_kind' => 'area_cycle_allocation',
                'target_owner' => 'Area Stewardship / Area Focus Loop',
                'target_owner_doc' => 'docs/engineering-knowledge-base/atlas-area-stewardship-layer.md',
                'target_owner_contract' => 'AP-743/AP-744/AP-745/AP-746/AP-747',
                'allowed_next_actions' => ['prepare_area_stewardship_review', 'prepare_area_focus_cycle', 'defer', 'request_changes'],
            ],
            default => [
                'route_kind' => 'product_mode_review',
                'target_owner' => 'Night Shift Product Mode Cockpit',
                'target_owner_doc' => 'docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift-product-mode.md',
                'target_owner_contract' => 'AP-739/AP-742',
                'allowed_next_actions' => ['review_in_product_mode', 'defer', 'request_changes'],
            ],
        };
    }

    /**
     * @return list<string>
     */
    private function requiredGateSequence(string $routeKind): array
    {
        $base = [
            'ap735_recommendation_pack_recorded_or_supplied',
            'ap731_operator_accept_receipt_present',
            'ap752_allocation_handoff_packet_review',
            'target_owner_replays_source_anchors',
        ];

        return array_merge($base, match ($routeKind) {
            'owner_runtime_result_review' => [
                'ap750_result_identity_and_evidence_review',
                'ap751_portfolio_signal_review',
                'operator_decides_followup_or_closeout',
            ],
            'owner_runtime_followup' => [
                'area_stewardship_followup_review',
                'ap749_owner_specific_consumption_gate_if_execution_needed',
                'operator_receipt_before_owner_runtime_consumption',
            ],
            'area_cycle_allocation' => [
                'area_stewardship_active_handoff_or_area_focus_cycle_review',
                'ap726_or_ap747_release_gate_if_dev_forge_needed',
                'evidence_and_morning_inbox_outcome_bridge',
            ],
            default => [
                'product_mode_cockpit_operator_review',
                'operator_selects_next_governed_owner',
            ],
        });
    }

    /**
     * @return list<string>
     */
    private function forbiddenActions(): array
    {
        return [
            'auto_execute_recommendation',
            'invoke_provider',
            'invoke_atlas_dev',
            'invoke_forge',
            'create_branch',
            'create_worktree',
            'merge',
            'deploy',
            'touch_secrets',
            'spend_budget',
            'destructive_change',
            'auto_approve',
            'auto_promote',
            'create_parallel_runtime',
        ];
    }

    /**
     * @param  array<string,mixed>  $packet
     * @return list<string>
     */
    private function nextActions(array $packet): array
    {
        return [
            'Review the AP-752 allocation handoff packet before routing any owner work.',
            'Ask the target owner to replay AP-735 and AP-731 anchors before accepting the packet.',
            'Run the target owner gate before any provider, Dev, Forge, branch, merge, deploy, secret or destructive action.',
            'Target owner: '.(string) ($packet['target_owner'] ?? 'unknown'),
        ];
    }

    /**
     * @param  array<string,mixed>  $pack
     * @return list<string>
     */
    private function sourceApContracts(array $pack): array
    {
        $contracts = ['AP-731', 'AP-735', 'AP-752'];
        foreach ((array) ($pack['source_ap_contracts'] ?? []) as $contract) {
            $contract = trim((string) $contract);
            if ($contract !== '') {
                $contracts[] = $contract;
            }
        }

        return array_values(array_unique($contracts));
    }

    /**
     * @param  array<string,mixed>  $packet
     * @return array<string,mixed>
     */
    private function recordPacket(string $portfolioId, array $packet): array
    {
        $path = $this->ledgerFilePath($portfolioId);
        $packetId = (string) ($packet['handoff_packet_id'] ?? '');
        $existing = $this->findPacket($path, $packetId);
        if ($existing !== null) {
            return array_merge($existing, ['handoff_storage_status' => 'existing']);
        }

        $record = array_merge($packet, [
            'ledger_schema_version' => self::LEDGER_SCHEMA,
            'handoff_storage_status' => 'recorded',
            'recorded_at' => $this->now(),
        ]);

        $this->appendJsonl($path, $record);

        return $record;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findPacket(string $path, string $packetId): ?array
    {
        [$records] = $this->readRecords($path);
        foreach ($records as $record) {
            if ((string) ($record['handoff_packet_id'] ?? '') === $packetId) {
                return $record;
            }
        }

        return null;
    }

    /**
     * @return array{0:list<array<string,mixed>>,1:int}
     */
    private function readRecords(string $path): array
    {
        if (! is_file($path)) {
            return [[], 0];
        }

        $records = [];
        $corrupted = 0;
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded) && isset($decoded['handoff_packet_id']) && is_string($decoded['handoff_packet_id'])) {
                $records[] = $decoded;
            } else {
                $corrupted++;
            }
        }

        return [$records, $corrupted];
    }

    /**
     * @return list<string>
     */
    private function portfolioFiles(): array
    {
        $dir = $this->storageDir();
        if (! is_dir($dir)) {
            return [];
        }

        return array_values(array_filter((array) glob($dir.DIRECTORY_SEPARATOR.'*.jsonl'), 'is_string'));
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function appendJsonl(string $path, array $payload): void
    {
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, json_encode($payload, JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /**
     * @return array<string,mixed>
     */
    private function finalize(array $payload): array
    {
        $payload['handoff_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->stableReport($payload));
        $payload['generated_at'] = $this->now();

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function stableReport(array $payload): array
    {
        unset($payload['generated_at'], $payload['handoff_hash']);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $packet
     * @return array<string,mixed>
     */
    private function stablePacket(array $packet): array
    {
        unset($packet['packet_hash'], $packet['handoff_storage_status'], $packet['recorded_at'], $packet['ledger_schema_version']);

        return $packet;
    }

    /**
     * @return array<string,bool|string>
     */
    private function claimPolicy(bool $writesLocalState): array
    {
        return [
            'read_only_over_repo' => true,
            'handoff_gate_only' => true,
            'writes_local_state' => $writesLocalState,
            'local_state_kind' => $writesLocalState ? 'jsonl_append_only_executive_allocation_handoffs' : 'none',
            'mutates_target_repo' => false,
            'provider_invoked' => false,
            'dev_invoked' => false,
            'forge_invoked' => false,
            'opens_branch' => false,
            'opens_worktree' => false,
            'merges' => false,
            'deploys' => false,
            'touches_secrets' => false,
            'secrets_in_payload' => false,
            'spends_budget' => false,
            'autoapproval_allowed' => false,
            'autoimplementation_allowed' => false,
            'auto_promotion' => false,
            'operator_review_required' => true,
            'is_new_os' => false,
            'parallel_runtime_created' => false,
        ];
    }

    /**
     * @return array<string,bool|string>
     */
    private function packetClaimPolicy(): array
    {
        return [
            'handoff_packet_only' => true,
            'read_only_over_repo' => true,
            'mutates_target_repo' => false,
            'provider_invoked' => false,
            'dev_invoked' => false,
            'forge_invoked' => false,
            'branch_created' => false,
            'worktree_created' => false,
            'merges' => false,
            'deploys' => false,
            'touches_secrets' => false,
            'spends_budget' => false,
            'autoapproval_allowed' => false,
            'autoimplementation_allowed' => false,
            'auto_promotion' => false,
            'target_owner_must_run_own_gate' => true,
        ];
    }

    private function slug(string $value): string
    {
        $slug = preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim($value))) ?: '';

        return $slug === '' ? 'default' : $slug;
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }
}
