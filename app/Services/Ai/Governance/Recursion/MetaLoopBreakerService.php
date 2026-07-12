<?php

declare(strict_types=1);

namespace App\Services\Ai\Governance\Recursion;

final class MetaLoopBreakerService
{
    public const SCHEMA_VERSION = 'atlas.acos.rec06.meta_loop_breakers.v1';

    public const MIN_TOP_VOI = 0.01;

    /**
     * @param  array<string,array<string,mixed>>  $series
     * @return array<string,mixed>
     */
    public function evaluate(array $series): array
    {
        $readiness = $this->readiness($series);
        $breakers = $this->disarmedBreakers($readiness['reason']);

        if (! $readiness['ready']) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'slice' => 'REC-06',
                'status' => 'insufficient_signal',
                'armed' => false,
                'reason' => $readiness['reason'],
                'series_readiness' => $readiness['series'],
                'breakers' => $breakers,
                'actions' => [],
            ];
        }

        $breakers = [
            'low_voi' => $this->breaker(
                ((float) $series['voi']['top_voi']) < self::MIN_TOP_VOI,
                'top_voi_below_floor',
                ['top_voi' => (float) $series['voi']['top_voi'], 'floor' => self::MIN_TOP_VOI],
            ),
            'non_positive_r' => $this->breaker(
                ((float) $series['r']['r']) <= 0.0,
                'recursion_value_non_positive',
                ['r' => (float) $series['r']['r']],
            ),
            'operator_m_under_neutral' => $this->breaker(
                ((float) $series['m_operator']['m_operator']) < ((float) $series['m_operator']['m_neutral']),
                'operator_weighted_m_under_neutral',
                [
                    'm_operator' => (float) $series['m_operator']['m_operator'],
                    'm_neutral' => (float) $series['m_operator']['m_neutral'],
                    'divergence' => (float) ($series['m_operator']['divergence'] ?? 0.0),
                ],
            ),
        ];

        $armed = array_filter($breakers, static fn (array $breaker): bool => $breaker['state'] === 'armed');

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'slice' => 'REC-06',
            'status' => $armed === [] ? 'clear' : 'armed',
            'armed' => $armed !== [],
            'reason' => $armed === [] ? 'real_series_clear' : 'real_series_triggered',
            'series_readiness' => $readiness['series'],
            'breakers' => $breakers,
            'actions' => $armed === [] ? [] : ['pause_meta_loop', 'require_operator_review', 'keep_new_recursion_flips_disarmed'],
        ];
    }

    /**
     * @param  array<string,array<string,mixed>>  $series
     * @return array{ready:bool,reason:string,series:array<string,array<string,mixed>>}
     */
    private function readiness(array $series): array
    {
        $required = [
            'voi' => static fn (array $row): bool => ($row['status'] ?? null) === 'ok'
                && is_numeric($row['top_voi'] ?? null)
                && (int) ($row['denominator'] ?? 0) > 0,
            'r' => static fn (array $row): bool => ($row['status'] ?? null) === 'measured'
                && is_numeric($row['r'] ?? null)
                && is_numeric($row['denominator_effective'] ?? null),
            'm_operator' => static fn (array $row): bool => ($row['status'] ?? null) === 'measured'
                && is_numeric($row['m_operator'] ?? null)
                && is_numeric($row['m_neutral'] ?? null),
        ];

        $states = [];
        $missing = false;
        $unmeasured = false;

        foreach ($required as $name => $accept) {
            $row = $series[$name] ?? null;
            if (! is_array($row)) {
                $states[$name] = ['status' => 'missing'];
                $missing = true;

                continue;
            }

            $real = $accept($row);
            $states[$name] = [
                'status' => $real ? 'measured' : 'insufficient_signal',
                'source_status' => (string) ($row['status'] ?? 'unknown'),
            ];
            $unmeasured = $unmeasured || ! $real;
        }

        return [
            'ready' => ! $missing && ! $unmeasured,
            'reason' => $missing ? 'missing_real_series' : ($unmeasured ? 'series_not_measured' : 'real_series_measured'),
            'series' => $states,
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private function disarmedBreakers(string $reason): array
    {
        return [
            'low_voi' => ['state' => 'disarmed', 'basis' => 'insufficient_signal', 'reason' => $reason],
            'non_positive_r' => ['state' => 'disarmed', 'basis' => 'insufficient_signal', 'reason' => $reason],
            'operator_m_under_neutral' => ['state' => 'disarmed', 'basis' => 'insufficient_signal', 'reason' => $reason],
        ];
    }

    /** @param array<string,mixed> $evidence @return array<string,mixed> */
    private function breaker(bool $armed, string $reason, array $evidence): array
    {
        return [
            'state' => $armed ? 'armed' : 'clear',
            'basis' => 'real_series',
            'reason' => $reason,
            'evidence' => $evidence,
        ];
    }
}
