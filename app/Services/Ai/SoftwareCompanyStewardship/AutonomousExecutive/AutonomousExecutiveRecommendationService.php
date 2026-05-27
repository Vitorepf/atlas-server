<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AutonomousExecutive;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompanyStewardship\PortfolioStewardship\PortfolioStewardshipHealthModelService;
use App\Services\Ai\SoftwareCompanyStewardship\PortfolioStewardship\PortfolioStewardshipInboxService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipEvolutionDecisionLedgerService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipEvolutionReadModelService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

/**
 * Autonomous Executive · recommendation pack (AP-735).
 *
 * Converts AP-734 Portfolio Steward Inbox items into executive recommendations
 * for strategy, capacity and risk tradeoffs. It is proposal/review
 * infrastructure only: no provider calls, no branch/worktree, no Dev/Forge
 * dispatch, no merge/deploy/secrets and no autonomous approval.
 */
class AutonomousExecutiveRecommendationService
{
    public const PACK_SCHEMA = 'atlas.autonomous_executive.recommendation_pack.v1';

    public const RECOMMENDATION_SCHEMA = 'atlas.executive.recommendation.v1';

    public const LEDGER_SCHEMA = 'atlas.autonomous_executive.recommendation_ledger.v1';

    public const STATUS_READY_FOR_OPERATOR_REVIEW = 'ready_for_operator_review';

    public const STATUS_BLOCKED = 'blocked';

    private ?string $storageRootOverride = null;

