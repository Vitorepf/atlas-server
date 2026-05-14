<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AtlasProject;
use App\Services\Ai\Programming\AtlasForgeProviderCapacityService;
use App\Services\Ai\Programming\AtlasForgeProviderFailureMemoryService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Atlas Forge Provider Capacity CLI.
 *
 * Read-only inspection of the local capacity snapshot for the 5 canonical
 * Forge runtime providers. Never calls an external provider, never spends a
 * token. Optionally scopes the snapshot to a bound Obra so failure memory
 * influences the report.
 *
 * Doc: docs/engineering-knowledge-base/atlas-forge-provider-capacity-continuity-v1.md
 */
final class AtlasForgeProviderCapacityCommand extends Command
{
    protected $signature = 'atlas:forge:provider-capacity
        {--obra= : UUID de Obra opcional para incluir failure memory persistida}
        {--workspace= : Workspace root opcional}
        {--json : Emite JSON canonico atlas.forge.provider_capacity.v1}
        {--strict : Exit non-zero se schema invalido ou capacity exhausted}';

    protected $description = 'Atlas Forge Provider Capacity (read-model). Local-only; nunca chama provider externo.';

    public function handle(
        AtlasForgeProviderCapacityService $capacity,
        AtlasForgeProviderFailureMemoryService $memory,
    ): int {
        $obraId = $this->stringOption('obra');
        $workspace = $this->stringOption('workspace');
        $strict = (bool) $this->option('strict');

        $snapshot = $capacity->snapshot([
            'obra_id' => $obraId,
            'workspace' => $workspace,
        ]);

        if ($obraId !== null && Str::isUuid($obraId)) {
            try {
                $project = AtlasProject::query()->whereKey($obraId)->first();
                if ($project !== null) {
                    $snapshot['failure_memory'] = $memory->snapshot($project);
                }
            } catch (Throwable) {
                // best-effort enrichment; capacity snapshot stays canonical.
            }
        }

        if ((bool) $this->option('json')) {
            $this->line($this->encode($snapshot));
        } else {
            $this->renderHuman($snapshot);
        }

        return $this->resolveExit($snapshot, $strict);
    }

    /**
     * @param  array<string,mixed>  $snapshot
     */
    private function renderHuman(array $snapshot): void
    {
        $this->components->twoColumnDetail(
            '<fg=bright-blue;options=bold>Atlas Forge Provider Capacity</>',
            (string) ($snapshot['schema_version'] ?? ''),
        );
        $this->components->twoColumnDetail('Status', (string) ($snapshot['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Workspace', (string) ($snapshot['workspace'] ?? '—'));
        $this->components->twoColumnDetail('Obra', (string) ($snapshot['obra_id'] ?? '—'));
        $this->components->twoColumnDetail('Best available', (string) ($snapshot['best_available_provider'] ?? '—'));
        $this->components->twoColumnDetail(
            'Counts',
            sprintf(
                'available=%d  degraded=%d  unavailable=%d  unknown=%d',
                (int) ($snapshot['available_count'] ?? 0),
                (int) ($snapshot['degraded_count'] ?? 0),
                (int) ($snapshot['unavailable_count'] ?? 0),
                (int) ($snapshot['unknown_count'] ?? 0),
            ),
        );
        $this->components->twoColumnDetail('Runtime dispatch', $snapshot['runtime_dispatch_allowed'] ? 'allowed' : 'blocked');

        $providers = is_array($snapshot['providers'] ?? null) ? $snapshot['providers'] : [];
        if ($providers !== []) {
            $this->newLine();
            $this->components->info('Providers');
            foreach ($providers as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $line = sprintf(
                    '%-14s  %-12s  cap=%-10s  rate=%-10s  quota=%-10s  auth=%-12s  conf=%s',
                    (string) ($entry['provider'] ?? '—'),
                    (string) ($entry['status'] ?? '—'),
                    (string) ($entry['capacity_state'] ?? '—'),
                    (string) ($entry['rate_limit_state'] ?? '—'),
                    (string) ($entry['quota_state'] ?? '—'),
                    (string) ($entry['auth_state'] ?? '—'),
                    (string) ($entry['confidence'] ?? '—'),
                );
                $this->line($line);
            }
        }

        $blockers = is_array($snapshot['blockers'] ?? null) ? $snapshot['blockers'] : [];
        if ($blockers !== []) {
            $this->newLine();
            $this->components->bulletList($blockers);
        }
    }

    /**
     * @param  array<string,mixed>  $snapshot
     */
    private function resolveExit(array $snapshot, bool $strict): int
    {
        if (! $strict) {
            return self::SUCCESS;
        }

        $schema = (string) ($snapshot['schema_version'] ?? '');
        if ($schema !== AtlasForgeProviderCapacityService::SCHEMA_VERSION) {
            return self::FAILURE;
        }
        if ((string) ($snapshot['status'] ?? '') === AtlasForgeProviderCapacityService::TOP_STATUS_BLOCKED) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function encode(array $payload): string
    {
        return (string) json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}
