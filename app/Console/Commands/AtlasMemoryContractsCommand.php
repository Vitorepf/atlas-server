<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasMemoryContractsService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas AI Memory Contracts gate CLI.
 *
 *   php artisan atlas:aaeos:memory-contracts
 *     [--ref-type=knowledge_refs] [--field=reason]
 *     [--json]
 *
 * Read-only, deterministic. With --ref-type it validates a minimal ref payload
 * against the documented Reference Contract (and can drop one --field to show a
 * missing-field verdict); otherwise it emits the full Memory Contracts manifest
 * (immune layers, initial quarantine block, required memory types, ref
 * contracts).
 *
 * @see docs/engineering-knowledge-base/memory/contracts.md
 */
class AtlasMemoryContractsCommand extends Command
{
    protected $signature = 'atlas:aaeos:memory-contracts
        {--ref-type= : ref type to validate (memory_refs|verbatim_refs|knowledge_refs|code_refs)}
        {--field= : a required field to omit, to demonstrate a missing-field verdict}
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas Memory · contracts gate (cognitive immune quarantine, promotion gates, ref contracts, privacy) + manifest.';

    public function handle(AtlasMemoryContractsService $service): int
    {
        try {
            $refType = $this->option('ref-type');

            if (is_string($refType) && trim($refType) !== '') {
                $payload = [
                    'ok' => true,
                    'ref_validation' => $service->validateRef(
                        $refType,
                        $this->sampleRef($refType, $this->option('field')),
                    ),
                ];
            } else {
                $payload = [
                    'ok' => true,
                    'manifest' => $service->manifest(),
                ];
            }

            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'memory_contracts_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }

    /**
     * Build a complete sample ref for a type, optionally dropping one field so
     * the operator can see the missing-field verdict.
     *
     * @return array<string,mixed>
     */
    private function sampleRef(string $refType, mixed $omit): array
    {
        $samples = [
            'memory_refs' => [
                'type' => 'memory_ref', 'id' => 'm1', 'memory_type' => 'decision',
                'scope' => 'global', 'priority' => 90, 'source' => 'registry',
                'reason' => 'matches active task scope',
            ],
            'verbatim_refs' => [
                'type' => 'verbatim_ref', 'id' => 'v1', 'verbatim_type' => 'note',
                'scope' => 'task', 'snippet' => '[redacted]', 'reason' => 'exact evidence requested',
            ],
            'knowledge_refs' => [
                'type' => 'knowledge_ref', 'id' => 'k1', 'slug' => 'memory-contracts',
                'title' => 'Atlas Memory Contracts', 'canonical_path' => 'docs/.../contracts.md',
                'content_hash' => 'abc123', 'summary' => 'focused contract', 'reason' => 'owns this subject',
            ],
            'code_refs' => [
                'type' => 'code_ref', 'id' => 'c1', 'slug' => 'svc', 'name' => 'Service',
                'layer' => 'service', 'root_path' => 'app/Services', 'reason' => 'implements doc',
            ],
        ];

        $ref = $samples[$refType] ?? [];

        if (is_string($omit) && trim($omit) !== '') {
            unset($ref[$omit]);
        }

        return $ref;
    }
}
