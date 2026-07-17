<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition;

use App\Services\Ai\Support\AiValueNormalizer;
use Carbon\CarbonImmutable;

/**
 * ACOS Excellence ROL-01 — read-only rollback trigger check for EVI-01 / WDG-01.
 *
 * Pre-declared objective conditions for future ACOS flips. The watchdog ALERTS;
 * the operator reverts via the named env overrides — no auto-revert in this slice.
 *
 * @see docs/engineering-knowledge-base/atlas-acos-excellence-10-10-plan-v1.md §ROL-01
 */
final class AtlasAcosRollbackTriggerCheckService
{
    public const SCHEMA_VERSION = 'atlas.acos.rollback_triggers.v1';

    /**
     * @return array<string,mixed>
     */
    public function check(?CarbonImmutable $asOf = null, ?string $simulateTriggerId = null): array
    {
        $asOf = ($asOf ?? CarbonImmutable::now())->utc();
        $enabled = (bool) config('atlas.acos.rollback_triggers.enabled', true);
        /** @var list<array<string,mixed>> $flips */
        $flips = AiValueNormalizer::arrayOrEmpty(config('atlas.acos.rollback_triggers.flips', []));

        $evaluations = [];
        $alerts = [];

        foreach ($flips as $flip) {
            if (! is_array($flip)) {
                continue;
            }
            $id = (string) ($flip['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $simulated = $simulateTriggerId !== null && $simulateTriggerId === $id;
            $evaluation = $simulated
                ? $this->simulatedEvaluation($flip, $asOf)
                : $this->evaluateFlip($flip, $asOf);

            $evaluations[] = $evaluation;
            if (($evaluation['fired'] ?? false) === true) {
                $alerts[] = [
                    'trigger_id' => $id,
                    'slices' => $flip['slices'] ?? [],
                    'rollback_action' => $flip['rollback_action'] ?? [],
                    'executor' => $flip['executor'] ?? 'watchdog_alert_operator_reverts',
                    'condition_kind' => data_get($flip, 'condition.kind'),
                    'simulated' => $simulated,
                ];
            }
        }

        $alert = $alerts !== [];
        $status = 'healthy';
        if (! $enabled) {
            $status = 'disabled';
        } elseif ($alert) {
            $status = 'alert';
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'checked_at' => $asOf->toIso8601String(),
            'status' => $status,
            'alert' => $alert,
            'alert_code' => $alert ? 'rollback_trigger_fired' : null,
            'enabled' => $enabled,
            'flip_count' => count($evaluations),
            'evaluations' => $evaluations,
            'alerts' => $alerts,
        ];
    }

    /**
     * @param  array<string,mixed>  $flip
     * @return array<string,mixed>
     */
    private function simulatedEvaluation(array $flip, CarbonImmutable $asOf): array
    {
        return [
            'trigger_id' => (string) ($flip['id'] ?? ''),
            'slices' => $flip['slices'] ?? [],
            'armed' => true,
            'fired' => true,
            'status' => 'simulated_fire',
            'reason' => 'simulated_condition',
            'condition_kind' => data_get($flip, 'condition.kind'),
            'rollback_action' => $flip['rollback_action'] ?? [],
            'executor' => $flip['executor'] ?? 'watchdog_alert_operator_reverts',
            'checked_at' => $asOf->toIso8601String(),
        ];
    }

    /**
     * @param  array<string,mixed>  $flip
     * @return array<string,mixed>
     */
    private function evaluateFlip(array $flip, CarbonImmutable $asOf): array
    {
        $id = (string) ($flip['id'] ?? '');
        $condition = AiValueNormalizer::arrayOrEmpty($flip['condition'] ?? null);
        $armed = $this->flipIsArmed($condition);

        return [
            'trigger_id' => $id,
            'slices' => $flip['slices'] ?? [],
            'armed' => $armed,
            'fired' => false,
            'status' => $armed ? 'monitoring' : 'pre_flip',
            'reason' => $armed ? 'condition_not_met' : 'flip_not_armed',
            'condition_kind' => $condition['kind'] ?? null,
            'rollback_action' => $flip['rollback_action'] ?? [],
            'executor' => $flip['executor'] ?? 'watchdog_alert_operator_reverts',
            'checked_at' => $asOf->toIso8601String(),
        ];
    }

    /**
     * @param  array<string,mixed>  $condition
     */
    private function flipIsArmed(array $condition): bool
    {
        $requires = $condition['requires_flip'] ?? null;
        if (! is_array($requires)) {
            return false;
        }

        if (isset($requires['any_env']) && is_array($requires['any_env'])) {
            foreach ($requires['any_env'] as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                if ($this->envMatches($entry)) {
                    return true;
                }
            }

            return false;
        }

        return $this->envMatches($requires);
    }

    /**
     * @param  array<string,mixed>  $entry
     */
    private function envMatches(array $entry): bool
    {
        $env = (string) ($entry['env'] ?? '');
        if ($env === '') {
            return false;
        }

        $expected = $entry['value'] ?? null;
        $actual = getenv($env);
        if ($actual === false) {
            return false;
        }

        if (is_bool($expected)) {
            return filter_var($actual, FILTER_VALIDATE_BOOLEAN) === $expected;
        }

        return (string) $actual === (string) $expected;
    }
}
