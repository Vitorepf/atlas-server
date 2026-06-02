<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Engineering\AtlasUniversalRealityCartographyService;
use Illuminate\Console\Command;

final class AtlasUniversalRealityCartographyCommand extends Command
{
    protected $signature = 'atlas:universal-reality-cartography
        {action=map : map|nodes|visual-scene|semantic-zoom|human-routes|task-simulator|human-clarity|navigation-slice}
        {--mode=universe : universe|system|flow|evidence|risk|implementation}
        {--json : Emit canonical JSON}
        {--strict : Exit non-zero unless ready}';

    protected $description = 'Read-only AURC visual reality map over ADRS and ACRUI.';

    public function handle(AtlasUniversalRealityCartographyService $service): int
    {
        $payload = $service->map((string) $this->option('mode'));
        $action = (string) $this->argument('action');

        $output = match ($action) {
            'map' => $payload,
            'nodes' => [
                'schema_version' => $payload['schema_version'],
                'status' => $payload['status'],
                'summary' => $payload['summary'],
                'curated_macro_projection' => $payload['curated_macro_projection'],
                'nodes' => $payload['nodes'],
                'edges' => $payload['edges'],
                'complete_derived_structure' => $payload['complete_derived_structure'],
                'system_structure' => $payload['system_structure'],
                'coverage_audit' => $payload['coverage_audit'],
                'writes' => false,
            ],
            'visual-scene' => [
                'schema_version' => $payload['schema_version'],
                'status' => data_get($payload, 'visual_scene.status') === 'ready' ? $payload['status'] : 'review',
                'visual_scene' => $payload['visual_scene'],
                'writes' => false,
            ],
            'semantic-zoom' => [
                'schema_version' => $payload['schema_version'],
                'status' => data_get($payload, 'semantic_zoom_scenes.status') === 'ready' ? $payload['status'] : 'review',
                'semantic_zoom_scenes' => $payload['semantic_zoom_scenes'],
                'writes' => false,
            ],
            'human-routes' => [
                'schema_version' => $payload['schema_version'],
                'status' => data_get($payload, 'human_route_map.status') === 'ready' ? $payload['status'] : 'review',
                'human_route_map' => $payload['human_route_map'],
                'writes' => false,
            ],
            'task-simulator' => [
                'schema_version' => $payload['schema_version'],
                'status' => $payload['status'],
                'task_simulator' => $payload['task_simulator'],
                'writes' => false,
            ],
            'human-clarity' => [
                'schema_version' => $payload['schema_version'],
                'status' => data_get($payload, 'human_clarity.status') === 'ready' ? $payload['status'] : 'review',
                'human_clarity' => $payload['human_clarity'],
                'writes' => false,
            ],
            'navigation-slice' => [
                'schema_version' => $payload['schema_version'],
                'status' => $payload['status'],
                'ai_navigation_slice' => $payload['ai_navigation_slice'],
                'writes' => false,
            ],
            default => null,
        };

        if ($output === null) {
            $this->error('Unknown action. Expected map, nodes, visual-scene, semantic-zoom, human-routes, task-simulator, human-clarity or navigation-slice.');

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return $this->exitCode($output);
        }

        $this->components->twoColumnDetail('Atlas Universal Reality Cartography', (string) $payload['status']);
        $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
        $this->components->twoColumnDetail('Curated macro nodes (entrypoint)', (string) data_get($payload, 'summary.curated_node_count', 0));
        $this->components->twoColumnDetail('Curated macro edges (entrypoint)', (string) data_get($payload, 'summary.curated_edge_count', 0));
        $this->components->twoColumnDetail('Complete derived nodes', (string) data_get($payload, 'summary.complete_node_count', 0));
        $this->components->twoColumnDetail('Complete derived edges', (string) data_get($payload, 'summary.complete_edge_count', 0));
        $this->components->twoColumnDetail('Complete structure source', (string) data_get($payload, 'summary.complete_structure_source', 'unavailable'));
        $this->components->twoColumnDetail('Coverage', (string) data_get($payload, 'coverage_audit.visual_completeness_score', 0));
        $this->components->twoColumnDetail('Hash', (string) $payload['cartography_hash']);

        return $this->exitCode($payload);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function exitCode(array $payload): int
    {
        return (bool) $this->option('strict') && ($payload['status'] ?? null) !== 'ready'
            ? self::FAILURE
            : self::SUCCESS;
    }
}
