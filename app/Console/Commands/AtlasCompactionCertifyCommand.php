<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AtlasLongHorizonCompactionReceipt;
use App\Services\Ai\Cognition\Watchdog\AtlasAcosWatchdogHealthService;
use App\Services\Ai\LongHorizon\AtlasLongHorizonCanon;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;
use App\Support\YesNo;

final class AtlasCompactionCertifyCommand extends Command
{
    private const SCHEMA_VERSION = 'atlas.compaction.certify.v1';

    private const SHADOW_SCHEMA_VERSION = 'atlas.context.must_keep_budget_allocation.shadow.v1';

    protected $signature = 'atlas:compaction:certify
        {--days=14 : Audit window in days}
        {--json : Emit canonical JSON}';

    protected $description = 'CPT-10 — certify compaction 10/10 from real source events and receipts.';

    public function handle(AtlasAcosWatchdogHealthService $health): int
    {
        $payload = $this->certify($health);

        if ((bool) $this->option('json')) {
            $this->line(json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
            ) ?: '{}');
        } else {
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('certified', YesNo::trueFalse((bool) $payload['certified']));
            foreach ((array) ($payload['blocking'] ?? []) as $blocker) {
                $this->components->twoColumnDetail('blocking', (string) $blocker);
            }
        }

        return ($payload['certified'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string,mixed>
     */
    private function certify(AtlasAcosWatchdogHealthService $health): array
    {
        $days = max(1, (int) $this->option('days'));
        $since = CarbonImmutable::now('UTC')->subDays($days);
        $receipts = $this->loadReceipts($since);
        $shadowRows = $this->loadShadowRows($since);

        $mechanisms = [
            'conversation' => $this->conversationMechanism($receipts, $since),
            'handoff' => $this->handoffMechanism($receipts, $since),
            'long_horizon' => $this->longHorizonMechanism($receipts),
            'payload' => $this->payloadMechanism($shadowRows),
        ];

        $checks = [
            'coverage' => $this->coverageCheck($receipts),
            'context_retention_score' => $this->contextRetentionScoreCheck($receipts),
            'cpt_08_shadow_evidence' => $this->cpt08ShadowEvidenceCheck($shadowRows),
            'cpt_09_soak' => $this->cpt09SoakCheck($health),
        ];

        $blocking = [];
        $hasFailure = false;
        $hasPending = false;

        foreach ($mechanisms as $mechanism => $result) {
            $status = (string) ($result['status'] ?? 'unknown');
            if ($status === 'pass') {
                continue;
            }
            if ($status === 'fail') {
                $hasFailure = true;
                $blocking[] = 'event_receipt_mismatch:'.$mechanism;
            } elseif ($status === 'insufficient_sample') {
                $hasPending = true;
                $blocking[] = 'zero_denominator:'.$mechanism;
            } else {
                $hasPending = true;
                $blocking[] = 'source_unavailable:'.$mechanism;
            }
        }

        foreach ($checks as $check => $result) {
            $status = (string) ($result['status'] ?? 'unknown');
            if ($status === 'pass') {
                continue;
            }
            if ($status === 'fail') {
                $hasFailure = true;
                $blocking[] = $check.'_failed';
            } elseif ($status === 'pending_soak') {
                $hasPending = true;
                $blocking[] = 'pending_soak';
            } elseif ($status === 'insufficient_sample') {
                $hasPending = true;
                $blocking[] = $check.'_zero_denominator';
            } else {
                $hasPending = true;
                $blocking[] = $check.'_pending';
            }
        }

        $blocking = array_values(array_unique($blocking));
        $certified = $blocking === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $certified ? 'certified' : ($hasFailure ? 'failed' : 'pending'),
            'certified' => $certified,
            'window' => [
                'days' => $days,
                'since' => $since->toIso8601String(),
            ],
            'minimum_samples' => [
                'conversation_events' => 1,
                'handoff_events' => 1,
                'long_horizon_receipts' => 1,
                'payload_shadow_rows' => 1,
                'certified_requires_all_mechanisms' => true,
            ],
            'mechanisms' => $mechanisms,
            'checks' => $checks,
            'blocking' => $blocking,
            'claim_policy' => [
                'read_only' => true,
                'enumerates_source_events_not_receipt_only_queries' => true,
                'zero_denominator_never_passes' => true,
                'source_none_receipts_do_not_prove_coverage' => true,
                'author_judge_separated' => true,
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return Collection<int,AtlasLongHorizonCompactionReceipt>
     */
    private function loadReceipts(CarbonImmutable $since): Collection
    {
        if (! DatabaseTableAvailability::has('atlas_long_horizon_compaction_receipts')) {
            return collect();
        }

        return AtlasLongHorizonCompactionReceipt::query()
            ->where('created_at', '>=', $since)
            ->get();
    }

    /**
     * @param  Collection<int,AtlasLongHorizonCompactionReceipt>  $receipts
     * @return array<string,mixed>
     */
    private function conversationMechanism(Collection $receipts, CarbonImmutable $since): array
    {
        if (! DatabaseTableAvailability::has('ai_compactions')) {
            return $this->mechanismUnavailable('ai_compactions_table_missing');
        }

        $events = DB::table('ai_compactions')->where('created_at', '>=', $since)->get();
        $conversationReceipts = $receipts->filter(
            static fn (AtlasLongHorizonCompactionReceipt $receipt): bool => $receipt->scope_type === AtlasLongHorizonCanon::SCOPE_TYPE_CONVERSATION
        );

        return $this->matchSourceEvents(
            mechanism: 'conversation',
            sourceTable: 'ai_compactions',
            events: $events,
            receipts: $conversationReceipts,
            eventRefPrefix: 'ai_compaction:'
        );
    }

    /**
     * @param  Collection<int,AtlasLongHorizonCompactionReceipt>  $receipts
     * @return array<string,mixed>
     */
    private function handoffMechanism(Collection $receipts, CarbonImmutable $since): array
    {
        if (! DatabaseTableAvailability::has('ai_provider_handoffs')) {
            return $this->mechanismUnavailable('ai_provider_handoffs_table_missing');
        }

        $events = DB::table('ai_provider_handoffs')->where('created_at', '>=', $since)->get();
        $handoffReceipts = $receipts->filter(
            static fn (AtlasLongHorizonCompactionReceipt $receipt): bool => $receipt->scope_type === AtlasLongHorizonCanon::SCOPE_TYPE_HANDOFF
        );

        return $this->matchSourceEvents(
            mechanism: 'handoff',
            sourceTable: 'ai_provider_handoffs',
            events: $events,
            receipts: $handoffReceipts,
            eventRefPrefix: 'ai_provider_handoff:'
        );
    }

    /**
     * @param  Collection<int,object>  $events
     * @param  Collection<int,AtlasLongHorizonCompactionReceipt>  $receipts
     * @return array<string,mixed>
     */
    private function matchSourceEvents(
        string $mechanism,
        string $sourceTable,
        Collection $events,
        Collection $receipts,
        string $eventRefPrefix,
    ): array {
        if ($events->isEmpty()) {
            return [
                'status' => 'insufficient_sample',
                'source_table' => $sourceTable,
                'event_count' => 0,
                'receipt_count' => $receipts->count(),
                'matched_event_count' => 0,
                'unmatched_event_count' => 0,
                'reason' => 'no_source_events_in_window',
            ];
        }

        $matched = 0;
        $unmatched = [];
        foreach ($events as $event) {
            $eventId = (string) ($event->id ?? '');
            $metadata = $this->decodeJsonObject($event->metadata ?? null);
            $metadataReceiptHash = (string) ($metadata['long_horizon_compaction_receipt_hash'] ?? '');
            $match = $receipts->first(function (AtlasLongHorizonCompactionReceipt $receipt) use ($eventId, $eventRefPrefix, $metadataReceiptHash): bool {
                if ($metadataReceiptHash !== '' && $receipt->receipt_hash === $metadataReceiptHash) {
                    return true;
                }

                if ($receipt->scope_id === $eventId) {
                    return true;
                }

                return in_array($eventRefPrefix.$eventId, $this->stringList($receipt->source_context_refs), true)
                    || in_array($eventRefPrefix.$eventId, $this->stringList($receipt->evidence_refs), true);
            });

            if ($match instanceof AtlasLongHorizonCompactionReceipt) {
                $matched++;
            } else {
                $unmatched[] = $eventId;
            }
        }

        return [
            'status' => $unmatched === [] ? 'pass' : 'fail',
            'source_table' => $sourceTable,
            'event_count' => $events->count(),
            'receipt_count' => $receipts->count(),
            'matched_event_count' => $matched,
            'unmatched_event_count' => count($unmatched),
            'unmatched_event_ids' => array_slice($unmatched, 0, 10),
            'match_policy' => [
                'metadata.long_horizon_compaction_receipt_hash',
                'receipt.scope_id',
                'receipt.source_context_refs',
                'receipt.evidence_refs',
            ],
            'mechanism' => $mechanism,
        ];
    }

    /**
     * @param  Collection<int,AtlasLongHorizonCompactionReceipt>  $receipts
     * @return array<string,mixed>
     */
    private function longHorizonMechanism(Collection $receipts): array
    {
        if (! DatabaseTableAvailability::has('atlas_long_horizon_compaction_receipts')) {
            return $this->mechanismUnavailable('atlas_long_horizon_compaction_receipts_table_missing');
        }

        $longHorizonReceipts = $receipts->reject(static fn (AtlasLongHorizonCompactionReceipt $receipt): bool => in_array(
            $receipt->scope_type,
            [AtlasLongHorizonCanon::SCOPE_TYPE_CONVERSATION, AtlasLongHorizonCanon::SCOPE_TYPE_HANDOFF],
            true
        ));

        if ($longHorizonReceipts->isEmpty()) {
            return [
                'status' => 'insufficient_sample',
                'source_table' => 'atlas_long_horizon_compaction_receipts',
                'event_count' => 0,
                'receipt_count' => 0,
                'matched_event_count' => 0,
                'unmatched_event_count' => 0,
                'reason' => 'no_long_horizon_receipts_in_window',
            ];
        }

        $invalid = [];
        foreach ($longHorizonReceipts as $receipt) {
            if ((string) $receipt->receipt_hash === '' || (string) $receipt->uuid === '') {
                $invalid[] = (string) $receipt->id;
            }
        }

        return [
            'status' => $invalid === [] ? 'pass' : 'fail',
            'source_table' => 'atlas_long_horizon_compaction_receipts',
            'event_count' => $longHorizonReceipts->count(),
            'receipt_count' => $longHorizonReceipts->count(),
            'matched_event_count' => $longHorizonReceipts->count() - count($invalid),
            'unmatched_event_count' => count($invalid),
            'unmatched_event_ids' => array_slice($invalid, 0, 10),
            'match_policy' => ['long_horizon_event_is_its_canonical_receipt_row'],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $shadowRows
     * @return array<string,mixed>
     */
    private function payloadMechanism(array $shadowRows): array
    {
        if ($shadowRows === []) {
            return [
                'status' => 'insufficient_sample',
                'source_table' => 'token_economy_must_keep_shadow_jsonl',
                'event_count' => 0,
                'receipt_count' => 0,
                'matched_event_count' => 0,
                'unmatched_event_count' => 0,
                'reason' => 'no_token_economy_shadow_rows_in_window',
            ];
        }

        $invalid = [];
        foreach ($shadowRows as $index => $row) {
            if ((string) ($row['schema_version'] ?? '') !== self::SHADOW_SCHEMA_VERSION
                || (string) ($row['token_economy_hash'] ?? '') === ''
                || ! is_array($row['allocation'] ?? null)) {
                $invalid[] = 'shadow_row:'.$index;
            }
        }

        return [
            'status' => $invalid === [] ? 'pass' : 'fail',
            'source_table' => 'token_economy_must_keep_shadow_jsonl',
            'event_count' => count($shadowRows),
            'receipt_count' => count($shadowRows),
            'matched_event_count' => count($shadowRows) - count($invalid),
            'unmatched_event_count' => count($invalid),
            'unmatched_event_ids' => array_slice($invalid, 0, 10),
            'match_policy' => ['token_economy_hash_present', 'allocator_shadow_allocation_present'],
        ];
    }

    /**
     * @param  Collection<int,AtlasLongHorizonCompactionReceipt>  $receipts
     * @return array<string,mixed>
     */
    private function coverageCheck(Collection $receipts): array
    {
        $vacuous = 0;
        $proven = 0;
        $violations = [];
        $hasWriteAllowedColumn = Schema::hasColumn('atlas_long_horizon_compaction_receipts', 'write_allowed');

        foreach ($receipts as $receipt) {
            $source = $this->mustKeepSource($receipt);
            if ($source === 'none') {
                $vacuous++;

                continue;
            }

            $proven++;
            $coverage = is_numeric($receipt->must_keep_coverage) ? (float) $receipt->must_keep_coverage : null;
            if ($coverage === 1.0) {
                continue;
            }

            $writeAllowed = $hasWriteAllowedColumn ? $receipt->getAttribute('write_allowed') : null;
            if ($writeAllowed === false || $writeAllowed === 0 || $writeAllowed === '0') {
                continue;
            }

            $violations[] = [
                'receipt_id' => (string) $receipt->id,
                'scope_type' => (string) $receipt->scope_type,
                'must_keep_coverage' => $coverage,
                'reason' => $hasWriteAllowedColumn ? 'write_allowed_not_false' : 'write_allowed_not_persisted',
            ];
        }

        return [
            'status' => $proven === 0 ? 'insufficient_sample' : ($violations === [] ? 'pass' : 'fail'),
            'receipt_count' => $receipts->count(),
            'proven_receipt_count' => $proven,
            'source_none_receipt_count' => $vacuous,
            'violation_count' => count($violations),
            'violations' => array_slice($violations, 0, 10),
            'policy' => 'must_keep_coverage==1.0 OR write_allowed=false; must_keep_source=none excluded',
        ];
    }

    /**
     * @param  Collection<int,AtlasLongHorizonCompactionReceipt>  $receipts
     * @return array<string,mixed>
     */
    private function contextRetentionScoreCheck(Collection $receipts): array
    {
        if ($receipts->isEmpty()) {
            return [
                'status' => 'insufficient_sample',
                'receipt_count' => 0,
                'missing_count' => 0,
            ];
        }

        $missing = [];
        foreach ($receipts as $receipt) {
            if (! is_numeric($receipt->context_retention_score)) {
                $missing[] = (string) $receipt->id;
            }
        }

        return [
            'status' => $missing === [] ? 'pass' : 'fail',
            'receipt_count' => $receipts->count(),
            'with_context_retention_score_count' => $receipts->count() - count($missing),
            'missing_count' => count($missing),
            'missing_receipt_ids' => array_slice($missing, 0, 10),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $shadowRows
     * @return array<string,mixed>
     */
    private function cpt08ShadowEvidenceCheck(array $shadowRows): array
    {
        $flagEnabled = (bool) config('atlas.context_budget.must_keep_allocator_enabled', true);
        $valid = 0;
        foreach ($shadowRows as $row) {
            if ((string) ($row['schema_version'] ?? '') === self::SHADOW_SCHEMA_VERSION
                && (string) ($row['token_economy_hash'] ?? '') !== ''
                && is_array($row['allocation'] ?? null)) {
                $valid++;
            }
        }

        $status = match (true) {
            ! $flagEnabled => 'fail',
            count($shadowRows) === 0 => 'insufficient_sample',
            $valid !== count($shadowRows) => 'fail',
            default => 'pass',
        };

        return [
            'status' => $status,
            'must_keep_allocator_enabled' => $flagEnabled,
            'shadow_row_count' => count($shadowRows),
            'valid_shadow_row_count' => $valid,
            'source' => [
                'disk' => (string) config('atlas.context_budget.must_keep_allocator_shadow_disk', 'local'),
                'path' => (string) config('atlas.context_budget.must_keep_allocator_shadow_path', 'atlas/context-budget/must-keep-shadow.jsonl'),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function cpt09SoakCheck(AtlasAcosWatchdogHealthService $health): array
    {
        try {
            $report = $health->compactionSoakWatchReport();
        } catch (Throwable $e) {
            return [
                'status' => 'pending_soak',
                'ready_to_enforce' => false,
                'blocking' => ['soak_report_unavailable:'.$e::class],
            ];
        }

        $ready = ($report['ready_to_enforce'] ?? false) === true;

        return [
            'status' => $ready ? 'pass' : 'pending_soak',
            'ready_to_enforce' => $ready,
            'soak_status' => (string) ($report['status'] ?? 'unknown'),
            'blocking' => (array) ($report['blocking'] ?? []),
            'rollback_trigger' => $report['rollback_trigger'] ?? null,
            'window' => $report['window'] ?? null,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadShadowRows(CarbonImmutable $since): array
    {
        $disk = (string) config('atlas.context_budget.must_keep_allocator_shadow_disk', 'local');
        $path = trim((string) config('atlas.context_budget.must_keep_allocator_shadow_path', 'atlas/context-budget/must-keep-shadow.jsonl'));
        if ($path === '') {
            return [];
        }

        try {
            if (! Storage::disk($disk)->exists($path)) {
                return [];
            }
            $raw = Storage::disk($disk)->get($path);
        } catch (Throwable) {
            return [];
        }

        $rows = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            try {
                $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                $row = ['schema_version' => 'invalid_json'];
            }
            if (! is_array($row)) {
                continue;
            }
            $at = $this->parseDate($row['generated_at'] ?? null);
            if ($at === null || $at->greaterThanOrEqualTo($since)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @return array<string,mixed>
     */
    private function mechanismUnavailable(string $reason): array
    {
        return [
            'status' => 'unavailable',
            'event_count' => 0,
            'receipt_count' => 0,
            'matched_event_count' => 0,
            'unmatched_event_count' => 0,
            'reason' => $reason,
        ];
    }

    private function mustKeepSource(AtlasLongHorizonCompactionReceipt $receipt): string
    {
        $columnValue = $receipt->getAttribute('must_keep_source');
        if (is_string($columnValue) && trim($columnValue) !== '') {
            return trim($columnValue);
        }

        return $this->stringList($receipt->must_keep_items) === [] ? 'none' : 'unknown';
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJsonObject(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            } elseif (is_array($item)) {
                foreach (['ref', 'id', 'source_ref'] as $key) {
                    if (isset($item[$key]) && is_string($item[$key]) && trim($item[$key]) !== '') {
                        $out[] = trim($item[$key]);
                        break;
                    }
                }
            }
        }

        return array_values(array_unique($out));
    }

    private function parseDate(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value)->utc();
        }
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
