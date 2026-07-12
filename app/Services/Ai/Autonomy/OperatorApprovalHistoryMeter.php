<?php

declare(strict_types=1);

namespace App\Services\Ai\Autonomy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class OperatorApprovalHistoryMeter
{
    public const SCHEMA_VERSION = 'atlas.operator_approval_history.v1';

    public const MEASURE_ID = 'operator.approval_history.v1';

    public const DENOMINATOR_MIN = 10;

    public const TTL_DAYS = 30;

    /**
     * @return array<string,mixed>
     */
    public function report(?int $days = null): array
    {
        if (! Schema::hasTable('ai_operator_approvals')) {
            return $this->empty('table_missing', $days);
        }

        $query = DB::table('ai_operator_approvals');
        if ($days !== null && $days > 0) {
            $query->where('created_at', '>=', now()->subDays($days));
        }

        $rows = $query
            ->orderBy('requested_action')
            ->orderBy('risk_level')
            ->orderBy('created_at')
            ->get();

        if ($rows->isEmpty()) {
            return $this->empty('no_approvals', $days);
        }

        $groups = [];
        foreach ($rows as $row) {
            $action = trim((string) ($row->requested_action ?? ''));
            $risk = strtolower(trim((string) ($row->risk_level ?? 'unknown'))) ?: 'unknown';
            if ($action === '') {
                continue;
            }

            $key = $action.'::'.$risk;
            $groups[$key] ??= [
                'action_class' => $action,
                'risk_level' => $risk,
                'asks' => 0,
                'approved' => 0,
                'denied' => 0,
                'expired' => 0,
                'reused' => 0,
            ];

            $groups[$key]['asks']++;
            $status = strtolower(trim((string) ($row->status ?? '')));
            $decision = strtolower(trim((string) ($row->operator_decision ?? '')));
            if (in_array($decision, ['approve', 'approved'], true) || in_array($status, ['approved', 'consumed'], true)) {
                $groups[$key]['approved']++;
            } elseif (in_array($decision, ['deny', 'denied', 'reject', 'rejected'], true) || in_array($status, ['denied', 'rejected'], true)) {
                $groups[$key]['denied']++;
            }
            if ($status === 'expired') {
                $groups[$key]['expired']++;
            }
            if ($status === 'reused') {
                $groups[$key]['reused']++;
            }
        }

        ksort($groups);
        $cells = array_values(array_map(function (array $cell): array {
            $cell['n'] = (int) $cell['asks'];
            $cell['denominator_min'] = self::DENOMINATOR_MIN;
            $cell['status'] = $cell['n'] >= self::DENOMINATOR_MIN ? 'ok' : 'insufficient_n';

            return $cell;
        }, $groups));

        $okCells = count(array_filter($cells, static fn (array $cell): bool => ($cell['status'] ?? null) === 'ok'));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $okCells > 0 && $okCells === count($cells) ? 'ok' : 'insufficient_signal',
            'measure_id' => self::MEASURE_ID,
            'series_id' => 'approval_by_action_class_risk.v1',
            'formula_version' => 'multn15_approval_history.v1',
            'denominator_min' => self::DENOMINATOR_MIN,
            'window_days' => $days,
            'denominator' => [
                'approvals' => $rows->count(),
                'cells' => count($cells),
            ],
            'cells' => $cells,
        ];
    }

    /** @return array<string,mixed> */
    public static function freezePayload(): array
    {
        return [
            'kind' => 'measure_freeze',
            'measure_id' => self::MEASURE_ID,
            'formula_version' => 'multn15_approval_history.v1',
            'formula' => 'Read-only counts by requested_action and risk_level over ai_operator_approvals: asks, approved, denied, expired, reused, n. Cells with n below denominator_min publish insufficient_n.',
            'thresholds' => [
                'denominator_min_per_cell' => self::DENOMINATOR_MIN,
                'future_auto_act_deny_rate_ceiling' => 0.05,
            ],
            'denominator_min' => self::DENOMINATOR_MIN,
            'ttl_days' => self::TTL_DAYS,
            'author_engine_id' => 'cursor-acos-max-multn15-02',
            'judge_engine_id' => 'codex-independent-multn15-02-judge',
            'reader_command' => 'atlas:operator-approval-history --json',
            'dual_read_required' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function empty(string $reason, ?int $days): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'insufficient_signal',
            'reason' => $reason,
            'measure_id' => self::MEASURE_ID,
            'series_id' => 'approval_by_action_class_risk.v1',
            'formula_version' => 'multn15_approval_history.v1',
            'denominator_min' => self::DENOMINATOR_MIN,
            'window_days' => $days,
            'denominator' => [
                'approvals' => 0,
                'cells' => 0,
            ],
            'cells' => [],
        ];
    }
}
