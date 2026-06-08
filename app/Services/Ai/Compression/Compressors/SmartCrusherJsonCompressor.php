<?php

declare(strict_types=1);

namespace App\Services\Ai\Compression\Compressors;

use App\Services\Ai\Compression\CompressionResult;
use App\Services\Ai\Compression\Contracts\Compressor;

/**
 * SmartCrusher for JSON ARRAYS (AP-813).
 *
 * Handles the very common "list of homogeneous records" payload — paginated API
 * results, row dumps, vector-search hits, etc. — where the SAME row is repeated
 * verbatim many times and the signal lives in the rows that are DIFFERENT.
 *
 * Quality model — LOSSLESS-BY-GOVERNANCE (Atlas's contract), the headroom economy
 * done safely:
 *   - SIGNAL is kept in-compressor, UNCONDITIONALLY: every anomaly — an error/fail/
 *     exception marker, a row whose shape differs from the modal key-set, a strong
 *     length-outlier, a stray scalar — survives verbatim. No heuristic ever evicts it.
 *   - HOMOGENEOUS BULK is SAMPLED: among rows that match the modal shape and are not
 *     outliers, the head + tail window is kept and the redundant middle is dropped.
 *     This is where the big token economy comes from (paginated dumps, row lists,
 *     vector hits are mostly homogeneous bulk).
 *   - NOTHING IS EVER LOST: the FULL original block is persisted verbatim in the CCR
 *     store (Evidence-Ledger backed, durable, never auto-expired) BEFORE this output
 *     replaces it, and the pipeline leaves an `atlas_ccr` marker so the model can
 *     retrieve the exact original on demand. Sampling is recoverable, not destructive.
 *   - Exact byte-duplicate rows never appear twice in the output (the one provably
 *     lossless collapse).
 *
 * The economy↔safety DIAL is `max_keep` (plus keep_head/keep_tail): set it high to keep
 * essentially everything (near-lossless on the wire), low for maximum economy. Nothing
 * is summarized, paraphrased or rewritten; maps/objects at the top level are out of
 * scope. Pure & deterministic: array of JSON text in, {@see CompressionResult} out
 * (no I/O, no DB, no container); first-occurrence original order is preserved.
 */
final class SmartCrusherJsonCompressor implements Compressor
{
    public function contentType(): string
    {
        return 'json';
    }

    /**
     * Cheap, conservative detection: a JSON ARRAY with >= 6 sequential elements.
     * Errs toward FALSE — objects/maps and anything that does not cleanly decode to a
     * list are rejected (a missed block is harmless; a wrong match could mangle text).
     */
    public function detect(string $block): bool
    {
        $trimmed = ltrim($block);
        if ($trimmed === '' || $trimmed[0] !== '[') {
            return false;
        }

        $decoded = json_decode($trimmed, true);
        if (! is_array($decoded) || ! array_is_list($decoded)) {
            return false;
        }

        return count($decoded) >= 6;
    }

    public function compress(string $block, array $options = []): CompressionResult
    {
        $type = $this->contentType();

        $trimmed = ltrim($block);
        if ($trimmed === '' || $trimmed[0] !== '[') {
            return CompressionResult::unchanged($block, $type);
        }

        $items = json_decode($trimmed, true);
        if (! is_array($items) || ! array_is_list($items)) {
            return CompressionResult::unchanged($block, $type);
        }

        $total = count($items);
        if ($total < 6) {
            return CompressionResult::unchanged($block, $type);
        }

        // Per-item compact JSON text (used for identity, length & marker scanning).
        // Bail out if any item is not JSON-encodable rather than risk corruption.
        $encoded = [];
        foreach ($items as $i => $item) {
            $json = json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                return CompressionResult::unchanged($block, $type);
            }
            $encoded[$i] = $json;
        }

        // Stats drive anomaly classification: an anomaly is SIGNAL and is kept
        // unconditionally; the rest is homogeneous bulk eligible for sampling.
        $modalKeySig = $this->modalKeySignature($items);
        $lengths = array_map('strlen', $encoded);
        $median = $this->median($lengths);
        $mad = $this->medianAbsoluteDeviation($lengths, $median);

