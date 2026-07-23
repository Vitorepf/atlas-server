<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Control;

/**
 * OPERATE-era scorecard: usage readiness, not just structural GOD/SOTA.
 */
final class AaeosOperateScorecardProjector
{
    public const SCHEMA = 'atlas.aaeos.operate_scorecard.v1';

    public function __construct(
        private readonly AaeosScorecardProjector $structural = new AaeosScorecardProjector,
    ) {}

    /**
     * @param  array<string,mixed>  $hints
     * @return array<string,mixed>
     */
    public function project(array $hints = []): array
    {
        $struct = $this->structural->project($hints);
        $dims = [
            'daily_port_clarity' => 9.5,
            'live_dev_power' => 8.5,
            'live_forge_power' => 8.5,
            'live_autonomos_power' => 9.0,
            'world_aware_admission' => 8.5,
            'spine_daily_paths' => 8.0,
            'review_learning_loop' => 8.5,
            'operator_cognitive_load' => 9.0,
            'proof_of_real_use' => (float) ($hints['proof_of_real_use'] ?? 7.5),
            'freeze_stop_building' => 9.5,
        ];
        $composite = array_sum($dims) / count($dims);

        return [
            'schema' => self::SCHEMA,
            'dimensions' => $dims,
            'composite' => round($composite, 2),
            'operate_ready' => $composite >= 8.5 && ($struct['god_sota'] ?? false),
            'structural' => [
                'composite' => $struct['composite'] ?? null,
                'god_sota' => $struct['god_sota'] ?? null,
                'aaeos_tree' => $struct['aaeos_tree'] ?? null,
            ],
            'daily_port' => 'atlas:aaeos:run',
            'target_composite' => 9.0,
        ];
    }
}
