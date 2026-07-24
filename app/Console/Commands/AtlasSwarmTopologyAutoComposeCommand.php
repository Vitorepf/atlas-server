<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AtlasDecide\AtlasSwarmTopologyAutoComposerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use App\Support\YesNo;

final class AtlasSwarmTopologyAutoComposeCommand extends Command
{
    protected $signature = 'atlas:swarm:topology-auto-compose
        {--fixture=two-types : two-types, live or single-type}
        {--receipt= : Optional path to write receipt JSON}
        {--write-receipt : Write to configured receipt path when --receipt is omitted}
        {--strict : Exit non-zero unless topology convergence is certified}
        {--json : Print canonical JSON}';

    protected $description = 'L6-10 shadow auto-composer: select swarm topology by task type and measure plan convergence.';

    public function handle(AtlasSwarmTopologyAutoComposerService $composer): int
    {
        $payload = $composer->evaluate([
            'fixture' => trim((string) $this->option('fixture')),
        ]);

        $receiptPath = $this->receiptPath();
        if ($receiptPath !== '') {
            File::ensureDirectoryExists(dirname($receiptPath));
            File::put($receiptPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
            $payload['receipt_path'] = $receiptPath;
        }

        $exit = (bool) $this->option('strict') && ! (bool) ($payload['certified'] ?? false)
            ? self::FAILURE
            : self::SUCCESS;

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $exit;
        }

        $this->components->twoColumnDetail('Swarm topology composer', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Certified', (bool) YesNo::format($payload['certified'] ?? false));
        $this->components->twoColumnDetail('Task types', (string) data_get($payload, 'summary.task_type_count', 0));
        $this->components->twoColumnDetail('Topologies', (string) data_get($payload, 'summary.topology_count', 0));
        $this->components->twoColumnDetail('Converged', (string) data_get($payload, 'summary.converged_count', 0));

        return $exit;
    }

    private function receiptPath(): string
    {
        $explicit = trim((string) ($this->option('receipt') ?: ''));
        if ($explicit !== '') {
            return $explicit;
        }

        return (bool) $this->option('write-receipt')
            ? (string) config('atlas.patamar4.swarm_topology_auto_composer.receipt_path', storage_path('app/atlas/evidence/swarm-topology-auto-compose.json'))
            : '';
    }
}
