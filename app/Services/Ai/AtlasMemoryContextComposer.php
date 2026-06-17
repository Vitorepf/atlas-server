<?php

namespace App\Services\Ai;

use App\Services\Ai\Aaeos\Cores\AtlasMemoryRecallRelevanceScorer;
use App\Services\Ai\Memory\MemoryRecallInput;
use Illuminate\Support\Str;

class AtlasMemoryContextComposer
{
    public function __construct(
        private readonly MemoryRecallInput $input,
        private readonly AtlasMemoryRecallRelevanceScorer $scorer,
    ) {}

    /**
     * @param  array<int,array<string,mixed>>  $registry
     * @param  array<int,array<string,mixed>>  $verbatim
     * @param  array<int,array<string,mixed>>  $semantic
     * @param  array<string,mixed>  $options
     * @param  array<int,array<string,mixed>>  $compounding  ACDE #3 — promoted compounding learnings (4th
     *                                                        source, LAST + default [] so every existing
     *                                                        caller is byte-identical; only the recall seam
     *                                                        passes it, flag-gated)
     * @return array<int,array<string,mixed>>
     */
    public function compose(array $registry, array $verbatim, array $semantic, array $options = [], array $compounding = []): array
    {
        $limit = $this->input->recallLimit($options['memory_recall_limit'] ?? null);
        $budget = $this->input->budgetChars($options['memory_recall_budget_chars'] ?? null);
        $itemChars = $this->input->itemChars($options['memory_recall_item_chars'] ?? null);

        if ($budget <= 0 || $itemChars <= 0) {
            return [];
        }

        $candidates = array_merge(
            $this->registryCandidates($registry),
            $this->verbatimCandidates($verbatim),
            $this->semanticCandidates($semantic),
            $this->compoundingCandidates($compounding),
        );

        usort($candidates, fn (array $left, array $right): int => ($right['score'] <=> $left['score'])
            ?: strcmp((string) $left['source'], (string) $right['source'])
            ?: strcmp((string) $left['title'], (string) $right['title']));

        $items = [];
        foreach ($candidates as $candidate) {
            if (count($items) >= $limit) {
                break;
            }

            $available = min($budget, $itemChars);
            if ($available < 40) {
                break;
            }

            $excerpt = $this->limitWithin((string) $candidate['text'], $available);
            if ($excerpt === '') {
                continue;
            }

            $items[] = [
                'rank' => count($items) + 1,
                'source' => $candidate['source'],
                'source_ref_type' => $candidate['source_ref_type'],
                'source_ref_id' => $candidate['source_ref_id'],
                'type' => $candidate['type'],
                'scope' => $candidate['scope'],
                'title' => $candidate['title'],
                'summary' => $candidate['summary'],
                'excerpt' => $excerpt,
                'score' => round((float) $candidate['score'], 3),
                'reason' => $candidate['reason'],
                'estimated_chars' => Str::length($excerpt),
                'lineage' => $candidate['lineage'],
                'freshness' => $candidate['freshness'],
                'audit' => $candidate['audit'],
                'audit_trail' => $candidate['audit'],
            ];

            $budget -= Str::length($excerpt);
        }

        return $items;
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     * @return array<int,array<string,mixed>>
     */
    private function registryCandidates(array $items): array
    {
        return array_values(array_filter(array_map(function (array $item): ?array {
            $text = $this->text($item['body'] ?? null, $item['summary'] ?? null, $item['title'] ?? null);
            if ($text === '') {
                return null;
            }

            $type = (string) ($item['type'] ?? 'memory');
            $scope = (string) ($item['scope_type'] ?? $item['scope'] ?? 'global');
            $score = $this->scorer->score([
                'source' => 'registry',
                'type' => $type,
                'scope_type' => $scope,
                'priority' => $item['priority'] ?? null,
                'importance' => $item['importance'] ?? null,
                'confidence' => $item['confidence'] ?? null,
                'hybrid_score' => $item['hybrid_score'] ?? null,
            ]);

            return $this->candidate(
                'registry',
                'atlas_memory_entry',
                $item['id'] ?? null,
                $type,
                (string) ($item['scope'] ?? $scope),
                (string) ($item['title'] ?? $item['summary'] ?? $type),
                (string) ($item['summary'] ?? ''),
                $text,
                $score,
                (string) ($item['reason'] ?? 'memoria canonica do registry'),
                $item,
            );
        }, $items)));
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     * @return array<int,array<string,mixed>>
     */
    private function verbatimCandidates(array $items): array
    {
        return array_values(array_filter(array_map(function (array $item): ?array {
            if (($item['blocked'] ?? false) === true) {
                return null;
            }

            $text = $this->text($item['snippet'] ?? null, $item['summary'] ?? null, $item['title'] ?? null);
            if ($text === '') {
                return null;
            }

            $type = (string) ($item['type'] ?? 'verbatim');
            $scope = (string) ($item['scope_type'] ?? $item['scope'] ?? 'global');
            $score = $this->scorer->score([
                'source' => 'verbatim',
                'type' => $type,
                'scope_type' => $scope,
                'hybrid_score' => $item['hybrid_score'] ?? null,
            ]);

            return $this->candidate(
                'verbatim',
                'atlas_verbatim_memory',
                $item['id'] ?? null,
                $type,
                (string) ($item['scope'] ?? $scope),
                (string) ($item['title'] ?? $item['summary'] ?? $type),
                (string) ($item['summary'] ?? ''),
                $text,
                $score,
                (string) ($item['reason'] ?? 'recall verbatim aprovado'),
                $item,
            );
        }, $items)));
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     * @return array<int,array<string,mixed>>
     */
    private function semanticCandidates(array $items): array
    {
        return array_values(array_filter(array_map(function (array $item): ?array {
            if (($item['blocked'] ?? false) === true || ($item['external_ai_allowed'] ?? true) === false) {
                return null;
            }

            $text = $this->text($item['excerpt'] ?? null, $item['summary'] ?? null, $item['title'] ?? null);
            if ($text === '') {
                return null;
            }

            $type = (string) ($item['type'] ?? 'semantic_note');
            $score = $this->scorer->score([
                'source' => 'semantic',
                'type' => $type,
                'score' => $item['score'] ?? null,
            ]);

            return $this->candidate(
                'semantic',
                'semantic_note',
                $item['id'] ?? null,
                $type,
                (string) ($item['path'] ?? 'semantic_note'),
                (string) ($item['title'] ?? $item['summary'] ?? $type),
                (string) ($item['summary'] ?? ''),
                $text,
                $score,
                'nota semantica provider-safe recuperada por busca local',
                $item,
            );
        }, $items)));
    }

    /**
     * ACDE #3 — promoted compounding learnings as recall candidates. The recall seam already filtered to
     * status=active + confidence-floor + provider-safe `claim` only, so this just maps them into the shared
     * candidate shape. Source 'compounding' / ref 'ai_compounding_memory'; a blocked item is skipped.
     *
     * @param  array<int,array<string,mixed>>  $items
     * @return array<int,array<string,mixed>>
     */
    private function compoundingCandidates(array $items): array
    {
        return array_values(array_filter(array_map(function (array $item): ?array {
            if (($item['blocked'] ?? false) === true) {
                return null;
            }

            $text = $this->text($item['claim'] ?? null, $item['summary'] ?? null, $item['title'] ?? null);
            if ($text === '') {
                return null;
            }

            $type = (string) ($item['type'] ?? 'compounding_learning');
            $score = $this->scorer->score([
                'source' => 'compounding',
                'type' => $type,
                'scope_type' => (string) ($item['scope_type'] ?? $item['scope'] ?? 'global'),
                'confidence' => $item['confidence'] ?? null,
                'hybrid_score' => $item['hybrid_score'] ?? null,
            ]);

            return $this->candidate(
                'compounding',
                'ai_compounding_memory',
                $item['id'] ?? null,
                $type,
                (string) ($item['scope'] ?? 'global'),
                (string) ($item['title'] ?? $type),
                (string) ($item['summary'] ?? ''),
                $text,
                $score,
                (string) ($item['reason'] ?? 'aprendizado compounding promovido (provider-safe)'),
                $item,
            );
        }, $items)));
    }

    /**
     * @return array<string,mixed>
     */
    private function candidate(
        string $source,
        string $sourceRefType,
        mixed $sourceRefId,
        string $type,
        string $scope,
        string $title,
        string $summary,
        string $text,
        float $score,
        string $reason,
        array $raw = [],
    ): array {
        return [
            'source' => $source,
            'source_ref_type' => $sourceRefType,
            'source_ref_id' => is_scalar($sourceRefId) ? (string) $sourceRefId : null,
            'type' => $type,
            'scope' => $scope,
            'title' => $title,
            'summary' => $summary,
            'text' => $text,
            'score' => $score,
            'reason' => $reason,
            'lineage' => $this->lineage($source, $sourceRefType, $sourceRefId, $raw),
            'freshness' => $this->freshness($raw),
            'audit' => $this->audit($raw),
        ];
    }

    /**
     * @param  array<string,mixed>  $raw
     * @return array<string,mixed>
     */
    private function lineage(string $source, string $sourceRefType, mixed $sourceRefId, array $raw): array
    {
        return [
            'source' => $source,
            'source_ref_type' => $sourceRefType,
            'source_ref_id' => is_scalar($sourceRefId) ? (string) $sourceRefId : null,
            'origin_type' => $this->scalarOrNull($raw['source_type'] ?? null) ?? $sourceRefType,
            'origin_id' => $this->scalarOrNull($raw['source_id'] ?? null),
            'origin_label' => $this->scalarOrNull($raw['source_label'] ?? null),
            'content_hash' => $this->scalarOrNull($raw['content_hash'] ?? null),
        ];
    }

    /**
     * @param  array<string,mixed>  $raw
     * @return array<string,mixed>
     */
    private function freshness(array $raw): array
    {
        $recordedAt = $this->scalarOrNull($raw['recorded_at'] ?? null);
        $ageDays = null;
        if ($recordedAt !== null) {
            try {
                $ageDays = max(0, now()->diffInDays(\Carbon\CarbonImmutable::parse($recordedAt), true));
            } catch (\Throwable) {
                $ageDays = null;
            }
        }

        return [
            'recorded_at' => $recordedAt,
            'last_used_at' => $this->scalarOrNull($raw['last_used_at'] ?? null),
            'age_days' => $ageDays,
            'status' => $recordedAt === null ? 'unknown' : ($ageDays !== null && $ageDays > 180 ? 'stale_review_recommended' : 'fresh'),
        ];
    }

    /**
     * @param  array<string,mixed>  $raw
     * @return array<string,mixed>
     */
    private function audit(array $raw): array
    {
        return [
            'schema_version' => 'atlas.memory.recall_audit.v1',
            'provider_safe' => true,
            'privacy_class' => $this->scalarOrNull($raw['privacy_class'] ?? null),
            'redaction_status' => $this->scalarOrNull($raw['redaction_status'] ?? null),
            'governance_checked_at' => $this->scalarOrNull($raw['governance_checked_at'] ?? null),
            'privacy_reviewed_at' => $this->scalarOrNull($raw['privacy_reviewed_at'] ?? null),
            'reviewed_at' => $this->scalarOrNull($raw['reviewed_at'] ?? null),
            'content_hash' => $this->scalarOrNull($raw['content_hash'] ?? null),
            'redacted_hash' => $this->scalarOrNull($raw['redacted_hash'] ?? null),
            'raw_content_persisted' => false,
        ];
    }

    private function scalarOrNull(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private function text(mixed ...$values): string
    {
        foreach ($values as $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return '';
    }

    private function limitWithin(string $value, int $limit): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return Str::length($value) > $limit
            ? Str::limit($value, max(1, $limit - 3), '...')
            : $value;
    }
}
