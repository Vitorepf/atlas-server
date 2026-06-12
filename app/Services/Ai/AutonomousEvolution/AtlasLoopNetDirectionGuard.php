<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Models\AtlasLoopProposal;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Throwable;

/**
 * L2-6 — Guard de saldo líquido (política merge-livre v2 do operador): merges continuam
 * LIVRES enquanto a direção líquida MEDIDA for positiva; se o sistema começar a se
 * afogar na própria quebra (composto negativo), o dial aperta SOZINHO (raise-only:
 * apertar é automático; afrouxar acontece quando a janela medida melhora — dado, não
 * decisão do sistema).
 *
 * Medição: a taxa de falha do canário sobre a janela dos últimos merges (o resultado do
 * canário persiste em quality._canary no merge). Throttle quando, com amostra mínima,
 * a maioria dos canários executados falhou — sinal de que fix-forward está perdendo a
 * corrida. Puro leitor; fail-open (sem dados/tabela => sem throttle, merges livres).
 */
final class AtlasLoopNetDirectionGuard
{
    public const SCHEMA = 'atlas.ai.loop_net_direction_guard.v1';

    /** Janela de merges observada e amostra mínima de canários executados p/ decidir. */
    private const WINDOW = 10;

    private const MIN_RAN = 4;

    private const FAIL_RATE_THRESHOLD = 0.5;

    /**
     * @return array{schema_version:string, throttled:bool, reason:?string, window:int, canaries_ran:int, canaries_failed:int, fail_rate:?float}
     */
    public function verdict(): array
    {
        $base = [
            'schema_version' => self::SCHEMA,
            'throttled' => false,
            'reason' => null,
            'window' => self::WINDOW,
            'canaries_ran' => 0,
            'canaries_failed' => 0,
            'fail_rate' => null,
        ];

        if (! DatabaseTableAvailability::has('atlas_loop_proposals')) {
            return $base; // fail-open: sem dados, merges livres
        }

        try {
            $recent = AtlasLoopProposal::query()
                ->where('merged_to_main', true)
                ->orderByDesc('reviewed_at')
                ->limit(self::WINDOW)
                ->get();
        } catch (Throwable) {
            return $base;
        }

        $ran = 0;
        $failed = 0;
        foreach ($recent as $proposal) {
            $canary = is_array($proposal->quality) ? ($proposal->quality['_canary'] ?? null) : null;
            if (! is_array($canary) || ($canary['ran'] ?? false) !== true) {
                continue;
            }
            $ran++;
            if (($canary['passed'] ?? null) === false) {
                $failed++;
            }
        }

        $base['canaries_ran'] = $ran;
        $base['canaries_failed'] = $failed;
        if ($ran >= self::MIN_RAN) {
            $rate = $failed / $ran;
            $base['fail_rate'] = round($rate, 3);
            if ($rate >= self::FAIL_RATE_THRESHOLD) {
                $base['throttled'] = true;
                $base['reason'] = "canary_fail_rate {$failed}/{$ran} >= 50% na janela — fix-forward perdendo a corrida";
            }
        }

        return $base;
    }
}
