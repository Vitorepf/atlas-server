<?php

namespace App\Console\Commands;

use App\Services\Ai\Kernel\Architecture\AtlasExternalGraphHarnessService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use JsonException;

class AtlasAiExternalGraphHarnessCommand extends Command
{
    protected $signature = 'atlas:ai:external-graph-harness
        {--candidate-file= : Optional external_graph_candidate.v1 JSON file to validate read-only}
        {--json : Print machine-readable JSON}';

    protected $description = 'Publish the AP-684 External Graph Harness contract and validate graph candidates without writes.';

    public function handle(AtlasExternalGraphHarnessService $harness): int
    {
        $candidate = $this->candidateFromOption();

        if ($candidate === false) {
            return self::FAILURE;
        }

        $payload = [
            'status' => 'ok',
            'external_graph_harness' => $harness->report($candidate),
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $report = $payload['external_graph_harness'];
        $validation = $report['candidate_validation'];

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas External Graph Harness</>', $report['status']);
        $this->components->twoColumnDetail('Mode', $report['mode']);
        $this->components->twoColumnDetail('Authority', data_get($report, 'contract.authority'));
        $this->components->twoColumnDetail('Writes memory', data_get($report, 'contract.guardrails.writes_memory_registry') ? 'yes' : 'no');
        $this->components->twoColumnDetail('Provider calls', data_get($report, 'contract.guardrails.provider_calls_enabled') ? 'yes' : 'no');

        if (is_array($validation)) {
            $this->components->twoColumnDetail('Candidate', $validation['status']);
            $this->components->twoColumnDetail('Nodes', (string) $validation['node_count']);
            $this->components->twoColumnDetail('Edges', (string) $validation['edge_count']);
            $this->components->twoColumnDetail('Errors', (string) $validation['error_count']);
            $this->components->twoColumnDetail('Warnings', (string) $validation['warning_count']);
            $this->components->twoColumnDetail('Review packet', (string) data_get($validation, 'review_packet.status'));
            $this->components->twoColumnDetail('Auto promotion', data_get($validation, 'review_packet.auto_promotion_allowed') ? 'allowed' : 'blocked');
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>|false|null
     */
    private function candidateFromOption(): array|false|null
    {
        $path = trim((string) $this->option('candidate-file'));
        if ($path === '') {
            return null;
        }

        if (! File::isFile($path)) {
            $this->components->error("Candidate file not found: {$path}");

            return false;
        }

        try {
            $candidate = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->components->error('Candidate file is not valid JSON: '.$exception->getMessage());

            return false;
        }

        if (! is_array($candidate) || array_is_list($candidate)) {
            $this->components->error('Candidate file must contain a JSON object.');

            return false;
        }

        return $candidate;
    }
}
