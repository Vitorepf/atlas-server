<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopIntentVerifierFactory;
use Illuminate\Console\Command;
use JsonException;

final class AtlasLoopCompileVerifierCommand extends Command
{
    protected $signature = 'atlas:loop:compile-verifier
        {--intent= : Human intent to turn into a frozen verifier}
        {--intent-file= : File containing the human intent}
        {--target= : Target relative path}
        {--method= : Explicit method-return atom method name}
        {--returns= : Explicit method-return expected value}
        {--returns-json= : JSON literal for expected return value}
        {--command= : Explicit command-output atom shell command}
        {--output-contains= : Expected stdout substring for command-output atom}
        {--output-exact= : Expected exact stdout for command-output atom}
        {--exit-code=0 : Expected exit code for command-output atom}
        {--http-method=GET : HTTP method for http-response atom}
        {--http-path= : HTTP path for http-response atom}
        {--http-status=200 : Expected HTTP status for http-response atom}
        {--http-body-contains= : Expected response body substring}
        {--http-body-exact= : Expected exact response body}
        {--event-class= : Expected event class/name for event-dispatched atom}
        {--job-class= : Expected queued job class for job-dispatched atom}
        {--artisan-command= : In-process Artisan command used as event-dispatched trigger}
        {--artisan-parameters-json= : JSON object passed to the in-process Artisan trigger}
        {--atom-json= : JSON array of verification atoms}
        {--allowed-file=* : Allowed changed file}
        {--refuter-command=* : External implementation refuter command to pass through to SIC}
        {--refuters= : Required implementation refuter count}
        {--verifier-refuter-command=* : External command that tries to refute the verifier packet before implementation}
        {--verifier-refuters= : Required verifier refuter count}
        {--timeout=120 : Acceptance/refuter timeout seconds}
        {--no-preflight : Skip baseline RED preflight}
        {--write= : Optional path to write the full verifier packet JSON}
        {--strict : Exit non-zero unless the verifier packet is ready}
        {--json : Print machine-readable JSON}';

    protected $description = 'Compile a narrow implementation intent into a RED frozen verifier packet for the Unified Evolution Loop.';

