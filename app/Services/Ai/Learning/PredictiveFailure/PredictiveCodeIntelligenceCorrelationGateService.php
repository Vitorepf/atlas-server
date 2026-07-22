<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognitive\PredictiveFailure;

use App\Services\Engineering\AtlasCodeIntelligenceAutomaticGateService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * L6-11: certify that predictive code intelligence is grounded in outcomes.
 *
 * This gate does not mint predictions or backfill outcomes. It only joins the
 * existing code-intelligence gate with predictive-failure calibration evidence
 * and allows the claim when enough resolved outcomes, including real failure
 * signatures, support the prediction quality.
 */
final class PredictiveCodeIntelligenceCorrelationGateService
{
    public const SCHEMA_VERSION = 'atlas.cognitive.predictive_code_intelligence_correlation_gate.v1';

    public function __construct(
        private readonly AtlasCodeIntelligenceAutomaticGateService $codeGate,
        private readonly PredictiveFailureCalibrationMetricsService $metrics,
        private readonly PredictiveFailureRepository $repository,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function evaluate(array $options = []): array
    {
        $cfg = (array) config('atlas.cognition.predictive_code_intelligence_gate', []);
        $enabled = (bool) ($options['enabled'] ?? $cfg['enabled'] ?? true);
        $fixture = trim((string) ($options['fixture'] ?? 'live'));
        if ($fixture === '') {
            $fixture = 'live';
        }

        $domain = trim((string) ($options['domain'] ?? $cfg['domain'] ?? 'learning')) ?: 'learning';
        $windowDays = max(1, (int) ($options['window_days'] ?? $cfg['window_days'] ?? 60));
        $minOutcomes = max(1, (int) ($options['min_outcomes'] ?? $cfg['min_outcomes'] ?? 3));
        $minFailureSignatureOutcomes = max(1, (int) ($options['min_failure_signature_outcomes'] ?? $cfg['min_failure_signature_outcomes'] ?? 1));
        $maxAvgCalibrationError = max(0.0, min(1.0, (float) ($options['max_avg_calibration_error'] ?? $cfg['max_avg_calibration_error'] ?? 0.35)));
        $maxBrierScore = max(0.0, min(1.0, (float) ($options['max_brier_score'] ?? $cfg['max_brier_score'] ?? 0.25)));
        $autoRefresh = (bool) ($options['auto_refresh'] ?? $cfg['auto_refresh'] ?? false);

        if (! $enabled) {
            return $this->payload('disabled', false, $fixture, [], [], [], ['predictive_code_intelligence_gate_disabled'], [
                'domain' => $domain,
                'window_days' => $windowDays,
                'min_outcomes' => $minOutcomes,
                'min_failure_signature_outcomes' => $minFailureSignatureOutcomes,
                'max_avg_calibration_error' => $maxAvgCalibrationError,
                'max_brier_score' => $maxBrierScore,
                'auto_refresh' => $autoRefresh,
            ]);
        }

        [$codeGateReport, $metric, $history] = match ($fixture) {
            'mature' => [$this->readyCodeGate(), $this->metricFixture(5, 5, 0.12, 0.04), $this->historyFixture()],
            'zero-outcomes' => [$this->readyCodeGate(), $this->metricFixture(0, 0, null, null), []],
            'stale-code' => [$this->blockedCodeGate(), $this->metricFixture(5, 5, 0.12, 0.04), $this->historyFixture()],
            'live' => [
                is_array($options['code_gate_report'] ?? null) ? $options['code_gate_report'] : $this->codeGate->evaluate([
                    'mode' => 'readiness',
                    'strict_freshness' => true,
                    'auto_refresh' => $autoRefresh,
                    'max_age_minutes' => $cfg['max_code_index_age_minutes'] ?? 1440,
                    'run_context_type' => 'l6_11_predictive_code_intelligence_gate',
                ]),
                $this->normaliseMetric(is_array($options['metrics_report'] ?? null) ? $options['metrics_report'] : $this->metrics->compute($domain, $windowDays)),
                is_array($options['history'] ?? null) ? array_values($options['history']) : $this->repository->history($domain, $windowDays),
            ],
            default => [null, null, null],
        };

        if (! is_array($codeGateReport) || ! is_array($metric) || ! is_array($history)) {
            return $this->payload('blocked', false, $fixture, [], [], [], ['unsupported_fixture'], [
                'domain' => $domain,
                'window_days' => $windowDays,
            ]);
        }

        $assessment = $this->assess(
            $codeGateReport,
            $metric,
            $history,
            $minOutcomes,
            $minFailureSignatureOutcomes,
            $maxAvgCalibrationError,
            $maxBrierScore,
        );
        $blockers = $assessment['blockers'];
        $certified = $blockers === [];

        return $this->payload(
            $certified ? 'predictive_code_intelligence_correlated' : 'insufficient_predictive_failure_correlation',
            $certified,
            $fixture,
            $codeGateReport,
            $metric,
            $assessment,
            $blockers,
            [
                'domain' => $domain,
                'window_days' => $windowDays,
                'min_outcomes' => $minOutcomes,
                'min_failure_signature_outcomes' => $minFailureSignatureOutcomes,
                'max_avg_calibration_error' => $maxAvgCalibrationError,
                'max_brier_score' => $maxBrierScore,
                'auto_refresh' => $autoRefresh,
            ],
        );
    }

    /**
     * @param  array<string,mixed>  $codeGateReport
     * @param  array<string,mixed>  $metric
     * @param  list<array<string,mixed>>  $history
     * @return array<string,mixed>
     */
    private function assess(
        array $codeGateReport,
        array $metric,
        array $history,
        int $minOutcomes,
        int $minFailureSignatureOutcomes,
        float $maxAvgCalibrationError,
        float $maxBrierScore,
    ): array {
        $outcomes = (int) ($metric['outcomes_recorded'] ?? 0);
        $avgError = $metric['avg_calibration_error'] ?? null;
        $brier = $metric['brier_score'] ?? null;
        $signatureOutcomes = $this->failureSignatureOutcomeCount($history);
        $resolvedOutcomeRows = count(array_filter(
            $history,
            static fn (array $row): bool => ($row['prediction_calibration_error'] ?? null) !== null,
        ));

        $blockers = [];
        if ((string) ($codeGateReport['status'] ?? '') !== 'ready') {
            $blockers[] = 'code_intelligence_gate_not_ready';
        }
        foreach ((array) ($codeGateReport['blockers'] ?? []) as $blocker) {
            $blockers[] = 'code_gate:'.(string) $blocker;
        }
        if ((string) ($metric['status'] ?? '') !== 'computed') {
            $blockers[] = 'predictive_metrics_not_computed';
        }
        if ($outcomes < $minOutcomes) {
            $blockers[] = 'predictive_outcomes_below_floor';
        }
        if ($resolvedOutcomeRows < $outcomes) {
            $blockers[] = 'predictive_history_missing_resolved_rows';
        }
        if ($signatureOutcomes < $minFailureSignatureOutcomes) {
            $blockers[] = 'failure_signature_outcomes_below_floor';
        }
        if ($avgError === null) {
            $blockers[] = 'avg_calibration_error_missing';
        } elseif ((float) $avgError > $maxAvgCalibrationError) {
            $blockers[] = 'avg_calibration_error_above_floor';
        }
        if ($brier === null) {
            $blockers[] = 'brier_score_missing';
        } elseif ((float) $brier > $maxBrierScore) {
            $blockers[] = 'brier_score_above_floor';
        }

        return [
            'code_gate_status' => (string) ($codeGateReport['status'] ?? 'unknown'),
            'code_gate_blockers' => array_values((array) ($codeGateReport['blockers'] ?? [])),
            'code_symbols' => (int) data_get($codeGateReport, 'summary.symbol_count', 0),
            'code_drift_total' => (int) data_get($codeGateReport, 'metrics.drift_total', 0),
            'metrics_status' => (string) ($metric['status'] ?? 'unknown'),
            'total_insertions' => (int) ($metric['total_insertions'] ?? 0),
            'outcomes_recorded' => $outcomes,
            'resolved_history_rows' => $resolvedOutcomeRows,
            'failure_signature_outcomes' => $signatureOutcomes,
            'avg_calibration_error' => $avgError !== null ? round((float) $avgError, 4) : null,
            'brier_score' => $brier !== null ? round((float) $brier, 4) : null,
            'floors' => [
                'min_outcomes' => $minOutcomes,
                'min_failure_signature_outcomes' => $minFailureSignatureOutcomes,
                'max_avg_calibration_error' => $maxAvgCalibrationError,
                'max_brier_score' => $maxBrierScore,
            ],
            'blockers' => array_values(array_unique($blockers)),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $history
     */
    private function failureSignatureOutcomeCount(array $history): int
    {
        $count = 0;
        foreach ($history as $row) {
            if (($row['prediction_calibration_error'] ?? null) === null) {
                continue;
            }
            $signature = $row['actual_failure_signature'] ?? null;
            if (is_array($signature) && trim((string) ($signature['signature_key'] ?? '')) !== '') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  array<string,mixed>  $metric
     * @return array<string,mixed>
     */
    private function normaliseMetric(array $metric): array
    {
        $candidate = is_array($metric['metrics'] ?? null) ? $metric['metrics'] : $metric;

        return [
            'schema_version' => (string) ($candidate['schema_version'] ?? PredictiveFailureCalibrationMetricsService::SCHEMA_VERSION),
            'status' => (string) ($candidate['status'] ?? 'unknown'),
            'domain' => (string) ($candidate['domain'] ?? 'unknown'),
            'window_days' => (int) ($candidate['window_days'] ?? 0),
            'total_insertions' => (int) ($candidate['total_insertions'] ?? 0),
            'outcomes_recorded' => (int) ($candidate['outcomes_recorded'] ?? 0),
            'avg_calibration_error' => $candidate['avg_calibration_error'] ?? null,
            'brier_score' => $candidate['brier_score'] ?? null,
            'insertion_distribution_by_band' => is_array($candidate['insertion_distribution_by_band'] ?? null)
                ? $candidate['insertion_distribution_by_band']
                : [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function metricFixture(int $total, int $outcomes, ?float $avgError, ?float $brier): array
    {
        return [
            'schema_version' => PredictiveFailureCalibrationMetricsService::SCHEMA_VERSION,
            'status' => 'computed',
            'domain' => 'programming',
            'window_days' => 60,
            'total_insertions' => $total,
            'outcomes_recorded' => $outcomes,
            'avg_calibration_error' => $avgError,
            'brier_score' => $brier,
            'insertion_distribution_by_band' => ['sweet' => $total],
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function historyFixture(): array
    {
        return [
            $this->historyRow(0.82, 'failure', 'auth-expired.provider'),
            $this->historyRow(0.78, 'failure', 'decision.expired'),
            $this->historyRow(0.76, 'partial', 'queue.timeout'),
            $this->historyRow(0.22, 'success', null),
            $this->historyRow(0.18, 'success', null),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function historyRow(float $predicted, string $outcome, ?string $signature): array
    {
        $actual = match ($outcome) {
            'failure' => 1.0,
            'partial' => 0.5,
            default => 0.0,
        };

        return [
            'predicted_failure_probability' => $predicted,
            'outcome' => $outcome,
            'prediction_calibration_error' => round(abs($predicted - $actual), 3),
            'actual_failure_signature' => $signature !== null ? ['signature_key' => $signature] : [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function readyCodeGate(): array
    {
        return [
            'schema_version' => AtlasCodeIntelligenceAutomaticGateService::SCHEMA_VERSION,
            'status' => 'ready',
            'summary' => [
                'symbol_count' => 120000,
                'module_count' => 20,
            ],
            'metrics' => [
                'drift_total' => 0,
                'consumer_count' => 11,
                'consumer_missing_count' => 0,
            ],
            'blockers' => [],
            'claim_policy' => [
                'provider_calls_made' => false,
                'context_can_be_trusted_when_blocked' => false,
            ],
            'writes' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function blockedCodeGate(): array
    {
        $report = $this->readyCodeGate();
        $report['status'] = 'blocked';
        $report['blockers'] = ['audit_not_fresh', 'drift_detected'];
        $report['metrics']['drift_total'] = 9;

        return $report;
    }

    /**
     * @param  array<string,mixed>  $codeGateReport
     * @param  array<string,mixed>  $metric
     * @param  array<string,mixed>  $assessment
     * @param  list<string>  $blockers
     * @param  array<string,mixed>  $config
     * @return array<string,mixed>
     */
    private function payload(string $status, bool $certified, string $fixture, array $codeGateReport, array $metric, array $assessment, array $blockers, array $config): array
    {
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'status' => $status,
            'certified' => $certified,
            'completion_claim_allowed' => $certified,
            'fixture' => $fixture,
            'assessment' => $assessment,
            'code_gate' => [
                'schema_version' => (string) ($codeGateReport['schema_version'] ?? ''),
                'status' => (string) ($codeGateReport['status'] ?? 'unknown'),
                'blockers' => array_values((array) ($codeGateReport['blockers'] ?? [])),
                'summary' => (array) ($codeGateReport['summary'] ?? []),
                'metrics' => (array) ($codeGateReport['metrics'] ?? []),
                'writes' => (bool) ($codeGateReport['writes'] ?? false),
            ],
            'predictive_metrics' => $metric,
            'blockers' => $blockers,
            'config' => $config,
            'claim_policy' => [
                'provider_calls_made' => false,
                'provider_tokens_spent' => false,
                'does_not_mint_predictions' => true,
                'does_not_backfill_outcomes' => true,
                'does_not_mutate_code' => true,
                'context_can_be_trusted_when_blocked' => false,
                'completion_claim_requires_resolved_outcomes' => true,
            ],
        ];
        $payload['receipt_hash'] = 'sha256:'.hash('sha256', json_encode([
            'schema' => self::SCHEMA_VERSION,
            'status' => $status,
            'fixture' => $fixture,
            'assessment' => $assessment,
            'blockers' => $blockers,
        ], JSON_THROW_ON_ERROR));

        return $payload;
    }
}
