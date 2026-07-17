<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\QualityFoundry;

use App\Services\Ai\EngineeringKernel\CanonicalKernelPayload;
use App\Services\Ai\Support\AiValueNormalizer;
use InvalidArgumentException;

/**
 * Provider-free contract for binding a Software Twin prediction to a real
 * observed outcome and deriving conservative calibration facts.
 *
 * Persistence remains with the existing ledger/owner; this class never writes,
 * promotes a route, or grants claim authority.
 */
final class QualityFoundryPredictionCalibration
{
    public const SCHEMA = 'atlas.quality_foundry.prediction_calibration.v1';

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function issue(array $input): array
    {
        $predictionId = trim((string) ($input['prediction_id'] ?? ''));
        $workspaceId = trim((string) ($input['workspace_id'] ?? ''));
        $snapshotHash = $this->hash($input['snapshot_hash'] ?? null, 'prediction_snapshot_hash');
        $orderHash = $this->hash($input['order_hash'] ?? null, 'prediction_order_hash');
        $releaseHash = array_key_exists('release_hash', $input)
            ? $this->hash($input['release_hash'], 'prediction_release_hash')
            : null;
        $expected = trim((string) ($input['expected_observation'] ?? ''));
        $probability = $input['predicted_probability'] ?? null;
        $issuedAt = $this->timestamp($input['issued_at'] ?? null, 'prediction_issued_at_invalid');
        $window = is_array($input['observation_window'] ?? null) ? $input['observation_window'] : [];
        $from = $this->timestamp($window['from'] ?? null, 'prediction_window_from_invalid');
        $until = $this->timestamp($window['until'] ?? null, 'prediction_window_until_invalid');

        if ($predictionId === '' || $workspaceId === '' || $expected === '') {
            throw new InvalidArgumentException('prediction_context_required');
        }
        if (! is_int($probability) && ! is_float($probability) && ! is_numeric($probability)) {
            throw new InvalidArgumentException('prediction_probability_invalid');
        }
        $probability = AiValueNormalizer::finiteFloatOrNull($probability);
        if ($probability === null) {
            throw new InvalidArgumentException('prediction_probability_invalid');
        }
        if ($probability < 0.0 || $probability > 1.0) {
            throw new InvalidArgumentException('prediction_probability_out_of_range');
        }
        if ($from <= $issuedAt || $until <= $from) {
            throw new InvalidArgumentException('prediction_observation_window_invalid');
        }

        $body = [
            'schema' => self::SCHEMA,
            'prediction_id' => $predictionId,
            'workspace_id' => $workspaceId,
            'snapshot_hash' => $snapshotHash,
            'order_hash' => $orderHash,
            'release_hash' => $releaseHash,
            'expected_observation' => $expected,
            'predicted_probability' => round($probability, 6),
            'issued_at' => $issuedAt->format(DATE_ATOM),
            'observation_window' => [
                'from' => $from->format(DATE_ATOM),
                'until' => $until->format(DATE_ATOM),
            ],
            'status' => 'issued',
            'observation_state' => 'pending',
            'claim_eligible' => false,
        ];
        $body['receipt_hash'] = CanonicalKernelPayload::hash($body);

        return $body;
    }

    /** @param array<string,mixed> $receipt @param array<string,mixed> $observation @return array<string,mixed> */
    public function reconcile(array $receipt, array $observation): array
    {
        $blockers = [];
        if (! $this->receiptHashMatches($receipt)) {
            $blockers[] = 'prediction_receipt_hash_invalid';
        }
        if (($observation['snapshot_hash'] ?? null) !== ($receipt['snapshot_hash'] ?? null)) {
            $blockers[] = 'snapshot_hash_mismatch';
        }
        if (($observation['order_hash'] ?? null) !== ($receipt['order_hash'] ?? null)) {
            $blockers[] = 'order_hash_mismatch';
        }
        if (($receipt['release_hash'] ?? null) !== null && ($observation['release_hash'] ?? null) !== $receipt['release_hash']) {
            $blockers[] = 'release_hash_mismatch';
        }
        if (($observation['real'] ?? false) !== true) {
            $blockers[] = 'observed_outcome_not_real';
        }
        if (! is_bool($observation['observed'] ?? null)) {
            $blockers[] = 'observed_outcome_missing';
        }

        $observedAt = null;
        try {
            $observedAt = $this->timestamp($observation['observed_at'] ?? null, 'observed_at_invalid');
        } catch (InvalidArgumentException $e) {
            $blockers[] = $e->getMessage();
        }

        $base = [
            'schema' => self::SCHEMA,
            'prediction_id' => $receipt['prediction_id'] ?? null,
            'receipt_hash' => $receipt['receipt_hash'] ?? null,
            'snapshot_hash' => $receipt['snapshot_hash'] ?? null,
            'order_hash' => $receipt['order_hash'] ?? null,
            'release_hash' => $receipt['release_hash'] ?? null,
            'claim_eligible' => false,
        ];
        if ($blockers !== []) {
            return array_merge($base, ['status' => 'unresolved', 'blockers' => array_values(array_unique($blockers))]);
        }

        $from = new \DateTimeImmutable((string) ($receipt['observation_window']['from'] ?? ''));
        $until = new \DateTimeImmutable((string) ($receipt['observation_window']['until'] ?? ''));
        $late = $observedAt > $until;
        if ($observedAt < $from) {
            return array_merge($base, ['status' => 'unresolved', 'blockers' => ['observation_before_window']]);
        }

        $probability = AiValueNormalizer::finiteFloatOrNull($receipt['predicted_probability'] ?? null) ?? 0.0;
        $actual = $observation['observed'] ? 1.0 : 0.0;
        $error = round(($probability - $actual) ** 2, 6);

        return array_merge($base, [
            'status' => $late ? 'resolved_late' : 'resolved',
            'blockers' => $late ? ['observation_late'] : [],
            'observed' => $observation['observed'],
            'observed_at' => $observedAt->format(DATE_ATOM),
            'real_observation' => true,
            'calibration_error' => $error,
            'observation_hash' => CanonicalKernelPayload::hash([
                'receipt_hash' => $receipt['receipt_hash'] ?? null,
                'observed' => $observation['observed'],
                'observed_at' => $observedAt->format(DATE_ATOM),
            ]),
        ]);
    }

