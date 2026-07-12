<?php

declare(strict_types=1);

namespace App\Services\Ai\Kernel\Procedural;

use App\Models\AtlasMemoryEntry;
use App\Services\Ai\Support\DatabaseTableAvailability;

/**
 * ASI-14 — cross-executor retrieval seam.
 *
 * Dev / Forge / Autonomos consume playbooks by task_category. The floor is
 * pétreo: a playbook is CONTEXT, never authority (never bypasses gates or
 * certify).
 *
 * The seam returns a small envelope of ready-to-inject items:
 *   `{playbook, entry_id, scope, source_ref, execution_hint}`
 */
final class AtlasProceduralPlaybookCrossExecutorRetrieval
{
    public function __construct(
        private readonly AtlasProceduralPlaybookMemoryBridge $bridge = new AtlasProceduralPlaybookMemoryBridge,
    ) {}

    public const AUTHORITY_FLOOR_NOTE = 'procedural playbook is CONTEXT, never authority — never substitutes gates/certify';

    /**
     * @param  array{cap?:int,executor?:string}  $options
     * @return array<string,mixed>
     */
    public function retrieveByTaskCategory(string $taskCategory, array $options = []): array
    {
        $cap = max(1, min(5, (int) ($options['cap'] ?? 2))); // plan: cap 1–2 playbooks per packet
        $executor = (string) ($options['executor'] ?? 'unknown');
        $key = ProceduralPlaybook::normalizeCategory($taskCategory);

        $envelope = [
            'schema' => 'atlas.procedural_playbook.retrieval.v1',
            'executor' => $executor,
            'task_category' => $taskCategory,
            'task_category_key' => $key,
            'authority_floor' => self::AUTHORITY_FLOOR_NOTE,
            'items' => [],
        ];

        if (! DatabaseTableAvailability::has('atlas_memory_entries')) {
            $envelope['reason'] = 'memory_table_unavailable';

            return $envelope;
        }

        $rows = AtlasMemoryEntry::query()
            ->where('memory_type', 'procedural')
            ->where('status', 'active')
            ->orderByDesc('priority')
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get();

        foreach ($rows as $row) {
            $playbook = $this->bridge->fromMemoryEntry($row);
            if ($playbook === null) {
                continue;
            }
            if ($playbook->key() !== $key) {
                continue;
            }

            $envelope['items'][] = [
                'entry_id' => $row->id,
                'playbook' => $playbook->toArray(),
                'scope' => ['type' => $row->scope_type, 'id' => $row->scope_id],
                'source_ref' => 'memory:'.$row->id,
                'execution_hint' => 'inject_as_context',
            ];

            if (count($envelope['items']) >= $cap) {
                break;
            }
        }

        return $envelope;
    }
}
