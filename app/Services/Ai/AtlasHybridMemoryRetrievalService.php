<?php

namespace App\Services\Ai;

use App\Models\AtlasMemoryEntry;
use App\Models\AtlasVerbatimMemory;
use App\Models\SemanticNote;
use App\Services\Ai\Memory\MemoryRecallInput;
use App\Services\Semantic\SemanticSearchService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AtlasHybridMemoryRetrievalService
{
    public function __construct(
        private readonly AtlasMemoryRegistryService $registry,
        private readonly AtlasVerbatimMemoryService $verbatim,
        private readonly SemanticSearchService $semantic,
        private readonly AtlasMemoryPrivacyService $privacy,
        private readonly AtlasMemorySourcePrivacyPolicy $sourcePrivacy,
        private readonly AtlasMemoryContextComposer $composer,
        private readonly MemoryRecallInput $input,
        private readonly AtlasMemoryUsageService $usage,
    ) {}

    /**
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>  $filters
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function recall(string $query = '', array $context = [], array $filters = [], array $options = []): array
    {
        $query = trim($query);
        $limit = $this->input->recallLimit($options['limit'] ?? null);
        $registryLimit = $this->input->registryCandidateLimit($options['registry_limit'] ?? null, $limit);
        $verbatimLimit = $this->input->verbatimCandidateLimit($options['verbatim_limit'] ?? null, $limit);
        $semanticLimit = $this->input->semanticCandidateLimit($options['semantic_limit'] ?? null, $limit);

        $registry = $this->registryItems($query, $context, $filters, $registryLimit, (bool) ($options['include_registry'] ?? true));
        $verbatim = $this->verbatimItems($query, $context, $filters, $verbatimLimit, (bool) ($options['include_verbatim'] ?? true));
        $semantic = $this->semanticItems($query, $filters, $semanticLimit, (bool) ($options['include_semantic'] ?? true));

        $recall = $this->composer->compose($registry, $verbatim, $semantic, [
            'memory_recall_limit' => $limit,
            'memory_recall_budget_chars' => $this->input->budgetChars($options['budget_chars'] ?? null),
            'memory_recall_item_chars' => $this->input->itemChars($options['item_chars'] ?? null),
        ]);
        $usage = $this->usage->recordRecallUsages($query, $this->publicContext($context), $recall, [
            'source' => is_scalar($options['requester'] ?? null) ? (string) $options['requester'] : 'atlas_memory_recall',
        ]);

        return [
            'query' => $query,
            'context' => $this->publicContext($context),
            'summary' => [
                'registry_candidates' => count($registry),
                'verbatim_candidates' => count($verbatim),
                'semantic_candidates' => count($semantic),
                'recall_count' => count($recall),
                'usage_recorded_count' => $usage['recorded_count'],
                'usage_audit_id' => $usage['audit_id'],
                'budget_chars' => collect($recall)->sum(fn (array $item): int => (int) ($item['estimated_chars'] ?? 0)),
                'policy' => 'provider_safe_only',
            ],
            'recall' => $recall,
            'sources' => [
                'registry' => $registry,
                'verbatim' => $verbatim,
                'semantic' => $semantic,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>  $filters
     * @return array<int,array<string,mixed>>
     */
    private function registryItems(string $query, array $context, array $filters, int $limit, bool $enabled): array
    {
        if (! $enabled || $limit <= 0 || ! Schema::hasTable('atlas_memory_entries')) {
            return [];
        }

        return $this->registry
            ->relevantForContext($context, $this->registryFilters($filters), $limit)
            ->filter(fn (AtlasMemoryEntry $entry): bool => $this->privacy->providerAllowed($entry))
            ->map(fn (AtlasMemoryEntry $entry): array => [
                'id' => $entry->id,
                'type' => $entry->memory_type,
                'scope' => $entry->scope_id ? $entry->scope_type.':'.$entry->scope_id : $entry->scope_type,
                'scope_type' => $entry->scope_type,
                'scope_id' => $entry->scope_id,
                'title' => $this->privacy->providerTitle($entry),
                'summary' => $this->privacy->providerSummary($entry),
                'body' => Str::limit($this->privacy->providerBody($entry), $this->input->registryExcerptChars(), '...'),
                'importance' => $entry->importance,
                'priority' => $entry->priority,
                'confidence' => $entry->confidence,
                'privacy_class' => $entry->privacy_class,
                'redaction_status' => $entry->redaction_status,
                'source_type' => $entry->source_type,
                'source_id' => $entry->source_id,
                'source_label' => $entry->source_label,
                'content_hash' => $entry->content_hash,
                'recorded_at' => $entry->recorded_at?->toJSON(),
                'last_used_at' => $entry->last_used_at?->toJSON(),
                'governance_checked_at' => $entry->governance_checked_at?->toJSON(),
                'privacy_reviewed_at' => $entry->privacy_reviewed_at?->toJSON(),
                'reason' => $this->reasonForRegistry($entry, $query),
                'hybrid_score' => $this->lexicalScore($query, [
                    $entry->title,
                    $entry->summary,
                    $this->privacy->providerBody($entry),
                    $entry->source_type,
                ]),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>  $filters
     * @return array<int,array<string,mixed>>
     */
    private function verbatimItems(string $query, array $context, array $filters, int $limit, bool $enabled): array
    {
        if (! $enabled || $limit <= 0 || ! Schema::hasTable('atlas_verbatim_memories')) {
            return [];
        }

        return $this->verbatim
            ->relevantForContext($context, $this->verbatimFilters($filters), $limit)
            ->filter(fn (AtlasVerbatimMemory $memory): bool => $memory->external_ai_allowed === true && trim((string) $memory->redacted_text) !== '')
            ->map(fn (AtlasVerbatimMemory $memory): array => [
                'id' => $memory->id,
                'type' => $memory->verbatim_type,
                'scope' => $memory->scope_id ? $memory->scope_type.':'.$memory->scope_id : $memory->scope_type,
                'scope_type' => $memory->scope_type,
                'scope_id' => $memory->scope_id,
                'title' => $memory->title,
                'summary' => $memory->summary,
                'snippet' => Str::limit((string) $memory->redacted_text, $this->input->itemChars(), '...'),
                'privacy_class' => $memory->privacy_class,
                'redaction_status' => $memory->redaction_status,
                'source_type' => $memory->source_type,
                'source_id' => $memory->source_id,
                'source_label' => $memory->source_label,
                'content_hash' => $memory->content_hash,
                'redacted_hash' => $memory->redacted_hash,
                'recorded_at' => $memory->recorded_at?->toJSON(),
                'reviewed_at' => $memory->reviewed_at?->toJSON(),
                'reason' => $query !== '' ? 'recall verbatim provider-safe filtrado por contexto e query' : 'recall verbatim provider-safe por escopo',
                'hybrid_score' => $this->lexicalScore($query, [
                    $memory->title,
                    $memory->summary,
                    $memory->redacted_text,
                    $memory->source_type,
                ]),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array<int,array<string,mixed>>
     */
    private function semanticItems(string $query, array $filters, int $limit, bool $enabled): array
    {
        if (! $enabled || $limit <= 0 || ! Schema::hasTable('semantic_notes')) {
            return [];
        }

        return $this->semantic
            ->search($query, (array) ($filters['semantic'] ?? []), $limit)
            ->map(fn (SemanticNote $note): array => $this->semanticItem($note))
            ->filter(fn (array $item): bool => ($item['external_ai_allowed'] ?? false) === true)
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function semanticItem(SemanticNote $note): array
    {
        $privacy = $this->sourcePrivacy->project('semantic_note', [
            'title' => $note->title,
            'summary' => $note->summary,
            'body_excerpt' => $note->body_excerpt,
            'path' => $note->path,
            'frontmatter' => $note->frontmatter ?? [],
            'metadata' => $note->metadata ?? [],
            'domains' => $note->domains ?? [],
        ]);

        return [
            'id' => $note->id,
            'type' => $note->type,
            'path' => $note->path,
            'title' => data_get($privacy, 'fields.title') ?? $note->title,
            'summary' => data_get($privacy, 'fields.summary') ?? $note->summary,
            'excerpt' => data_get($privacy, 'fields.body') ?? data_get($privacy, 'fields.summary'),
            'score' => isset($note->score) ? (float) $note->score : 0.55,
            'privacy_class' => $privacy['privacy_class'],
            'external_ai_allowed' => $privacy['external_ai_allowed'],
            'redaction_status' => $privacy['redaction_status'],
            'source_type' => 'semantic_note',
            'source_id' => $note->id,
            'source_label' => $note->path,
            'content_hash' => is_scalar(data_get($note->metadata, 'content_hash')) ? (string) data_get($note->metadata, 'content_hash') : hash('sha256', implode('|', [
                (string) $note->path,
                (string) $note->title,
                (string) $note->summary,
                (string) $note->body_excerpt,
            ])),
            'recorded_at' => $note->updated_at?->toJSON() ?? $note->created_at?->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    private function registryFilters(array $filters): array
    {
        return array_filter([
            'types' => $filters['types'] ?? $filters['memory_type'] ?? [],
            'privacy_class' => $filters['privacy_class'] ?? null,
        ], fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    private function verbatimFilters(array $filters): array
    {
        return array_filter([
            'types' => $filters['verbatim_types'] ?? $filters['verbatim_type'] ?? [],
            'privacy_class' => $filters['privacy_class'] ?? null,
        ], fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');
    }

    /**
     * @param  array<int,mixed>  $fields
     */
    private function lexicalScore(string $query, array $fields): float
    {
        if ($query === '') {
            return 0.0;
        }

        $haystack = Str::lower(implode(' ', array_filter(array_map(
            fn (mixed $field): string => is_scalar($field) ? (string) $field : '',
            $fields,
        ))));
        $tokens = $this->tokens($query);
        if ($tokens === [] || $haystack === '') {
            return 0.0;
        }

        $matches = 0;
        foreach ($tokens as $token) {
            if (str_contains($haystack, $token)) {
                $matches++;
            }
        }

        return round($matches / count($tokens), 3);
    }

    /**
     * @return array<int,string>
     */
    private function tokens(string $query): array
    {
        preg_match_all('/[\pL\pN]{3,}/u', Str::lower($query), $matches);

        return array_values(array_unique($matches[0] ?? []));
    }

    private function reasonForRegistry(AtlasMemoryEntry $entry, string $query): string
    {
        $base = match ($entry->scope_type) {
            'global' => 'memoria global provider-safe',
            'workspace' => 'memoria provider-safe ligada ao workspace',
            default => 'memoria provider-safe ligada ao contexto atual',
        };

        return $query !== '' ? $base.' com sinal lexical da consulta' : $base;
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private function publicContext(array $context): array
    {
        $workspace = $context['workspace'] ?? $context['workspace_path'] ?? null;

        return array_filter([
            'workspace' => is_scalar($workspace) ? (string) $workspace : null,
            'project_id' => $context['project_id'] ?? null,
            'task_id' => $context['task_id'] ?? null,
            'engineering_run_id' => $context['engineering_run_id'] ?? ($context['run_id'] ?? null),
            'session_id' => $context['session_id'] ?? null,
            'user_id' => $context['user_id'] ?? null,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

}
