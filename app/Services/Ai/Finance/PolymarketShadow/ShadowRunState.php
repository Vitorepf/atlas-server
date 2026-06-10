<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\PolymarketShadow;

use Illuminate\Support\Facades\DB;

/**
 * Durable virtual-bankroll and day-P&L state for the shadow runtime, keyed by
 * UTC day so the daily halt gate has an unambiguous baseline across restarts.
 */
final class ShadowRunState
{
    private const TABLE = 'atlas_poly_shadow_state';

    public function __construct(private readonly float $defaultBankroll = 200.0) {}

    /**
     * @return array{bankroll: float, day: string, day_start_bankroll: float, day_pnl: float}
     */
    public function snapshot(?string $utcDay = null): array
    {
        $day = $utcDay ?? gmdate('Y-m-d');
        $stored = $this->read('bankroll_state');

        $bankroll = (float) ($stored['bankroll'] ?? $this->defaultBankroll);

        if (($stored['day'] ?? null) !== $day) {
            $state = [
                'bankroll' => $bankroll,
                'day' => $day,
                'day_start_bankroll' => $bankroll,
                'day_pnl' => 0.0,
            ];
            $this->write('bankroll_state', $state);

            return $state;
        }

        return [
            'bankroll' => $bankroll,
            'day' => $day,
            'day_start_bankroll' => (float) ($stored['day_start_bankroll'] ?? $bankroll),
            'day_pnl' => (float) ($stored['day_pnl'] ?? 0.0),
        ];
    }

    public function applyPnl(float $pnl, ?string $utcDay = null): array
    {
        $state = $this->snapshot($utcDay);
        $state['bankroll'] = round($state['bankroll'] + $pnl, 4);
        $state['day_pnl'] = round($state['day_pnl'] + $pnl, 4);
        $this->write('bankroll_state', $state);

        return $state;
    }

    private function read(string $key): array
    {
        $row = DB::table(self::TABLE)->where('key', $key)->first();
        if ($row === null) {
            return [];
        }

        $decoded = json_decode((string) $row->value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function write(string $key, array $value): void
    {
        DB::table(self::TABLE)->updateOrInsert(
            ['key' => $key],
            ['value' => json_encode($value), 'updated_at' => now(), 'created_at' => now()],
        );
    }
}
