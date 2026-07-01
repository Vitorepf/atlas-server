<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Governance;

use App\Services\Ai\SelfConstruction\VerificationCourt\AtlasVerificationCourtFalseGreenDetector;
use App\Services\Ai\SelfConstruction\VerificationCourt\AtlasVerificationCourtVerdictLedger;

/**
 * STRICTLY READ-ONLY. Turns the observe-mode verdict ledger (recorded by
 * {@see \App\Services\Ai\SelfConstruction\Governance\AtlasTaskCommitGovernanceChain} without ever
 * enforcing) into the numbers an operator needs to decide whether to arm enforce mode: what would
 * have been blocked, how often, why, and per risk level.
 *
 * This dossier NEVER flips any governance mode itself — arming stays behind the human gate the
 * constitution requires during bootstrap. It only compiles evidence.
 *
 * WOULD-BLOCK THRESHOLD — 10% (0.10), chosen deliberately conservative: observe mode has been
 * recording verdicts on deliveries that already landed successfully (they were never actually
 * blocked). If more than 1 in 10 of those historically-admitted deliveries would have been
 * blocked under enforce, that is not noise — it means enforce would wedge real workers on a
 * meaningful fraction of otherwise-healthy work, and the underlying blocker reasons need
 * investigation BEFORE arming, not after. A risk level with zero recorded verdicts is unproven
 * and defaults to hold (fail-closed) rather than a false "ready".
 */
final class AtlasTaskGovernanceObserveDossier
{
    public const SCHEMA = 'atlas.task_serving.governance_observe_dossier.v1';

    private const WOULD_BLOCK_THRESHOLD = 0.10;

    private const RISK_LEVEL_REASON_PREFIX = 'risk_level:';

    private const RISK_LEVEL_UNKNOWN = 'unknown';

    private const WOULD_BLOCK_VERDICTS = [
        AtlasVerificationCourtFalseGreenDetector::VERDICT_FAILED,
        AtlasVerificationCourtFalseGreenDetector::VERDICT_BLOCKED,
    ];

    private readonly AtlasVerificationCourtVerdictLedger $ledger;

    public function __construct(string $ledgerPath)
    {
        $this->ledger = new AtlasVerificationCourtVerdictLedger($ledgerPath);
    }

