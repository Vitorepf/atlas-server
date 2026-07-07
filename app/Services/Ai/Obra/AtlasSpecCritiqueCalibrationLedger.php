<?php

declare(strict_types=1);

namespace App\Services\Ai\Obra;

use Throwable;

/**
 * T4-S6 (Obra #17) — counterfactual close-replay that CALIBRATES the P3 critic
 * ({@see AtlasSpecCritiqueService}). The critic is deterministic but UNMEASURED: when it
 * flags `concerns_found` on an obra's spec, does that concern actually MATERIALIZE, or is
 * the critic over-flagging? This append-only ledger records the critic's BET at obra open
 * (verdict + concerns, keyed by obra id) and RESOLVES it at obra close (did the assembled
 * obra come out clean?), then builds a calibration curve over resolved obras.
 *
 *  - A flagged concern on an obra that then came out CLEAN is a REFUTATION-REVERSAL
 *    (the critic cried wolf) — the miscalibration the curve surfaces.
 *  - Only the critic's ACTUAL calls (concerns_found | governed) count as predictions;
 *    'empty' / 'no_brain_signal' are non-predictions and are excluded from the curve.
 *  - UNMEASURED (measured=false) until >= minObras resolved pairs — an honest "not enough
 *    data yet", never a fabricated score (pétrea: proibido fabricar).
 *
 * Append-only + fail-open: a calibration fault must NEVER break an obra. Reversible by
 * design (replay the ledger without an entry). Local disk only, zero provider spend.
 */
final class AtlasSpecCritiqueCalibrationLedger
{
    public const SCHEMA = 'atlas.obra.spec_critique_calibration.v1';

    private readonly string $root;

    public function __construct(?string $root = null)
    {
        $this->root = rtrim(
            (string) ($root ?? config('atlas.obra.spec_critique_calibration_root', storage_path('app/atlas/obra/spec-critique-calibration'))),
            '/'
        );
    }

    /**
     * Record the critic's BET for an obra (verdict + concerns), keyed by obra id.
     *
     * @param  array<string,mixed>  $critique  the {@see AtlasSpecCritiqueService::critique} result
     */
    public function recordBet(string $obraId, array $critique): void
    {
        $obraId = trim($obraId);
        if ($obraId === '') {
            return;
        }
        $concerns = array_values(array_map('strval', (array) ($critique['concerns'] ?? [])));
        $this->append([
            'kind' => 'bet',
            'obra_id' => $obraId,
            'verdict' => (string) ($critique['verdict'] ?? ''),
            'concerns' => $concerns,
            'concerns_count' => (int) ($critique['concerns_count'] ?? count($concerns)),
        ]);
    }

    /**
     * Resolve the bet at obra close: did the assembled obra come out CLEAN (certified)?
     */
    public function resolve(string $obraId, bool $outcomeClean, ?string $evidence = null): void
    {
        $obraId = trim($obraId);
        if ($obraId === '') {
            return;
        }
        $this->append([
            'kind' => 'outcome',
            'obra_id' => $obraId,
            'outcome_clean' => $outcomeClean,
            'evidence' => $evidence,
        ]);
    }

    /**
     * Calibration curve over resolved obras: each obra's bet paired with its outcome by
     * obra id (last of each wins). UNMEASURED until >= $minObras real-call pairs.
     *
     * @return array<string,mixed>
     */
    public function curve(int $minObras = 5): array
    {
        [$bets, $outcomes] = $this->replay();

        $pairs = [];
        foreach ($bets as $obraId => $bet) {
            if (! isset($outcomes[$obraId])) {
                continue;
            }
            $verdict = (string) ($bet['verdict'] ?? '');
            if (! in_array($verdict, ['concerns_found', 'governed'], true)) {
                continue; // only the critic's ACTUAL calls are predictions
            }
            $pairs[] = [
                'flagged' => $verdict === 'concerns_found',
                'clean' => (bool) ($outcomes[$obraId]['outcome_clean'] ?? false),
            ];
        }

        $n = count($pairs);
        if ($n < max(1, $minObras)) {
            return [
                'schema' => self::SCHEMA,
                'measured' => false,
                'resolved_pairs' => $n,
                'min_obras' => max(1, $minObras),
            ];
        }

        $tp = $fp = $tn = $fn = 0;
        $brierSum = 0.0;
        foreach ($pairs as $p) {
            $problem = ! $p['clean'];              // the event the critic predicts
            $pred = $p['flagged'] ? 1.0 : 0.0;
            $actual = $problem ? 1.0 : 0.0;
            $brierSum += ($pred - $actual) ** 2;
            if ($p['flagged'] && $problem) {
                $tp++;
            } elseif ($p['flagged']) {
                $fp++;              // flagged but clean = refutation-reversal (cried wolf)
            } elseif ($problem) {
                $fn++;              // not flagged but a problem = a miss
            } else {
                $tn++;              // not flagged and clean
            }
        }

        return [
            'schema' => self::SCHEMA,
            'measured' => true,
            'resolved_pairs' => $n,
            'flagged_precision' => ($tp + $fp) > 0 ? round($tp / ($tp + $fp), 3) : null,
            'flagged_recall' => ($tp + $fn) > 0 ? round($tp / ($tp + $fn), 3) : null,
            'refutation_reversals' => $fp,
            'brier' => round($brierSum / $n, 3), // 0 = perfect, 0.25 = coin-flip baseline
            'confusion' => ['tp' => $tp, 'fp' => $fp, 'tn' => $tn, 'fn' => $fn],
        ];
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function append(array $row): void
    {
        try {
            if (! is_dir($this->root)) {
                @mkdir($this->root, 0775, true);
            }
            $payload = ['schema' => self::SCHEMA, 'at' => gmdate('Y-m-d\TH:i:s\Z')] + $row;
            @file_put_contents(
                $this->path(),
                json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL,
                FILE_APPEND | LOCK_EX
            );
        } catch (Throwable) {
            // fail-open: calibration must never break an obra
        }
    }

    private function path(): string
    {
        return $this->root.'/ledger.jsonl';
    }

    /**
     * @return array{0: array<string,array<string,mixed>>, 1: array<string,array<string,mixed>>}
     */
    private function replay(): array
    {
        $bets = [];
        $outcomes = [];
        $path = $this->path();
        if (! is_file($path)) {
            return [$bets, $outcomes];
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $row = json_decode((string) $line, true);
            if (! is_array($row)) {
                continue;
            }
            $obraId = trim((string) ($row['obra_id'] ?? ''));
            if ($obraId === '') {
                continue;
            }
            $kind = (string) ($row['kind'] ?? '');
            if ($kind === 'bet') {
                $bets[$obraId] = $row;
            } elseif ($kind === 'outcome') {
                $outcomes[$obraId] = $row;
            }
        }

        return [$bets, $outcomes];
    }
}