        // Partition: ANOMALIES (error/shape-change/outlier/scalar) kept unconditionally;
        // everything else is homogeneous BULK eligible for head+tail sampling.
        $anomalyFlag = [];
        $anomalyIdx = [];
        $bulkIdx = [];
        for ($i = 0; $i < $total; $i++) {
            $isAnomaly = $this->isAnomaly($items[$i], $encoded[$i], $modalKeySig, $median, $mad);
            $anomalyFlag[$i] = $isAnomaly;
            if ($isAnomaly) {
                $anomalyIdx[] = $i;
            } else {
                $bulkIdx[] = $i;
            }
        }

        $keepHead = max(0, (int) ($options['keep_head'] ?? 8));
        $keepTail = max(0, (int) ($options['keep_tail'] ?? 4));
        $maxKeep = max($keepHead + $keepTail, (int) ($options['max_keep'] ?? 40));

        // Sample the homogeneous bulk up to the budget (max_keep minus the anomalies we
        // must always keep). Budget is the economy<->safety dial: a high max_keep keeps
        // essentially all bulk (near-lossless on the wire), a low one maximizes economy.
        // Dropped middle rows remain recoverable verbatim from the CCR store.
        $budgetBulk = max(0, $maxKeep - count($anomalyIdx));
        $bulkPick = $this->sampleBulk($bulkIdx, $keepHead, $keepTail, $budgetBulk);

        // Union (anomalies + sampled bulk), original order, exact-duplicate-free.
        $candidateIdx = array_merge($anomalyIdx, $bulkPick);
        sort($candidateIdx);
        $keptIndexes = [];
        $seenExact = [];
        foreach ($candidateIdx as $i) {
            if (isset($seenExact[$encoded[$i]])) {
                continue; // exact byte-duplicate of a kept row — provably redundant
            }
            $seenExact[$encoded[$i]] = true;
            $keptIndexes[] = $i;
        }

        $kept = count($keptIndexes);

        // Nothing was dropped → re-encoding cannot help; pass through unchanged.
        if ($kept >= $total) {
            return CompressionResult::unchanged($block, $type);
        }

        $keptItems = [];
        $anomaliesKept = 0;
        foreach ($keptIndexes as $i) {
            $keptItems[] = $items[$i];
            if ($anomalyFlag[$i]) {
                $anomaliesKept++;
            }
        }

        $candidate = json_encode($keptItems, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($candidate === false) {
            return CompressionResult::unchanged($block, $type);
        }

        $omitted = $total - $kept;
        $candidate .= "\n... (kept {$kept} of {$total} items — every anomaly/error/outlier preserved verbatim; the {$omitted} omitted rows were homogeneous bulk, recoverable in full via the atlas_ccr marker above)";

        // compressed() self-downgrades to unchanged if this is not strictly smaller.
        return CompressionResult::compressed($block, $candidate, $type, [
            'items_total' => $total,
            'items_kept' => $kept,
            'anomalies_kept' => $anomaliesKept,
        ]);
    }

    /**
     * Deterministically pick which homogeneous-bulk indexes to KEEP: always the first
     * keep_head and last keep_tail, then an even-strided sample of the middle until the
     * budget is filled. Returns ALL bulk indexes when the budget covers them (so a high
     * max_keep is near-lossless on the wire). Dropped rows stay recoverable via CCR.
     *
     * @param  list<int>  $bulkIdx  item indexes of the homogeneous bulk, in order
     * @return list<int>
     */
    private function sampleBulk(array $bulkIdx, int $keepHead, int $keepTail, int $budget): array
    {
        $count = count($bulkIdx);
        if ($budget <= 0) {
            return [];
        }
        if ($count <= $budget) {
            return $bulkIdx; // budget covers the whole bulk — keep all of it
        }

        $picked = [];
        foreach (array_slice($bulkIdx, 0, min($keepHead, $budget)) as $i) {
            $picked[$i] = true;
        }
        if ($keepTail > 0) {
            foreach (array_slice($bulkIdx, -$keepTail) as $i) {
                $picked[$i] = true;
            }
        }

        $remaining = $budget - count($picked);
        if ($remaining > 0) {
            $stride = $count / ($remaining + 1);
            for ($k = 1; $k <= $remaining; $k++) {
                $pos = max(0, min($count - 1, (int) floor($k * $stride)));
                $picked[$bulkIdx[$pos]] = true;
            }
        }

        $keys = array_keys($picked);
        sort($keys);

        return $keys;
    }

