<?php

namespace App\Console\Commands;

use App\Console\Commands\Support\AtlasCliLimitInput;
use App\Console\Concerns\EmitsCanonicalJson;
use App\Models\AiToolEvent;
use App\Models\AiTrace;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Support\YesNo;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class AtlasCliTraceCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:cli:trace
        {action=last : last, show, replay or list}
        {trace? : Trace id. Empty uses latest}
        {--workspace= : Accepted for bin/atlas compatibility; traces are NOT workspace-scoped (ai_traces has no workspace column)}
        {--limit=20}
        {--full : Show less-redacted payloads}
        {--json : Print machine-readable JSON}';

    protected $description = 'Inspect Atlas traces, tool events, quality gates and replay-safe command summaries.';

    private ?AtlasCliLimitInput $limits = null;

    public function __construct(?AtlasCliLimitInput $limits = null)
    {
        parent::__construct();

        $this->limits = $limits;
    }

    public function handle(): int
    {
        $action = Str::of((string) $this->argument('action'))->lower()->trim()->value();

        return match ($action) {
            'last' => $this->show($this->latestTrace()),
            'show' => $this->show($this->resolveTrace()),
            'replay' => $this->replay($this->resolveTrace()),
            'list' => $this->list(),
            default => $this->invalidAction($action),
        };
    }

    private function list(): int
    {
        $traces = AiTrace::query()
            ->latest()
            ->limit($this->cliLimits()->standardLimit($this->option('limit')))
            ->get();

        $payload = $traces->map(fn (AiTrace $trace): array => $this->traceSummary($trace))->all();

        if ((bool) $this->option('json')) {
            $this->line(json_encode(['traces' => $payload], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->table(['trace', 'status', 'provider', 'agent', 'created'], collect($payload)->map(fn (array $row): array => [
            $row['id'],
            $row['status'],
            $row['provider'],
            $row['agent_slug'],
            $row['created_at'],
        ])->all());

        return self::SUCCESS;
    }

    private function cliLimits(): AtlasCliLimitInput
    {
        return $this->limits ?? app(AtlasCliLimitInput::class);
    }

    private function show(?AiTrace $trace): int
    {
        if (! $trace) {
            return $this->notFound();
        }

        $payload = $this->tracePayload($trace);

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Trace</>', $trace->id);
        $this->components->twoColumnDetail('Status', $trace->status);
        $this->components->twoColumnDetail('Provider', (string) $trace->provider);
        $this->components->twoColumnDetail('Agent', $trace->agent_slug);
        $this->components->twoColumnDetail('Thread', (string) $trace->thread_id);
        $this->components->twoColumnDetail('Session', (string) $trace->session_id);

        $this->newLine();
        $this->line('<fg=bright-blue;options=bold>Input</>');
        $this->line(Str::limit($trace->operator_input, 1200));

        if ($trace->response_text) {
            $this->newLine();
            $this->line('<fg=bright-blue;options=bold>Response</>');
            $this->line((bool) $this->option('full') ? $trace->response_text : Str::limit($trace->response_text, 2000));
        }

        $events = (array) $payload['tool_events'];
        if ($events !== []) {
            $this->newLine();
            $this->line('<fg=bright-blue;options=bold>Tool Events</>');
            $this->table(['tool', 'risk', 'permission', 'ok', 'ms', 'files'], collect($events)->map(fn (array $event): array => [
                $event['tool'],
                $event['risk'],
                $event['permission_status'],
                (bool) YesNo::format(data_get($event, 'output_summary.ok')),
                $event['duration_ms'],
                implode(', ', (array) ($event['changed_files'] ?? [])),
            ])->all());
        }

        return self::SUCCESS;
    }

    private function replay(?AiTrace $trace): int
    {
        if (! $trace) {
            return $this->notFound();
        }

        $events = $this->toolEvents($trace);
        $lines = [
            '# Replay seguro gerado pelo Atlas.',
            '# Comandos de escrita nao sao reaplicados automaticamente.',
            'set -euo pipefail',
            '',
        ];

        foreach ($events as $event) {
            $tool = (string) $event['tool'];
            $args = (array) data_get($event, 'input_summary.arguments', []);
            $lines[] = '# '.$tool.' ['.$event['permission_status'].']';

            $lines[] = match ($tool) {
                'git.status' => 'git status --short',
                'git.diff' => 'git diff',
                'search.rg' => 'rg '.escapeshellarg((string) ($args['query'] ?? '')).' '.escapeshellarg((string) ($args['path'] ?? '.')),
                'session.search' => 'atlas search '.escapeshellarg((string) ($args['query'] ?? '')),
                'test.run' => isset($args['command']) && is_string($args['command']) ? '# review before running: '.$args['command'] : '# test.run command redacted',
                'shell.run' => isset($args['command']) && is_string($args['command']) ? '# review before running: '.$args['command'] : '# shell.run command redacted',
                default => '# '.$tool.' replay is informational only',
            };
            $lines[] = '';
        }

        $script = implode("\n", $lines);

        if ((bool) $this->option('json')) {
            $this->line(json_encode(['trace_id' => $trace->id, 'script' => $script], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line($script);
        }

        return self::SUCCESS;
    }

    private function resolveTrace(): ?AiTrace
    {
        $id = $this->argument('trace');

        return is_string($id) && $id !== ''
            ? AiTrace::query()->find($id)
            : $this->latestTrace();
    }

    private function latestTrace(): ?AiTrace
    {
        return AiTrace::query()->latest()->first();
    }

    /**
     * @return array<string,mixed>
     */
    private function tracePayload(AiTrace $trace): array
    {
        return $this->traceSummary($trace) + [
            'operator_input' => $trace->operator_input,
            'response_text' => (bool) $this->option('full') ? $trace->response_text : Str::limit((string) $trace->response_text, 4000),
            'metadata' => (bool) $this->option('full') ? $trace->metadata : [
                'mode' => data_get($trace->metadata, 'mode'),
                'execution_plan' => data_get($trace->metadata, 'execution_plan'),
                'dev_execution_plan' => data_get($trace->metadata, 'dev_execution_plan'),
            ],
            'router_decision' => $trace->routerDecision ? [
                'mode' => $trace->routerDecision->mode,
                'selected_provider' => $trace->routerDecision->selected_provider,
                'fallback_provider' => $trace->routerDecision->fallback_provider,
                'reason' => $trace->routerDecision->reason,
                'signals' => $trace->routerDecision->signals,
            ] : null,
            'tool_events' => $this->toolEvents($trace),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function traceSummary(AiTrace $trace): array
    {
        return [
            'id' => $trace->id,
            'trace_key' => $trace->trace_key,
            'status' => $trace->status,
            'provider' => $trace->provider,
            'agent_slug' => $trace->agent_slug,
            'thread_id' => $trace->thread_id,
            'session_id' => $trace->session_id,
            'created_at' => $trace->created_at?->toJSON(),
            'completed_at' => $trace->completed_at?->toJSON(),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function toolEvents(AiTrace $trace): array
    {
        if (! DatabaseTableAvailability::has('ai_tool_events')) {
            return [];
        }

        return AiToolEvent::query()
            ->where('trace_id', $trace->id)
            ->orderBy('created_at')
            ->get()
            ->map(fn (AiToolEvent $event): array => [
                'id' => $event->id,
                'tool' => $event->tool,
                'risk' => $event->risk,
                'permission_status' => $event->permission_status,
                'approval_source' => $event->approval_source,
                'input_summary' => $event->input_summary,
                'output_summary' => $event->output_summary,
                'changed_files' => $event->changed_files,
                'checkpoint_id' => $event->checkpoint_id,
                'exit_code' => $event->exit_code,
                'duration_ms' => $event->duration_ms,
                'error' => $event->error,
                'created_at' => $event->created_at?->toJSON(),
            ])
            ->all();
    }

    private function invalidAction(string $action): int
    {
        $this->error("Acao invalida para atlas trace: {$action}");

        return self::FAILURE;
    }

    private function notFound(): int
    {
        $this->error('Trace nao encontrado.');

        return self::FAILURE;
    }
}
