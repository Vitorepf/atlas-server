<?php

namespace App\Console\Commands;

use App\Support\AtlasSecurity;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class AtlasCliVersionCommand extends Command
{
    protected $signature = 'atlas:cli:version {--json}';

    protected $description = 'Show Atlas CLI version, commit and branch.';

    public function handle(): int
    {
        $composer = json_decode((string) @file_get_contents(base_path('composer.json')), true);
        $payload = [
            'name' => is_array($composer) ? ($composer['name'] ?? 'atlas/server') : 'atlas/server',
            'version' => config('atlas.version') ?: 'dev',
            'branch' => $this->git(['rev-parse', '--abbrev-ref', 'HEAD']),
            'commit' => $this->git(['rev-parse', '--short', 'HEAD']),
            'root' => base_path(),
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->components->twoColumnDetail('Atlas CLI', (string) $payload['version']);
            $this->components->twoColumnDetail('Branch', (string) $payload['branch']);
            $this->components->twoColumnDetail('Commit', (string) $payload['commit']);
            $this->components->twoColumnDetail('Root', (string) $payload['root']);
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<int,string>  $args
     */
    private function git(array $args): ?string
    {
        $process = new Process(['git', ...$args], base_path(), AtlasSecurity::processEnv(profile: 'tool'));
        $process->setTimeout(10);
        $process->run();

        return $process->isSuccessful() ? trim(AtlasSecurity::redactString($process->getOutput())) : null;
    }
}