    public function __construct(
        private readonly PortfolioStewardshipInboxService $portfolioInbox,
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
            ? storage_path('atlas/software_company_stewardship/executive_recommendations')
            : sys_get_temp_dir().'/atlas/software_company_stewardship/executive_recommendations';
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
        $inbox = $this->portfolioInbox($input);
        $portfolioId = (string) ($inbox['portfolio_id'] ?? $input['portfolio_id'] ?? PortfolioStewardshipHealthModelService::DEFAULT_PORTFOLIO_ID);
        $areaId = (string) ($inbox['area_id'] ?? $input['area_id'] ?? StewardshipEvolutionReadModelService::DEFAULT_AREA_ID);

        if ($inbox === null || ($inbox['status'] ?? '') === PortfolioStewardshipInboxService::STATUS_BLOCKED) {
            return $this->finalize([
                'schema_version' => self::PACK_SCHEMA,
                'status' => self::STATUS_BLOCKED,
                'reason' => 'portfolio_inbox_not_ready',
                'ap_contract' => 'AP-735',
                'portfolio_id' => $portfolioId,
                'area_id' => $areaId,
                'recommendation_count' => 0,
                'recommendations' => [],
                'blockers' => ['AP-734 Portfolio Steward Inbox is blocked or missing.'],
                'claim_policy' => $this->claimPolicy(false),
            ]);
        }

        $items = array_values(array_filter((array) ($inbox['items'] ?? []), 'is_array'));
        usort($items, static function (array $a, array $b): int {
            $riskOrder = ['critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1];
            $riskA = $riskOrder[(string) ($a['risk_level'] ?? 'medium')] ?? 2;
            $riskB = $riskOrder[(string) ($b['risk_level'] ?? 'medium')] ?? 2;
            $priorityA = (int) ($a['priority_score'] ?? 0);
            $priorityB = (int) ($b['priority_score'] ?? 0);

            return [$riskB, $priorityB] <=> [$riskA, $priorityA];
        });

        $decisions = is_array($input['decision_ledger'] ?? null)
            ? $input['decision_ledger']
            : $this->decisionLedger->listDecisions($areaId);

        $recommendations = [];
        foreach (array_slice($items, 0, max(1, (int) ($input['max_recommendations'] ?? 5))) as $item) {
            $recommendations[] = $this->recommendation($item, $inbox, $decisions);
        }

        return $this->finalize([
            'schema_version' => self::PACK_SCHEMA,
            'status' => self::STATUS_READY_FOR_OPERATOR_REVIEW,
            'ap_contract' => 'AP-735',
            'portfolio_id' => $portfolioId,
            'area_id' => $areaId,
            'source_ap_contracts' => $this->sourceApContracts($inbox),
            'source_inbox_id' => (string) ($inbox['inbox_id'] ?? ''),
            'source_inbox_hash' => (string) ($inbox['inbox_hash'] ?? ''),
            'source_health_hash' => (string) ($inbox['source_health_hash'] ?? ''),
            'recommendation_count' => count($recommendations),
            'recommendations' => $recommendations,
            'portfolio_decision_context' => $this->portfolioDecisionContext($decisions),
            'executive_policy' => $this->executivePolicy(),
            'operator_inbox' => [
                'target_type' => 'autonomous_executive',
                'decision_options' => ['accept', 'reject', 'defer', 'request_changes'],
                'acceptance_effect' => 'unlocks_next_governed_owner_step_only',
                'autoapproval_allowed' => false,
                'autoimplementation_allowed' => false,
            ],
            'reused_owners' => $this->reusedOwners(),
            'claim_policy' => $this->claimPolicy(false),
        ]);
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function record(array $input = []): array
    {
        $pack = $this->project($input);
        if (($pack['status'] ?? '') === self::STATUS_BLOCKED) {
            return $pack;
        }

        $portfolioId = (string) ($pack['portfolio_id'] ?? PortfolioStewardshipHealthModelService::DEFAULT_PORTFOLIO_ID);
        $packId = $this->packId($pack);
        $existing = $this->findInFile($this->ledgerFilePath($portfolioId), $packId);
        if ($existing !== null) {
            return $existing;
        }

        $record = $pack + [
            'ledger_schema_version' => self::LEDGER_SCHEMA,
            'pack_id' => $packId,
            'recorded_at' => $this->now(),
            'claim_policy' => $this->claimPolicy(true),
        ];

        $this->appendJsonl($this->ledgerFilePath($portfolioId), $record);

        return $record;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function replay(string $packId): ?array
    {
        foreach ($this->portfolioFiles() as $file) {
            $found = $this->findInFile($file, $packId);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    public function listPacks(?string $portfolioId = null): array
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
                'pack_id' => (string) ($record['pack_id'] ?? ''),
                'portfolio_id' => (string) ($record['portfolio_id'] ?? ''),
                'area_id' => (string) ($record['area_id'] ?? ''),
                'status' => (string) ($record['status'] ?? ''),
                'recommendation_count' => (int) ($record['recommendation_count'] ?? 0),
                'source_inbox_id' => (string) ($record['source_inbox_id'] ?? ''),
                'recorded_at' => (string) ($record['recorded_at'] ?? ''),
                'pack_hash' => (string) ($record['pack_hash'] ?? ''),
            ];
        }

        usort($summaries, static fn (array $a, array $b): int => strcmp((string) $a['recorded_at'], (string) $b['recorded_at']));

        return [
            'schema_version' => self::LEDGER_SCHEMA,
            'ap_contract' => 'AP-735',
            'portfolio_id' => $portfolioId,
            'pack_count' => count($summaries),
            'corrupted_line_count' => $corrupted,
            'packs' => $summaries,
            'claim_policy' => $this->claimPolicy(true),
        ];
    }

    /**
     * Record an explicit operator decision for an executive recommendation.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function decide(array $input): array
    {
        $anchor = $this->decisionAnchor($input);

        return $this->decisionLedger->record([
            'area_id' => $anchor['area_id'],
            'portfolio_id' => $anchor['portfolio_id'],
            'target_type' => 'autonomous_executive',
            'target_id' => $anchor['target_id'],
            'target_hash' => $anchor['target_hash'],
            'target_payload' => $anchor['target_payload'],
            'operator_actor' => $input['operator_actor'] ?? null,
            'decision' => $input['decision'] ?? null,
            'risk' => $input['risk'] ?? ($anchor['risk_level'] ?? 'medium'),
            'rationale' => $input['rationale'] ?? '',
        ]) + [
            'source_ap_contract' => 'AP-735',
            'source_pack_id' => $anchor['pack_id'],
            'source_recommendation_id' => $anchor['recommendation_id'],
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>|null
     */
    private function portfolioInbox(array $input): ?array
    {
        if (is_array($input['portfolio_inbox'] ?? null)) {
            return $input['portfolio_inbox'];
        }

        $inboxId = trim((string) ($input['inbox_id'] ?? ''));
        if ($inboxId !== '') {
            return $this->portfolioInbox->replay($inboxId);
        }

        return $this->portfolioInbox->project($this->sanitizedPortfolioInput($input));
    }

    /**
     * @param  array<string,mixed>  $item
     * @param  array<string,mixed>  $inbox
     * @param  array<string,mixed>  $decisions
     * @return array<string,mixed>
     */
    private function recommendation(array $item, array $inbox, array $decisions): array
    {
        $portfolioId = (string) ($item['portfolio_id'] ?? $inbox['portfolio_id'] ?? PortfolioStewardshipHealthModelService::DEFAULT_PORTFOLIO_ID);
        $areaId = (string) ($item['area_id'] ?? $inbox['area_id'] ?? StewardshipEvolutionReadModelService::DEFAULT_AREA_ID);
        $targetArea = (string) ($item['target_area'] ?? 'unknown_area');
        $risk = (string) ($item['risk_level'] ?? 'medium');
        $priority = (int) ($item['priority_score'] ?? 0);
        $regret = $this->regretAnalysis($risk, $priority);
        $targetPayload = [
            'portfolio_id' => $portfolioId,
            'area_id' => $areaId,
            'source_portfolio_inbox_item' => $item,
            'source_inbox_id' => (string) ($inbox['inbox_id'] ?? ''),
            'source_inbox_hash' => (string) ($inbox['inbox_hash'] ?? ''),
            'source_health_hash' => (string) ($inbox['source_health_hash'] ?? ''),
            'decision_context' => $this->portfolioDecisionContext($decisions),
        ];
        $targetHash = 'sha256:'.MissionCanonicalHash::sha256($targetPayload);
        $recommendationId = 'exec_'.substr(MissionCanonicalHash::sha256([$portfolioId, $targetArea, $targetHash]), 0, 16);

        return [
            'schema_version' => self::RECOMMENDATION_SCHEMA,
            'recommendation_id' => $recommendationId,
            'target_type' => 'autonomous_executive',
            'target_id' => 'executive_allocation_'.$this->slug($targetArea),
            'target_hash' => $targetHash,
            'target_payload' => $targetPayload,
            'portfolio_id' => $portfolioId,
            'area_id' => $areaId,
            'source_portfolio_item_id' => (string) ($item['item_id'] ?? ''),
            'source_portfolio_target_id' => (string) ($item['target_id'] ?? ''),
            'source_portfolio_target_hash' => (string) ($item['target_hash'] ?? ''),
            'source_inbox_id' => (string) ($inbox['inbox_id'] ?? ''),
            'source_inbox_hash' => (string) ($inbox['inbox_hash'] ?? ''),
            'recommended_action' => (string) ($item['recommended_action'] ?? 'allocate_next_governed_cycle'),
            'target_area' => $targetArea,
            'executive_summary' => 'Allocate the next governed stewardship cycle to '.$targetArea.'.',
            'expected_unlock' => $this->expectedUnlock($targetArea),
            'capacity_allocation' => $this->capacityAllocation($risk, $priority),
            'budget_policy' => $this->budgetPolicy($risk, $priority),
            'regret_analysis' => $regret,
            'risk_analysis' => [
                'risk_level' => $risk,
                'priority_score' => $priority,
                'primary_risk' => (string) ($item['rationale'] ?? 'portfolio_rebalance_risk'),
                'controls' => ['operator_decision_required', 'evidence_pack_required', 'no_irreversible_action_without_receipt'],
            ],
            'decision_inbox' => [
                'destination' => 'morning_inbox',
                'decision_options' => ['accept', 'reject', 'defer', 'request_changes'],
                'operator_review_required' => true,
                'irreversible_action_allowed' => false,
                'acceptance_effect' => 'authorizes_planning_or_handoff_only',
            ],
            'evidence_refs' => array_values((array) ($item['evidence_refs'] ?? [])),
            'autoapproval_allowed' => false,
            'autoimplementation_allowed' => false,
            'external_side_effect_allowed' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $decisions
     * @return array<string,mixed>
     */
    private function portfolioDecisionContext(array $decisions): array
    {
        $accepted = 0;
        $deferred = 0;
        $latest = null;
        foreach ((array) ($decisions['decisions'] ?? []) as $decision) {
            if (! is_array($decision) || (string) ($decision['target_type'] ?? '') !== 'portfolio_stewardship') {
                continue;
            }

            $latest = (string) ($decision['decision_id'] ?? '');
            if (($decision['decision'] ?? '') === 'accept') {
                $accepted++;
            }
            if (($decision['decision'] ?? '') === 'defer') {
                $deferred++;
            }
        }

        return [
            'source' => 'AP-731',
            'portfolio_accept_count' => $accepted,
            'portfolio_defer_count' => $deferred,
            'latest_portfolio_decision_id' => $latest,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function decisionAnchor(array $input): array
    {
        $targetId = trim((string) ($input['target_id'] ?? ''));
        $targetHash = trim((string) ($input['target_hash'] ?? ''));
        $recommendationId = trim((string) ($input['recommendation_id'] ?? ''));

        if ($targetId !== '' && $targetHash !== '') {
            return [
                'portfolio_id' => $this->portfolioId($input['portfolio_id'] ?? PortfolioStewardshipHealthModelService::DEFAULT_PORTFOLIO_ID),
                'area_id' => $this->areaId($input['area_id'] ?? StewardshipEvolutionReadModelService::DEFAULT_AREA_ID),
                'pack_id' => trim((string) ($input['pack_id'] ?? '')) ?: null,
                'recommendation_id' => $recommendationId !== '' ? $recommendationId : null,
                'target_id' => $targetId,
                'target_hash' => $targetHash,
                'target_payload' => is_array($input['target_payload'] ?? null) ? $input['target_payload'] : null,
                'risk_level' => (string) ($input['risk'] ?? 'medium'),
            ];
        }

        if ($recommendationId === '') {
            throw new InvalidArgumentException('executive_recommendation_required: --recommendation-id or target anchors are required.');
        }

        $packId = trim((string) ($input['pack_id'] ?? ''));
        $pack = $packId !== ''
            ? $this->replay($packId)
            : $this->project($input);
        if ($pack === null) {
            throw new InvalidArgumentException('executive_recommendation_pack_not_found: pack_id was not found in the AP-735 ledger.');
        }

        foreach ((array) ($pack['recommendations'] ?? []) as $recommendation) {
            if (! is_array($recommendation) || (string) ($recommendation['recommendation_id'] ?? '') !== $recommendationId) {
                continue;
            }

            return [
                'portfolio_id' => (string) ($recommendation['portfolio_id'] ?? $pack['portfolio_id'] ?? PortfolioStewardshipHealthModelService::DEFAULT_PORTFOLIO_ID),
                'area_id' => (string) ($recommendation['area_id'] ?? $pack['area_id'] ?? StewardshipEvolutionReadModelService::DEFAULT_AREA_ID),
                'pack_id' => $packId !== '' ? $packId : (string) ($pack['pack_id'] ?? ''),
                'recommendation_id' => $recommendationId,
                'target_id' => (string) ($recommendation['target_id'] ?? ''),
                'target_hash' => (string) ($recommendation['target_hash'] ?? ''),
                'target_payload' => is_array($recommendation['target_payload'] ?? null) ? $recommendation['target_payload'] : null,
                'risk_level' => (string) data_get($recommendation, 'risk_analysis.risk_level', 'medium'),
            ];
        }

        throw new InvalidArgumentException('executive_recommendation_not_found: recommendation_id was not found in the projected pack.');
    }

    /**
     * @return array<string,mixed>
     */
    private function capacityAllocation(string $risk, int $priority): array
    {
        return [
            'claude_slots' => $risk === 'critical' ? 3 : 2,
            'codex_slots' => 1,
            'forge_slots' => $priority >= 60 ? 1 : 0,
            'night_shift_cycles' => 1,
            'continuous_loop_watch' => true,
            'wip_action' => $risk === 'critical' ? 'pause_lower_priority_work' : 'keep_wip_limit',
            'operator_review_required' => true,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function budgetPolicy(string $risk, int $priority): array
    {
        $ceiling = match ($risk) {
            'critical' => 5,
            'high' => 3,
            'medium' => 2,
            default => 1,
        };

        return [
            'budget_ceiling_units' => max($ceiling, (int) ceil($priority / 25)),
            'spend_requires_operator_acceptance' => true,
            'no_external_spend_without_receipt' => true,
            'budget_source' => 'operator_approved_stewardship_budget',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function regretAnalysis(string $risk, int $priority): array
    {
        $riskWeight = ['critical' => 40, 'high' => 28, 'medium' => 16, 'low' => 8][$risk] ?? 16;
        $regretIfDefer = max(0, min(100, $riskWeight + (int) round($priority * 0.6)));

        return [
            'time_horizon_days' => 14,
            'regret_if_defer_score' => $regretIfDefer,
            'regret_if_accept_score' => max(0, 100 - $regretIfDefer),
            'recommendation_confidence' => $regretIfDefer >= 70 ? 'high' : ($regretIfDefer >= 45 ? 'medium' : 'low'),
            'primary_uncertainty' => 'outcome evidence is still operator-reviewed, not autonomous truth',
        ];
    }

    private function expectedUnlock(string $targetArea): string
    {
        return match ($targetArea) {
            'atlas_dev' => 'faster safe local implementation and stronger scope control',
            'atlas_forge' => 'stronger long-horizon multi-agent execution throughput',
            'self_directed_evolution' => 'more autonomous spec generation under review',
            'agentic_engineering_os' => 'higher quality across the Atlas software company development flow',
            'evidence' => 'stronger proof, replay and trust for stewardship decisions',
            default => 'higher portfolio health and lower operational drag',
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function executivePolicy(): array
    {
        return [
            'layer' => 'Autonomous Executive Layer',
            'mode' => 'recommendation_only',
            'strategy_allowed' => true,
            'capacity_allocation_allowed' => true,
            'irreversible_action_allowed' => false,
            'operator_receipt_required' => true,
            'no_auto_ceo_claim' => true,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function reusedOwners(): array
    {
        return [
            'portfolio_inbox' => [
                'owner_service' => PortfolioStewardshipInboxService::class,
                'ap_contract' => 'AP-734',
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
    private function claimPolicy(bool $writesLocalState): array
    {
        return [
            'read_only_over_repo' => true,
            'executive_recommendation_only' => true,
            'writes_local_state' => $writesLocalState,
            'local_state_kind' => $writesLocalState ? 'jsonl_append_only_executive_recommendations' : 'none',
            'mutates_target_repo' => false,
            'provider_invoked' => false,
            'dev_invoked' => false,
            'forge_invoked' => false,
            'opens_branch' => false,
            'merges' => false,
            'deploys' => false,
            'touches_secrets' => false,
            'secrets_in_payload' => false,
            'autoapproval_allowed' => false,
            'autoimplementation_allowed' => false,
            'auto_promotion' => false,
            'operator_review_required' => true,
            'is_new_os' => false,
            'parallel_runtime_created' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function sanitizedPortfolioInput(array $input): array
    {
        $allowed = [
            'area_id',
            'portfolio_id',
            'snapshot_id',
            'health_report',
            'areas',
            'evolution_report',
            'decision_ledger',
            'area_readiness',
            'release_portfolio_feed',
            'release_outcome_bridge',
            'owner_runtime_result_portfolio_feed',
            'owner_runtime_result_bridge',
        ];
        $out = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $input)) {
                $out[$key] = $input[$key];
            }
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $inbox
     * @return list<string>
     */
    private function sourceApContracts(array $inbox): array
    {
        $contracts = ['AP-730', 'AP-731', 'AP-733', 'AP-734'];
        foreach ((array) ($inbox['source_ap_contracts'] ?? []) as $contract) {
            $contract = trim((string) $contract);
            if ($contract !== '') {
                $contracts[] = $contract;
            }
        }

        return array_values(array_unique($contracts));
    }

    private function packId(array $pack): string
    {
        return 'aer_'.substr(MissionCanonicalHash::sha256($this->stableIdentity($pack)), 0, 16);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function finalize(array $payload): array
    {
        $payload['pack_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->stableIdentity($payload));
        $payload['generated_at'] = $this->now();

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function stableIdentity(array $payload): array
    {
        unset($payload['generated_at'], $payload['pack_hash'], $payload['recorded_at'], $payload['pack_id'], $payload['ledger_schema_version']);

        return $payload;
    }

    private function appendJsonl(string $path, array $record): void
    {
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, json_encode($record, JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findInFile(string $path, string $packId): ?array
    {
        [$records] = $this->readRecords($path);
        foreach ($records as $record) {
            if ((string) ($record['pack_id'] ?? '') === $packId) {
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
            if (is_array($decoded) && isset($decoded['pack_id']) && is_string($decoded['pack_id'])) {
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

    private function portfolioId(mixed $value): string
    {
        $id = preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim((string) $value))) ?: '';

        return $id === '' ? PortfolioStewardshipHealthModelService::DEFAULT_PORTFOLIO_ID : $id;
    }

    private function areaId(mixed $value): string
    {
        $id = preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim((string) $value))) ?: '';

        return $id === '' ? StewardshipEvolutionReadModelService::DEFAULT_AREA_ID : $id;
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
