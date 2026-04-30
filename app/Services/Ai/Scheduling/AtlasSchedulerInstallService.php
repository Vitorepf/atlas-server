<?php

namespace App\Services\Ai\Scheduling;

use App\Support\AtlasSecurity;
use Symfony\Component\Process\Process;

class AtlasSchedulerInstallService
{
    public function inspect(): array
    {
        $current = $this->currentCrontab();
        $installed = $current['ok'] && str_contains((string) $current['contents'], $this->markerStart());
        $manualEquivalent = $current['ok']
            && str_contains((string) $current['contents'], 'php artisan schedule:run')
            && str_contains((string) $current['contents'], base_path());

        return [
            'ok' => $current['ok'],
            'installed' => $installed || $manualEquivalent,
            'managed_by_atlas' => $installed,
            'manual_equivalent' => $manualEquivalent && ! $installed,
            'command' => $this->cronLine(),
            'marker_start' => $this->markerStart(),
            'message' => $current['message'],
        ];
    }

    public function install(bool $dryRun = false): array
    {
        $current = $this->currentCrontab();
        if (! $current['ok']) {
            return [
                'ok' => false,
                'written' => false,
                'dry_run' => $dryRun,
                'already_installed' => false,
                'command' => $this->cronLine(),
                'message' => 'Nao foi possivel ler o crontab atual com seguranca: '.$current['message'],
            ];
        }

        $contents = rtrim((string) $current['contents']);
        $block = $this->managedBlock();
        $alreadyInstalled = str_contains($contents, $this->markerStart());

        if ($dryRun || $alreadyInstalled) {
            return [
                'ok' => true,
                'written' => false,
                'dry_run' => $dryRun,
                'already_installed' => $alreadyInstalled,
                'command' => $this->cronLine(),
                'message' => $alreadyInstalled
                    ? 'Crontab do Laravel scheduler ja esta instalado.'
                    : 'Dry-run: crontab do Laravel scheduler seria instalado.',
            ];
        }

        $newContents = trim($contents."\n\n".$block)."\n";
        $process = new Process(['crontab', '-'], base_path(), AtlasSecurity::processEnv(profile: 'internal'));
        $process->setInput($newContents);
        $process->setTimeout(20);
        $process->run();

        return [
            'ok' => $process->isSuccessful(),
            'written' => $process->isSuccessful(),
            'dry_run' => false,
            'already_installed' => false,
            'command' => $this->cronLine(),
            'message' => $process->isSuccessful()
                ? 'Crontab do Laravel scheduler instalado.'
                : trim($process->getErrorOutput() ?: $process->getOutput()),
        ];
    }

    private function currentCrontab(): array
    {
        $process = new Process(['crontab', '-l'], base_path(), AtlasSecurity::processEnv(profile: 'internal'));
        $process->setTimeout(10);
        $process->run();

        $exitCode = $process->getExitCode();
        if ($process->isSuccessful() || $exitCode === 1) {
            return [
                'ok' => true,
                'contents' => $process->isSuccessful() ? $process->getOutput() : '',
                'message' => $process->isSuccessful() ? 'Crontab lido.' : 'Nenhum crontab atual.',
            ];
        }

        return [
            'ok' => false,
            'contents' => '',
            'message' => trim($process->getErrorOutput() ?: $process->getOutput()),
        ];
    }

    private function managedBlock(): string
    {
        return implode("\n", [
            $this->markerStart(),
            $this->cronLine(),
            $this->markerEnd(),
        ]);
    }

    private function cronLine(): string
    {
        $basePath = str_replace("'", "'\\''", base_path());

        return "* * * * * cd '{$basePath}' && php artisan schedule:run >> /dev/null 2>&1";
    }

    private function markerStart(): string
    {
        return '# ATLAS CLI SCHEDULER START';
    }

    private function markerEnd(): string
    {
        return '# ATLAS CLI SCHEDULER END';
    }
}
