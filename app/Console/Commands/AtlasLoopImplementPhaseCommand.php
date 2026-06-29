<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\LiveCycle\AtlasLoopImplementPhaseRunner;
use Closure;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopImplementPhaseRunner::run()} at the operator surface using a DETERMINISTIC
 * in-memory implementer: the stub delegate echoes the packet's allowed_files as files_touched with NO commit
 * (no real edit), so the runner emits an implement receipt referencing the packet without mutating anything.
 *
 * Pure read-only: the implementer is a closure that edits no file and never commits; the runner reports only.
 */
final class AtlasLoopImplementPhaseCommand extends Command
{
    protected $signature = 'atlas:loop:implement-phase {--packet=} {--json}';

    protected $description = 'Read-only implement-phase run over a packet (deterministic stub implementer; no real edit).';

    public function handle(): int
    {
        $raw = trim((string) $this->option('packet'));
        if ($raw === '') {
            return $this->refuse('implement-phase requires --packet=<json object or path>');
        }
        if (is_file($raw) && is_readable($raw)) {
            $raw = (string) file_get_contents($raw);
        }
        $packet = json_decode($raw, true);
        if (! is_array($packet)) {
            return $this->refuse('--packet must be a JSON object');
        }

        // Deterministic in-memory implementer: report the packet's allowed_files as touched, but NO commit_sha
        // (no real edit). The runner never compensates by writing files itself (anti-Goodhart).
        $implementer = static fn (array $taskPacket): array => [
            'files_touched' => array_values((array) ($taskPacket['allowed_files'] ?? [])),
            'reason' => 'stub_implement_no_real_edit',
        ];

        $receipt = (new AtlasLoopImplementPhaseRunner(Closure::fromCallable($implementer)))->run($packet);

        $facts = ['schema' => 'atlas.loop.implement_phase.v1'] + $receipt;

        if ($this->option('json')) {
            $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('status: '.$facts['status'].'  files_touched: '.count($facts['files_touched']).'  commit_sha: '.($facts['commit_sha'] ?? '(none)'));
        }

        return self::SUCCESS;
    }

    private function refuse(string $message): int
    {
        $this->line((string) json_encode([
            'outcome' => 'refused',
            'reason' => 'usage_error',
            'message' => $message,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::FAILURE;
    }
}
