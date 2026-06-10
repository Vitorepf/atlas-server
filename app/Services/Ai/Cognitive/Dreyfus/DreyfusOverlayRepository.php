<?php

namespace App\Services\Ai\Cognitive\Dreyfus;

use App\Services\Ai\Kernel\Slo\KernelSloProbe;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Facades\DB;

class DreyfusOverlayRepository
{
    public const SCHEMA_VERSION = 'atlas.cognitive.dreyfus_overlay.v1';

    public function __construct(private readonly KernelSloProbe $slo) {}

    /**
     * @return array<string,mixed>|null
     */
    public function find(string $knowledgeNodeId, string $domain): ?array
    {
        return $this->slo->measure('cognitive.dreyfus.lookup', function () use ($knowledgeNodeId, $domain): ?array {
            if (! $this->tableReady()) {
                return null;
            }

            $row = DB::table('dreyfus_overlays')
                ->where('knowledge_node_id', $knowledgeNodeId)
                ->where('domain', $domain)
                ->first();

            return $row ? $this->normalizeRow((array) $row) : null;
        }, [
            'domain' => $domain,
            'knowledge_node_id' => $knowledgeNodeId,
        ]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function all(?string $domain = null): array
    {
        if (! $this->tableReady()) {
            return [];
        }

        $query = DB::table('dreyfus_overlays')
            ->orderBy('domain')
            ->orderByDesc('confidence')
            ->orderByDesc('updated_at');

        if ($domain !== null && $domain !== '') {
            $query->where('domain', $domain);
        }

        return $query->get()
            ->map(fn (object $row): array => $this->normalizeRow((array) $row))
            ->values()
            ->all();
    }

    /**
     * @param  array<int,string>  $evidenceRefs
     * @return array<string,mixed>
     */
    public function upsert(
        string $knowledgeNodeId,
        string $domain,
        int $level,
        float $confidence,
        array $evidenceRefs = [],
        string $lastUpdatedVia = 'operator_dispute',
        ?string $specialistProfile = null,
    ): array {
        if (! $this->tableReady()) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'table_missing',
                'knowledge_node_id' => $knowledgeNodeId,
                'domain' => $domain,
            ];
        }

        $level = max(1, min(5, $level));
        $confidence = round(max(0.0, min(1.0, $confidence)), 2);
        $now = now();
        $exists = DB::table('dreyfus_overlays')
            ->where('knowledge_node_id', $knowledgeNodeId)
            ->where('domain', $domain)
            ->exists();

        DB::table('dreyfus_overlays')->updateOrInsert(
            ['knowledge_node_id' => $knowledgeNodeId, 'domain' => $domain],
            array_filter([
                'specialist_profile' => $specialistProfile,
                'current_level' => $level,
                'confidence' => $confidence,
                'evidence_refs' => json_encode(array_values(array_unique($evidenceRefs)), JSON_THROW_ON_ERROR),
                'last_updated_via' => $lastUpdatedVia,
                'last_validated_at' => $now,
                'next_validation_at' => $now->copy()->addDays($level >= 4 ? 30 : 14),
                'updated_at' => $now,
                'created_at' => $exists ? null : $now,
            ], fn (mixed $value): bool => $value !== null)
        );

        return $this->find($knowledgeNodeId, $domain) ?? [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'not_found_after_upsert',
            'knowledge_node_id' => $knowledgeNodeId,
            'domain' => $domain,
        ];
    }

    public function nodeIdForTopic(string $topic): string
    {
        $topic = strtolower(trim($topic));

        $hash = sha1('atlas:dreyfus:'.($topic !== '' ? $topic : 'unknown'));

        return sprintf(
            '%s-%s-5%s-%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 12, 3),
            substr($hash, 15, 4),
            substr($hash, 19, 12),
        );
    }

    public function tableReady(): bool
    {
        return DatabaseTableAvailability::has('dreyfus_overlays');
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function normalizeRow(array $row): array
    {
        $evidenceRefs = $row['evidence_refs'] ?? [];
        if (is_string($evidenceRefs)) {
            $decoded = json_decode($evidenceRefs, true);
            $evidenceRefs = is_array($decoded) ? $decoded : [];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'ok',
            'id' => (int) ($row['id'] ?? 0),
            'knowledge_node_id' => (string) ($row['knowledge_node_id'] ?? ''),
            'domain' => (string) ($row['domain'] ?? ''),
            'specialist_profile' => $row['specialist_profile'] ?? null,
            'current_level' => (int) ($row['current_level'] ?? 1),
            'confidence' => (float) ($row['confidence'] ?? 0.0),
            'evidence_refs' => array_values(array_filter((array) $evidenceRefs, 'is_string')),
            'last_updated_via' => (string) ($row['last_updated_via'] ?? ''),
            'last_validated_at' => $row['last_validated_at'] ?? null,
            'next_validation_at' => $row['next_validation_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }
}
