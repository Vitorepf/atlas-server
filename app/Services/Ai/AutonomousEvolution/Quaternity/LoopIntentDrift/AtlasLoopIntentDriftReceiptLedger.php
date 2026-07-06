<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Quaternity\LoopIntentDrift;

use App\Services\Ai\EngineeringKernel\Adapters\JsonlReceiptStore;
use App\Services\Ai\SelfConstruction\Support\CanonicalizesNestedValues;
use Closure;
use Throwable;

/**
 * APPEND-ONLY JSONL ledger of intent-drift receipts. No public update/delete/truncate methods — invariant
 * enforced by reflection in the test. Idempotent: re-appending a payload whose content_hash already exists
 * is a no-op that returns the existing receipt_id. {@see verify()} re-hashes every line and returns the
 * tampered receipt_ids.
 *
 * Storage path: config('atlas.loop.quaternity.intent_drift.ledger_path', storage_path(...)).
 *
 * Filesystem-only — no Eloquent.
 */
final class AtlasLoopIntentDriftReceiptLedger
{
    use CanonicalizesNestedValues;

    private const DEFAULT_RELATIVE_PATH = 'atlas/loop/quaternity/intent-drift.jsonl';

    private ?Closure $clock = null;

    public function __construct(private readonly ?string $path = null) {}

    public function setClock(Closure $clock): void
    {
        $this->clock = $clock;
    }

    public function path(): string
    {
        if ($this->path !== null && trim($this->path) !== '') {
            return $this->path;
        }

        return (string) config('atlas.loop.quaternity.intent_drift.ledger_path', storage_path(self::DEFAULT_RELATIVE_PATH));
    }

    /**
     * Append ONE receipt. Idempotent on content_hash collision — returns the prior receipt_id unchanged.
     *
     * @param  array<string,mixed>  $detectorFact
     * @param  array<string,mixed>  $recalibration
     */
    public function append(array $detectorFact, array $recalibration): AtlasLoopIntentDriftReceipt
    {
        $contentHash = $this->contentHash($detectorFact, $recalibration);

        $result = null;
        try {
            // Idempotency check runs INSIDE the store's exclusive lock — no dedup TOCTOU between writers.
            (new JsonlReceiptStore($this->path()))->appendWith(function (?string $lastLine) use (&$result, $contentHash, $detectorFact, $recalibration): ?array {
                $existing = $this->findByContentHash($contentHash);
                if ($existing !== null) {
                    $result = $existing;

                    return null;
                }

                $result = $this->buildReceipt($contentHash, $detectorFact, $recalibration);

                return $result->toArray();
            });
        } catch (Throwable) {
            // best-effort append preserved from the legacy @file_put_contents path
        }

        // Fail-open fallback: the legacy writer returned the receipt even when the disk write failed.
        return $result ?? $this->buildReceipt($contentHash, $detectorFact, $recalibration);
    }

    /**
     * @param  array<string,mixed>  $detectorFact
     * @param  array<string,mixed>  $recalibration
     */
    private function buildReceipt(string $contentHash, array $detectorFact, array $recalibration): AtlasLoopIntentDriftReceipt
    {
        $recordedAt = $this->now();
        $receiptId = hash('sha256', $this->canonicalJson([
            'recorded_at' => $recordedAt,
            'content_hash' => $contentHash,
        ]));

        return new AtlasLoopIntentDriftReceipt($receiptId, $recordedAt, $detectorFact, $recalibration, $contentHash);
    }

    /**
     * @return list<AtlasLoopIntentDriftReceipt>
     */
    public function all(): array
    {
        $rows = [];
        // replay() skips corrupt/empty/non-array lines — identical to the prior decodeOrNull loop.
        foreach ((new JsonlReceiptStore($this->path()))->replay() as $decoded) {
            $rows[] = new AtlasLoopIntentDriftReceipt(
                (string) ($decoded['receipt_id'] ?? ''),
                (string) ($decoded['recorded_at'] ?? ''),
                (array) ($decoded['detector_fact'] ?? []),
                (array) ($decoded['recalibration'] ?? []),
                (string) ($decoded['content_hash'] ?? ''),
            );
        }

        return $rows;
    }

    /**
     * Verify every line by recomputing the FACT/recalibration content_hash and the receipt_id. Returns the
     * receipt_ids of any tampered lines (empty list ⇒ ledger intact).
     *
     * @return list<string>
     */
    public function verify(): array
    {
        $path = $this->path();
        if (! is_file($path)) {
            return [];
        }
        $tampered = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            try {
                $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                $tampered[] = 'unparseable_line';

                continue;
            }
            if (! is_array($decoded)) {
                $tampered[] = 'non_array_line';

                continue;
            }
            $receiptId = (string) ($decoded['receipt_id'] ?? '');
            $detectorFact = (array) ($decoded['detector_fact'] ?? []);
            $recalibration = (array) ($decoded['recalibration'] ?? []);
            $recordedAt = (string) ($decoded['recorded_at'] ?? '');
            $contentHash = (string) ($decoded['content_hash'] ?? '');

            $recomputedContent = $this->contentHash($detectorFact, $recalibration);
            $recomputedReceiptId = hash('sha256', $this->canonicalJson([
                'recorded_at' => $recordedAt,
                'content_hash' => $recomputedContent,
            ]));
            if ($recomputedContent !== $contentHash || $recomputedReceiptId !== $receiptId) {
                $tampered[] = $receiptId !== '' ? $receiptId : 'missing_receipt_id';
            }
        }

        return $tampered;
    }

    /**
     * @param  array<string,mixed>  $detectorFact
     * @param  array<string,mixed>  $recalibration
     */
    private function contentHash(array $detectorFact, array $recalibration): string
    {
        return hash('sha256', $this->canonicalJson([
            'detector_fact' => $detectorFact,
            'recalibration' => $recalibration,
        ]));
    }

    private function findByContentHash(string $contentHash): ?AtlasLoopIntentDriftReceipt
    {
        foreach ($this->all() as $receipt) {
            if ($receipt->contentHash === $contentHash) {
                return $receipt;
            }
        }

        return null;
    }

    private function now(): string
    {
        if ($this->clock === null) {
            // The packet forbids time() — fall back to the request-bound app time when no clock is injected.
            $iso = (string) (function_exists('now') ? now('UTC')->toIso8601String() : '1970-01-01T00:00:00+00:00');

            return $iso;
        }

        return (string) ($this->clock)();
    }

    private function canonicalJson(mixed $value): string
    {
        return (string) json_encode($this->canonicalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
