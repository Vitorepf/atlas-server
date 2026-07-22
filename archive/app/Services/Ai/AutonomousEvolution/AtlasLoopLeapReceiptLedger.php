<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

final class AtlasLoopLeapReceiptLedger
{
    /** @var array<string,array<string,mixed>> */
    private array $receipts = [];

    public function __construct(private readonly ?AtlasLoopOriginationOutcomeRecorder $outcomes = null)
    {
    }

    /**
     * @param  array<string,mixed>  $receipt
     * @return array{recorded:bool,outcome:string,reason:?string,receipt:?array<string,mixed>}
     */
    public function record(array $receipt, ?AtlasLoopAttemptLedger $attemptLedger = null): array
    {
        $leapId = trim((string) ($receipt['leap_id'] ?? data_get($receipt, 'ambition_leap.leap_id', '')));
        if ($leapId === '') {
            return ['recorded' => false, 'outcome' => 'invalid', 'reason' => 'missing_leap_id', 'receipt' => null];
        }
        if (isset($this->receipts[$leapId])) {
            return ['recorded' => false, 'outcome' => 'already_recorded', 'reason' => 'already_recorded', 'receipt' => $this->receipts[$leapId]];
        }

        $safe = $this->providerSafe($receipt);
        $stored = [
            'record_type' => 'LeapReceipt',
            'leap_id' => $leapId,
            'gap_id' => trim((string) ($safe['gap_id'] ?? data_get($safe, 'ambition_leap.gap_id', ''))),
            'ambition_leap' => (array) ($safe['ambition_leap'] ?? []),
            'risk_verdict' => (array) ($safe['risk_verdict'] ?? []),
            'task_packet_ids' => $this->taskPacketIds($safe),
            'outcome' => $this->resolveOutcome($safe, $attemptLedger),
            'outcome_sources' => $this->outcomeSources($safe, $attemptLedger),
        ];

        $this->receipts[$leapId] = $stored;

        return ['recorded' => true, 'outcome' => 'recorded', 'reason' => null, 'receipt' => $stored];
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return array_values($this->receipts);
    }

    /** @return list<array<string,mixed>> */
    public function byGapId(string $gapId): array
    {
        $gapId = trim($gapId);

        return array_values(array_filter($this->receipts, static fn (array $receipt): bool => ($receipt['gap_id'] ?? null) === $gapId));
    }

    /** @return list<array<string,mixed>> */
    public function byOutcome(string $outcome): array
    {
        $outcome = trim($outcome);

        return array_values(array_filter($this->receipts, static fn (array $receipt): bool => ($receipt['outcome'] ?? null) === $outcome));
    }

    /**
     * @param  array<string,mixed>  $value
     * @return array<string,mixed>
     */
    private function providerSafe(array $value): array
    {
        $safe = [];
        foreach ($value as $key => $item) {
            $key = (string) $key;
            if ($this->forbiddenKey($key)) {
                continue;
            }
            $safe[$key] = is_array($item) ? $this->providerSafe($item) : $item;
        }

        return $safe;
    }

    private function forbiddenKey(string $key): bool
    {
        $normalized = strtolower($key);

        return str_contains($normalized, 'provider_key')
            || str_contains($normalized, 'api_key')
            || str_contains($normalized, 'prompt')
            || str_contains($normalized, 'secret')
            || str_contains($normalized, 'trace');
    }

    /**
     * @param  array<string,mixed>  $receipt
     * @return list<string>
     */
    private function taskPacketIds(array $receipt): array
    {
        $ids = (array) ($receipt['task_packet_ids'] ?? []);
        if ($ids === [] && is_array($receipt['task_packets'] ?? null)) {
            $ids = array_column((array) $receipt['task_packets'], 'task_packet_id');
        }

        $ids = array_values(array_filter(array_map(
            static fn (mixed $id): string => trim((string) $id),
            $ids,
        ), static fn (string $id): bool => $id !== ''));
        sort($ids, SORT_STRING);

        return array_values(array_unique($ids));
    }

    /** @param array<string,mixed> $receipt */
    private function resolveOutcome(array $receipt, ?AtlasLoopAttemptLedger $attemptLedger): string
    {
        if ($attemptLedger !== null) {
            foreach ($attemptLedger->attempts() as $attempt) {
                if (($attempt['passed'] ?? false) === true) {
                    return 'certified';
                }
            }
            if ($attemptLedger->convergence()['thrashing']) {
                return 'abandoned';
            }
        }

        $targetPath = trim((string) ($receipt['target_path'] ?? data_get($receipt, 'ambition_leap.target_path', '')));
        $criteriaCount = count((array) ($receipt['acceptance_criteria'] ?? []));
        if ($targetPath !== '') {
            $recorder = $this->outcomes ?? new AtlasLoopOriginationOutcomeRecorder;
            $shapeToken = $recorder->shapeToken($targetPath, $criteriaCount);
            $history = $recorder->history($shapeToken);
            if (($history['accepted'] ?? 0) > 0) {
                return 'delivered';
            }
        }

        return 'pending';
    }

    /**
     * @param  array<string,mixed>  $receipt
     * @return array<string,mixed>
     */
    private function outcomeSources(array $receipt, ?AtlasLoopAttemptLedger $attemptLedger): array
    {
        $targetPath = trim((string) ($receipt['target_path'] ?? data_get($receipt, 'ambition_leap.target_path', '')));
        $criteriaCount = count((array) ($receipt['acceptance_criteria'] ?? []));
        $shapeToken = $targetPath !== ''
            ? ($this->outcomes ?? new AtlasLoopOriginationOutcomeRecorder)->shapeToken($targetPath, $criteriaCount)
            : null;

        return [
            'origination_shape_token' => $shapeToken,
            'attempt_rounds' => $attemptLedger?->rounds() ?? 0,
        ];
    }
}