    /** @param list<array<string,mixed>> $records @return array<string,mixed> */
    public function calibrate(array $records): array
    {
        $contradictoryIds = [];
        foreach ($records as $record) {
            $predictionId = $record['prediction_id'] ?? null;
            if (! is_string($predictionId) || ! array_key_exists('observed', $record)) {
                continue;
            }
            $contradictoryIds[$predictionId][(string) (int) ((bool) $record['observed'])] = true;
        }
        $contradictoryIds = array_keys(array_filter($contradictoryIds, static fn (array $observations): bool => count($observations) > 1));
        $resolved = array_values(array_filter($records, static function (array $record) use ($contradictoryIds): bool {
            return in_array($record['status'] ?? null, ['resolved', 'resolved_late'], true)
                && isset($record['calibration_error'])
                && ! in_array($record['prediction_id'] ?? null, $contradictoryIds, true);
        }));
        $unresolved = count($records) - count($resolved);
        $late = count(array_filter($resolved, static fn (array $record): bool => ($record['status'] ?? null) === 'resolved_late'));
        $averageError = $resolved === [] ? null : round(array_sum(array_map(static fn (array $record): float => AiValueNormalizer::finiteFloatOrNull($record['calibration_error'] ?? null) ?? 0.0, $resolved)) / count($resolved), 6);
        $confidence = $resolved === [] ? 0.0 : round((count($resolved) / max(1, count($records))) * (1.0 - min(1.0, AiValueNormalizer::finiteFloatOrNull($averageError) ?? 0.0)), 6);
        $uncertainty = $resolved === [] ? 1.0 : round(1.0 / sqrt(count($resolved)), 6);
        $blockers = $contradictoryIds === [] ? [] : ['contradictory_observation'];

        return [
            'schema' => self::SCHEMA,
            'status' => $resolved === [] ? ($blockers === [] ? 'uncalibrated' : 'degraded') : ($unresolved > 0 || $late > 0 || $blockers !== [] ? 'degraded' : 'calibrated'),
            'total_count' => count($records),
            'resolved_count' => count($resolved),
            'unresolved_count' => $unresolved,
            'late_count' => $late,
            'average_calibration_error' => $averageError,
            'confidence' => $confidence,
            'confidence_interval' => [
                'lower' => round(max(0.0, $confidence - $uncertainty), 6),
                'upper' => round(min(1.0, $confidence + $uncertainty), 6),
            ],
            'blockers' => $blockers,
            'claim_eligible' => false,
        ];
    }

    private function hash(mixed $value, string $name): string
    {
        if (! is_string($value) || preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
            throw new InvalidArgumentException($name.'_invalid');
        }

        return $value;
    }

    private function timestamp(mixed $value, string $error): \DateTimeImmutable
    {
        if (! is_string($value) || trim($value) === '' || ($timestamp = date_create_immutable($value)) === false) {
            throw new InvalidArgumentException($error);
        }

        return $timestamp;
    }

    /** @param array<string,mixed> $receipt */
    private function receiptHashMatches(array $receipt): bool
    {
        $hash = $receipt['receipt_hash'] ?? null;
        if (! is_string($hash)) {
            return false;
        }
        unset($receipt['receipt_hash']);

        return hash_equals($hash, CanonicalKernelPayload::hash($receipt));
    }
}
