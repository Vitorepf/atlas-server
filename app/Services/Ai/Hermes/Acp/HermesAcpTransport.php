<?php

namespace App\Services\Ai\Hermes\Acp;

use RuntimeException;

/**
 * Codec-agnostic I/O transport for a PERSISTENT `hermes acp` process.
 *
 * Drives Hermes via the Agent Client Protocol (newline-delimited JSON-RPC 2.0
 * over stdio) instead of the per-call `hermes chat` subprocess. This is the
 * robust execution path proven by a live spike (initialize ~3.6s cold,
 * session/new ~0.77s, session/prompt ~3.3s) — structured results, no human
 * stdout parsing, no `--checkpoints`/large-workdir hang.
 *
 * This class is DELIBERATELY dumb: it only starts the process and reads/writes
 * raw newline-delimited lines with a deadline. All JSON-RPC framing/semantics
 * live in {@see HermesAcpProtocol} (pure + unit-tested); orchestration +
 * governance live in the runtime + permission gate. Keeping I/O separate from
 * the codec is what makes the protocol logic testable without spawning Hermes.
 */
class HermesAcpTransport implements HermesAcpChannel
{
    /** @var resource|null */
    private $proc = null;

    /** @var array<int,resource> */
    private array $pipes = [];

    private string $buffer = '';

    /**
     * @param  array<string,string>|null  $extraEnv  merged over the inherited env (e.g. HERMES_HOME)
     */
    public function __construct(
        private readonly string $binary = 'hermes',
        private readonly string $cwd = '',
        private readonly ?array $extraEnv = null,
    ) {}

    public function start(): void
    {
        if (is_resource($this->proc)) {
            return;
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = null;
        if ($this->extraEnv !== null) {
            $inherited = getenv();
            $env = array_merge(is_array($inherited) ? $inherited : [], $this->extraEnv);
        }

        $cwd = $this->cwd !== '' ? $this->cwd : null;

        $proc = proc_open([$this->binary, 'acp'], $descriptors, $this->pipes, $cwd, $env);
        if (! is_resource($proc)) {
            throw new RuntimeException('HermesAcpTransport: failed to start `'.$this->binary.' acp`');
        }

        $this->proc = $proc;
        stream_set_blocking($this->pipes[1], false);
        stream_set_blocking($this->pipes[2], false);
    }

    /** Write one JSON-RPC frame (newline-delimited). */
    public function writeLine(string $line): void
    {
        if (! isset($this->pipes[0]) || ! is_resource($this->pipes[0])) {
            throw new RuntimeException('HermesAcpTransport: stdin not open');
        }
        fwrite($this->pipes[0], rtrim($line, "\n")."\n");
        fflush($this->pipes[0]);
    }

    /**
     * Read one newline-delimited stdout line, waiting up to $budget seconds.
     * Returns null on timeout or when the process closes its stdout.
     */
    public function readLine(float $budget): ?string
    {
        $deadline = microtime(true) + max(0.0, $budget);

        while (true) {
            $nl = strpos($this->buffer, "\n");
            if ($nl !== false) {
                $line = substr($this->buffer, 0, $nl);
                $this->buffer = substr($this->buffer, $nl + 1);

                return $line;
            }

            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                return null;
            }
            if (! isset($this->pipes[1]) || ! is_resource($this->pipes[1])) {
                return null;
            }

            $read = [$this->pipes[1]];
            $write = $except = [];
            $sec = (int) floor($remaining);
            $usec = (int) round(($remaining - $sec) * 1_000_000);

            $n = @stream_select($read, $write, $except, $sec, $usec);
            if ($n === false) {
                return null;
            }
            if ($n > 0) {
                $chunk = fread($this->pipes[1], 65536);
                if ($chunk === false || ($chunk === '' && feof($this->pipes[1]))) {
                    return null;
                }
                $this->buffer .= $chunk;
            }
        }
    }

    /** Drain whatever Hermes wrote to stderr (banner/logs) without blocking. */
    public function drainStderr(): string
    {
        if (! isset($this->pipes[2]) || ! is_resource($this->pipes[2])) {
            return '';
        }
        $out = '';
        while (($chunk = fread($this->pipes[2], 65536)) !== false && $chunk !== '') {
            $out .= $chunk;
        }

        return $out;
    }

    public function isRunning(): bool
    {
        if (! is_resource($this->proc)) {
            return false;
        }
        $status = proc_get_status($this->proc);

        return (bool) ($status['running'] ?? false);
    }

    public function stop(): void
    {
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
            }
        }
        if (is_resource($this->proc)) {
            @proc_terminate($this->proc);
            @proc_close($this->proc);
        }
        $this->proc = null;
        $this->pipes = [];
        $this->buffer = '';
    }
}
