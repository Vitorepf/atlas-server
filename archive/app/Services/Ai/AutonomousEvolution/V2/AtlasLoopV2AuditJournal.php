<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\V2;

use App\Services\Ai\EngineeringKernel\Adapters\JsonlReceiptStore;
use InvalidArgumentException;

final class AtlasLoopV2AuditJournal
{
    public const GENESIS_HASH = 'GENESIS';

    /** @var null|callable():string */
    private $clock;

    /**
     * @param  null|callable():string  $clock
     */
    public function __construct(
        private readonly string $journalPath,
        ?callable $clock = null,
    ) {
        $this->clock = $clock;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{ts:string,event_type:string,payload:array<string,mixed>,prev_hash:string,line_hash:string}
     */
    public function append(string $eventType, array $payload): array
    {
        $eventType = trim($eventType);
        if ($eventType === '') {
            throw new InvalidArgumentException('event_type_empty');
        }

        $store = new JsonlReceiptStore($this->journalPath);
        $entry = [];
        // prev_hash derivation (last VALID line_hash, skipping malformed lines) runs INSIDE the lock.
        $store->appendWith(function (?string $lastLine) use ($store, $eventType, $payload, &$entry): array {
            $prevHash = self::GENESIS_HASH;
            foreach ($store->replay() as $row) {
                if (is_string($row['line_hash'] ?? null) && $row['line_hash'] !== '') {
                    $prevHash = $row['line_hash'];
                }
            }
            $entry = [
                'ts' => $this->timestamp(),
                'event_type' => $eventType,
                'payload' => $this->sortKeys($payload),
                'prev_hash' => $prevHash,
                'line_hash' => '',
            ];
            $entry['line_hash'] = $this->lineHash($entry);

            return $entry;
        });

        return $entry;
    }

    /**
     * @return array{ok:bool,broken_at_line:?int,total_lines:int}
     */
    public function verify(): array
    {
        $lines = $this->lines();
        $prevHash = self::GENESIS_HASH;

        foreach ($lines as $index => $line) {
            $entry = json_decode($line, true);
            $lineNumber = $index + 1;
            if (! is_array($entry)) {
                return ['ok' => false, 'broken_at_line' => $lineNumber, 'total_lines' => count($lines)];
            }
            if ((string) ($entry['prev_hash'] ?? '') !== $prevHash) {
                return ['ok' => false, 'broken_at_line' => $lineNumber, 'total_lines' => count($lines)];
            }
            if ((string) ($entry['line_hash'] ?? '') !== $this->lineHash($entry)) {
                return ['ok' => false, 'broken_at_line' => $lineNumber, 'total_lines' => count($lines)];
            }

            $prevHash = (string) $entry['line_hash'];
        }

        return ['ok' => true, 'broken_at_line' => null, 'total_lines' => count($lines)];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function tail(int $n): array
    {
        if ($n <= 0) {
            return [];
        }

        $decoded = [];
        foreach (array_slice($this->lines(), -$n) as $line) {
            $entry = json_decode($line, true);
            if (is_array($entry)) {
                $decoded[] = $entry;
            }
        }

        return $decoded;
    }

    /**
     * @return list<string>
     */
    private function lines(): array
    {
        if (! is_file($this->journalPath)) {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', file($this->journalPath, FILE_IGNORE_NEW_LINES) ?: []),
            static fn (string $line): bool => $line !== '',
        ));
    }

    private function timestamp(): string
    {
        if (is_callable($this->clock)) {
            return (string) ($this->clock)();
        }

        return gmdate(DATE_ATOM);
    }

    /**
     * @param  array<string,mixed>  $entry
     */
    private function lineHash(array $entry): string
    {
        $payload = is_array($entry['payload'] ?? null) ? $this->sortKeys($entry['payload']) : [];

        return hash('sha256', (string) ($entry['prev_hash'] ?? '')
            .(string) ($entry['ts'] ?? '')
            .(string) ($entry['event_type'] ?? '')
            .json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function sortKeys(array $payload): array
    {
        if (array_is_list($payload)) {
            foreach ($payload as $key => $value) {
                if (is_array($value)) {
                    $payload[$key] = $this->sortKeys($value);
                }
            }

            return $payload;
        }

        ksort($payload);
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->sortKeys($value);
            }
        }

        return $payload;
    }
}
