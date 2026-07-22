<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\SelfModel\Oracle\Domains;

use App\Services\Ai\AutonomousEvolution\SelfModel\Oracle\AtlasLoopModelOutcomeOracle;

final class AtlasLoopFinanceOutcomeOracle implements AtlasLoopModelOutcomeOracle
{
    public const SCHEMA = 'atlas.loop.model_outcome.finance.v1';

    public function domain(): string
    {
        return 'finance';
    }

    /**
     * @param  array<string,mixed>  $delivery
     * @return array{schema:string, score:float, grounded:bool, basis:string}
     */
    public function scoreOutcome(array $delivery): array
    {
        $hasReconciled = array_key_exists('reconciled', $delivery);
        $hasDiscrepancy = array_key_exists('discrepancy_cents', $delivery);

        if (! $hasReconciled || ! $hasDiscrepancy) {
            return ['schema' => self::SCHEMA, 'score' => 0.0, 'grounded' => false, 'basis' => 'ungrounded'];
        }

        $balanced = $delivery['reconciled'] === true && $delivery['discrepancy_cents'] === 0;

        return [
            'schema' => self::SCHEMA,
            'score' => $balanced ? 1.0 : 0.0,
            'grounded' => true,
            'basis' => $balanced ? 'balanced' : 'unbalanced',
        ];
    }
}
