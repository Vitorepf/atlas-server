<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs;

use App\Services\Ai\AgenticEngineeringOs\Scoring\OutcomeCausalityRanker;
use App\Services\Ai\Support\AppendOnlyJsonlStore;
use App\Services\Ai\Support\AiValueNormalizer;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Str;

/**
 * AAEOS Deferred Phase Dispatcher (AP-799 wiring slice).
 *
 * The HTTP path facade emits envelopes marked `synchronous_invocation: deferred`
 * for phases P5..P9 (topology, routing, spec, tasks, receipt) under Phase 3/4.
 * Those envelopes declare WHAT must happen but do not invoke the canonical
 * runtime synchronously to keep HTTP p95 latency under +20%.
 *
 * This service is the canonical place where the deferred work is parked.
 * It writes each deferred envelope into a JSONL queue file and an in-memory
 * counter, providing:
 *
 *   - durable persistence (file at storage/atlas/aaeos/deferred.jsonl),
 *   - a count of pending dispatches (telemetry),
 *   - an explicit `claim()` API for async workers to pick up the next batch.
 *
 * The actual worker is intentionally NOT bundled here — async workers
 * (Laravel queues, cron, Forge job runner) call `claim()` and act on
 * the returned envelopes. This keeps the dispatcher pure and testable
 * while providing a concrete persistence path (no longer "caller
 * responsibility").
 */
final class AaeosDeferredPhaseDispatcherService
{
    public const FIELD_ENQUEUED = 'enqueued';
    public const FIELD_ENQUEUED_AT = 'enqueued_at';
    public const SCHEMA_VERSION = 'atlas.aaeos.deferred_phase_dispatch.v1';

    public const PHASE_UNKNOWN = 'unknown';

    public const STATUS_BLOCKED = 'blocked';

