<?php

namespace App\Console\Commands;

use App\Services\Ai\Kernel\Architecture\AtlasArchitectureOperationsCatalog;
use Illuminate\Console\Command;

class AtlasAiArchitectureOperationsCommand extends Command
{
    protected $signature = 'atlas:ai:architecture-operations
        {--id= : Filter by stable operation id}
        {--kind= : Filter by operation kind}
        {--section= : Filter by canonical operation section}
        {--surface= : Filter by operation surface}
        {--owner-layer= : Filter by governing owner layer}
        {--json : Print machine-readable JSON}';

    protected $description = 'List canonical Atlas AI mother-architecture operations.';

    public function handle(AtlasArchitectureOperationsCatalog $catalog): int
    {
        $payload = [
            'status' => 'ok',
            'architecture_operations' => $catalog->summary($this->filters()),
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $operations = $payload['architecture_operations'];

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas AI Architecture Operations</>', $operations['section']);
        $this->components->twoColumnDetail('Commands', (string) $operations['command_count']);
        $this->components->twoColumnDetail('Filters', json_encode($operations['filters'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

        $this->table(
            ['command', 'description'],
            collect($operations['commands'])
                ->map(fn (array $operation): array => [
                    $operation['command'],
                    $operation['description'],
                ])
                ->all(),
        );

        return self::SUCCESS;
    }

    /**
     * @return array{id?:string,kind?:string,section?:string,surface?:string,owner_layer?:string}
     */
    private function filters(): array
    {
        return array_filter([
            'id' => is_string($this->option('id')) && $this->option('id') !== '' ? $this->option('id') : null,
            'kind' => is_string($this->option('kind')) && $this->option('kind') !== '' ? $this->option('kind') : null,
            'section' => is_string($this->option('section')) && $this->option('section') !== '' ? $this->option('section') : null,
            'surface' => is_string($this->option('surface')) && $this->option('surface') !== '' ? $this->option('surface') : null,
            'owner_layer' => is_string($this->option('owner-layer')) && $this->option('owner-layer') !== '' ? $this->option('owner-layer') : null,
        ], fn (?string $value): bool => $value !== null);
    }
}
