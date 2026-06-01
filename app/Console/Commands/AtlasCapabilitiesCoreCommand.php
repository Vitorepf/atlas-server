<?php

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasCapabilitiesCoreService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Runtime surface for the cognitive Capabilities Core. Without args it prints the
 * catalog snapshot (evidence levels, capability count, implemented capabilities,
 * non-default capabilities, restricted capabilities). With --capability it
 * classifies a single capability (evidence + runtime + defaultability).
 *
 * @see docs/engineering-knowledge-base/cognitive/capabilities-core.md
 */
class AtlasCapabilitiesCoreCommand extends Command
{
    protected $signature = 'atlas:aaeos:capabilities-core
        {--capability= : Classify a single capability (evidence + runtime + defaultability)}
        {--json : Print machine-readable JSON}';

    protected $description = 'Inspect and evaluate the Atlas cognitive Capabilities Core catalog (evidence levels, placement, operational restrictions).';

    public function handle(AtlasCapabilitiesCoreService $capabilities): int
    {
        try {
            $capability = $this->option('capability');

            if (is_string($capability) && trim($capability) !== '') {
                return $this->emit($capabilities->classifyCapability($capability));
            }

            return $this->emit($capabilities->snapshot());
        } catch (Throwable $e) {
            return $this->emit([
                'schema_version' => AtlasCapabilitiesCoreService::SCHEMA_VERSION,
                'error' => true,
                'message' => $e->getMessage(),
            ], self::FAILURE);
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, int $exit = self::SUCCESS): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return $exit;
        }

        foreach ($payload as $key => $value) {
            $this->components->twoColumnDetail((string) $key, is_scalar($value) ? (string) $value : json_encode($value));
        }

        return $exit;
    }
}
