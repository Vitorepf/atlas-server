<?php

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasCognitivePipelineOverlayService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Runtime surface for the cognitive Pipeline Overlay. Without args it prints the
 * overlay snapshot (authority chain, flow catalog size, runtime-ready flows,
 * mandatory gates, temporal loops). With --flow / --available-seconds it
 * evaluates a real classification / loop selection.
 *
 * @see docs/engineering-knowledge-base/cognitive/pipeline-overlay.md
 */
class AtlasCognitivePipelineOverlayCommand extends Command
{
    protected $signature = 'atlas:aaeos:cognitive-pipeline-overlay
        {--flow= : Classify a learning flow id (cadence + maturity + runtime readiness)}
        {--available-seconds= : Select the temporal loop for this many available seconds}
        {--json : Print machine-readable JSON}';

    protected $description = 'Inspect and evaluate the Atlas cognitive Pipeline Overlay (flow catalog, gates, repair, temporal loops).';

    public function handle(AtlasCognitivePipelineOverlayService $overlay): int
    {
        try {
            $flow = $this->option('flow');
            $seconds = $this->option('available-seconds');

            if (is_string($flow) && trim($flow) !== '') {
                return $this->emit($overlay->classifyFlow($flow));
            }
            if (is_numeric($seconds)) {
                return $this->emit($overlay->selectLoop((int) $seconds));
            }

            return $this->emit($overlay->snapshot());
        } catch (Throwable $e) {
            return $this->emit([
                'schema_version' => AtlasCognitivePipelineOverlayService::SCHEMA_VERSION,
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
