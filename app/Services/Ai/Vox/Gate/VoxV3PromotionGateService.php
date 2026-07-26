<?php

declare(strict_types=1);

namespace App\Services\Ai\Vox\Gate;

use App\Services\Ai\Vox\Metrics\VoxMetricsService;
use Carbon\CarbonImmutable;

/**
 * Atlas Vox V3 promotion gate.
 *
 * Reads `VoxMetricsService::snapshot()` and decides whether the program
 * is ready for Vitor's manual review. The gate ONLY recommends — V4+
 * still requires explicit human approval per ADR 0003 (Lei 0.9).
 *
 * Hard gate criteria (`atlas-vox-operational-thinking-interface.md`):
 *   - 30 days OR 100 sessions of real usage
 *   - destructive_action_without_receipt == 0
 *   - confirmation_bypass_count == 0
 *   - raw_audio_persisted_count == 0
 *   - eclipse_test_success_count >= 3
 *
 * Status mapping:
 *   - any hard safety gate failing → `blocked` (cannot proceed)
 *   - core usage/quality not yet reached → `warming_up`
 *   - everything green → `ready_for_vitor_review`
 *
 * `explicit_vitor_approval_required` is ALWAYS true in the response.
 */
final class VoxV3PromotionGateService
{
    public const SCHEMA = 'atlas.vox.gate_v3.v1';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_WARMING_UP = 'warming_up';

    public const STATUS_READY = 'ready_for_vitor_review';

    public const MIN_REAL_USAGE_DAYS = 30;

    public const MIN_TOTAL_SESSIONS = 100;

    public const MIN_ECLIPSE_TEST_SUCCESS = 3;

    public function __construct(
        private readonly VoxMetricsService $metrics,
    ) {}

    /** @return array<string,mixed> */
    public function evaluate(): array
    {
        $snapshot = $this->metrics->snapshot();
        $gates = $this->gates($snapshot);
        $status = $this->resolveStatus($gates);

        $blockers = array_values(array_filter(
            $gates,
            static fn (array $g): bool => $g['hard'] && ! $g['passed']
        ));
        $warming = array_values(array_filter(
            $gates,
            static fn (array $g): bool => ! $g['hard'] && ! $g['passed']
        ));

        return [
            'schema' => self::SCHEMA,
            'status' => $status,
            'gates' => $gates,
            'blockers' => array_map(static fn (array $g) => $g['name'], $blockers),
            'warming_up' => array_map(static fn (array $g) => $g['name'], $warming),
            'next_actions' => $this->nextActions($status, $blockers, $warming, $snapshot),
            'explicit_vitor_approval_required' => true,
            'snapshot' => $snapshot,
            'generated_at' => CarbonImmutable::now('UTC')->toIso8601String(),
        ];
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @return list<array<string,mixed>>
     */
    private function gates(array $snapshot): array
    {
        $totalSessions = (int) data_get($snapshot, 'summary.total_sessions', 0);
        $realDays = (int) data_get($snapshot, 'summary.real_usage_days', 0);
        $usageMet = $totalSessions >= self::MIN_TOTAL_SESSIONS || $realDays >= self::MIN_REAL_USAGE_DAYS;

        $hg = $snapshot['hard_gates'] ?? [];

        return [
            [
                'name' => 'usage_window',
                'hard' => false,
                'passed' => $usageMet,
                'observed' => [
                    'total_sessions' => $totalSessions,
                    'real_usage_days' => $realDays,
                ],
                'target' => '>= '.self::MIN_REAL_USAGE_DAYS.' days OR >= '.self::MIN_TOTAL_SESSIONS.' sessions',
            ],
            [
                'name' => 'raw_audio_persisted_zero',
                'hard' => true,
                'passed' => (int) ($hg['raw_audio_persisted_count'] ?? 0) === 0,
                'observed' => (int) ($hg['raw_audio_persisted_count'] ?? 0),
                'target' => 0,
            ],
            [
                'name' => 'confirmation_bypass_zero',
                'hard' => true,
                'passed' => (int) ($hg['confirmation_bypass_count'] ?? 0) === 0,
                'observed' => (int) ($hg['confirmation_bypass_count'] ?? 0),
                'target' => 0,
            ],
            [
                'name' => 'destructive_action_without_receipt_zero',
                'hard' => true,
                'passed' => (int) ($hg['destructive_action_without_receipt'] ?? 0) === 0,
                'observed' => (int) ($hg['destructive_action_without_receipt'] ?? 0),
                'target' => 0,
            ],
            [
                'name' => 'eclipse_test_success_min',
                'hard' => true,
                'passed' => (int) ($hg['eclipse_test_success_count'] ?? 0) >= self::MIN_ECLIPSE_TEST_SUCCESS,
                'observed' => (int) ($hg['eclipse_test_success_count'] ?? 0),
                'target' => '>= '.self::MIN_ECLIPSE_TEST_SUCCESS,
            ],
        ];
    }

    /** @param list<array<string,mixed>> $gates */
    private function resolveStatus(array $gates): string
    {
        foreach ($gates as $gate) {
            if ($gate['hard'] && ! $gate['passed']) {
                return self::STATUS_BLOCKED;
            }
        }
        foreach ($gates as $gate) {
            if (! $gate['hard'] && ! $gate['passed']) {
                return self::STATUS_WARMING_UP;
            }
        }

        return self::STATUS_READY;
    }

    /**
     * @param  list<array<string,mixed>>  $blockers
     * @param  list<array<string,mixed>>  $warming
     * @param  array<string,mixed>  $snapshot
     * @return list<string>
     */
    private function nextActions(string $status, array $blockers, array $warming, array $snapshot): array
    {
        if ($status === self::STATUS_BLOCKED) {
            $actions = [];
            foreach ($blockers as $gate) {
                $actions[] = match ($gate['name']) {
                    'raw_audio_persisted_zero' => 'investigar imediatamente: áudio cru foi persistido em alguma sessão Vox',
                    'confirmation_bypass_zero' => 'investigar imediatamente: tentativa de bypass de confirmação detectada',
                    'destructive_action_without_receipt_zero' => 'investigar imediatamente: ação destrutiva sem receipt válido',
                    'eclipse_test_success_min' => 'testar eclipse pelo menos '.self::MIN_ECLIPSE_TEST_SUCCESS.' vezes (Esc-Esc rápido no overlay)',
                    default => 'investigar gate hard falhando: '.$gate['name'],
                };
            }

            return $actions;
        }

        if ($status === self::STATUS_WARMING_UP) {
            $actions = [];
            $totalSessions = (int) data_get($snapshot, 'summary.total_sessions', 0);
            $realDays = (int) data_get($snapshot, 'summary.real_usage_days', 0);
            foreach ($warming as $gate) {
                $actions[] = match ($gate['name']) {
                    'usage_window' => sprintf(
                        'aumentar uso real: %d/%d dias, %d/%d sessões — falta atingir 30 dias ou 100 sessões',
                        $realDays,
                        self::MIN_REAL_USAGE_DAYS,
                        $totalSessions,
                        self::MIN_TOTAL_SESSIONS,
                    ),
                    default => 'completar gate soft: '.$gate['name'],
                };
            }

            return $actions;
        }

        return [
            'enviar relatório de gate-v3 para revisão manual do Vitor',
            'apenas Vitor pode autorizar V4 — gate apenas recomenda',
        ];
    }
}
