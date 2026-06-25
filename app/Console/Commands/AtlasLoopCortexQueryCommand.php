<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\QueryLanguage\AtlasCortexQueryLanguageExecutor;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\QueryLanguage\AtlasCortexQueryLanguageParser;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator/Loop entrypoint for the Cortex query language. THIN: parse delegates to the parser,
 * execute delegates to the executor over a named snapshot, history tails the append-only JSONL
 * audit log. NO NLP, NO free-text inference — only the DSL.
 */
final class AtlasLoopCortexQueryCommand extends Command
{
    public const SNAPSHOT_SOURCE_BINDING = 'atlas.loop.cortex.query.snapshot_source';

    public const HISTORY_PATH_BINDING = 'atlas.loop.cortex.query.history_path';

    private const VALID_ACTIONS = ['parse', 'execute', 'history'];

    protected $signature = 'atlas:loop:cortex:query {action : parse|execute|history} {--dsl=} {--snapshot=} {--limit=20}';

    protected $description = 'Operator front-door for the Cortex query language (parse | execute | history).';

    public function __construct(
        private readonly AtlasCortexQueryLanguageParser $parser,
        private readonly AtlasCortexQueryLanguageExecutor $executor,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        if (! in_array($action, self::VALID_ACTIONS, true)) {
            return $this->stderr([
                'error' => 'unknown_action',
                'action' => $action,
                'valid_actions' => self::VALID_ACTIONS,
            ], 1);
        }

        return match ($action) {
            'parse' => $this->doParse(),
            'execute' => $this->doExecute(),
            'history' => $this->doHistory(),
        };
    }

    private function doParse(): int
    {
        $dsl = (string) $this->option('dsl');
        if ($dsl === '') {
            return $this->stderr(['error' => 'dsl_required'], 1);
        }
        try {
            $ast = $this->parser->parse($dsl);
        } catch (Throwable $e) {
            return $this->stderr(['error' => 'parse_error', 'message' => $e->getMessage(), 'offending_token' => $this->offendingToken($e)], 1);
        }

        $this->appendHistory(['action' => 'parse', 'dsl' => $dsl, 'ts' => gmdate('Y-m-d\TH:i:s\Z')]);

        return $this->emit($ast, 0);
    }

    private function doExecute(): int
    {
        $dsl = (string) $this->option('dsl');
        if ($dsl === '') {
            return $this->stderr(['error' => 'dsl_required'], 1);
        }
        $snapshotName = (string) $this->option('snapshot');
        $snapshot = $this->loadSnapshot($snapshotName);

        try {
            $ast = $this->parser->parse($dsl);
        } catch (Throwable $e) {
            return $this->stderr(['error' => 'parse_error', 'message' => $e->getMessage(), 'offending_token' => $this->offendingToken($e)], 1);
        }

        try {
            $result = $this->executor->execute($ast, $snapshot, $snapshotName !== '' ? $snapshotName : 'default');
        } catch (Throwable $e) {
            return $this->stderr(['error' => 'execute_error', 'message' => $e->getMessage()], 1);
        }

        $this->appendHistory(['action' => 'execute', 'dsl' => $dsl, 'snapshot' => $snapshotName, 'ts' => gmdate('Y-m-d\TH:i:s\Z')]);

        return $this->emit($result, 0);
    }

    private function doHistory(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $path = $this->historyPath();
        if (! is_file($path)) {
            return $this->emit(['action' => 'history', 'rows' => []], 0);
        }
        $rows = [];
        foreach ((array) file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $decoded = json_decode((string) $line, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }
        $rows = array_slice(array_reverse($rows), 0, $limit);

        return $this->emit(['action' => 'history', 'rows' => $rows], 0);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadSnapshot(string $name): array
    {
        if (! $this->getLaravel()->bound(self::SNAPSHOT_SOURCE_BINDING)) {
            return [];
        }
        $source = $this->getLaravel()->make(self::SNAPSHOT_SOURCE_BINDING);
        if (! is_callable($source)) {
            return [];
        }
        $rows = $source($name);
        $out = [];
        foreach ((array) $rows as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    private function historyPath(): string
    {
        if ($this->getLaravel()->bound(self::HISTORY_PATH_BINDING)) {
            return (string) $this->getLaravel()->make(self::HISTORY_PATH_BINDING);
        }

        return storage_path('atlas/cortex/query-history.jsonl');
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function appendHistory(array $row): void
    {
        $path = $this->historyPath();
        $dir = \dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            return;
        }
        @file_put_contents($path, (string) json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private function offendingToken(Throwable $e): string
    {
        $msg = $e->getMessage();
        if (preg_match('/token[^A-Za-z0-9_]+([A-Za-z0-9_*=<>!"\'.]+)/i', $msg, $m)) {
            return (string) $m[1];
        }

        return '';
    }

    /**
     * @param  array<string|int,mixed>  $payload
     */
    private function emit(array $payload, int $exit): int
    {
        $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $exit;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function stderr(array $payload, int $exit): int
    {
        $output = $this->output;
        $msg = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($output instanceof \Symfony\Component\Console\Output\ConsoleOutputInterface) {
            $output->getErrorOutput()->writeln($msg);
        }
        $this->line($msg);

        return $exit;
    }
}
