<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

/**
 * Shared ACOS intelligence rollout ladder: offline → shadow → canary → default.
 *
 * Mirrors the token-economy observe/shadow/enforce vocabulary without inventing
 * a parallel flag system. Kill switches (`*_enabled=false`) always force offline.
 * Legacy boolean ON with mode still at offline is treated as default so existing
 * callers that only flip the kill switch keep working.
 */
final class AtlasIntelligenceRolloutMode
{
    public const OFFLINE = 'offline';

    public const SHADOW = 'shadow';

    public const CANARY = 'canary';

    public const DEFAULT = 'default';

    /** @var list<string> */
    public const MODES = [self::OFFLINE, self::SHADOW, self::CANARY, self::DEFAULT];

    /**
     * @param  array{enabled?:bool, mode?:string, canary_percent?:int}  $config
     * @param  array<string,mixed>  $context  workspace / flow_id / actor for canary bucketing
     */
    public static function resolve(array $config, array $context = []): string
    {
        $enabled = array_key_exists('enabled', $config) ? (bool) $config['enabled'] : true;
        if (! $enabled) {
            return self::OFFLINE;
        }

        $mode = strtolower(trim((string) ($config['mode'] ?? self::OFFLINE)));
        if (! in_array($mode, self::MODES, true)) {
            $mode = self::OFFLINE;
        }

        // Legacy: boolean kill-switch ON with mode left at offline means "fully on".
        if ($mode === self::OFFLINE && array_key_exists('enabled', $config) && $enabled) {
            $mode = self::DEFAULT;
        }

        if ($mode === self::CANARY && ! self::canaryAllowed(
            max(0, min(100, (int) ($config['canary_percent'] ?? 0))),
            $context,
        )) {
            return self::SHADOW;
        }

        return $mode;
    }

    public static function shouldExecuteLive(string $mode): bool
    {
        return in_array($mode, [self::CANARY, self::DEFAULT], true);
    }

    public static function shouldRecordShadow(string $mode): bool
    {
        return in_array($mode, [self::SHADOW, self::CANARY, self::DEFAULT], true);
    }

    /**
     * @param  array<string,mixed>  $context
     */
    public static function canaryAllowed(int $percent, array $context = []): bool
    {
        if ($percent <= 0) {
            return false;
        }
        if ($percent >= 100) {
            return true;
        }

        $seed = implode('|', [
            (string) ($context['workspace'] ?? ''),
            (string) ($context['flow_id'] ?? ''),
            (string) ($context['actor'] ?? ''),
            (string) ($context['scope_id'] ?? ''),
        ]);
        $bucket = hexdec(substr(hash('sha256', $seed === '|||' ? 'atlas-canary-default' : $seed), 0, 8)) % 100;

        return $bucket < $percent;
    }

    /**
     * @return array{mode:string, live:bool, shadow:bool, kill_switch_off:bool}
     */
    public static function receipt(string $mode, bool $enabled): array
    {
        return [
            'schema_version' => 'atlas.intelligence.rollout.v1',
            'mode' => $mode,
            'live' => self::shouldExecuteLive($mode),
            'shadow' => self::shouldRecordShadow($mode),
            'kill_switch_off' => ! $enabled,
        ];
    }
}
