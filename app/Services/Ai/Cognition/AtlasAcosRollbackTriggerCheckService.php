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

    public const ENABLED_CONFIG_KEY = 'atlas.acos.rollback_triggers.enabled';

    public const DEFAULT_ENABLED = true;

    public const FLIPS_CONFIG_KEY = 'atlas.acos.rollback_triggers.flips';

    public const STATUS_SIMULATED_FIRE = 'simulated_fire';

    public const STATUS_HEALTHY = 'healthy';

    public const STATUS_DISABLED = 'disabled';

    public const STATUS_ALERT = 'alert';

    public const FIELD_ENABLED = 'enabled';
    public const FIELD_SLICES = 'slices';
    public const FIELD_ROLLBACK_ACTION = 'rollback_action';
    public const FIELD_EXECUTOR = 'executor';
    public const FIELD_STATUS = 'status';
    public const FIELD_REASON = 'reason';
    public const FIELD_OK = 'ok';
    public const FIELD_TRIGGERS = 'triggers';
    public const FIELD_FIRED = 'fired';

    public const REASON_SIMULATED_CONDITION = 'simulated_condition';


    /**
     * @return array<string,mixed>
     */
    public function check(?CarbonImmutable $asOf = null, ?string $simulateTriggerId = null): array
    {
        $asOf = ($asOf ?? CarbonImmutable::now())->utc();
        $enabled = (AiValueNormalizer::boolOrNull(config(self::ENABLED_CONFIG_KEY, self::DEFAULT_ENABLED)) ?? self::DEFAULT_ENABLED);
        /** @var list<array<string,mixed>> $flips */
        $flips = AiValueNormalizer::arrayOrEmpty(config(self::FLIPS_CONFIG_KEY, []));

        $evaluations = [];
        $alerts = [];

        foreach ($flips as $flip) {
            if (! is_array($flip)) {
                continue;
            }
            $id = (AiValueNormalizer::trimmedStringOrNull($flip['id'] ?? null) ?? '');
            if ($id === '') {
                continue;
            }

            $simulated = $simulateTriggerId !== null && $simulateTriggerId === $id;
            $evaluation = $simulated
                ? $this->simulatedEvaluation($flip, $asOf)
                : $this->evaluateFlip($flip, $asOf);

            $evaluations[] = $evaluation;
            if (($evaluation[self::FIELD_FIRED] ?? false) === true) {
                $alerts[] = [
                    'trigger_id' => $id,
                    self::FIELD_SLICES => $flip[self::FIELD_SLICES] ?? [],
                    self::FIELD_ROLLBACK_ACTION => $flip[self::FIELD_ROLLBACK_ACTION] ?? [],
                    self::FIELD_EXECUTOR => $flip[self::FIELD_EXECUTOR] ?? 'watchdog_alert_operator_reverts',
                    'condition_kind' => data_get($flip, 'condition.kind'),
                    'simulated' => $simulated,
                ];
            }
        }

        $alert = $alerts !== [];
        $status = self::STATUS_HEALTHY;
        if (! $enabled) {
            $status = self::STATUS_DISABLED;
        } elseif ($alert) {
            $status = self::STATUS_ALERT;
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'checked_at' => $asOf->toIso8601String(),
            self::FIELD_STATUS => $status,
            self::STATUS_ALERT => $alert,
            'alert_code' => $alert ? 'rollback_trigger_fired' : null,
            self::FIELD_ENABLED => $enabled,
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
            'trigger_id' => (AiValueNormalizer::trimmedStringOrNull($flip['id'] ?? null) ?? ''),
            self::FIELD_SLICES => $flip[self::FIELD_SLICES] ?? [],
            'armed' => true,
            self::FIELD_FIRED => true,
            self::FIELD_STATUS => self::STATUS_SIMULATED_FIRE,
            self::FIELD_REASON => self::REASON_SIMULATED_CONDITION,
            'condition_kind' => data_get($flip, 'condition.kind'),
            self::FIELD_ROLLBACK_ACTION => $flip[self::FIELD_ROLLBACK_ACTION] ?? [],
            self::FIELD_EXECUTOR => $flip[self::FIELD_EXECUTOR] ?? 'watchdog_alert_operator_reverts',
            'checked_at' => $asOf->toIso8601String(),
        ];
    }

    /**
     * @param  array<string,mixed>  $flip
     * @return array<string,mixed>
     */
    private function evaluateFlip(array $flip, CarbonImmutable $asOf): array
    {
        $id = (AiValueNormalizer::trimmedStringOrNull($flip['id'] ?? null) ?? '');
        $condition = AiValueNormalizer::arrayOrEmpty($flip['condition'] ?? null);
        $armed = $this->flipIsArmed($condition);

        return [
            'trigger_id' => $id,
            self::FIELD_SLICES => $flip[self::FIELD_SLICES] ?? [],
            'armed' => $armed,
            self::FIELD_FIRED => false,
            self::FIELD_STATUS => $armed ? 'monitoring' : 'pre_flip',
            self::FIELD_REASON => $armed ? 'condition_not_met' : 'flip_not_armed',
            'condition_kind' => $condition['kind'] ?? null,
            self::FIELD_ROLLBACK_ACTION => $flip[self::FIELD_ROLLBACK_ACTION] ?? [],
            self::FIELD_EXECUTOR => $flip[self::FIELD_EXECUTOR] ?? 'watchdog_alert_operator_reverts',
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

        if (is_array($requires['any_env'] ?? null)) {
            foreach (AiValueNormalizer::arrayOrEmpty($requires['any_env']) as $entry) {
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
        $env = (AiValueNormalizer::trimmedStringOrNull($entry['env'] ?? null) ?? '');
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

        return (AiValueNormalizer::trimmedScalarStringOrNull($actual) ?? '') === (AiValueNormalizer::trimmedScalarStringOrNull($expected) ?? '');
    }
}