    public function handle(AtlasLoopIntentVerifierFactory $factory): int
    {
        try {
            $intent = $this->intent();
            $payload = $this->payload();
            $packet = $factory->compileFrameworkPacket(base_path(), $intent, $payload);
        } catch (JsonException $e) {
            $this->error('Invalid JSON: '.$e->getMessage());

            return self::FAILURE;
        }

        $write = trim((string) ($this->option('write') ?: ''));
        if ($write !== '') {
            $dir = dirname($write);
            if (! is_dir($dir) && ! mkdir($dir, 0o755, true) && ! is_dir($dir)) {
                $this->error('Cannot create verifier packet directory: '.$dir);

                return self::FAILURE;
            }
            file_put_contents($write, $this->encode($packet)."\n");
            $packet['packet_path'] = $write;
        }

        if ((bool) $this->option('json')) {
            $this->line($this->encode($packet));
        } else {
            $this->components->twoColumnDetail('Intent verifier factory', (string) ($packet['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Target', (string) ($packet['target_relative_path'] ?? '-'));
            $this->components->twoColumnDetail('Verifier hash', (string) ($packet['verifier_hash'] ?? '-'));
            $this->components->twoColumnDetail('RED preflight', (string) data_get($packet, 'red_preflight.status', '-'));
            foreach ((array) ($packet['blockers'] ?? []) as $blocker) {
                $this->warn((string) $blocker);
            }
        }

        return (bool) ($packet['ready'] ?? false) || ! (bool) $this->option('strict')
            ? self::SUCCESS
            : self::FAILURE;
    }

    private function intent(): string
    {
        $intent = trim((string) ($this->option('intent') ?: ''));
        $file = trim((string) ($this->option('intent-file') ?: ''));
        if ($intent === '' && $file !== '' && is_file($file)) {
            $intent = trim((string) file_get_contents($file));
        }

        return $intent;
    }

    /**
     * @return array<string,mixed>
     *
     * @throws JsonException
     */
    private function payload(): array
    {
        $payload = [
            'intent_verifier_factory' => true,
            'intent_verifier_preflight' => ! (bool) $this->option('no-preflight'),
            'target_relative_path' => trim((string) ($this->option('target') ?: '')),
            'allowed_files' => $this->stringOptionList('allowed-file'),
            'semantic_refuter_commands' => $this->stringOptionList('refuter-command'),
            'provider_refuters_required' => $this->intOption('refuters'),
            'verifier_refuter_commands' => $this->stringOptionList('verifier-refuter-command'),
            'verifier_refuters_required' => $this->intOption('verifier-refuters'),
            'timeout_seconds' => max(1, (int) ($this->option('timeout') ?: 120)),
        ];

        $method = trim((string) ($this->option('method') ?: ''));
        if ($method !== '') {
            $payload['method'] = $method;
            if ($this->hasOptionValue('returns') || $this->hasOptionValue('returns-json')) {
                $payload['returns'] = $this->expectedReturn();
            }
        }
        $command = trim((string) ($this->option('command') ?: ''));
        if ($command !== '') {
            $payload['command'] = $command;
            $contains = trim((string) ($this->option('output-contains') ?: ''));
            $exact = trim((string) ($this->option('output-exact') ?: ''));
            if ($contains !== '') {
                $payload['output_contains'] = $contains;
            }
            if ($exact !== '') {
                $payload['output_exact'] = $exact;
            }
            $payload['exit_code'] = $this->intOption('exit-code') ?? 0;
        }
        $httpPath = trim((string) ($this->option('http-path') ?: ''));
        if ($httpPath !== '') {
            $payload['http_method'] = trim((string) ($this->option('http-method') ?: 'GET')) ?: 'GET';
            $payload['http_path'] = $httpPath;
            $payload['http_status'] = $this->intOption('http-status') ?? 200;
            $bodyContains = trim((string) ($this->option('http-body-contains') ?: ''));
            $bodyExact = trim((string) ($this->option('http-body-exact') ?: ''));
            if ($bodyContains !== '') {
                $payload['http_body_contains'] = $bodyContains;
            }
            if ($bodyExact !== '') {
                $payload['http_body_exact'] = $bodyExact;
            }
        }
        $eventClass = trim((string) ($this->option('event-class') ?: ''));
        if ($eventClass !== '') {
            $payload['event_class'] = $eventClass;
        }
        $jobClass = trim((string) ($this->option('job-class') ?: ''));
        if ($jobClass !== '') {
            $payload['job_class'] = $jobClass;
        }
        $artisanCommand = trim((string) ($this->option('artisan-command') ?: ''));
        if ($artisanCommand !== '') {
            $payload['artisan_command'] = $artisanCommand;
            $parametersJson = trim((string) ($this->option('artisan-parameters-json') ?: ''));
            if ($parametersJson !== '') {
                $parameters = json_decode($parametersJson, true, flags: JSON_THROW_ON_ERROR);
                $payload['artisan_parameters'] = is_array($parameters) ? $parameters : [];
            }
        }

        $atomJson = trim((string) ($this->option('atom-json') ?: ''));
        if ($atomJson !== '') {
            $atoms = json_decode($atomJson, true, flags: JSON_THROW_ON_ERROR);
            $payload['verification_atoms'] = is_array($atoms) ? $atoms : [];
        }

        return $payload;
    }

    /**
     * @return mixed
     *
     * @throws JsonException
     */
    private function expectedReturn(): mixed
    {
        $json = trim((string) ($this->option('returns-json') ?: ''));
        if ($json !== '') {
            return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        }

        return (string) ($this->option('returns') ?? '');
    }

    /**
     * @return list<string>
     */
    private function stringOptionList(string $key): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $v): string => is_string($v) ? trim($v) : '',
            (array) $this->option($key),
        ), static fn (string $v): bool => $v !== ''));
    }

    private function intOption(string $key): ?int
    {
        $raw = trim((string) ($this->option($key) ?: ''));

        return $raw === '' || ! ctype_digit($raw) ? null : (int) $raw;
    }

    private function hasOptionValue(string $key): bool
    {
        return trim((string) ($this->option($key) ?? '')) !== '';
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function encode(array $payload): string
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json === false ? '{}' : $json;
    }
}
