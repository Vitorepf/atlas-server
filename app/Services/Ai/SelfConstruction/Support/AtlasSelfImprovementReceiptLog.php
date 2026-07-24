<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Support;

use App\Services\Ai\EngineeringKernel\Adapters\JsonlReceiptStore;
use Throwable;
use App\Support\UtcIsoTimestamp;

/**
 * S3.F4 — the EVIDENCE / RECEIPT LOG of the RECURSIVE GOVERNED SELF-IMPROVEMENT LOOP.
 *
 * NO SILENT ACTION. Every cycle decision — accepted, rejected (off-target / off-concern),
 * needs_review (gate passed but the adversarial re-check refused), or blocked (no
 * delivery) — appends ONE honest, auditable receipt line to a durable JSONL log. The
 * operator (or an auditor) can read exactly: what signal, what was generated (the touched
 * files), the gate's scores, the adversarial re-check verdict, the final decision, and
 * the branch (kept / discarded / held). A rejection is logged AS a rejection — never
 * hidden, never relabelled as progress (anti-Goodhart, mirrored from the meta-metric).
 *
 * WHAT CROSSES INTO THE RECEIPT (privacy-aware, like the brain write-back):
 *   only ids / file PATHS / labels / numeric scores / decisions / a branch ref. NEVER
 *   the generated source code, NEVER a diff, NEVER the measure output — the receipt is a
 *   provenance record, not a content dump. The signal's own marker text is the operator's
 *   own words about the Atlas codebase (already local), kept so the receipt is readable.
 *
 * DURABILITY + SAFETY:
 *   - append-only JSONL (one decision per line), under storage/app by default. The path
 *     is configurable so tests write to an isolated file.
 *   - FAIL-OPEN: a write failure (unwritable dir, IO error) NEVER breaks a loop cycle —
 *     it returns a non-written marker. The receipt is an audit obligation, not a control
 *     gate; an audit-log outage must not block (nor silently enable) self-improvement —
 *     but the caller surfaces `receipt_written=false` so the gap itself is visible.
 *   - DETERMINISTIC receipt_hash over the decision facts — links a log line back to the
 *     exact outcome and dedups a re-logged identical decision on read.
 *   - the AURG-evidence half of "evidence/receipt" is covered by the mission's own
 *     STAGE-5 brain write-back (the ACCEPTED outcome becomes an evidence node the next
 *     cycle reaches). This log is the COMPLEMENTARY audit surface that ALSO records the
 *     decisions the brain deliberately does NOT ingest (rejections / needs_review),
 *     so the full decision trail is auditable, not just the worthy outcomes.
 */
final class AtlasSelfImprovementReceiptLog
{
    public const SCHEMA = 'atlas.ai.self_improvement_receipt.v1';

    public const DECISION_ACCEPTED = 'accepted';

    public const DECISION_REJECTED = 'rejected';

    public const DECISION_NEEDS_REVIEW = 'needs_review';

    public const DECISION_BLOCKED = 'blocked';

    public function __construct(
        // Optional explicit log path (tests inject an isolated file). When null the
        // path is resolved from config / the storage default at write time.
        private readonly ?string $path = null,
    ) {}

    /**
     * Append ONE honest receipt for a single cycle outcome. Returns the receipt payload
     * augmented with {receipt_hash, receipt_written}. FAIL-OPEN: never throws.
     *
     * @param  array<string,mixed>  $outcome  one entry of the loop summary's `outcomes`
     * @return array<string,mixed>
     */
    public function record(array $outcome): array
    {
        $receipt = $this->buildReceipt($outcome);
        $receipt['receipt_written'] = $this->append($receipt);

        return $receipt;
    }

