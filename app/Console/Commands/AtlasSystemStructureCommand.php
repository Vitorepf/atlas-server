<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Engineering\AtlasSystemStructureService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

final class AtlasSystemStructureCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:system-structure
        {--area= : Drill into a single top area, e.g. app/Services/Ai}
        {--json : Emit canonical JSON}
        {--strict : Exit non-zero unless the structure derived successfully}';

    protected $description = 'Read-only derived Atlas system structure (areas -> subsystems -> services/commands) from the live code index or filesystem.';

    public function handle(AtlasSystemStructureService $service): int
    {
        $area = $this->option('area');
        $area = is_string($area) ? trim($area) : '';

        $payload = $area !== ''
            ? $service->deriveArea($area)
            : $service->deriveStructure();

        if ((bool) $this->option('json')) {
            $this->jsonLine($payload);

            return $this->exitCode($payload);
        }

        return $area !== ''
            ? $this->renderArea($payload)
            : $this->renderStructure($payload);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function renderStructure(array $payload): int
    {
        if (($payload['available'] ?? false) === false) {
            $this->error('System structure unavailable: '.(string) ($payload['reason'] ?? 'unknown'));

            return $this->exitCode($payload);
        }

        $summary = (array) ($payload['summary'] ?? []);
        $this->components->twoColumnDetail('Atlas System Structure', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Source', (string) ($payload['source'] ?? 'unknown'));
        $this->components->twoColumnDetail('Areas', (string) ($summary['area_count'] ?? 0));
        $this->components->twoColumnDetail('Subsystems', (string) ($summary['subsystem_count'] ?? 0));
        $this->components->twoColumnDetail('Subsystems (app/Services/Ai)', (string) ($summary['ai_subsystem_count'] ?? 0));
        $this->components->twoColumnDetail('Services', (string) ($summary['service_count'] ?? 0));
        $this->components->twoColumnDetail('Commands', (string) ($summary['command_count'] ?? 0));
        $this->components->twoColumnDetail('Nodes', (string) ($summary['node_count'] ?? 0));
        $this->components->twoColumnDetail('Edges', (string) ($summary['edge_count'] ?? 0));
        $this->components->twoColumnDetail('  containment / dependency', ((string) ($summary['containment_edge_count'] ?? 0)).' / '.((string) ($summary['dependency_edge_count'] ?? 0)));

        $this->newLine();
        $this->line('<options=bold>Top areas (services / commands / subsystems):</>');
        foreach ((array) ($payload['nodes'] ?? []) as $node) {
            if (($node['kind'] ?? null) !== 'area') {
                continue;
            }
            $this->components->twoColumnDetail(
                '  '.(string) ($node['real_path'] ?? ''),
                ((string) ($node['service_count'] ?? 0)).' svc / '.((string) ($node['command_count'] ?? 0)).' cmd / '.((string) ($node['subsystem_count'] ?? 0)).' sub',
            );
        }

        return $this->exitCode($payload);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function renderArea(array $payload): int
    {
        if (($payload['available'] ?? false) === false) {
            $this->error('System structure unavailable: '.(string) ($payload['reason'] ?? 'unknown'));

            return $this->exitCode($payload);
        }

        if (($payload['found'] ?? false) === false) {
            $this->error('Area not found: '.(string) ($payload['area'] ?? ''));
            $this->line('Known areas: '.implode(', ', (array) ($payload['known_areas'] ?? [])));

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Area', (string) ($payload['area'] ?? ''));
        $this->components->twoColumnDetail('Source', (string) ($payload['source'] ?? 'unknown'));
        $this->components->twoColumnDetail('Subsystems', (string) ($payload['subsystem_count'] ?? 0));
        $this->components->twoColumnDetail('Services', (string) data_get($payload, 'area_node.service_count', 0));
        $this->components->twoColumnDetail('Commands', (string) data_get($payload, 'area_node.command_count', 0));

        $this->newLine();
        $this->line('<options=bold>Subsystems (services / commands):</>');
        foreach ((array) ($payload['subsystems'] ?? []) as $subsystem) {
            $this->components->twoColumnDetail(
                '  '.(string) ($subsystem['real_path'] ?? ''),
                ((string) ($subsystem['service_count'] ?? 0)).' svc / '.((string) ($subsystem['command_count'] ?? 0)).' cmd',
            );
        }

        return $this->exitCode($payload);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function exitCode(array $payload): int
    {
        if (! (bool) $this->option('strict')) {
            return self::SUCCESS;
        }

        $ok = ($payload['available'] ?? false) === true
            && (! array_key_exists('found', $payload) || $payload['found'] === true);

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
