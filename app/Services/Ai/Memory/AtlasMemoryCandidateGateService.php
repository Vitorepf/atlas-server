<?php

declare(strict_types=1);

namespace App\Services\Ai\Memory;

use App\Models\AtlasMemoryCandidate;
use App\Models\AtlasMemoryEntry;
use App\Services\Ai\AtlasOpenBrainContextInjectionBoundaryClassifier;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

final class AtlasMemoryCandidateGateService
{
    public function __construct(
        private readonly AtlasMemoryRegistryService $registry,
        private readonly AtlasOpenBrainContextInjectionBoundaryClassifier $injectionBoundaryClassifier,
        private readonly AtlasMemoryUsageService $usages,
    ) {}

    /**
     * @param  array<string,mixed>  $payload
     * @return array{candidate:AtlasMemoryCandidate,entry:?AtlasMemoryEntry,quality:array<string,mixed>}
     */
    public function capture(array $payload, bool $apply = false): array
    {
        $payload = $this->normalizePayload($payload);
        $quality = $this->qualityReport($payload);

        $candidate = AtlasMemoryCandidate::query()->create([
            'source_type' => (string) $payload['source_type'],
            'source_id' => $payload['source_id'],
            'source_path' => $payload['source_path'],
            'memory_type' => (string) $payload['memory_type'],
            'scope_type' => (string) $payload['scope_type'],
            'scope_id' => $payload['scope_id'],
            'title' => (string) $payload['title'],
            'summary' => $payload['summary'],
            'body' => (string) $payload['body'],
            'candidate_payload' => $payload,
            'quality_report' => $quality,
            'missing_checks' => $quality['missing_checks'],
            'status' => 'candidate',
        ]);

        if (! $apply) {
            return ['candidate' => $candidate, 'entry' => null, 'quality' => $quality];
        }

        return $this->admitIfPassing($candidate);
    }

