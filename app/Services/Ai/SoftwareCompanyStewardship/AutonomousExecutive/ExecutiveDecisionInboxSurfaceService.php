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

/**
 * Autonomous Executive · decision inbox surface (AP-736).
 *
 * Read-only surface over AP-735 recommendation packs and AP-731 receipts. It
 * shows what the operator can review, which recommendations already have a
 * decision, and which command anchors should be used. It never records a
 * decision, executes work, allocates budget or dispatches Dev/Forge.
 */
class ExecutiveDecisionInboxSurfaceService
{
    public const SURFACE_SCHEMA = 'atlas.autonomous_executive.decision_inbox_surface.v1';

    public const ITEM_SCHEMA = 'atlas.autonomous_executive.decision_inbox_item.v1';

    public const STATUS_READY = 'ready';

    public const STATUS_BLOCKED = 'blocked';

    public function __construct(
        private readonly AutonomousExecutiveRecommendationService $recommendations,
        private readonly StewardshipEvolutionDecisionLedgerService $decisionLedger,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function project(string $portfolioId = PortfolioStewardshipHealthModelService::DEFAULT_PORTFOLIO_ID, array $input = []): array
    {
        $pack = $this->recommendationPack($portfolioId, $input);
        $areaId = (string) ($pack['area_id'] ?? $input['area_id'] ?? StewardshipEvolutionReadModelService::DEFAULT_AREA_ID);

        if ($pack === null || ($pack['status'] ?? '') === AutonomousExecutiveRecommendationService::STATUS_BLOCKED) {
            return $this->finalize([
                'schema_version' => self::SURFACE_SCHEMA,
                'status' => self::STATUS_BLOCKED,
                'reason' => 'executive_recommendation_pack_not_ready',
                'ap_contract' => 'AP-736',
                'portfolio_id' => $this->portfolioId($portfolioId),
                'area_id' => $areaId,
                'read_only' => true,
                'item_count' => 0,
                'items' => [],
                'blockers' => ['AP-735 recommendation pack is blocked or missing.'],
                'claim_policy' => $this->claimPolicy(),
            ]);
        }

        $decisions = is_array($input['decision_ledger'] ?? null)
            ? $input['decision_ledger']
            : $this->decisionLedger->listDecisions($areaId);

        $items = [];
        foreach ((array) ($pack['recommendations'] ?? []) as $recommendation) {
            if (is_array($recommendation)) {
                $items[] = $this->item($recommendation, $pack, $decisions);
            }
        }

        return $this->finalize([
            'schema_version' => self::SURFACE_SCHEMA,
            'status' => self::STATUS_READY,
            'ap_contract' => 'AP-736',
            'portfolio_id' => (string) ($pack['portfolio_id'] ?? $this->portfolioId($portfolioId)),
            'area_id' => $areaId,
            'read_only' => true,
            'source_ap_contracts' => ['AP-731', 'AP-735'],
            'source_pack_id' => (string) ($pack['pack_id'] ?? ''),
            'source_pack_hash' => (string) ($pack['pack_hash'] ?? ''),
            'item_count' => count($items),
            'decision_summary' => $this->decisionSummary($items),
            'items' => $items,
            'operator_controls' => [
                'decision_options' => ['accept', 'reject', 'defer', 'request_changes'],
                'stable_anchor_rule' => 'prefer pack_id + recommendation_id',
                'decision_command' => 'php artisan atlas:software-company-stewardship executive-recommendation-decision --pack-id=<pack> --recommendation-id=<recommendation> --actor=<operator> --decision=<decision> --rationale=<why>',
                'irreversible_action_allowed' => false,
            ],
            'reused_owners' => $this->reusedOwners(),
            'claim_policy' => $this->claimPolicy(),
        ]);
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>|null
     */
    private function recommendationPack(string $portfolioId, array $input): ?array
    {
        if (is_array($input['executive_pack'] ?? null)) {
            return $input['executive_pack'];
        }

        $packId = trim((string) ($input['pack_id'] ?? ''));
        if ($packId !== '') {
            return $this->recommendations->replay($packId);
        }

        return $this->recommendations->project($input + ['portfolio_id' => $portfolioId]);
    }

    /**
     * @param  array<string,mixed>  $recommendation
     * @param  array<string,mixed>  $pack
     * @param  array<string,mixed>  $decisions
     * @return array<string,mixed>
     */
    private function item(array $recommendation, array $pack, array $decisions): array
    {
        $targetId = (string) ($recommendation['target_id'] ?? '');
        $targetHash = (string) ($recommendation['target_hash'] ?? '');
        $latest = $this->latestDecision($targetId, $targetHash, $decisions);
        $decision = (string) ($latest['decision'] ?? '');
        $status = match ($decision) {
            'accept' => 'accepted_no_execution',
            'reject' => 'rejected',
            'defer' => 'deferred',
            'request_changes' => 'changes_requested',
            default => 'pending_operator_review',
        };

        return [
            'schema_version' => self::ITEM_SCHEMA,
            'inbox_item_id' => 'edi_'.substr(MissionCanonicalHash::sha256([$pack['pack_id'] ?? '', $recommendation['recommendation_id'] ?? '', $targetHash]), 0, 16),
            'portfolio_id' => (string) ($recommendation['portfolio_id'] ?? $pack['portfolio_id'] ?? ''),
            'area_id' => (string) ($recommendation['area_id'] ?? $pack['area_id'] ?? ''),
            'source_pack_id' => (string) ($pack['pack_id'] ?? ''),
            'source_recommendation_id' => (string) ($recommendation['recommendation_id'] ?? ''),
            'target_type' => 'autonomous_executive',
            'target_id' => $targetId,
            'target_hash' => $targetHash,
            'title' => (string) ($recommendation['executive_summary'] ?? 'Review executive recommendation'),
            'target_area' => (string) ($recommendation['target_area'] ?? ''),
            'recommended_action' => (string) ($recommendation['recommended_action'] ?? ''),
            'risk_level' => (string) data_get($recommendation, 'risk_analysis.risk_level', 'medium'),
            'priority_score' => (int) data_get($recommendation, 'risk_analysis.priority_score', 0),
            'regret_if_defer_score' => (int) data_get($recommendation, 'regret_analysis.regret_if_defer_score', 0),
            'status' => $status,
            'decision_state' => [
                'has_decision' => $latest !== null,
                'latest_decision_id' => (string) ($latest['decision_id'] ?? ''),
                'latest_decision' => $decision,
                'latest_actor' => (string) ($latest['operator_actor'] ?? ''),
                'latest_recorded_at' => (string) ($latest['recorded_at'] ?? ''),
                'executed' => (bool) ($latest['executed'] ?? false),
            ],
            'operator_actions' => ['accept', 'reject', 'defer', 'request_changes'],
            'stable_decision_anchor' => [
                'pack_id' => (string) ($pack['pack_id'] ?? ''),
                'recommendation_id' => (string) ($recommendation['recommendation_id'] ?? ''),
                'target_id' => $targetId,
                'target_hash' => $targetHash,
            ],
            'policy' => [
                'operator_review_required' => true,
                'autoapproval_allowed' => false,
                'autoimplementation_allowed' => false,
                'irreversible_action_allowed' => false,
                'dev_invoked' => false,
                'forge_invoked' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $decisions
     * @return array<string,mixed>|null
     */
    private function latestDecision(string $targetId, string $targetHash, array $decisions): ?array
    {
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
     * @param  list<array<string,mixed>>  $items
     * @return array<string,int>
     */
    private function decisionSummary(array $items): array
    {
        $summary = [
            'total' => count($items),
            'pending_operator_review' => 0,
            'accepted_no_execution' => 0,
            'deferred' => 0,
            'rejected' => 0,
            'changes_requested' => 0,
        ];

        foreach ($items as $item) {
            $status = (string) ($item['status'] ?? 'pending_operator_review');
            if (array_key_exists($status, $summary)) {
                $summary[$status]++;
            }
        }

        return $summary;
    }

    /**
     * @return array<string,mixed>
     */
    private function reusedOwners(): array
    {
        return [
            'executive_recommendations' => [
                'owner_service' => AutonomousExecutiveRecommendationService::class,
                'ap_contract' => 'AP-735',
            ],
            'operator_decision_ledger' => [
                'owner_service' => StewardshipEvolutionDecisionLedgerService::class,
                'ap_contract' => 'AP-731',
            ],
            'stack_doc' => 'docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md',
            'ladder_doc' => 'docs/engineering-knowledge-base/atlas-stewardship-evolution-ladder.md',
        ];
    }

    /**
     * @return array<string,bool|string>
     */
    private function claimPolicy(): array
    {
        return [
            'read_only_over_repo' => true,
            'surface_only' => true,
            'writes_local_state' => false,
            'mutates_target_repo' => false,
            'provider_invoked' => false,
            'dev_invoked' => false,
            'forge_invoked' => false,
            'opens_branch' => false,
            'merges' => false,
            'deploys' => false,
            'touches_secrets' => false,
            'autoapproval_allowed' => false,
            'autoimplementation_allowed' => false,
            'auto_promotion' => false,
            'operator_review_required' => true,
            'is_new_os' => false,
            'parallel_runtime_created' => false,
        ];
    }

    private function portfolioId(string $value): string
    {
        $id = preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim($value))) ?: '';

        return $id === '' ? PortfolioStewardshipHealthModelService::DEFAULT_PORTFOLIO_ID : $id;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function finalize(array $payload): array
    {
        $payload['surface_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->stableIdentity($payload));
        $payload['generated_at'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function stableIdentity(array $payload): array
    {
        unset($payload['generated_at'], $payload['surface_hash']);

        return $payload;
    }
}
