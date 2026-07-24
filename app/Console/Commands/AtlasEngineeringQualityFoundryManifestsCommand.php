<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\EngineeringKernel\QualityFoundry\QualityFoundryLiveManifestService;
use App\Services\Ai\EngineeringKernel\QualityFoundry\QualityFoundryMutationCoverageRunner;
use Illuminate\Console\Command;
use App\Support\YesNo;

final class AtlasEngineeringQualityFoundryManifestsCommand extends Command
{
    protected $signature = 'atlas:engineering:quality-foundry-manifests
        {--mutation : Run the real scoped Infection mutation battery}
        {--mutation-only : Emit only the real mutation evidence; skip mode readiness subprocesses}
        {--mutation-surface= : Run only one registered mutative surface (diagnostic; matrix remains incomplete)}
        {--json : Machine-readable JSON}';

    protected $description = 'Execute mode-scoped evidence tests and emit read-only Quality Foundry live manifests.';

    public function handle(QualityFoundryLiveManifestService $service): int
    {
        if ((bool) $this->option('mutation-only')) {
            $surface = is_string($this->option('mutation-surface')) ? $this->option('mutation-surface') : null;
            $payload = (new QualityFoundryMutationCoverageRunner(base_path()))
                ->run('quality-foundry-mutation-only', $surface);

            if ((bool) $this->option('json')) {
                $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $this->line('[atlas:engineering:quality-foundry-manifests] mutation_status='.$payload['status']);
                $this->line('  mutation_score_percent='.(string) ($payload['mutation_score_percent'] ?? 'n/a'));
                $this->line('  tested_surfaces='.implode(',', (array) ($payload['tested_mutation_surfaces'] ?? [])));
            }

            return ($payload['status'] ?? null) === 'failed' ? self::FAILURE : self::SUCCESS;
        }

        $payload = $service->build(
            (bool) $this->option('mutation'),
            is_string($this->option('mutation-surface')) ? $this->option('mutation-surface') : null,
        );

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line('[atlas:engineering:quality-foundry-manifests] status='.$payload['status']);
        $this->line('  operational_status='.$payload['operational_status']);
        $this->line('  ready_modes='.$payload['summary']['ready_modes'].'/'.$payload['summary']['required_modes']);
        $this->line('  completion_allowed='.(YesNo::trueFalse($payload['completion_allowed'])));

        return self::SUCCESS;
    }
}
