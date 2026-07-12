<?php

declare(strict_types=1);

namespace App\Services\Ai\AtlasDecide;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainModelCapabilityGapLedger;

/**
 * ASI-13 — Self-model read model consumed by the Decide layer.
 *
 * The Decide gets ONE canonical read-model of "what has this route actually
 * proven?" instead of the five disconnected fragments the plan enumerates
 * (capability manifest, causal self-model, gap ledger, live outcomes,
 * scorecard). This service is READ-ONLY over evidence — it never fabricates
 * capabilities from narrative.
 *
 * `basis` is DERIVED from the source of the signal (not the caller):
 *   - `proven`:   n>=MIN_PROVEN and rate>=0 with verified_basis in the
 *                 weighted set (server_verified / gates_passed)
 *   - `inferred`: has outcomes but n<MIN_PROVEN or majority unverified
 *   - `declared`: no outcomes, but the route is present in the capability
 *                 declaration set (caller-supplied `declared_routes`)
 *
 * `gaps_open` folds `AtlasExternalBrainModelCapabilityGapLedger` — this is
 * the caller the plan says the gap-ledger has been missing (it now has one).
 */
final class AtlasSelfModelReadModelService
{
    public const SCHEMA = 'atlas.decide.self_model.v1';

    public const BASIS_PROVEN = 'proven';

    public const BASIS_INFERRED = 'inferred';

    public const BASIS_DECLARED = 'declared';

    /** Minimum sample to claim `proven`. */
    public const MIN_PROVEN = 10;

    public function __construct(
        private readonly AtlasDecideLiveOutcomeFeedbackService $outcomes,
        private readonly ?AtlasExternalBrainModelCapabilityGapLedger $gapLedger = null,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function readForTaskCategory(string $taskCategory, array $options = []): array
    {
        $declaredRoutes = array_values(array_map('strval', (array) ($options['declared_routes'] ?? [])));
        $windowDays = max(1, (int) ($options['window_days'] ?? 30));
        $capabilityGaps = (array) ($options['capability_gaps'] ?? []);

        $rows = $this->readOutcomeRows($windowDays, $taskCategory);
        $byRoute = [];
        foreach ($rows as $row) {
            $provider = trim((string) ($row['provider'] ?? ''));
            $model = trim((string) ($row['model'] ?? ''));
            if ($provider === '') {
                continue;
            }
            $route = $model !== '' ? $provider.':'.$model : $provider;
            $bucket = $byRoute[$route] ?? [
                'n_total' => 0,
                'n_success' => 0,
                'n_proven' => 0,
                'n_weighted_basis' => 0,
                'certified_receipt_ids' => [],
            ];
            $bucket['n_total']++;
            $bucket['n_success'] += ($row['result'] ?? '') === 'success' ? 1 : 0;
            $bucket['n_proven'] += ($row['proven_real'] ?? false) === true ? 1 : 0;
            if (in_array((string) ($row['verified_basis'] ?? ''), AtlasDecideLiveOutcomeFeedbackService::VERIFIED_BASES_WEIGHTED, true)) {
                $bucket['n_weighted_basis']++;
            }
            $receiptId = (string) ($row['certified_receipt_id'] ?? '');
            if ($receiptId !== '') {
                $bucket['certified_receipt_ids'][$receiptId] = true;
            }
            $byRoute[$route] = $bucket;
        }

        // Emit one candidate per known route (measured + declared).
        $candidates = [];
        $seenRoutes = [];
        foreach ($byRoute as $route => $stats) {
            $rate = $stats['n_total'] > 0 ? $stats['n_success'] / $stats['n_total'] : 0.0;
            $provenRate = $stats['n_total'] > 0 ? $stats['n_proven'] / $stats['n_total'] : 0.0;
            $basis = self::BASIS_INFERRED;
            if ($stats['n_total'] >= self::MIN_PROVEN && $stats['n_weighted_basis'] >= self::MIN_PROVEN && $stats['n_proven'] > 0) {
                $basis = self::BASIS_PROVEN;
            }

            $candidates[] = [
                'route' => $route,
                'n' => $stats['n_total'],
                'n_proven' => $stats['n_proven'],
                'n_weighted_basis' => $stats['n_weighted_basis'],
                'success_rate' => round($rate, 4),
                'proven_rate' => round($provenRate, 4),
                'basis' => $basis,
                'certified_receipts' => array_values(array_keys($stats['certified_receipt_ids'])),
            ];
            $seenRoutes[$route] = true;
        }
        foreach ($declaredRoutes as $route) {
            if (isset($seenRoutes[$route])) {
                continue;
            }
            $candidates[] = [
                'route' => $route,
                'n' => 0,
                'n_proven' => 0,
                'n_weighted_basis' => 0,
                'success_rate' => 0.0,
                'proven_rate' => 0.0,
                'basis' => self::BASIS_DECLARED,
                'certified_receipts' => [],
            ];
        }

        // Rank: proven first (highest proven_rate), then inferred, then declared.
        usort($candidates, function (array $a, array $b): int {
            $order = [self::BASIS_PROVEN => 0, self::BASIS_INFERRED => 1, self::BASIS_DECLARED => 2];
            $cmp = ($order[$a['basis']] ?? 9) <=> ($order[$b['basis']] ?? 9);
            if ($cmp !== 0) {
                return $cmp;
            }

            return $b['proven_rate'] <=> $a['proven_rate'];
        });

        $gapReport = $this->gapLedger !== null && $capabilityGaps !== []
            ? $this->gapLedger->ledger($capabilityGaps)
            : ['schema' => AtlasExternalBrainModelCapabilityGapLedger::SCHEMA, 'gaps' => [], 'gap_count' => 0, 'capabilities' => []];

        // Deprioritize routes whose capability appears in the gap ledger.
        $gappedCapabilities = (array) $gapReport['capabilities'];
        foreach ($candidates as &$candidate) {
            $candidate['gap_open'] = in_array($candidate['route'], $gappedCapabilities, true);
        }
        unset($candidate);

        return [
            'schema' => self::SCHEMA,
            'task_category' => $taskCategory,
            'window_days' => $windowDays,
            'candidates' => $candidates,
            'gaps' => $gapReport,
            'summary' => [
                'total_routes' => count($candidates),
                'proven_routes' => count(array_filter($candidates, fn (array $c) => $c['basis'] === self::BASIS_PROVEN)),
                'inferred_routes' => count(array_filter($candidates, fn (array $c) => $c['basis'] === self::BASIS_INFERRED)),
                'declared_routes' => count(array_filter($candidates, fn (array $c) => $c['basis'] === self::BASIS_DECLARED)),
            ],
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function readOutcomeRows(int $windowDays, string $taskCategory): array
    {
        $path = $this->outcomes->logPath();
        if (! is_file($path) || ! is_readable($path)) {
            return [];
        }

        $cutoff = time() - ($windowDays * 86400);
        $rows = [];
        $fh = @fopen($path, 'r');
        if ($fh === false) {
            return [];
        }
        try {
            while (($line = fgets($fh)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $decoded = json_decode($line, true);
                if (! is_array($decoded)) {
                    continue;
                }
                if (($decoded['task_category'] ?? null) !== $taskCategory) {
                    continue;
                }
                $recordedAt = strtotime((string) ($decoded['recorded_at'] ?? ''));
                if ($recordedAt !== false && $recordedAt < $cutoff) {
                    continue;
                }
                $rows[] = $decoded;
            }
        } finally {
            fclose($fh);
        }

        return $rows;
    }
}
