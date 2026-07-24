<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Control\Dispatch;

use App\Services\Ai\Aaeos\Control\AaeosExecutorMode;
use App\Services\Ai\Aaeos\Control\AaeosModeToDualCoreRoute;
use App\Services\Ai\DualCore\DualCoreRouteDecisionService;
use Throwable;

/**
 * Sole I/O chokepoint for live AAEOS dispatch.
 */
final class AaeosLiveDispatchGateway
{
    public const SCHEMA = 'atlas.aaeos.live_dispatch.v1';

    /** @var array<string, AaeosModeLiveDispatcher> */
    private array $dispatchers;

    public function __construct(
        ?AaeosModeLiveDispatcher $autonomos = null,
        ?AaeosModeLiveDispatcher $dev = null,
        ?AaeosModeLiveDispatcher $forge = null,
        private readonly ?DualCoreRouteDecisionService $dualCore = null,
    ) {
        $this->dispatchers = [
            AaeosExecutorMode::AUTONOMOS => $autonomos ?? new AutonomosLiveDispatcher,
            AaeosExecutorMode::DEV => $dev ?? new DevLiveDispatcher,
            AaeosExecutorMode::FORGE => $forge ?? new ForgeLiveDispatcher,
        ];
    }

    /**
     * @param  array<string,mixed>  $cyclePlan
     * @param  array<string,mixed>  $options  live, max_seeds, execute_provider, plan_only, scope…
     * @return array<string,mixed>
     */
    public function dispatch(string $mode, array $cyclePlan, array $options = []): array
    {
        $planOnly = (bool) ($options['plan_only'] ?? false);
        $live = ! $planOnly && (bool) ($options['live'] ?? $cyclePlan['live_dispatch'] ?? false);

        $dualcore = $this->recordDualCore($mode, $cyclePlan);

        if (! $live) {
            return [
                'schema' => self::SCHEMA,
                'status' => 'plan_only',
                'mode' => $mode,
                'live' => false,
                'effects' => [],
                'dualcore' => $dualcore,
                'provider_calls' => 0,
            ];
        }

        $dispatcher = $this->dispatchers[$mode] ?? null;
        if ($dispatcher === null) {
            return [
                'schema' => self::SCHEMA,
                'status' => 'dispatch_failed',
                'mode' => $mode,
                'live' => true,
                'effects' => [],
                'error' => 'unknown_mode_dispatcher',
                'dualcore' => $dualcore,
                'provider_calls' => 0,
            ];
        }

        try {
            $result = $dispatcher->liveDispatch($cyclePlan, $options);
        } catch (Throwable $e) {
            return [
                'schema' => self::SCHEMA,
                'status' => 'dispatch_failed',
                'mode' => $mode,
                'live' => true,
                'effects' => [],
                'error' => $e->getMessage(),
                'dualcore' => $dualcore,
                'provider_calls' => 0,
            ];
        }

        return array_merge([
            'schema' => self::SCHEMA,
            'mode' => $mode,
            'live' => true,
            'dualcore' => $dualcore,
        ], $result);
    }

    /**
     * @param  array<string,mixed>  $cyclePlan
     * @return array<string,mixed>
     */
    private function recordDualCore(string $mode, array $cyclePlan): array
    {
        $route = AaeosModeToDualCoreRoute::map($mode);
        $summary = (string) ($cyclePlan['objective']['objective'] ?? $cyclePlan['objective']['raw'] ?? 'aaeos_cycle');
        $summary = mb_substr(trim($summary) !== '' ? trim($summary) : 'aaeos_cycle', 0, 240);

        $payload = [
            'route' => $route,
            'reason' => 'aaeos_mode_selector',
            'intent_summary' => $summary,
            'recorded' => false,
        ];

        try {
            $svc = $this->dualCore;
            if ($svc === null && function_exists('app')) {
                try {
                    $svc = app(DualCoreRouteDecisionService::class);
                } catch (Throwable) {
                    $svc = null;
                }
            }
            if ($svc instanceof DualCoreRouteDecisionService) {
                $row = $svc->record($route, 'aaeos_control_plane_mode_select', $summary, [
                    'actor_type' => 'aaeos',
                    'routing_signals' => [
                        'aaeos_mode' => $mode,
                        'difficulty' => $cyclePlan['difficulty']['level'] ?? null,
                    ],
                    'operator_visible' => true,
                ]);
                $payload['recorded'] = true;
                $payload['id'] = $row->id ?? null;
                $payload['uuid'] = $row->uuid ?? null;
            }
        } catch (Throwable $e) {
            $payload['recorded'] = false;
            $payload['error'] = 'dualcore_record_fail_open';
            $payload['error_detail'] = $e->getMessage();
        }

        return $payload;
    }
}
