<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Models\AiProgrammingRuntimeTelemetryEvent;
use App\Models\AtlasLoopProposal;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * L4-6 · Morning digest read-model for the last autonomous Loop window.
 *
 * One command should answer the operator's question: "what did Atlas do by
 * itself yesterday?" This service only reads resolved sources and never calls a
 * provider, sends mail, mutates memory, or creates backlog.
 */
final class AtlasLoopMorningDigestService
{
    public const SCHEMA_VERSION = 'atlas.loop.morning_digest.v1';

    /**
     * @return array<string,mixed>
     */
    public function digest(?int $hours = null): array
    {
        $hours = max(1, min(168, $hours ?? (int) config('atlas.loop.morning_digest.window_hours', 24)));
        $until = Carbon::now();
        $since = $until->copy()->subHours($hours);

        $funnel = $this->funnel();
        $merges = $this->merges($since);
        $canaries = $this->canaries($since);
        $cost = $this->cost($since, $merges);
        $keepalive = $this->keepalive($since);
        $operatorReview = $this->operatorReview();

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'ok',
            'title' => 'Atlas Loop morning digest',
            'window' => [
                'hours' => $hours,
                'since' => $since->toIso8601String(),
                'until' => $until->toIso8601String(),
            ],
            'headline' => $this->headline($funnel, $merges, $canaries, $operatorReview),
            'sections' => [
                'funnel' => $funnel,
                'merges' => $merges,
                'canaries' => $canaries,
                'cost' => $cost,
                'keepalive' => $keepalive,
                'operator_review' => $operatorReview,
            ],
            'sources' => [
                'funnel' => 'AtlasLoopFunnelService::snapshot()',
                'merges' => 'atlas_loop_proposals.merged_to_main + quality._impact_receipt',
                'canaries' => 'atlas_loop_proposals.quality._canary',
                'cost' => 'ai_programming_runtime_telemetry_events.cost_estimate_usd + atlas_loop_campaigns.spend_usd_cents',
                'keepalive' => (string) config('atlas.loop.morning_digest.keepalive_event_log_path'),
                'operator_review' => 'AtlasLoopOperatorReviewQueueService::queue()',
            ],
            'claim_policy' => [
                'read_only' => true,
                'provider_calls_made' => false,
                'email_sent' => false,
                'one_command_answer' => 'php artisan atlas:loop:morning-digest --json',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function funnel(): array
    {
        if (! DatabaseTableAvailability::has('atlas_loop_tasks') || ! DatabaseTableAvailability::has('atlas_loop_proposals')) {
            return [
                'status' => 'unavailable',
                'reason' => 'loop_tables_missing',
                'stages' => [],
            ];
        }

        try {
            return array_merge(app(AtlasLoopFunnelService::class)->snapshot(), ['status' => 'ok']);
        } catch (Throwable $e) {
            return [
                'status' => 'unavailable',
                'reason' => 'funnel_failed',
                'error' => mb_substr($e->getMessage(), 0, 160),
                'stages' => [],
            ];
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function merges(Carbon $since): array
    {
        $base = [
            'status' => 'ok',
            'merged_24h' => 0,
            'impact_receipts_24h' => 0,
            'impact_receipt_coverage_pct_24h' => 0.0,
            'impact_receipt_aggregate' => [],
            'latest' => [],
        ];

        if (! DatabaseTableAvailability::has('atlas_loop_proposals')) {
            return array_merge($base, ['status' => 'unavailable', 'reason' => 'atlas_loop_proposals_missing']);
        }

        try {
            $rows = AtlasLoopProposal::query()
                ->where('merged_to_main', true)
                ->where(function ($q) use ($since): void {
                    $q->where('reviewed_at', '>=', $since)
                        ->orWhere(function ($q) use ($since): void {
                            $q->whereNull('reviewed_at')->where('updated_at', '>=', $since);
                        });
                })
                ->orderByDesc('reviewed_at')
                ->orderByDesc('updated_at')
                ->limit(100)
                ->get();

            $latest = [];
            $receipts = 0;
            foreach ($rows as $proposal) {
                $quality = is_array($proposal->quality) ? $proposal->quality : [];
                $impact = is_array($quality['_impact_receipt'] ?? null) ? $quality['_impact_receipt'] : null;
                if ($impact !== null) {
                    $receipts++;
                }
                if (count($latest) < 8) {
                    $latest[] = [
                        'proposal_id' => (string) $proposal->getKey(),
                        'target_path' => (string) $proposal->target_path,
                        'reviewed_at' => $proposal->reviewed_at?->toIso8601String(),
                        'impact_category' => $impact['category'] ?? null,
                        'impact_score' => $impact['impact_score'] ?? null,
                        'target_kind' => $impact['target_kind'] ?? null,
                    ];
                }
            }

            $count = $rows->count();

            return array_merge($base, [
                'merged_24h' => $count,
                'impact_receipts_24h' => $receipts,
                'impact_receipt_coverage_pct_24h' => $count > 0 ? round(($receipts / $count) * 100, 1) : 0.0,
                'impact_receipt_aggregate' => app(AtlasLoopImpactReceiptService::class)->aggregate(),
                'latest' => $latest,
            ]);
        } catch (Throwable $e) {
            return array_merge($base, [
                'status' => 'unavailable',
                'reason' => 'merge_query_failed',
                'error' => mb_substr($e->getMessage(), 0, 160),
            ]);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function canaries(Carbon $since): array
    {
        $base = [
            'status' => 'ok',
            'ran_24h' => 0,
            'passed_24h' => 0,
            'failed_24h' => 0,
            'not_run_24h' => 0,
            'latest_failed' => [],
        ];

        if (! DatabaseTableAvailability::has('atlas_loop_proposals')) {
            return array_merge($base, ['status' => 'unavailable', 'reason' => 'atlas_loop_proposals_missing']);
        }

        try {
            $rows = AtlasLoopProposal::query()
                ->where('merged_to_main', true)
                ->where(function ($q) use ($since): void {
                    $q->where('reviewed_at', '>=', $since)
                        ->orWhere(function ($q) use ($since): void {
                            $q->whereNull('reviewed_at')->where('updated_at', '>=', $since);
                        });
                })
                ->orderByDesc('reviewed_at')
                ->orderByDesc('updated_at')
                ->limit(200)
                ->get();

            $failed = [];
            foreach ($rows as $proposal) {
                $quality = is_array($proposal->quality) ? $proposal->quality : [];
                $canary = is_array($quality['_canary'] ?? null) ? $quality['_canary'] : null;
                if ($canary === null || ($canary['ran'] ?? false) !== true) {
                    $base['not_run_24h']++;
                    continue;
                }
                $base['ran_24h']++;
                if (($canary['passed'] ?? null) === true) {
                    $base['passed_24h']++;
                } elseif (($canary['passed'] ?? null) === false) {
                    $base['failed_24h']++;
                    if (count($failed) < 5) {
                        $failed[] = [
                            'proposal_id' => (string) $proposal->getKey(),
                            'target_path' => (string) $proposal->target_path,
                            'canary_target' => $canary['target'] ?? null,
                        ];
                    }
                }
            }

            $base['latest_failed'] = $failed;

            return $base;
        } catch (Throwable $e) {
            return array_merge($base, [
                'status' => 'unavailable',
                'reason' => 'canary_query_failed',
                'error' => mb_substr($e->getMessage(), 0, 160),
            ]);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function cost(Carbon $since, array $merges): array
    {
        $base = [
            'status' => 'ok',
            'events_24h' => 0,
            'measured_events_24h' => 0,
            'coverage_pct_24h' => 0.0,
            'total_cost_usd_24h' => 0.0,
            'cost_per_merge_usd_24h' => null,
            'cost_per_merge_available' => false,
            'by_flow' => [],
            'campaign_budget' => $this->campaignBudgetCost($since),
        ];

        if (! DatabaseTableAvailability::has('ai_programming_runtime_telemetry_events')) {
            return array_merge($base, ['status' => 'unavailable', 'reason' => 'ai_programming_runtime_telemetry_events_missing']);
        }

        try {
            $events = AiProgrammingRuntimeTelemetryEvent::query()
                ->where('occurred_at', '>=', $since)
                ->get(['flow', 'cost_estimate_usd']);

            $byFlow = [];
            $measured = 0;
            $cost = 0.0;
            foreach ($events as $event) {
                $flow = trim((string) ($event->flow ?? '')) ?: 'unknown';
                $byFlow[$flow] ??= ['events' => 0, 'measured_events' => 0, 'cost_usd' => 0.0];
                $byFlow[$flow]['events']++;

                $eventCost = (float) ($event->cost_estimate_usd ?? 0.0);
                if ($eventCost > 0) {
                    $measured++;
                    $cost += $eventCost;
                    $byFlow[$flow]['measured_events']++;
                    $byFlow[$flow]['cost_usd'] = round((float) $byFlow[$flow]['cost_usd'] + $eventCost, 6);
                }
            }

            $total = $events->count();
            ksort($byFlow);
            $merged = max(0, (int) ($merges['merged_24h'] ?? 0));
            $costPerMerge = $merged > 0 && $cost > 0.0 ? round($cost / $merged, 6) : null;

            return [
                'status' => 'ok',
                'events_24h' => $total,
                'measured_events_24h' => $measured,
                'coverage_pct_24h' => $total > 0 ? round(($measured / $total) * 100, 1) : 0.0,
                'total_cost_usd_24h' => round($cost, 6),
                'cost_per_merge_usd_24h' => $costPerMerge,
                'cost_per_merge_available' => $costPerMerge !== null,
                'by_flow' => $byFlow,
                'campaign_budget' => $base['campaign_budget'],
            ];
        } catch (Throwable $e) {
            return array_merge($base, [
                'status' => 'unavailable',
                'reason' => 'cost_query_failed',
                'error' => mb_substr($e->getMessage(), 0, 160),
            ]);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function campaignBudgetCost(Carbon $since): array
    {
        $base = [
            'status' => 'ok',
            'governor_enabled' => (bool) config('atlas.loop.cost_governor.enabled', false),
            'campaigns_observed' => 0,
            'running_campaigns' => 0,
            'campaigns_with_cost_cap' => 0,
            'total_spend_usd' => 0.0,
            'total_cap_usd' => 0.0,
            'remaining_cap_usd' => null,
            'nearest_spend_pct' => null,
        ];

        if (! DatabaseTableAvailability::has('atlas_loop_campaigns')) {
            return array_merge($base, ['status' => 'unavailable', 'reason' => 'atlas_loop_campaigns_missing']);
        }
        if (! Schema::hasColumn('atlas_loop_campaigns', 'spend_usd_cents') || ! Schema::hasColumn('atlas_loop_campaigns', 'max_usd_cents')) {
            return array_merge($base, ['status' => 'unavailable', 'reason' => 'campaign_cost_columns_missing']);
        }

        try {
            $rows = DB::table('atlas_loop_campaigns')
                ->where(function ($q) use ($since): void {
                    $q->where('status', 'running')
                        ->orWhere('updated_at', '>=', $since);
                })
                ->get(['status', 'spend_usd_cents', 'max_usd_cents']);

            $spend = 0;
            $cap = 0;
            $nearest = null;
            $withCap = 0;
            $running = 0;
            foreach ($rows as $row) {
                $rowSpend = max(0, (int) ($row->spend_usd_cents ?? 0));
                $rowCap = max(0, (int) ($row->max_usd_cents ?? 0));
                $spend += $rowSpend;
                if ((string) ($row->status ?? '') === 'running') {
                    $running++;
                }
                if ($rowCap > 0) {
                    $withCap++;
                    $cap += $rowCap;
                    $pct = round(($rowSpend / $rowCap) * 100, 2);
                    $nearest = $nearest === null ? $pct : max($nearest, $pct);
                }
            }

            return [
                'status' => 'ok',
                'governor_enabled' => (bool) config('atlas.loop.cost_governor.enabled', false),
                'campaigns_observed' => $rows->count(),
                'running_campaigns' => $running,
                'campaigns_with_cost_cap' => $withCap,
                'total_spend_usd' => round($spend / 100, 2),
                'total_cap_usd' => round($cap / 100, 2),
                'remaining_cap_usd' => $cap > 0 ? round(max(0, $cap - $spend) / 100, 2) : null,
                'nearest_spend_pct' => $nearest,
            ];
        } catch (Throwable $e) {
            return array_merge($base, [
                'status' => 'unavailable',
                'reason' => 'campaign_budget_query_failed',
                'error' => mb_substr($e->getMessage(), 0, 160),
            ]);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function keepalive(Carbon $since): array
    {
        $path = (string) config('atlas.loop.morning_digest.keepalive_event_log_path');
        $base = [
            'status' => 'ok',
            'event_log_path' => $path,
            'events_24h' => 0,
            'respawned_24h' => 0,
            'revived_starved_24h' => 0,
            'latest' => [],
        ];

        if ($path === '' || ! is_file($path)) {
            return array_merge($base, ['status' => 'missing', 'reason' => 'keepalive_event_log_missing']);
        }

        try {
            $events = [];
            foreach (preg_split('/\r?\n/', (string) file_get_contents($path)) ?: [] as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $decoded = json_decode($line, true);
                if (! is_array($decoded)) {
                    continue;
                }
                $at = Carbon::parse((string) ($decoded['recorded_at'] ?? $decoded['generated_at'] ?? '1970-01-01T00:00:00Z'));
                if ($at->lessThan($since)) {
                    continue;
                }
                $events[] = $decoded;
            }

            $respawned = 0;
            $revived = 0;
            foreach ($events as $event) {
                $respawned += count((array) ($event['respawned'] ?? []));
                $revived += count((array) ($event['revived_starved'] ?? []));
            }

            return array_merge($base, [
                'events_24h' => count($events),
                'respawned_24h' => $respawned,
                'revived_starved_24h' => $revived,
                'latest' => array_slice(array_reverse($events), 0, 5),
            ]);
        } catch (Throwable $e) {
            return array_merge($base, [
                'status' => 'unavailable',
                'reason' => 'keepalive_event_log_unreadable',
                'error' => mb_substr($e->getMessage(), 0, 160),
            ]);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function operatorReview(): array
    {
        $base = [
            'status' => 'ok',
            'pending_parked_for_review' => 0,
            'items' => [],
        ];

        if (! DatabaseTableAvailability::has('atlas_loop_proposals')) {
            return array_merge($base, ['status' => 'unavailable', 'reason' => 'atlas_loop_proposals_missing']);
        }

        try {
            // L4-6 must report the exact queue L4-7 exposes. Some real parked
            // self-targets predate the `_operator_review` payload and are queued
            // by policy fallback; duplicating the query here undercounts them.
            $queue = app(AtlasLoopOperatorReviewQueueService::class)
                ->queue((int) config('atlas.loop.operator_review.limit', 10));
            $items = array_map(static fn (array $item): array => [
                'proposal_id' => (string) ($item['id'] ?? ''),
                'proposal_hash' => (string) ($item['proposal_hash'] ?? ''),
                'target_path' => (string) ($item['target_path'] ?? ''),
                'reason' => (string) ($item['reason'] ?? ''),
                'reviewed_at' => $item['reviewed_at'] ?? null,
            ], (array) ($queue['items'] ?? []));

            return [
                'status' => (string) ($queue['status'] ?? 'ok'),
                'pending_parked_for_review' => (int) ($queue['count'] ?? count($items)),
                'items' => $items,
            ];
        } catch (Throwable $e) {
            return array_merge($base, [
                'status' => 'unavailable',
                'reason' => 'operator_review_query_failed',
                'error' => mb_substr($e->getMessage(), 0, 160),
            ]);
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public static function appendKeepaliveEvent(array $payload): void
    {
        if (! (bool) config('atlas.loop.morning_digest.keepalive_event_log_enabled', true)) {
            return;
        }

        $path = (string) config('atlas.loop.morning_digest.keepalive_event_log_path');
        if ($path === '') {
            return;
        }

        try {
            File::ensureDirectoryExists(dirname($path));
            $payload['source_schema_version'] = $payload['schema_version'] ?? null;
            $payload['schema_version'] = 'atlas.loop.keepalive.digest_event.v1';
            $payload['recorded_at'] = Carbon::now()->toIso8601String();
            file_put_contents($path, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND | LOCK_EX);
            self::trimJsonl($path, (int) config('atlas.loop.morning_digest.keepalive_event_log_max_lines', 2000));
        } catch (Throwable) {
            // Digest evidence is best-effort; keepalive itself must never fail on logging.
        }
    }

    private static function trimJsonl(string $path, int $maxLines): void
    {
        $maxLines = max(100, min(10000, $maxLines));
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false || count($lines) <= $maxLines) {
            return;
        }

        file_put_contents($path, implode("\n", array_slice($lines, -$maxLines))."\n", LOCK_EX);
    }

    /**
     * @param  array<string,mixed>  $funnel
     * @param  array<string,mixed>  $merges
     * @param  array<string,mixed>  $canaries
     * @param  array<string,mixed>  $operatorReview
     */
    private function headline(array $funnel, array $merges, array $canaries, array $operatorReview): string
    {
        return sprintf(
            '%d merges em 24h, %d canários vermelhos, %d propostas aguardando operador. Funil: %s',
            (int) ($merges['merged_24h'] ?? 0),
            (int) ($canaries['failed_24h'] ?? 0),
            (int) ($operatorReview['pending_parked_for_review'] ?? 0),
            (string) ($funnel['verdict'] ?? 'indisponível'),
        );
    }
}
