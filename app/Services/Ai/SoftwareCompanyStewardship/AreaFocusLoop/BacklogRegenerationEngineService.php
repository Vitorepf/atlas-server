<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * AP-810 / LHL-10 — Backlog Regeneration Engine (AP-809).
 *
 * When the loop's executable backlog runs dry, this engine re-fills it from REAL
 * signal sources — never from filler. It answers exactly one question:
 *
 *   > Given the current runtime gaps / blocked cycles / quality drift / evidence
 *   > gaps / context-memory-retrieval gaps / promoted Factory docs, what canonical
 *   > findings (and, for broad work, bounded Self-Construction packet proposals)
 *   > should re-enter the backlog, ranked by multiplier * safety * executability?
 *
 * This service is read-only / deterministic / input-seam driven. It NEVER invokes
 * a provider, NEVER runs the loop, NEVER merges, NEVER deletes a branch/worktree,
 * NEVER mutates code. Every source is an `$input[...]` seam; absence of a source
 * simply contributes nothing. Empty sources => status=empty (NOT a fabricated
 * recovery item).
 *
 * Honesty rules (operator does not accept false claims):
 *   - FILLER / starvation-recovery items can NEVER satisfy regeneration. They are
 *     excluded into `rejected_filler[]` with an explicit reason and are NEVER
 *     counted as regenerated findings or ranked work.
 *   - "Broad" findings (whole-feature / cross-system / high severity) become
 *     bounded Self-Construction PACKET PROPOSALS (proposal-only, status=planned),
 *     never an instruction to "implement the whole feature".
 *   - empty sources => status=empty, NEVER a synthetic finding dressed as work.
 *
 * Contract: AP-809; AP-810 build contract slice LHL-10.
 * Reference shape: LoopPreflightCycleFirewallService (report skeleton + seams).
 */
