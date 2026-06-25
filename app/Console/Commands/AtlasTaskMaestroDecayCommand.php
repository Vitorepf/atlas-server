<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\Maestro\Decay\AtlasMaestroPacketAgeFactReporter;
use App\Services\Ai\SelfConstruction\Maestro\Decay\AtlasMaestroPacketDecayPolicy;
use Illuminate\Console\Command;

/**
 * Operator surface for Maestro packet-decay observability.
 *   atlas:task:maestro:decay inspect [--json]
 *   atlas:task:maestro:decay propose [--json]
 *   atlas:task:maestro:decay history [--limit=N] [--json]
 *
 * All three modes are read-only and provider-free. The CLI never mutates the queue —
 * `propose` only prints the policy's proposed park list; `history` is append-only audit.
 * Master OFF (config atlas.loop.master_enabled=false) → one-line notice + exit 0 + empty payload.
 *
 * Signature uses colon-only form to avoid the space-as-command-separator collision with the
 * `atlas:task` worker entrypoint (memory: maestro-cost-cmd auto-cure).
 */
final class AtlasTaskMaestroDecayCommand extends Command
{
    public const EXIT_OK = 0;

    public const EXIT_USAGE = 2;

    public const HISTORY_REL = 'atlas/maestro/packet_decay_history.jsonl';

    protected $signature = 'atlas:task:maestro:decay {mode : inspect|propose|history}
        {--limit=20}
        {--json}';

    protected $description = 'Maestro packet-decay CLI (inspect | propose | history) — advisory only.';

    public function handle(): int
    {
        if (! $this->masterEnabled()) {
            $this->emit('master switch OFF — atlas:task:maestro:decay returns empty payload', []);

            return self::EXIT_OK;
        }
        $mode = (string) $this->argument('mode');

        return match ($mode) {
            'inspect' => $this->inspect(),
            'propose' => $this->propose(),
            'history' => $this->history(),
            default => $this->refuse('unknown_mode:'.$mode),
        };
    }

    private function inspect(): int
    {
        $reporter = $this->reporter();
        $rows = $reporter->report();
        $rows = array_map(static fn (array $r): array => [
            'task_packet_id' => (string) ($r['task_packet_id'] ?? ''),
            'age_seconds' => (int) ($r['time_in_queue_seconds'] ?? 0),
            'queue_status' => (string) ($r['queue_status'] ?? ''),
        ], $rows);
        $this->emit('inspect', $rows);

        return self::EXIT_OK;
    }

    private function propose(): int
    {
        $policy = $this->policy();
        $proposals = $policy->propose();
        $this->emit('propose', $proposals);
        $this->appendHistory(['mode' => 'propose', 'proposals' => $proposals]);

        return self::EXIT_OK;
    }

    private function history(): int
    {
        $path = $this->historyPath();
        $limit = max(1, (int) ($this->option('limit') ?? 20));
        $rows = [];
        if (is_file($path)) {
            foreach ((array) file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $decoded = json_decode((string) $line, true);
                if (is_array($decoded)) {
                    $rows[] = $decoded;
                }
            }
        }
        $rows = array_values(array_slice($rows, -$limit));
        $this->emit('history', $rows);

        return self::EXIT_OK;
    }

    private function reporter(): AtlasMaestroPacketAgeFactReporter
    {
        if (app()->bound(AtlasMaestroPacketAgeFactReporter::class)) {
            return app(AtlasMaestroPacketAgeFactReporter::class);
        }
        // Default: empty packet source (no live serving queue introspection from the CLI itself).
        return new AtlasMaestroPacketAgeFactReporter(
            static fn (): array => [],
            null,
            fn (): bool => $this->masterEnabled(),
        );
    }

    private function policy(): AtlasMaestroPacketDecayPolicy
    {
        if (app()->bound(AtlasMaestroPacketDecayPolicy::class)) {
            return app(AtlasMaestroPacketDecayPolicy::class);
        }

        return new AtlasMaestroPacketDecayPolicy(
            $this->reporter(),
            null,
            fn (): bool => $this->masterEnabled(),
        );
    }

    private function masterEnabled(): bool
    {
        if (function_exists('config')) {
            $v = config('atlas.loop.master_enabled');
            if ($v !== null) {
                return (bool) $v;
            }
        }

        return (bool) env('ATLAS_LOOP_MASTER_ENABLED', false);
    }

    private function historyPath(): string
    {
        return function_exists('storage_path') ? storage_path(self::HISTORY_REL) : sys_get_temp_dir().'/'.self::HISTORY_REL;
    }

    /**
     * @param  array<string,mixed>  $entry
     */
    private function appendHistory(array $entry): void
    {
        $path = $this->historyPath();
        $dir = \dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            return;
        }
        $entry['ts'] = gmdate('Y-m-d\TH:i:s\Z');
        ksort($entry, SORT_STRING);
        $line = (string) json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $fh = @fopen($path, 'ab');
        if ($fh === false) {
            return;
        }
        try {
            if (! @flock($fh, LOCK_EX)) {
                return;
            }
            fwrite($fh, $line."\n");
            fflush($fh);
        } finally {
            @flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /**
     * @param  array<int|string,mixed>  $payload
     */
    private function emit(string $mode, array $payload): void
    {
        if ($this->option('json')) {
            $this->getOutput()->writeln((string) json_encode([
                'mode' => $mode,
                'payload' => $payload,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return;
        }
        $this->getOutput()->writeln($mode);
        foreach ($payload as $row) {
            if (is_array($row)) {
                $cells = [];
                foreach ($row as $k => $v) {
                    $cells[] = $k.'='.(is_scalar($v) || $v === null ? (string) $v : json_encode($v, JSON_UNESCAPED_SLASHES));
                }
                $this->getOutput()->writeln(implode(' ', $cells));
            } else {
                $this->getOutput()->writeln((string) $row);
            }
        }
    }

    private function refuse(string $reason): int
    {
        $this->getOutput()->writeln($reason);

        return self::EXIT_USAGE;
    }
}
