<?php

namespace App\Services\Ai\Cognitive\Pattern;

use App\Services\Ai\Cognitive\Dreyfus\DreyfusOverlayRepository;
use App\Services\Ai\Kernel\Slo\KernelSloProbe;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Facades\DB;

class ProcessPatternRepository
{
    public const SCHEMA_VERSION = 'atlas.cognitive.process_pattern.v1';

    public function __construct(
        private readonly DreyfusOverlayRepository $nodes,
        private readonly ProcessPatternStructureValidator $validator,
        private readonly KernelSloProbe $slo,
    ) {}

    /**
     * @return array<string,mixed>|null
     */
    public function findByName(string $name): ?array
    {
        if (! $this->tableReady()) {
            return null;
        }

        $row = DB::table('process_patterns')->where('name', $this->slug($name))->first();

        return $row ? $this->normalize((array) $row) : null;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function catalog(?string $category = null, string $status = 'active'): array
    {
        return $this->slo->measure('cognitive.process_pattern.catalog', function () use ($category, $status): array {
            if (! $this->tableReady()) {
                return [];
            }

            $query = DB::table('process_patterns')
                ->where('status', $status)
                ->orderByDesc('applied_count')
                ->orderBy('name');

            if ($category !== null && $category !== '') {
                $query->where('category', $category);
            }

            return $query->get()
                ->map(fn (object $row): array => $this->normalize((array) $row))
                ->values()
                ->all();
        }, [
            'domain' => 'learning',
            'category' => (string) $category,
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function upsert(array $payload): array
    {
        if (! $this->tableReady()) {
            return ['schema_version' => self::SCHEMA_VERSION, 'status' => 'table_missing'];
        }

        $payload = $this->normalizedPayload($payload);
        $validation = $this->validator->validate($payload);
        if ($validation['status'] !== 'passed') {
            return ['schema_version' => self::SCHEMA_VERSION, 'status' => 'invalid', 'validation' => $validation];
        }

        $now = now();
        DB::table('process_patterns')->updateOrInsert(
            ['name' => $payload['name']],
            [
                'category' => $payload['category'],
                'intent' => $payload['intent'],
                'problem_context' => $payload['problem_context'],
                'forces' => json_encode($payload['forces'], JSON_THROW_ON_ERROR),
                'solution' => json_encode($payload['solution'], JSON_THROW_ON_ERROR),
                'consequences' => json_encode($payload['consequences'], JSON_THROW_ON_ERROR),
                'anti_patterns' => json_encode($payload['anti_patterns'], JSON_THROW_ON_ERROR),
                'related_patterns' => json_encode($payload['related_patterns'], JSON_THROW_ON_ERROR),
                'personal_evidence_refs' => json_encode($payload['personal_evidence_refs'], JSON_THROW_ON_ERROR),
                'created_via' => $payload['created_via'],
                'status' => $payload['status'],
                'knowledge_node_id' => $this->nodes->nodeIdForTopic($payload['name']),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        return $this->findByName($payload['name']) ?? ['schema_version' => self::SCHEMA_VERSION, 'status' => 'not_found_after_upsert'];
    }

    public function tableReady(): bool
    {
        return DatabaseTableAvailability::all(['process_patterns', 'process_pattern_applications']);
    }

    private function slug(string $name): string
    {
        return str($name)->lower()->replaceMatches('/[^a-z0-9]+/', '-')->trim('-')->toString();
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function normalizedPayload(array $payload): array
    {
        $name = $this->slug((string) ($payload['name'] ?? 'unnamed-pattern'));

        return [
            'name' => $name,
            'category' => (string) ($payload['category'] ?? 'process'),
            'intent' => trim((string) ($payload['intent'] ?? 'Reusable process pattern.')),
            'problem_context' => trim((string) ($payload['problem_context'] ?? 'Operator-authored process pattern.')),
            'forces' => is_array($payload['forces'] ?? null) ? $payload['forces'] : [],
            'solution' => is_array($payload['solution'] ?? null) ? $payload['solution'] : ['abstract' => 'Apply '.$name, 'steps' => []],
            'consequences' => is_array($payload['consequences'] ?? null) ? $payload['consequences'] : ['pros' => [], 'cons' => [], 'trade_offs' => []],
            'anti_patterns' => is_array($payload['anti_patterns'] ?? null) ? $payload['anti_patterns'] : [],
            'related_patterns' => is_array($payload['related_patterns'] ?? null) ? $payload['related_patterns'] : [],
            'personal_evidence_refs' => is_array($payload['personal_evidence_refs'] ?? null) ? $payload['personal_evidence_refs'] : [],
            'created_via' => (string) ($payload['created_via'] ?? 'operator'),
            'status' => (string) ($payload['status'] ?? 'active'),
        ];
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function normalize(array $row): array
    {
        $applied = (int) ($row['applied_count'] ?? 0);
        $success = (int) ($row['success_count'] ?? 0);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'category' => (string) ($row['category'] ?? ''),
            'intent' => (string) ($row['intent'] ?? ''),
            'problem_context' => (string) ($row['problem_context'] ?? ''),
            'forces' => $this->jsonArray($row['forces'] ?? []),
            'solution' => $this->jsonArray($row['solution'] ?? []),
            'consequences' => $this->jsonArray($row['consequences'] ?? []),
            'anti_patterns' => $this->jsonArray($row['anti_patterns'] ?? []),
            'related_patterns' => $this->jsonArray($row['related_patterns'] ?? []),
            'personal_evidence_refs' => $this->jsonArray($row['personal_evidence_refs'] ?? []),
            'metrics' => [
                'applied_count' => $applied,
                'success_count' => $success,
                'failure_count' => (int) ($row['failure_count'] ?? 0),
                'success_rate' => $applied > 0 ? round($success / $applied, 3) : null,
            ],
            'last_applied_at' => $row['last_applied_at'] ?? null,
            'created_via' => (string) ($row['created_via'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'knowledge_node_id' => $row['knowledge_node_id'] ?? null,
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
