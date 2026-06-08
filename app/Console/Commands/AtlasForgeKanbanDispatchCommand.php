<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Programming\Forge\ForgeKanbanSwarmDispatcher;
use Illuminate\Console\Command;

/**
 * Operator + automation surface for the Forge → Kanban consumer call-path:
 * dispatch a DECOMPOSED Forge obra (its work packets) as a durable Hermes Kanban
 * swarm via {@see ForgeKanbanSwarmDispatcher}.
 *
 * Default-safe: `--dry-run` (default behaviour without --confirm prints the masked
 * preview and nothing else). A real dispatch is FAIL-CLOSED three ways
 * (kanban.policy=atlas_adapter AND kanban.dispatch_for_forge=true AND --confirm);
 * the spec's goal + worker titles are masked to hashes in every preview/receipt.
 * Auto-discovered from app/Console/Commands.
 *
 * @see docs/engineering-knowledge-base/atlas-hermes-kanban-substrate.md
 */
class AtlasForgeKanbanDispatchCommand extends Command
{
    protected $signature = 'atlas:forge:kanban-dispatch
        {--file= : JSON file: {"task_summary":"...","work_packets":[{"objective":"...","role_slot":"coder"}],"permission_mode":"write","mission_id":"..."}}
        {--json : Emit JSON}
        {--dry-run : Print the masked plan/argv WITHOUT creating a board or spawning}
        {--confirm : Required to actually run the live swarm}';

    protected $description = 'Dispatch a decomposed Forge obra as a durable Hermes Kanban swarm (fail-closed: kanban.policy=atlas_adapter AND kanban.dispatch_for_forge=true AND --confirm).';

    public function handle(ForgeKanbanSwarmDispatcher $dispatcher): int
    {
        [$spec, $error] = $this->load();
        if ($error !== null) {
            return $this->failWith($error);
        }

        $taskSummary = (string) ($spec['task_summary'] ?? '');
        $packets = is_array($spec['work_packets'] ?? null) ? $spec['work_packets'] : [];
        $options = array_filter([
            'permission_mode' => $spec['permission_mode'] ?? null,
            'verifier' => $spec['verifier'] ?? null,
            'synthesizer' => $spec['synthesizer'] ?? null,
            'mission_id' => $spec['mission_id'] ?? null,
        ], static fn ($v): bool => $v !== null);

        if ((bool) $this->option('dry-run') || ! (bool) $this->option('confirm')) {
            $preview = $dispatcher->preview($taskSummary, $packets, $options);
            if ($this->wantsJson()) {
                $this->printJson(array_merge(['action' => 'dry_run', 'launched' => false], $preview));

                return self::SUCCESS;
            }
            $this->components->twoColumnDetail('action', 'dry_run (nothing created/launched)');
            $this->components->twoColumnDetail('forge dispatch allowed', ($preview['forge_dispatch_allowed'] ?? false) ? 'yes' : 'no');
            $this->components->twoColumnDetail('block reason', (string) ($preview['forge_block_reason'] ?? '—'));
            $this->components->twoColumnDetail('workers', (string) ($preview['worker_count'] ?? 0));
            $this->line('  swarm: '.implode(' ', (array) data_get($preview, 'preview.swarm_argv', [])));

            return self::SUCCESS;
        }

        $result = $dispatcher->dispatch($taskSummary, $packets, $options + ['confirm' => true]);

        if ($this->wantsJson()) {
            $this->printJson(['action' => 'dispatch', 'result' => $result]);

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('status', (string) ($result['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('dispatched', ($result['dispatched'] ?? false) ? 'yes' : 'no');
        $this->components->twoColumnDetail('block reason', (string) ($result['blocked_reason'] ?? '—'));
        $this->components->twoColumnDetail('workers', (string) ($result['worker_count'] ?? 0));

        return self::SUCCESS;
    }

    /**
     * @return array{0:array<string,mixed>,1:?string}
     */
    private function load(): array
    {
        $file = $this->option('file');
        if (! is_string($file) || trim($file) === '') {
            return [[], 'missing --file=<path to forge dispatch spec json>'];
        }
        if (! is_file($file)) {
            return [[], "file not found: {$file}"];
        }
        $decoded = json_decode((string) file_get_contents($file), true);
        if (! is_array($decoded)) {
            return [[], 'file is not a valid JSON object'];
        }

        return [$decoded, null];
    }

    private function wantsJson(): bool
    {
        return (bool) $this->option('json');
    }

    private function failWith(string $message): int
    {
        if ($this->wantsJson()) {
            $this->printJson(['error' => $message, 'authority' => 'atlas']);
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function printJson(array $payload): void
    {
        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
    }
}
