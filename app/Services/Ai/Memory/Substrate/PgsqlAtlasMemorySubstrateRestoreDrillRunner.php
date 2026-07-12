<?php

declare(strict_types=1);

namespace App\Services\Ai\Memory\Substrate;

final class PgsqlAtlasMemorySubstrateRestoreDrillRunner implements AtlasMemorySubstrateRestoreDrillRunner
{
    public function restore(string $dumpPath, array $tableNames, array $targetConnection): array
    {
        if (! is_file($dumpPath) || ! is_readable($dumpPath)) {
            return $this->failure($targetConnection, 'dump_missing');
        }

        $binary = trim((string) @shell_exec('command -v psql 2>/dev/null'));
        if ($binary === '') {
            return $this->failure($targetConnection, 'psql_unavailable');
        }

        $database = trim((string) ($targetConnection['database'] ?? ''));
        if ($database === '') {
            return $this->failure($targetConnection, 'target_database_missing');
        }

        $truncate = $this->runProcess([
            $binary,
            '--set=ON_ERROR_STOP=1',
            '--dbname='.$database,
            '--command='.$this->truncateSql($tableNames),
        ], $targetConnection);

        if (! $truncate['ok']) {
            return $this->failure($targetConnection, 'target_truncate_failed', (string) $truncate['stderr']);
        }

        $restore = $this->runProcess([
            $binary,
            '--set=ON_ERROR_STOP=1',
            '--dbname='.$database,
            '--file='.$dumpPath,
        ], $targetConnection);

        if (! $restore['ok']) {
            return $this->failure($targetConnection, 'psql_restore_failed', (string) $restore['stderr']);
        }

        $counts = [];
        foreach ($tableNames as $table) {
            $query = $this->runProcess([
                $binary,
                '--set=ON_ERROR_STOP=1',
                '--tuples-only',
                '--no-align',
                '--dbname='.$database,
                '--command=SELECT COUNT(*) FROM public.'.$this->quoteIdent($table),
            ], $targetConnection);

            if (! $query['ok']) {
                return $this->failure($targetConnection, 'count_query_failed:'.$table, (string) $query['stderr']);
            }

            $counts[$table] = (int) trim((string) $query['stdout']);
        }

        return [
            'ok' => true,
            'restored_counts' => $counts,
            'target' => $this->safeTarget($targetConnection),
        ];
    }

    /**
     * @param  list<string>  $command
     * @param  array<string,mixed>  $targetConnection
     * @return array{ok:bool,stdout:string,stderr:string,exit_code:int}
     */
    private function runProcess(array $command, array $targetConnection): array
    {
        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            null,
            $this->connectionEnvironment($targetConnection),
        );

        if (! \is_resource($process)) {
            return ['ok' => false, 'stdout' => '', 'stderr' => 'proc_open_failed', 'exit_code' => 1];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [
            'ok' => $exitCode === 0,
            'stdout' => trim($stdout),
            'stderr' => trim($stderr),
            'exit_code' => $exitCode,
        ];
    }

    /** @param list<string> $tableNames */
    private function truncateSql(array $tableNames): string
    {
        if ($tableNames === []) {
            return 'SELECT 1;';
        }

        return 'TRUNCATE TABLE '.implode(', ', array_map(
            fn (string $table): string => 'public.'.$this->quoteIdent($table),
            $tableNames,
        )).' RESTART IDENTITY CASCADE;';
    }

    /**
     * @param  array<string,mixed>  $targetConnection
     * @return array<string,string>
     */
    private function connectionEnvironment(array $targetConnection): array
    {
        $env = [];
        foreach ($_ENV as $key => $value) {
            if (\is_string($key) && \is_string($value)) {
                $env[$key] = $value;
            }
        }

        if (($targetConnection['host'] ?? null) !== null) {
            $env['PGHOST'] = (string) $targetConnection['host'];
        }
        if (($targetConnection['port'] ?? null) !== null) {
            $env['PGPORT'] = (string) $targetConnection['port'];
        }
        if (($targetConnection['username'] ?? null) !== null) {
            $env['PGUSER'] = (string) $targetConnection['username'];
        }
        if (($targetConnection['password'] ?? null) !== null) {
            $env['PGPASSWORD'] = (string) $targetConnection['password'];
        }

        return $env;
    }

    private function quoteIdent(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    /**
     * @param  array<string,mixed>  $targetConnection
     * @return array<string,mixed>
     */
    private function failure(array $targetConnection, string $reason, string $stderr = ''): array
    {
        return [
            'ok' => false,
            'restored_counts' => [],
            'target' => $this->safeTarget($targetConnection),
            'reason' => $reason,
            'stderr' => $stderr,
        ];
    }

    /**
     * @param  array<string,mixed>  $targetConnection
     * @return array<string,mixed>
     */
    private function safeTarget(array $targetConnection): array
    {
        return [
            'host' => (string) ($targetConnection['host'] ?? ''),
            'port' => (int) ($targetConnection['port'] ?? 0),
            'database' => (string) ($targetConnection['database'] ?? ''),
            'username' => (string) ($targetConnection['username'] ?? ''),
        ];
    }
}