    /**
     * @return array{candidate:AtlasMemoryCandidate,entry:?AtlasMemoryEntry,quality:array<string,mixed>}
     */
    public function admitIfPassing(AtlasMemoryCandidate $candidate): array
    {
        $quality = is_array($candidate->quality_report) ? $candidate->quality_report : [];
        if ((array) ($quality['missing_checks'] ?? []) !== []) {
            $candidate->forceFill([
                'status' => 'rejected',
                'rejected_at' => now(),
            ])->save();

            // MAXB-05 — admission reject becomes mined_negative when an entry ref exists.
            $linkedEntryId = is_string($candidate->memory_entry_id ?? null) ? trim((string) $candidate->memory_entry_id) : '';
            if ($linkedEntryId !== '') {
                $this->usages->recordMinedNegative($linkedEntryId, 'candidate_gate_reject', [
                    'label_kind' => 'admission',
                    'source_id' => 'gate:'.(string) $candidate->id,
                    'query_context_hash' => hash('sha256', (string) ($candidate->title ?? '').'|'.(string) ($candidate->id ?? '')),
                ]);
            }

            return ['candidate' => $candidate->refresh(), 'entry' => null, 'quality' => $quality];
        }

        $payload = is_array($candidate->candidate_payload) ? $candidate->candidate_payload : [];
        $entry = $this->registry->record([
            'memory_type' => $candidate->memory_type,
            'scope_type' => $candidate->scope_type,
            'scope_id' => $candidate->scope_id,
            'title' => $candidate->title,
            'summary' => $candidate->summary,
            'body' => $candidate->body,
            'importance' => (int) ($payload['importance'] ?? 3),
            'priority' => (int) ($payload['priority'] ?? 70),
            'confidence' => (float) ($payload['confidence'] ?? 0.75),
            'privacy_class' => (string) ($payload['privacy_class'] ?? 'normal'),
            'external_ai_allowed' => (bool) ($payload['external_ai_allowed'] ?? true),
            'source_type' => 'memory_candidate',
            'source_id' => (string) $candidate->id,
            'source_label' => 'atlas:memory:capture-candidates',
            'tags' => array_values(array_filter([
                'memory_candidate',
                is_string($payload['domain'] ?? null) ? (string) $payload['domain'] : null,
            ])),
            'metadata' => [
                'paths' => array_values((array) ($payload['paths'] ?? [])),
                'domains' => array_values(array_filter([(string) ($payload['domain'] ?? '')])),
                'immune_signals' => is_array($payload['immune_signals'] ?? null) ? $payload['immune_signals'] : [],
                'injection_boundary' => is_array($payload['injection_boundary'] ?? null) ? $payload['injection_boundary'] : [],
                'candidate' => [
                    'id' => (string) $candidate->id,
                    'source' => (string) ($payload['source_path'] ?? $payload['source_id'] ?? ''),
                    'quality_report' => $quality,
                    'reverse_handle' => 'php artisan atlas:ai:memory-forget <pending>',
                ],
            ],
        ]);

        $metadata = $entry->metadata ?? [];
        data_set($metadata, 'candidate.reverse_handle', 'php artisan atlas:ai:memory-forget '.$entry->id.'   (undo: --restore)');
        $entry = $this->registry->curate($entry, ['metadata' => $metadata]);

        $candidate->forceFill([
            'status' => 'admitted',
            'memory_entry_id' => $entry->id,
            'admitted_at' => now(),
        ])->save();

        return ['candidate' => $candidate->refresh(), 'entry' => $entry->refresh(), 'quality' => $quality];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function normalizePayload(array $payload): array
    {
        $source = is_scalar($payload['source'] ?? null) ? trim((string) $payload['source']) : '';
        $sourcePath = $source !== '' && File::exists($source) ? $source : null;
        $sourceText = $sourcePath !== null ? (string) File::get($sourcePath) : '';
        $title = $this->stringOrNull($payload['title'] ?? null) ?? $this->titleFromSource($sourceText, $sourcePath);
        $summary = $this->stringOrNull($payload['summary'] ?? null) ?? $this->summaryFromSource($sourceText);
        $body = $this->stringOrNull($payload['body'] ?? null) ?? $this->bodyFromSource($sourceText);
        $paths = array_values(array_unique(array_filter(array_map(
            fn (mixed $path): string => trim((string) $path),
            (array) ($payload['paths'] ?? []),
        ), fn (string $path): bool => $path !== '')));
        $domain = $this->stringOrNull($payload['domain'] ?? null);
        $memoryType = $this->enumOrDefault($payload['memory_type'] ?? $payload['type'] ?? null, AtlasMemoryEntry::TYPES, 'technical_context');
        $scopeType = $this->enumOrDefault($payload['scope_type'] ?? null, AtlasMemoryEntry::SCOPES, 'global');
        $scopeId = $scopeType === 'global' ? null : $this->stringOrNull($payload['scope_id'] ?? null);
        $privacyClass = $this->enumOrDefault($payload['privacy_class'] ?? null, AtlasMemoryEntry::PRIVACY_CLASSES, 'normal');
        $segmentSource = $this->enumOrDefault(
            $payload['segment_source'] ?? null,
            ['current_turn', 'task_contract', 'memory', 'excerpt', 'quoted_memory', 'example', 'summary', 'unknown'],
            'excerpt',
        );
        $injectionBoundary = $this->injectionBoundaryClassifier->classify([
            'text' => $body,
            'source' => $segmentSource,
        ]);

        return [
            'source_type' => $sourcePath !== null ? 'docs' : 'manual',
            'source_id' => $source !== '' ? $source : null,
            'source_path' => $sourcePath,
            'memory_type' => $memoryType,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'title' => Str::limit($title, 180, ''),
            'summary' => $summary,
            'body' => $body,
            'paths' => $paths,
            'domain' => $domain,
            'importance' => $payload['importance'] ?? 3,
            'priority' => $payload['priority'] ?? 70,
            'confidence' => $payload['confidence'] ?? 0.75,
            'privacy_class' => $privacyClass,
            'external_ai_allowed' => array_key_exists('external_ai_allowed', $payload) ? (bool) $payload['external_ai_allowed'] : true,
            'segment_source' => $segmentSource,
            'injection_boundary' => [
                'schema' => AtlasOpenBrainContextInjectionBoundaryClassifier::SCHEMA,
                'text_hash' => hash('sha256', $body),
                'source' => $segmentSource,
                'classification' => (string) $injectionBoundary['classification'],
                'allow_as_worker_directive' => (bool) $injectionBoundary['allow_as_worker_directive'],
                'reason' => (string) $injectionBoundary['reason'],
                'confidence' => (float) $injectionBoundary['confidence'],
            ],
            'immune_signals' => is_array($payload['immune_signals'] ?? null) ? $payload['immune_signals'] : [],
            'rationale_hash' => hash('sha256', mb_strtolower(trim($body))),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function qualityReport(array $payload): array
    {
        $missing = [];
        $title = trim((string) ($payload['title'] ?? ''));
        $summary = trim((string) ($payload['summary'] ?? ''));
        $body = trim((string) ($payload['body'] ?? ''));
        $paths = array_values((array) ($payload['paths'] ?? []));

        if ($title === '' || $summary === '' || $body === '') {
            $missing[] = 'title_summary_body_required';
        }
        if ($title !== '' && $summary !== '' && mb_strtolower($title) === mb_strtolower($summary)) {
            $missing[] = 'title_summary_distinction_required';
        }
        if ($paths === []) {
            $missing[] = 'meta_paths_required';
        }
        foreach ($paths as $path) {
            if (! is_string($path) || ! File::exists(base_path($path))) {
                $missing[] = 'meta_paths_file_exists_required';
                break;
            }
        }
        if (! is_string($payload['domain'] ?? null) || trim((string) $payload['domain']) === '') {
            $missing[] = 'domain_tag_required';
        }
        if (! AtlasMemoryRationalePolicy::hasRationale($body) || ! AtlasMemoryRationalePolicy::hasProvenance($body)) {
            $missing[] = 'rationale_with_provenance_required';
        }
        if ($this->rationaleReuseCount((string) ($payload['rationale_hash'] ?? '')) >= 3) {
            $missing[] = 'rationale_distinction_required';
        }
        $admission = $this->registry->evaluateAdmission(array_merge($payload, [
            'source_type' => 'memory_candidate',
            'source_label' => 'atlas:memory:capture-candidates',
        ]), 'memory_candidate');

        return [
            'passed' => $missing === [],
            'missing_checks' => array_values(array_unique($missing)),
            'checked_at' => now()->toJSON(),
            'provider_safe' => data_get($admission, 'verdict.gate_statuses.G3') === 'pass',
            'injection_boundary' => is_array($payload['injection_boundary'] ?? null) ? $payload['injection_boundary'] : [],
            'admission' => $admission,
            'rationale_hash' => (string) ($payload['rationale_hash'] ?? ''),
        ];
    }

    private function rationaleReuseCount(string $hash): int
    {
        if ($hash === '' || ! DatabaseTableAvailability::has('atlas_memory_candidates')) {
            return 0;
        }

        return AtlasMemoryCandidate::query()
            ->where('status', 'admitted')
            ->get()
            ->filter(fn (AtlasMemoryCandidate $candidate): bool => (string) Arr::get($candidate->quality_report ?? [], 'rationale_hash') === $hash)
            ->count();
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    /**
     * @param  list<string>  $allowed
     */
    private function enumOrDefault(mixed $value, array $allowed, string $default): string
    {
        $value = is_string($value) ? trim($value) : '';

        return in_array($value, $allowed, true) ? $value : $default;
    }

    private function titleFromSource(string $sourceText, ?string $sourcePath): string
    {
        if (preg_match('/^#\s+(.+)$/m', $sourceText, $matches) === 1) {
            return trim($matches[1]);
        }

        return $sourcePath !== null ? pathinfo($sourcePath, PATHINFO_FILENAME) : 'Atlas memory candidate';
    }

    private function summaryFromSource(string $sourceText): string
    {
        foreach (preg_split('/\R/', $sourceText) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line !== '' && ! str_starts_with($line, '#')) {
                return Str::limit($line, 240, '');
            }
        }

        return 'Candidate captured from a real Atlas source.';
    }

    private function bodyFromSource(string $sourceText): string
    {
        $summary = $this->summaryFromSource($sourceText);

        return 'motivo: captured from a canonical Atlas source for memory growth. provenance: "'.Str::limit($summary, 180, '').'"';
    }
}
