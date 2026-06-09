<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Installs/uninstalls the operator-local launchd agent that keeps the unified
 * evolution loop alive across crash/kill/sleep recovery. The loop remains
 * propose-only; launchd only restarts the same run id.
 */
final class AtlasUnifiedLoopInstallLaunchdCommand extends Command
{
    protected $signature = 'atlas:loop:unified:install-launchd
        {--run= : Unified loop run id to resume (default: latest, or generated)}
        {--modes=deadcode,docs_structure : Comma list of unified-loop modes}
        {--provider= : Provider (default: config atlas.loop.default_provider or hermes_cli)}
        {--max-seconds=86400 : Wall-clock budget per loop process before launchd restarts it}
        {--label=com.atlas.unified-loop : launchd label}
        {--uninstall : Remove the launchd agent}
        {--dry-run : Print plist without writing or loading}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Install or remove the launchd agent that keeps the Atlas unified loop running propose-only.';

    public function handle(): int
    {
        $label = (string) $this->option('label');
        $home = $this->homeDir();
        $plistPath = $home.'/Library/LaunchAgents/'.$label.'.plist';
        $dryRun = (bool) $this->option('dry-run');
        $json = (bool) $this->option('json');

        if ((bool) $this->option('uninstall')) {
            $result = $this->uninstall($plistPath, $label, $dryRun);
            $this->emit($result, $json);

            return self::SUCCESS;
        }

        [$runId, $runIdSource] = $this->resolveRunId();
        $php = trim((string) shell_exec('which php')) ?: '/usr/bin/php';
        $artisan = base_path('artisan');
        $repo = base_path();
        $modes = $this->normalizedModes();
        $provider = trim((string) $this->option('provider'));
        if ($provider === '') {
            $provider = (string) config('atlas.loop.default_provider', 'hermes_cli');
        }
        $maxSeconds = max(60, (int) $this->option('max-seconds'));
        $logOut = storage_path('atlas/loop/unified/launchd.out.log');
        $logErr = storage_path('atlas/loop/unified/launchd.err.log');
        @mkdir(dirname($logOut), 0775, true);

        $programArguments = [
            $php,
            $artisan,
            'atlas:loop:unified',
            '--run-id='.$runId,
            '--max-seconds='.$maxSeconds,
            '--modes='.$modes,
            '--provider='.$provider,
        ];
        $plist = $this->renderPlist($label, $programArguments, $repo, $logOut, $logErr);

        $result = [
            'action' => 'install',
            'label' => $label,
            'plist_path' => $plistPath,
            'plist' => $plist,
            'php' => $php,
            'artisan' => $artisan,
            'repo' => $repo,
            'run_id' => $runId,
            'run_id_source' => $runIdSource,
            'modes' => $modes,
            'provider' => $provider,
            'max_seconds' => $maxSeconds,
            'log_out' => $logOut,
            'log_err' => $logErr,
            'dry_run' => $dryRun,
            'loaded' => false,
            'claim_policy' => [
                'propose_only' => true,
                'never_merges' => true,
                'launchd_keepalive' => true,
                'mutates_launchd' => ! $dryRun,
                'mutates_code' => false,
            ],
        ];

        if (! $dryRun) {
            @mkdir(dirname($plistPath), 0775, true);
            file_put_contents($plistPath, $plist);
            @shell_exec('launchctl unload '.escapeshellarg($plistPath).' 2>/dev/null');
            $loadOutput = (string) shell_exec('launchctl load '.escapeshellarg($plistPath).' 2>&1');
            $result['loaded'] = trim($loadOutput) === '';
            $result['load_output'] = $loadOutput;
        }

        $this->emit($result, $json);

        return self::SUCCESS;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function resolveRunId(): array
    {
        $provided = trim((string) $this->option('run'));
        if ($provided !== '') {
            return [$provided, 'provided'];
        }

        $latest = $this->latestRunId(storage_path('atlas/loop/unified'));
        if ($latest !== null) {
            return [$latest, 'latest'];
        }

        return ['run-'.gmdate('Ymd-His').'-'.substr(bin2hex(random_bytes(3)), 0, 6), 'generated'];
    }

    private function latestRunId(string $root): ?string
    {
        if (! is_dir($root)) {
            return null;
        }

        $dirs = array_values(array_filter(glob($root.'/run-*') ?: [], 'is_dir'));
        if ($dirs === []) {
            return null;
        }
        usort($dirs, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return basename($dirs[0]);
    }

    private function normalizedModes(): string
    {
        $modes = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('modes')))));

        return $modes === [] ? 'deadcode,docs_structure' : implode(',', $modes);
    }

    /**
     * @param  list<string>  $programArguments
     */
    private function renderPlist(string $label, array $programArguments, string $repo, string $logOut, string $logErr): string
    {
        $esc = static fn (string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $args = implode("\n", array_map(static fn (string $arg): string => '        <string>'.$esc($arg).'</string>', $programArguments));

        return <<<PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key>
    <string>{$esc($label)}</string>
    <key>ProgramArguments</key>
    <array>
{$args}
    </array>
    <key>WorkingDirectory</key>
    <string>{$esc($repo)}</string>
    <key>RunAtLoad</key>
    <true/>
    <key>KeepAlive</key>
    <true/>
    <key>ThrottleInterval</key>
    <integer>30</integer>
    <key>StandardOutPath</key>
    <string>{$esc($logOut)}</string>
    <key>StandardErrorPath</key>
    <string>{$esc($logErr)}</string>
</dict>
</plist>
PLIST;
    }

    /**
     * @return array<string,mixed>
     */
    private function uninstall(string $plistPath, string $label, bool $dryRun): array
    {
        $result = [
            'action' => 'uninstall',
            'label' => $label,
            'plist_path' => $plistPath,
            'dry_run' => $dryRun,
            'unloaded' => false,
            'removed' => false,
        ];
        if ($dryRun) {
            return $result;
        }
        if (is_file($plistPath)) {
            $unloadOutput = (string) shell_exec('launchctl unload '.escapeshellarg($plistPath).' 2>&1');
            $result['unloaded'] = trim($unloadOutput) === '';
            $result['unload_output'] = $unloadOutput;
            $result['removed'] = @unlink($plistPath);
        }

        return $result;
    }

    private function homeDir(): string
    {
        $home = getenv('HOME');
        if (is_string($home) && $home !== '') {
            return $home;
        }
        $pw = function_exists('posix_getpwuid') && function_exists('posix_getuid')
            ? @posix_getpwuid(posix_getuid())
            : null;

        return is_array($pw) ? (string) $pw['dir'] : sys_get_temp_dir();
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, bool $json): void
    {
        if ($json) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }
        foreach ($payload as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $this->components->twoColumnDetail((string) $key, (string) ($value ?? 'null'));
            } elseif ($key === 'plist') {
                $this->line((string) $value);
            }
        }
    }
}
