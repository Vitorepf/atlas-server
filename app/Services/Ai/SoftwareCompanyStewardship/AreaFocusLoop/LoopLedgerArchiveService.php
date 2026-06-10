<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Throwable;

/**
 * AP-810 · LHL-14 — Ledger Compaction, Archival & Replay (owner AP-809 Part 3).
 *
 * Months of autonomous loop operation produce too much raw JSONL/evidence to
 * keep replaying from scratch. This read-only diagnostic service plans and
 * proves compaction WITHOUT ever losing replay truth:
 *
 *   - {@see plan()}      decides which raw cycle segments are sealable now under
 *                        the retention policy (recent segments are retained raw).
 *   - {@see compact()}   seals each retained-for-archive raw segment with a
 *                        sha256, builds a compact cycle index + commit/blocker/
 *                        provider summary, and a replay manifest. If sealing a
 *                        segment fails (e.g. a corrupted/unreadable tail) it
 *                        PAUSES (status=paused) BEFORE deleting anything, so raw
 *                        evidence is never lost to disk pressure.
 *   - {@see replayManifest()} reconstructs the full cycle stream ACROSS sealed
 *                        archive segments + live raw segments, in order.
 *
 * Hard invariants (AP-810 / AP-809 Part 3):
 *   - raw evidence is NEVER deleted until sealed and indexed (this service
 *     never deletes at all — sealing is an index + hash, deletion is a separate
 *     gated maintenance action it only PLANS);
 *   - compaction never changes cycle truth (the compact index references the
 *     exact raw cycle ids + their per-record hashes);
 *   - replay must work across archived segments;
 *   - failed compaction pauses the loop before disk exhaustion (status=paused),
 *     a pause is NEVER dressed as ok;
 *   - read-only / deterministic / input-seam driven: same input => same
 *     report_hash. Never invokes a provider, never merges, never deletes a
 *     branch/worktree, never runs destructive git.
 *
 * Contract: docs/ap/AP-810-long-horizon-loop-enterprise-delivery-block-contract.md (LHL-14),
 *           docs/ap/AP-809-...-platform-contract.md (Part 3).
 */
