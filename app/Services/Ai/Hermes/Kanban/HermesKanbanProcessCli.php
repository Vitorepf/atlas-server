<?php

namespace App\Services\Ai\Hermes\Kanban;

use Symfony\Component\Process\Process;
use Throwable;

/**
 * Real {@see HermesKanbanCli}: runs `hermes kanban …` as a one-shot subprocess
 * (never a daemon) and decodes its `--json` output. The board slug is placed at
 * the kanban level (`hermes kanban --board <slug> <sub>`), and an optional
 * HERMES_HOME pins the board's SQLite root to an Atlas-owned location.
 */
class HermesKanbanProcessCli implements HermesKanbanCli
{
    public function invoke(array $args, array $options = []): HermesKanbanResult
    {
        $binary = (string) config('atlas.ai.providers.hermes_cli.binary', 'hermes');

        $command = [$binary, 'kanban'];
        if (isset($options['board']) && is_string($options['board']) && trim($options['board']) !== '') {
            $command[] = '--board';
            $command[] = $options['board'];
        }
        foreach ($args as $arg) {
            $command[] = (string) $arg;
        }
        if (($options['json'] ?? true) === true) {
            $command[] = '--json';
        }

        $env = null;
        if (isset($options['hermes_home']) && is_string($options['hermes_home']) && trim($options['hermes_home']) !== '') {
            $inherited = getenv();
            $env = array_merge(is_array($inherited) ? $inherited : [], ['HERMES_HOME' => $options['hermes_home']]);
        }

        $timeout = (int) ($options['timeout'] ?? 120);

        try {
            $process = new Process($command, base_path(), $env, null, max(1, $timeout));
            $process->run();
        } catch (Throwable $e) {
            return new HermesKanbanResult(false, null, '', $e->getMessage(), []);
        }

        $stdout = (string) $process->getOutput();

        return new HermesKanbanResult(
            ok: $process->isSuccessful(),
            exitCode: $process->getExitCode(),
            stdout: $stdout,
            stderr: (string) $process->getErrorOutput(),
            json: $this->decodeJson($stdout),
        );
    }

    /**
     * Decode the CLI's `--json`: a single JSON document, or — for subcommands that
     * emit one JSON object per line (e.g. `decompose --json`) — a list of objects.
     *
     * @return array<string,mixed>|array<int,mixed>
     */
    private function decodeJson(string $stdout): array
    {
        $trimmed = trim($stdout);
        if ($trimmed === '') {
            return [];
        }

        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $objects = [];
        foreach (preg_split('/\r?\n/', $trimmed) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $obj = json_decode($line, true);
            if (is_array($obj)) {
                $objects[] = $obj;
            }
        }

        return $objects;
    }
}
