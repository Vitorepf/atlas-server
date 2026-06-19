<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * L5-5 TAXA²: raise-only, clamped Loop dial overlay.
 *
 * This is deliberately not a second runtime. It reads resolved Loop outcomes,
 * computes effective campaign dials for the existing supervisor, and writes an
 * audit receipt. Operator CLI overrides still win.
 */
final class AtlasLoopTaxa2DialOverlayService
{
    public const SCHEMA_VERSION = 'atlas.loop.taxa2_dial_overlay.v1';

    public const RECEIPT_SCHEMA_VERSION = 'atlas.loop.taxa2_dial_receipt.v1';

    public function __construct(
        private readonly AtlasLoopFunnelService $funnel,
        private readonly AtlasLoopMorningDigestService $morningDigest,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function evaluate(array $options = []): array
    {
        $enabled = (bool) config('atlas.loop.taxa2_dials.enabled', false);
        $base = $this->baseDials();
        $limits = $this->limits();

        if (! $enabled) {
            return $this->payload(
                'disabled',
                $base,
                $base,
                $base,
                $limits,
                [],
                [],
                [],
                $options,
            );
        }

        $hours = max(1, min(168, (int) ($options['hours'] ?? config('atlas.loop.taxa2_dials.window_hours', 24))));
        $funnel = $this->arrayOptionOr($options, 'funnel', fn (): array => $this->funnel->snapshot());
        $digest = $this->arrayOptionOr($options, 'morning_digest', fn (): array => $this->morningDigest->digest($hours));
        $measurements = $this->measurements($funnel, $digest);
        [$suggested, $adjustments, $reasons] = $this->suggest($base, $limits, $measurements);
        $effective = $this->applyOperatorOverrides($base, $suggested, $options, $adjustments, $reasons);

        $status = $this->changed($base, $effective) ? 'adjusted' : 'steady';
        $payload = $this->payload($status, $base, $suggested, $effective, $limits, $measurements, $adjustments, $reasons, $options);

        if ((bool) ($options['write_receipt'] ?? false)) {
            $payload['receipt'] = $this->writeReceipt($payload);
        }

        return $payload;
    }

    /**
     * @return array{scenarios_per_task:int,queue_low_watermark:int,refill_batch:int}
     */
    private function baseDials(): array
    {
        return [
            'scenarios_per_task' => max(1, (int) config('atlas.loop.scenarios_per_task', 3)),
            'queue_low_watermark' => max(1, (int) config('atlas.loop.campaign.queue_low_watermark', 4)),
            'refill_batch' => max(1, (int) config('atlas.loop.campaign.refill_batch', 6)),
        ];
    }

    /**
     * @return array<string,int|float>
     */
    private function limits(): array
    {
        return [
            'max_scenarios_per_task' => max(1, (int) config('atlas.loop.max_scenarios_per_task', 12)),
            'max_queue_low_watermark' => max(1, (int) config('atlas.loop.taxa2_dials.max_queue_low_watermark', 12)),
            'max_refill_batch' => max(1, (int) config('atlas.loop.taxa2_dials.max_refill_batch', 24)),
            'max_delta_per_run' => max(1, (int) config('atlas.loop.taxa2_dials.max_delta_per_run', 2)),
            'min_certification_rate' => max(0.0, min(1.0, (float) config('atlas.loop.taxa2_dials.min_certification_rate', 0.65))),
            'min_certified_to_merged' => max(0.0, min(1.0, (float) config('atlas.loop.taxa2_dials.min_certified_to_merged', 0.5))),
            'max_canary_failures_24h' => max(0, (int) config('atlas.loop.taxa2_dials.max_canary_failures_24h', 0)),
            'min_impact_receipt_coverage_pct' => max(0.0, min(100.0, (float) config('atlas.loop.taxa2_dials.min_impact_receipt_coverage_pct', 95.0))),
            'min_cost_coverage_pct' => max(0.0, min(100.0, (float) config('atlas.loop.taxa2_dials.min_cost_coverage_pct', 80.0))),
        ];
    }

    /**
     * @param  array<string,mixed>  $funnel
     * @param  array<string,mixed>  $digest
     * @return array<string,mixed>
     */
    private function measurements(array $funnel, array $digest): array
    {
        $attempted = $this->intData($funnel, 'stages.attempted', $digest, 'sections.funnel.stages.attempted');
        $certified = $this->intData($funnel, 'stages.certified', $digest, 'sections.funnel.stages.certified');
        $merged = $this->intData($funnel, 'stages.merged', $digest, 'sections.funnel.stages.merged');
        $pending = $this->intData($funnel, 'branches.pending_tasks', $digest, 'sections.funnel.branches.pending_tasks');
        $drainable = $this->intData($funnel, 'conversion.drainable_remaining', $digest, 'sections.funnel.conversion.drainable_remaining');
        $retiredStale = $this->intData($funnel, 'branches.retired_stale', $digest, 'sections.funnel.branches.retired_stale');

        $certificationRate = $attempted > 0 ? min(1.0, round($certified / max(1, $attempted), 4)) : null;
        $certifiedToMerged = data_get($funnel, 'conversion.certified_to_merged');
        if (! is_numeric($certifiedToMerged)) {
            $certifiedToMerged = $certified > 0 ? round($merged / max(1, $certified), 4) : null;
        }

        $canaryRan = (int) data_get($digest, 'sections.canaries.ran_24h', 0);
        $canaryFailed = (int) data_get($digest, 'sections.canaries.failed_24h', 0);
        $costEvents = (int) data_get($digest, 'sections.cost.events_24h', 0);

        return [
            'attempted' => $attempted,
            'certified' => $certified,
            'merged' => $merged,
            'pending_tasks' => $pending,
            'drainable_remaining' => $drainable,
            'retired_stale' => $retiredStale,
            'certification_rate' => $certificationRate,
            'certified_to_merged' => is_numeric($certifiedToMerged) ? (float) $certifiedToMerged : null,
            'canary_ran_24h' => $canaryRan,
            'canary_failed_24h' => $canaryFailed,
            'canary_failure_rate_24h' => $canaryRan > 0 ? round($canaryFailed / max(1, $canaryRan), 4) : null,
            'impact_receipt_coverage_pct_24h' => (float) data_get($digest, 'sections.merges.impact_receipt_coverage_pct_24h', 0.0),
            'cost_events_24h' => $costEvents,
            'cost_measured_events_24h' => (int) data_get($digest, 'sections.cost.measured_events_24h', 0),
            'cost_coverage_pct_24h' => (float) data_get($digest, 'sections.cost.coverage_pct_24h', 0.0),
            'source_status' => [
                'funnel' => (string) data_get($funnel, 'status', 'ok'),
                'morning_digest' => (string) data_get($digest, 'status', 'ok'),
            ],
        ];
    }

    /**
     * @param  array<string,int>  $base
     * @param  array<string,int|float>  $limits
     * @param  array<string,mixed>  $m
     * @return array{0:array<string,int>,1:array<string,mixed>,2:list<string>}
     */
    private function suggest(array $base, array $limits, array $m): array
    {
        $signals = [
            [
                'active' => is_float($m['certification_rate']) && $m['certification_rate'] < (float) $limits['min_certification_rate'],
                'reason' => 'certification_rate_below_floor',
                'scenario_delta' => 1,
                'watermark_delta' => 0,
                'refill_delta' => 0,
            ],
            [
                'active' => (int) $m['canary_failed_24h'] > (int) $limits['max_canary_failures_24h'],
                'reason' => 'red_canaries_in_loop_window',
                'scenario_delta' => 1,
                'watermark_delta' => 0,
                'refill_delta' => 0,
            ],
            [
                'active' => (float) $m['impact_receipt_coverage_pct_24h'] > 0.0
                    && (float) $m['impact_receipt_coverage_pct_24h'] < (float) $limits['min_impact_receipt_coverage_pct'],
                'reason' => 'impact_receipt_coverage_below_floor',
                'scenario_delta' => 1,
                'watermark_delta' => 0,
                'refill_delta' => 0,
            ],
            [
                'active' => is_float($m['certified_to_merged']) && $m['certified_to_merged'] < (float) $limits['min_certified_to_merged'],
                'reason' => 'certified_to_merged_below_floor',
                'scenario_delta' => 0,
                'watermark_delta' => 1,
                'refill_delta' => 1,
            ],
            [
                'active' => (int) $m['pending_tasks'] < $base['queue_low_watermark'],
                'reason' => 'pending_queue_below_base_watermark',
                'scenario_delta' => 0,
                'watermark_delta' => 1,
                'refill_delta' => 1,
            ],
            [
                'active' => (int) $m['retired_stale'] > max(0, (int) $m['merged']),
                'reason' => 'stale_retirements_exceed_merges',
                'scenario_delta' => 0,
                'watermark_delta' => 0,
                'refill_delta' => 1,
            ],
            [
                'active' => (int) $m['cost_events_24h'] > 0 && (float) $m['cost_coverage_pct_24h'] < (float) $limits['min_cost_coverage_pct'],
                'reason' => 'cost_coverage_too_low_for_downshift',
                'scenario_delta' => 0,
                'watermark_delta' => 0,
                'refill_delta' => 0,
            ],
        ];
        $activeSignals = array_filter($signals, static fn (array $signal): bool => $signal['active']);
        $scenarioDelta = (int) array_sum(array_column($activeSignals, 'scenario_delta'));
        $watermarkDelta = (int) array_sum(array_column($activeSignals, 'watermark_delta'));
        $refillDelta = (int) array_sum(array_column($activeSignals, 'refill_delta'));
        $reasons = array_column($activeSignals, 'reason');

        $maxDelta = (int) $limits['max_delta_per_run'];
        $suggested = [
            'scenarios_per_task' => min((int) $limits['max_scenarios_per_task'], $base['scenarios_per_task'] + min($maxDelta, $scenarioDelta)),
            'queue_low_watermark' => min((int) $limits['max_queue_low_watermark'], $base['queue_low_watermark'] + min($maxDelta, $watermarkDelta)),
            'refill_batch' => min((int) $limits['max_refill_batch'], $base['refill_batch'] + min($maxDelta, $refillDelta)),
        ];

        $adjustments = [
            'scenarios_per_task' => [
                'base' => $base['scenarios_per_task'],
                'suggested' => $suggested['scenarios_per_task'],
                'delta' => $suggested['scenarios_per_task'] - $base['scenarios_per_task'],
            ],
            'queue_low_watermark' => [
                'base' => $base['queue_low_watermark'],
                'suggested' => $suggested['queue_low_watermark'],
                'delta' => $suggested['queue_low_watermark'] - $base['queue_low_watermark'],
            ],
            'refill_batch' => [
                'base' => $base['refill_batch'],
                'suggested' => $suggested['refill_batch'],
                'delta' => $suggested['refill_batch'] - $base['refill_batch'],
            ],
        ];

        return [$suggested, $adjustments, array_values(array_unique($reasons))];
    }

    /**
     * @param  array<string,int>  $base
     * @param  array<string,int>  $suggested
     * @param  array<string,mixed>  $options
     * @param  array<string,mixed>  $adjustments
     * @param  list<string>  $reasons
     * @return array<string,int>
     */
    private function applyOperatorOverrides(array $base, array $suggested, array $options, array &$adjustments, array &$reasons): array
    {
        $effective = [
            'scenarios_per_task' => max($base['scenarios_per_task'], $suggested['scenarios_per_task']),
            'queue_low_watermark' => max($base['queue_low_watermark'], $suggested['queue_low_watermark']),
            'refill_batch' => max($base['refill_batch'], $suggested['refill_batch']),
        ];

        $explicitScenarios = $this->positiveIntOrNull($options['explicit_scenarios'] ?? null);
        if ($explicitScenarios !== null) {
            $effective['scenarios_per_task'] = $explicitScenarios;
            $adjustments['scenarios_per_task']['operator_override'] = true;
            $adjustments['scenarios_per_task']['effective'] = $explicitScenarios;
            $reasons[] = 'operator_scenarios_override_preserved';
        }

        foreach (['queue_low_watermark', 'refill_batch'] as $dial) {
            $adjustments[$dial]['operator_override'] = false;
            $adjustments[$dial]['effective'] = $effective[$dial];
        }
        $adjustments['scenarios_per_task']['operator_override'] ??= false;
        $adjustments['scenarios_per_task']['effective'] ??= $effective['scenarios_per_task'];

        return $effective;
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function payload(
        string $status,
        array $base,
        array $suggested,
        array $effective,
        array $limits,
        array $measurements,
        array $adjustments,
        array $reasons,
        array $options,
    ): array {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'generated_at' => Carbon::now()->toIso8601String(),
            'source' => (string) ($options['source'] ?? 'taxa2_dial_overlay'),
            'kernel_sanction' => [
                'enabled' => (bool) config('atlas.loop.taxa2_dials.enabled', false),
                'kernel_sanctioned' => (bool) config('atlas.loop.taxa2_dials.kernel_sanctioned', true),
                'policy' => 'raise_only_clamped',
                'operator_overrides_win' => true,
            ],
            'base_dials' => $base,
            'suggested_dials' => $suggested,
            'effective_dials' => $effective,
            'limits' => $limits,
            'measurements' => $measurements,
            'adjustments' => $adjustments,
            'reasons' => array_values(array_unique(array_map('strval', $reasons))),
            'changed' => $this->changed($base, $effective),
            'claim_policy' => [
                'read_only_without_receipt' => ! (bool) ($options['write_receipt'] ?? false),
                'provider_calls_made' => false,
                'provider_tokens_spent' => false,
                'workspace_mutated' => false,
                'merged_to_main' => false,
                'never_merge_default_changed' => false,
                'writes_receipt_only' => (bool) ($options['write_receipt'] ?? false),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function writeReceipt(array $payload): array
    {
        $hash = substr(hash('sha256', json_encode([
            $payload['generated_at'] ?? '',
            $payload['effective_dials'] ?? [],
            $payload['measurements'] ?? [],
            random_int(1, PHP_INT_MAX),
        ], JSON_UNESCAPED_SLASHES) ?: ''), 0, 12);
        $receiptId = 'taxa2_dials_'.Carbon::now()->format('YmdHis').'_'.$hash;
        $path = 'atlas/loop/taxa2-dials/receipts/'.$receiptId.'.json';
        $receipt = [
            'schema_version' => self::RECEIPT_SCHEMA_VERSION,
            'receipt_id' => $receiptId,
            'status' => $payload['status'] ?? 'unknown',
            'generated_at' => Carbon::now()->toIso8601String(),
            'overlay' => $payload,
            'receipt_path' => $path,
        ];

        Storage::disk('local')->put($path, (string) json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return [
            'schema_version' => self::RECEIPT_SCHEMA_VERSION,
            'receipt_id' => $receiptId,
            'status' => $payload['status'] ?? 'unknown',
            'receipt_path' => $path,
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function arrayOptionOr(array $options, string $key, Closure $fallback): array
    {
        if (is_array($options[$key] ?? null)) {
            return $options[$key];
        }

        try {
            $value = $fallback();

            return is_array($value) ? $value : ['status' => 'unavailable', 'reason' => $key.'_not_array'];
        } catch (Throwable $e) {
            return [
                'status' => 'unavailable',
                'reason' => $key.'_failed',
                'error' => mb_substr($e->getMessage(), 0, 160),
            ];
        }
    }

    private function intData(array $first, string $firstKey, array $second, string $secondKey): int
    {
        $value = data_get($first, $firstKey);
        if (! is_numeric($value)) {
            $value = data_get($second, $secondKey, 0);
        }

        return max(0, (int) $value);
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @param  array<string,int>  $base
     * @param  array<string,int>  $effective
     */
    private function changed(array $base, array $effective): bool
    {
        foreach (['scenarios_per_task', 'queue_low_watermark', 'refill_batch'] as $dial) {
            if (($effective[$dial] ?? null) !== ($base[$dial] ?? null)) {
                return true;
            }
        }

        return false;
    }
}
