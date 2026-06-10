<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\PortfolioStewardship;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipStringListNormalizer;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipEvolutionDecisionLedgerService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipEvolutionReadModelService;
use App\Services\Ai\Support\AppendOnlyJsonlStore;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Portfolio Stewardship · operator inbox (AP-734).
 *
 * Projects AP-733 Portfolio Health Model rebalance candidates into
 * operator-reviewable inbox items, optionally records the inbox as append-only
 * JSONL, and records explicit operator decisions through the AP-731 decision
 * ledger. It is not an executor and never mutates target repositories.
 */
class PortfolioStewardshipInboxService
{
    public const INBOX_SCHEMA = 'atlas.portfolio_stewardship.inbox.v1';

    public const ITEM_SCHEMA = 'atlas.portfolio_stewardship.inbox_item.v1';

    public const LEDGER_SCHEMA = 'atlas.portfolio_stewardship.inbox_ledger.v1';

    public const STATUS_READY = 'ready';

    public const STATUS_BLOCKED = 'blocked';

    private ?string $storageRootOverride = null;

    public function __construct(
        private readonly PortfolioStewardshipHealthModelService $healthModel,
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
            ? storage_path('atlas/software_company_stewardship/portfolio_inbox')
            : sys_get_temp_dir().'/atlas/software_company_stewardship/portfolio_inbox';
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
        $health = $this->healthReport($input);
        $portfolioId = (string) ($health['portfolio_id'] ?? $input['portfolio_id'] ?? PortfolioStewardshipHealthModelService::DEFAULT_PORTFOLIO_ID);
        $areaId = (string) ($health['area_id'] ?? $input['area_id'] ?? StewardshipEvolutionReadModelService::DEFAULT_AREA_ID);

        if (($health['status'] ?? '') === PortfolioStewardshipHealthModelService::STATUS_BLOCKED) {
            return $this->finalize([
                'schema_version' => self::INBOX_SCHEMA,
                'status' => self::STATUS_BLOCKED,
                'reason' => 'portfolio_health_not_ready',
                'ap_contract' => 'AP-734',
                'portfolio_id' => $portfolioId,
                'area_id' => $areaId,
                'item_count' => 0,
                'items' => [],
                'blockers' => ['AP-733 portfolio health report is blocked.'],
                'claim_policy' => $this->claimPolicy(false),
            ]);
        }

        $items = [];
        foreach ((array) ($health['rebalance_candidates'] ?? []) as $candidate) {
            if (is_array($candidate)) {
                $items[] = $this->item($candidate, $health);
            }
        }

        return $this->finalize([
            'schema_version' => self::INBOX_SCHEMA,
            'status' => self::STATUS_READY,
            'ap_contract' => 'AP-734',
            'portfolio_id' => $portfolioId,
            'area_id' => $areaId,
            'source_health_hash' => (string) ($health['health_hash'] ?? ''),
            'source_snapshot_id' => (string) ($health['snapshot_id'] ?? ''),
            'source_ap_contracts' => $this->sourceApContracts($health),
            'item_count' => count($items),
            'counts' => $this->counts($items),
            'items' => $items,
            'blockers' => [],
            'decision_ledger' => [
                'schema_version' => StewardshipEvolutionDecisionLedgerService::LEDGER_SCHEMA,
                'decision_target_type' => 'portfolio_stewardship',
                'decision_options' => ['accept', 'reject', 'defer', 'request_changes'],
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
        $inbox = $this->project($input);
        if (($inbox['status'] ?? '') === self::STATUS_BLOCKED) {
            return $inbox;
        }

        $portfolioId = (string) ($inbox['portfolio_id'] ?? PortfolioStewardshipHealthModelService::DEFAULT_PORTFOLIO_ID);
        $inboxId = $this->inboxId($inbox);
        $existing = $this->findInFile($this->ledgerFilePath($portfolioId), $inboxId);
        if ($existing !== null) {
            return $existing;
        }

        $record = $inbox + [
            'ledger_schema_version' => self::LEDGER_SCHEMA,
            'inbox_id' => $inboxId,
            'recorded_at' => $this->now(),
            'claim_policy' => $this->claimPolicy(true),
        ];

        AppendOnlyJsonlStore::append($this->ledgerFilePath($portfolioId), $record);

        return $record;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function replay(string $inboxId): ?array
    {
        foreach ($this->portfolioFiles() as $file) {
            $found = $this->findInFile($file, $inboxId);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    public function listInboxes(?string $portfolioId = null): array
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
                'inbox_id' => (string) ($record['inbox_id'] ?? ''),
                'portfolio_id' => (string) ($record['portfolio_id'] ?? ''),
                'area_id' => (string) ($record['area_id'] ?? ''),
                'status' => (string) ($record['status'] ?? ''),
                'item_count' => (int) ($record['item_count'] ?? 0),
                'source_health_hash' => (string) ($record['source_health_hash'] ?? ''),
                'source_snapshot_id' => (string) ($record['source_snapshot_id'] ?? ''),
                'recorded_at' => (string) ($record['recorded_at'] ?? ''),
                'inbox_hash' => (string) ($record['inbox_hash'] ?? ''),
            ];
        }

        usort($summaries, static fn (array $a, array $b): int => strcmp((string) $a['recorded_at'], (string) $b['recorded_at']));

        return [
            'schema_version' => self::LEDGER_SCHEMA,
            'ap_contract' => 'AP-734',
            'portfolio_id' => $portfolioId,
            'inbox_count' => count($summaries),
            'corrupted_line_count' => $corrupted,
            'inboxes' => $summaries,
            'claim_policy' => $this->claimPolicy(true),
        ];
    }

    /**
     * Record an explicit operator decision for a Portfolio Steward inbox item.
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
            'target_type' => 'portfolio_stewardship',
            'target_id' => $anchor['target_id'],
            'target_hash' => $anchor['target_hash'],
            'target_payload' => $anchor['target_payload'],
            'operator_actor' => $input['operator_actor'] ?? null,
            'decision' => $input['decision'] ?? null,
            'risk' => $input['risk'] ?? ($anchor['risk_level'] ?? 'medium'),
            'rationale' => $input['rationale'] ?? '',
        ]) + [
            'source_ap_contract' => 'AP-734',
            'source_inbox_item_id' => $anchor['item_id'],
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function healthReport(array $input): array
    {
        if (is_array($input['health_report'] ?? null)) {
            return $input['health_report'];
        }

        $snapshotId = trim((string) ($input['snapshot_id'] ?? ''));
        if ($snapshotId !== '') {
            $snapshot = $this->healthModel->replay($snapshotId);
            if ($snapshot !== null) {
                return $snapshot;
            }

            return [
                'schema_version' => PortfolioStewardshipHealthModelService::HEALTH_SCHEMA,
                'status' => PortfolioStewardshipHealthModelService::STATUS_BLOCKED,
                'portfolio_id' => (string) ($input['portfolio_id'] ?? PortfolioStewardshipHealthModelService::DEFAULT_PORTFOLIO_ID),
                'area_id' => (string) ($input['area_id'] ?? StewardshipEvolutionReadModelService::DEFAULT_AREA_ID),
                'reason' => 'health_snapshot_not_found',
            ];
        }

        return $this->healthModel->project($this->sanitizedHealthInput($input));
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @param  array<string,mixed>  $health
     * @return array<string,mixed>
     */
    private function item(array $candidate, array $health): array
    {
        $portfolioId = (string) ($health['portfolio_id'] ?? PortfolioStewardshipHealthModelService::DEFAULT_PORTFOLIO_ID);
        $targetArea = (string) ($candidate['target_area'] ?? '');
        $targetId = (string) ($candidate['candidate_id'] ?? ('portfolio_rebalance_'.$targetArea));
        $targetPayload = [
            'portfolio_id' => $portfolioId,
            'candidate' => $candidate,
            'source_health_hash' => (string) ($health['health_hash'] ?? ''),
            'source_snapshot_id' => (string) ($health['snapshot_id'] ?? ''),
        ];
        $targetHash = 'sha256:'.MissionCanonicalHash::sha256($targetPayload);

        return [
            'schema_version' => self::ITEM_SCHEMA,
            'item_id' => 'psib_'.substr(MissionCanonicalHash::sha256([$portfolioId, $targetId, $targetHash]), 0, 16),
            'portfolio_id' => $portfolioId,
            'area_id' => (string) ($health['area_id'] ?? StewardshipEvolutionReadModelService::DEFAULT_AREA_ID),
            'target_type' => 'portfolio_stewardship',
            'target_id' => $targetId,
            'target_hash' => $targetHash,
            'target_payload' => $targetPayload,
            'source_health_hash' => (string) ($health['health_hash'] ?? ''),
            'source_snapshot_id' => (string) ($health['snapshot_id'] ?? ''),
            'title' => 'Review portfolio rebalance: '.$targetArea,
            'rationale' => (string) ($candidate['reason'] ?? 'portfolio_rebalance_candidate'),
            'recommended_action' => (string) ($candidate['action'] ?? 'allocate_next_governed_cycle'),
            'target_area' => $targetArea,
            'priority_score' => (int) ($candidate['priority_score'] ?? 0),
            'risk_level' => $this->riskLevel($health, $candidate),
            'status' => 'pending_operator_review',
            'operator_decision_required' => true,
            'operator_actions' => ['accept', 'reject', 'defer', 'request_changes'],
            'routes_to' => array_values((array) ($candidate['routes_to'] ?? [])),
            'evidence_refs' => array_values((array) ($health['evidence_refs'] ?? [])),
            'autoapproval_allowed' => false,
            'autoimplementation_allowed' => false,
            'external_side_effect_allowed' => false,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @return array<string,mixed>
     */
    private function counts(array $items): array
    {
        $byRisk = [];
        foreach ($items as $item) {
            $risk = (string) ($item['risk_level'] ?? 'medium');
            $byRisk[$risk] = ($byRisk[$risk] ?? 0) + 1;
        }
        ksort($byRisk);

        return [
            'total' => count($items),
            'pending_operator_review' => count($items),
            'by_risk' => $byRisk,
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
        $itemId = trim((string) ($input['item_id'] ?? ''));

        if (($targetId !== '' && $targetHash !== '') || is_array($input['target_payload'] ?? null)) {
            return [
                'portfolio_id' => $this->portfolioId($input['portfolio_id'] ?? PortfolioStewardshipHealthModelService::DEFAULT_PORTFOLIO_ID),
                'area_id' => $this->areaId($input['area_id'] ?? StewardshipEvolutionReadModelService::DEFAULT_AREA_ID),
                'item_id' => $itemId !== '' ? $itemId : null,
                'target_id' => $targetId,
                'target_hash' => $targetHash,
                'target_payload' => is_array($input['target_payload'] ?? null) ? $input['target_payload'] : null,
                'risk_level' => (string) ($input['risk'] ?? 'medium'),
            ];
        }

        if ($itemId === '') {
            throw new InvalidArgumentException('portfolio_inbox_item_required: --item-id or target anchors are required.');
        }

        $inboxId = trim((string) ($input['inbox_id'] ?? ''));
        $inbox = $inboxId !== ''
            ? $this->replay($inboxId)
            : $this->project($input);
        if ($inbox === null) {
            throw new InvalidArgumentException('portfolio_inbox_not_found: inbox_id was not found in the AP-734 ledger.');
        }
        foreach ((array) ($inbox['items'] ?? []) as $item) {
            if (! is_array($item) || (string) ($item['item_id'] ?? '') !== $itemId) {
                continue;
            }

            return [
                'portfolio_id' => (string) ($item['portfolio_id'] ?? $inbox['portfolio_id'] ?? PortfolioStewardshipHealthModelService::DEFAULT_PORTFOLIO_ID),
                'area_id' => (string) ($item['area_id'] ?? $inbox['area_id'] ?? StewardshipEvolutionReadModelService::DEFAULT_AREA_ID),
                'item_id' => $itemId,
                'target_id' => (string) ($item['target_id'] ?? ''),
                'target_hash' => (string) ($item['target_hash'] ?? ''),
                'target_payload' => is_array($item['target_payload'] ?? null) ? $item['target_payload'] : null,
                'risk_level' => (string) ($item['risk_level'] ?? 'medium'),
            ];
        }

        throw new InvalidArgumentException('portfolio_inbox_item_not_found: item_id was not found in the projected inbox.');
    }

    /**
     * @return array<string,mixed>
     */
    private function reusedOwners(): array
    {
        return [
            'portfolio_health_model' => [
                'owner_service' => PortfolioStewardshipHealthModelService::class,
                'ap_contract' => 'AP-733',
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
            'portfolio_inbox_only' => true,
            'writes_local_state' => $writesLocalState,
            'local_state_kind' => $writesLocalState ? 'jsonl_append_only_portfolio_inbox' : 'none',
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
    private function sanitizedHealthInput(array $input): array
    {
        $allowed = [
            'area_id',
            'portfolio_id',
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
     * @param  array<string,mixed>  $health
     * @return list<string>
     */
    private function sourceApContracts(array $health): array
    {
        $contracts = ['AP-730', 'AP-731', 'AP-732', 'AP-733'];
        foreach ((array) ($health['source_ap_contracts'] ?? []) as $contract) {
            $contract = trim((string) $contract);
            if ($contract !== '') {
                $contracts[] = $contract;
            }
        }

        return StewardshipStringListNormalizer::uniqueStrings($contracts);
    }

    /**
     * @param  array<string,mixed>  $health
     * @param  array<string,mixed>  $candidate
     */
    private function riskLevel(array $health, array $candidate): string
    {
        $score = (int) data_get($health, 'portfolio_health.score', 70);
        $priority = (int) ($candidate['priority_score'] ?? 0);

        return match (true) {
            $score < 50 || $priority >= 85 => 'critical',
            $score < 70 || $priority >= 60 => 'high',
            $score < 85 || $priority >= 30 => 'medium',
            default => 'low',
        };
    }

    private function inboxId(array $inbox): string
    {
        return 'psi_'.substr(MissionCanonicalHash::sha256([
            $inbox['portfolio_id'] ?? PortfolioStewardshipHealthModelService::DEFAULT_PORTFOLIO_ID,
            $inbox['inbox_hash'] ?? '',
        ]), 0, 16);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findInFile(string $path, string $inboxId): ?array
    {
        [$records] = $this->readRecords($path);
        foreach ($records as $record) {
            if ((string) ($record['inbox_id'] ?? '') === $inboxId) {
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
        return AppendOnlyJsonlStore::readWhereWithRejectedCount(
            $path,
            static fn (array $row): bool => isset($row['inbox_id']) && is_string($row['inbox_id']),
        );
    }

    /**
     * @return list<string>
     */
    private function portfolioFiles(): array
    {
        return AppendOnlyJsonlStore::jsonlFilesInDirectory($this->storageDir());
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function finalize(array $payload): array
    {
        $payload['inbox_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->stable($payload));
        $payload['generated_at'] = $this->now();

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function stable(array $payload): array
    {
        unset($payload['inbox_hash'], $payload['generated_at'], $payload['recorded_at']);

        return $payload;
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }

    private function areaId(mixed $value): string
    {
        $slug = preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim((string) $value))) ?? '';

        return $slug !== '' ? $slug : StewardshipEvolutionReadModelService::DEFAULT_AREA_ID;
    }

    private function portfolioId(mixed $value): string
    {
        $slug = $this->slug((string) $value);

        return $slug !== '' ? $slug : PortfolioStewardshipHealthModelService::DEFAULT_PORTFOLIO_ID;
    }

    private function slug(string $value): string
    {
        return preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim($value))) ?: '';
    }
}
