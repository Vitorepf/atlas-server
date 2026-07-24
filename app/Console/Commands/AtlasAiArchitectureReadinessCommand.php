<?php

namespace App\Console\Commands;

use App\Services\Ai\Kernel\Architecture\AtlasArchitectureReadinessService;
use Illuminate\Console\Command;
use App\Support\YesNo;

class AtlasAiArchitectureReadinessCommand extends Command
{
    protected $signature = 'atlas:ai:architecture-readiness
        {--workspace= : Workspace root used for provider projection status}
        {--owner= : Optional docs owner area for focused split plan}
        {--json : Print machine-readable JSON}';

    protected $description = 'Show Atlas AI mother-architecture readiness across validation, docs, projections and architecture operations.';

    public function handle(AtlasArchitectureReadinessService $readiness): int
    {
        $payload = $readiness->snapshot([
            'workspace' => $this->option('workspace'),
            'owner' => $this->option('owner'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas AI Architecture Readiness</>', (string) $payload['status']);
        $this->components->twoColumnDetail('Workspace', (string) $payload['workspace']);
        $this->components->twoColumnDetail('Ready for implementation', data_getYesNo::format($payload, 'summary.ready_for_implementation'));
        $this->components->twoColumnDetail('Architecture', (string) data_get($payload, 'checks.architecture_validate.status', 'unknown'));
        $this->components->twoColumnDetail('Documentation', (string) data_get($payload, 'checks.documentation_health.status', 'unknown'));
        $this->components->twoColumnDetail('Provider projection', (string) data_get($payload, 'checks.provider_projection.status', 'unknown'));
        $this->components->twoColumnDetail('Split required', (string) data_get($payload, 'checks.documentation_health.total_split_required_count', 0));
        $this->components->twoColumnDetail('Recommended action', (string) data_get($payload, 'review_signal.recommended_action', 'unknown'));

        $safeNextBlocks = (array) data_get($payload, 'safe_next_blocks', []);
        if ($safeNextBlocks !== []) {
            $this->newLine();
            $this->line('Safe next blocks:');
            foreach (array_slice($safeNextBlocks, 0, 5) as $block) {
                $this->line('  - '.data_get($block, 'order').'. '.data_get($block, 'block'));
            }
        }

        $commands = (array) data_get($payload, 'review_signal.required_next_commands', []);
        if ($commands !== []) {
            $this->newLine();
            $this->line('Comandos recomendados:');
            foreach ($commands as $command) {
                $this->line('  - '.$command);
            }
        }

        return self::SUCCESS;
    }
}
