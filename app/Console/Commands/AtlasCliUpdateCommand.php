<?php

namespace App\Console\Commands;

use App\Console\Concerns\EmitsCanonicalJson;
use App\Support\AtlasPhpBinary;
use App\Support\AtlasSecurity;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class AtlasCliUpdateCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:cli:update
        {--channel=stable : only `stable` is implemented; any other value refuses}
        {--allow-dirty}
        {--dry-run}
        {--strict}
        {--json}';

    protected $description = 'Update local Atlas CLI checkout safely.';

    public function handle(): int
    {
        // --channel was declared and never read: `--channel=beta` silently pulled
        // the current branch, so the operator believed they were on a channel that
        // does not exist. Only `stable` is implemented; anything else refuses
        // instead of quietly doing something different from what was asked.
        $channel = (string) ($this->option('channel') ?? 'stable');
        if ($channel !== 'stable') {
            return $this->finish(false, 'unsupported_channel', [
                'message' => "Canal [{$channel}] nao implementado: atlas:cli:update so atualiza o branch atual (stable).",
                'channel' => $channel,
            ]);
        }

        $dirty = $this->runProcess(['git', 'status', '--short']);
        if ($dirty['stdout'] !== '' && ! (bool) $this->option('allow-dirty') && ! (bool) $this->option('dry-run')) {
            return $this->finish(false, 'worktree_dirty', [
                'message' => 'Worktree suja. Use --allow-dirty apenas se souber que essas mudancas podem conviver com update.',
                'dirty' => $dirty['stdout'],
            ]);
        }

        $fetch = $this->runProcess(['git', 'fetch', 'origin']);
        $branch = trim($this->runProcess(['git', 'rev-parse', '--abbrev-ref', 'HEAD'])['stdout']);
        $head = trim($this->runProcess(['git', 'rev-parse', 'HEAD'])['stdout']);
        $remote = trim($this->runProcess(['git', 'rev-parse', "origin/{$branch}"])['stdout']);
        $changes = $this->runProcess(['git', 'log', '--oneline', "{$head}..origin/{$branch}"])['stdout'];
        $migrations = $this->runProcess(['git', 'diff', '--name-only', "{$head}..origin/{$branch}", '--', 'database/migrations'])['stdout'];

        $plan = [
            'branch' => $branch,
            'head' => $head,
            'remote' => $remote,
            'has_update' => $head !== $remote,
            'changes' => array_values(array_filter(explode("\n", trim($changes)))),
            'migration_files' => array_values(array_filter(explode("\n", trim($migrations)))),
            'fetch_ok' => $fetch['exit_code'] === 0,
            'dirty' => $dirty['stdout'],
        ];

        if ((bool) $this->option('dry-run') || $head === $remote) {
            return $this->finish(true, 'dry_run', $plan);
        }

        $backup = $this->backupRef();
        $pull = $this->runProcess(['git', 'pull', '--ff-only']);
        if ($pull['exit_code'] !== 0) {
            return $this->finish(false, 'pull_failed', ['backup_ref' => $backup, 'stderr' => $pull['stderr']]);
        }

        $composer = $this->runProcess(['composer', 'install']);
        if ($composer['exit_code'] !== 0) {
            return $this->finish(false, 'composer_failed', ['backup_ref' => $backup, 'stderr' => $composer['stderr']]);
        }

        $migrate = $this->runProcess([AtlasPhpBinary::path(), 'artisan', 'migrate', '--force']);
        if ($migrate['exit_code'] !== 0) {
            return $this->finish(false, 'migrate_failed', ['backup_ref' => $backup, 'stderr' => $migrate['stderr']]);
        }

        $doctor = (bool) $this->option('strict') ? $this->runProcess([AtlasPhpBinary::path(), 'artisan', 'atlas:cli:doctor', '--strict']) : ['exit_code' => 0, 'stdout' => '', 'stderr' => ''];

        return $this->finish($doctor['exit_code'] === 0, 'updated', [
            'backup_ref' => $backup,
            'doctor' => $doctor,
        ]);
    }

    private function backupRef(): string
    {
        $ref = 'atlas-update-backup-'.now()->format('YmdHis');
        $this->runProcess(['git', 'tag', $ref]);

        return $ref;
    }

    /**
     * @param  array<int,string>  $command
     * @return array{exit_code:int,stdout:string,stderr:string}
     */
    private function runProcess(array $command): array
    {
        $process = new Process($command, base_path(), AtlasSecurity::processEnv(profile: 'internal'));
        $process->setTimeout(600);
        $process->run();

        return [
            'exit_code' => $process->getExitCode() ?? self::FAILURE,
            'stdout' => trim(AtlasSecurity::redactString($process->getOutput())),
            'stderr' => trim(AtlasSecurity::redactString($process->getErrorOutput())),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function finish(bool $ok, string $status, array $payload): int
    {
        $payload = AtlasSecurity::redactArray(['ok' => $ok, 'status' => $status] + $payload);
        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));
        } else {
            $this->line($this->encode($payload));
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
