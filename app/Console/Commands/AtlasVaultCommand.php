<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReadsNonEmptyStringOption;
use App\Console\Concerns\EmitsCanonicalJson;
use App\Services\Semantic\AtlasVaultCommandInput;
use App\Services\Semantic\AtlasVaultManagedNoteService;
use App\Services\Semantic\AtlasVaultSyncService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class AtlasVaultCommand extends Command
{
    use ReadsNonEmptyStringOption;

    use EmitsCanonicalJson;

    private AtlasVaultCommandInput $vaultInput;

    protected $signature = 'atlas:vault
        {action : status, note, import, export-semantic, sync, conflicts, item or resolve}
        {--type= : Atlas entity type for note}
        {--id= : Atlas entity id for note}
        {--title= : Note title}
        {--summary= : Human summary}
        {--content= : Generated Atlas content for the managed block}
        {--privacy-class=normal : normal, private, sensitive or secret}
        {--provider-safe=1 : Whether the note is provider-safe}
        {--redaction-status=clean : clean, redacted, blocked or needs_review}
        {--path= : Optional vault-relative markdown path}
        {--semantic-note= : Semantic note id for export-semantic}
        {--item= : Atlas vault sync item id for item or resolve}
        {--resolution= : Resolution action for resolve}
        {--reason= : Optional operator reason for resolve}
        {--limit=200 : Max files/items for sync or conflicts}
        {--status= : Comma-separated status filter for conflicts}
        {--direction= : Direction filter for conflicts}
        {--operation= : Operation filter for conflicts}
        {--link=* : Extra atlas link as Label=atlas://type/id}
        {--canonical-doc=* : Extra canonical doc path to include in Links Atlas}
        {--dry-run : Preview note without writing}
        {--write : Write note if safe}
        {--json : Print machine-readable JSON}';

    protected $description = 'Inspect AtlasVault and create or update managed vault notes safely.';

    public function handle(AtlasVaultManagedNoteService $notes, AtlasVaultSyncService $sync, AtlasVaultCommandInput $input): int
    {
        $this->vaultInput = $input;
        $action = (string) $this->argument('action');

        try {
            $payload = match ($action) {
                'status' => ['ok' => true, 'vault' => [
                    ...$notes->status(),
                    'sync_queue' => $sync->queueSummary(),
                ]],
                'note' => $this->handleNote($notes),
                'import' => $this->handleImport($sync),
                'export-semantic' => $this->handleExportSemantic($sync),
                'sync' => $this->handleSync($sync),
                'conflicts' => $sync->conflicts($this->conflictLimitOption(), $this->conflictFilters()),
                'item' => $this->handleItem($sync),
                'resolve' => $this->handleResolve($sync),
                default => ['ok' => false, 'error' => "Unsupported action: {$action}"],
            };
        } catch (ModelNotFoundException) {
            $payload = ['ok' => false, 'error' => $this->notFoundMessage($action)];
        } catch (\Throwable $exception) {
            $payload = ['ok' => false, 'error' => $exception->getMessage()];
        }

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return ($payload['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
        }

        if (! ($payload['ok'] ?? false) && ! isset($payload['note'])) {
            $this->error((string) ($payload['error'] ?? 'Atlas vault command failed.'));

            return self::FAILURE;
        }

        $this->renderHuman($payload);

        return ($payload['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string,mixed>
     */
    private function handleImport(AtlasVaultSyncService $sync): array
    {
        [$dryRun, $write] = $this->executionMode('import');

        return $sync->importPath((string) $this->stringOption('path'), $write);
    }

    /**
     * @return array<string,mixed>
     */
    private function handleExportSemantic(AtlasVaultSyncService $sync): array
    {
        [$dryRun, $write] = $this->executionMode('export-semantic');

        return $sync->exportSemanticNote((string) $this->stringOption('semantic-note'), $write);
    }

    /**
     * @return array<string,mixed>
     */
    private function handleSync(AtlasVaultSyncService $sync): array
    {
        [$dryRun, $write] = $this->executionMode('sync');

        return $sync->sync($write, $this->syncLimitOption());
    }

    /**
     * @return array<string,mixed>
     */
    private function handleResolve(AtlasVaultSyncService $sync): array
    {
        return $sync->resolve(
            (string) $this->stringOption('item'),
            (string) $this->stringOption('resolution'),
            $this->stringOption('reason'),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function handleItem(AtlasVaultSyncService $sync): array
    {
        return $sync->item((string) $this->stringOption('item'));
    }

    /**
     * @return array<string,mixed>
     */
    private function conflictFilters(): array
    {
        return [
            'status' => $this->stringOption('status'),
            'direction' => $this->stringOption('direction'),
            'operation' => $this->stringOption('operation'),
        ];
    }

    /**
     * @return array{0:bool,1:bool}
     */
    private function executionMode(string $action): array
    {
        $dryRun = (bool) $this->option('dry-run');
        $write = (bool) $this->option('write');
        if ($dryRun === $write) {
            throw new \InvalidArgumentException("Use exactly one of --dry-run or --write for {$action}.");
        }

        return [$dryRun, $write];
    }

    /**
     * @return array<string,mixed>
     */
    private function handleNote(AtlasVaultManagedNoteService $notes): array
    {
        $dryRun = (bool) $this->option('dry-run');
        $write = (bool) $this->option('write');
        if ($dryRun === $write) {
            return ['ok' => false, 'error' => 'Use exactly one of --dry-run or --write for note.'];
        }

        $input = [
            'type' => $this->stringOption('type'),
            'id' => $this->stringOption('id'),
            'title' => $this->stringOption('title'),
            'summary' => $this->stringOption('summary') ?? '',
            'content' => $this->stringOption('content') ?? '',
            'privacy_class' => $this->stringOption('privacy-class') ?? 'normal',
            'provider_safe' => $this->boolOption('provider-safe'),
            'redaction_status' => $this->stringOption('redaction-status') ?? 'clean',
            'path' => $this->stringOption('path'),
            'links' => $this->extraLinks(),
        ];

        $note = $write ? $notes->write($input) : $notes->preview($input);

        return [
            'ok' => $note->conflicts === [],
            'note' => $note->toArray(),
        ];
    }


    private function boolOption(string $key): bool
    {
        $value = $this->option($key);
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));
        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        throw new \InvalidArgumentException("Invalid boolean option --{$key}: ".(string) $value);
    }

    private function syncLimitOption(): int
    {
        return $this->vaultInput->syncLimit($this->option('limit'));
    }

    private function conflictLimitOption(): int
    {
        return $this->vaultInput->conflictLimit($this->option('limit'));
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function extraLinks(): array
    {
        $links = [];
        foreach ($this->arrayOption('link') as $raw) {
            [$label, $uri] = $this->splitExtraLink($raw);
            $links[] = ['label' => $label, 'uri' => $uri];
        }

        foreach ($this->arrayOption('canonical-doc') as $path) {
            $links[] = ['label' => 'Canonical Doc', 'path' => $path];
        }

        return $links;
    }

    /**
     * @return array<int,string>
     */
    private function arrayOption(string $key): array
    {
        $value = $this->option($key);
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => trim((string) $item),
            $value,
        ), static fn (string $item): bool => $item !== ''));
    }

    /**
     * @return array{0:string,1:string}
     */
    private function splitExtraLink(string $raw): array
    {
        if (! str_contains($raw, '=')) {
            throw new \InvalidArgumentException('Extra links must use Label=atlas://type/id.');
        }

        [$label, $uri] = explode('=', $raw, 2);
        $label = trim($label);
        $uri = trim($uri);
        if ($label === '' || $uri === '') {
            throw new \InvalidArgumentException('Extra links must use Label=atlas://type/id.');
        }

        return [$label, $uri];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function renderHuman(array $payload): void
    {
        if (isset($payload['vault']) && is_array($payload['vault'])) {
            $this->renderVaultStatus($payload['vault']);

            return;
        }

        if (isset($payload['note']) && is_array($payload['note'])) {
            $this->renderNoteResult($payload['note'], (bool) ($payload['ok'] ?? false));

            return;
        }

        if (isset($payload['items']) && is_array($payload['items'])) {
            $this->info('AtlasVault items: '.count($payload['items']));

            return;
        }

        $this->line($this->encode($payload));
    }

    /**
     * @param  array<string,mixed>  $vault
     */
    private function renderVaultStatus(array $vault): void
    {
        $this->info('AtlasVault status');
        $this->line('Path: '.(string) ($vault['vault_path'] ?? ''));
        $this->line('Exists: '.$this->yesNo((bool) ($vault['exists'] ?? false)));
        $this->line('Writable: '.$this->yesNo((bool) ($vault['writable'] ?? false)));
        $this->line('Markdown inspected: '.(string) ($vault['inspected_markdown_count'] ?? 0));
        $this->line('Managed notes: '.(string) ($vault['managed_notes_count'] ?? 0));
        $this->line('Conflicts: '.(string) ($vault['conflicts_count'] ?? 0));
        $this->line('Invalid managed notes: '.(string) ($vault['invalid_notes_count'] ?? 0));
        $this->line('Sync queue open: '.(string) data_get($vault, 'sync_queue.open', 0));
        $this->line('Sync queue blocked: '.(string) data_get($vault, 'sync_queue.blocked', 0));
        $this->line('Write policy: '.(string) data_get($vault, 'safety_status.write_policy', 'unknown'));
    }

    /**
     * @param  array<string,mixed>  $note
     */
    private function renderNoteResult(array $note, bool $ok): void
    {
        $metadata = is_array($note['metadata'] ?? null) ? $note['metadata'] : [];
        $conflicts = is_array($note['conflicts'] ?? null) ? $note['conflicts'] : [];

        $ok ? $this->info('AtlasVault note ready') : $this->error('AtlasVault note blocked');
        $this->line('Path: '.(string) ($note['path'] ?? ''));
        $this->line('Operation: '.(string) ($metadata['operation'] ?? 'unknown'));
        $this->line('Dry run: '.$this->yesNo((bool) ($metadata['dry_run'] ?? false)));
        $this->line('Written: '.$this->yesNo((bool) ($metadata['written'] ?? false)));

        if ($conflicts !== []) {
            $this->line('Conflicts:');
            foreach ($conflicts as $conflict) {
                $this->line('- '.(string) $conflict);
            }
        }
    }

    private function yesNo(bool $value): string
    {
        return $value ? 'yes' : 'no';
    }

    private function notFoundMessage(string $action): string
    {
        return match ($action) {
            'export-semantic' => 'Semantic note not found.',
            'item', 'resolve' => 'AtlasVault sync item not found.',
            default => 'AtlasVault resource not found.',
        };
    }

}
