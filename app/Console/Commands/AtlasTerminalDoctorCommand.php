<?php

namespace App\Console\Commands;

use App\Services\Ai\TerminalDev\Protocol\AapSchema;
use App\Services\Ai\TerminalDev\Providers\TerminalHermesBridge;
use App\Services\Ai\TerminalDev\Superiority\TerminalSuperiorityService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\ExecutableFinder;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasTerminalDoctorCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:terminal:doctor
        {--workspace= : Workspace path}
        {--json : Machine JSON}';

    protected $description = 'Terminal Dev doctor: Hermes, AAP, clipboard, TUI binary, theme/truecolor hints.';

    public function handle(TerminalHermesBridge $hermes, TerminalSuperiorityService $superiority): int
    {
        $workspace = $this->option('workspace');
        $workspace = is_string($workspace) && $workspace !== ''
            ? (realpath($workspace) ?: $workspace)
            : (realpath(getcwd() ?: base_path()) ?: base_path());

        $finder = new ExecutableFinder;
        $hermesBin = $finder->find('hermes') ?: (config('atlas.ai.providers.hermes_cli.binary') ?: null);
        $atlasTerm = null;
        foreach ([
            base_path('../atlas-terminal/target/release/atlas-term'),
            ($_SERVER['HOME'] ?? getenv('HOME') ?: '').'/.local/bin/atlas-term',
            $finder->find('atlas-term'),
        ] as $c) {
            if (is_string($c) && $c !== '' && is_executable($c)) {
                $atlasTerm = $c;
                break;
            }
        }

        $session = [
            'id' => 'doctor',
            'workspace' => $workspace,
            'mode' => 'normal',
            'profile' => 'dev',
            'yolo' => false,
            'permission_mode' => 'write',
        ];

        $payload = [
            'schema' => 'atlas.terminal.doctor.v1',
            'ok' => true,
            'protocol_version' => AapSchema::VERSION,
            'workspace' => $workspace,
            'hermes' => [
                'enabled' => $hermes->isEnabled(),
                'dry_run' => $hermes->isDryRun(),
                'binary' => $hermesBin,
                'binary_ok' => is_string($hermesBin) && (is_executable($hermesBin) || $finder->find((string) $hermesBin)),
                'oneshot' => (bool) config('atlas_terminal.hermes_cli_oneshot', true),
                'provider' => (string) config('atlas_terminal.provider', 'hermes_cli'),
            ],
            'tui' => [
                'atlas_term' => $atlasTerm,
                'source' => File::isFile(base_path('../atlas-terminal/src/main.rs')),
            ],
            'clipboard' => [
                'macos' => PHP_OS_FAMILY === 'Darwin',
                'pngpaste' => $finder->find('pngpaste'),
                'osascript' => is_executable('/usr/bin/osascript'),
            ],
            'colorterm' => getenv('COLORTERM') ?: null,
            'term' => getenv('TERM') ?: null,
            'open_brain' => (bool) config('atlas_terminal.open_brain.enabled', true),
            'sessions_root' => (string) config('atlas_terminal.sessions_root'),
            'superiority' => $superiority->doctor($session),
            'issues' => [],
            'recommendations' => [],
        ];

        if (! ($payload['hermes']['binary_ok'] ?? false)) {
            $payload['issues'][] = 'hermes binary not found on PATH';
            $payload['recommendations'][] = 'Install Hermes CLI and ensure `hermes` is on PATH';
            $payload['ok'] = false;
        }
        if ($atlasTerm === null) {
            $payload['issues'][] = 'atlas-term binary not found (TUI)';
            $payload['recommendations'][] = 'cargo build --release in Atlas/atlas-terminal with CommandLineTools';
        }
        if (PHP_OS_FAMILY === 'Darwin' && $finder->find('pngpaste') === null && ! is_executable('/usr/bin/osascript')) {
            $payload['issues'][] = 'clipboard image capture tools missing';
        }
        if (($payload['colorterm'] ?? '') !== 'truecolor' && ($payload['colorterm'] ?? '') !== '24bit') {
            $payload['recommendations'][] = 'export COLORTERM=truecolor for best theme fidelity';
        }

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));
        } else {
            $this->info($payload['ok'] ? 'Terminal doctor OK' : 'Terminal doctor has issues');
            $this->line('Hermes: '.json_encode($payload['hermes']));
            $this->line('TUI: '.json_encode($payload['tui']));
            foreach ($payload['issues'] as $i) {
                $this->warn('issue: '.$i);
            }
            foreach ($payload['recommendations'] as $r) {
                $this->line('rec: '.$r);
            }
        }

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
