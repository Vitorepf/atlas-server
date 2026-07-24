<?php

namespace App\Console\Commands;

use App\Console\Concerns\EmitsCanonicalJson;
use App\Services\Ai\ProgrammingRuntime\ControlPlane\ProgrammingRuntimeControlPlaneCanon;
use App\Services\Ai\ProgrammingRuntime\ControlPlane\ProgrammingRuntimeControlPlaneService;
use Illuminate\Console\Command;
use App\Support\YesNo;

class AtlasAiProgrammingRuntimeControlPlaneCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:ai:programming-runtime-control-plane
        {--json : output JSON only}';

    protected $description = 'Programming Runtime control plane: aggregated read model (missions, Dev runs, Forge Obras, work packets, RAG gates, repair loops, telemetry, blockers, evidence, certification, next actions). Read only, never runs a benchmark.';

    public function handle(ProgrammingRuntimeControlPlaneService $service): int
    {
        $payload = $service->snapshot();

        if ($this->option('json')) {
            $this->line($this->encodeOrEmptyObject($payload));
        } else {
            $this->renderHuman($payload);
        }

        return match ($payload['runtime_status']) {
            ProgrammingRuntimeControlPlaneCanon::STATUS_BLOCKED => Command::FAILURE,
            default => Command::SUCCESS,
        };
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function renderHuman(array $payload): void
    {
        $this->line(sprintf(
            '[programming-runtime-control-plane] schema=%s status=%s generated_at=%s',
            (string) $payload['schema_version'],
            (string) $payload['runtime_status'],
            (string) $payload['generated_at'],
        ));
        $this->line(sprintf(
            'benchmark_status.not_run=%s rivals_compared=%s',
            YesNo::trueFalse($payload['benchmark_status']['not_run'] ?? true),
            YesNo::trueFalse($payload['benchmark_status']['rivals_compared'] ?? false),
        ));

        $missions = (array) ($payload['active_missions'] ?? []);
        $this->line(sprintf(
            'missions: active=%d total=%d',
            (int) ($missions['count_active'] ?? 0),
            (int) ($missions['count_total'] ?? 0),
        ));

        $devRuns = (array) ($payload['dev_runs_summary'] ?? []);
        $this->line(sprintf(
            'dev_runs: count=%d avg_exec=%s',
            (int) ($devRuns['count'] ?? 0),
            $this->formatNumeric($devRuns['avg_execution_quality'] ?? null),
        ));

        $forge = (array) ($payload['forge_obras_summary'] ?? []);
        $this->line(sprintf('forge_obras: count=%d', (int) ($forge['count'] ?? 0)));

        $workPackets = (array) ($payload['work_packets_summary'] ?? []);
        $this->line(sprintf('work_packets: count=%d', (int) ($workPackets['count'] ?? 0)));

        $ragGates = (array) ($payload['rag_gate_summary'] ?? []);
        $this->line(sprintf(
            'rag_gates: count=%d failed_closed=%d avg_sufficiency=%s',
            (int) ($ragGates['count'] ?? 0),
            (int) ($ragGates['failed_closed_count'] ?? 0),
            $this->formatNumeric($ragGates['avg_context_sufficiency'] ?? null),
        ));

        $repairLoops = (array) ($payload['repair_loop_summary'] ?? []);
        $this->line(sprintf('repair_loops: count=%d', (int) ($repairLoops['count'] ?? 0)));

        $telemetry = (array) ($payload['telemetry_summary'] ?? []);
        $this->line(sprintf(
            'telemetry: total=%d distinct_runs=%d',
            (int) ($telemetry['total_events'] ?? 0),
            (int) ($telemetry['distinct_runs'] ?? 0),
        ));

        $blockers = (array) ($payload['blockers'] ?? []);
        $this->line(sprintf(
            'blockers: total=%d by_severity=%s',
            (int) ($blockers['total'] ?? 0),
            json_encode($blockers['by_severity'] ?? new \stdClass) ?: '{}',
        ));
        foreach (array_slice((array) ($blockers['items'] ?? []), 0, 5) as $item) {
            $this->line(sprintf(
                '  - [%s/%s] %s: %s',
                (string) $item['severity'],
                (string) $item['source'],
                (string) $item['id'],
                (string) $item['message'],
            ));
        }

        $nextActions = (array) ($payload['next_actions'] ?? []);
        $this->line('next_actions:');
        foreach (array_slice($nextActions, 0, 5) as $action) {
            $this->line(sprintf(
                '  - [%s/%s] %s',
                (string) ($action['priority'] ?? '?'),
                (string) ($action['source'] ?? '?'),
                (string) ($action['description'] ?? ''),
            ));
        }
    }

    private function formatNumeric(mixed $value): string
    {
        if (! is_numeric($value)) {
            return 'n/a';
        }

        return (string) $value;
    }

}