final class LoopLedgerArchiveService
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.loop_ledger_archive.v1';

    public const STATUS_OK = 'ok';

    public const STATUS_PAUSED = 'paused';

    /** Default number of most-recent raw segments kept un-sealed (hot window). */
    public const DEFAULT_RETAIN_RAW_SEGMENTS = 2;

    private ?string $storageRootOverride = null;

    public function setStorageRootForTesting(?string $dir): void
    {
        $this->storageRootOverride = $dir;
    }

    /**
     * Ledger directory for a given area/focus. Raw segments live here as
     * `*.jsonl`; sealed archive segments + index live under `archive/`.
     */
    public function ledgerDir(string $area, string $focus): string
    {
        if ($this->storageRootOverride !== null) {
            return rtrim($this->storageRootOverride, DIRECTORY_SEPARATOR);
        }

        $rel = 'atlas/software_company_stewardship/long_horizon_loop/'
            .AreaFocusSlugNormalizer::lowerUnderscoreToken($area, 'unknown', false, false).'/'.AreaFocusSlugNormalizer::lowerUnderscoreToken($focus, 'unknown', false, false).'/ledger';

        return function_exists('storage_path')
            ? storage_path($rel)
            : sys_get_temp_dir().DIRECTORY_SEPARATOR.$rel;
    }

    public function archiveDir(string $area, string $focus): string
    {
        return $this->ledgerDir($area, $focus).DIRECTORY_SEPARATOR.'archive';
    }

    // ---------- primary methods ----------

    /**
     * Plan compaction: classify raw segments into retained-raw (hot window) vs
     * sealable-for-archive, honoring the retention policy. No mutation, no
     * deletion — a plan only.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function plan(array $input = []): array
    {
        [$area, $focus] = $this->scope($input);
        $retainRaw = $this->retainRawSegments($input);

        $segments = $this->rawSegments($input, $area, $focus);
        $totalBytes = 0;
        foreach ($segments as $seg) {
            $totalBytes += (int) ($seg['bytes'] ?? 0);
        }

        // Newest segments (by name) stay raw; the rest become archive candidates.
        $retained = array_slice($segments, max(0, count($segments) - $retainRaw));
        $sealable = array_slice($segments, 0, max(0, count($segments) - $retainRaw));

        $retainedNames = array_map(static fn (array $s): string => (string) $s['name'], $retained);
        $sealableNames = array_map(static fn (array $s): string => (string) $s['name'], $sealable);

        $warnings = [];
        $diskCeiling = (int) ($input['disk_ceiling_bytes'] ?? 0);
        $diskPressure = $diskCeiling > 0 && $totalBytes >= $diskCeiling;
        if ($diskPressure && $sealable === []) {
            // Over ceiling but nothing eligible to seal — operator must intervene.
            $warnings[] = 'disk_ceiling_reached_with_no_sealable_segment';
        }

        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-810',
            'slice_id' => 'LHL-14',
            'block_action' => 'plan',
            'status' => self::STATUS_OK,
            'archive_id' => 'lla_'.substr(MissionCanonicalHash::sha256([$area, $focus, 'plan', $sealableNames, $retainedNames]), 0, 16),
            'area' => $area,
            'focus' => $focus,
            'checked_at' => AreaFocusUtcClock::atomNow(),
            'retention' => [
                'retain_raw_segments' => $retainRaw,
                'disk_ceiling_bytes' => $diskCeiling,
                'policy' => 'newest '.$retainRaw.' raw segment(s) retained; older sealed to archive; raw never deleted before sealed+indexed',
            ],
            'raw_segment_count' => count($segments),
            'raw_total_bytes' => $totalBytes,
            'retained_raw_segments' => $retainedNames,
            'sealable_segments' => $sealableNames,
            'disk_pressure' => $diskPressure,
            'next_action' => $sealable === [] ? 'nothing_to_compact' : 'compact',
            'blockers' => [],
            'warnings' => AreaFocusStringListNormalizer::uniqueStringValues($warnings),
            'claim_policy' => $this->claimPolicy(),
        ];

        $payload['report_hash'] = 'sha256:'.MissionCanonicalHash::sha256(AreaFocusLoopPayloadNormalizer::withoutVolatileReportFields($payload));

        return $payload;
    }

    /**
     * Compact: seal each sealable raw segment with a sha256, build a compact
     * cycle index + summary, and a replay manifest. If any segment fails to seal
     * (corrupt/unreadable, or an injected failure via the `seal_failures` seam),
     * PAUSE before disk exhaustion — never delete raw, never claim ok.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function compact(array $input = []): array
    {
        [$area, $focus] = $this->scope($input);
        $retainRaw = $this->retainRawSegments($input);

        $segments = $this->rawSegments($input, $area, $focus);
        $retained = array_slice($segments, max(0, count($segments) - $retainRaw));
        $sealable = array_slice($segments, 0, max(0, count($segments) - $retainRaw));
        $retainedNames = array_map(static fn (array $s): string => (string) $s['name'], $retained);

        // Injected/observed failures: segment names that cannot be sealed.
        $sealFailures = AreaFocusStringListNormalizer::stringifiedNonEmptyValues($input['seal_failures'] ?? []);

        $sealedSegments = [];
        $compactIndex = [];
        $blockers = [];
        $warnings = [];
        $commitCount = 0;
        $blockedCount = 0;
        $providerCallCount = 0;
        $totalCyclesIndexed = 0;

        foreach ($sealable as $seg) {
            $name = (string) $seg['name'];
            $records = $this->segmentRecords($input, $area, $focus, $seg);

            $unsealable = in_array($name, $sealFailures, true) || $records === null;
            if ($unsealable) {
                // PAUSE BEFORE DISK EXHAUSTION: do not delete raw, do not seal,
                // do not continue sealing later segments past a hole. Honest stop.
                $blockers[] = 'compaction_failed_segment:'.$name;
                break;
            }

            $cycleIds = [];
            foreach ($records as $rec) {
                $cid = (string) ($rec['cycle_id'] ?? ($rec['id'] ?? ''));
                $recHash = (string) ($rec['cycle_hash'] ?? ($rec['record_hash'] ?? $this->hash($rec)));
                $cycleIds[] = ['cycle_id' => $cid, 'record_hash' => $recHash];

                $kind = (string) ($rec['kind'] ?? ($rec['outcome'] ?? ''));
                if (($rec['merge_performed'] ?? false) === true || ($rec['commit'] ?? null) !== null || $kind === 'merged') {
                    $commitCount++;
                }
                if (($rec['blocked'] ?? false) === true || $kind === 'blocked') {
                    $blockedCount++;
                }
                if (($rec['provider_invoked'] ?? false) === true || ($rec['provider_call'] ?? null) !== null) {
                    $providerCallCount++;
                }
            }

            $segmentHash = $this->hash([$name, $cycleIds]);
            $sealedSegments[] = [
                'segment' => $name,
                'cycle_count' => count($records),
                'sealed_hash' => $segmentHash,
                'raw_bytes' => (int) ($seg['bytes'] ?? 0),
                'archive_ref' => 'archive/'.$this->archiveName($name),
                'raw_preserved' => true,
            ];
            $compactIndex[] = [
                'segment' => $name,
                'sealed_hash' => $segmentHash,
                'cycles' => $cycleIds,
            ];
            $totalCyclesIndexed += count($records);
        }

        $paused = $blockers !== [];
        $status = $paused ? self::STATUS_PAUSED : self::STATUS_OK;

        if ($paused) {
            $warnings[] = 'raw_evidence_preserved_no_deletion_on_pause';
        }
        if (! $paused && $sealable === []) {
            $warnings[] = 'no_sealable_segment';
        }

        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-810',
            'slice_id' => 'LHL-14',
            'block_action' => 'compact',
            'status' => $status,
            'archive_id' => 'lla_'.substr(MissionCanonicalHash::sha256([$area, $focus, 'compact', $compactIndex, $retainedNames]), 0, 16),
            'area' => $area,
            'focus' => $focus,
            'checked_at' => AreaFocusUtcClock::atomNow(),
            'retention' => [
                'retain_raw_segments' => $retainRaw,
                'policy' => 'newest '.$retainRaw.' raw segment(s) retained; raw never deleted before sealed+indexed',
            ],
            'sealed_segments' => $sealedSegments,
            'sealed_segment_count' => count($sealedSegments),
            'retained_raw_segments' => $retainedNames,
            'compact_index' => $compactIndex,
            'compact_index_hash' => $this->hash($compactIndex),
            'cycles_indexed' => $totalCyclesIndexed,
            'summary' => [
                'commit_count' => $commitCount,
                'blocked_count' => $blockedCount,
                'provider_call_count' => $providerCallCount,
            ],
            'replay_available' => ! $paused,
            'next_action' => $paused ? 'pause_resolve_corrupt_segment' : 'replay',
            'blockers' => AreaFocusStringListNormalizer::uniqueStringValues($blockers),
            'warnings' => AreaFocusStringListNormalizer::uniqueStringValues($warnings),
            'claim_policy' => $this->claimPolicy(),
        ];

        $payload['report_hash'] = 'sha256:'.MissionCanonicalHash::sha256(AreaFocusLoopPayloadNormalizer::withoutVolatileReportFields($payload));

        return $payload;
    }

    /**
     * Build a replay manifest reconstructing the full cycle stream across sealed
     * archive segments AND live raw segments, in canonical order. A gap (a sealed
     * hash that no longer matches its raw cycles, or a missing referenced segment)
     * pauses replay rather than returning a silently-incomplete stream.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function replayManifest(array $input = []): array
    {
        [$area, $focus] = $this->scope($input);
        $retainRaw = $this->retainRawSegments($input);

        $segments = $this->rawSegments($input, $area, $focus);
        $sealable = array_slice($segments, 0, max(0, count($segments) - $retainRaw));
        $retained = array_slice($segments, max(0, count($segments) - $retainRaw));

        // Prefer an explicit compact index from the seam (e.g. compact()'s output
        // fed back); else derive it from the current sealable segments.
        $compactIndex = is_array($input['compact_index'] ?? null)
            ? $input['compact_index']
            : $this->deriveIndex($input, $area, $focus, $sealable);

        $manifest = [];
        $blockers = [];
        $warnings = [];
        $cycleOrder = [];

        // 1) Archived (sealed) segments, in index order.
        foreach ($compactIndex as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $name = (string) ($entry['segment'] ?? '');
            $cycles = is_array($entry['cycles'] ?? null) ? $entry['cycles'] : [];
            $ids = [];
            foreach ($cycles as $c) {
                if (is_array($c)) {
                    $ids[] = (string) ($c['cycle_id'] ?? '');
                }
            }
            $expectedHash = (string) ($entry['sealed_hash'] ?? '');
            $recomputed = $this->hash([$name, array_values($cycles)]);
            $intact = $expectedHash === '' || $expectedHash === $recomputed;
            if (! $intact) {
                $blockers[] = 'archive_segment_integrity_failed:'.$name;
            }
            $manifest[] = [
                'source' => 'archive',
                'segment' => $name,
                'cycle_count' => count($ids),
                'sealed_hash' => $expectedHash !== '' ? $expectedHash : $recomputed,
                'integrity_ok' => $intact,
            ];
            foreach ($ids as $id) {
                $cycleOrder[] = $id;
            }
        }

        // 2) Live raw (un-sealed, hot) segments, appended in order.
        foreach ($retained as $seg) {
            $name = (string) $seg['name'];
            $records = $this->segmentRecords($input, $area, $focus, $seg);
            if ($records === null) {
                $blockers[] = 'raw_segment_unreadable:'.$name;

                continue;
            }
            $ids = [];
            foreach ($records as $rec) {
                $ids[] = (string) ($rec['cycle_id'] ?? ($rec['id'] ?? ''));
            }
            $manifest[] = [
                'source' => 'raw',
                'segment' => $name,
                'cycle_count' => count($ids),
                'sealed_hash' => null,
                'integrity_ok' => true,
            ];
            foreach ($ids as $id) {
                $cycleOrder[] = $id;
            }
        }

        $spansArchive = false;
        $spansRaw = false;
        foreach ($manifest as $m) {
            if (($m['source'] ?? '') === 'archive') {
                $spansArchive = true;
            }
            if (($m['source'] ?? '') === 'raw') {
                $spansRaw = true;
            }
        }

        $paused = $blockers !== [];
        $status = $paused ? self::STATUS_PAUSED : self::STATUS_OK;
        if ($paused) {
            $warnings[] = 'replay_incomplete_raw_evidence_preserved';
        }

        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-810',
            'slice_id' => 'LHL-14',
            'block_action' => 'replay',
            'status' => $status,
            'archive_id' => 'lla_'.substr(MissionCanonicalHash::sha256([$area, $focus, 'replay', $cycleOrder]), 0, 16),
            'area' => $area,
            'focus' => $focus,
            'checked_at' => AreaFocusUtcClock::atomNow(),
            'manifest' => $manifest,
            'segment_count' => count($manifest),
            'cycle_order' => $cycleOrder,
            'total_cycles' => count($cycleOrder),
            'spans_archive_segments' => $spansArchive,
            'spans_raw_segments' => $spansRaw,
            'crosses_archive_and_raw' => $spansArchive && $spansRaw,
            'replay_complete' => ! $paused,
            'next_action' => $paused ? 'pause_resolve_integrity_gap' : 'replay_ready',
            'blockers' => AreaFocusStringListNormalizer::uniqueStringValues($blockers),
            'warnings' => AreaFocusStringListNormalizer::uniqueStringValues($warnings),
            'claim_policy' => $this->claimPolicy(),
        ];

        $payload['report_hash'] = 'sha256:'.MissionCanonicalHash::sha256(AreaFocusLoopPayloadNormalizer::withoutVolatileReportFields($payload));

        return $payload;
    }

    // ---------- segment discovery (input seam first, real fs fallback) ----------

    /**
     * Discover raw cycle segments. Input-seam first: `$input['segments']` is a
     * list of {name, bytes?, records?} (records => inline cycle records). When
     * absent, read `*.jsonl` files from the ledger dir (sorted by name).
     *
     * @param  array<string,mixed>  $input
     * @return list<array<string,mixed>>
     */
    private function rawSegments(array $input, string $area, string $focus): array
    {
        if (isset($input['segments']) && is_array($input['segments'])) {
            $out = [];
            foreach ($input['segments'] as $seg) {
                if (is_array($seg) && isset($seg['name']) && is_string($seg['name'])) {
                    $records = isset($seg['records']) && is_array($seg['records']) ? array_values($seg['records']) : null;
                    $bytes = (int) ($seg['bytes'] ?? ($records !== null ? strlen((string) json_encode($records)) : 0));
                    $out[] = ['name' => $seg['name'], 'bytes' => $bytes, 'records' => $records, 'path' => null];
                }
            }
            usort($out, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

            return array_values($out);
        }

        $dir = $this->ledgerDir($area, $focus);
        $files = is_dir($dir) ? AreaFocusStringListNormalizer::coercedStringValues(glob($dir.DIRECTORY_SEPARATOR.'*.jsonl')) : [];
        sort($files);
        $out = [];
        foreach ($files as $path) {
            $out[] = [
                'name' => basename($path),
                'bytes' => is_file($path) ? (int) (filesize($path) ?: 0) : 0,
                'records' => null,
                'path' => $path,
            ];
        }

        return $out;
    }

    /**
     * Resolve the cycle records for one segment. Inline records win; else parse
     * the JSONL file. Returns null on an unreadable/corrupt segment (a corrupt
     * tail is treated as unsealable so compaction pauses rather than loses data).
     *
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $segment
     * @return list<array<string,mixed>>|null
     */
    private function segmentRecords(array $input, string $area, string $focus, array $segment): ?array
    {
        if (isset($segment['records']) && is_array($segment['records'])) {
            return AreaFocusLoopPayloadNormalizer::listOfArrays($segment['records']);
        }

        $path = $segment['path'] ?? null;
        if (! is_string($path) || ! is_file($path)) {
            return null;
        }

        try {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        } catch (Throwable) {
            return null;
        }
        if ($lines === false) {
            return null;
        }

        $records = [];
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (! is_array($decoded)) {
                // Corrupt tail => unsealable; pause beats silent loss.
                return null;
            }
            $records[] = $decoded;
        }

        return $records;
    }

    /**
     * Derive a compact index (segment + sealed_hash + cycles) for replay when no
     * explicit index seam is supplied.
     *
     * @param  array<string,mixed>  $input
     * @param  list<array<string,mixed>>  $sealable
     * @return list<array<string,mixed>>
     */
    private function deriveIndex(array $input, string $area, string $focus, array $sealable): array
    {
        $index = [];
        foreach ($sealable as $seg) {
            $name = (string) $seg['name'];
            $records = $this->segmentRecords($input, $area, $focus, $seg);
            if ($records === null) {
                // Mark a corrupt segment with a sentinel so replay flags integrity.
                $index[] = ['segment' => $name, 'sealed_hash' => 'sha256:UNREADABLE', 'cycles' => []];

                continue;
            }
            $cycles = [];
            foreach ($records as $rec) {
                $cycles[] = [
                    'cycle_id' => (string) ($rec['cycle_id'] ?? ($rec['id'] ?? '')),
                    'record_hash' => (string) ($rec['cycle_hash'] ?? ($rec['record_hash'] ?? $this->hash($rec))),
                ];
            }
            $index[] = [
                'segment' => $name,
                'sealed_hash' => $this->hash([$name, $cycles]),
                'cycles' => $cycles,
            ];
        }

        return $index;
    }

    // ---------- helpers ----------

    /**
     * @param  array<string,mixed>  $input
     * @return array{0:string,1:string}
     */
    private function scope(array $input): array
    {
        $area = trim((string) ($input['area'] ?? 'agentic_engineering_os')) ?: 'agentic_engineering_os';
        $focus = trim((string) ($input['focus'] ?? 'dev_forge')) ?: 'dev_forge';

        return [$area, $focus];
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function retainRawSegments(array $input): int
    {
        $retain = array_key_exists('retain_raw_segments', $input)
            ? (int) $input['retain_raw_segments']
            : self::DEFAULT_RETAIN_RAW_SEGMENTS;

        return max(0, $retain);
    }

    private function archiveName(string $rawName): string
    {
        $base = preg_replace('/\.jsonl$/', '', $rawName) ?? $rawName;

        return $base.'.sealed.json';
    }

    private function hash(mixed $value): string
    {
        return 'sha256:'.MissionCanonicalHash::sha256($value);
    }

    /**
     * @return array<string,bool|string>
     */
    private function claimPolicy(): array
    {
        return [
            'read_only' => true,
            'runs_provider' => false,
            'runs_merge' => false,
            'deletes_branches' => false,
            'deletes_raw_before_sealed' => false,
            'mutates_cycle_truth' => false,
            'blocked_never_dressed_as_ready' => true,
            'paused_never_dressed_as_ok' => true,
        ];
    }
}