    /**
     * Is this kept item an anomaly (error / shape-change / length-outlier)? Drives the
     * keep partition (anomalies are kept unconditionally) and the honest `anomalies_kept`
     * meta.
     *
     * @param  mixed  $item
     * @param  list<string>  $modalKeySig  modal key signature of the list
     */
    private function isAnomaly($item, string $encoded, array $modalKeySig, float $median, float $mad): bool
    {
        // 1) Error-ish marker anywhere in the item's keys or values.
        if (preg_match('/error|fail|exception|null|warn/i', $encoded) === 1) {
            return true;
        }

        // 2) Shape differs from the list's modal key-set (includes scalars amid
        //    objects — their signature is the type tag, which differs from a map).
        if ($this->keySignature($item) !== $modalKeySig) {
            return true;
        }

        // 3) Strong length outlier vs the median (robust MAD threshold; falls back to
        //    a relative-deviation test when the bulk is too uniform for MAD to bite).
        $len = (float) strlen($encoded);
        if ($mad > 0.0) {
            // 0.6745 * |x - med| / MAD is the standard modified z-score; >3.5 is a
            // conventional strong-outlier cut.
            $modifiedZ = 0.6745 * abs($len - $median) / $mad;
            if ($modifiedZ > 3.5) {
                return true;
            }
        } elseif ($median > 0.0) {
            // MAD == 0 → the bulk is identical-length; anything materially off is rare
            // and therefore signal.
            if (abs($len - $median) / $median > 0.5) {
                return true;
            }
        }

        return false;
    }

    /**
     * The modal (most common) key signature across the list's items.
     *
     * @param  list<mixed>  $items
     * @return list<string>
     */
    private function modalKeySignature(array $items): array
    {
        $counts = [];
        $byKey = [];
        foreach ($items as $item) {
            $sig = $this->keySignature($item);
            $k = implode("\0", $sig);
            $counts[$k] = ($counts[$k] ?? 0) + 1;
            $byKey[$k] = $sig;
        }

        $bestKey = null;
        $bestCount = -1;
        foreach ($counts as $k => $count) {
            if ($count > $bestCount) {
                $bestCount = $count;
                $bestKey = $k;
            }
        }

        return $bestKey === null ? [] : $byKey[$bestKey];
    }

    /**
     * Stable structural signature of one item: sorted key names for a map; a type tag
     * for a scalar or list (so a stray scalar / nested list reads as a shape change).
     *
     * @param  mixed  $item
     * @return list<string>
     */
    private function keySignature($item): array
    {
        if (is_array($item) && ! array_is_list($item)) {
            $keys = array_map('strval', array_keys($item));
            sort($keys);

            return $keys;
        }

        if (is_array($item)) {
            return ['__list__'];
        }

        return ['__scalar__:'.gettype($item)];
    }

    /**
     * @param  list<int>  $values
     */
    private function median(array $values): float
    {
        $n = count($values);
        if ($n === 0) {
            return 0.0;
        }
        sort($values);
        $mid = intdiv($n, 2);

        return $n % 2 === 1
            ? (float) $values[$mid]
            : ((float) $values[$mid - 1] + (float) $values[$mid]) / 2.0;
    }

    /**
     * @param  list<int>  $values
     */
    private function medianAbsoluteDeviation(array $values, float $median): float
    {
        if ($values === []) {
            return 0.0;
        }
        $deviations = [];
        foreach ($values as $v) {
            $deviations[] = (int) round(abs((float) $v - $median));
        }

        return $this->median($deviations);
    }
}
