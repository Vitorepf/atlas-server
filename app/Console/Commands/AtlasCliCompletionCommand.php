<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class AtlasCliCompletionCommand extends Command
{
    protected $signature = 'atlas:cli:completion
        {action=path : path, bash, zsh, install}';

    protected $description = 'Print or install Atlas CLI shell completion (bash/zsh).';

    public function handle(): int
    {
        $action = strtolower(trim((string) $this->argument('action')));
        $script = base_path('bin/atlas-completion.bash');

        if (! is_file($script)) {
            $this->error('Script de completion ausente em '.$script);

            return self::FAILURE;
        }

        return match ($action) {
            'path' => $this->printPath($script),
            'bash', 'zsh' => $this->printScript($script, $action),
            'install' => $this->printInstallSnippet($script),
            default => $this->printUsage(),
        };
    }

    private function printPath(string $script): int
    {
        $this->line($script);

        return self::SUCCESS;
    }

    private function printScript(string $script, string $shell): int
    {
        if ($shell === 'zsh') {
            $this->line('# zsh: source este arquivo apos `autoload -Uz compinit && compinit && bashcompinit`');
        }
        $this->output->write((string) file_get_contents($script));

        return self::SUCCESS;
    }

    private function printInstallSnippet(string $script): int
    {
        $this->line('# bash');
        $this->line('echo "source '.$script.'" >> ~/.bashrc');
        $this->newLine();
        $this->line('# zsh');
        $this->line('cat <<\'EOZ\' >> ~/.zshrc');
        $this->line('autoload -Uz compinit && compinit');
        $this->line('autoload -Uz bashcompinit && bashcompinit');
        $this->line('source "'.$script.'"');
        $this->line('EOZ');

        return self::SUCCESS;
    }

    private function printUsage(): int
    {
        $this->line('Uso: atlas completion <path|bash|zsh|install>');
        $this->line('  path     imprime o caminho do script');
        $this->line('  bash     imprime o script para `eval`');
        $this->line('  zsh      imprime o script com cabecalho zsh');
        $this->line('  install  imprime as linhas para colar em ~/.bashrc ou ~/.zshrc');

        return self::SUCCESS;
    }
}
