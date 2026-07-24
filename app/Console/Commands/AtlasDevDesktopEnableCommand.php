<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Programming\AtlasDev\Runtime\AtlasDevDesktopCertificationService;
use App\Services\Ai\Programming\AtlasDev\Runtime\AtlasDevReadinessService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use App\Console\Concerns\EmitsCanonicalJson;

final class AtlasDevDesktopEnableCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:dev:desktop:enable
        {--env-path= : Custom .env path for tests or controlled setup}
        {--dry-run : Show the env updates without writing}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Enable the local Atlas Dev Desktop runtime flags and report readiness.';

    public function handle(AtlasDevReadinessService $readiness, AtlasDevDesktopCertificationService $certification): int
    {
        $envPath = $this->envPath();
        $updates = [
            'ATLAS_DEV_EFFICIENT_ENABLED' => 'true',
            'ATLAS_DEV_EFFICIENT_PLAN_ENABLED' => 'true',
            'ATLAS_DEV_EFFICIENT_RUN_ENABLED' => 'true',
            'ATLAS_DEV_EFFICIENT_DESKTOP_ENABLED' => 'true',
            'ATLAS_DEV_RUN_DISPATCH_MODE' => 'process',
        ];

        $write = (bool) $this->option('dry-run')
            ? [
                'env_path' => $envPath,
                'backup_path' => null,
                'dry_run' => true,
                'updates' => $updates,
            ]
            : $this->writeEnv($envPath, $updates);

        foreach ($updates as $key => $value) {
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
        config()->set('atlas_dev.efficient.enabled', true);
        config()->set('atlas_dev.efficient.plan_enabled', true);
        config()->set('atlas_dev.efficient.run_enabled', true);
        config()->set('atlas_dev.efficient.desktop_enabled', true);
        config()->set('atlas_dev.efficient.run_dispatch_mode', 'process');

        $payload = [
            'ok' => true,
            'schema_version' => 'atlas.dev.desktop_enable.v1',
            'write_env' => $write,
            'readiness' => $readiness->inspect(strict: true, providerSafe: true),
            'certification' => $certification->certify(),
            'next_steps' => [
                'restart_backend_runtime_if_config_is_cached',
                'open_desktop_atlas_ai_surface',
                'run_plan_before_run_and_confirm_operator_prompt',
            ],
        ];

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return $payload['readiness']['status'] === 'passed' && $payload['certification']['status'] === 'passed'
                ? self::SUCCESS
                : self::FAILURE;
        }

        $this->info('Atlas Dev Desktop flags enabled.');
        $this->line('Env: '.$envPath);
        if (is_string($write['backup_path'] ?? null)) {
            $this->line('Backup: '.$write['backup_path']);
        }
        $this->line('Readiness: '.$payload['readiness']['status']);
        $this->line('Certification: '.$payload['certification']['status']);

        return $payload['readiness']['status'] === 'passed' && $payload['certification']['status'] === 'passed'
            ? self::SUCCESS
            : self::FAILURE;
    }

    private function envPath(): string
    {
        $option = $this->option('env-path');
        if (is_string($option) && trim($option) !== '') {
            return trim($option);
        }

        return base_path('.env');
    }

    /**
     * @param  array<string, string>  $updates
     * @return array{env_path:string,backup_path:string,dry_run:false,updates:array<string,string>}
     */
    private function writeEnv(string $envPath, array $updates): array
    {
        $contents = File::exists($envPath) ? File::get($envPath) : '';
        $backupPath = $envPath.'.atlas-dev-desktop-backup-'.now()->format('YmdHis');

        if (File::exists($envPath)) {
            File::copy($envPath, $backupPath);
        } else {
            File::ensureDirectoryExists(dirname($envPath));
            File::put($backupPath, '');
        }

        $updated = $contents;
        foreach ($updates as $key => $value) {
            $line = $key.'='.$value;
            if (preg_match('/^'.preg_quote($key, '/').'=.*/m', $updated) === 1) {
                $updated = preg_replace('/^'.preg_quote($key, '/').'=.*/m', $line, $updated) ?? $updated;
            } else {
                $updated = rtrim($updated).PHP_EOL.$line.PHP_EOL;
            }
        }

        File::put($envPath, $updated);

        return [
            'env_path' => $envPath,
            'backup_path' => $backupPath,
            'dry_run' => false,
            'updates' => $updates,
        ];
    }
}
