<?php

namespace App\Console\Commands;

use App\Models\AiTrace;
use App\Services\Ai\AtlasMemoryUsageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AtlasMemoryAuditCommand extends Command
{
    protected $signature = 'atlas:memory:audit
        {--trace-id= : AI trace UUID}
        {--json : Print machine-readable JSON}';

    protected $description = 'Audit which Atlas memory entries were used by a trace context pack.';

    public function handle(AtlasMemoryUsageService $usages): int
    {
        if (! Schema::hasTable('atlas_memory_entry_usages')) {
            $this->error('Tabela atlas_memory_entry_usages ainda nao existe. Rode migrations.');

            return self::FAILURE;
        }

        $traceId = is_string($this->option('trace-id')) ? trim($this->option('trace-id')) : '';
        if ($traceId === '') {
            $this->error('--trace-id e obrigatorio.');

            return self::FAILURE;
        }

        $trace = AiTrace::query()->find($traceId);
        if (! $trace) {
            $this->error("Trace nao encontrado: {$traceId}");

            return self::FAILURE;
        }

        $audit = $usages->auditTrace($trace);
        if ((bool) $this->option('json')) {
            $this->line(json_encode(['audit' => $audit], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Memory Audit</>', $trace->id);
        $this->components->twoColumnDetail('Context snapshot', (string) ($audit['context_snapshot_id'] ?? '-'));
        $this->components->twoColumnDetail('Memory usages', (string) ($audit['memory_usage_count'] ?? 0));

        $this->table(
            ['usage', 'type', 'scope', 'reason', 'feedback', 'memory'],
            collect((array) ($audit['memories'] ?? []))->map(fn (array $usage): array => [
                $usage['id'] ?? '-',
                $usage['memory_type'] ?? '-',
                ($usage['scope_id'] ?? null) ? ($usage['scope_type'] ?? '-').':'.$usage['scope_id'] : ($usage['scope_type'] ?? '-'),
                Str::limit((string) ($usage['included_reason'] ?? '-'), 50),
                $usage['feedback_action'] ?? '-',
                Str::limit((string) data_get($usage, 'memory.title', data_get($usage, 'memory.summary', data_get($usage, 'memory.body', '-'))), 80),
            ])->all(),
        );

        return self::SUCCESS;
    }
}
