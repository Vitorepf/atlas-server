<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Supply;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Back off from two-file extract-class when the live campaign has already proven that shape is
 * burning the time budget (supply-lane extraction contract: the read-model lives here, the
 * refiller keeps only its per-refill memoization). Fail-open: any read issue leaves the lane
 * available.
 */
final class AtlasLoopRefillerExtractClassBackoff
{
    /** @return array{active:bool, timeouts:int, successes:int, window_hours:int} */
    public function compute(string $campaignId, string $extractClassObjectiveKind): array
    {
        if (! (bool) config('atlas.loop.extract_class_timeout_backoff_enabled', true)) {
            return ['active' => false, 'timeouts' => 0, 'successes' => 0, 'window_hours' => 0];
        }

        $windowHours = max(1, (int) config('atlas.loop.extract_class_timeout_backoff_window_hours', 6));
        $minTimeouts = max(1, (int) config('atlas.loop.extract_class_timeout_backoff_min_timeouts', 3));
        $backoff = ['active' => false, 'timeouts' => 0, 'successes' => 0, 'window_hours' => $windowHours];

        $since = now()->subHours($windowHours)->toDateTimeString();
        try {
            $rows = DB::table('atlas_loop_tasks')
                ->where('campaign_id', $campaignId)
                ->where('updated_at', '>=', $since)
                ->get(['status', 'payload', 'result']);
        } catch (Throwable) {
            return $backoff;
        }

        foreach ($rows as $row) {
            $payload = AtlasLoopRefillerPayloadNormalizer::jsonObject($row->payload ?? null);
            if (($payload['objective_kind'] ?? null) !== $extractClassObjectiveKind) {
                continue;
            }

            if ((string) ($row->status ?? '') === 'done') {
                $backoff['successes']++;
                continue;
            }

            $result = AtlasLoopRefillerPayloadNormalizer::jsonObject($row->result ?? null);
            if (($result['reason'] ?? null) === 'parallel_worker_timeout') {
                $backoff['timeouts']++;
            }
        }

        $backoff['active'] = $backoff['timeouts'] >= $minTimeouts
            && $backoff['timeouts'] > $backoff['successes'];

        return $backoff;
    }
}
