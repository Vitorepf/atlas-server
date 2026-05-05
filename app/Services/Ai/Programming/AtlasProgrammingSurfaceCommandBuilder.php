<?php

namespace App\Services\Ai\Programming;

use App\Support\AtlasPhpBinary;

class AtlasProgrammingSurfaceCommandBuilder
{
    public function repairTask(string $description): string
    {
        $description = trim($description);

        return $description !== ''
            ? "Corrija: {$description}"
            : 'Corrija o ultimo teste falho, bug ou quality gate detectado neste workspace. Primeiro inspecione o estado atual, depois aplique a menor correcao segura.';
    }

    /**
     * @return array<string,mixed>
     */
    public function repairDevArguments(
        string $workspace,
        ?string $provider,
        string $description,
        int $maxIterations = 3,
        bool $autoTest = false,
        bool $allowWrite = false,
        bool $json = false,
    ): array {
        return array_filter([
            'task' => [$this->repairTask($description)],
            '--workspace' => $workspace,
            '--provider' => $provider,
            '--permission' => 'write',
            '--repair' => true,
            '--allow-write' => $allowWrite,
            '--auto-test' => $autoTest,
            '--max-iterations' => (string) max(1, min(10, $maxIterations)),
            '--json' => $json,
        ], fn (mixed $value): bool => $value !== null && $value !== false && $value !== '');
    }

    /**
     * @param  array<string,mixed>  $resume
     * @param  array<string,mixed>  $operatorOptions
     * @param  array<string,mixed>  $openBrain
     * @return array<int,string>
     */
    public function resumeDevCommand(array $resume, array $operatorOptions, array $openBrain = []): array
    {
        $command = [
            AtlasPhpBinary::path(),
            base_path('artisan'),
            'atlas:cli:dev',
            (string) $resume['task'],
            '--workspace='.(string) $resume['workspace'],
            '--resume='.(string) $resume['plan_id'],
        ];

        $this->appendScalarOption($command, '--max-iterations', $operatorOptions['max_iterations'] ?? null, integer: true);
        $this->appendScalarOption($command, '--provider', $operatorOptions['provider'] ?? null);
        $this->appendScalarOption($command, '--model', $resume['model'] ?? null);

        if (($resume['programming_profile'] ?? null) === 'forge') {
            $command[] = '--forge';
        }

        if (($operatorOptions['programming_intent'] ?? null) === 'repair' || ($operatorOptions['intent'] ?? null) === 'repair') {
            $command[] = '--repair';
        }

        foreach ([
            'critical' => '--critical',
            'allow_write' => '--allow-write',
            'allow_danger' => '--dangerously-allow-all',
            'allow_unsandboxed' => '--allow-unsandboxed',
            'auto_test' => '--auto-test',
            'no_stream' => '--no-stream',
        ] as $key => $flag) {
            if (! empty($operatorOptions[$key])) {
                $command[] = $flag;
            }
        }

        if (($openBrain['mode'] ?? null) === 'off') {
            $command[] = '--no-open-brain';
        } elseif (($openBrain['mode'] ?? null) === 'required') {
            $command[] = '--require-open-brain';
        }
        if (! empty($openBrain['refresh'])) {
            $command[] = '--open-brain-refresh';
        }
        $this->appendScalarOption($command, '--open-brain-budget', $openBrain['budget_chars'] ?? null, integer: true);

        $permission = (string) ($operatorOptions['permission'] ?? '');
        if (in_array($permission, ['read', 'write', 'danger'], true)) {
            $command[] = '--permission='.$permission;
        }

        foreach ((array) ($operatorOptions['skills'] ?? []) as $skill) {
            if (is_scalar($skill) && trim((string) $skill) !== '') {
                $command[] = '--skill='.trim((string) $skill);
            }
        }

        return $command;
    }

    /**
     * @param  array<int,string>  $command
     */
    private function appendScalarOption(array &$command, string $option, mixed $value, bool $integer = false): void
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return;
        }

        $value = $integer ? (string) max(1, (int) $value) : trim((string) $value);
        $command[] = "{$option}={$value}";
    }
}
