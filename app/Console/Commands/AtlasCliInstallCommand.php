<?php

namespace App\Console\Commands;

use App\Services\Ai\Cli\AtlasCliInstallService;
use Illuminate\Console\Command;
use App\Support\YesNo;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasCliInstallCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:cli:install
        {--target= : Symlink target. Defaults to ~/.local/bin/atlas}
        {--force : Replace an existing target}
        {--write-shell-profile : Add the target directory to the detected shell profile}
        {--shell-profile= : Shell profile path used with --write-shell-profile}
        {--dry-run : Show planned install without writing}
        {--json : Print machine-readable JSON}';

    protected $description = 'Install the Atlas CLI launcher into the local shell PATH.';

    public function handle(AtlasCliInstallService $installer): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $plan = $installer->install(
            target: is_string($this->option('target')) ? $this->option('target') : null,
            force: $force,
            dryRun: $dryRun,
        );

        if ((bool) $this->option('write-shell-profile')) {
            $plan['shell_profile_write'] = $installer->writeShellProfile(
                targetDir: (string) $plan['target_dir'],
                profilePath: is_string($this->option('shell-profile')) ? $this->option('shell-profile') : null,
                dryRun: $dryRun,
            );
            $plan['path_ready'] = true;
        }

        if ((bool) $this->option('json')) {
            $this->line($this->encode($plan));

            return $plan['ok'] ? self::SUCCESS : self::FAILURE;
        }

        $this->render($plan);

        return $plan['ok'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function render(array $plan): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas CLI Install</>', $plan['installed'] ? 'installed' : ($plan['dry_run'] ? 'dry-run' : 'planned'));
        $this->components->twoColumnDetail('Source', (string) $plan['source']);
        $this->components->twoColumnDetail('Target', (string) $plan['target']);
        $this->components->twoColumnDetail('PATH ready', YesNo::format($plan['path_ready']));

        if (isset($plan['shell_profile_write']) && is_array($plan['shell_profile_write'])) {
            $this->components->twoColumnDetail('Shell profile', (string) $plan['shell_profile_write']['profile_path']);
            $this->line((string) $plan['shell_profile_write']['message']);
        }

        if (! $plan['ok']) {
            $this->error('Target existente exige --force.');
        }

        foreach ((array) $plan['next_actions'] as $action) {
            $this->line('  - '.$action);
        }
    }
}
