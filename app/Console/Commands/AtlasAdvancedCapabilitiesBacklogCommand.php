<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasAdvancedCapabilitiesBacklogService;
use Illuminate\Console\Command;

/**
 * Runtime surface for the Advanced Capabilities Backlog doc. Without args it
 * prints the governance snapshot (backlog families, autonomy ladder, tool
 * synthesis minimum gate). With --level it classifies one autonomy-ladder level
 * (optionally as a mutating action via --mutating); with --tool-synthesis it
 * evaluates a comma-separated set of satisfied synthesis requirements against the
 * minimum gate.
 *
 * @see docs/engineering-knowledge-base/evolution/advanced-capabilities-backlog.md
 */
final class AtlasAdvancedCapabilitiesBacklogCommand extends Command
{
    protected $signature = 'atlas:aaeos:advanced-capabilities-backlog
        {--level= : Classify one autonomy-ladder level (shadow|proposal|assisted|governed|critical)}
        {--mutating : Treat the classified level as a mutating/production action (used with --level)}
        {--tool-synthesis= : Comma-separated satisfied synthesis requirements to evaluate against the minimum gate}
        {--json : Print machine-readable JSON}';

    protected $description = 'Inspect and evaluate the Advanced Capabilities Backlog (families, autonomy ladder, tool synthesis minimum gate, simulation loop) runtime.';

    public function handle(AtlasAdvancedCapabilitiesBacklogService $service): int
    {
        $json = (bool) $this->option('json');

        try {
            $level = $this->option('level');
            if (is_string($level) && trim($level) !== '') {
                return $this->emit(
                    $service->classifyLevel($level, (bool) $this->option('mutating')),
                    $json
                );
            }

            $toolSynthesis = $this->option('tool-synthesis');
            if (is_string($toolSynthesis) && trim($toolSynthesis) !== '') {
                $signals = [];
                foreach (explode(',', $toolSynthesis) as $requirement) {
                    $key = trim($requirement);
                    if ($key !== '') {
                        $signals[$key] = true;
                    }
                }

                return $this->emit($service->evaluateToolSynthesis($signals), $json);
            }

            return $this->emit($service->snapshot(), $json);
        } catch (\Throwable $e) {
            $this->emit([
                'schema_version' => AtlasAdvancedCapabilitiesBacklogService::SCHEMA_VERSION,
                'error' => true,
                'message' => $e->getMessage(),
            ], $json);

            return self::FAILURE;
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, bool $json): int
    {
        $this->line((string) json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));

        return self::SUCCESS;
    }
}
