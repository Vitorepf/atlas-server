<?php

namespace App\Services\Ai;

use Illuminate\Support\Str;

class AtlasMemoryContextComposer
{
    /**
     * @param  array<int,array<string,mixed>>  $registry
     * @param  array<int,array<string,mixed>>  $verbatim
     * @param  array<int,array<string,mixed>>  $semantic
     * @param  array<string,mixed>  $options
     * @return array<int,array<string,mixed>>
     */
    public function compose(array $registry, array $verbatim, array $semantic, array $options = []): array
    {
        $limit = (int) ($options['memory_recall_limit'] ?? config('atlas.ai.memory_recall_limit', 10));
        $budget = (int) ($options['memory_recall_budget_chars'] ?? config('atlas.ai.memory_recall_budget_chars', 2400));
        $itemChars = (int) ($options['memory_recall_item_chars'] ?? config('atlas.ai.memory_recall_item_chars', 360));

        if ($limit <= 0 || $budget <= 0 || $itemChars <= 0) {
            return [];
        }

        $candidates = array_merge(
            $this->registryCandidates($registry),
            $this->verbatimCandidates($verbatim),
            $this->semanticCandidates($semantic),
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
            $score = (float) ($item['priority'] ?? 50)
                + ((float) ($item['importance'] ?? 3) * 10)
                + ((float) ($item['confidence'] ?? 0.7) * 10)
                + $this->scopeWeight($scope)
                + $this->typeWeight($type);

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
            $score = 82 + $this->scopeWeight($scope) + $this->typeWeight($type);

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
            $score = ((float) ($item['score'] ?? 0.55) * 100) + $this->typeWeight($type);

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
        ];
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

    private function scopeWeight(string $scope): int
    {
        return match ($scope) {
            'task' => 22,
            'engineering_run' => 20,
            'project' => 16,
            'workspace' => 12,
            'session' => 10,
            'user' => 8,
            default => 4,
        };
    }

    private function typeWeight(string $type): int
    {
        return match ($type) {
            'decision', 'resolution', 'requirement' => 16,
            'issue', 'failure' => 14,
            'technical_context', 'command', 'evidence', 'harness_learning' => 11,
            'preference', 'feedback' => 8,
            default => 5,
        };
    }
}
