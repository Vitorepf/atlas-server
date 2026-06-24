<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\SelfModel\Registry;

final class AtlasLoopMuscleRollbackController
{
    /**
     * @param  array{degraded?:bool,reason?:string}  $canarySignal
     * @return array{status:string,from?:?string,to?:?string,reason?:string}
     */
    public function rollback(AtlasLoopModelRegistry $registry, array $canarySignal): array
    {
        if (($canarySignal['degraded'] ?? false) !== true) {
            return ['status' => 'noop'];
        }

        if ($registry->previous() === null) {
            return [
                'status' => 'blocked',
                'reason' => 'no_previous_version',
            ];
        }

        $from = $registry->active();
        $result = $registry->revertToPrevious();

        return [
            'status' => 'rolled_back',
            'from' => $from,
            'to' => $result['active'] ?? $registry->active(),
            'reason' => (string) ($canarySignal['reason'] ?? ''),
        ];
    }
}
