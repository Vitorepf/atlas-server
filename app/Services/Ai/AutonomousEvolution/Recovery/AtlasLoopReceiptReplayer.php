<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Recovery;

use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;
use InvalidArgumentException;
use RuntimeException;

final class AtlasLoopReceiptReplayer
{
    /**
     * @return array{
     *     state: array<string, array<string, mixed>>,
     *     audit_trail: list<array<string, mixed>>
     * }
     */
    public function replay(string $ledgerPath, ?int $upToTimestamp = null): array
    {
        if ($ledgerPath === '' || ! is_file($ledgerPath)) {
            throw new InvalidArgumentException("Ledger path \"{$ledgerPath}\" does not exist.");
        }

        $lines = file($ledgerPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new RuntimeException("Could not read ledger path \"{$ledgerPath}\".");
        }

        $events = [];
        foreach ($lines as $index => $line) {
            $decoded = json_decode($line, true);
            if (! is_array($decoded)) {
                throw new AtlasLoopReceiptReplayException($index + 1, 'line is not valid JSON');
            }

            $expectedHash = hash('sha256', CanonicalJson::encode($this->withoutReceiptHash($decoded)));
            if (($decoded['receipt_hash'] ?? null) !== $expectedHash) {
                throw new AtlasLoopReceiptReplayException($index + 1, 'receipt hash mismatch');
            }

            $timestamp = $decoded['timestamp'] ?? null;
            if (! is_int($timestamp)) {
                throw new AtlasLoopReceiptReplayException($index + 1, 'timestamp must be an integer');
            }

            $events[] = [
                'line_number' => $index + 1,
                'timestamp' => $timestamp,
                'payload' => $decoded,
            ];
        }

        usort($events, static function (array $left, array $right): int {
            return $left['timestamp'] <=> $right['timestamp']
                ?: $left['line_number'] <=> $right['line_number'];
        });

        $state = [];
        $auditTrail = [];
        foreach ($events as $event) {
            if ($upToTimestamp !== null && $event['timestamp'] > $upToTimestamp) {
                continue;
            }

            $payload = $event['payload'];
            $key = $this->stateKeyFor($payload, $event['line_number']);
            $changes = is_array($payload['changes'] ?? null) ? $payload['changes'] : [];

            $state[$key] = array_merge(
                $state[$key] ?? [],
                $changes,
                [
                    'entity_id' => $key,
                    'event' => $payload['event'] ?? null,
                    'timestamp' => $event['timestamp'],
                ],
            );

            $auditTrail[] = [
                'entity_id' => $key,
                'event' => $payload['event'] ?? null,
                'line_number' => $event['line_number'],
                'receipt_hash' => $payload['receipt_hash'] ?? null,
                'timestamp' => $event['timestamp'],
            ];
        }

        ksort($state, SORT_STRING);

        return [
            'state' => $state,
            'audit_trail' => $auditTrail,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withoutReceiptHash(array $payload): array
    {
        unset($payload['receipt_hash']);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function stateKeyFor(array $payload, int $lineNumber): string
    {
        foreach (['attempt_id', 'proposal_id'] as $key) {
            if (is_string($payload[$key] ?? null) && trim((string) $payload[$key]) !== '') {
                return trim((string) $payload[$key]);
            }
        }

        throw new AtlasLoopReceiptReplayException($lineNumber, 'missing attempt_id/proposal_id');
    }
}

final class AtlasLoopReceiptReplayException extends RuntimeException
{
    public function __construct(int $lineNumber, string $reason)
    {
        parent::__construct(sprintf('Receipt replay failed at line %d: %s.', $lineNumber, $reason));
    }
}
