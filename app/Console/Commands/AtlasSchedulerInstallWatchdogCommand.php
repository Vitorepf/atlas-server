<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\AtlasPhpBinary;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Install/uninstall the EXTERNAL scheduler watchdog launchd agent (EVI-01).
 *
 * Patient = com.atlas.scheduler (schedule:run). Watchdog = com.atlas.scheduler-watchdog
 * (scripts/scheduler-watchdog.php) — lives OUTSIDE schedule:run so silent patient
 * death is detectable. Also boots out the legacy com.atlas.ai-health agent to avoid
 * two ambiguous vigias (decision documented for EVI-08).
 *
 * Authority: docs/engineering-knowledge-base/atlas-acos-excellence-10-10-plan-v1.md §EVI-01
 */
class AtlasSchedulerInstallWatchdogCommand extends Command
{
    use EmitsCanonicalJson;

    public const LABEL = 'com.atlas.scheduler-watchdog';

    public const LEGACY_HEALTH_LABEL = 'com.atlas.ai-health';

    protected $signature = 'atlas:scheduler:install-watchdog
        {--uninstall : Remove the watchdog launchd agent}
        {--dry-run : Print plist without writing or loading}
        {--label=com.atlas.scheduler-watchdog : launchd label}
        {--interval=300 : StartInterval seconds}
        {--skip-legacy-bootout : Do not bootout com.atlas.ai-health}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Install or remove the external Atlas scheduler watchdog launchd agent (EVI-01).';

    public function handle(): int
    {
        $label = (string) $this->option('label');
        $home = $this->homeDir();
        $plistPath = $home.'/Library/LaunchAgents/'.$label.'.plist';
        $dryRun = (bool) $this->option('dry-run');
        $uninstall = (bool) $this->option('uninstall');
        $json = (bool) $this->option('json');
        $interval = max(60, (int) $this->option('interval'));

        if ($uninstall) {
            $result = $this->uninstall($plistPath, $label, $dryRun);
            $this->emit($result, $json);

            return self::SUCCESS;
        }

        $php = AtlasPhpBinary::path();
        $script = base_path('scripts/scheduler-watchdog.php');
        $repo = base_path();
        $logOut = storage_path('atlas/scheduler/watchdog.out.log');
        $logErr = storage_path('atlas/scheduler/watchdog.err.log');
        @mkdir(dirname($logOut), 0775, true);

        $plist = $this->renderPlist($label, $php, $script, $repo, $logOut, $logErr, $interval);

        $result = [
            'action' => 'install',
            'label' => $label,
            'plist_path' => $plistPath,
            'plist' => $plist,
            'php' => $php,
            'script' => $script,
            'log_out' => $logOut,
            'log_err' => $logErr,
            'interval' => $interval,
            'dry_run' => $dryRun,
            'loaded' => false,
            'kickstarted' => false,
            'legacy_ai_health' => null,
        ];

        if (! $dryRun) {
            @mkdir(dirname($plistPath), 0775, true);
            file_put_contents($plistPath, $plist);

            if (! (bool) $this->option('skip-legacy-bootout')) {
                $result['legacy_ai_health'] = $this->bootoutLegacyAiHealth($home);
            }

            $uid = $this->uid();
            $domainTarget = 'gui/'.$uid.'/'.$label;
            // Idempotent unload/bootout then bootstrap/load.
            @shell_exec('launchctl bootout '.escapeshellarg($domainTarget).' 2>/dev/null');
            @shell_exec('launchctl unload '.escapeshellarg($plistPath).' 2>/dev/null');
            $loadOutput = (string) shell_exec('launchctl bootstrap '.escapeshellarg('gui/'.$uid).' '.escapeshellarg($plistPath).' 2>&1');
            if (trim($loadOutput) !== '' && ! str_contains($loadOutput, 'Bootstrap failed: 5:')) {
                // Fall back to legacy load for older semantics.
                $loadOutput = (string) shell_exec('launchctl load '.escapeshellarg($plistPath).' 2>&1');
            }
            $result['load_output'] = $loadOutput;
            $result['loaded'] = true;

            $kick = (string) shell_exec('launchctl kickstart -k '.escapeshellarg($domainTarget).' 2>&1');
            $result['kickstart_output'] = $kick;
            $result['kickstarted'] = true;
        }

        $this->emit($result, $json);

        return self::SUCCESS;
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
        $uid = $this->uid();
        $domainTarget = 'gui/'.$uid.'/'.$label;
        @shell_exec('launchctl bootout '.escapeshellarg($domainTarget).' 2>/dev/null');
        if (is_file($plistPath)) {
            $unloadOutput = (string) shell_exec('launchctl unload '.escapeshellarg($plistPath).' 2>&1');
            $result['unload_output'] = $unloadOutput;
            $result['unloaded'] = true;
            $result['removed'] = @unlink($plistPath);
        } else {
            $result['unloaded'] = true;
            $result['removed'] = true;
        }

        return $result;
    }

    /**
     * Explicit decision (EVI-01): bootout + remove com.atlas.ai-health so two
     * vigias never sit in ambiguous state. Documented further in EVI-08.
     *
     * @return array<string,mixed>
     */
    private function bootoutLegacyAiHealth(string $home): array
    {
        $label = self::LEGACY_HEALTH_LABEL;
        $plistPath = $home.'/Library/LaunchAgents/'.$label.'.plist';
        $uid = $this->uid();
        $out = [
            'label' => $label,
            'plist_path' => $plistPath,
            'booted_out' => false,
            'removed' => false,
            'was_present' => is_file($plistPath),
        ];
        @shell_exec('launchctl bootout '.escapeshellarg('gui/'.$uid.'/'.$label).' 2>/dev/null');
        @shell_exec('launchctl unload '.escapeshellarg($plistPath).' 2>/dev/null');
        $out['booted_out'] = true;
        if (is_file($plistPath)) {
            $out['removed'] = @unlink($plistPath);
        } else {
            $out['removed'] = true;
        }

        return $out;
    }

    private function renderPlist(
        string $label,
        string $php,
        string $script,
        string $repo,
        string $logOut,
        string $logErr,
        int $interval,
    ): string {
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
        <string>{$esc($script)}</string>
    </array>
    <key>WorkingDirectory</key>
    <string>{$esc($repo)}</string>
    <key>EnvironmentVariables</key>
    <dict>
        <key>ATLAS_WATCHDOG_ROOT</key>
        <string>{$esc($repo)}</string>
        <key>ATLAS_WATCHDOG_STORAGE</key>
        <string>{$esc($repo.'/storage/atlas/scheduler')}</string>
        <key>ATLAS_WATCHDOG_PHP</key>
        <string>{$esc($php)}</string>
        <key>ATLAS_WATCHDOG_ARTISAN</key>
        <string>{$esc($repo.'/artisan')}</string>
    </dict>
    <key>StartInterval</key>
    <integer>{$interval}</integer>
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

    private function uid(): int
    {
        return function_exists('posix_getuid') ? (int) posix_getuid() : 0;
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
            } elseif (is_array($v)) {
                $this->components->twoColumnDetail($k, json_encode($v, JSON_UNESCAPED_SLASHES));
            }
        }
    }
}
