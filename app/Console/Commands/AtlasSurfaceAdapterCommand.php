<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasSurfaceAdapterService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Surface Adapter — input/output normalization CLI.
 *
 *   php artisan atlas:aaeos:surface-adapter
 *     [--surface=atlas_code] [--origin=operator] [--channel=desktop]
 *     [--content="paste of code"]
 *     [--provider=...] [--policy=...] [--execute=...] [--plan=...]  // smuggled (stripped/flagged)
 *     [--json]
 *
 * Read-only, deterministic. Normalizes a raw surface event into the common
 * payload that flows to `atlas-input`. It NEVER executes a tool, picks a
 * provider/model, applies policy, or infers a plan. Any execution/provider/
 * policy field is stripped; any adapter-level plan decision is dropped and
 * flagged. A raw event with no origin is rejected (it would break the audit
 * trail). With no flags the defaults model an Atlas Code paste => outcome=normalized.
 *
 * @see docs/engineering-knowledge-base/system-graph/surface-adapter.md
 */
class AtlasSurfaceAdapterCommand extends Command
{
    protected $signature = 'atlas:aaeos:surface-adapter
        {--surface=atlas_code : surface id the raw event came from}
        {--origin= : where the input originated (required for audit; defaults to operator)}
        {--channel= : UX channel label preserved onto metadata}
        {--content= : the request content carried verbatim}
        {--provider= : provider field the surface tries to carry (forbidden; stripped)}
        {--policy= : policy field the surface tries to carry (forbidden; stripped)}
        {--execute= : execution field the surface tries to carry (forbidden; stripped)}
        {--plan= : plan/intent the adapter must not infer (deferred; flagged)}
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas surface adapter · normalize a raw surface event into the common payload for atlas-input, enforcing the no-execution/provider/policy and audit-origin invariants.';

    public function handle(AtlasSurfaceAdapterService $service): int
    {
        try {
            $event = array_filter([
                'surface' => $this->stringOption('surface') ?? 'atlas_code',
                'origin' => $this->stringOption('origin') ?? 'operator',
                'channel' => $this->stringOption('channel'),
                'content' => $this->stringOption('content') ?? 'normalize this surface input',
                'provider' => $this->stringOption('provider'),
                'policy' => $this->stringOption('policy'),
                'execute' => $this->stringOption('execute'),
                'plan' => $this->stringOption('plan'),
            ], static fn (mixed $v): bool => $v !== null);

            $result = $service->normalize($event);

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
}
