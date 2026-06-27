<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * COMPOUNDING substrate — the portfolio's compounding path was named "combine proven deliveries into a
 * frontier jump; learn from outcomes" but had no DATA: nothing summarized cross-cycle behavior so the
 * brain could SEE the trend (5 served in a row ⇒ compound the lift; 3 refused in a row ⇒ rotate path).
 * This digest is that summary: pure, read-only over the per-scope done-set ledger; emits a tail-window
 * shape (status distribution, recent success streak, action-frequency) the brain reads each cycle.
 *
 * NO-SCALAR (the anti-Goodhart contract this brain has carried since the reflection stream): every value
 * here is a COUNT or a STREAK INT — a fact directly readable from the ledger, never a learned score, never
 * a leverage rank. The brain reads the FACTS and decides what to do (compound? rotate?).
 *
 * Deterministic: given the same ledger state, returns the same digest (window size + tail order + tally
 * are all stable). Pétreo: the réu never edits the organ that summarizes its own track record — else it'd
 * shape its OWN apparent success streak to fake compounding leverage (the self-confirmation trap again).
 */
final class AtlasBrainCompoundingDigest
{
    public const SCHEMA = 'atlas.brain.compounding_digest.v1';

    public const DEFAULT_WINDOW = 50;

    /**
     * Summarize the last N cycle rows from a per-scope done-set ledger. Empty ledger ⇒ empty digest with
     * window:0 (the brain stays quiet rather than mis-reading "no signal" as "all good").
     *
     * @return array{schema:string, window:int, by_status:array<string,int>, success_streak:int, top_actions:list<array{action:string,count:int}>}
     */
    public function digest(AtlasBrainDoneSetLedger $ledger, int $window = self::DEFAULT_WINDOW): array
    {
        if ($window <= 0) {
            return ['schema' => self::SCHEMA, 'window' => 0, 'by_status' => [], 'success_streak' => 0, 'top_actions' => []];
        }

        $rows = $ledger->recentCycles($window);
        if ($rows === []) {
            return ['schema' => self::SCHEMA, 'window' => 0, 'by_status' => [], 'success_streak' => 0, 'top_actions' => []];
        }

        // Status distribution — every key the done-set records (served / refused / abstain / already_done /
        // forbidden_target / prepare_blocked / seeded / gated_brain_off / dry_run). Sorted alphabetically so
        // the digest is byte-stable for a given ledger state.
        $byStatus = [];
        foreach ($rows as $row) {
            $status = trim((string) ($row['status'] ?? ''));
            if ($status === '') {
                continue;
            }
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
        }
        ksort($byStatus);

        // Success streak — consecutive `served` (or `seeded`) rows from the tail. A long streak means
        // the brain is on a productive run and a compounding lift is on the table; zero means the next
        // cycle should probably rotate path (the L2 router output already says which).
        $streak = 0;
        for ($i = count($rows) - 1; $i >= 0; $i--) {
            $status = trim((string) ($rows[$i]['status'] ?? ''));
            if ($status === 'served' || $status === 'seeded') {
                $streak++;

                continue;
            }
            break;
        }

        // Top actions — bounded count (top-5 by frequency, ties broken alphabetically for stability).
        $actions = [];
        foreach ($rows as $row) {
            $action = trim((string) ($row['action'] ?? ''));
            if ($action === '') {
                continue;
            }
            $actions[$action] = ($actions[$action] ?? 0) + 1;
        }
        $topActions = [];
        foreach ($actions as $action => $count) {
            $topActions[] = ['action' => $action, 'count' => $count];
        }
        usort($topActions, static fn (array $a, array $b): int => [$b['count'], $a['action']] <=> [$a['count'], $b['action']]);
        $topActions = array_slice($topActions, 0, 5);

        return [
            'schema' => self::SCHEMA,
            'window' => count($rows),
            'by_status' => $byStatus,
            'success_streak' => $streak,
            'top_actions' => $topActions,
        ];
    }
}
