<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * ACDE lever B3 — provider-safe DELIVERY RECALL into the loop's prompt window.
 *
 * The weak engine edits a file with no memory of what the loop has ALREADY certified nearby. This recalls the
 * recent CERTIFIED merged deliveries in the same module so the engine matches the conventions of what just
 * landed — the read-back half of the brain flywheel whose WRITE half is the merge itself (the loop already
 * persists every delivery in atlas_loop_proposals, so there is no new write and no merge-path touch).
 *
 * PROVIDER-SAFE: returns only the repo-relative file PATHS of certified deliveries — never raw code, never a
 * model self-report, never engine-vs-engine. A SELF signal. Flag-gated default-OFF => empty => byte-identical;
 * every DB touch is guarded (a DB-less caller degrades to empty, never throws).
 */
final class AtlasLoopDeliveryRecallService
{
    /**
     * Repo-relative paths of the most recent CERTIFIED merged deliveries in $module (its directory), excluding
     * $exclude (the file being edited). Empty when the flag is OFF / no DB / no module.
     *
     * @return list<string>
     */
    public function recall(string $module, string $exclude = '', int $limit = 5): array
    {
        if (! (bool) config('atlas.loop.brain_delivery_recall_enabled', false)) {
            return [];
        }
        if (! DatabaseTableAvailability::all(['atlas_loop_proposals'])) {
            return [];
        }
        $module = trim(rtrim($module, '/'));
        if ($module === '' || $module === '.') {
            return [];
        }
        $exclude = trim($exclude);

        try {
            $rows = DB::table('atlas_loop_proposals')
                ->where('merged_to_main', true)
                ->where('target_path', 'like', $module.'/%')
                ->orderByDesc('updated_at')
                ->limit(max(1, min(50, $limit)) * 3) // over-fetch; dedupe + exclude below
                ->get(['target_path']);
        } catch (Throwable) {
            return [];
        }

        $seen = [];
        foreach ($rows as $row) {
            $path = trim((string) $row->target_path);
            if ($path === '' || $path === $exclude || isset($seen[$path])) {
                continue;
            }
            // only direct children of the module dir (not deeper subtrees) — the tightest "neighbours" signal
            if (str_contains(substr($path, strlen($module) + 1), '/')) {
                continue;
            }
            $seen[$path] = true;
            if (count($seen) >= max(1, min(50, $limit))) {
                break;
            }
        }

        return array_keys($seen);
    }
}
