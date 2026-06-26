<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Campaign;

use App\Models\AtlasLoopCampaign;
use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\AtlasLoopTerritoryLadder;
use Throwable;

/**
 * Pure territory-climb escalation decision for the Atlas loop campaign
 * supervisor.
 *
 * Extracted from AtlasLoopCampaignSupervisor to reduce the god-class.
 * No DB writes, no provider calls, no I/O. The ladder and DB query are
 * passed in as parameters to preserve test seams.
 */
final class AtlasLoopTerritoryClimbDecider
{
    /**
     * @param  list<string>|array<int,string>  $current
     * @param  list<string>|array<int,string>  $rungs
     * @return array{widen:bool, reason:string, next_roots:?list<string>, violations:list<string>}
     */
    public static function territoryClimbDecision(array $current, array $rungs, string $campaignId, ?AtlasLoopTerritoryLadder $ladder = null, int $certifiedLeaps = 0): array
    {
        $current = self::normalizeRoots($current);
        $rungs = self::normalizeRoots($rungs);

        // GRADUAL-RELEASE DEFAULT: with no operator-defined rung, the ladder is a logged no-op.
        if ($rungs === []) {
            return ['widen' => false, 'reason' => 'no_rung_defined', 'next_roots' => null, 'violations' => []];
        }

        $nextRoots = array_values(array_unique(array_merge($current, $rungs)));

        $ladder = $ladder ?? new AtlasLoopTerritoryLadder;
        $verdict = $ladder->canPromote([
            'name' => 'campaign:'.$campaignId,
            'discovery_roots' => $nextRoots,
            // The REAL frozen-safety-file list (pétreo): every widened root MUST have one under it.
            'frozen_safety_files' => AtlasLoopHarnessGuard::FORBIDDEN_SELF_TARGETS,
            'robustness_cases' => 1,
            'certified_leaps' => $certifiedLeaps,
            'red_main_in_window' => 0,
            'compounding_trend_up' => true,
        ]);

        $promotable = (bool) ($verdict['promotable'] ?? false);

        return [
            'widen' => $promotable,
            'reason' => $promotable ? 'promotable' : 'blocked',
            'next_roots' => $promotable ? $nextRoots : null,
            'violations' => array_values((array) ($verdict['violations'] ?? [])),
        ];
    }

    /**
     * The campaign's certified-leaps count for the promotion rule. Every
     * persisted Atlas Loop proposal is forced to status certified_for_review
     * by the model + DB guard, so the campaign's rolling proposals_count IS
     * the certified-leaps count — no extra query. Best-effort: a read failure
     * yields 0 (conservatively short of the K threshold, so the promotion
     * rule simply does not fire).
     */
    public static function certifiedLeapsFor(string $campaignId): int
    {
        try {
            $campaign = AtlasLoopCampaign::query()->find($campaignId);

            return $campaign instanceof AtlasLoopCampaign ? max(0, (int) $campaign->proposals_count) : 0;
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @param  array<int,mixed>  $roots
     * @return list<string>
     */
    public static function normalizeRoots(array $roots): array
    {
        $out = [];
        foreach ($roots as $root) {
            if (! is_string($root)) {
                continue;
            }
            $root = trim(str_replace('\\', '/', $root));
            if ($root !== '') {
                $out[$root] = true;
            }
        }

        return array_keys($out);
    }
}