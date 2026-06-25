<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\AuditTrail;

use RuntimeException;

/**
 * Streams the composed audit timeline to JSONL for offline operator review.
 *
 * Self-describing: the FIRST line of every output file is an {@see ExportManifest}
 * header carrying schema_version, source_ledger_versions, window and
 * sha256_of_body. The hash covers the concatenation of all event lines (including
 * trailing newlines) so a single-byte mutation invalidates the manifest.
 *
 * Memory-bounded: events flow through a Generator-style iterable into a
 * temp body file, computed sha256 is incremental (hash_init/hash_update/
 * hash_final), the final file is written by streaming the body line-by-line.
 * No call site ever materialises an array of all events.
 *
 * Deterministic: each event line is JSON with lexicographically sorted keys, so
 * two exports of the same window produce byte-identical files.
 */
final class AtlasLoopAuditTrailExporter
{
    public const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    /**
     * @param  iterable<AuditEvent>  $events  the composer's compose($window) iterator (or a fake equivalent).
     * @param  array<string,string>  $sourceLedgerVersions
     */
    public function export(
        iterable $events,
        string $outputPath,
        TimelineWindow $window,
        array $sourceLedgerVersions = [],
    ): ExportManifest {
        $bodyPath = $outputPath.'.body.tmp';
        $bodyHandle = @fopen($bodyPath, 'wb');
        if ($bodyHandle === false) {
            throw new RuntimeException('cannot open body temp file: '.$bodyPath);
        }

        $hashCtx = hash_init('sha256');
        $count = 0;

        try {
            foreach ($events as $event) {
                if (! $event instanceof AuditEvent) {
                    continue;
                }
                $line = $this->encodeEvent($event)."\n";
                hash_update($hashCtx, $line);
                if (fwrite($bodyHandle, $line) === false) {
                    throw new RuntimeException('write to body failed at event '.$count);
                }
                $count++;
            }
        } finally {
            fclose($bodyHandle);
        }

        $sha256 = hash_final($hashCtx);

        ksort($sourceLedgerVersions);
        $manifest = new ExportManifest(
            schema_version: ExportManifest::SCHEMA_VERSION,
            source_ledger_versions: $sourceLedgerVersions,
            window_from: $window->fromTsUtc,
            window_to: $window->toTsUtc,
            event_count: $count,
            sha256_of_body: $sha256,
        );

        $this->writeFinal($outputPath, $bodyPath, $manifest);
        @unlink($bodyPath);

        return $manifest;
    }

    private function encodeEvent(AuditEvent $event): string
    {
        $payload = [
            'event_id' => $event->event_id,
            'facts' => $this->ksortDeep($event->facts),
            'kind' => $event->kind,
            'refs' => $event->refs,
            'source_ledger' => $event->source_ledger,
            'ts_utc' => $event->ts_utc,
        ];

        return json_encode($payload, self::JSON_FLAGS);
    }

    /**
     * @param  array<mixed,mixed>  $value
     * @return array<mixed,mixed>
     */
    private function ksortDeep(array $value): array
    {
        $isAssoc = $value !== [] && array_keys($value) !== range(0, count($value) - 1);
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $value[$k] = $this->ksortDeep($v);
            }
        }
        if ($isAssoc) {
            ksort($value);
        }

        return $value;
    }

    private function writeFinal(string $outputPath, string $bodyPath, ExportManifest $manifest): void
    {
        $out = @fopen($outputPath, 'wb');
        if ($out === false) {
            throw new RuntimeException('cannot open output file: '.$outputPath);
        }
        try {
            $headerLine = json_encode($manifest->toArray(), self::JSON_FLAGS)."\n";
            if (fwrite($out, $headerLine) === false) {
                throw new RuntimeException('write header failed: '.$outputPath);
            }

            $body = @fopen($bodyPath, 'rb');
            if ($body === false) {
                throw new RuntimeException('cannot open body for read: '.$bodyPath);
            }
            try {
                while (! feof($body)) {
                    $chunk = fread($body, 8192);
                    if ($chunk === false) {
                        throw new RuntimeException('read body chunk failed');
                    }
                    if ($chunk === '') {
                        continue;
                    }
                    if (fwrite($out, $chunk) === false) {
                        throw new RuntimeException('copy body chunk failed');
                    }
                }
            } finally {
                fclose($body);
            }
        } finally {
            fclose($out);
        }
    }
}
