<?php

namespace App\Services\Ai\Arena;

final class ArenaMeasurementControlService
{
    public function __construct(private readonly ArenaMeasurementStore $store = new ArenaMeasurementStore) {}

    /**
     * Pede parada idempotente da medição.
     *
     * Entradas ainda na fila terminam imediatamente. Entradas em execução
     * viram `stopping`; o worker reconhece o pedido entre casos e carimba o
     * terminal `stopped`. O recibo nunca afirma cancelamento do provider.
     *
     * @return array{status_code:int,payload:array<string,mixed>}
     */
    public function stop(string $measurementId, array $input): array
    {
        $actor = trim((string) ($input['operator_actor'] ?? $input['actor'] ?? ''));
        $reason = trim((string) ($input['operator_reason'] ?? $input['reason'] ?? ''));
        if ($actor === '') {
            return $this->error(422, 'operator_actor_required');
        }
        if ($reason === '') {
            return $this->error(422, 'operator_reason_required');
        }

        $result = $this->store->mutateQueuedRequestsAtomically(
            function (array $allEntries) use ($measurementId, $actor, $reason): array {
                $entries = array_values(array_filter(
                    $allEntries,
                    static fn (array $entry): bool => ($entry['measurement_id_public'] ?? null) === $measurementId
                ));
                if ($entries === []) {
                    return ['entries' => $allEntries, 'result' => null];
                }

                $existing = null;
                foreach ($entries as $entry) {
                    if (is_string($entry['stop_receipt_hash'] ?? null)) {
                        $existing = $entry;
                        break;
                    }
                }
                $requestedAt = is_array($existing)
                    ? (string) ($existing['stop_requested_at'] ?? now()->toIso8601String())
                    : now()->toIso8601String();
                $receiptHash = is_array($existing)
                    ? (string) $existing['stop_receipt_hash']
                    : hash('sha256', json_encode([
                        'measurement_id_public' => $measurementId,
                        'actor_hash' => hash('sha256', $actor),
                        'reason_hash' => hash('sha256', $reason),
                        'action' => 'stop',
                    ], JSON_UNESCAPED_SLASHES));

                $queued = $this->countWithStatus($entries, 'queued');
                $running = $this->countWithStatus($entries, 'running');
                $alreadyStopping = $this->countWithStatus($entries, 'stopping');
                $alreadyStopped = $this->countWithStatus($entries, 'stopped');
                $completed = $this->countWithStatus($entries, 'done');
                $failed = $this->countWithStatus($entries, 'failed');
                $accepted = is_array($existing)
                    ? ($existing['stop_accepted'] ?? true) === true
                    : $queued > 0 || $running > 0 || $alreadyStopping > 0;
                $receiptStatus = is_array($existing) && is_string($existing['stop_receipt_status'] ?? null)
                    ? (string) $existing['stop_receipt_status']
                    : match (true) {
                        $running > 0 || $alreadyStopping > 0 => 'stopping',
                        $queued > 0 || $alreadyStopped > 0 => 'stopped',
                        $failed > 0 => 'failed',
                        $completed > 0 => 'completed',
                        default => 'stopped',
                    };
                $queuedStopped = is_array($existing)
                    ? (int) ($existing['stop_queued_stopped'] ?? 0)
                    : $queued;
                $runningStopRequested = is_array($existing)
                    ? (int) ($existing['stop_running_requested'] ?? 0)
                    : $running;
                $alreadyStoppedCount = is_array($existing)
                    ? (int) ($existing['stop_already_stopped'] ?? 0)
                    : $alreadyStopped;
                $common = [
                    'stop_requested_at' => $requestedAt,
                    'stop_actor_hash' => is_array($existing)
                        ? (string) ($existing['stop_actor_hash'] ?? hash('sha256', $actor))
                        : hash('sha256', $actor),
                    'stop_reason_hash' => is_array($existing)
                        ? (string) ($existing['stop_reason_hash'] ?? hash('sha256', $reason))
                        : hash('sha256', $reason),
                    'stop_receipt_hash' => $receiptHash,
                    'stop_receipt_status' => $receiptStatus,
                    'stop_accepted' => $accepted,
                    'stop_queued_stopped' => $queuedStopped,
                    'stop_running_requested' => $runningStopRequested,
                    'stop_already_stopped' => $alreadyStoppedCount,
                ];

                foreach ($allEntries as &$entry) {
                    if (($entry['measurement_id_public'] ?? null) !== $measurementId) {
                        continue;
                    }
                    $storedStatus = (string) ($entry['status'] ?? '');
                    $entry = array_merge($entry, $common);
                    if ($storedStatus === 'queued') {
                        $entry = array_merge($entry, [
                            'status' => 'stopped',
                            'stopped_at' => $requestedAt,
                            'drained_at' => $requestedAt,
                            'terminal_receipt_hash' => $receiptHash,
                        ]);
                    } elseif ($storedStatus === 'running') {
                        $entry['status'] = 'stopping';
                    }
                }
                unset($entry);

                $hasInFlight = $receiptStatus === 'stopping';

                return [
                    'entries' => $allEntries,
                    'result' => [
                        'status_code' => $hasInFlight ? 202 : 200,
                        'payload' => [
                            'schema_version' => 'atlas.arena.stop_receipt.v1',
                            'measurement_id_public' => $measurementId,
                            'status' => $receiptStatus,
                            'accepted' => $accepted,
                            'receipt_hash' => $receiptHash,
                            'requested_at' => $requestedAt,
                            'queued_stopped' => $queuedStopped,
                            'running_stop_requested' => $runningStopRequested,
                            'already_stopped' => $alreadyStoppedCount,
                            'stops_after_current_case' => $hasInFlight,
                        ],
                    ],
                ];
            }
        );

        if (! is_array($result)) {
            return $this->error(404, 'measurement_not_found');
        }

        return $result;
    }

    /** @param list<array<string,mixed>> $entries */
    private function countWithStatus(array $entries, string $status): int
    {
        return count(array_filter(
            $entries,
            static fn (array $entry): bool => ($entry['status'] ?? null) === $status
        ));
    }

    /** @return array{status_code:int,payload:array<string,mixed>} */
    private function error(int $status, string $reason): array
    {
        return [
            'status_code' => $status,
            'payload' => [
                'schema_version' => 'atlas.arena.stop_error.v1',
                'status' => 'error',
                'reason' => $reason,
            ],
        ];
    }
}
