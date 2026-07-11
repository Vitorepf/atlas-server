<?php

declare(strict_types=1);

namespace App\Services\Ai\Memory\Substrate;

use Illuminate\Support\Facades\DB;

final class PgDumpAtlasMemorySubstrateDumpRunner implements AtlasMemorySubstrateDumpRunner
{
    public function dump(array $tableNames, string $dumpPath): array
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return [
                'ok' => false,
                'dump_path' => $dumpPath,
                'stderr' => '',
                'reason' => 'not_pgsql',
            ];
        }

        $binary = trim((string) @shell_exec('command -v pg_dump 2>/dev/null'));
        if ($binary === '') {
            return [
                'ok' => false,
                'dump_path' => $dumpPath,
                'stderr' => '',
                'reason' => 'pg_dump_unavailable',
            ];
        }

        $config = DB::connection()->getConfig();
        $database = (string) ($config['database'] ?? '');
        if ($database === '') {
            return [
                'ok' => false,
                'dump_path' => $dumpPath,
                'stderr' => '',
                'reason' => 'missing_database_name',
            ];
        }

        $dir = \dirname($dumpPath);
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return [
                'ok' => false,
                'dump_path' => $dumpPath,
                'stderr' => '',
                'reason' => 'dump_directory_unwritable',
            ];
        }

        $command = [
            $binary,
            '--data-only',
            '--format=plain',
            '--no-owner',
            '--no-privileges',
            '--dbname='.$database,
            '--file='.$dumpPath,
        ];

        foreach ($tableNames as $table) {
            $command[] = '--table=public.'.(string) $table;
        }

        $env = $this->connectionEnvironment($config);
        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            null,
            $env,
        );

        if (! \is_resource($process)) {
            return [
                'ok' => false,
                'dump_path' => $dumpPath,
                'stderr' => '',
                'reason' => 'proc_open_failed',
            ];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0 || ! is_file($dumpPath)) {
            return [
                'ok' => false,
                'dump_path' => $dumpPath,
                'stderr' => trim($stderr !== '' ? $stderr : $stdout),
                'reason' => 'pg_dump_failed',
            ];
        }

        return [
            'ok' => true,
            'dump_path' => $dumpPath,
            'stderr' => trim($stderr),
        ];
    }

    /**
     * @param  array<string,mixed>  $config
     * @return array<string,string>
     */
    private function connectionEnvironment(array $config): array
    {
        $env = [];
        foreach ($_ENV as $key => $value) {
            if (\is_string($key) && \is_string($value)) {
                $env[$key] = $value;
            }
        }

        if (($config['host'] ?? null) !== null) {
            $env['PGHOST'] = (string) $config['host'];
        }
        if (($config['port'] ?? null) !== null) {
            $env['PGPORT'] = (string) $config['port'];
        }
        if (($config['username'] ?? null) !== null) {
            $env['PGUSER'] = (string) $config['username'];
        }
        if (($config['password'] ?? null) !== null) {
            $env['PGPASSWORD'] = (string) $config['password'];
        }

        return $env;
    }
}
