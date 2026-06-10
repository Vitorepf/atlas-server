<?php

namespace App\Services\Ai\Cognitive\WorkedExample;

use App\Services\Ai\Cognitive\Dreyfus\DreyfusOverlayRepository;
use App\Services\Ai\Kernel\Slo\KernelSloProbe;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Facades\DB;

class WorkedExampleRepository
{
    public const SCHEMA_VERSION = 'atlas.cognitive.worked_example.v1';

    public function __construct(
        private readonly DreyfusOverlayRepository $nodes,
        private readonly KernelSloProbe $slo,
    ) {}

    /**
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        if (! $this->tableReady()) {
            return null;
        }

        $row = DB::table('worked_examples')->where('id', $id)->first();

        return $row ? $this->normalize((array) $row) : null;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function list(string $domain = 'learning', string $source = 'any'): array
    {
        if (! $this->tableReady()) {
            return [];
        }

        $query = DB::table('worked_examples')
            ->where('domain', $domain)
            ->where('status', 'active')
            ->orderByDesc('delivered_count')
            ->orderBy('title');

        if ($source !== 'any') {
            $query->where('source', $source);
        }

        return $query->get()
            ->map(fn (object $row): array => $this->normalize((array) $row))
            ->values()
            ->all();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function candidates(string $knowledgeNodeId, string $domain, string $sourcePreference = 'any'): array
    {
        return $this->slo->measure('cognitive.worked_example.select', function () use ($knowledgeNodeId, $domain, $sourcePreference): array {
            if (! $this->tableReady()) {
                return [];
            }

            $query = DB::table('worked_examples')
                ->where('knowledge_node_id', $knowledgeNodeId)
                ->where('domain', $domain)
                ->where('status', 'active')
                ->orderByRaw("case source when 'canonical_library' then 0 when 'operator_authored' then 1 when 'personal_ledger' then 2 else 3 end")
                ->orderByDesc('delivered_count')
                ->orderBy('title');

            if ($sourcePreference !== 'any') {
                $query->where('source', $sourcePreference);
            }

            return $query->get()
                ->map(fn (object $row): array => $this->normalize((array) $row))
                ->values()
                ->all();
        }, [
            'domain' => $domain,
            'knowledge_node_id' => $knowledgeNodeId,
        ]);
    }

    /**
     * @param  array<int,array<string,mixed>>  $solutionFull
     * @param  array<string,array<int,int>>  $fadingLevels
     * @param  array<int,string>  $authorEvidenceRefs
     * @return array<string,mixed>
     */
    public function create(
        string $topic,
        string $domain,
        string $title,
        string $problemContext,
        array $solutionFull,
        array $fadingLevels = [],
        string $source = 'operator_authored',
        ?string $specialistProfile = null,
        array $authorEvidenceRefs = [],
    ): array {
        if (! $this->tableReady()) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'table_missing',
            ];
        }

        $knowledgeNodeId = $this->nodes->nodeIdForTopic($topic);
        $now = now();

        $id = DB::table('worked_examples')->insertGetId([
            'knowledge_node_id' => $knowledgeNodeId,
            'domain' => $domain,
            'specialist_profile' => $specialistProfile,
            'title' => $title,
            'problem_context' => $problemContext,
            'solution_full' => json_encode(array_values($solutionFull), JSON_THROW_ON_ERROR),
            'fading_levels' => json_encode($fadingLevels ?: $this->defaultFadingLevels(), JSON_THROW_ON_ERROR),
            'source' => $source,
            'author_evidence_refs' => json_encode(array_values(array_unique($authorEvidenceRefs)), JSON_THROW_ON_ERROR),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->find((int) $id) ?? ['schema_version' => self::SCHEMA_VERSION, 'status' => 'not_found_after_create'];
    }

    public function markDelivered(int $id): void
    {
        if (! $this->tableReady()) {
            return;
        }

        DB::table('worked_examples')
            ->where('id', $id)
            ->update([
                'delivered_count' => DB::raw('delivered_count + 1'),
                'last_delivered_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function tableReady(): bool
    {
        return DatabaseTableAvailability::has('worked_examples');
    }

    /**
     * @return array<string,array<int,int>>
     */
    public function defaultFadingLevels(): array
    {
        return [
            '1' => [1, 2, 3, 4, 5],
            '2' => [1, 3, 5],
            '3' => [1, 5],
            '4' => [],
            '5' => [],
        ];
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function normalize(array $row): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => (string) ($row['status'] ?? 'active'),
            'id' => (int) ($row['id'] ?? 0),
            'knowledge_node_id' => (string) ($row['knowledge_node_id'] ?? ''),
            'domain' => (string) ($row['domain'] ?? ''),
            'specialist_profile' => $row['specialist_profile'] ?? null,
            'title' => (string) ($row['title'] ?? ''),
            'problem_context' => (string) ($row['problem_context'] ?? ''),
            'solution_full' => $this->jsonArray($row['solution_full'] ?? []),
            'fading_levels' => $this->jsonArray($row['fading_levels'] ?? []),
            'source' => (string) ($row['source'] ?? ''),
            'author_evidence_refs' => array_values(array_filter($this->jsonArray($row['author_evidence_refs'] ?? []), 'is_string')),
            'delivered_count' => (int) ($row['delivered_count'] ?? 0),
            'last_delivered_at' => $row['last_delivered_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    /**
     * @return array<mixed>
     */
    private function jsonArray(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($value) ? $value : [];
    }
}
