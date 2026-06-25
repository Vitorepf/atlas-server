<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\Maestro\Personalization\AtlasMaestroPersonalizedServingPolicy;
use App\Services\Ai\SelfConstruction\Maestro\Personalization\AtlasMaestroWorkerPreferenceRegistry;
use Illuminate\Console\Command;

/**
 * Operator surface for the Maestro personalization triad.
 *
 *   atlas:task:maestro:prefs register --client=<id> [--max-files] [--max-loc] [--tier]
 *   atlas:task:maestro:prefs inspect  --client=<id>
 *   atlas:task:maestro:prefs policy   --client=<id> --packet=<json|-|path>
 *
 * Register mutates only the in-process registry singleton (config remains the source of truth).
 * All output JSON carries `schema = atlas.maestro.personalization.v1`.
 */
final class AtlasTaskMaestroPrefsCommand extends Command
{
    public const EXIT_OK = 0;

    public const EXIT_USAGE = 2;

    public const SCHEMA = 'atlas.maestro.personalization.v1';

    protected $signature = 'atlas:task:maestro:prefs {sub : register|inspect|policy}
        {--client= : opaque client id}
        {--max-files= : declared file budget (register)}
        {--max-loc= : declared loc budget (register)}
        {--tier= : declared tier label (register)}
        {--packet= : packet JSON / path / `-` for stdin (policy)}
        {--json : emit machine-readable JSON}';

    protected $description = 'Maestro personalization CLI: register | inspect | policy.';

    public function handle(): int
    {
        $sub = (string) $this->argument('sub');

        return match ($sub) {
            'register' => $this->subRegister(),
            'inspect' => $this->subInspect(),
            'policy' => $this->subPolicy(),
            default => $this->emitStatus('unknown_sub', ['sub' => $sub], exit: self::EXIT_USAGE),
        };
    }

    private function subRegister(): int
    {
        $client = (string) ($this->option('client') ?? '');
        if ($client === '') {
            return $this->emitStatus('missing_client', [], exit: self::EXIT_USAGE);
        }
        $registry = app(AtlasMaestroWorkerPreferenceRegistry::class);
        $registry->register($client, [
            'max_files' => (int) ($this->option('max-files') ?? 0),
            'max_loc' => (int) ($this->option('max-loc') ?? 0),
            'tier' => (string) ($this->option('tier') ?? ''),
        ]);

        return $this->emit(['status' => 'ok', 'client_id' => $client, 'preferences' => $registry->inspect($client)]);
    }

    private function subInspect(): int
    {
        $client = (string) ($this->option('client') ?? '');
        if ($client === '') {
            return $this->emitStatus('missing_client', [], exit: self::EXIT_USAGE);
        }
        $registry = app(AtlasMaestroWorkerPreferenceRegistry::class);

        return $this->emit(['status' => 'ok', 'client_id' => $client, 'preferences' => $registry->inspect($client)]);
    }

    private function subPolicy(): int
    {
        $client = (string) ($this->option('client') ?? '');
        if ($client === '') {
            return $this->emitStatus('missing_client', [], exit: self::EXIT_USAGE);
        }
        $packetRaw = (string) ($this->option('packet') ?? '');
        if ($packetRaw === '') {
            return $this->emitStatus('missing_packet', [], exit: self::EXIT_USAGE);
        }
        if ($packetRaw === '-') {
            $packetRaw = (string) @file_get_contents('php://stdin');
        } elseif (is_file($packetRaw)) {
            $packetRaw = (string) file_get_contents($packetRaw);
        }
        $packet = json_decode($packetRaw, true);
        if (! is_array($packet)) {
            return $this->emitStatus('invalid_packet_json', [], exit: self::EXIT_USAGE);
        }

        $registry = app(AtlasMaestroWorkerPreferenceRegistry::class);
        $policy = app()->bound(AtlasMaestroPersonalizedServingPolicy::class)
            ? app(AtlasMaestroPersonalizedServingPolicy::class)
            : new AtlasMaestroPersonalizedServingPolicy($registry);
        $verdict = $policy->decide($client, $packet);

        return $this->emit(['status' => 'ok', 'verdict' => $verdict]);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, int $exit = self::EXIT_OK): int
    {
        $envelope = array_merge(['schema' => self::SCHEMA], $payload);
        if ($this->option('json')) {
            $this->line((string) json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            foreach ($envelope as $k => $v) {
                $this->line($k.': '.(is_scalar($v) || $v === null ? (string) $v : json_encode($v, JSON_UNESCAPED_SLASHES)));
            }
        }

        return $exit;
    }

    /**
     * @param  array<string,mixed>  $extra
     */
    private function emitStatus(string $status, array $extra = [], int $exit = self::EXIT_OK): int
    {
        return $this->emit(array_merge(['status' => $status], $extra), $exit);
    }
}
