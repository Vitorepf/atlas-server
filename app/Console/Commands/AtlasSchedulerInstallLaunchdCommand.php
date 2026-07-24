<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Installs/uninstalls launchd agent that runs `php artisan schedule:run`
 * every minute on the operator's Mac.
 *
 * Authority doc: docs/engineering-knowledge-base/atlas-scheduler-os.md
 */
class AtlasSchedulerInstallLaunchdCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:scheduler:install-launchd
        {--uninstall : Remove the launchd agent}
        {--dry-run : Print plist without writing or loading}
        {--label=com.atlas.scheduler : launchd label}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Install or remove the Atlas scheduler launchd agent (Mac local cron OS).';

    public function handle(): int
    {
        $label = (string) $this->option('label');
        $home = $this->homeDir();
        $plistPath = $home.'/Library/LaunchAgents/'.$label.'.plist';
        $dryRun = (bool) $this->option('dry-run');
        $uninstall = (bool) $this->option('uninstall');
        $json = (bool) $this->option('json');

        if ($uninstall) {
            $result = $this->uninstall($plistPath, $label, $dryRun);
            $this->emit($result, $json);

            return self::SUCCESS;
        }

        $php = trim((string) shell_exec('which php')) ?: '/usr/bin/php';
        $artisan = base_path('artisan');
        $repo = base_path();
        $logOut = storage_path('atlas/scheduler/launchd.out.log');
        $logErr = storage_path('atlas/scheduler/launchd.err.log');
        @mkdir(dirname($logOut), 0775, true);

        $plist = $this->renderPlist($label, $php, $artisan, $repo, $logOut, $logErr);

        $result = [
            'action' => 'install',
            'label' => $label,
            'plist_path' => $plistPath,
            'plist' => $plist,
            'php' => $php,
            'artisan' => $artisan,
            'log_out' => $logOut,
            'log_err' => $logErr,
            'dry_run' => $dryRun,
            'loaded' => false,
        ];

        if (! $dryRun) {
            @mkdir(dirname($plistPath), 0775, true);
            file_put_contents($plistPath, $plist);
            // unload then load is idempotent
            @shell_exec('launchctl unload '.escapeshellarg($plistPath).' 2>/dev/null');
            $loadOutput = (string) shell_exec('launchctl load '.escapeshellarg($plistPath).' 2>&1');
            $result['loaded'] = trim($loadOutput) === '';
            $result['load_output'] = $loadOutput;
        }

        $this->emit($result, $json);

        return self::SUCCESS;
    }

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

    private function renderPlist(string $label, string $php, string $artisan, string $repo, string $logOut, string $logErr): string
    {
        $esc = static fn (string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return <<<PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key>
    <string>{$esc($label)}</string>
    <key>ProgramArguments</key>
    <array>
        <string>{$esc($php)}</string>
        <string>{$esc($artisan)}</string>
        <string>schedule:run</string>
    </array>
    <key>WorkingDirectory</key>
    <string>{$esc($repo)}</string>
    <key>StartInterval</key>
    <integer>60</integer>
    <key>RunAtLoad</key>
    <true/>
    <key>StandardOutPath</key>
    <string>{$esc($logOut)}</string>
    <key>StandardErrorPath</key>
    <string>{$esc($logErr)}</string>
</dict>
</plist>
PLIST;
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

        return is_array($pw) && isset($pw['dir']) ? (string) $pw['dir'] : sys_get_temp_dir();
    }

    private function emit(array $payload, bool $json): void
    {
        if ($json) {
            $this->line($this->encode($payload));

            return;
        }
        foreach ($payload as $k => $v) {
            if (is_scalar($v) || $v === null) {
                $this->components->twoColumnDetail($k, (string) ($v ?? '∅'));
            } elseif ($k === 'plist') {
                $this->line($v);
            }
        }
    }
}
