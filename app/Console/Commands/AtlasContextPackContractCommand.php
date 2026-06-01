<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasContextPackContractService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Context Pack Contract CLI.
 *
 *   php artisan atlas:aaeos:context-pack-contract
 *     [--risk=medium]                       // low|medium|high|critical
 *     [--tags=architecture,maintenance]
 *     [--blueprint=task_contract,review_gates]   // present nucleus keys
 *     [--max-refs=8]
 *     [--no-table]                          // simulate missing knowledge table (safe-fail)
 *     [--json]
 *
 * Read-only, deterministic. With no flags it self-checks the contract against a
 * small built-in ref-set (a malformed ref, a duplicate hash and an over-budget
 * tail) so the output demonstrates ref validation, ranking, the max-8 cap and
 * hash de-duplication. It NEVER touches the database.
 *
 * @see docs/engineering-knowledge-base/context-pack.md
 */
class AtlasContextPackContractCommand extends Command
{
    protected $signature = 'atlas:aaeos:context-pack-contract
        {--risk= : task risk level low|medium|high|critical (default low)}
        {--tags= : comma-separated task tags used for category boost}
        {--blueprint= : comma-separated present blueprint nucleus keys}
        {--max-refs= : reference budget override (default 8)}
        {--no-table : simulate a missing knowledge table (safe-fail path)}
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas context pack contract · validates ref shape, ranks + caps at 8, de-dupes by hash and gates autonomy by blueprint nucleus.';

    public function handle(AtlasContextPackContractService $service): int
    {
        try {
            $input = [
                'risk_level' => $this->strOption('risk') ?? 'low',
                'task_tags' => $this->list('tags'),
                'blueprint_refs' => $this->list('blueprint'),
                'knowledge_refs' => $this->sampleRefs(),
                'table_present' => ! (bool) $this->option('no-table'),
            ];

            $maxRefs = $this->strOption('max-refs');
            if ($maxRefs !== null) {
                $input['max_refs'] = (int) $maxRefs;
            }

            $result = $service->build($input);

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'context_pack_contract_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }

    /**
     * Built-in ref-set that exercises every contract rule for the default run:
     *   - one malformed ref (missing content_hash + summary) => rejected;
     *   - two refs sharing a content_hash => collapsed to one;
     *   - nine valid refs total => over the budget => truncated to 8.
     *
     * @return list<array<string,mixed>>
     */
    private function sampleRefs(): array
    {
        $refs = [];
        $refs[] = $this->ref('arch', 'architecture', 90, 'hash-arch');
        $refs[] = $this->ref('maint', 'maintenance', 80, 'hash-maint');
        // Duplicate hash of the architecture ref — must collapse.
        $refs[] = $this->ref('arch-dup', 'architecture', 70, 'hash-arch');
        $refs[] = $this->ref('caps', 'capabilities', 60, 'hash-caps');
        for ($i = 1; $i <= 6; $i++) {
            $refs[] = $this->ref("misc-{$i}", 'reference', 50 - $i, "hash-misc-{$i}");
        }
        // Malformed: missing content_hash and summary.
        $refs[] = [
            'id' => 'broken',
            'slug' => 'broken',
            'title' => 'Broken Ref',
            'category' => 'reference',
            'priority' => 10,
            'canonical_path' => 'docs/broken.md',
            'reason' => 'malformed sample',
        ];

        return $refs;
    }

    /**
     * @return array<string,mixed>
     */
    private function ref(string $id, string $category, int $priority, string $hash): array
    {
        return [
            'id' => $id,
            'slug' => $id,
            'title' => ucfirst($id),
            'category' => $category,
            'priority' => $priority,
            'canonical_path' => "docs/{$id}.md",
            'content_hash' => $hash,
            'summary' => "Summary for {$id}.",
            'reason' => 'sample ref for contract self-check',
        ];
    }

    private function strOption(string $option): ?string
    {
        $raw = $this->option($option);

        return is_string($raw) && trim($raw) !== '' ? trim($raw) : null;
    }

    /**
     * @return list<string>
     */
    private function list(string $option): array
    {
        $raw = $this->option($option);
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn ($v) => $v !== '',
        ));
    }
}
