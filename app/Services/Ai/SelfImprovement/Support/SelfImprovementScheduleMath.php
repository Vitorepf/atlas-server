<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfImprovement\Support;

use App\Services\Ai\SelfImprovement\AtlasSelfImprovementOrchestrator;
use App\Support\CanonicalValue;
use Carbon\CarbonImmutable;

/**
 * Pure schedule math for Self-Improvement schedule plan (full-pass peel).
 *
 * No config(), no Eloquent, no service container. Callers inject config values
 * and optional clock (`$now`) so next-run math stays deterministic in tests.
 */
final class SelfImprovementScheduleMath
{
    /**
     * @param  list<string>|null  $supportedFlows  full flow ids (`self_improvement.*`); defaults to orchestrator catalog
     */
    public static function normalizeFlow(string $flow, ?array $supportedFlows = null): ?string
    {
        $flow = trim($flow);
        if ($flow === '') {
            return null;
        }

        if (str_starts_with($flow, 'self_improvement.')) {
            $flow = substr($flow, strlen('self_improvement.'));
        }

        $supported = $supportedFlows ?? AtlasSelfImprovementOrchestrator::SUPPORTED_FLOWS;

        return in_array('self_improvement.'.$flow, $supported, true)
            ? $flow
            : null;
    }

    public static function cadenceForFlow(string $flow): string
    {
        return $flow === 'weekly_architecture_audit' ? 'weekly' : 'daily';
    }

    public static function weekDayForFlow(string $flow): ?int
    {
        return self::cadenceForFlow($flow) === 'weekly' ? 1 : null;
    }

    /**
     * @param  array<int,array{cadence:string}>  $commands
     * @return array<string,int>
     */
    public static function cadenceCounts(array $commands): array
    {
        return collect($commands)
            ->countBy(fn (array $command): string => $command['cadence'])
            ->all();
    }

    public static function isValidTime(string $time): bool
    {
        return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time) === 1;
    }

    public static function isValidTimezone(string $timezone): bool
    {
        return in_array($timezone, timezone_identifiers_list(), true);
    }

    /**
     * @param  array{enabled:bool,time:string,timezone:string,count:int}  $plan
     */
    public static function isSchedulable(array $plan): bool
    {
        return (bool) ($plan['enabled'] ?? false)
            && (int) ($plan['count'] ?? 0) > 0
            && self::isValidTime((string) ($plan['time'] ?? ''))
            && self::isValidTimezone((string) ($plan['timezone'] ?? ''));
    }

    /**
     * Next run instant as JSON datetime string, or null when time/timezone invalid.
     * Inject `$now` for deterministic unit tests; when null, uses CarbonImmutable::now($timezone).
     */
    public static function nextRunAtForCommand(
        string $time,
        string $timezone,
        string $cadence,
        ?int $weekDay,
        ?CarbonImmutable $now = null,
    ): ?string {
        if (! self::isValidTime($time) || ! self::isValidTimezone($timezone)) {
            return null;
        }

        [$hour, $minute] = array_map('intval', explode(':', $time));
        $now ??= CarbonImmutable::now($timezone);
        if ($now->timezoneName !== $timezone) {
            $now = $now->setTimezone($timezone);
        }
        $next = $now->setTime($hour, $minute);

        if ($cadence === 'weekly') {
            $targetWeekDay = max(0, min(6, (int) ($weekDay ?? 1)));

            while ((int) $next->dayOfWeek !== $targetWeekDay || $next->lessThanOrEqualTo($now)) {
                $next = $next->addDay();
            }

            return $next->toJSON();
        }

        if ($next->lessThanOrEqualTo($now)) {
            $next = $next->addDay();
        }

        return $next->toJSON();
    }

    /**
     * @param  array<int,array<string,mixed>>  $commands
     * @return array<int,array<string,mixed>>
     */
    public static function hashableCommands(array $commands): array
    {
        return array_map(static function (array $command): array {
            unset($command['next_run_at']);

            return $command;
        }, $commands);
    }

    /**
     * Stable sha256 plan hash (excludes ephemeral next_run_at on commands).
     *
     * @param  array<string,mixed>  $plan
     */
    public static function planHash(array $plan): string
    {
        return hash('sha256', json_encode(CanonicalValue::canonicalize([
            'schema_version' => $plan['schema_version'] ?? null,
            'enabled' => $plan['enabled'] ?? null,
            'schedulable' => $plan['schedulable'] ?? null,
            'scheduler_registration' => $plan['scheduler_registration'] ?? null,
            'time' => $plan['time'] ?? null,
            'timezone' => $plan['timezone'] ?? null,
            'configured_flows' => $plan['configured_flows'] ?? [],
            'invalid_flows' => $plan['invalid_flows'] ?? [],
            'defaulted' => $plan['defaulted'] ?? false,
            'flows' => $plan['flows'] ?? [],
            'commands' => self::hashableCommands((array) ($plan['commands'] ?? [])),
            'count' => $plan['count'] ?? 0,
            'cadence_counts' => $plan['cadence_counts'] ?? [],
            'emit' => $plan['emit'] ?? false,
            'health' => [
                'status' => data_get($plan, 'health.status'),
                'issues' => data_get($plan, 'health.issues', []),
            ],
        ]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
