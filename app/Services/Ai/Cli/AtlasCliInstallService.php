<?php

namespace App\Services\Ai\Cli;

use Illuminate\Support\Facades\File;

class AtlasCliInstallService
{
    /**
     * @return array<string, mixed>
     */
    public function plan(?string $target = null, bool $force = false, bool $dryRun = false): array
    {
        $source = realpath(base_path('bin/atlas')) ?: base_path('bin/atlas');
        $target = $this->targetPath($target);
        $exists = file_exists($target) || is_link($target);
        $currentTarget = is_link($target) ? readlink($target) : null;
        $alreadyInstalled = $currentTarget !== false && $currentTarget !== null && realpath($currentTarget) === realpath($source);
        $targetDir = dirname($target);
        $pathReady = $this->pathContains($targetDir);
        $canInstall = $alreadyInstalled || ! $exists || $force;

        return [
            'ok' => $canInstall,
            'installed' => $alreadyInstalled,
            'dry_run' => $dryRun,
            'source' => $source,
            'target' => $target,
            'target_dir' => $targetDir,
            'target_exists' => $exists,
            'target_current_link' => $currentTarget ?: null,
            'force_required' => $exists && ! $alreadyInstalled && ! $force,
            'can_install' => $canInstall,
            'path_ready' => $pathReady,
            'shell_profile' => $this->detectShellProfile(),
            'next_actions' => $this->nextActions($targetDir, $exists, $alreadyInstalled, $force, $pathReady),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function install(?string $target = null, bool $force = false, bool $dryRun = false): array
    {
        $plan = $this->plan($target, $force, $dryRun);
        if ($dryRun || ! $plan['can_install']) {
            return $plan;
        }

        $source = (string) $plan['source'];
        $target = (string) $plan['target'];
        File::ensureDirectoryExists(dirname($target));
        chmod($source, 0755);

        if ((file_exists($target) || is_link($target)) && $force) {
            File::delete($target);
        }

        if (! file_exists($target) && ! is_link($target)) {
            symlink($source, $target);
        }

        $installed = $this->plan($target, $force, $dryRun);
        $installed['installed'] = true;

        return $installed;
    }

    /**
     * @return array<string, mixed>
     */
    public function writeShellProfile(string $targetDir, ?string $profilePath = null, bool $dryRun = false): array
    {
        $targetDir = rtrim($this->expandHome($targetDir), '/');
        $profilePath = $profilePath ? $this->expandHome($profilePath) : $this->detectShellProfile();
        $line = 'export PATH="'.$targetDir.':$PATH"';
        $block = implode(PHP_EOL, [
            '',
            '# >>> atlas-cli >>>',
            $line,
            '# <<< atlas-cli <<<',
            '',
        ]);

        $contents = File::exists($profilePath) ? File::get($profilePath) : '';
        $alreadyConfigured = str_contains($contents, $line) || str_contains($contents, '# >>> atlas-cli >>>');

        if (! $dryRun && ! $alreadyConfigured) {
            File::ensureDirectoryExists(dirname($profilePath));
            File::put($profilePath, rtrim($contents).$block);
        }

        return [
            'ok' => true,
            'dry_run' => $dryRun,
            'profile_path' => $profilePath,
            'target_dir' => $targetDir,
            'already_configured' => $alreadyConfigured,
            'written' => ! $dryRun && ! $alreadyConfigured,
            'line' => $line,
            'message' => $alreadyConfigured
                ? 'Shell profile ja contem configuracao do Atlas CLI.'
                : ($dryRun ? 'Shell profile seria atualizado.' : 'Shell profile atualizado. Reabra o terminal ou rode source '.$profilePath.'.'),
        ];
    }

    public function targetPath(?string $target = null): string
    {
        $target = is_string($target) && trim($target) !== ''
            ? trim($target)
            : '~/.local/bin/atlas';

        return $this->expandHome($target);
    }

    public function pathContains(string $dir): bool
    {
        $path = (string) getenv('PATH');

        return collect(explode(PATH_SEPARATOR, $path))
            ->filter()
            ->contains(fn (string $entry): bool => rtrim($entry, '/') === rtrim($dir, '/'));
    }

    public function detectShellProfile(): string
    {
        $shell = basename((string) ($_SERVER['SHELL'] ?? getenv('SHELL') ?: 'zsh'));
        $home = (string) ($_SERVER['HOME'] ?? getenv('HOME') ?: '');

        return match ($shell) {
            'bash' => $home.'/.bashrc',
            'fish' => $home.'/.config/fish/config.fish',
            default => $home.'/.zshrc',
        };
    }

    /**
     * @return array<int, string>
     */
    private function nextActions(string $targetDir, bool $exists, bool $alreadyInstalled, bool $force, bool $pathReady): array
    {
        $actions = [];

        if ($exists && ! $alreadyInstalled && ! $force) {
            $actions[] = 'Rode atlas bootstrap --force se quiser substituir o target existente.';
        }

        if (! $pathReady) {
            $actions[] = "Rode atlas bootstrap --write-shell-profile para configurar {$targetDir} no PATH do shell.";
        }

        if ($alreadyInstalled) {
            $actions[] = 'Rode atlas bootstrap --refresh-providers --strict para validar o produto terminal.';
        }

        return $actions;
    }

    private function expandHome(string $path): string
    {
        if (! str_starts_with($path, '~/')) {
            return $path;
        }

        $home = (string) ($_SERVER['HOME'] ?? getenv('HOME') ?: '');

        return $home !== '' ? $home.substr($path, 1) : $path;
    }
}
