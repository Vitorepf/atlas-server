<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognitive\Staleness;

final class StalenessActionLadder
{
    private const SCHEMA_VERSION = 'atlas.cognitive.staleness.action_ladder.v1';

    private const RUNG_LADDER = [
        'none',
        'reindex_recommended',
        'reindex_required',
        'block_until_reindex',
    ];

    private const SEVERITY_RUNGS = [
        'fresh' => 0,
        'aging' => 1,
        'stale' => 2,
        'critical' => 3,
    ];

    private const FAIL_SAFE_RUNG = 2;

    /**
     * @return array{
     *     schema_version: string,
     *     severity: string,
     *     high_risk: bool,
     *     base_rung: int,
     *     effective_rung: int,
     *     action: string,
     *     escalated: bool
     * }
     */
    public function action(string $severity, bool $highRisk): array
    {
        $baseRung = self::SEVERITY_RUNGS[$severity] ?? self::FAIL_SAFE_RUNG;

        $effectiveRung = min(3, $baseRung + ($highRisk ? 1 : 0));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'severity' => $severity,
            'high_risk' => $highRisk,
            'base_rung' => $baseRung,
            'effective_rung' => $effectiveRung,
            'action' => self::RUNG_LADDER[$effectiveRung],
            'escalated' => $effectiveRung > $baseRung,
        ];
    }
}
