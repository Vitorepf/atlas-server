<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Quaternity\IntentIngest;

/**
 * QUATERNITY · INTENT INGEST — the read-only front of the operator-intent pipeline. It reads an append-only
 * JSONL log of operator chat/goal/cli messages and returns a deterministic, ordered list of immutable
 * {@see OperatorIntentMessage} value objects.
 *
 * Contract:
 *  - BYTE-DETERMINISTIC: each message id is the sha256 of its raw line, so two reads of the same bytes yield an
 *    identical message list (same ids, same order).
 *  - READ-ONLY: never writes/truncates/mutates the file.
 *  - RESILIENT: blank lines are skipped; a malformed JSON line is rejected as a typed {@see IntentParseError}
 *    carrying its exact line number, and parsing CONTINUES (a bad line never corrupts the lines after it).
 *  - INCREMENTAL: {@see tailSince()} reads only the complete lines after a byte offset and returns the next
 *    offset, so a consumer can ingest a growing log without re-reading what it already saw.
 */
final class AtlasLoopOperatorIntentStreamReader
{
    private const AUTHOR = 'operator';

    private const VALID_SOURCES = ['chat', 'goal', 'cli'];

    private const DEFAULT_SOURCE = 'chat';

    public function __construct(private readonly ?string $path = null) {}

    /** The resolved stream path: explicit > config override > storage default. */
    public function path(): string
    {
        if ($this->path !== null && trim($this->path) !== '') {
            return $this->path;
        }

        return (string) config('atlas.quaternity.intent_stream_path', storage_path('atlas/quaternity/operator-intent.jsonl'));
    }

    /**
     * The full ordered message list (offset 0). Deterministic across calls.
     *
     * @return list<OperatorIntentMessage>
     */
    public function read(): array
    {
        return $this->tailSince(0)['messages'];
    }

    /**
     * Read only the COMPLETE (newline-terminated) lines starting at $byteOffset.
     *
     * @return array{messages:list<OperatorIntentMessage>, errors:list<IntentParseError>, next_offset:int}
     */
    public function tailSince(int $byteOffset): array
    {
        $offset = max(0, $byteOffset);
        $result = ['messages' => [], 'errors' => [], 'next_offset' => $offset];

        $path = $this->path();
        if (! is_file($path)) {
            return $result;
        }

        // Read-only: pull the bytes, never open for writing.
        $content = (string) @file_get_contents($path);
        $size = strlen($content);
        if ($offset >= $size) {
            $result['next_offset'] = $size;

            return $result;
        }

        $chunk = substr($content, $offset);
        $lastNewline = strrpos($chunk, "\n");
        if ($lastNewline === false) {
            // No complete line yet (a partial line still being appended) — consume nothing.
            return $result;
        }

        $completeLength = $lastNewline + 1;
        $result['next_offset'] = $offset + $completeLength;
        $complete = substr($chunk, 0, $completeLength);

        $lineNumber = 0;
        foreach (explode("\n", rtrim($complete, "\n")) as $line) {
            $lineNumber++;
            if (trim($line) === '') {
                continue; // blank line — skipped, but still counted so line numbers stay exact
            }

            $decoded = json_decode($line, true);
            if (! is_array($decoded)) {
                $result['errors'][] = new IntentParseError($lineNumber, $line, 'invalid_json');

                continue; // resilient: a malformed line never stops the lines after it
            }

            $source = (string) ($decoded['source'] ?? self::DEFAULT_SOURCE);
            if (! in_array($source, self::VALID_SOURCES, true)) {
                $source = self::DEFAULT_SOURCE;
            }

            $result['messages'][] = new OperatorIntentMessage(
                hash('sha256', $line),
                (int) ($decoded['ts'] ?? 0),
                self::AUTHOR,
                (string) ($decoded['raw_text'] ?? $decoded['text'] ?? ''),
                $source,
            );
        }

        return $result;
    }
}