final class BacklogRegenerationEngineService
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.loop_backlog_regeneration.v1';

    public const STATUS_OK = 'ok';

    public const STATUS_EMPTY = 'empty';

    /** Source seams that feed regeneration (AP-809). Each maps to a canonical kind. */
    public const SOURCE_RUNTIME_GAPS = 'runtime_gaps';

    public const SOURCE_BLOCKED_CYCLES = 'blocked_cycles';

    public const SOURCE_QUALITY_DRIFT = 'quality_drift';

    public const SOURCE_EVIDENCE_GAPS = 'evidence_gaps';

    public const SOURCE_CONTEXT_GAPS = 'context_gaps';

    public const SOURCE_PROMOTED_DOCS = 'promoted_docs';

    /** All known source seams, in deterministic order. */
    private const SOURCES = [
        self::SOURCE_RUNTIME_GAPS,
        self::SOURCE_BLOCKED_CYCLES,
        self::SOURCE_QUALITY_DRIFT,
        self::SOURCE_EVIDENCE_GAPS,
        self::SOURCE_CONTEXT_GAPS,
        self::SOURCE_PROMOTED_DOCS,
    ];

    /** Map source seam => canonical finding kind. */
    private const SOURCE_KIND = [
        self::SOURCE_RUNTIME_GAPS => 'runtime_gap',
        self::SOURCE_BLOCKED_CYCLES => 'blocked_cycle_gap',
        self::SOURCE_QUALITY_DRIFT => 'quality_drift_gap',
        self::SOURCE_EVIDENCE_GAPS => 'evidence_gap',
        self::SOURCE_CONTEXT_GAPS => 'context_memory_retrieval_gap',
        self::SOURCE_PROMOTED_DOCS => 'promoted_doc_gap',
    ];

    /** Severities that demand a bounded Self-Construction packet proposal. */
    private const BROAD_SEVERITIES = ['high', 'critical'];

    /** A bounded packet may touch at most this many files; bigger is not "bounded". */
    public const MAX_PACKET_ALLOWED_FILES = 8;

    /** Rejection reason taxonomy — filler is NEVER counted as regeneration. */
    public const REJECT_FILLER = 'filler_cannot_satisfy_regeneration';

    public const REJECT_STARVATION_RECOVERY = 'starvation_recovery_cannot_satisfy_regeneration';

    public const REJECT_MISSING_SIGNAL = 'missing_source_or_canonical_reason';

    /**
     * Single entrypoint. Every key is optional; the diagnostic default analyzes an
     * empty state (no sources) and returns status=empty without crashing.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function regenerate(array $input = []): array
    {
        // A wiring-phase `fixture` (from --fixture-file) may carry the whole source
        // bundle; merge it under the explicit input so direct keys still win.
        $input = $this->mergeFixture($input);

        $area = trim((string) ($input['area'] ?? 'agentic_engineering_os')) ?: 'agentic_engineering_os';
        $focus = trim((string) ($input['focus'] ?? 'dev_forge')) ?: 'dev_forge';
        $runId = trim((string) ($input['run_id'] ?? ''));

        $warnings = [];

        /** @var list<array<string,mixed>> $regenerated */
        $regenerated = [];
        /** @var list<array<string,mixed>> $packets */
        $packets = [];
        /** @var list<array<string,mixed>> $rejected */
        $rejected = [];
        /** @var array<string,int> $sourceCounts */
        $sourceCounts = [];

        foreach (self::SOURCES as $source) {
            $items = $this->itemsFor($input, $source);
            $sourceCounts[$source] = 0;

            foreach ($items as $raw) {
                $item = is_array($raw) ? $raw : [];

                // NEGATIVE INVARIANT: filler / recovery can NEVER satisfy regeneration.
                $fillerReason = $this->fillerRejectionReason($item);
                if ($fillerReason !== null) {
                    $rejected[] = $this->rejectionRecord($item, $source, $fillerReason);

                    continue;
                }

                $finding = $this->toCanonicalFinding($item, $source, $area, $focus);
                if ($finding === null) {
                    // No usable signal (no reason/no source) — reject, never invent.
                    $rejected[] = $this->rejectionRecord($item, $source, self::REJECT_MISSING_SIGNAL);

                    continue;
                }

                $regenerated[] = $finding;
                $sourceCounts[$source]++;

                if ($this->isBroad($finding)) {
                    $packets[] = $this->toPacketProposal($finding, $area, $focus);
                }
            }
        }

        // Deterministic de-dup by finding_id (later duplicates fold away, stable order).
        $regenerated = $this->dedupeById($regenerated);
        $packets = $this->dedupeById($packets, 'packet_id');

        // Ranking by multiplier * safety * executability, descending; ties broken by
        // finding_id so the order is stable for the hash.
        $ranking = $this->buildRanking($regenerated);

        $status = $regenerated === [] ? self::STATUS_EMPTY : self::STATUS_OK;
        if ($status === self::STATUS_EMPTY && $rejected !== []) {
            $warnings[] = 'only_filler_or_unusable_signals_present';
        }

        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-809',
            'slice_id' => 'LHL-10',
            'status' => $status,
            'regeneration_id' => 'lbr_'.substr(MissionCanonicalHash::sha256([
                $area,
                $focus,
                $runId,
                $this->stableIdList($regenerated),
                $this->stableIdList($packets, 'packet_id'),
            ]), 0, 16),
            'run_id' => $runId,
            'area' => $area,
            'focus' => $focus,
            'checked_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'source_counts' => $sourceCounts,
            'regenerated_count' => count($regenerated),
            'proposed_packet_count' => count($packets),
            'rejected_filler_count' => count($rejected),
            'regenerated_findings' => $regenerated,
            'proposed_packets' => $packets,
            'ranking' => $ranking,
            'rejected_filler' => $rejected,
            'warnings' => array_values(array_unique($warnings)),
            'next_action' => $status === self::STATUS_OK ? 'enqueue_regenerated_backlog' : 'no_regeneration_backlog_empty',
            'claim_policy' => [
                'read_only' => true,
                'runs_provider' => false,
                'runs_loop' => false,
                'runs_merge' => false,
                'deletes_branches' => false,
                'filler_counted_as_work' => false,
                'blocked_never_dressed_as_ready' => true,
            ],
        ];

        $payload['report_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->withoutVolatile($payload));

        return $payload;
    }

    // ---------------------------------------------------------------- sources

    /**
     * Pull the raw item list for a source seam. Accepts both the flat seam
     * (`$input['runtime_gaps']`) and a nested bundle (`$input['sources']['runtime_gaps']`).
     *
     * @param  array<string,mixed>  $input
     * @return list<mixed>
     */
    private function itemsFor(array $input, string $source): array
    {
        $value = $input[$source] ?? null;
        if ($value === null && is_array($input['sources'] ?? null)) {
            $value = $input['sources'][$source] ?? null;
        }

        return is_array($value) ? array_values($value) : [];
    }

    // ---------------------------------------------------------------- filler

    /**
     * Return a rejection reason if the item is filler / starvation-recovery,
     * otherwise null. Filler is NEVER admissible regeneration.
     *
     * @param  array<string,mixed>  $item
     */
    private function fillerRejectionReason(array $item): ?string
    {
        $kind = strtolower(trim((string) ($item['kind'] ?? '')));
        $origin = strtolower(trim((string) ($item['origin_type'] ?? ($item['source'] ?? ''))));
        $title = strtolower((string) ($item['title'] ?? ''));

        $isRecovery = (bool) ($item['is_recovery'] ?? false)
            || (bool) ($item['is_starvation_recovery'] ?? false)
            || in_array($origin, ['starvation_recovery', 'recovery'], true)
            || str_contains($title, 'starvation recovery');
        if ($isRecovery) {
            return self::REJECT_STARVATION_RECOVERY;
        }

        $isFiller = (bool) ($item['is_filler'] ?? false)
            || in_array($kind, ['filler'], true)
            || $origin === 'filler'
            || str_contains($title, 'filler')
            // routine missing-test filler with no strategic value is filler too.
            || ($kind === 'missing_test' && ! (bool) ($item['strategic_value'] ?? false));
        if ($isFiller) {
            return self::REJECT_FILLER;
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>
     */
    private function rejectionRecord(array $item, string $source, string $reason): array
    {
        return [
            'source' => $source,
            'title' => trim((string) ($item['title'] ?? '')),
            'kind' => strtolower(trim((string) ($item['kind'] ?? ''))),
            'rejection_reason' => $reason,
            'counted_as_regeneration' => false,
        ];
    }

    // ---------------------------------------------------------------- mapping

    /**
     * Map a raw source item to a canonical-finding-shaped row, or null when there is
     * no usable signal (no canonical reason AND no evidence/source reference).
     *
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>|null
     */
    private function toCanonicalFinding(array $item, string $source, string $area, string $focus): ?array
    {
        $title = trim((string) ($item['title'] ?? ''));
        $reason = trim((string) ($item['why_it_matters'] ?? ($item['canonical_reason'] ?? ($item['detail'] ?? ''))));
        $evidence = AreaFocusStringListNormalizer::preserveStrings($item['evidence_refs'] ?? []);
        $sourceDoc = trim((string) ($item['source_doc'] ?? ''));

        // No canonical reason AND no evidence/source => unusable, do not invent one.
        if ($title === '' && $reason === '' && $evidence === [] && $sourceDoc === '') {
            return null;
        }
        if ($reason === '' && $evidence === [] && $sourceDoc === '') {
            return null;
        }

        $kind = strtolower(trim((string) ($item['kind'] ?? ''))) ?: self::SOURCE_KIND[$source];
        $severity = $this->normalizeSeverity((string) ($item['severity'] ?? 'medium'));
        $crossSystem = (bool) ($item['cross_system'] ?? false);
        $whole = $this->isWholeFeatureText($item);

        $multiplier = $this->normalizeScore($item['multiplier'] ?? null, $this->defaultMultiplier($severity, $source));
        $safety = $this->normalizeScore($item['safety'] ?? null, $this->defaultSafety($severity, $crossSystem));
        $executability = $this->normalizeScore($item['executability'] ?? null, $this->defaultExecutability($crossSystem, $whole));

        $findingId = $this->findingId($item, $source, $title, $reason, $area, $focus);

        return [
            'finding_id' => $findingId,
            'kind' => $kind,
            'source' => $source,
            'origin_type' => 'backlog_regeneration',
            'area' => $area,
            'focus' => $focus,
            'title' => $title !== '' ? $title : ucfirst(str_replace('_', ' ', self::SOURCE_KIND[$source])),
            'why_it_matters' => $reason !== '' ? $reason : ('Regenerated from '.$source.' signal.'),
            'severity' => $severity,
            'cross_system' => $crossSystem,
            'whole_feature' => $whole,
            'evidence_refs' => $evidence,
            'source_doc' => $sourceDoc !== '' ? $sourceDoc : null,
            'requires_bounded_packet' => $this->isBroadShape($severity, $crossSystem, $whole),
            'multiplier' => $multiplier,
            'safety' => $safety,
            'executability' => $executability,
            'rank_score' => $this->roundScore($multiplier * $safety * $executability),
        ];
    }

    /**
     * Is this canonical finding "broad" (needs a bounded packet proposal)?
     *
     * @param  array<string,mixed>  $finding
     */
    private function isBroad(array $finding): bool
    {
        return (bool) ($finding['requires_bounded_packet'] ?? false);
    }

    private function isBroadShape(string $severity, bool $crossSystem, bool $whole): bool
    {
        return in_array($severity, self::BROAD_SEVERITIES, true) || $crossSystem || $whole;
    }

    /**
     * Build a bounded, proposal-only Self-Construction packet for a broad finding.
     * Always status=planned, never "implement the whole feature".
     *
     * @param  array<string,mixed>  $finding
     * @return array<string,mixed>
     */
    private function toPacketProposal(array $finding, string $area, string $focus): array
    {
        $parent = (string) $finding['finding_id'];
        $packetId = 'pkt_'.substr(MissionCanonicalHash::sha256([$parent, 'slice', 1]), 0, 16);

        return [
            'packet_id' => $packetId,
            'parent_finding_id' => $parent,
            'slice_sequence' => 1,
            'active_slice_id' => $packetId,
            'status' => 'planned',
            'proposal_only' => true,
            'auto_execution_allowed' => false,
            'objective' => 'Execute ONLY bounded slice 1 of: '.(string) $finding['title'],
            'allowed_files' => [],
            'forbidden_files' => [],
            'required_tests' => [],
            'expected_diff_shape' => 'small_focused',
            'stop_conditions' => ['scope_violation', 'validation_failed'],
            'owner_runtime' => null,
            'dependency_state' => 'not_required',
            'risk_level' => (string) $finding['severity'],
            'cross_system' => (bool) $finding['cross_system'],
            'why_it_matters' => (string) $finding['why_it_matters'],
            'rank_score' => $finding['rank_score'],
        ];
    }

    // ---------------------------------------------------------------- ranking

    /**
     * Rank regenerated findings by multiplier * safety * executability, descending.
     * Ties broken by finding_id for a stable, deterministic order.
     *
     * @param  list<array<string,mixed>>  $findings
     * @return list<array<string,mixed>>
     */
    private function buildRanking(array $findings): array
    {
        $rows = [];
        foreach ($findings as $f) {
            $rows[] = [
                'finding_id' => (string) $f['finding_id'],
                'multiplier' => $f['multiplier'],
                'safety' => $f['safety'],
                'executability' => $f['executability'],
                'rank_score' => $f['rank_score'],
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            $cmp = $b['rank_score'] <=> $a['rank_score'];
            if ($cmp !== 0) {
                return $cmp;
            }

            return strcmp((string) $a['finding_id'], (string) $b['finding_id']);
        });

        $ranked = [];
        $position = 1;
        foreach ($rows as $row) {
            $row['rank'] = $position++;
            $ranked[] = $row;
        }

        return $ranked;
    }

    // ---------------------------------------------------------------- scoring helpers

    private function defaultMultiplier(string $severity, string $source): float
    {
        $base = match ($severity) {
            'critical' => 0.95,
            'high' => 0.8,
            'medium' => 0.55,
            default => 0.35,
        };
        // runtime + blocked-cycle gaps carry more loop multiplier than doc promotion.
        $bonus = match ($source) {
            self::SOURCE_RUNTIME_GAPS, self::SOURCE_BLOCKED_CYCLES => 0.05,
            default => 0.0,
        };

        return $this->clamp($base + $bonus);
    }

    private function defaultSafety(string $severity, bool $crossSystem): float
    {
        $base = match ($severity) {
            'critical' => 0.5,
            'high' => 0.65,
            'medium' => 0.8,
            default => 0.9,
        };
        if ($crossSystem) {
            $base -= 0.15;
        }

        return $this->clamp($base);
    }

    private function defaultExecutability(bool $crossSystem, bool $whole): float
    {
        $base = 0.85;
        if ($crossSystem) {
            $base -= 0.2;
        }
        if ($whole) {
            $base -= 0.3;
        }

        return $this->clamp($base);
    }

    private function normalizeScore(mixed $value, float $default): float
    {
        if ($value === null || ! is_numeric($value)) {
            return $this->roundScore($default);
        }

        return $this->roundScore($this->clamp((float) $value));
    }

    private function clamp(float $v): float
    {
        if ($v < 0.0) {
            return 0.0;
        }
        if ($v > 1.0) {
            return 1.0;
        }

        return $v;
    }

    private function roundScore(float $v): float
    {
        return round($v, 4);
    }

    // ---------------------------------------------------------------- misc helpers

    private function normalizeSeverity(string $value): string
    {
        $value = strtolower(trim($value));

        return in_array($value, ['low', 'medium', 'high', 'critical'], true) ? $value : 'medium';
    }

    /**
     * @param  array<string,mixed>  $item
     */
    private function isWholeFeatureText(array $item): bool
    {
        if ((bool) ($item['whole_feature'] ?? false)) {
            return true;
        }
        $text = strtolower(trim(
            (string) ($item['title'] ?? '').' '.(string) ($item['detail'] ?? '').' '.(string) ($item['why_it_matters'] ?? '')
        ));
        if ($text === '') {
            return false;
        }
        foreach (['implement the whole feature', 'implement whole feature', 'implement the entire feature', 'build the whole feature', 'whole feature end to end', 'entire feature'] as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Deterministic, content-derived finding id (stable across runs for same input).
     *
     * @param  array<string,mixed>  $item
     */
    private function findingId(array $item, string $source, string $title, string $reason, string $area, string $focus): string
    {
        $explicit = trim((string) ($item['finding_id'] ?? ($item['id'] ?? '')));
        if ($explicit !== '') {
            return $explicit;
        }

        return 'REGEN-'.strtoupper(substr(MissionCanonicalHash::sha256([
            $area,
            $focus,
            $source,
            $title,
            $reason,
        ]), 0, 12));
    }

    /**
     * De-dupe a list of associative rows by an id key, keeping the first occurrence
     * and preserving order (deterministic).
     *
     * @param  list<array<string,mixed>>  $rows
     * @return list<array<string,mixed>>
     */
    private function dedupeById(array $rows, string $idKey = 'finding_id'): array
    {
        $seen = [];
        $out = [];
        foreach ($rows as $row) {
            $id = (string) ($row[$idKey] ?? '');
            if ($id !== '' && isset($seen[$id])) {
                continue;
            }
            if ($id !== '') {
                $seen[$id] = true;
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return list<string>
     */
    private function stableIdList(array $rows, string $idKey = 'finding_id'): array
    {
        $ids = array_map(static fn (array $r): string => (string) ($r[$idKey] ?? ''), $rows);
        sort($ids);

        return array_values($ids);
    }

    /**
     * A wiring-phase `fixture` may carry the whole source bundle; fold it under the
     * explicit input so direct keys still take precedence (input-seam composition).
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function mergeFixture(array $input): array
    {
        $fixture = $input['fixture'] ?? null;
        if (! is_array($fixture) || $fixture === []) {
            return $input;
        }
        unset($input['fixture']);

        return array_merge($fixture, $input);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function withoutVolatile(array $payload): array
    {
        unset($payload['checked_at'], $payload['report_hash']);

        return $payload;
    }
}
