<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

use App\Models\AiRagFeedbackEvent;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * MAXC-06 — Ex-post calibration of the sufficiency sensor.
 *
 * Joins delivered pack ledger (COM-01, carries the sufficiency block) × ARFL
 * events with `measured=true` and reports used-rate + utility means for the
 * two groups {declared_insufficient, declared_covered}. Denominators are
 * always exposed; `insufficient_signal` when massa is missing.
 *
 * Read-only, provider-safe by construction, honest empty when unwired.
 */
final class SufficiencyCalibrationService
{
    public const SCHEMA = 'atlas.context.sufficiency_calibration.v1';

    public const MIN_MEASURED = 10;

    private readonly AtlasDeliveredPackLedger $ledger;

    public function __construct(?AtlasDeliveredPackLedger $ledger = null)
    {
        $this->ledger = $ledger ?? AtlasDeliveredPackLedger::fromConfig();
    }

    /**
     * @return array<string,mixed>
     */
    public function report(int $windowDays = 14): array
    {
        $windowDays = max(1, $windowDays);
        $now = Carbon::now();
        $since = $now->copy()->subDays($windowDays);

        $rows = $this->collectLedgerRows();
        $arflRows = $this->collectArflRows($since);

        $matched = 0;
        $covered = ['n' => 0, 'utility_sum' => 0.0, 'used_sum' => 0, 'delivered_sum' => 0];
        $insufficient = ['n' => 0, 'utility_sum' => 0.0, 'used_sum' => 0, 'delivered_sum' => 0];

        foreach ($arflRows as $event) {
            $ledgerRow = $rows[$event['context_pack_hash']] ?? null;
            if ($ledgerRow === null) {
                continue;
            }
            $block = $this->extractSufficiencyBlock($ledgerRow);
            if ($block === null) {
                continue;
            }
            $matched++;
            $utility = (float) ($event['post_execution_utility'] ?? 0.0);
            $used = (int) ($event['used_sources'] ?? 0);
            $delivered = (int) ($event['included_sources'] ?? 0);

            $bucket = $block['not_enough_context'] ? 'insufficient' : 'covered';
            if ($bucket === 'insufficient') {
                $insufficient['n']++;
                $insufficient['utility_sum'] += $utility;
                $insufficient['used_sum'] += $used;
                $insufficient['delivered_sum'] += $delivered;
            } else {
                $covered['n']++;
                $covered['utility_sum'] += $utility;
                $covered['used_sum'] += $used;
                $covered['delivered_sum'] += $delivered;
            }
        }

        $totalMeasured = $covered['n'] + $insufficient['n'];
        $status = $totalMeasured >= self::MIN_MEASURED ? 'ready' : 'insufficient_signal';

        return [
            'schema' => self::SCHEMA,
            'formula_version' => 'atlas.context.sufficiency_calibration.v1',
            'window_days' => $windowDays,
            'status' => $status,
            'status_reason' => $this->statusReason($status, $matched, $totalMeasured),
            'denominator_min' => self::MIN_MEASURED,
            'measured_count' => $totalMeasured,
            'total_event_count' => count($arflRows),
            'ledger_row_count' => count($rows),
            'declared_insufficient' => [
                'n' => $insufficient['n'],
                'mean_post_execution_utility' => $this->safeMean($insufficient['utility_sum'], $insufficient['n']),
                'used_rate' => $this->safeRate($insufficient['used_sum'], $insufficient['delivered_sum']),
            ],
            'declared_covered' => [
                'n' => $covered['n'],
                'mean_post_execution_utility' => $this->safeMean($covered['utility_sum'], $covered['n']),
                'used_rate' => $this->safeRate($covered['used_sum'], $covered['delivered_sum']),
            ],
            'death_criterion' => 'if measured_count>='.self::MIN_MEASURED
                .' and abs(declared_insufficient.mean_utility - declared_covered.mean_utility) < 0.05'
                .' then open gap issue recommending sufficiency block removal (self-declared honesty).',
            'peek_invariant' => 'read-only; source is COM-01 ledger + ARFL measured=true; never writes',
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function collectLedgerRows(): array
    {
        try {
            $path = $this->ledger->path();
            if (! is_string($path) || $path === '' || ! is_file($path)) {
                return [];
            }
            $rows = [];
            $handle = @fopen($path, 'rb');
            if ($handle === false) {
                return [];
            }
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $decoded = json_decode($line, true);
                if (! is_array($decoded)) {
                    continue;
                }
                $hash = (string) ($decoded['context_pack_hash'] ?? '');
                if ($hash === '') {
                    continue;
                }
                $rows[$hash] = $decoded;
            }
            fclose($handle);

            return $rows;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function collectArflRows(Carbon $since): array
    {
        try {
            return AiRagFeedbackEvent::query()
                ->where('created_at', '>=', $since)
                ->get([
                    'flow_id',
                    'query_plan_hash',
                    'included_sources',
                    'used_sources',
                    'noise_sources',
                    'post_execution_utility',
                    'payload',
                    'created_at',
                ])
                ->map(function (AiRagFeedbackEvent $event): array {
                    $payload = (array) ($event->payload ?? []);
                    $measured = (bool) ($payload['measured'] ?? false);
                    if (! $measured) {
                        return [];
                    }

                    return [
                        'flow_id' => (string) $event->flow_id,
                        'context_pack_hash' => (string) (
                            $payload['context_pack_hash']
                            ?? data_get($payload, 'context_pack.context_pack_hash', '')
                            ?? ''
                        ),
                        'included_sources' => (int) ($event->included_sources ?? 0),
                        'used_sources' => (int) ($event->used_sources ?? 0),
                        'post_execution_utility' => (float) ($event->post_execution_utility ?? 0),
                    ];
                })
                ->filter(static fn (array $row): bool => ($row['context_pack_hash'] ?? '') !== '')
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  array<string,mixed>  $ledgerRow
     * @return array{not_enough_context:bool}|null
     */
    private function extractSufficiencyBlock(array $ledgerRow): ?array
    {
        $block = $ledgerRow['sufficiency_block'] ?? data_get($ledgerRow, 'pack.sufficiency', null);
        if (! is_array($block) || ! array_key_exists('not_enough_context', $block)) {
            return null;
        }

        return ['not_enough_context' => (bool) $block['not_enough_context']];
    }

    private function safeMean(float $sum, int $n): float
    {
        if ($n <= 0) {
            return 0.0;
        }

        return round($sum / $n, 4);
    }

    private function safeRate(int $numerator, int $denominator): float
    {
        if ($denominator <= 0) {
            return 0.0;
        }

        return round($numerator / $denominator, 4);
    }

    private function statusReason(string $status, int $matched, int $measuredCount): string
    {
        if ($status === 'insufficient_signal') {
            if ($matched === 0) {
                return 'no_ledger_events_with_sufficiency_block_joined';
            }

            return 'measured_count_below_min_denominator';
        }

        return 'ready';
    }
}
