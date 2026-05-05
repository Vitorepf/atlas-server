<?php

namespace App\Services\Ai;

use App\Models\AiMemoryDelta;
use App\Models\AtlasMemoryEntry;
use App\Services\Ai\Kernel\Slo\KernelSloProbe;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Throwable;

class AtlasMemoryLearningPromotionService
{
    private const DEFAULT_AUTO_TYPES = [
        'decision',
        'preference',
        'feedback',
        'error_pattern',
        'issue',
        'resolution',
        'benchmark',
        'benchmark_observation',
        'harness_learning',
        'process',
        'technical_context',
    ];

    public function __construct(
        private readonly AtlasMemoryDeltaPromotionService $promoter,
        private readonly KernelSloProbe $slo,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function run(array $options = []): array
    {
        return $this->slo->measure('learning.project', function () use ($options): array {
            return $this->runUnmeasured($options);
        }, [
            'tenant_id' => (string) ($options['tenant_id'] ?? 'default'),
            'operator_id' => (string) ($options['operator_id'] ?? $options['initiator'] ?? 'system'),
            'envelope_id' => (string) ($options['envelope_id'] ?? 'memory_learning_promotion'),
            'receipt_id' => isset($options['receipt_id']) ? (string) $options['receipt_id'] : null,
            'trace_id' => isset($options['trace_id']) ? (string) $options['trace_id'] : null,
            'correlation_id' => (string) ($options['correlation_id'] ?? $options['trace_id'] ?? 'memory_learning_promotion'),
            'domain' => (string) ($options['domain'] ?? 'self_improvement'),
            'flow' => (string) ($options['flow'] ?? 'learning.project'),
            'surface_id' => isset($options['surface_id']) ? (string) $options['surface_id'] : null,
        ]);
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function runUnmeasured(array $options = []): array
    {
        if (! Schema::hasTable('ai_memory_deltas') || ! Schema::hasTable('atlas_memory_entries')) {
            return [
                'ok' => true,
                'status' => 'skipped_missing_tables',
                'dry_run' => (bool) ($options['dry_run'] ?? false),
                'promoted_count' => 0,
                'accepted_eligible_count' => 0,
                'candidate_eligible_count' => 0,
                'review_required_count' => 0,
                'promoted' => [],
                'would_promote' => [],
                'failures' => [],
            ];
        }

        $dryRun = (bool) ($options['dry_run'] ?? false);
        $limit = max(1, min(200, (int) ($options['limit'] ?? 50)));
        $autoPromoteCandidates = (bool) ($options['auto_promote_candidates'] ?? false);
        $minConfidence = max(0.0, min(1.0, (float) ($options['min_confidence'] ?? 0.86)));
        $workspace = $this->workspace($options['workspace'] ?? null);

        $accepted = $this->acceptedQuery($options, $workspace)
            ->limit($limit)
            ->get();
        $remaining = max(0, $limit - $accepted->count());
        $candidates = $remaining > 0
            ? $this->candidateQuery($options, $workspace, $minConfidence)->limit($remaining)->get()
            : collect();
        $reviewRequiredCount = $this->reviewRequiredQuery($options, $workspace)->count();
        $eligible = $autoPromoteCandidates ? $accepted->concat($candidates)->values() : $accepted;
        $wouldPromote = $eligible
            ->map(fn (AiMemoryDelta $delta): array => $this->deltaRow($delta))
            ->values()
            ->all();

        if ($dryRun) {
            return [
                'ok' => true,
                'status' => 'dry_run_ready',
                'dry_run' => true,
                'auto_promote_candidates' => $autoPromoteCandidates,
                'min_confidence' => $minConfidence,
                'promoted_count' => 0,
                'accepted_eligible_count' => $accepted->count(),
                'candidate_eligible_count' => $candidates->count(),
                'review_required_count' => $reviewRequiredCount,
                'promoted' => [],
                'would_promote' => $wouldPromote,
                'failures' => [],
            ];
        }

        $promoted = [];
        $failures = [];
        $eligible->each(function (AiMemoryDelta $delta) use (&$promoted, &$failures, $autoPromoteCandidates, $minConfidence): void {
            try {
                $entry = $this->promoter->promote($delta, [
                    'force' => $delta->status !== 'accepted',
                    'promoted_by' => 'atlas_memory_learning_promotion',
                    'metadata' => [
                        'learning_promotion' => [
                            'mode' => $delta->status === 'accepted' ? 'accepted_delta' : 'trusted_candidate',
                            'auto_promote_candidates' => $autoPromoteCandidates,
                            'min_confidence' => $minConfidence,
                            'promoted_at' => now()->toJSON(),
                        ],
                    ],
                ]);
                $promoted[] = $this->memoryRow($entry->refresh(), $delta);
            } catch (Throwable $exception) {
                $failures[] = [
                    'memory_delta_id' => $delta->id,
                    'status' => $delta->status,
                    'type' => $delta->type,
                    'message' => $exception->getMessage(),
                    'exception' => class_basename($exception),
                ];
            }
        });

        return [
            'ok' => $failures === [],
            'status' => $failures === []
                ? ($promoted === [] ? 'no_eligible_learning' : 'promoted')
                : 'failed',
            'dry_run' => false,
            'auto_promote_candidates' => $autoPromoteCandidates,
            'min_confidence' => $minConfidence,
            'promoted_count' => count($promoted),
            'accepted_eligible_count' => $accepted->count(),
            'candidate_eligible_count' => $candidates->count(),
            'review_required_count' => $reviewRequiredCount,
            'promoted' => $promoted,
            'would_promote' => $wouldPromote,
            'failures' => $failures,
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function acceptedQuery(array $options, ?string $workspace): Builder
    {
        return $this->baseQuery($options, $workspace)
            ->where('status', 'accepted')
            ->latest('updated_at');
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function candidateQuery(array $options, ?string $workspace, float $minConfidence): Builder
    {
        return $this->baseQuery($options, $workspace)
            ->where('status', 'pending')
            ->where('requires_confirmation', false)
            ->where('confidence', '>=', $minConfidence)
            ->where(function (Builder $query): void {
                $query->whereNull('valid_until')
                    ->orWhere('valid_until', '>=', now());
            })
            ->whereIn('type', $this->autoTypes($options))
            ->latest('updated_at');
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function reviewRequiredQuery(array $options, ?string $workspace): Builder
    {
        return $this->baseQuery($options, $workspace)
            ->where('status', 'pending')
            ->where('requires_confirmation', true);
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function baseQuery(array $options, ?string $workspace): Builder
    {
        $query = AiMemoryDelta::query();

        $scope = $this->string($options['scope'] ?? null);
        if ($scope !== null) {
            $query->where('scope', $scope);
        } elseif ($workspace !== null) {
            $scopes = array_values(array_unique([
                'workspace:'.$workspace,
                'workspace:'.(realpath($workspace) ?: $workspace),
            ]));
            $query->whereIn('scope', $scopes);
        }

        $types = array_values(array_filter((array) ($options['types'] ?? []), 'is_string'));
        if ($types !== []) {
            $query->whereIn('type', $types);
        }

        return $query;
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<int,string>
     */
    private function autoTypes(array $options): array
    {
        $types = array_values(array_filter((array) ($options['auto_types'] ?? []), 'is_string'));

        return $types === [] ? self::DEFAULT_AUTO_TYPES : $types;
    }

    private function workspace(mixed $workspace): ?string
    {
        $workspace = $this->string($workspace);

        return $workspace === null ? null : (realpath($workspace) ?: $workspace);
    }

    /**
     * @return array<string,mixed>
     */
    private function deltaRow(AiMemoryDelta $delta): array
    {
        return [
            'id' => $delta->id,
            'status' => $delta->status,
            'type' => $delta->type,
            'scope' => $delta->scope,
            'confidence' => $delta->confidence,
            'requires_confirmation' => $delta->requires_confirmation,
            'claim' => $delta->claim,
            'promoted_memory_entry_id' => $delta->promoted_memory_entry_id,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function memoryRow(AtlasMemoryEntry $entry, AiMemoryDelta $delta): array
    {
        return [
            'id' => $entry->id,
            'memory_type' => $entry->memory_type,
            'scope_type' => $entry->scope_type,
            'scope_id' => $entry->scope_id,
            'title' => $entry->title,
            'priority' => $entry->priority,
            'importance' => $entry->importance,
            'source_type' => $entry->source_type,
            'source_id' => $entry->source_id,
            'memory_delta' => $this->deltaRow($delta->refresh()),
        ];
    }

    private function string(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
