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
 * canário persiste em quality._canary no merge). L4-3 acrescenta o saldo de impacto
 * (quality._impact_receipt) como sinal consultivo: o guard passa a enxergar se o composto
 * está indo para alvo real ou farmando baixo valor, sem mudar o throttle já provado.
 * Throttle quando, com amostra mínima, a maioria dos canários executados falhou — sinal
 * de que fix-forward está perdendo a corrida. Puro leitor; fail-open.
 */
final class AtlasLoopNetDirectionGuard
{
    public const SCHEMA = 'atlas.ai.loop_net_direction_guard.v1';

    /** Janela de merges observada e amostra mínima de canários executados p/ decidir. */
    private const WINDOW = 10;

    private const MIN_RAN = 4;

    private const FAIL_RATE_THRESHOLD = 0.5;

    /**
     * @return array<string,mixed>
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
            'impact_receipts' => [
                'observed' => 0,
                'coverage_pct' => 0.0,
                'avg_impact_score' => 0.0,
                'generated_target_pct' => 0.0,
                'by_category' => [],
                'by_target_kind' => [],
            ],
        ];

        if (! DatabaseTableAvailability::has('atlas_loop_proposals')) {
            return $base; // fail-open: sem dados, merges livres
        }

        $impact = app(AtlasLoopImpactReceiptService::class)->aggregate(self::WINDOW);
        $base['impact_receipts'] = [
            'observed' => (int) ($impact['receipted_merges'] ?? 0),
            'coverage_pct' => (float) ($impact['coverage_pct'] ?? 0.0),
            'avg_impact_score' => (float) ($impact['avg_impact_score'] ?? 0.0),
            'generated_target_pct' => (float) ($impact['generated_target_pct'] ?? 0.0),
            'by_category' => is_array($impact['by_category'] ?? null) ? $impact['by_category'] : [],
            'by_target_kind' => is_array($impact['by_target_kind'] ?? null) ? $impact['by_target_kind'] : [],
        ];

        // RECENCY BOUND (deadlock fix): the window is the last N merges WITHIN a recent
        // time horizon, not the last N merges of all time. The guard is count-based, but
        // merged_to_main rows are PERMANENT history — without a time bound, a poisoned old
        // window (e.g. a prior campaign's red canaries) freezes forever, because the
        // throttle blocks the very merges that would refresh it: the loop produces certified
        // proposals it can NEVER commit. With the bound, stale breakage ages out; once there
        // are fewer than MIN_RAN recent canaries the existing min-sample gate fail-opens, so
        // merges resume and fresh canaries decide the direction honestly. Recent breakage
        // still throttles (safety intact) — it just can no longer deadlock on ancient data.
        $recencyHours = max(1, (int) config('atlas.loop.net_direction_recency_hours', 6));
        $base['recency_hours'] = $recencyHours;
        try {
            $recent = AtlasLoopProposal::query()
                ->where('merged_to_main', true)
                ->where('reviewed_at', '>=', now()->subHours($recencyHours))
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
