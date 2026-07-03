<?php

namespace App\Services\Ai\Rivals\Benchmarks;

use App\Services\Ai\Rivals\Support\RunPaths;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Clona/instala/valida os benchmark repos EXTERNOS reais (registry em
 * config atlas_rivals.benchmarks). Honestidade primeiro: status running só
 * existe com receipt de smoke real (exit 0) gravado; qualquer erro vira
 * blocked com o erro exato. Nenhum comando aqui gasta provider.
 * Isolamento: tudo roda dentro do clone com .atlas-venv/bin no PATH —
 * nunca toca vendor/autoload do repo vivo (lição do incidente wiper).
 */
class BenchmarkRepoManager
{
    public function root(): string
    {
        return rtrim((string) config('atlas_rivals.benchmarks.root'), '/');
    }

    /** @return array<string, array> */
    public function registry(): array
    {
        return (array) config('atlas_rivals.benchmarks.repos', []);
    }

    /** Status honesto de todos os repos do registry (deriva do último receipt). */
    public function status(): array
    {
        $repos = [];
        $running = 0;
        foreach ($this->registry() as $id => $spec) {
            $entry = $this->repoStatus($id, $spec);
            $running += $entry['status'] === 'running' ? 1 : 0;
            $repos[] = $entry;
        }

        return [
            'schema_version' => 'atlas.rivals2.benchmarks.v1',
            'status' => 'ok',
            'root' => $this->root(),
            'total' => count($repos),
            'running' => $running,
            'blocked' => count($repos) - $running,
            'repos' => $repos,
        ];
    }

    /**
     * Clona (se preciso), instala (se preciso) e roda o smoke REAL do repo.
     * Grava receipt + log em <storage>/benchmarks/<id>/. Erro = blocked, nunca done.
     */
    public function smoke(string $id): array
    {
        $spec = $this->registry()[$id] ?? throw new RuntimeException("unknown_benchmark_repo:{$id}");
        $dir = $this->root().'/'.$id;
        $startedAt = date('c');
        $t0 = microtime(true);
        $steps = [];

        if (! is_dir($dir.'/.git')) {
            RunPaths::ensureDir($this->root());
            $clone = $this->exec(['git', 'clone', '--depth', '1', $spec['url'], $dir], $this->root(), 600);
            $steps[] = ['step' => 'clone', 'command' => 'git clone --depth 1 '.$spec['url'], 'exit_code' => $clone['exit_code']];
            if ($clone['exit_code'] !== 0) {
                return $this->receipt($id, $spec, 'blocked', $steps, 'clone_failed: '.$clone['tail'], $startedAt, $t0);
            }
        }
        $commit = trim($this->exec(['git', 'rev-parse', 'HEAD'], $dir, 30)['stdout']);

        // install idempotente: marker só é escrito depois de TODOS os passos saírem 0
        $marker = $dir.'/.atlas-venv/.atlas-install-ok';
        if (! is_file($marker)) {
            $timeout = (int) config('atlas_rivals.benchmarks.install_timeout_seconds', 900);
            foreach ((array) $spec['install'] as $cmd) {
                $run = $this->shell($cmd, $dir, $timeout);
                $steps[] = ['step' => 'install', 'command' => $cmd, 'exit_code' => $run['exit_code']];
                if ($run['exit_code'] !== 0) {
                    return $this->receipt($id, $spec, 'blocked', $steps, "install_failed: {$cmd} :: ".$run['tail'], $startedAt, $t0, $commit);
                }
            }
            if (is_dir(dirname($marker))) {
                file_put_contents($marker, date('c'));
            }
        }

        $smoke = $this->shell($spec['smoke'], $dir, (int) config('atlas_rivals.benchmarks.smoke_timeout_seconds', 300));
        $steps[] = ['step' => 'smoke', 'command' => $spec['smoke'], 'exit_code' => $smoke['exit_code']];
        $status = $smoke['exit_code'] === 0 ? 'running' : 'blocked';
        $error = $status === 'blocked' ? 'smoke_failed: '.$smoke['tail'] : null;

        return $this->receipt($id, $spec, $status, $steps, $error, $startedAt, $t0, $commit, $smoke);
    }

