<?php

namespace App\Services\Ai;

use App\Models\AtlasMemoryEntry;
use App\Services\Ai\Memory\MemoryQueryInput;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Support\AtlasSecurity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class AtlasMemoryPrivacyService
{
    public function __construct(private readonly MemoryQueryInput $input) {}

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $source
     * @return array<string,mixed>
     */
    public function normalizeForStorage(array $payload, array $source = []): array
    {
        $privacyClass = $this->privacyClass($source['privacy_class'] ?? data_get($payload, 'metadata.privacy.class', $payload['privacy_class'] ?? 'normal'));
        $externalAiAllowed = $this->externalAiAllowed($privacyClass, $source['external_ai_allowed'] ?? ($payload['external_ai_allowed'] ?? null));
        $redactedTitle = $this->redactedValue((string) ($payload['title'] ?? ''), $source['redacted_title'] ?? null);
        $redactedBody = $this->redactedValue((string) ($payload['body'] ?? ''), $source['redacted_body'] ?? null);
        $redactedSummary = $this->redactedValue((string) ($payload['summary'] ?? ''), $source['redacted_summary'] ?? null);
        $redactionStatus = $this->redactionStatus([
            [(string) ($payload['title'] ?? ''), $redactedTitle],
            [(string) ($payload['body'] ?? ''), $redactedBody],
            [(string) ($payload['summary'] ?? ''), $redactedSummary],
        ]);

        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        $metadata['privacy'] = array_merge((array) ($metadata['privacy'] ?? []), [
            'class' => $privacyClass,
            'external_ai_allowed' => $externalAiAllowed,
            'redaction_status' => $redactionStatus,
        ]);

        $payload['metadata'] = $metadata;
        foreach ($this->columnPayload($privacyClass, $externalAiAllowed, $redactionStatus, $redactedTitle, $redactedBody, $redactedSummary) as $key => $value) {
            $payload[$key] = $value;
        }

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public function apply(AtlasMemoryEntry $entry, array $data = []): AtlasMemoryEntry
    {
        $privacyClass = $this->privacyClass($data['privacy_class'] ?? data_get($entry->metadata, 'privacy.class', $entry->privacy_class ?? 'normal'));
        $externalAiAllowed = $this->externalAiAllowed(
            $privacyClass,
            array_key_exists('external_ai_allowed', $data) ? $data['external_ai_allowed'] : ($entry->external_ai_allowed ?? null),
        );
        $redactedTitle = $this->redactedValue((string) ($entry->title ?? ''), $data['redacted_title'] ?? null);
        $redactedBody = $this->redactedValue((string) $entry->body, $data['redacted_body'] ?? null);
        $redactedSummary = $this->redactedValue((string) ($entry->summary ?? ''), $data['redacted_summary'] ?? null);
        $redactionStatus = $this->redactionStatus([
            [(string) ($entry->title ?? ''), $redactedTitle],
            [(string) $entry->body, $redactedBody],
            [(string) ($entry->summary ?? ''), $redactedSummary],
        ]);
        $metadata = $this->metadataForReview($entry, $data, $privacyClass, $externalAiAllowed, $redactionStatus);

        $entry->forceFill(array_merge([
            'metadata' => $metadata,
        ], $this->columnPayload($privacyClass, $externalAiAllowed, $redactionStatus, $redactedTitle, $redactedBody, $redactedSummary), [
            ...$this->privacyReviewedTimestamp(),
        ]))->save();

        return $entry->refresh();
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    public function scan(array $filters = [], int $limit = 200, bool $dryRun = true): array
    {
        if (! DatabaseTableAvailability::has('atlas_memory_entries')) {
            return ['scanned' => 0, 'updated' => 0, 'entries' => []];
        }

        $entries = $this->queryEntries($filters)
            ->limit($this->input->governanceScanLimit($limit))
            ->get();
        $rows = $entries->map(function (AtlasMemoryEntry $entry) use ($dryRun): array {
            $before = $this->privacyPayload($entry);
            $after = $this->computedPayload($entry);
            $changed = $before !== $after;

            if ($changed && ! $dryRun) {
                $this->apply($entry);
            }

            return [
                'memory_entry_id' => $entry->id,
                'changed' => $changed,
                'dry_run' => $dryRun,
                'before' => $before,
                'after' => $after,
            ];
        })->values();

        return [
            'scanned' => $entries->count(),
            'updated' => $dryRun ? 0 : $rows->where('changed', true)->count(),
            'entries' => $rows->all(),
        ];
    }

    public function providerAllowed(AtlasMemoryEntry $entry): bool
    {
        return (bool) $this->providerDecision($entry)['allowed'];
    }

    /**
     * @return array{allowed:bool,privacy_class:string,external_ai_allowed:bool,metadata_external_ai_allowed:mixed,reason:string}
     */
    public function providerDecision(AtlasMemoryEntry $entry): array
    {
        $privacyClass = $this->privacyClass(data_get($entry->metadata, 'privacy.class', $entry->privacy_class ?? 'normal'));
        $externalAiAllowed = $this->externalAiAllowed($privacyClass, $entry->external_ai_allowed ?? null);
        $metadataExternalAiAllowed = data_get($entry->metadata ?? [], 'privacy.external_ai_allowed');
        $allowed = $externalAiAllowed && $metadataExternalAiAllowed !== false;

        return [
            'allowed' => $allowed,
            'privacy_class' => $privacyClass,
            'external_ai_allowed' => $externalAiAllowed,
            'metadata_external_ai_allowed' => $metadataExternalAiAllowed,
            'reason' => match (true) {
                $allowed => 'provider_safe',
                ! $externalAiAllowed => 'external_ai_blocked_by_privacy_class',
                $metadataExternalAiAllowed === false => 'external_ai_blocked_by_metadata',
                default => 'not_provider_safe',
            },
        ];
    }

    public function providerTitle(AtlasMemoryEntry $entry): ?string
    {
        $value = $entry->redacted_title ?: ($entry->title ? AtlasSecurity::redactString((string) $entry->title) : null);

        return $value === '' ? null : $value;
    }

    public function providerSummary(AtlasMemoryEntry $entry): ?string
    {
        $value = $entry->redacted_summary ?: ($entry->summary ? AtlasSecurity::redactString((string) $entry->summary) : null);

        return $value === '' ? null : $value;
    }

    public function providerBody(AtlasMemoryEntry $entry): string
    {
        return (string) ($entry->redacted_body ?: AtlasSecurity::redactString((string) $entry->body));
    }

    private function queryEntries(array $filters): Builder
    {
        $query = AtlasMemoryEntry::query();

        foreach (['scope_type', 'scope_id', 'project_id', 'task_id', 'engineering_run_id', 'source_type'] as $column) {
            if (is_string($filters[$column] ?? null) && trim((string) $filters[$column]) !== '') {
                $query->where($column, trim((string) $filters[$column]));
            }
        }

        $types = array_values(array_filter((array) ($filters['types'] ?? []), 'is_string'));
        if ($types !== []) {
            $query->whereIn('memory_type', $types);
        }
        if (is_string($filters['privacy_class'] ?? null) && $filters['privacy_class'] !== '') {
            $query->where('privacy_class', $filters['privacy_class']);
        }

        return $query->latest('recorded_at');
    }

    /**
     * @return array<string,mixed>
     */
    private function computedPayload(AtlasMemoryEntry $entry): array
    {
        $privacyClass = $this->privacyClass(data_get($entry->metadata, 'privacy.class', $entry->privacy_class ?? 'normal'));
        $externalAiAllowed = $this->externalAiAllowed($privacyClass, $entry->external_ai_allowed ?? null);
        $redactedTitle = $this->redactedValue((string) ($entry->title ?? ''));
        $redactedBody = $this->redactedValue((string) $entry->body);
        $redactedSummary = $this->redactedValue((string) ($entry->summary ?? ''));

        return [
            'privacy_class' => $privacyClass,
            'external_ai_allowed' => $externalAiAllowed,
            'redaction_status' => $this->redactionStatus([
                [(string) ($entry->title ?? ''), $redactedTitle],
                [(string) $entry->body, $redactedBody],
                [(string) ($entry->summary ?? ''), $redactedSummary],
            ]),
            'redacted_title' => $redactedTitle === '' ? null : $redactedTitle,
            'redacted_body' => $redactedBody,
            'redacted_summary' => $redactedSummary === '' ? null : $redactedSummary,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function privacyPayload(AtlasMemoryEntry $entry): array
    {
        return [
            'privacy_class' => $entry->privacy_class ?? null,
            'external_ai_allowed' => $entry->external_ai_allowed ?? null,
            'redaction_status' => $entry->redaction_status ?? null,
            'redacted_title' => $entry->redacted_title ?? null,
            'redacted_body' => $entry->redacted_body ?? null,
            'redacted_summary' => $entry->redacted_summary ?? null,
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function metadataForReview(AtlasMemoryEntry $entry, array $data, string $privacyClass, bool $externalAiAllowed, string $redactionStatus): array
    {
        $metadata = is_array($entry->metadata) ? $entry->metadata : [];
        $history = array_values((array) ($metadata['privacy_review_history'] ?? []));
        if ($data !== []) {
            $history[] = AtlasSecurity::redactArray([
                'reviewed_at' => now()->toJSON(),
                'reviewed_by' => is_scalar($data['reviewed_by'] ?? null) ? (string) $data['reviewed_by'] : null,
                'note' => is_scalar($data['review_note'] ?? null) ? (string) $data['review_note'] : null,
                'privacy_class' => $privacyClass,
                'external_ai_allowed' => $externalAiAllowed,
                'redaction_status' => $redactionStatus,
            ]);
            if (count($history) > 20) {
                $history = array_slice($history, -20);
            }
            $metadata['privacy_review_history'] = $history;
        }

        $metadata['privacy'] = [
            'class' => $privacyClass,
            'external_ai_allowed' => $externalAiAllowed,
            'redaction_status' => $redactionStatus,
            'reviewed_at' => now()->toJSON(),
        ];

        if (is_array($data['metadata'] ?? null)) {
            $metadata = array_merge($metadata, AtlasSecurity::redactArray($data['metadata']));
        }

        return $metadata;
    }

    /**
     * @return array<string,mixed>
     */
    private function columnPayload(string $privacyClass, bool $externalAiAllowed, string $redactionStatus, string $redactedTitle, string $redactedBody, string $redactedSummary): array
    {
        $payload = [];
        foreach ([
            'redacted_title' => $redactedTitle === '' ? null : $redactedTitle,
            'redacted_body' => $redactedBody,
            'redacted_summary' => $redactedSummary === '' ? null : $redactedSummary,
            'privacy_class' => $privacyClass,
            'external_ai_allowed' => $externalAiAllowed,
            'redaction_status' => $redactionStatus,
        ] as $column => $value) {
            if (DatabaseTableAvailability::hasColumn('atlas_memory_entries', $column)) {
                $payload[$column] = $value;
            }
        }

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function privacyReviewedTimestamp(): array
    {
        return DatabaseTableAvailability::hasColumn('atlas_memory_entries', 'privacy_reviewed_at')
            ? ['privacy_reviewed_at' => now()]
            : [];
    }

    private function redactedValue(string $raw, mixed $override = null): string
    {
        if (is_string($override)) {
            return AtlasSecurity::redactString(trim($override));
        }

        return AtlasSecurity::redactString($raw);
    }

    /**
     * @param  array<int,array{0:string,1:string}>  $pairs
     */
    private function redactionStatus(array $pairs): string
    {
        foreach ($pairs as [$raw, $redacted]) {
            if ($raw !== '' && $raw !== $redacted) {
                return 'redacted';
            }
        }

        return 'clean';
    }

    private function privacyClass(mixed $privacyClass): string
    {
        $privacyClass = is_string($privacyClass) ? $privacyClass : 'normal';

        return in_array($privacyClass, AtlasMemoryEntry::PRIVACY_CLASSES, true) ? $privacyClass : 'normal';
    }

    private function externalAiAllowed(string $privacyClass, mixed $requested): bool
    {
        $blocked = array_values(array_unique(array_merge(
            (array) config('atlas.privacy.block_external_ai_for_sensitivity', []),
            ['secret'],
        )));

        if (in_array($privacyClass, $blocked, true)) {
            return false;
        }

        return $requested === null ? true : filter_var($requested, FILTER_VALIDATE_BOOL);
    }
}
