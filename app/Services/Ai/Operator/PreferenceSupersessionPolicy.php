<?php

declare(strict_types=1);

namespace App\Services\Ai\Operator;

final class PreferenceSupersessionPolicy
{
    public const SCHEMA_VERSION = 'atlas.operator.preference_supersession.v1';

    public const OVERRIDE_STREAK_FLOOR = 5;

    /**
     * @param  array<string,mixed>  $item
     * @param  list<string>  $events
     * @return array<string,mixed>
     */
    public static function evaluate(array $item, array $events): array
    {
        if (($item['safety_floor'] ?? false) === true) {
            return self::result('no_action', 'safety_floor_protected', $item, 0);
        }

        $streak = 0;
        foreach (array_reverse($events) as $event) {
            if ($event !== 'override') {
                break;
            }
            $streak++;
        }

        if ($streak >= self::OVERRIDE_STREAK_FLOOR) {
            return self::result('pause_old_preference', 'override_streak', $item, $streak);
        }

        return self::result('no_action', 'streak_below_floor', $item, $streak);
    }

    /**
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>
     */
    private static function result(string $action, string $basis, array $item, int $streak): array
    {
        $profileKey = (string) ($item['profile_key'] ?? 'unknown');

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'action' => $action,
            'basis' => $basis,
            'override_streak' => $streak,
            'reverse_handle' => $action === 'pause_old_preference' ? 'operator_preference_supersession:'.$profileKey : null,
            'source' => [
                'explicit_overrides_only' => true,
                'deletes_item' => false,
                'safety_floor_auto_demoted' => false,
            ],
        ];
    }
}
