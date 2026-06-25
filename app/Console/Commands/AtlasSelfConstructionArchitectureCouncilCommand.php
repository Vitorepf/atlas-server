<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\ArchitectureCouncil\AtlasArchitectureCouncilBoundaryMap;
use App\Services\Ai\SelfConstruction\ArchitectureCouncil\AtlasArchitectureCouncilContractCritic;
use App\Services\Ai\SelfConstruction\ArchitectureCouncil\AtlasArchitectureCouncilImplementationSliceDesigner;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only CLI for the Self-Construction Architecture Council surface.
 *
 * Verbs:
 *   inspect    — services + non-execution guarantees.
 *   critic     — critique a contract via the contract critic.
 *   invariants — list the invariants declared in a contract (passthrough; no derivation).
 *   boundaries — map organ boundaries via the boundary map.
 *   slices     — design implementation slice briefs from a contract bundle.
 *
 * Every verb is READ-ONLY: no enqueue, no execute, no storage mutation.
 */
final class AtlasSelfConstructionArchitectureCouncilCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:self-construction:architecture-council {action : inspect|critic|invariants|boundaries|slices} {--contract=} {--json}';

    /** @var string */
    protected $description = 'Read-only Architecture Council surface: inspect / critic / invariants / boundaries / slices.';

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        $contract = $this->readJson('contract');

        $payload = match ($action) {
            'inspect' => $this->inspect(),
            'critic' => $this->critic($contract),
            'invariants' => $this->invariants($contract),
            'boundaries' => $this->boundaries($contract),
            'slices' => $this->slices($contract),
            default => ['status' => 'unknown_action', 'action' => $action],
        };
        $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return ($payload['status'] ?? 'ok') === 'ok' || ! isset($payload['status']) ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string,mixed> */
    private function inspect(): array
    {
        return [
            'status' => 'ok',
            'verbs' => ['inspect', 'critic', 'invariants', 'boundaries', 'slices'],
            'services' => [
                AtlasArchitectureCouncilContractCritic::SCHEMA,
                AtlasArchitectureCouncilBoundaryMap::SCHEMA,
                AtlasArchitectureCouncilImplementationSliceDesigner::SCHEMA,
            ],
            'non_execution_guarantees' => [
                'writes_storage' => false,
                'enqueues_tasks' => false,
                'invokes_shell_or_git' => false,
                'calls_external_providers' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>|null  $contract
     * @return array<string,mixed>
     */
    private function critic(?array $contract): array
    {
        if (! is_array($contract)) {
            return ['status' => 'usage_error', 'reason' => '--contract JSON file required'];
        }
        $r = $this->app()->make(AtlasArchitectureCouncilContractCritic::class)->critique($contract);

        return ['status' => 'ok', 'critic' => $r];
    }

    /**
     * @param  array<string,mixed>|null  $contract
     * @return array<string,mixed>
     */
    private function invariants(?array $contract): array
    {
        if (! is_array($contract)) {
            return ['status' => 'usage_error', 'reason' => '--contract JSON file required'];
        }
        $invariants = is_array($contract['invariants'] ?? null) ? array_values(array_map('strval', $contract['invariants'])) : [];

        return ['status' => 'ok', 'invariants' => $invariants];
    }

    /**
     * @param  array<string,mixed>|null  $contract
     * @return array<string,mixed>
     */
    private function boundaries(?array $contract): array
    {
        if (! is_array($contract)) {
            return ['status' => 'usage_error', 'reason' => '--contract JSON file required'];
        }
        $contracts = is_array($contract['contracts'] ?? null) ? $contract['contracts'] : [$contract];
        $r = $this->app()->make(AtlasArchitectureCouncilBoundaryMap::class)->map($contracts);

        return ['status' => 'ok', 'boundary_map' => $r];
    }

    /**
     * @param  array<string,mixed>|null  $contract
     * @return array<string,mixed>
     */
    private function slices(?array $contract): array
    {
        if (! is_array($contract)) {
            return ['status' => 'usage_error', 'reason' => '--contract JSON file required'];
        }
        try {
            $r = $this->app()->make(AtlasArchitectureCouncilImplementationSliceDesigner::class)->design($contract);
        } catch (Throwable $e) {
            return ['status' => 'slices_invalid', 'reason' => $e->getMessage()];
        }

        return ['status' => 'ok', 'slices' => $r];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readJson(string $optionName): ?array
    {
        $path = (string) ($this->option($optionName) ?? '');
        if ($path === '' || ! is_file($path)) {
            return null;
        }
        try {
            $decoded = json_decode((string) file_get_contents($path), true);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /** @return \Illuminate\Contracts\Container\Container */
    private function app()
    {
        return $this->getLaravel();
    }
}
