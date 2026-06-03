<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\StrategyLoop\Campaign;

/**
 * Records promoted/certified champion regions so a later independent campaign can
 * prove rediscovery. This is a hard anti-luck gate, not a ranking signal.
 */
final class CandidateRediscoveryLedger
{
    public function __construct(private readonly string $path) {}

    public static function default(bool $dryRun = false): self
    {
        return new self($dryRun
            ? storage_path('framework/atlas/finance/candidate-rediscovery-ledger.jsonl')
            : storage_path('atlas/finance/candidate-rediscovery-ledger.jsonl'));
    }

    /**
     * @param  array<string,mixed>  $event
     */
    public function record(array $event): void
    {
        @mkdir(dirname($this->path), 0o755, true);
        file_put_contents($this->path, json_encode([
            'schema_version' => 'atlas.finance.strategy_candidate_rediscovery.v1',
            'recorded_at' => gmdate('c'),
            ...$event,
            'propose_only' => true,
            'live_trading' => 'forbidden',
        ], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)."\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * @return array<string,mixed>
     */
    public function confirmations(array $signature, string $currentCampaignId): array
    {
        $target = (string) ($signature['signature'] ?? '');
        $scope = $signature['scope'] ?? [];
        $campaigns = [];
        foreach ($this->rows() as $row) {
            if ((string) ($row['campaign_id'] ?? '') === $currentCampaignId) {
                continue;
            }
            if ((string) ($row['signature']['signature'] ?? '') !== $target) {
                continue;
            }
            $rowScope = $row['signature']['scope'] ?? [];
            if (($rowScope['symbol'] ?? null) !== ($scope['symbol'] ?? null)
                || ($rowScope['interval'] ?? null) !== ($scope['interval'] ?? null)
                || ($rowScope['family'] ?? null) !== ($scope['family'] ?? null)) {
                continue;
            }
            $campaign = (string) ($row['campaign_id'] ?? '');
            if ($campaign !== '') {
                $campaigns[$campaign] = [
                    'campaign_id' => $campaign,
                    'round' => $row['round'] ?? null,
                    'status' => $row['status'] ?? null,
                    'deflated_sharpe' => $row['deflated_sharpe'] ?? null,
                    'holdout_sharpe' => $row['holdout_sharpe'] ?? null,
                ];
            }
        }

        return [
            'signature' => $target,
            'independent_campaigns' => array_values($campaigns),
            'count' => count($campaigns),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function rows(): array
    {
        if (! is_file($this->path)) {
            return [];
        }
        $rows = [];
        foreach (file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }

        return $rows;
    }
}