    private function repoStatus(string $id, array $spec): array
    {
        $dir = $this->root().'/'.$id;
        $cloned = is_dir($dir.'/.git');
        $latest = $this->latestReceipt($id);

        return [
            'repo_id' => $id,
            'url' => $spec['url'],
            'adapter' => $spec['adapter'],
            'cloned' => $cloned,
            'commit' => $cloned ? trim($this->exec(['git', 'rev-parse', 'HEAD'], $dir, 30)['stdout']) : null,
            'installed' => is_file($dir.'/.atlas-venv/.atlas-install-ok'),
            'install_command' => implode(' && ', (array) $spec['install']),
            'smoke_command' => $spec['smoke'],
            // sem receipt de smoke = blocked honesto, nunca "pronto por presunção"
            'status' => $latest['status'] ?? 'blocked',
            'error' => $latest === null ? 'smoke_never_ran' : ($latest['error'] ?? null),
            'last_smoke_at' => $latest['finished_at'] ?? null,
            'receipt' => $latest['receipt_path'] ?? null,
        ];
    }

    private function receipt(string $id, array $spec, string $status, array $steps, ?string $error, string $startedAt, float $t0, ?string $commit = null, ?array $smoke = null): array
    {
        $dir = RunPaths::root().'/benchmarks/'.$id;
        RunPaths::ensureDir($dir);
        $ts = date('Ymd_His');
        $logPath = $dir."/smoke-{$ts}.log";
        file_put_contents($logPath, ($smoke['stdout'] ?? '').($smoke['stderr'] ?? ''));

        $payload = [
            'schema_version' => 'atlas.rivals2.benchmark_smoke.v1',
            'repo_id' => $id,
            'url' => $spec['url'],
            'adapter' => $spec['adapter'],
            'commit' => $commit,
            'status' => $status,
            'error' => $error,
            'steps' => $steps,
            'stdout_tail' => mb_substr($smoke['stdout'] ?? '', -2000),
            'stderr_tail' => mb_substr($smoke['stderr'] ?? '', -2000),
            'log' => ['path' => $logPath, 'sha256' => hash_file('sha256', $logPath)],
            'duration_ms' => (int) round((microtime(true) - $t0) * 1000),
            'started_at' => $startedAt,
            'finished_at' => date('c'),
        ];
        $receiptPath = $dir."/receipt-{$ts}.json";
        file_put_contents($receiptPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($dir.'/latest.json', json_encode($payload + ['receipt_path' => $receiptPath], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $payload + ['receipt_path' => $receiptPath];
    }

    private function latestReceipt(string $id): ?array
    {
        $path = RunPaths::root().'/benchmarks/'.$id.'/latest.json';
        if (! is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }

    /** @param list<string> $cmd */
    private function exec(array $cmd, string $cwd, int $timeout): array
    {
        $p = new Process($cmd, $cwd, null, null, (float) $timeout);
        $p->run();

        return ['exit_code' => (int) $p->getExitCode(), 'stdout' => $p->getOutput(), 'stderr' => $p->getErrorOutput(), 'tail' => mb_substr(trim($p->getErrorOutput()."\n".$p->getOutput()), -400)];
    }

    private function shell(string $cmd, string $cwd, int $timeout): array
    {
        $env = ['VIRTUAL_ENV' => $cwd.'/.atlas-venv', 'PATH' => $cwd.'/.atlas-venv/bin:'.getenv('PATH')];
        $p = new Process(['/bin/bash', '-c', $cmd], $cwd, $env, null, (float) $timeout);
        try {
            $p->run();
        } catch (\Symfony\Component\Process\Exception\ProcessTimedOutException) {
            return ['exit_code' => 124, 'stdout' => $p->getOutput(), 'stderr' => $p->getErrorOutput()."\ntimeout_after_{$timeout}s", 'tail' => "timeout_after_{$timeout}s"];
        }

        return ['exit_code' => (int) $p->getExitCode(), 'stdout' => $p->getOutput(), 'stderr' => $p->getErrorOutput(), 'tail' => mb_substr(trim($p->getErrorOutput()."\n".$p->getOutput()), -400)];
    }
}
