<?php

namespace App\Console\Commands;

use App\Support\AtlasPhpBinary;
use App\Support\AtlasSecurity;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasCliRollbackCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:cli:rollback
        {--to= : Version tag or commit sha}
        {--steps=1 : Number of commits back when --to is omitted}
        {--dry-run}
        {--confirm-data-loss}
        {--json}';

    protected $description = 'Rollback local Atlas CLI checkout with dry-run and migration safety.';

    public function handle(): int
    {
        $target = $this->target();
        if (! $target) {
            return $this->finish(false, 'target_not_found', ['message' => 'Nao foi possivel resolver target de rollback.']);
        }

        $head = trim($this->runProcess(['git', 'rev-parse', 'HEAD'])['stdout']);
        $migrationDiff = $this->runProcess(['git', 'diff', '--name-only', "{$target}..{$head}", '--', 'database/migrations'])['stdout'];
        $plan = [
            'head' => $head,
            'target' => $target,
            'migration_files_since_target' => array_values(array_filter(explode("\n", trim($migrationDiff)))),
            'requires_data_loss_confirmation' => trim($migrationDiff) !== '',
        ];

        if ((bool) $this->option('dry-run')) {
            return $this->finish(true, 'dry_run', $plan);
        }

        if ($plan['requires_data_loss_confirmation'] && ! (bool) $this->option('confirm-data-loss')) {
            return $this->finish(false, 'migration_risk_blocked', $plan + [
                'message' => 'Rollback envolve migrations. Rode --dry-run, revise e use --confirm-data-loss apenas com backup.',
            ]);
        }

        $backup = 'atlas-rollback-backup-'.now()->format('YmdHis');
        $this->runProcess(['git', 'tag', $backup]);
        $checkout = $this->runProcess(['git', 'checkout', $target]);
        if ($checkout['exit_code'] !== 0) {
            return $this->finish(false, 'checkout_failed', ['backup_ref' => $backup, 'stderr' => $checkout['stderr']]);
        }

        $composer = $this->runProcess(['composer', 'install']);
        if ($composer['exit_code'] !== 0) {
            return $this->finish(false, 'composer_failed', ['backup_ref' => $backup, 'stderr' => $composer['stderr']]);
        }

        $doctor = $this->runProcess([AtlasPhpBinary::path(), 'artisan', 'atlas:cli:doctor', '--strict']);

        return $this->finish($doctor['exit_code'] === 0, 'rolled_back', [
            'backup_ref' => $backup,
            'doctor' => $doctor,
        ]);
    }

    private function target(): ?string
    {
        $to = $this->option('to');
        if (is_string($to) && $to !== '') {
            $resolved = trim($this->runProcess(['git', 'rev-parse', '--verify', $to])['stdout']);

            return $resolved !== '' ? $resolved : null;
        }

        $steps = max(1, min(50, (int) $this->option('steps')));
        $target = trim($this->runProcess(['git', 'rev-parse', 'HEAD~'.$steps])['stdout']);

        return $target !== '' ? $target : null;
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
        $this->line($this->encode($payload));

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
