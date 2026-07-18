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
    public const FIELD_ID = 'id';
    public const FIELD_ENV = 'env';
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
    public const FIELD_TRIGGER_ID = 'trigger_id';
    public const FIELD_CONDITION_KIND = 'condition_kind';
    public const FIELD_CHECKED_AT = 'checked_at';
    public const FIELD_ARMED = 'armed';
    public const FIELD_ANY_ENV = 'any_env';
    public const FIELD_ALERT_CODE = 'alert_code';

    public const REASON_SIMULATED_CONDITION = 'simulated_condition';
    public const FIELD_ALERTS = 'alerts';
    public const FIELD_CONDITION = 'condition';


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
            $id = (AiValueNormalizer::trimmedStringOrNull($flip[self::FIELD_ID] ?? null) ?? '');
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
                    self::FIELD_TRIGGER_ID => $id,
                    self::FIELD_SLICES => $flip[self::FIELD_SLICES] ?? [],
                    self::FIELD_ROLLBACK_ACTION => $flip[self::FIELD_ROLLBACK_ACTION] ?? [],
                    self::FIELD_EXECUTOR => $flip[self::FIELD_EXECUTOR] ?? 'watchdog_alert_operator_reverts',
                    self::FIELD_CONDITION_KIND => data_get($flip, 'condition.kind'),
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
            self::FIELD_CHECKED_AT => $asOf->toIso8601String(),
            self::FIELD_STATUS => $status,
            self::STATUS_ALERT => $alert,
            self::FIELD_ALERT_CODE => $alert ? 'rollback_trigger_fired' : null,
            self::FIELD_ENABLED => $enabled,
            'flip_count' => count($evaluations),
            'evaluations' => $evaluations,
            self::FIELD_ALERTS => $alerts,
        ];
    }

    /**
     * @param  array<string,mixed>  $flip
     * @return array<string,mixed>
     */
    private function simulatedEvaluation(array $flip, CarbonImmutable $asOf): array
    {
        return [
            self::FIELD_TRIGGER_ID => (AiValueNormalizer::trimmedStringOrNull($flip[self::FIELD_ID] ?? null) ?? ''),
            self::FIELD_SLICES => $flip[self::FIELD_SLICES] ?? [],
            self::FIELD_ARMED => true,
            self::FIELD_FIRED => true,
            self::FIELD_STATUS => self::STATUS_SIMULATED_FIRE,
            self::FIELD_REASON => self::REASON_SIMULATED_CONDITION,
            self::FIELD_CONDITION_KIND => data_get($flip, 'condition.kind'),
            self::FIELD_ROLLBACK_ACTION => $flip[self::FIELD_ROLLBACK_ACTION] ?? [],
            self::FIELD_EXECUTOR => $flip[self::FIELD_EXECUTOR] ?? 'watchdog_alert_operator_reverts',
            self::FIELD_CHECKED_AT => $asOf->toIso8601String(),
        ];
    }

    /**
     * @param  array<string,mixed>  $flip
     * @return array<string,mixed>
     */
    private function evaluateFlip(array $flip, CarbonImmutable $asOf): array
    {
        $id = (AiValueNormalizer::trimmedStringOrNull($flip[self::FIELD_ID] ?? null) ?? '');
        $condition = AiValueNormalizer::arrayOrEmpty($flip[self::FIELD_CONDITION] ?? null);
        $armed = $this->flipIsArmed($condition);

        return [
            self::FIELD_TRIGGER_ID => $id,
            self::FIELD_SLICES => $flip[self::FIELD_SLICES] ?? [],
            self::FIELD_ARMED => $armed,
            self::FIELD_FIRED => false,
            self::FIELD_STATUS => $armed ? 'monitoring' : 'pre_flip',
            self::FIELD_REASON => $armed ? 'condition_not_met' : 'flip_not_armed',
            self::FIELD_CONDITION_KIND => $condition['kind'] ?? null,
            self::FIELD_ROLLBACK_ACTION => $flip[self::FIELD_ROLLBACK_ACTION] ?? [],
            self::FIELD_EXECUTOR => $flip[self::FIELD_EXECUTOR] ?? 'watchdog_alert_operator_reverts',
            self::FIELD_CHECKED_AT => $asOf->toIso8601String(),
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

        if (is_array($requires[self::FIELD_ANY_ENV] ?? null)) {
            foreach (AiValueNormalizer::arrayOrEmpty($requires[self::FIELD_ANY_ENV]) as $entry) {
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
        $env = (AiValueNormalizer::trimmedStringOrNull($entry[self::FIELD_ENV] ?? null) ?? '');
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