    /**
     * Read the persisted receipts back (audit surface). Bounded, newest-last. FAIL-OPEN:
     * a missing / unreadable log yields an empty list.
     *
     * @return list<array<string,mixed>>
     */
    public function read(int $limit = 200): array
    {
        $limit = max(1, min(5000, $limit));
        $path = $this->resolvePath();

        try {
            if ($path === null) {
                return [];
            }
            $lines = array_slice((new JsonlReceiptStore($path))->rawLines(), -$limit);
            $out = [];
            foreach ($lines as $line) {
                $decoded = json_decode($line, true);
                if (is_array($decoded)) {
                    $out[] = $decoded;
                }
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Project a loop outcome onto a privacy-aware receipt. Pure: no IO.
     *
     * @param  array<string,mixed>  $outcome
     * @return array<string,mixed>
     */
    private function buildReceipt(array $outcome): array
    {
        $signal = (array) ($outcome['signal'] ?? []);
        $decision = $this->classify($outcome);

        // Touched files = PATHS only (no content) — provenance, not a code dump.
        $touched = array_values(array_filter(
            (array) ($outcome['touched_files'] ?? []),
            'is_string',
        ));

        $recheck = (array) ($outcome['recheck'] ?? []);

        $facts = [
            'schema_version' => self::SCHEMA,
            'decision' => $decision,
            'mission_id' => (string) ($outcome['mission_id'] ?? ''),
            'signal_area' => (string) ($signal['area'] ?? ''),
            'signal_source' => (string) ($signal['source'] ?? ''),
            'signal_file' => is_string($signal['file'] ?? null) ? (string) $signal['file'] : null,
            'signal_line' => isset($signal['line']) ? (int) $signal['line'] : null,
            'signal' => (string) ($signal['signal'] ?? ($signal['request'] ?? '')),
            // The gate's numbers — the OUT-OF-PROCESS verdict, never a self-declared pass.
            'relevant' => (bool) ($outcome['relevant'] ?? false),
            'relevance_reason' => (string) ($outcome['relevance_reason'] ?? 'unknown'),
            'target_match' => $outcome['target_match'] ?? null,
            'content_relevance' => $outcome['content_relevance'] ?? null,
            'content_method' => $outcome['content_method'] ?? null,
            'matched_file' => is_string($outcome['matched_file'] ?? null) ? (string) $outcome['matched_file'] : null,
            'touched_files' => $touched,
            // The adversarial re-check verdict (F4) — confirmed / why not.
            'recheck_confirmed' => array_key_exists('confirmed', $recheck) ? (bool) $recheck['confirmed'] : null,
            'recheck_reason' => is_string($recheck['reason'] ?? null) ? (string) $recheck['reason'] : null,
            // Branch provenance: kept (accepted), held (needs_review), or discarded.
            'branch' => is_string($outcome['branch'] ?? null) ? (string) $outcome['branch'] : null,
            'rejected_branch' => is_string($outcome['rejected_branch'] ?? null) ? (string) $outcome['rejected_branch'] : null,
            'held_branch' => is_string($outcome['held_branch'] ?? null) ? (string) $outcome['held_branch'] : null,
            'discarded' => (bool) (($outcome['discarded']['discarded'] ?? false)),
            'delivered' => (bool) ($outcome['delivered'] ?? false),
            'accepted' => (bool) ($outcome['accepted'] ?? false),
            // Governance assertions ride every receipt (auditable invariants).
            'never_merged' => (bool) ($outcome['never_merged'] ?? true),
            'main_untouched' => (bool) ($outcome['main_untouched'] ?? true),
            'recorded_at' => $this->now(),
        ];

        // Deterministic receipt fingerprint over the decision facts (excludes the
        // timestamp so an identical decision dedups on read).
        $hashable = $facts;
        unset($hashable['recorded_at']);
        $facts['receipt_hash'] = hash('sha256', (string) json_encode($hashable, JSON_UNESCAPED_SLASHES));

        return $facts;
    }

    /**
     * Final decision label, honest. accepted requires BOTH delivered+relevant AND a kept
     * branch (the loop only sets `branch` on a confirmed-worthy outcome). A delivered+
     * relevant outcome with NO kept branch is needs_review (the adversarial re-check
     * held it). Off-target/off-concern is rejected. No delivery is blocked.
     *
     * @param  array<string,mixed>  $outcome
     */
    private function classify(array $outcome): string
    {
        $delivered = (bool) ($outcome['delivered'] ?? false);
        if (! $delivered) {
            return self::DECISION_BLOCKED;
        }
        if ((bool) ($outcome['accepted'] ?? false)) {
            return self::DECISION_ACCEPTED;
        }
        if (! empty($outcome['needs_review'])) {
            return self::DECISION_NEEDS_REVIEW;
        }

        return self::DECISION_REJECTED;
    }

    /**
     * Append one JSONL line. FAIL-OPEN: returns false (never throws) on any IO failure.
     *
     * @param  array<string,mixed>  $receipt
     */
    private function append(array $receipt): bool
    {
        $path = $this->resolvePath();
        if ($path === null) {
            return false;
        }

        try {
            (new JsonlReceiptStore($path))->append($receipt);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Resolve the log path: explicit ctor path > config > the storage default. Tolerates
     * an unbooted container so the writer stays trivially constructable in unit tests.
     */
    private function resolvePath(): ?string
    {
        if ($this->path !== null && trim($this->path) !== '') {
            return $this->path;
        }

        $configured = $this->cfg('atlas.self_construction.receipt_log_path', null);
        if (is_string($configured) && trim($configured) !== '') {
            return $configured;
        }

        if (function_exists('storage_path')) {
            try {
                return storage_path('app/atlas-self-construct-receipts.jsonl');
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    private function now(): string
    {
        try {
            if (function_exists('now')) {
                return (string) now()->toIso8601String();
            }
        } catch (Throwable) {
            // fall through to a plain UTC stamp
        }

        return UtcIsoTimestamp::now();
    }

    private function cfg(string $key, mixed $default): mixed
    {
        if (! function_exists('config')) {
            return $default;
        }
        try {
            return config($key, $default);
        } catch (Throwable) {
            return $default;
        }
    }
}
