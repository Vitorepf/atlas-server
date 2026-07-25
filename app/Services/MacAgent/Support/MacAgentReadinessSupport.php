<?php

declare(strict_types=1);

namespace App\Services\MacAgent\Support;

/**
 * Pure Mac Agent readiness action projection (full-pass peel).
 */
final class MacAgentReadinessSupport
{
    /**
     * @param  list<array<string, mixed>>  $blockers
     * @param  list<array<string, mixed>>  $warnings
     * @return array{code: string, severity: string, message: string, command: mixed, kind: string}
     */
    public static function readinessPrimaryAction(
        array $blockers,
        array $warnings,
        bool $readyForBackgroundJobs,
        bool $readyForRemote,
    ): array {
        if ($readyForBackgroundJobs) {
            return [
                'code' => 'none',
                'severity' => 'info',
                'message' => 'Nenhuma acao pendente.',
                'command' => null,
                'kind' => 'ready',
            ];
        }

        $priority = [
            'mac_agent_not_migrated' => 10,
            'mac_agent_offline_or_sleeping' => 20,
            'mac_agent_launch_agent_not_ready' => 30,
            'caffeinate_unavailable' => 40,
            'power_helper_not_ready' => 50,
            'atlas_wake_not_confirmed' => 60,
            'battery_too_low_for_background_jobs' => 70,
        ];

        $items = collect($blockers)
            ->sortBy(fn (array $item): int => $priority[(string) ($item['code'] ?? '')] ?? 100)
            ->values();

        $primary = $items->first();
        if (! is_array($primary) && ! empty($warnings)) {
            $primary = $warnings[0];
        }

        if (! is_array($primary)) {
            return [
                'code' => 'refresh_status',
                'severity' => 'info',
                'message' => 'Atualize o status do Mac Agent para confirmar prontidao.',
                'command' => '/opt/homebrew/bin/php artisan atlas:host doctor --json',
                'kind' => 'refresh',
            ];
        }

        $code = (string) ($primary['code'] ?? 'unknown');

        return [
            'code' => $code,
            'severity' => (string) ($primary['severity'] ?? 'warning'),
            'message' => (string) ($primary['message'] ?? ''),
            'command' => $primary['action'] ?? null,
            'kind' => $readyForRemote ? 'background_setup' : 'remote_setup',
        ];
    }
}
