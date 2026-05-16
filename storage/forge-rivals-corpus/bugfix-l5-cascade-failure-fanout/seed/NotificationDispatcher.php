<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

final class NotificationDispatcher
{
    /**
     * BUG: dispatches channels serially; a single slow channel can
     * starve the rest. The arm must rewrite this method so each call
     * is wrapped in a per-channel ChannelGuard with a budget, and a
     * channel that exceeds its budget is reported as `timed_out`
     * without blocking the other channels.
     *
     * @param  array<string, callable(array):int>  $channels   each callable returns the elapsed millis
     * @param  array<string, ChannelGuard>          $guards     guard per channel id
     * @return array<string, array{status:string,elapsed_ms?:int,error?:string}>
     */
    public function dispatchAll(array $payload, array $channels, array $guards): array
    {
        $report = [];
        foreach ($channels as $name => $send) {
            $guard = $guards[$name] ?? null;
            if ($guard === null) {
                $report[$name] = ['status' => 'error', 'error' => 'guard_missing'];

                continue;
            }
            // BUG: no budget check, no breaker check.
            $elapsed = $send($payload);
            $report[$name] = ['status' => 'ok', 'elapsed_ms' => $elapsed];
        }

        return $report;
    }
}
