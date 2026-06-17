<?php

namespace App\Services\Ai\Scheduling;

use Carbon\CarbonImmutable;
use Cron\CronExpression;
use DateTimeInterface;
use InvalidArgumentException;
use Throwable;

class ScheduleParser
{
    private const MAX_INTERVAL_MINUTES = 366 * 24 * 60;

    /**
     * @return array{kind:string,schedule:string,expression?:string,run_at?:CarbonImmutable,interval_minutes?:int,next_run_at:CarbonImmutable}
     */
    public function parse(string $schedule, ?DateTimeInterface $now = null): array
    {
        $original = trim($schedule);
        if ($original === '') {
            throw new InvalidArgumentException('Schedule cannot be empty.');
        }

        $now = $now instanceof CarbonImmutable ? $now : CarbonImmutable::instance($now ?: now());
        $normalized = strtolower(preg_replace('/\s+/', ' ', $original) ?: $original);

        if ($interval = $this->parseInterval($normalized, prefixRequired: false)) {
            $runAt = $now->addMinutes($interval);

            return [
                'kind' => 'once',
                'schedule' => $original,
                'run_at' => $runAt,
                'interval_minutes' => $interval,
                'next_run_at' => $runAt,
            ];
        }

        if (str_starts_with($normalized, 'every ')) {
            $interval = $this->parseInterval(substr($normalized, 6), prefixRequired: true);
            if (! $interval) {
                throw new InvalidArgumentException("Invalid interval schedule [{$original}]. Use every 30m, every 2h or every 1d.");
            }

            return [
                'kind' => 'interval',
                'schedule' => $original,
                'interval_minutes' => $interval,
                'next_run_at' => $now->addMinutes($interval),
            ];
        }

        if ($this->looksLikeIsoDate($original)) {
            try {
                $runAt = CarbonImmutable::parse($original);
            } catch (Throwable $exception) {
                throw new InvalidArgumentException("Invalid ISO schedule [{$original}].", previous: $exception);
            }

            return [
                'kind' => 'once',
                'schedule' => $original,
                'run_at' => $runAt,
                'next_run_at' => $runAt,
            ];
        }

        if ($this->looksLikeCron($original)) {
            try {
                $cron = CronExpression::factory($original);
                $next = CarbonImmutable::instance($cron->getNextRunDate($now));
            } catch (Throwable $exception) {
                throw new InvalidArgumentException("Invalid cron schedule [{$original}].", previous: $exception);
            }

            return [
                'kind' => 'cron',
                'schedule' => $original,
                'expression' => $original,
                'next_run_at' => $next,
            ];
        }

        throw new InvalidArgumentException("Unsupported schedule [{$original}]. Use 30m, every 2h, a 5-field cron expression or an ISO timestamp.");
    }

    public function nextRunAt(array $parsed, ?DateTimeInterface $from = null): ?CarbonImmutable
    {
        $from = $from instanceof CarbonImmutable ? $from : CarbonImmutable::instance($from ?: now());

        return match ((string) ($parsed['kind'] ?? '')) {
            'once' => isset($parsed['run_at']) && $parsed['run_at'] instanceof DateTimeInterface
                ? CarbonImmutable::instance($parsed['run_at'])
                : null,
            'interval' => $from->addMinutes((int) ($parsed['interval_minutes'] ?? 0)),
            'cron' => CarbonImmutable::instance(CronExpression::factory((string) ($parsed['expression'] ?? $parsed['schedule'] ?? ''))->getNextRunDate($from)),
            default => throw new InvalidArgumentException('Unknown schedule kind: '.(string) ($parsed['kind'] ?? '')),
        };
    }

    private function parseInterval(string $value, bool $prefixRequired): ?int
    {
        $value = trim($value);
        $pattern = '/^(\d+)\s*(m|min|mins|minute|minutes|h|hr|hrs|hour|hours|d|day|days|w|week|weeks)$/';
        if (! preg_match($pattern, $value, $matches)) {
            return null;
        }

        $amount = (int) $matches[1];
        if ($amount <= 0) {
            throw new InvalidArgumentException('Schedule interval must be greater than zero.');
        }

        $unit = $matches[2];
        $minutes = match ($unit) {
            'm', 'min', 'mins', 'minute', 'minutes' => $amount,
            'h', 'hr', 'hrs', 'hour', 'hours' => $amount * 60,
            'd', 'day', 'days' => $amount * 24 * 60,
            'w', 'week', 'weeks' => $amount * 7 * 24 * 60,
            default => null,
        };

        if ($minutes === null || $minutes > self::MAX_INTERVAL_MINUTES) {
            throw new InvalidArgumentException('Schedule interval is outside the supported range.');
        }

        if (! $prefixRequired && preg_match('/^(minute|minutes|hour|hours|day|days|week|weeks)$/', $unit)) {
            return null;
        }

        return $minutes;
    }

    private function looksLikeIsoDate(string $value): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}[T\s]\d{2}:\d{2}/', trim($value));
    }

    private function looksLikeCron(string $value): bool
    {
        $parts = preg_split('/\s+/', trim($value)) ?: [];

        return count($parts) === 5;
    }
}