    public const FIELD_BLOCKED = 'blocked';
    public const FIELD_PHASE = 'phase';
    public const FIELD_BLOCKERS = 'blockers';
    public const FIELD_INTENT_ID = 'intent_id';
    public const FIELD_SCHEMA = 'schema';
    public const FIELD_BLOCKER_SIGNAL = 'blocker_signal';
    public const FIELD_DISPATCH_ID = 'dispatch_id';
    public const FIELD_PHASE_OUT = 'phase_out';
    public const FIELD_ENVELOPE = 'envelope';
    public const FIELD_PHASE_ADVANCE = 'phase_advance';
    public const FIELD_OUTCOME_CAUSALITY = 'outcome_causality';
    public const FIELD_ENQUEUED_COUNT = 'enqueued_count';
    public const FIELD_GATES = 'gates';
    public const FIELD_OUTPUTS = 'outputs';
    public const FIELD_QUEUE_PATH = 'queue_path';
    public const FIELD_DEFERRED = 'deferred';
    public const FIELD_ATLAS_AAEOS_DEFERRED_CLAIMED_ = 'atlas.aaeos.deferred.claimed.';
    public const FIELD_ATLAS_AAEOS_DEFERRED_ENQUEUED_ = 'atlas.aaeos.deferred.enqueued.';
    public const FIELD_ATLAS_AAEOS_DEFERRED_JSONL = 'atlas/aaeos/deferred.jsonl';

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly PhaseAdvanceVerdictClassifier $phaseAdvance = new PhaseAdvanceVerdictClassifier,
        private readonly OutcomeCausalityRanker $outcomeCausality = new OutcomeCausalityRanker,
        private readonly AaeosBlockerSeverityGate $blockerSeverity = new AaeosBlockerSeverityGate,
    ) {}

    /**
     * Append all envelopes that carry a `deferred` marker to the queue.
     *
     * @param  list<array<string,mixed>>  $envelopes
     * @return array<string,mixed>
     */
    public function enqueueFromFacadeResult(array $envelopes, string $queuePath = ''): array
    {
        $path = $this->resolveQueuePath($queuePath);
        $enqueued = [];
        foreach ($envelopes as $env) {
            if (! $this->isDeferred($env)) {
                continue;
            }
            $record = [
                self::FIELD_SCHEMA => self::SCHEMA_VERSION,
                self::FIELD_DISPATCH_ID => 'disp-'.Str::ulid()->toBase32(),
                self::FIELD_PHASE => AiValueNormalizer::trimmedStringOrNull($env[self::FIELD_PHASE_OUT] ?? null) ?? '',
                self::FIELD_INTENT_ID => AiValueNormalizer::trimmedStringOrNull($env[self::FIELD_INTENT_ID] ?? null) ?? '',
                self::FIELD_ENVELOPE => $env,
                // Observe-only: same advance classifier as HTTP path / cockpit.
                self::FIELD_PHASE_ADVANCE => $this->phaseAdvance->classify($env),
                // Observe-only causality when the deferred envelope already
                // carries open blockers / blocked gates (never blocks enqueue).
                self::FIELD_OUTCOME_CAUSALITY => $this->observeCausality($env),
                // Observe-only severity reduction (same gate as cockpit).
                self::FIELD_BLOCKER_SIGNAL => $this->observeBlockerSignal($env),
                self::FIELD_ENQUEUED_AT => gmdate('c'),
            ];
            $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($line !== false) {
                AppendOnlyJsonlStore::appendEncodedLineSilently($path, $line, FILE_APPEND | LOCK_EX, 0o755);
            }
            $enqueued[] = $record;
            $this->incrementCounter(self::FIELD_ATLAS_AAEOS_DEFERRED_ENQUEUED_.$record[self::FIELD_PHASE]);
        }

        return [
            self::FIELD_SCHEMA => self::SCHEMA_VERSION,
            self::FIELD_QUEUE_PATH => $path,
            self::FIELD_ENQUEUED_COUNT => count($enqueued),
            self::FIELD_ENQUEUED => $enqueued,
        ];
    }

    /**
     * Atomically claim up to `$max` pending dispatches from the queue.
     * The claimed records are removed from the queue file.
     *
     * @return list<array<string,mixed>>
     */
    public function claim(int $max = 16, string $queuePath = ''): array
    {
        $path = $this->resolveQueuePath($queuePath);
        if ($max <= 0 || ! is_file($path)) {
            return [];
        }

        $fh = fopen($path, 'r+');
        if ($fh === false) {
            return [];
        }
        if (! flock($fh, LOCK_EX)) {
            fclose($fh);

            return [];
        }
        $contents = stream_get_contents($fh) ?: '';
        $lines = $contents === '' ? [] : explode("\n", trim($contents, "\n"));
        $claimed = [];
        $remaining = [];
        foreach ($lines as $line) {
            if (AiValueNormalizer::trimmedStringOrNull($line) === null) {
                continue;
            }
            if (count($claimed) < $max) {
                $decoded = json_decode(AiValueNormalizer::trimmedStringOrNull($line) ?? '', true);
                if (is_array($decoded)) {
                    $claimed[] = $decoded;
                    continue;
                }
            }
            $remaining[] = $line;
        }
        // Truncate and rewrite remaining lines.
        ftruncate($fh, 0);
        rewind($fh);
        if ($remaining !== []) {
            fwrite($fh, implode("\n", $remaining)."\n");
        }
        fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);

        foreach ($claimed as $record) {
            $phase = (AiValueNormalizer::trimmedStringOrNull($record[self::FIELD_PHASE] ?? null) ?? self::PHASE_UNKNOWN);
            $this->incrementCounter(self::FIELD_ATLAS_AAEOS_DEFERRED_CLAIMED_.$phase);
        }

        return $claimed;
    }

    /**
     * Read pending count without claiming.
     */
    public function pendingCount(string $queuePath = ''): int
    {
        $path = $this->resolveQueuePath($queuePath);
        if (! is_file($path)) {
            return 0;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return $lines === false ? 0 : count($lines);
    }

    /**
     * @param  array<string,mixed>  $envelope
     */
    private function isDeferred(array $envelope): bool
    {
        $outputs = AiValueNormalizer::arrayOrEmpty($envelope[self::FIELD_OUTPUTS] ?? null);
        foreach ($outputs as $key => $value) {
            $value = AiValueNormalizer::trimmedStringOrNull($value);
            if ($value === null) {
                continue;
            }
            if (str_ends_with(AiValueNormalizer::trimmedScalarStringOrNull($key) ?? '', '_invocation') && $value === self::FIELD_DEFERRED) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $envelope
     * @return array<string,mixed>|null
     */
    private function observeCausality(array $envelope): ?array
    {
        $gates = AiValueNormalizer::arrayOrEmpty($envelope[self::FIELD_GATES] ?? null);
        $blockedGates = AiValueNormalizer::arrayOrEmpty($gates[self::FIELD_BLOCKED] ?? null);
        $blockers = AiValueNormalizer::arrayOrEmpty($envelope[self::FIELD_BLOCKERS] ?? null);
        if ($blockedGates === [] && $blockers === []) {
            return null;
        }

        return $this->outcomeCausality->rank(
            hasEvidenceRefs: true,
            status: self::STATUS_BLOCKED,
            missingRequiredSources: $blockedGates !== [],
            testsPassed: null,
        );
    }

    /**
     * @param  array<string,mixed>  $envelope
     * @return array<string,mixed>|null
     */
    private function observeBlockerSignal(array $envelope): ?array
    {
        $blockers = AiValueNormalizer::arrayOrEmpty($envelope[self::FIELD_BLOCKERS] ?? null);
        if ($blockers === []) {
            return null;
        }

        return $this->blockerSeverity->assess($blockers);
    }

    private function resolveQueuePath(string $queuePath): string
    {
        return $queuePath !== '' ? $queuePath : $this->defaultQueuePath();
    }

    private function defaultQueuePath(): string
    {
        return storage_path(self::FIELD_ATLAS_AAEOS_DEFERRED_JSONL);
    }

    private function incrementCounter(string $key): void
    {
        $current = (int) $this->cache->get($key, 0);
        $this->cache->forever($key, $current + 1);
    }
}