    /**
     * @return array<string,mixed>
     */
    public function compile(string $sinceIso = ''): array
    {
        $rows = $this->ledger->all();
        if ($sinceIso !== '') {
            $sinceTs = strtotime($sinceIso);
            $rows = array_values(array_filter($rows, static function (array $row) use ($sinceTs): bool {
                $ts = strtotime((string) ($row['decided_at'] ?? ''));

                return $ts !== false && $sinceTs !== false && $ts >= $sinceTs;
            }));
        }

        $totalVerdicts = count($rows);
        $wouldBlockRows = array_values(array_filter(
            $rows,
            static fn (array $row): bool => in_array((string) ($row['verdict'] ?? ''), self::WOULD_BLOCK_VERDICTS, true),
        ));
        $wouldBlockCount = count($wouldBlockRows);
        $wouldBlockRate = $totalVerdicts > 0 ? round($wouldBlockCount / $totalVerdicts, 4) : 0.0;

        $blockersByReason = $this->countReasons($wouldBlockRows);
        $verdictsByRisk = $this->groupByRisk($rows);
        $topBlockedTasks = $this->topBlockedTasks($wouldBlockRows);
        $armingRecommendation = $this->armingRecommendation($verdictsByRisk);

        return [
            'schema' => self::SCHEMA,
            'total_verdicts' => $totalVerdicts,
            'would_block_count' => $wouldBlockCount,
            'would_block_rate' => $wouldBlockRate,
            'blockers_by_reason' => $blockersByReason,
            'verdicts_by_risk' => $verdictsByRisk,
            'top_blocked_tasks' => $topBlockedTasks,
            'arming_recommendation' => $armingRecommendation,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return list<array{reason:string,count:int}>
     */
    private function countReasons(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            foreach ((array) ($row['reasons'] ?? []) as $reason) {
                $reason = (string) $reason;
                if ($reason === '' || str_starts_with($reason, self::RISK_LEVEL_REASON_PREFIX)) {
                    continue;
                }
                $counts[$reason] = ($counts[$reason] ?? 0) + 1;
            }
        }

        $result = [];
        foreach ($counts as $reason => $count) {
            $result[] = ['reason' => $reason, 'count' => $count];
        }
        usort($result, static fn (array $a, array $b): int => $b['count'] !== $a['count']
            ? $b['count'] <=> $a['count']
            : strcmp($a['reason'], $b['reason']));

        return $result;
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array<string, array{total:int, would_block:int, would_block_rate:float}>
     */
    private function groupByRisk(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $riskLevel = $this->extractRiskLevel($row);
            if (! isset($groups[$riskLevel])) {
                $groups[$riskLevel] = ['total' => 0, 'would_block' => 0];
            }
            $groups[$riskLevel]['total']++;
            if (in_array((string) ($row['verdict'] ?? ''), self::WOULD_BLOCK_VERDICTS, true)) {
                $groups[$riskLevel]['would_block']++;
            }
        }

        ksort($groups, SORT_STRING);

        $result = [];
        foreach ($groups as $riskLevel => $stats) {
            $result[$riskLevel] = [
                'total' => $stats['total'],
                'would_block' => $stats['would_block'],
                'would_block_rate' => $stats['total'] > 0 ? round($stats['would_block'] / $stats['total'], 4) : 0.0,
            ];
        }

        return $result;
    }

    /** @param  array<string,mixed>  $row */
    private function extractRiskLevel(array $row): string
    {
        foreach ((array) ($row['reasons'] ?? []) as $reason) {
            $reason = (string) $reason;
            if (str_starts_with($reason, self::RISK_LEVEL_REASON_PREFIX)) {
                $level = trim(substr($reason, strlen(self::RISK_LEVEL_REASON_PREFIX)));

                return $level !== '' ? $level : self::RISK_LEVEL_UNKNOWN;
            }
        }

        return self::RISK_LEVEL_UNKNOWN;
    }

    /**
     * @param  list<array<string,mixed>>  $wouldBlockRows
     * @return list<array{task_packet_id:string, would_block_count:int}>
     */
    private function topBlockedTasks(array $wouldBlockRows): array
    {
        $counts = [];
        foreach ($wouldBlockRows as $row) {
            $taskId = (string) ($row['task_packet_id'] ?? '');
            if ($taskId === '') {
                continue;
            }
            $counts[$taskId] = ($counts[$taskId] ?? 0) + 1;
        }

        $result = [];
        foreach ($counts as $taskId => $count) {
            $result[] = ['task_packet_id' => $taskId, 'would_block_count' => $count];
        }
        usort($result, static fn (array $a, array $b): int => $b['would_block_count'] !== $a['would_block_count']
            ? $b['would_block_count'] <=> $a['would_block_count']
            : strcmp($a['task_packet_id'], $b['task_packet_id']));

        return array_slice($result, 0, 10);
    }

    /**
     * @param  array<string, array{total:int, would_block:int, would_block_rate:float}>  $verdictsByRisk
     * @return array<string, array{status:string, would_block_count:int, total_verdicts:int, would_block_rate:float, reason:string}>
     */
    private function armingRecommendation(array $verdictsByRisk): array
    {
        $recommendation = [];
        foreach ($verdictsByRisk as $riskLevel => $stats) {
            if ($stats['total'] === 0) {
                $recommendation[$riskLevel] = [
                    'status' => 'hold',
                    'would_block_count' => 0,
                    'total_verdicts' => 0,
                    'would_block_rate' => 0.0,
                    'reason' => 'no_recorded_verdicts_for_this_risk_level',
                ];

                continue;
            }

            $ready = $stats['would_block_rate'] <= self::WOULD_BLOCK_THRESHOLD;
            $recommendation[$riskLevel] = [
                'status' => $ready ? 'ready' : 'hold',
                'would_block_count' => $stats['would_block'],
                'total_verdicts' => $stats['total'],
                'would_block_rate' => $stats['would_block_rate'],
                'reason' => $ready
                    ? sprintf('would_block_rate:%.4f<=%.2f', $stats['would_block_rate'], self::WOULD_BLOCK_THRESHOLD)
                    : sprintf('would_block_rate:%.4f>%.2f', $stats['would_block_rate'], self::WOULD_BLOCK_THRESHOLD),
            ];
        }

        return $recommendation;
    }
}
