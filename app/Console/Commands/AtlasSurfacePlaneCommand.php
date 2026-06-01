<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasSurfacePlaneService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Surface Plane — entry-plane admission + normalization CLI.
 *
 *   php artisan atlas:aaeos:surface-plane
 *     [--surface=atlas_code]
 *     [--channel=desktop] [--origin=operator] [--intent=start_obra]
 *     [--affordances=text,attachment]
 *     [--provider=...] [--policy=...] [--autonomy=...] [--budget=...]  // smuggled decisions (stripped)
 *     [--json]
 *
 * Read-only, deterministic. Decides whether a surface interaction may be
 * admitted to the plane and emits the raw event that flows to `surface-adapter`.
 * It NEVER routes a provider, picks policy/autonomy/budget, or calls the Kernel.
 * Any provider/policy/autonomy/budget passed in is stripped and reported as a
 * boundary violation. With no flags the defaults model an Atlas Code interaction
 * => decision=admit.
 *
 * @see docs/engineering-knowledge-base/system-graph/surface-plane.md
 */
class AtlasSurfacePlaneCommand extends Command
{
    protected $signature = 'atlas:aaeos:surface-plane
        {--surface=atlas_code : official surface id (atlas_code, atlas_cli, atlas_mobile, atlas_api, atlas_mcp, atlas_app)}
        {--channel= : UX channel label preserved onto the event}
        {--origin= : where the interaction originated (e.g. operator, system)}
        {--intent= : logical intent of the request}
        {--affordances= : comma-separated UX affordances}
        {--provider= : provider the surface tries to choose (hard boundary; stripped)}
        {--policy= : policy the surface tries to choose (hard boundary; stripped)}
        {--autonomy= : autonomy the surface tries to choose (hard boundary; stripped)}
        {--budget= : budget the surface tries to choose (hard boundary; stripped)}
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas surface plane · admit|reject a surface interaction and emit the raw event for surface-adapter, enforcing the no-decision invariant.';

    public function handle(AtlasSurfacePlaneService $service): int
    {
        try {
            $interaction = [
                'surface' => $this->stringOption('surface') ?? 'atlas_code',
                'channel' => $this->stringOption('channel'),
                'origin' => $this->stringOption('origin'),
                'intent' => $this->stringOption('intent'),
                'affordances' => $this->listOption('affordances'),
                'payload' => array_filter([
                    'provider' => $this->stringOption('provider'),
                    'policy' => $this->stringOption('policy'),
                    'autonomy' => $this->stringOption('autonomy'),
                    'budget' => $this->stringOption('budget'),
                ], static fn (mixed $v): bool => $v !== null),
            ];

            $result = $service->admit($interaction);

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode(
                ['ok' => false, 'error' => $e->getMessage()],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));

            return self::FAILURE;
        }
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @return array<int,string>
     */
    private function listOption(string $name): array
    {
        $raw = $this->stringOption($name);

        if ($raw === null) {
            return [];
        }

        $parts = array_map('trim', explode(',', $raw));

        return array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));
    }
}
