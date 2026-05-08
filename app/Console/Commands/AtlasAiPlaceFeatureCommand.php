<?php

namespace App\Console\Commands;

use App\Services\Ai\Kernel\Architecture\AtlasFeaturePlacementService;
use App\Services\Ai\Kernel\Architecture\AtlasGovernanceGateService;
use Illuminate\Console\Command;

class AtlasAiPlaceFeatureCommand extends Command
{
    protected $signature = 'atlas:ai:place-feature
        {feature : Feature, bug, question or capability to place in the Atlas architecture}
        {--hint=* : Optional key=value hints}
        {--strict : Return failure when the placement gate is blocked}
        {--json : Print machine-readable JSON}';

    protected $description = 'Place a feature in the canonical Atlas AI architecture before implementation.';

    public function handle(AtlasFeaturePlacementService $placement, AtlasGovernanceGateService $gate): int
    {
        $payload = $placement->place((string) $this->argument('feature'), $this->hints());

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $gate->cliExitCode($payload, (bool) $this->option('strict'));
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Feature Placement</>', $payload['status']);
        $this->components->twoColumnDetail('Layer', data_get($payload, 'placement.layer'));
        $this->components->twoColumnDetail('Domain', data_get($payload, 'placement.domain'));
        $this->components->twoColumnDetail('Business Context', data_get($payload, 'placement.business_context', '-'));
        $this->components->twoColumnDetail('Flow', data_get($payload, 'placement.flow'));
        $this->components->twoColumnDetail('Surface', data_get($payload, 'placement.surface', '-'));
        $this->components->twoColumnDetail('Runtime', data_get($payload, 'placement.runtime', '-'));

        $this->newLine();
        $this->table(
            ['owner doc', 'exists'],
            collect($payload['owner_docs'])->map(fn (array $doc): array => [$doc['path'], $doc['exists']])->all(),
        );

        $duplicates = (array) ($payload['duplicate_candidates'] ?? []);
        if ($duplicates !== []) {
            $this->newLine();
            $this->warn('Possiveis duplicacoes encontradas; revise antes de implementar.');
            $this->table(
                ['source', 'path', 'score'],
                collect($duplicates)->take(5)->map(fn (array $candidate): array => [
                    $candidate['source'] ?? '-',
                    $candidate['path'] ?? '-',
                    (string) ($candidate['score'] ?? 0),
                ])->all(),
            );
        }

        return $gate->cliExitCode($payload, (bool) $this->option('strict'));
    }

    /**
     * @return array<string,string>
     */
    private function hints(): array
    {
        $hints = [];
        foreach ((array) $this->option('hint') as $hint) {
            if (! is_string($hint) || ! str_contains($hint, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $hint, 2);
            $key = trim($key);
            if ($key !== '') {
                $hints[$key] = trim($value);
            }
        }

        return $hints;
    }

}
